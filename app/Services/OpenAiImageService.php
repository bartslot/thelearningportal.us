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

        Storage::disk('public')->put($destination, $bytes);

        return $destination;
    }
}
