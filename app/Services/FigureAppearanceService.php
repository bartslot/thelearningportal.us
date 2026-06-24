<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Corpus\Figure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Grounds a historical figure's look in a real reference portrait so generated scene images render
 * them accurately (no anachronistic baseball caps on Julius Caesar). It resolves the canonical
 * portrait (corpus Wikidata P18 → the figure's Wikipedia lead image), asks a vision model to
 * describe the person in one image-prompt-ready sentence, and caches that per figure — the look
 * never changes, so it's computed once and reused across every scene and every lesson.
 */
final class FigureAppearanceService
{
    private const TTL_DAYS = 28;

    private const UA = 'TheLearningPortalBot/1.0 (+https://thelearningportal.us; education)';

    private const INSTRUCTION = <<<'TXT'
    Describe ONLY the physical appearance of the historical figure in this reference portrait, for
    use inside an image-generation prompt. Reply with ONE concise sentence covering: approximate
    age, face shape, skin tone, hair (style/colour or baldness), facial hair, build, and the
    period-accurate clothing or headwear they wear. Do NOT name them, do NOT describe the
    background, frame, or art style, and do NOT add any modern item. If the reference is a bust,
    coin, or statue, infer a plausible living appearance.
    TXT;

    public function __construct(
        private readonly OpenAiLlmService $llm,
        private readonly WikipediaService $wiki,
    ) {}

    /**
     * A cached one-line appearance clause for a figure, or null when no usable reference exists
     * (the caller should then fall back to period guardrails only).
     */
    public function describe(?string $qid, ?string $name): ?string
    {
        if (! $qid && ! filled($name)) {
            return null;
        }

        $key    = 'figure_appearance:'.($qid ?: Str::slug((string) $name));
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached !== '' ? $cached : null; // '' is the "known to have no reference" sentinel
        }

        $dataUrl = $this->referenceDataUrl($qid, $name);
        if (! $dataUrl) {
            Cache::put($key, '', now()->addDays(self::TTL_DAYS));

            return null;
        }

        try {
            $desc = trim($this->llm->describeImage($dataUrl, self::INSTRUCTION));
        } catch (\Throwable $e) {
            return null; // transient (vision/network) — don't cache, retry next request
        }

        Cache::put($key, $desc, now()->addDays(self::TTL_DAYS));

        return $desc !== '' ? $desc : null;
    }

    /** Resolve a reference portrait and inline it as a base64 data URL (UA-fetched, so Commons won't 403). */
    private function referenceDataUrl(?string $qid, ?string $name): ?string
    {
        $url = $this->referenceUrl($qid, $name);
        if (! $url) {
            return null;
        }

        try {
            $res = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        $bytes = $res->body();
        $mime  = strtok((string) ($res->header('Content-Type') ?: 'image/jpeg'), ';');
        if (! $res->successful() || ! str_starts_with((string) $mime, 'image/') || strlen($bytes) < 1024) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** Canonical portrait URL: corpus P18 first, then the figure's Wikipedia lead image. */
    private function referenceUrl(?string $qid, ?string $name): ?string
    {
        if ($qid) {
            try {
                $url = Figure::on('pgsql_corpus')->where('qid', $qid)->value('image_url');
                if (filled($url)) {
                    return (string) $url;
                }
            } catch (\Throwable $e) {
                // corpus unreachable — fall through to Wikipedia
            }
        }

        if (filled($name)) {
            $article = 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', (string) $name));

            return $this->wiki->fetchLeadImageUrl($article);
        }

        return null;
    }
}
