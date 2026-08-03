<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Cloudinary uploader — hosts teacher-uploaded and AI-generated imagery off our own
 * storage/bandwidth. Uses Cloudinary's signed REST upload directly (no SDK dependency), driven by
 * the standard CLOUDINARY_URL (cloudinary://<api_key>:<api_secret>@<cloud_name>).
 *
 * Not configured → every method returns null so callers cleanly fall back to local storage.
 */
class CloudinaryService
{
    /**
     * Delivery transformation baked into every returned URL:
     *  - f_auto  → Cloudinary picks the best format per browser (AVIF > WebP > JP/PNG) — big savings.
     *  - q_auto  → perceptual auto-quality (typically 40-70% smaller than the original, no visible loss).
     *  - c_limit,w_2400 → only DOWNSCALES oversized uploads; never upscales, so small images pass through.
     * Applied at delivery (not on the stored master) so we keep the original and can re-derive later.
     */
    private const DELIVERY_TRANSFORM = 'f_auto,q_auto,c_limit,w_2400';

    /**
     * Delivery transformation for lesson card posters. A card is drawn at 240x360 CSS px, so 480
     * wide covers it on a retina screen and anything beyond that is bytes nobody sees. WebP at 60
     * rather than the q_auto used elsewhere: these are small, heavily scaled-down thumbnails where
     * the extra compression is invisible, and it is what the poster shelves asked for.
     */
    private const COVER_TRANSFORM = 'f_webp,q_60,c_limit,w_480';

    /** How many of our own images the library shelf pulls in one Admin API call. */
    private const LIBRARY_LIMIT = 60;

    private ?string $cloud;

    private ?string $key;

    private ?string $secret;

    public function __construct()
    {
        $parsed = $this->parse((string) config('services.cloudinary.url'));
        $this->cloud = $parsed['cloud'] ?? null;
        $this->key = $parsed['key'] ?? null;
        $this->secret = $parsed['secret'] ?? null;
    }

    /** Our cloud name — the account segment of every delivery URL we own. */
    public function cloudName(): ?string
    {
        return $this->cloud;
    }

    public function configured(): bool
    {
        return (bool) ($this->cloud && $this->key && $this->secret);
    }

    /**
     * Upload raw image bytes; returns the optimized Cloudinary URL, or null on failure/not-configured.
     * Passing $publicId makes the upload deterministic (overwrite=true) so re-runs replace the same
     * asset instead of piling up duplicates — used by content builders that rebuild on every run.
     */
    public function uploadBytes(string $bytes, string $folder = 'lessons', ?string $publicId = null, string $transform = self::DELIVERY_TRANSFORM): ?string
    {
        if (! $this->configured() || $bytes === '') {
            return null;
        }

        // Cloudinary signs the alphabetically-sorted upload params (excluding file/api_key/signature).
        $timestamp = time();
        $params = ['timestamp' => $timestamp];
        if ($publicId !== null) {
            $params['public_id'] = $publicId;
            $params['overwrite'] = 'true';
            $params['invalidate'] = 'true';   // bust the CDN cache when we replace an asset
        } else {
            $params['folder'] = $folder;
        }
        ksort($params);
        $toSign = collect($params)->map(fn ($v, $k) => "$k=$v")->implode('&');
        $signature = sha1($toSign.$this->secret);

        try {
            $response = Http::timeout(30)
                ->attach('file', $bytes, 'upload')
                ->post("https://api.cloudinary.com/v1_1/{$this->cloud}/image/upload", $params + [
                    'api_key' => $this->key,
                    'signature' => $signature,
                ]);

            $url = $response->json('secure_url');
            if ($response->successful() && $url) {
                return $this->optimize($url, $transform);
            }
            report(new \RuntimeException('Cloudinary upload failed: HTTP '.$response->status().' '.$response->body()));
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    /** Upload a local file by path. */
    public function uploadFile(string $absolutePath, string $folder = 'lessons', ?string $publicId = null): ?string
    {
        return is_file($absolutePath) ? $this->uploadBytes((string) file_get_contents($absolutePath), $folder, $publicId) : null;
    }

    /**
     * Fetch a remote image and host it on Cloudinary. Returns the optimized Cloudinary URL, or the
     * original $url as a graceful fallback when Cloudinary isn't configured or the fetch/upload fails
     * (so a lesson still shows the image rather than a broken tile). A User-Agent is required — bare
     * requests to Wikimedia Commons are rejected.
     */
    public function uploadFromUrl(string $url, string $folder = 'lessons', ?string $publicId = null): string
    {
        if (! $this->configured() || ! preg_match('#^https?://#i', $url)) {
            return $url;
        }
        try {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'TheLearningPortal/1.0 (educational; +https://thelearningportal.us)'])
                ->get($url);
            if ($response->successful()) {
                $hosted = $this->uploadBytes($response->body(), $folder, $publicId);
                if ($hosted !== null) {
                    return $hosted;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $url;
    }

    /**
     * Upload a lesson's card poster and return a CDN URL that delivers it as a small WebP.
     * The public id is deterministic, so regenerating a cover replaces the same asset (and busts
     * the CDN cache) instead of leaving an orphan behind.
     */
    public function uploadCover(string $bytes, int $lessonId): ?string
    {
        return $this->uploadBytes($bytes, 'lessons', "lessons/{$lessonId}/cover", self::COVER_TRANSFORM);
    }

    /**
     * Everything this account already holds, newest first — our own image library.
     *
     * Read through the Admin search API (same key/secret, Basic auth). Cached for 15 minutes:
     * the picker that renders this re-evaluates on every Livewire round trip, and the Admin API
     * is rate limited per hour.
     *
     * Lesson card posters are skipped. They are 480px derivatives of images that are already in
     * this list, so offering them as slide imagery only hands the teacher a blurry duplicate.
     *
     * @return array<int,array{url:string,thumb:string,title:string,public_id:string,width:?int,height:?int}>
     */
    public function library(string $term = '', int $limit = self::LIBRARY_LIMIT): array
    {
        if (! $this->configured()) {
            return [];
        }

        $expression = 'resource_type:image';
        foreach ($this->searchWords($term) as $word) {
            $expression .= " AND (public_id:*{$word}* OR tags:{$word})";
        }

        return Cache::remember(
            'cloudinary.library.v1.'.md5($expression.'|'.$limit),
            now()->addMinutes(15),
            function () use ($expression, $limit): array {
                try {
                    $response = Http::withBasicAuth((string) $this->key, (string) $this->secret)
                        ->timeout(20)
                        ->post("https://api.cloudinary.com/v1_1/{$this->cloud}/resources/search", [
                            'expression' => $expression,
                            'max_results' => $limit,
                            'sort_by' => [['created_at' => 'desc']],
                        ]);
                    if (! $response->successful()) {
                        report(new \RuntimeException('Cloudinary search failed: HTTP '.$response->status().' '.$response->body()));

                        return [];
                    }
                } catch (\Throwable $e) {
                    report($e);

                    return [];
                }

                return collect($response->json('resources') ?? [])
                    ->filter(fn (array $r) => ! str_ends_with((string) ($r['public_id'] ?? ''), '/cover'))
                    ->map(fn (array $r) => [
                        'url' => $this->optimize((string) $r['secure_url']),
                        'thumb' => $this->thumb((string) $r['secure_url']),
                        'title' => basename((string) $r['public_id']),
                        'public_id' => (string) $r['public_id'],
                        'width' => isset($r['width']) ? (int) $r['width'] : null,
                        'height' => isset($r['height']) ? (int) $r['height'] : null,
                    ])
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * A grid-card rendition of a Cloudinary image. Unlike optimize() this REPLACES any transform
     * the URL already carries — a stored lesson image is delivered at w_2400, and a picker tile is
     * 400px wide, so passing that URL through untouched would download six times the pixels shown.
     */
    public function thumb(string $url, int $width = 400, int $height = 250): string
    {
        if (! str_contains($url, 'res.cloudinary.com') || ! str_contains($url, '/image/upload/')) {
            return $url;
        }

        // The optional group eats an existing transform segment (always contains a comma), leaving
        // any /v123456/ version segment and the public id in place.
        return preg_replace(
            '#/image/upload/(?:[^/]*,[^/]*/)?#',
            "/image/upload/c_fill,w_{$width},h_{$height},f_auto,q_auto/",
            $url,
            1,
        ) ?? $url;
    }

    /**
     * Split a teacher's search box into safe search-expression words. Everything that could carry
     * expression syntax (quotes, colons, parentheses, wildcards) is stripped rather than escaped.
     *
     * @return array<int,string>
     */
    private function searchWords(string $term): array
    {
        return collect(preg_split('/\s+/', trim($term)) ?: [])
            ->map(fn (string $w) => preg_replace('/[^\p{L}\p{N}_-]/u', '', $w) ?? '')
            ->filter(fn (string $w) => mb_strlen($w) >= 2)
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * Insert a delivery transformation into a Cloudinary URL so it serves AVIF/WebP at the right
     * size. Idempotent — a URL that already carries a transform, or a non-Cloudinary URL, is
     * returned untouched.
     */
    public function optimize(string $url, string $transform = self::DELIVERY_TRANSFORM): string
    {
        if (! str_contains($url, 'res.cloudinary.com') || ! str_contains($url, '/image/upload/')) {
            return $url;
        }
        if (str_contains($url, '/upload/'.$transform.'/') || str_contains($url, 'f_auto') || str_contains($url, 'f_webp')) {
            return $url;
        }

        return str_replace('/image/upload/', '/image/upload/'.$transform.'/', $url);
    }

    /** @return array{cloud:?string,key:?string,secret:?string} */
    private function parse(string $url): array
    {
        if ($url === '') {
            return [];
        }
        $p = @parse_url($url);
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'cloudinary') {
            return [];
        }

        return ['cloud' => $p['host'] ?? null, 'key' => $p['user'] ?? null, 'secret' => $p['pass'] ?? null];
    }
}
