<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\CloudinaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-render small, cropped WebP cover images for lesson cards so no shelf — the public landing
 * page or a teacher's own lesson list — ever streams a multi-MB Wikimedia original (or a 3.86 MB
 * skybox PNG) straight into an <img src>. Each cover is a 480x768 (5:8) center-cropped WebP:
 * written to the 'public' disk at lessons/{id}/cover.webp, and uploaded to Cloudinary when it is
 * configured so cards are served from the CDN (Lesson::coverImageUrl prefers the CDN copy).
 * Idempotent: existing covers are skipped unless --force.
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
        {--lesson= : only this lesson id}
        {--playable-only : only Published/Previewable lessons (default covers a teacher\'s drafts too)}
        {--no-cdn : keep the cover local even when Cloudinary is configured}';

    protected $description = 'Generate small cropped WebP cover images for lesson cards, hosted on the CDN';

    /**
     * Final cover geometry (5:8 portrait) and encode quality. A card is drawn at 240x360 CSS px,
     * so 480 wide still looks sharp on a retina screen; quality 60 is plenty at that scale and
     * matches the CDN's delivery transform, so the bytes are small at both ends.
     */
    private const COVER_WIDTH = 480;

    private const COVER_HEIGHT = 768;

    private const COVER_QUALITY = 60;

    /** Bounded width fetched for a Wikimedia Special:FilePath source (avoids 12 MB originals). */
    private const SOURCE_FETCH_WIDTH = 1000;

    /** Wikimedia 403s requests without a User-Agent. */
    private const HTTP_USER_AGENT = 'TheLearningPortal/1.0 (https://thelearningportal.us)';

    /**
     * GD decodes to an uncompressed truecolor canvas — 4 bytes a pixel — so a 8000x5000 skybox
     * wants 160 MB before anything is resized. Give this command room, and refuse anything past
     * the budget with a readable message rather than dying on a fatal mid-run.
     */
    private const MEMORY_LIMIT = '512M';

    private const MAX_SOURCE_MEGAPIXELS = 80;

    public function handle(CloudinaryService $cloudinary): int
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            $this->error('GD with WebP support is required (imagewebp not available).');

            return self::FAILURE;
        }

        @ini_set('memory_limit', self::MEMORY_LIMIT);

        $useCdn = ! $this->option('no-cdn') && $cloudinary->configured();
        $this->line($useCdn
            ? 'Covers will be hosted on Cloudinary.'
            : 'Cloudinary not configured (or --no-cdn) — covers stay on the public disk.');
        $this->newLine();

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->coverableLessons() as $lesson) {
            $relativePath = "lessons/{$lesson->id}/cover.webp";

            // A cover is only done when it is where it should be SERVED from: with the CDN on, a
            // local file that was never uploaded still leaves the card pulling off our own box.
            $hasCover = Storage::disk('public')->exists($relativePath)
                && (! $useCdn || trim((string) $lesson->cover_url) !== '');

            if (! $this->option('force') && $hasCover) {
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

                // Keep the local copy either way: it is the fallback whenever the CDN URL is
                // missing, and the source for a later re-upload without re-fetching anything.
                Storage::disk('public')->put($relativePath, $coverBytes);

                $where = 'local';
                if ($useCdn) {
                    $cdnUrl = $cloudinary->uploadCover($coverBytes, (int) $lesson->id);
                    if ($cdnUrl === null) {
                        throw new \RuntimeException('CDN upload failed (cover kept locally)');
                    }
                    $lesson->forceFill(['cover_url' => $cdnUrl])->save();
                    $where = 'CDN';
                }

                $kb = (int) round(strlen($coverBytes) / 1024);
                $this->line("  <info>OK</info>       lesson {$lesson->id} ({$lesson->lesson_code}) — {$kb} KB, {$where}");
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
     * Every lesson that appears on a card shelf. That is wider than "playable": the teacher's own
     * shelves list drafts too, and those were the cards still pulling a full scene background
     * because no cover had ever been rendered for them. --playable-only restores the old scope
     * (Published/Previewable, the public landing page).
     *
     * Eager-loads the relations the source-resolution chain reads so per-lesson resolution stays
     * query-light.
     *
     * @return \Illuminate\Support\Collection<int, Lesson>
     */
    private function coverableLessons(): \Illuminate\Support\Collection
    {
        $query = Lesson::query()->with(['firstScene', 'source']);

        if ($this->option('playable-only')) {
            $query->whereIn('status', [LessonStatus::Published, LessonStatus::Previewable])
                ->whereNotNull('lesson_code');
        }

        if ($lessonId = $this->option('lesson')) {
            $query->whereKey($lessonId);
        }

        return $query->get();
    }

    /**
     * The image to render the cover FROM: the same chain a card would show, except the full-size
     * protagonist original is preferred over the bounded thumbnail because it crops better.
     *
     * The chain itself lives on the model (Lesson::cardImageSourceUrl) so the two can never drift
     * apart again — a lesson whose card has a picture always has something to render a cover from.
     */
    private function resolveSourceUrl(Lesson $lesson): ?string
    {
        $source = $lesson->protagonistImageUrlFull() ?: $lesson->cardImageSourceUrl();
        if ($source === null || $source === '') {
            return null;
        }

        // Some steps of the chain (the curated slideshow art) hand back a bare path rather than a
        // URL — "lessons/tasman/vloot-rennefeld.webp" — which curl reads as the host "lessons".
        // Such a path is either on the public disk or checked into the webroot; try both.
        if (preg_match('#^(https?:)?//#i', $source)) {
            return $source;
        }

        $path = ltrim($source, '/');

        return Storage::disk('public')->exists($path)
            ? $lesson->coverSourceMediaUrl($path)
            : url('/'.$path);
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
        // Measure before decoding: getimagesizefromstring only reads the header, so an image too
        // big to fit in memory is reported instead of killing the whole run on a fatal.
        $info = @getimagesizefromstring($sourceBytes);
        if (is_array($info)) {
            $megapixels = ($info[0] * $info[1]) / 1_000_000;
            if ($megapixels > self::MAX_SOURCE_MEGAPIXELS) {
                throw new \RuntimeException(sprintf(
                    'source too large to decode (%dx%d, %.0f MP)', $info[0], $info[1], $megapixels
                ));
            }
        }

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
