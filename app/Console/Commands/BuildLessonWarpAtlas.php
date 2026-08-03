<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pack a lesson's own artwork into one small sprite sheet for the hero timewarp.
 *
 * The landing hero flies down a tunnel made of thumbnails. Generic history cards are fine, but a
 * tunnel built from THIS lesson's pictures — Tasman's fleet, the Golden Bay encounter, the 1644
 * chart — is the lesson being made in front of you rather than stock imagery.
 *
 * The originals are unusable for that: twelve Tasman images are 8 MB. This crops each to a square,
 * drops it to 160px, and packs the lot into a single file of ~40 KB, which is the same trade the
 * main atlas makes (tools/build-timewarp-atlas.py).
 *
 * The cell count lives in the filename (warp-atlas-12.webp) so the browser knows how to slice it
 * without a database column or a second request.
 */
final class BuildLessonWarpAtlas extends Command
{
    protected $signature = 'lessons:build-warp-atlas
                            {lesson? : Lesson id (default: the configured demo lesson)}
                            {--from= : Folder of curated square images to use instead of the lesson\'s own}
                            {--all : Build for every lesson the hero can show (config/demo.php)}
                            {--force : Rebuild even if an atlas already exists}';

    protected $description = "Pack a lesson's images into a sprite sheet for the hero timewarp";

    /** Cell size in the sheet. Matches the main atlas: cards are never drawn bigger than this. */
    private const CELL = 160;

    private const COLUMNS = 4;

    private const MAX_CELLS = 24;

    /** Crops taken from a single source when the lesson has too few pictures to fill the sheet. */
    private const MAX_CROPS_PER_IMAGE = 3;

    public function handle(): int
    {
        // The hero's tiles always come from whichever lesson it is showing, and that now varies
        // by country — so every lesson in the map needs its own sheet.
        if ($this->option('all')) {
            return $this->buildForEveryDemoLesson();
        }

        $lesson = $this->resolveLesson();

        if (! $lesson) {
            $this->error('No lesson found. Pass an id, or publish the demo lesson.');

            return self::FAILURE;
        }

        // A curated folder beats scraping the lesson's own artwork: hand-picked, already square,
        // and chosen to sit next to each other in a ring. Falls back to the lesson's images.
        $sources = $this->option('from')
            ? $this->curatedSourcePaths((string) $this->option('from'))
            : $this->localSourcePaths($lesson);
        $tiles = $this->tilePlan($sources);

        if ($sources === []) {
            $this->warn("Lesson {$lesson->id} has no usable local images — nothing to pack.");

            return self::SUCCESS;
        }

        // Decode FIRST, pack second. Packing as we go left a blank navy cell wherever an image
        // failed, and the ring then showed empty cards — a hole in a wheel whose whole point is
        // that every seat is filled.
        $decoded = [];

        foreach ($tiles as [$path, $offset, $of]) {
            $tile = $this->squareTile($path, $offset, $of);

            if ($tile) {
                $decoded[] = $tile;
            } else {
                $this->warn('  skipped (unreadable): '.basename($path));
            }
        }

        if ($decoded === []) {
            $this->warn("Lesson {$lesson->id}: nothing could be decoded — no atlas written.");

            return self::SUCCESS;
        }

        $this->clearExisting($lesson);

        $rows = (int) ceil(count($decoded) / self::COLUMNS);
        $sheet = imagecreatetruecolor(self::COLUMNS * self::CELL, $rows * self::CELL);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 2, 8, 26));

        foreach ($decoded as $index => $tile) {
            imagecopy(
                $sheet,
                $tile,
                ($index % self::COLUMNS) * self::CELL,
                intdiv($index, self::COLUMNS) * self::CELL,
                0, 0, self::CELL, self::CELL,
            );
            imagedestroy($tile);
        }

        $relative = "lessons/{$lesson->id}/warp-atlas-".count($decoded).'.webp';

        ob_start();
        imagewebp($sheet, null, 72);
        $binary = (string) ob_get_clean();
        imagedestroy($sheet);

        Storage::disk('public')->put($relative, $binary);

        $this->info(sprintf(
            'Packed %d tiles from %d images for "%s" → %s (%d KB)',
            count($decoded),
            count($sources),
            $lesson->title,
            $relative,
            (int) round(strlen($binary) / 1024),
        ));

        return self::SUCCESS;
    }

    /** One sheet per lesson named in config('demo.by_country'), plus the default. */
    private function buildForEveryDemoLesson(): int
    {
        // title => curated footage folder (or null). Keyed by title so two countries sharing a
        // lesson only build it once.
        $titles = collect((array) config('demo.by_country', []))
            ->mapWithKeys(fn (array $entry) => [$entry['title'] => $entry['footage'] ?? null])
            ->put(config('demo.lesson_title'), config('demo.lesson_footage'))
            ->filter(fn ($footage, $title) => (bool) $title);

        foreach ($titles as $title => $footage) {
            $lesson = Lesson::query()->where('title', $title)->first();

            if (! $lesson) {
                $this->warn("No lesson titled \"{$title}\" — skipped.");

                continue;
            }

            // Curated footage wins where somebody has chosen it (config `footage`).
            $this->call('lessons:build-warp-atlas', array_filter([
                'lesson' => $lesson->id,
                '--from' => $footage,
            ]));
        }

        return self::SUCCESS;
    }

    private function resolveLesson(): ?Lesson
    {
        $id = $this->argument('lesson');

        return $id
            ? Lesson::find((int) $id)
            : \App\Support\DemoLesson::resolve();
    }

    /**
     * Absolute paths of the lesson's images that live on this server. Remote artwork (Cloudinary,
     * Wikimedia) is skipped: the point is one small local file, and fetching the internet during
     * a build would make the command fail in ways that have nothing to do with the lesson.
     *
     * @return list<string>
     */
    private function localSourcePaths(Lesson $lesson): array
    {
        $paths = [];

        foreach ($lesson->posterCandidates() as $candidate) {
            if (count($paths) >= self::MAX_CELLS) {
                break;
            }

            $url = (string) ($candidate['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $path = $this->localPath($url);

            if ($path !== null && is_file($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Every image in a curated folder, in name order. Already-square footage needs no cropping
     * heuristics — the person who chose it framed it.
     *
     * @return list<string>
     */
    private function curatedSourcePaths(string $folder): array
    {
        $path = str_starts_with($folder, '/') ? $folder : base_path($folder);
        $files = glob(rtrim($path, '/').'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];

        sort($files, SORT_NATURAL);

        return array_slice($files, 0, self::MAX_CELLS);
    }

    /**
     * Map an image URL onto a file on this server, or null when it lives somewhere else.
     *
     * Absolute URLs are NOT automatically remote: most of a lesson's artwork is stored on the
     * public disk and comes back as `http://localhost:8000/storage/...` or the production host.
     * Rejecting anything with a scheme (the first version of this) threw away every image a
     * lesson had and reported "no usable local images" for lessons full of them.
     */
    private function localPath(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return null; // genuinely somewhere else — Wikimedia, Cloudinary
        }

        $relative = ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/');

        if ($relative === '') {
            return null;
        }

        if (str_starts_with($relative, 'storage/')) {
            return storage_path('app/public/'.substr($relative, strlen('storage/')));
        }

        return public_path($relative);
    }

    /**
     * Which square tiles to cut, and from where.
     *
     * A lesson with a dozen pictures cannot fill a sheet on its own, and repeating the same tile
     * makes the burst look like a loop. Wide artwork — a line of ships, a row of Māori waka, a
     * coastline — holds several distinct square pictures, so take a few windows across it rather
     * than one centre crop and call it a day.
     *
     * @param  list<string>  $sources
     * @return list<array{0: string, 1: int, 2: int}> path, crop index, crops from this image
     */
    private function tilePlan(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $perImage = min(
            self::MAX_CROPS_PER_IMAGE,
            max(1, (int) ceil(self::MAX_CELLS / count($sources))),
        );

        $tiles = [];

        foreach ($sources as $path) {
            // A near-square picture has nowhere to slide a second window, so it gets one tile.
            $crops = $this->isPanoramic($path) ? $perImage : 1;

            for ($crop = 0; $crop < $crops && count($tiles) < self::MAX_CELLS; $crop++) {
                $tiles[] = [$path, $crop, $crops];
            }
        }

        return $tiles;
    }

    /**
     * Decode any of the formats a lesson's artwork comes in.
     *
     * AVIF support is split down the middle of this project's own machines: GD on the SiteGround
     * server reads it, GD on macOS does not, and Imagick is the other way round. Trying GD first
     * and falling back to Imagick covers both without asking anybody to recompile PHP.
     */
    private function decode(string $path): ?\GdImage
    {
        $binary = (string) @file_get_contents($path);

        if ($binary === '') {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image) {
            return $image;
        }

        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick;
            $imagick->readImageBlob($binary);
            $imagick->setImageFormat('png');
            $converted = @imagecreatefromstring($imagick->getImageBlob());
            $imagick->clear();

            return $converted ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Wide (or tall) enough that separate square windows show genuinely different things. */
    private function isPanoramic(string $path): bool
    {
        $size = @getimagesize($path);

        if (! $size) {
            return false; // unknown shape (AVIF on some builds): one centre crop is the safe call
        }

        [$width, $height] = $size;
        $ratio = max($width, $height) / max(1, min($width, $height));

        return $ratio >= 1.25;
    }

    /**
     * One CELL-sized square window of an image. `$offset` of `$of` slides the window evenly
     * across the long edge, so three crops of a fleet give three different ships.
     */
    private function squareTile(string $path, int $offset = 0, int $of = 1): ?\GdImage
    {
        $source = $this->decode($path);

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $slack = max($width, $height) - $side;
        // Spread the windows over the slack: centred when there is only one.
        $step = $of > 1 ? (int) round(($slack * $offset) / ($of - 1)) : intdiv($slack, 2);

        $tile = imagecreatetruecolor(self::CELL, self::CELL);
        imagecopyresampled(
            $tile,
            $source,
            0, 0,
            $width >= $height ? $step : 0,
            $width >= $height ? 0 : $step,
            self::CELL, self::CELL,
            $side, $side,
        );
        imagedestroy($source);

        return $tile;
    }

    /** Drop any earlier sheet, whose cell count (and so its filename) may have changed. */
    private function clearExisting(Lesson $lesson): void
    {
        foreach (Storage::disk('public')->files("lessons/{$lesson->id}") as $file) {
            if (str_contains(basename($file), 'warp-atlas-')) {
                Storage::disk('public')->delete($file);
            }
        }
    }
}
