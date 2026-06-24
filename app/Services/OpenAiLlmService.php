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

        // Strip markdown code fences that some local models (LM Studio, Ollama) wrap around JSON
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = preg_replace('/\s*```\s*$/', '', trim($clean ?? $raw));
        $clean = trim($clean ?? $raw);

        // Extract first JSON object/array if model prefixed with prose
        if (! str_starts_with($clean, '{') && ! str_starts_with($clean, '[')) {
            if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/u', $clean, $m)) {
                $clean = $m[1];
            }
        }

        $parsed = json_decode($clean, true);
        if (! is_array($parsed)) {
            Log::warning('[OpenAiLlmService] non-JSON response', ['raw' => substr($raw, 0, 500)]);
            throw new RuntimeException('OpenAI returned non-JSON despite json_object response_format.');
        }
        return $parsed;
    }

    public function text(string $system, string $user, ?string $model = null): string
    {
        return $this->call($system, $user, $model, jsonMode: false);
    }

    /**
     * Describe a reference image in words (vision). Used to ground a historical figure's
     * appearance in a real portrait/bust so generated scenes render them accurately instead of
     * inventing anachronistic looks. `imageUrl` may be a public URL or a base64 data URL.
     */
    public function describeImage(string $imageUrl, string $instruction, ?string $model = null): string
    {
        return $this->send([
            'model' => $model
                ?? config('services.openai.vision_model')
                ?? $this->model
                ?? config('services.openai.model'),
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                ]],
            ],
            'max_tokens'  => 220,
            'temperature' => 0.2,
        ]);
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

        // Limit output tokens — prevents thinking models (Gemma 4, QwQ, DeepSeek-R1) from
        // exhausting their budget on reasoning_content and returning empty content.
        $maxTokens = (int) config('services.openai.max_tokens', 2048);
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        $jsonFormat = (string) config('services.openai.json_format', 'json_object');
        if ($jsonMode && $jsonFormat !== '') {
            $payload['response_format'] = ['type' => $jsonFormat];
        }

        return $this->send($payload);
    }

    /** Issue a chat/completions request and return the assistant text. */
    private function send(array $payload): string
    {
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

        $msg = $response->json('choices.0.message') ?? [];

        // Primary field
        $content = $msg['content'] ?? null;

        // Thinking models (Gemma 4, QwQ, DeepSeek-R1) can return content: null and put
        // the real answer in reasoning_content when output tokens run out or thinking is long.
        if (! is_string($content) || $content === '') {
            $content = $msg['reasoning_content'] ?? null;
        }

        if (! is_string($content) || $content === '') {
            Log::warning('[OpenAiLlmService] empty completion', [
                'model'         => $payload['model'],
                'finish_reason' => $response->json('choices.0.finish_reason'),
                'message_keys'  => array_keys($msg),
                'usage'         => $response->json('usage'),
            ]);
            throw new RuntimeException('OpenAI returned an empty completion.');
        }

        return $content;
    }
}
