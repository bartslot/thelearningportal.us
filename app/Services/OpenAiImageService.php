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
                    'model'  => config('services.openai.image_model'),
                    'prompt' => $prompt,
                    'size'   => config('services.openai.image_size'),
                    'n'      => 1,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Image API request failed: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Image API returned {$response->status()}: {$response->body()}");
        }

        $url = $response->json('data.0.url');
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Image API returned no URL.');
        }

        $binary = Http::timeout(30)->get($url);
        if (! $binary->successful()) {
            throw new RuntimeException("Failed to download generated image: {$binary->status()}");
        }

        Storage::disk('public')->put($destination, $binary->body());

        return $destination;
    }
}
