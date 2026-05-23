<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class Automatic1111Service
{
    public function txt2img(
        string $prompt,
        string $negativePrompt = '',
        int    $width          = 1024,
        int    $height         = 512,
        int    $steps          = 20,
        float  $cfg            = 7.0,
    ): string {
        $url     = rtrim((string) config('services.a1111.url', 'http://localhost:7860'), '/') . '/sdapi/v1/txt2img';
        $timeout = (int) config('services.a1111.timeout', 120);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->post($url, [
                    'prompt'          => $prompt,
                    'negative_prompt' => $negativePrompt,
                    'width'           => $width,
                    'height'          => $height,
                    'steps'           => $steps,
                    'cfg_scale'       => $cfg,
                    'sampler_name'    => (string) config('services.a1111.sampler', 'Euler a'),
                    'send_images'     => true,
                    'save_images'     => false,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Automatic1111 connection failed: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('[Automatic1111] non-2xx', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
            throw new RuntimeException('Automatic1111 returned ' . $response->status());
        }

        $b64 = $response->json('images.0');
        if (! is_string($b64) || $b64 === '') {
            throw new RuntimeException('Automatic1111 response missing image data');
        }

        $bytes = base64_decode($b64, strict: true);
        if ($bytes === false) {
            throw new RuntimeException('Automatic1111 returned invalid base64 image data');
        }

        return $bytes;
    }

    public function isAvailable(): bool
    {
        $url = rtrim((string) config('services.a1111.url', 'http://localhost:7860'), '/') . '/sdapi/v1/sd-models';
        try {
            return Http::timeout(3)->connectTimeout(2)->get($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
