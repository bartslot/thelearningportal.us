<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiLlmService
{
    public function __construct(
        private readonly ?string $apiKey  = null,
        private readonly ?string $model   = null,
        private readonly ?string $baseUrl = null,
        private readonly ?int    $timeout = null,
    ) {}

    public function json(string $system, string $user, ?string $model = null): array
    {
        $raw = $this->call($system, $user, $model, jsonMode: true);
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned non-JSON despite json_object response_format.');
        }
        return $parsed;
    }

    public function text(string $system, string $user, ?string $model = null): string
    {
        return $this->call($system, $user, $model, jsonMode: false);
    }

    private function call(string $system, string $user, ?string $model, bool $jsonMode): string
    {
        $payload = [
            'model'    => $model ?? $this->model ?? config('services.openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.7,
        ];
        $jsonFormat = (string) config('services.openai.json_format', 'json_object');
        if ($jsonMode && $jsonFormat !== '') {
            $payload['response_format'] = ['type' => $jsonFormat];
        }

        $base    = $this->baseUrl ?? config('services.openai.base_url');
        $key     = $this->apiKey  ?? config('services.openai.api_key');
        $timeout = $this->timeout ?? (int) config('services.openai.timeout', 60);

        try {
            $response = Http::withToken($key)
                ->timeout($timeout)
                ->retry(times: 3, sleepMilliseconds: 1000, when: fn ($e, $req) => true, throw: false)
                ->post(rtrim($base, '/') . '/chat/completions', $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenAI API request failed: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('[OpenAiLlmService] non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException("OpenAI API returned {$response->status()}");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('OpenAI returned an empty completion.');
        }

        return $content;
    }
}
