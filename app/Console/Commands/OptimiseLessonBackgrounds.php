<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Scene;
use App\Services\BackgroundImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-encode backgrounds that were stored before the download path started optimising them.
 *
 * Every lesson built up to now carries full-size originals — 1.7 to 3 MB each, sixteen to a
 * lesson. New lessons are fixed at the source; this is for the ones already on disk.
 *
 * Dry run by default. It rewrites scene.image_path (the extension changes) and deletes the
 * original only after the replacement is safely on disk.
 */
class OptimiseLessonBackgrounds extends Command
{
    protected $signature = 'lessons:optimise-backgrounds
                            {--lesson= : Only this lesson id}
                            {--min-kb=120 : Skip anything already smaller than this}
                            {--apply : Actually write the files; without it nothing changes}';

    protected $description = 'Resize and re-encode existing scene backgrounds to fit the byte cap';

    public function handle(BackgroundImageOptimizer $optimiser): int
    {
        $apply = (bool) $this->option('apply');
        $minBytes = (int) $this->option('min-kb') * 1024;
        $disk = Storage::disk('public');

        $scenes = Scene::query()
            ->whereNotNull('image_path')
            ->when($this->option('lesson'), fn ($q, $id) => $q->where('lesson_id', $id))
            ->orderBy('lesson_id')
            ->orderBy('order')
            ->get();

        if ($scenes->isEmpty()) {
            $this->info('No scenes with backgrounds found.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? "Optimising backgrounds for {$scenes->count()} scenes."
            : "DRY RUN over {$scenes->count()} scenes — pass --apply to write.");
        $this->newLine();

        $before = $after = $converted = $skipped = $failed = 0;

        foreach ($scenes as $scene) {
            $path = (string) $scene->image_path;

            if (! $disk->exists($path)) {
                $this->warn("  missing on disk, skipped: {$path}");
                $skipped++;

                continue;
            }

            $size = $disk->size($path);
            if ($size < $minBytes) {
                $skipped++;

                continue;
            }

            try {
                $result = $optimiser->optimise($disk->get($path));
            } catch (Throwable $e) {
                $this->warn("  failed: {$path} — {$e->getMessage()}");
                $failed++;

                continue;
            }

            $newPath = preg_replace('/\.[^.\/]+$/', '', $path).'.'.$result['extension'];
            $newSize = strlen($result['bytes']);

            $this->line(sprintf(
                '  %s  %6.1f MB → %5.0f KB  q%-3d %s%s',
                str_pad("lesson {$scene->lesson_id} scene {$scene->id}", 26),
                $size / 1048576,
                $newSize / 1024,
                $result['quality'],
                $result['grain'] ? 'grain' : 'no-grain',
                $newSize > $size ? '   (LARGER — kept the original)' : '',
            ));

            $before += $size;
            $after += min($newSize, $size);

            // Re-encoding a small or already-efficient file can come out bigger. Keep whichever
            // is smaller; the point is bytes over the wire, not the file extension.
            if ($newSize >= $size) {
                $skipped++;

                continue;
            }

            $converted++;

            if (! $apply) {
                continue;
            }

            // Keep the original as bg.original.* rather than deleting it. This is a lossy,
            // one-way re-encode over every background in the install; if a preset or a quality
            // floor turns out to be wrong, the only way back is the file we started from. They are
            // safe to delete by hand once the results have been looked at.
            if ($newPath !== $path) {
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $keep = preg_replace('/\.[^.\/]+$/', '', $path).'.original.'.$extension;
                if (! $disk->exists($keep)) {
                    $disk->move($path, $keep);
                }
            }

            $disk->put($newPath, $result['bytes']);
            $scene->update(['image_path' => $newPath]);
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d scenes: %.1f MB → %.1f MB (%.0f%% smaller). Skipped %d, failed %d.',
            $apply ? 'Optimised' : 'Would optimise',
            $converted,
            $before / 1048576,
            $after / 1048576,
            $before > 0 ? (1 - $after / $before) * 100 : 0,
            $skipped,
            $failed,
        ));

        if (! $apply && $converted > 0) {
            $this->newLine();
            $this->info('Re-run with --apply to write these.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
