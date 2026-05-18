<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Support\ImageStyleTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiImageService
{
    /** Pixel-channel value below which we treat a pixel as "black bar" filler. */
    private const BLACK_BAR_THRESHOLD = 12;

    /** Fraction of a row that must be near-black before we trim it. */
    private const BLACK_BAR_ROW_RATIO = 0.98;

    public function generate(
        string $seedPrompt,
        string $style,
        string $destination,
        bool   $isGame = false,
    ): string {
        $prompt = ImageStyleTemplate::build($seedPrompt, $style, $isGame);

        // Single API call — we ask for letterbox black bars so the panorama itself
        // is the desired 2:1 band inside whatever frame gpt-image-1 supports
        // (1024 square or 1536×1024). The post-processor then trims the bars and
        // (optionally) center-crops to a clean 2:1.
        $bytes = $this->requestOne($prompt, (string) config('services.openai.image_size', '1536x1024'));
        $bytes = $this->trimBlackBars($bytes);
        $bytes = $this->cropTo2x1($bytes);
        $bytes = $this->upscaleWithFalai($bytes);

        Storage::disk('public')->put($destination, $bytes);

        return $destination;
    }

    /**
     * Post-process the panorama with a fal.ai upscaler (Real-ESRGAN-class). Doubles
     * the effective resolution so the texture wrapped on the sphere holds detail
     * even when the teacher orbits close. Failures fall through gracefully — the
     * original bytes are returned and the lesson still works.
     */
    private function upscaleWithFalai(string $bytes): string
    {
        if (! (bool) config('services.falai.upscale_enabled', false)) {
            return $bytes;
        }
        $key = (string) config('services.falai.api_key', '');
        if ($key === '') {
            return $bytes;
        }

        $model         = (string) config('services.falai.upscale_model', 'fal-ai/clarity-upscaler');
        $scale         = (int)    config('services.falai.upscale_factor', 2);
        $timeout       = (int)    config('services.falai.timeout', 60);
        $connectTimeout = (int)   config('services.falai.connect_timeout', 10);

        try {
            // clarity-upscaler rejects data: URIs (422 "Failed to load the image"),
            // so upload to fal storage first and pass the returned public URL.
            $uploadedUrl = $this->uploadToFalStorage($bytes, $key, $connectTimeout, $timeout);
            if ($uploadedUrl === null) {
                return $bytes;
            }

            $res = Http::withHeaders(['Authorization' => 'Key ' . $key])
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->acceptJson()
                ->post("https://fal.run/{$model}", [
                    'image_url'     => $uploadedUrl,
                    'scale_factor'  => $scale,
                    'output_format' => 'webp',
                ]);

            if (! $res->successful()) {
                Log::warning('[fal.ai] upscale ' . $res->status() . ': ' . substr($res->body(), 0, 200));
                return $bytes;
            }

            $url = $res->json('image.url')
                ?? $res->json('images.0.url')
                ?? $res->json('output.url')
                ?? null;
            if (! is_string($url) || $url === '') {
                Log::warning('[fal.ai] upscale response missing image url', ['body' => substr($res->body(), 0, 200)]);
                return $bytes;
            }

            $dl = Http::timeout(60)->get($url);
            if (! $dl->successful()) {
                Log::warning('[fal.ai] failed to download upscaled image: ' . $dl->status());
                return $bytes;
            }

            // fal.ai may return png or webp depending on the model — normalise to webp
            // at our configured quality so storage stays consistent.
            $normalized = $this->reencodeWebp($dl->body());
            return $normalized !== '' ? $normalized : $bytes;
        } catch (\Throwable $e) {
            Log::warning('[fal.ai] upscale exception: ' . $e->getMessage());
            return $bytes;
        }
    }

    /**
     * Upload bytes to fal.ai's storage and return a public URL we can hand to
     * any fal.run model. Two-step flow: initiate (returns presigned PUT URL +
     * resulting file URL), then PUT the bytes.
     */
    private function uploadToFalStorage(string $bytes, string $key, int $connectTimeout, int $timeout): ?string
    {
        try {
            $initiate = Http::withHeaders(['Authorization' => 'Key ' . $key])
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->acceptJson()
                ->asJson()
                ->post('https://rest.alpha.fal.ai/storage/upload/initiate', [
                    'content_type' => 'image/webp',
                    'file_name'    => 'skybox.webp',
                ]);

            if (! $initiate->successful()) {
                Log::warning('[fal.ai] storage initiate ' . $initiate->status() . ': ' . substr($initiate->body(), 0, 200));
                return null;
            }

            $uploadUrl = $initiate->json('upload_url');
            $fileUrl   = $initiate->json('file_url');
            if (! is_string($uploadUrl) || ! is_string($fileUrl)) {
                Log::warning('[fal.ai] storage initiate missing url(s)', ['body' => substr($initiate->body(), 0, 300)]);
                return null;
            }

            $put = Http::withHeaders(['Content-Type' => 'image/webp'])
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withBody($bytes, 'image/webp')
                ->put($uploadUrl);

            if (! $put->successful()) {
                Log::warning('[fal.ai] storage PUT ' . $put->status() . ': ' . substr($put->body(), 0, 200));
                return null;
            }

            return $fileUrl;
        } catch (\Throwable $e) {
            Log::warning('[fal.ai] storage upload exception: ' . $e->getMessage());
            return null;
        }
    }

    private function reencodeWebp(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }
        $out = $this->encodeWebp($img);
        imagedestroy($img);
        return $out;
    }

    /**
     * Call the OpenAI images/generations endpoint once and return the raw image bytes.
     */
    private function requestOne(string $prompt, string $size): string
    {
        $base = (string) config('services.openai.base_url');
        $key  = (string) config('services.openai.api_key');

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.openai.timeout', 60))
                ->retry(times: 3, sleepMilliseconds: 2000, when: fn ($e, $req) => true, throw: false)
                ->post(rtrim($base, '/') . '/images/generations', [
                    'model'              => config('services.openai.image_model'),
                    'prompt'             => $prompt,
                    'size'               => $size,
                    'output_format'      => (string) config('services.openai.image_format', 'webp'),
                    'output_compression' => (int) config('services.openai.image_compression', 50),
                    'n'                  => 1,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Image API request failed: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Image API returned {$response->status()}: {$response->body()}");
        }

        $payload = $response->json('data.0') ?? [];

        if (is_string($payload['b64_json'] ?? null) && $payload['b64_json'] !== '') {
            $bytes = base64_decode($payload['b64_json'], true);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }
        if (is_string($payload['url'] ?? null) && $payload['url'] !== '') {
            $binary = Http::timeout(30)->get($payload['url']);
            if (! $binary->successful()) {
                throw new RuntimeException("Failed to download generated image: {$binary->status()}");
            }
            return $binary->body();
        }

        throw new RuntimeException('Image API returned neither url nor b64_json.');
    }

    /**
     * Detect and crop solid-black letterbox bars on the top and bottom (and, defensively,
     * left/right) of the returned image. The model is asked to letterbox to 2:1; this
     * trims whatever bars actually came back.
     */
    private function trimBlackBars(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $top    = 0;
        $bottom = $h - 1;
        $left   = 0;
        $right  = $w - 1;

        while ($top    < $bottom && $this->isBlackRow($img,    $top,    $w))    $top++;
        while ($bottom > $top    && $this->isBlackRow($img,    $bottom, $w))    $bottom--;
        while ($left   < $right  && $this->isBlackColumn($img, $left,   $h))    $left++;
        while ($right  > $left   && $this->isBlackColumn($img, $right,  $h))    $right--;

        $newW = $right  - $left + 1;
        $newH = $bottom - $top  + 1;

        // No meaningful trim → leave the bytes alone.
        if ($newW >= $w && $newH >= $h) {
            imagedestroy($img);
            return $bytes;
        }
        if ($newW <= 0 || $newH <= 0) {
            imagedestroy($img);
            return $bytes;
        }

        $cropped = imagecrop($img, [
            'x'      => $left,
            'y'      => $top,
            'width'  => $newW,
            'height' => $newH,
        ]);
        imagedestroy($img);

        if ($cropped === false) {
            return $bytes;
        }

        $out = $this->encodeWebp($cropped);
        imagedestroy($cropped);
        return $out !== '' ? $out : $bytes;
    }

    private function isBlackRow(\GdImage $img, int $y, int $w): bool
    {
        $step      = max(1, (int) ($w / 64));      // sample ~64 columns
        $samples   = 0;
        $blackCnt  = 0;
        for ($x = 0; $x < $w; $x += $step) {
            $samples++;
            if ($this->isBlackPixel($img, $x, $y)) $blackCnt++;
        }
        return $samples > 0 && ($blackCnt / $samples) >= self::BLACK_BAR_ROW_RATIO;
    }

    private function isBlackColumn(\GdImage $img, int $x, int $h): bool
    {
        $step      = max(1, (int) ($h / 64));
        $samples   = 0;
        $blackCnt  = 0;
        for ($y = 0; $y < $h; $y += $step) {
            $samples++;
            if ($this->isBlackPixel($img, $x, $y)) $blackCnt++;
        }
        return $samples > 0 && ($blackCnt / $samples) >= self::BLACK_BAR_ROW_RATIO;
    }

    private function isBlackPixel(\GdImage $img, int $x, int $y): bool
    {
        $rgb = imagecolorat($img, $x, $y);
        $r   = ($rgb >> 16) & 0xFF;
        $g   = ($rgb >> 8)  & 0xFF;
        $b   =  $rgb        & 0xFF;
        return max($r, $g, $b) <= self::BLACK_BAR_THRESHOLD;
    }

    private function cropTo2x1(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $targetH = (int) round($w / 2);

        if ($h <= $targetH) {
            imagedestroy($img);
            return $bytes;
        }

        $cropped = imagecrop($img, [
            'x'      => 0,
            'y'      => (int) round(($h - $targetH) / 2),
            'width'  => $w,
            'height' => $targetH,
        ]);
        imagedestroy($img);

        if ($cropped === false) {
            return $bytes;
        }

        $out = $this->encodeWebp($cropped);
        imagedestroy($cropped);
        return $out !== '' ? $out : $bytes;
    }

    private function encodeWebp(\GdImage $img): string
    {
        $quality = (int) config('services.openai.image_compression', 50);
        ob_start();
        imagewebp($img, null, $quality);
        return (string) ob_get_clean();
    }
}
