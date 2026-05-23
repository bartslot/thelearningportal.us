<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FalAiImageService
{
    private const BASE_URL = 'https://fal.run';

    public function txt2img(
        string $prompt,
        int    $width  = 1024,
        int    $height = 512,
        int    $steps  = 4,
    ): string {
        $key   = (string) config('services.falai.api_key');
        $model = (string) config('services.falai.image_model', 'fal-ai/flux/schnell');

        $timeout = (int) config('services.falai.timeout', 90);

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $key])
                ->timeout($timeout)
                ->retry(times: 2, sleepMilliseconds: 3000, when: fn ($e, $req) => true, throw: false)
                ->post(self::BASE_URL . '/' . $model, [
                    'prompt'               => $prompt,
                    'image_size'           => ['width' => $width, 'height' => $height],
                    'num_inference_steps'  => $steps,
                    'num_images'           => 1,
                    'enable_safety_checker' => false,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('fal.ai request failed: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("[fal.ai] image {$response->status()}: {$response->body()}");
        }

        $url = $response->json('images.0.url');
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('[fal.ai] no image URL in response: ' . $response->body());
        }

        Log::info('[fal.ai] image generated', ['url' => $url, 'width' => $width, 'height' => $height]);

        $download = Http::timeout(60)->get($url);
        if (! $download->successful()) {
            throw new RuntimeException("[fal.ai] download failed {$download->status()}: {$url}");
        }

        $bytes = $download->body();
        if ($bytes === '') {
            throw new RuntimeException('[fal.ai] downloaded empty image');
        }

        return $bytes;
    }
}
