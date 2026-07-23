<?php

declare(strict_types=1);

namespace App\Services;

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

    public function configured(): bool
    {
        return (bool) ($this->cloud && $this->key && $this->secret);
    }

    /**
     * Upload raw image bytes; returns the optimized Cloudinary URL, or null on failure/not-configured.
     * Passing $publicId makes the upload deterministic (overwrite=true) so re-runs replace the same
     * asset instead of piling up duplicates — used by content builders that rebuild on every run.
     */
    public function uploadBytes(string $bytes, string $folder = 'lessons', ?string $publicId = null): ?string
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
                return $this->optimize($url);
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
     * Insert the delivery transformation (f_auto,q_auto,c_limit,w_2400) into a Cloudinary URL so it
     * serves AVIF/WebP at auto-quality. Idempotent — a URL that already carries the transform, or a
     * non-Cloudinary URL, is returned untouched.
     */
    public function optimize(string $url): string
    {
        if (! str_contains($url, 'res.cloudinary.com') || ! str_contains($url, '/image/upload/')) {
            return $url;
        }
        if (str_contains($url, '/upload/'.self::DELIVERY_TRANSFORM.'/') || str_contains($url, 'f_auto')) {
            return $url;
        }

        return str_replace('/image/upload/', '/image/upload/'.self::DELIVERY_TRANSFORM.'/', $url);
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
