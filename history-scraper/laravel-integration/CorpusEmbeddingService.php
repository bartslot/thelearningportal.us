<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * CorpusEmbeddingService
 *
 * Generates query embedding vectors for hybrid corpus search.
 *
 * Supports two backends (set CORPUS_EMBED_BACKEND in .env):
 *   ollama  — local Ollama server with nomic-embed-text (free, offline)
 *   openai  — OpenAI text-embedding-3-small (paid, hosted)
 *
 * The vector is cached in Laravel cache for 24h to avoid redundant API calls
 * for the same topic string.
 *
 * Usage:
 *   $embedding = CorpusEmbeddingService::embed('Roman Empire trade routes');
 *   $facts     = HistoryCorpusService::factsForLessonHybrid(
 *                    'Roman Empire', $embedding, 'K8', 15
 *                );
 *
 * .env variables:
 *   CORPUS_EMBED_BACKEND=ollama          # ollama | openai
 *   CORPUS_EMBED_MODEL=nomic-embed-text  # or text-embedding-3-small
 *   OLLAMA_URL=http://localhost:11434
 *   OPENAI_API_KEY=sk-...
 */
class CorpusEmbeddingService
{
    /**
     * Generate (or retrieve from cache) an embedding vector for the given text.
     *
     * @param  string  $text  The topic / query text to embed
     * @return float[]        Dense float array (768-dim for nomic, 1536 for OpenAI)
     *
     * @throws RuntimeException  If the embedding backend is unavailable
     */
    public static function embed(string $text): array
    {
        // Cache by backend+model+text to avoid redundant API round-trips
        $backend = config('corpus.embed_backend', 'ollama');
        $model   = config('corpus.embed_model',   'nomic-embed-text');
        $cacheKey = 'corpus_embed:' . $backend . ':' . $model . ':' . md5($text);

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($text, $backend, $model) {
            return match ($backend) {
                'ollama' => self::embedOllama($text, $model),
                'openai' => self::embedOpenAi($text, $model),
                default  => throw new RuntimeException("Unknown CORPUS_EMBED_BACKEND: {$backend}"),
            };
        });
    }

    // ── Ollama backend (local, free) ──────────────────────────────────────────

    /**
     * Call local Ollama /api/embed endpoint.
     * Default model: nomic-embed-text → 768-dim vectors.
     */
    private static function embedOllama(string $text, string $model): array
    {
        $ollamaUrl = rtrim(config('corpus.ollama_url', env('OLLAMA_URL', 'http://localhost:11434')), '/');

        $response = Http::timeout(30)
            ->post("{$ollamaUrl}/api/embed", [
                'model' => $model,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Ollama embedding failed (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $data = $response->json();

        // Ollama /api/embed returns: {"embeddings": [[...float...]]}
        $embeddings = $data['embeddings'] ?? [];
        if (empty($embeddings) || !is_array($embeddings[0])) {
            throw new RuntimeException('Ollama returned unexpected embedding shape: ' . json_encode($data));
        }

        return array_map('floatval', $embeddings[0]);
    }

    // ── OpenAI backend (paid, hosted) ─────────────────────────────────────────

    /**
     * Call OpenAI Embeddings API.
     * Default model: text-embedding-3-small → 1536-dim vectors.
     *
     * NOTE: If you use OpenAI embeddings, the Supabase vector column and HNSW
     * index must be set to vector(1536). Update supabase-setup.sql accordingly.
     */
    private static function embedOpenAi(string $text, string $model): array
    {
        $apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY');

        if (empty($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not set — cannot use OpenAI embedding backend');
        }

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenAI embedding failed (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $data = $response->json();
        $embedding = $data['data'][0]['embedding'] ?? null;

        if (!is_array($embedding)) {
            throw new RuntimeException('OpenAI returned unexpected embedding shape: ' . json_encode($data));
        }

        return array_map('floatval', $embedding);
    }
}
