<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-render small, cropped, LOCAL WebP cover images for playable lessons so the public landing
 * page never streams a multi-MB Wikimedia original (or a 3.86 MB skybox PNG) straight into an
 * <img src>. Each cover is a 480x768 (5:8) center-cropped WebP saved to the 'public' disk at
 * lessons/{id}/cover.webp. Idempotent: existing covers are skipped unless --force.
 *
 * The SOURCE image is resolved with the SAME priority chain as Lesson::cardImageUrl():
 *   1. Protagonist portrait (corpus P18) — full-size original, for the best crop.
 *   2. First scene skybox/base image.
 *   3. Editorial hero image (worldhistory.org).
 *   4. Wikipedia title-screen image.
 */
final class GenerateLessonCovers extends Command
{
    protected $signature = 'app:generate-lesson-covers
        {--force : regenerate even if a cover exists}
        {--lesson= : only this lesson id}';

    protected $description = 'Generate small cropped local WebP cover images for playable lessons';

    /** Final cover geometry (5:8 portrait) and encode quality. */
    private const COVER_WIDTH = 480;

    private const COVER_HEIGHT = 768;

    private const COVER_QUALITY = 72;

    /** Bounded width fetched for a Wikimedia Special:FilePath source (avoids 12 MB originals). */
    private const SOURCE_FETCH_WIDTH = 1000;

    /** Wikimedia 403s requests without a User-Agent. */
    private const HTTP_USER_AGENT = 'TheLearningPortal/1.0 (https://thelearningportal.us)';

    public function handle(): int
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            $this->error('GD with WebP support is required (imagewebp not available).');

            return self::FAILURE;
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->playableLessons() as $lesson) {
            $relativePath = "lessons/{$lesson->id}/cover.webp";

            if (! $this->option('force') && Storage::disk('public')->exists($relativePath)) {
                $this->line("  <comment>skipped</comment>  lesson {$lesson->id} ({$lesson->lesson_code}) — cover exists");
                $skipped++;

                continue;
            }

            try {
                $sourceUrl = $this->resolveSourceUrl($lesson);
                if ($sourceUrl === null) {
                    throw new \RuntimeException('no source image available');
                }

                $sourceBytes = $this->fetchSourceBytes($sourceUrl);
                $coverBytes = $this->renderCover($sourceBytes);

                Storage::disk('public')->put($relativePath, $coverBytes);

                $kb = (int) round(strlen($coverBytes) / 1024);
                $this->line("  <info>OK</info>       lesson {$lesson->id} ({$lesson->lesson_code}) — {$kb} KB");
                $generated++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>failed</fg=red>   lesson {$lesson->id} ({$lesson->lesson_code}) — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Generated {$generated}, skipped {$skipped}, failed {$failed}.");

        return $failed > 0 && $generated === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Playable lessons: Published or Previewable, with a non-null lesson_code. Eager-loads the
     * relations the source-resolution chain reads so per-lesson resolution stays query-light.
     *
     * @return \Illuminate\Support\Collection<int, Lesson>
     */
    private function playableLessons(): \Illuminate\Support\Collection
    {
        $query = Lesson::query()
            ->whereIn('status', [LessonStatus::Published, LessonStatus::Previewable])
            ->whereNotNull('lesson_code')
            ->with(['firstScene', 'source']);

        if ($lessonId = $this->option('lesson')) {
            $query->whereKey($lessonId);
        }

        return $query->get();
    }

    /**
     * Resolve a SOURCE image URL using the same priority as Lesson::cardImageUrl(), but prefer the
     * FULL-size protagonist original (best crop quality) over the bounded fallback thumbnail.
     */
    private function resolveSourceUrl(Lesson $lesson): ?string
    {
        // 1. People: full-size scenic portrait of the protagonist (corpus P18), unbounded.
        if ($url = $lesson->protagonistImageUrlFull()) {
            return $url;
        }

        // 2. First scene's generated panorama (skybox preferred, then the base scene image).
        if ($lesson->relationLoaded('firstScene') && $lesson->firstScene) {
            $url = $lesson->coverSourceMediaUrl($lesson->firstScene->skybox_image_path)
                ?? $lesson->coverSourceMediaUrl($lesson->firstScene->image_path);
            if ($url) {
                return $url;
            }
        }

        // 3. Editorial hero image (worldhistory.org), when the source provided one.
        if ($lesson->relationLoaded('source') && $lesson->source?->hero_image_path) {
            if ($url = $lesson->coverSourceMediaUrl($lesson->source->hero_image_path)) {
                return $url;
            }
        }

        // 4. Wikipedia title-screen image.
        if ($lesson->title_bg_path) {
            if ($url = $lesson->coverSourceMediaUrl($lesson->title_bg_path)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Fetch the raw source bytes. For Wikimedia Special:FilePath URLs, request a bounded width so
     * we never pull a multi-MB original, and always send a User-Agent (Wikimedia 403s without one).
     */
    private function fetchSourceBytes(string $url): string
    {
        $url = $this->boundWikimediaWidth($url, self::SOURCE_FETCH_WIDTH);

        $response = Http::withHeaders(['User-Agent' => self::HTTP_USER_AGENT])
            ->timeout(60)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("fetch failed (HTTP {$response->status()})");
        }

        $body = $response->body();
        if ($body === '') {
            throw new \RuntimeException('fetch returned an empty body');
        }

        return $body;
    }

    /**
     * Cover-fit crop the source to 5:8 (centered), resize to 480x768, encode WebP. Uses GD, which
     * is available on both local dev and the SiteGround host (Imagick is not installed in prod).
     * Resampling into a fresh truecolor canvas drops any source metadata.
     */
    private function renderCover(string $sourceBytes): string
    {
        $src = @imagecreatefromstring($sourceBytes);
        if ($src === false) {
            throw new \RuntimeException('unsupported or corrupt image data');
        }

        try {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $targetRatio = self::COVER_WIDTH / self::COVER_HEIGHT; // 5:8 = 0.625
            $srcRatio = $sw / max(1, $sh);

            // COVER fit: crop the source to the target ratio (centered), then resample to 480x768.
            if ($srcRatio > $targetRatio) {
                $cropH = $sh;
                $cropW = (int) round($sh * $targetRatio);
                $cropX = (int) round(($sw - $cropW) / 2);
                $cropY = 0;
            } else {
                $cropW = $sw;
                $cropH = (int) round($sw / $targetRatio);
                $cropX = 0;
                $cropY = (int) round(($sh - $cropH) / 2);
            }

            $dst = imagecreatetruecolor(self::COVER_WIDTH, self::COVER_HEIGHT);
            imagecopyresampled(
                $dst, $src,
                0, 0, $cropX, $cropY,
                self::COVER_WIDTH, self::COVER_HEIGHT,
                max(1, $cropW), max(1, $cropH)
            );

            ob_start();
            imagewebp($dst, null, self::COVER_QUALITY);
            $webp = (string) ob_get_clean();
            imagedestroy($dst);

            if ($webp === '') {
                throw new \RuntimeException('WebP encode produced no output');
            }

            return $webp;
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * Append a bounded thumbnail width to a Wikimedia Special:FilePath URL, preserving any existing
     * query string. Non-Wikimedia URLs are returned unchanged.
     */
    private function boundWikimediaWidth(string $url, int $width): string
    {
        if (! str_contains($url, 'Special:FilePath')) {
            return $url;
        }

        if (str_contains($url, 'width=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'width='.$width;
    }
}
