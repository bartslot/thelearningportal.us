<?php

declare(strict_types=1);

namespace App\Services\Llm;

use InvalidArgumentException;

/**
 * One provider, resolved: where to send a completion, with which key and model.
 *
 * Immutable — `withModel()` returns a copy, so a role can borrow a provider's
 * endpoint and key while naming a different model ("gemini:gemma-3-12b-it").
 */
final readonly class LlmProfile
{
    public function __construct(
        public string $provider,
        public string $label,
        public string $baseUrl,
        public ?string $apiKey,
        public string $model,
        public string $jsonFormat,
        public int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $config  one entry from config('llm.providers')
     */
    public static function fromConfig(string $provider, array $config): self
    {
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new InvalidArgumentException("LLM provider [{$provider}] has no base_url.");
        }

        return new self(
            provider: $provider,
            label: (string) ($config['label'] ?? $provider),
            baseUrl: rtrim($baseUrl, '/'),
            apiKey: ($config['api_key'] ?? null) ?: null,
            model: (string) ($config['model'] ?? ''),
            jsonFormat: (string) ($config['json_format'] ?? ''),
            timeout: (int) ($config['timeout'] ?? 60),
        );
    }

    public function withModel(?string $model): self
    {
        if ($model === null || $model === '' || $model === $this->model) {
            return $this;
        }

        return new self(
            provider: $this->provider,
            label: $this->label,
            baseUrl: $this->baseUrl,
            apiKey: $this->apiKey,
            model: $model,
            jsonFormat: $this->jsonFormat,
            timeout: $this->timeout,
        );
    }

    /** A local Ollama needs no real key; a hosted provider without one cannot answer. */
    public function isUsable(): bool
    {
        return $this->model !== '' && ($this->apiKey !== null || str_contains($this->baseUrl, 'localhost') || str_contains($this->baseUrl, '127.0.0.1'));
    }

    /** "gemini:gemma-3-27b-it" — what you'd write in .env to pin this exact combination. */
    public function reference(): string
    {
        return $this->provider.':'.$this->model;
    }
}
