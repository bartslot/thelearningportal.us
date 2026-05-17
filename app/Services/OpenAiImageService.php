<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Support\ImageStyleTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiImageService
{
    public function generate(
        string $seedPrompt,
        string $style,
        string $destination,
        bool   $isGame = false,
    ): string {
        $prompt = ImageStyleTemplate::build($seedPrompt, $style, $isGame);

        $stitch = (bool) config('services.openai.image_stitch', true);

        if ($stitch) {
            // Two 1024×1024 generations stitched horizontally → 2048×1024 (true 2:1
            // panorama, ~33% more horizontal resolution than a single 1536-wide image).
            // The left half is told it's the LEFT, the right half is told the RIGHT,
            // so gpt-image-1 at least varies composition rather than rendering the
            // same shot twice.
            $left  = $this->requestOne($prompt . ' Composition: left half of a continuous panorama, the scene continues off to the right.',  '1024x1024');
            $right = $this->requestOne($prompt . ' Composition: right half of a continuous panorama, the scene continues off to the left.', '1024x1024');
            $bytes = $this->stitchAndSoften($left, $right);
        } else {
            $bytes = $this->requestOne($prompt, (string) config('services.openai.image_size', '1536x1024'));
            $bytes = $this->cropTo2x1($bytes);
        }

        Storage::disk('public')->put($destination, $bytes);

        return $destination;
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
     * Place two images side-by-side and fade the outer left + right edges to black
     * so the wrap-around seam on the skybox sphere is no longer a hard cut.
     */
    private function stitchAndSoften(string $leftBytes, string $rightBytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $leftBytes; // GD missing — best-effort fallback to a single image
        }

        $left  = @imagecreatefromstring($leftBytes);
        $right = @imagecreatefromstring($rightBytes);
        if ($left === false || $right === false) {
            if ($left  !== false) imagedestroy($left);
            if ($right !== false) imagedestroy($right);
            return $leftBytes;
        }

        $h = min(imagesy($left), imagesy($right));
        $wL = imagesx($left);
        $wR = imagesx($right);
        $W = $wL + $wR;

        $out = imagecreatetruecolor($W, $h);
        imagesavealpha($out, true);
        imagealphablending($out, true);
        imagecopy($out, $left,  0,    0, 0, 0, $wL, $h);
        imagecopy($out, $right, $wL,  0, 0, 0, $wR, $h);

        imagedestroy($left);
        imagedestroy($right);

        // Soft edge mask: fade outermost ~5% on each side toward black so the seam
        // where left meets right on the sphere blurs into a dim band instead of a
        // visible cut. GD's alpha is 0=opaque, 127=transparent.
        $fade = (int) round($W * 0.05);
        for ($x = 0; $x < $fade; $x++) {
            $strength = 1.0 - ($x / $fade);          // 1.0 at the edge, 0 at $fade
            $alpha    = (int) round(127 * (1 - $strength));
            $col      = imagecolorallocatealpha($out, 0, 0, 0, $alpha);
            imagefilledrectangle($out, $x,           0, $x,           $h - 1, $col);
            imagefilledrectangle($out, $W - 1 - $x,  0, $W - 1 - $x,  $h - 1, $col);
        }

        $quality = (int) config('services.openai.image_compression', 50);
        ob_start();
        imagewebp($out, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        return $bytes !== '' ? $bytes : $leftBytes;
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

        $quality = (int) config('services.openai.image_compression', 50);
        ob_start();
        imagewebp($cropped, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($cropped);

        return $out !== '' ? $out : $bytes;
    }
}
