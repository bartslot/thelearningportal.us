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

        $base = (string) config('services.openai.base_url');
        $key  = (string) config('services.openai.api_key');

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.openai.timeout', 60))
                ->retry(times: 3, sleepMilliseconds: 2000, when: fn ($e, $req) => true, throw: false)
                ->post(rtrim($base, '/') . '/images/generations', [
                    'model'              => config('services.openai.image_model'),
                    'prompt'             => $prompt,
                    'size'               => config('services.openai.image_size'),
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
        $bytes   = null;

        if (is_string($payload['b64_json'] ?? null) && $payload['b64_json'] !== '') {
            $bytes = base64_decode($payload['b64_json'], true) ?: null;
        } elseif (is_string($payload['url'] ?? null) && $payload['url'] !== '') {
            $binary = Http::timeout(30)->get($payload['url']);
            if (! $binary->successful()) {
                throw new RuntimeException("Failed to download generated image: {$binary->status()}");
            }
            $bytes = $binary->body();
        }

        if ($bytes === null) {
            throw new RuntimeException('Image API returned neither url nor b64_json.');
        }

        // gpt-image-1 max size is 1536x1024 (3:2). Center-crop to 2:1 so the skybox
        // sphere doesn't squash the image vertically when wrapped.
        $bytes = $this->cropTo2x1($bytes);

        Storage::disk('public')->put($destination, $bytes);

        return $destination;
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
