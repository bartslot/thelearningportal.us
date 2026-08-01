<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Scene;
use App\Services\BackgroundImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Shrink and catalogue the images no lesson points at any more.
 *
 * Most of what sits under storage/app/public/lessons is no longer referenced: superseded originals
 * inside lessons that are still live, and everything belonging to lessons that were deleted. It is
 * the best part of a gigabyte, and it is worth keeping — every one of those files was chosen for a
 * scene, and sourcing them again costs the same effort a second time.
 *
 * So this re-encodes them in place at stage size, and writes a manifest describing what it found.
 * Archived files use the same size and format as a live background, which means reusing one is a
 * copy rather than a second generation of lossy encoding.
 *
 * Nothing is deleted, ever. Dry run by default.
 */
class ArchiveOrphanImages extends Command
{
    protected $signature = 'lessons:archive-images
                            {--lesson= : Only this lesson directory}
                            {--quality=45 : One-pass AVIF quality; no search, this is bulk work}
                            {--min-kb=150 : Leave anything already smaller than this alone}
                            {--manifest=storage/app/orphan-images.json : Where to write the catalogue}
                            {--apply : Actually rewrite files; without it nothing changes}';

    protected $description = 'Re-encode unreferenced lesson images to AVIF and catalogue them';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public function handle(BackgroundImageOptimizer $optimiser): int
    {
        $apply = (bool) $this->option('apply');
        $quality = (int) $this->option('quality');
        $minBytes = (int) $this->option('min-kb') * 1024;

        $referenced = $this->referencedPaths();
        $liveLessons = Lesson::pluck('title', 'id');
        $root = base_path('storage/app/public/');

        $this->line($apply ? 'Archiving unreferenced images.' : 'DRY RUN — pass --apply to write.');
        $this->newLine();

        $manifest = [];
        $before = $after = $done = $skipped = $failed = 0;

        foreach ($this->imageFiles() as $file) {
            $relative = str_replace($root, '', $file->getPathname());
            if ($referenced->has($relative)) {
                continue;
            }

            $lessonId = (int) explode('/', str_replace('lessons/', '', $relative))[0];
            if ($this->option('lesson') && $lessonId !== (int) $this->option('lesson')) {
                continue;
            }

            $size = $file->getSize();
            $entry = [
                'path' => $relative,
                'lesson_id' => $lessonId,
                'lesson_title' => $liveLessons[$lessonId] ?? null,
                'lesson_alive' => $liveLessons->has($lessonId),
                'role' => $this->describeRole($relative),
                'original_bytes' => $size,
            ];

            if ($size < $minBytes) {
                $skipped++;
                $manifest[] = $entry + ['archived_bytes' => $size, 'action' => 'left alone'];

                continue;
            }

            try {
                $result = $optimiser->archive((string) file_get_contents($file->getPathname()), $quality);
            } catch (Throwable $e) {
                $failed++;
                $manifest[] = $entry + ['action' => 'failed', 'error' => $e->getMessage()];

                continue;
            }

            $newBytes = strlen($result['bytes']);
            $newPath = preg_replace('/\.[^.\/]+$/', '', $relative).'.'.$result['extension'];

            // Re-encoding something already small and efficient can come out bigger. The point is
            // bytes on disk, so keep whichever wins.
            if ($newBytes >= $size) {
                $skipped++;
                $manifest[] = $entry + ['archived_bytes' => $size, 'action' => 'already smaller'];

                continue;
            }

            $before += $size;
            $after += $newBytes;
            $done++;

            $manifest[] = $entry + [
                'archived_path' => $newPath,
                'archived_bytes' => $newBytes,
                'width' => $result['width'],
                'height' => $result['height'],
                'action' => $apply ? 'archived' : 'would archive',
            ];

            if ($apply) {
                file_put_contents($root.$newPath, $result['bytes']);
                // The source only goes when the replacement is a genuinely different file and is
                // safely written; a .jpg becoming .avif frees the .jpg, a .avif re-encode does not.
                if ($newPath !== $relative && file_exists($root.$newPath)) {
                    @unlink($file->getPathname());
                }
            }

            if ($done % 25 === 0) {
                $this->line(sprintf('  %4d files … %.0f MB saved so far', $done, ($before - $after) / 1048576));
            }
        }

        $this->writeManifest($manifest);

        $this->newLine();
        $this->line(sprintf(
            '%s %d files: %.0f MB → %.0f MB (%.0f%% smaller). Left alone %d, failed %d.',
            $apply ? 'Archived' : 'Would archive',
            $done,
            $before / 1048576,
            $after / 1048576,
            $before > 0 ? (1 - $after / $before) * 100 : 0,
            $skipped,
            $failed,
        ));
        $this->line('Manifest: '.$this->option('manifest'));

        return self::SUCCESS;
    }

    /** Every image path any lesson or scene still points at, in any of its several columns. */
    private function referencedPaths(): Collection
    {
        $paths = collect();

        foreach (Scene::whereNotNull('image_path')->pluck('image_path') as $p) {
            $paths[$p] = true;
        }
        foreach (Scene::whereNotNull('skybox_image_path')->pluck('skybox_image_path') as $p) {
            $paths[$p] = true;
        }
        foreach (Scene::whereNotNull('shots')->pluck('shots') as $shots) {
            foreach ((array) $shots as $shot) {
                if (! empty($shot['image_path'])) {
                    $paths[$shot['image_path']] = true;
                }
            }
        }
        foreach (Lesson::whereNotNull('title_bg_path')->pluck('title_bg_path') as $p) {
            $paths[$p] = true;
        }
        foreach (Lesson::whereNotNull('poster_image')->pluck('poster_image') as $p) {
            $paths[$p] = true;
        }

        return $paths;
    }

    /**
     * The complete list of image files, read BEFORE any of them are touched.
     *
     * This deliberately builds an array rather than yielding as it walks. Archiving replaces a
     * .jpg with a .avif and unlinks the original, and mutating the tree while a
     * RecursiveDirectoryIterator is still walking it makes the walk give up early — the first run
     * stopped after about 125 of 1,450 files and exited 0, having quietly skipped the rest.
     *
     * @return list<SplFileInfo>
     */
    private function imageFiles(): array
    {
        $base = base_path('storage/app/public/lessons');
        if (! is_dir($base)) {
            return [];
        }

        $files = [];
        $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($walker as $file) {
            if ($file instanceof SplFileInfo
                && $file->isFile()
                && in_array(strtolower($file->getExtension()), self::IMAGE_EXTENSIONS, true)) {
                // Clone out of the iterator: SplFileInfo from a walker caches a path that is about
                // to stop existing, and getSize() on it later would fail.
                $files[] = new SplFileInfo($file->getPathname());
            }
        }

        return $files;
    }

    /** What the file was for, read off the path — the only clue left once a lesson is gone. */
    private function describeRole(string $path): string
    {
        $name = basename($path);

        return match (true) {
            str_starts_with($name, 'bg.') => 'scene background',
            str_starts_with($name, 'skybox') => 'skybox / panorama',
            str_starts_with($name, 'shot_') => 'parallax shot layer',
            str_contains($path, '/paintings/') => 'sourced painting',
            str_starts_with($name, 'cover') => 'lesson cover',
            default => 'other',
        };
    }

    private function writeManifest(array $manifest): void
    {
        $path = base_path((string) $this->option('manifest'));
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
