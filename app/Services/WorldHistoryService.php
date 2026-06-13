<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fetches educational content from World History Encyclopedia (worldhistory.org).
 * Falls back gracefully — callers should try WikipediaService when this returns null.
 */
class WorldHistoryService
{
    private const BASE_URL = 'https://www.worldhistory.org';

    private const SEARCH_URL = 'https://www.worldhistory.org/search/';

    private const USER_AGENT = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)';

    private const TIMEOUT = 12;

    /**
     * Minimum average saturation (0–255) for an image to be considered "colorful".
     * Greyscale paintings score near 0; vivid oil paintings typically score 60+.
     */
    private const MIN_SATURATION = 40;

    /**
     * Minimum image width in pixels. Reject tiny thumbnails.
     */
    private const MIN_WIDTH = 600;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Fetch clean educational text for a topic.
     * Also sets $this->lastArticleUrl for use by fetchHeroImage().
     * Returns null when no usable article is found.
     */
    private ?string $lastArticleUrl = null;

    private ?string $lastArticleHtml = null;

    public function fetchFacts(string $topic): ?string
    {
        try {
            // 1. Try direct slug match + cleaned variants (e.g. drop "and their economy")
            foreach ($this->slugCandidates($topic) as $slug) {
                $url = self::BASE_URL.'/'.$slug.'/';
                if (($text = $this->fetchAndCacheByUrl($url)) && $this->isRelevant($topic, $this->lastArticleUrl)) {
                    Log::info("WorldHistoryService: matched slug '{$slug}' → {$this->lastArticleUrl} for '{$topic}'");

                    return $text;
                }
            }

            // 2. Try search to find the canonical URL (often Cloudflare-blocked, but try)
            $foundUrl = $this->searchForUrl($topic);
            if ($foundUrl && ($text = $this->fetchAndCacheByUrl($foundUrl)) && $this->isRelevant($topic, $this->lastArticleUrl)) {
                return $text;
            }

            Log::info("WorldHistoryService: no relevant article found for '{$topic}' — falling back to Wikipedia");

            return null;

        } catch (\Throwable $e) {
            Log::warning('WorldHistoryService::fetchFacts failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * After calling fetchFacts(), call this to grab the article's hero image.
     *
     * Returns an array with:
     *   - 'url'        => original image URL
     *   - 'path'       => stored path on public disk  (e.g. lessons/42/worldhistory_hero.jpg)
     *   - 'colorful'   => bool  (passed the saturation check)
     *   - 'width'      => int
     *   - 'height'     => int
     *
     * Returns null if no suitable image is found or download fails.
     */
    public function fetchHeroImage(int $lessonId): ?array
    {
        if ($this->lastArticleHtml === null) {
            return null;
        }

        $imageUrl = $this->extractHeroImageUrl($this->lastArticleHtml);
        if (! $imageUrl) {
            Log::info("WorldHistoryService: no hero image found in article for lesson #{$lessonId}");

            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withUserAgent(self::USER_AGENT)
                ->get($imageUrl);

            if (! $response->successful()) {
                Log::info("WorldHistoryService: hero image download failed ({$response->status()}) — {$imageUrl}");

                return null;
            }

            $bytes = $response->body();
            $result = $this->analyzeImage($bytes);

            if ($result === null) {
                Log::info("WorldHistoryService: hero image could not be decoded — {$imageUrl}");

                return null;
            }

            if ($result['width'] < self::MIN_WIDTH) {
                Log::info("WorldHistoryService: hero image too small ({$result['width']}px) — {$imageUrl}");

                return null;
            }

            // Store on public disk regardless of color score — let caller decide whether to use it
            $ext = $this->guessExtension($imageUrl, $bytes);
            $path = "lessons/{$lessonId}/worldhistory_hero.{$ext}";
            Storage::disk('public')->put($path, $bytes);

            Log::info(sprintf(
                'WorldHistoryService: hero image stored — %s | %dx%d | sat=%.1f | colorful=%s',
                $path, $result['width'], $result['height'],
                $result['avg_saturation'],
                $result['colorful'] ? 'yes' : 'no',
            ));

            return [
                'url' => $imageUrl,
                'path' => $path,
                'colorful' => $result['colorful'],
                'width' => $result['width'],
                'height' => $result['height'],
            ];

        } catch (\Throwable $e) {
            Log::warning("WorldHistoryService: hero image error — {$e->getMessage()}");

            return null;
        }
    }

    // ── Internal — fetching ───────────────────────────────────────────────────

    private function topicToSlug(string $topic): string
    {
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $topic), '-'));
    }

    /**
     * Generate slug candidates from a topic, ordered most→least specific.
     * Tries: full slug → progressively cleaned (drop stopwords) → first significant word(s).
     *
     * Example: "Inca empire and their economy" →
     *   ['inca-empire-and-their-economy', 'inca-empire-economy', 'inca-empire', 'incas']
     */
    private const STOPWORDS = ['the', 'and', 'or', 'of', 'a', 'an', 'in', 'on', 'to', 'for',
        'with', 'their', 'its', 'his', 'her', 'our', 'your', 'about', 'into'];

    /**
     * Guard against drifting to an unrelated article (e.g. "Kingdom of France" → the disambiguation
     * page's first link "Middle Kingdom of Egypt"). Require EVERY meaningful topic word to appear in
     * the matched article's URL; otherwise we'd rather fall back to Wikipedia, which has the exact page.
     */
    private function isRelevant(string $topic, ?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $words = array_filter(
            explode('-', $this->topicToSlug($topic)),
            fn ($w) => strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true)
        );
        if ($words === []) {
            return false;
        }
        $slug = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach ($words as $w) {
            if (! str_contains($slug, $w)) {
                return false;
            }
        }

        return true;
    }

    private function slugCandidates(string $topic): array
    {
        $stopwords = self::STOPWORDS;

        // 1. Full slug
        $full = $this->topicToSlug($topic);
        $candidates = [$full];

        // 2. Drop stopwords
        $words = array_values(array_filter(
            explode('-', $full),
            fn ($w) => $w !== '' && ! in_array($w, $stopwords, true)
        ));
        if ($words) {
            $cleaned = implode('-', $words);
            if ($cleaned !== $full) {
                $candidates[] = $cleaned;
            }

            // 3. First 2 meaningful words (likely the core entity)
            if (count($words) > 2) {
                $candidates[] = implode('-', array_slice($words, 0, 2));
            }

            // NOTE: deliberately no single-word fallback. For a multi-word topic it strips the
            // specific entity (e.g. "Kingdom of France" → "kingdom") and matches an unrelated
            // article ("kingdom" → "Middle Kingdom of Egypt"), feeding the wrong source to the LLM.
        }

        return array_values(array_unique($candidates));
    }

    private function fetchAndCacheByUrl(string $url): ?string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withUserAgent(self::USER_AGENT)
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        // If we landed on a disambiguation page, follow its top-ranked content_item link.
        // worldhistory.org responds to ambiguous slugs (e.g. /Mussolini/) with a list of
        // candidate articles rather than a 404, and the first one is the highest-scored match.
        $effective = $response->effectiveUri()?->getPath() ?? '';
        $looksLikeDisambig = str_contains($effective, '/disambiguation/')
            || str_contains($html, 'class="disambiguation_page"');
        if ($looksLikeDisambig) {
            $followed = $this->firstContentItemUrl($html);
            if ($followed && $followed !== $url) {
                return $this->fetchAndCacheByUrl($followed);
            }
        }

        $text = $this->extractText($html);

        if ($text !== null) {
            $this->lastArticleUrl = $url;
            $this->lastArticleHtml = $html;
        }

        return $text;
    }

    /**
     * Extract the first article link from a disambiguation / search-results page.
     * Pattern: <a href="/Some_Slug/" class="content_item ...">
     */
    private function firstContentItemUrl(string $html): ?string
    {
        if (preg_match('~<a\s+href="(/[A-Za-z][^"?\#]+/)"[^>]*class="[^"]*content_item[^"]*"~i', $html, $m)) {
            return self::BASE_URL.$m[1];
        }

        return null;
    }

    private function searchForUrl(string $topic): ?string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withUserAgent(self::USER_AGENT)
            ->get(self::SEARCH_URL, ['q' => $topic]);

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        // Pattern 1: explicit search-result class
        if (preg_match('/<a[^>]+href="(https?:\/\/www\.worldhistory\.org\/[^"?#]+)"[^>]*class="[^"]*search-result[^"]*"/i', $html, $m)) {
            return $m[1];
        }

        // Pattern 2: first article-style /slug/ link in results
        if (preg_match_all('/<a[^>]+href="(https?:\/\/www\.worldhistory\.org\/[a-z0-9\-]+\/)"/', $html, $m)) {
            foreach ($m[1] as $candidate) {
                if (! preg_match('#worldhistory\.org/(search|tag|category|author|about|contact|donate)/#', $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    // ── Internal — text extraction ────────────────────────────────────────────

    private function extractText(string $html): ?string
    {
        // Reject Cloudflare bot-challenge interstitials and disambiguation pages.
        // Both have substantial char counts but contain zero usable article text.
        if (str_contains($html, 'Just a moment')
            || str_contains($html, 'Enable JavaScript and cookies')
            || preg_match('/<title>[^<]*Disambiguation/i', $html)
            || preg_match('/Did you mean\.{3}/', $html)) {
            return null;
        }

        $html = preg_replace('/<(script|style|nav|footer|header|aside|noscript)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        if (preg_match('/<(?:div|article)[^>]+class="[^"]*(?:content-holder|article-body|entry-content)[^"]*"[^>]*>(.*?)<\/(?:div|article)>/is', $html, $m)) {
            $html = $m[1];
        }

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/(\n\s*){3,}/', "\n\n", $text);
        $text = trim($text);

        if (mb_strlen($text) < 300) {
            return null;
        }

        return mb_substr($text, 0, 6000);
    }

    // ── Internal — image extraction & analysis ────────────────────────────────

    /**
     * Find the best hero image URL in the article HTML.
     * Tries: og:image meta → largest <img> in article body → first article <img>.
     */
    private function extractHeroImageUrl(string $html): ?string
    {
        // 1. Open Graph image — usually the editorial choice for the article
        if (preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            return $this->absoluteUrl($m[1]);
        }
        if (preg_match('/<meta[^>]+content="([^"]+)"[^>]+property="og:image"/i', $html, $m)) {
            return $this->absoluteUrl($m[1]);
        }

        // 2. Largest src= in the article body (pick by picking the one with biggest dimensions hint)
        if (preg_match('/<(?:div|article)[^>]+class="[^"]*(?:content-holder|article-body|entry-content)[^"]*"[^>]*>(.*?)<\/(?:div|article)>/is', $html, $body)) {
            $bodyHtml = $body[1];
        } else {
            $bodyHtml = $html;
        }

        // Collect all img src values, prefer those with width hints ≥ 600
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*(?:width="(\d+)")?/i', $bodyHtml, $imgs, PREG_SET_ORDER);
        $best = null;
        $bestW = 0;
        foreach ($imgs as $img) {
            $src = $img[1];
            $w = (int) ($img[2] ?? 0);
            // Skip SVG, icons, spacers
            if (preg_match('/\.(svg|gif|ico)$/i', $src)) {
                continue;
            }
            if ($w >= $bestW) {
                $bestW = $w;
                $best = $src;
            }
        }

        return $best ? $this->absoluteUrl($best) : null;
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return self::BASE_URL.'/'.ltrim($url, '/');
    }

    /**
     * Decode image bytes and compute colour metrics via GD.
     *
     * Returns:
     *   [width, height, avg_saturation (0–255), colorful (bool)]
     * or null on decode failure.
     */
    private function analyzeImage(string $bytes): ?array
    {
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // Sample up to 1000 evenly-spaced pixels for speed
        $sampleStep = max(1, (int) floor(sqrt(($w * $h) / 1000)));
        $totalSat = 0.0;
        $count = 0;

        for ($x = 0; $x < $w; $x += $sampleStep) {
            for ($y = 0; $y < $h; $y += $sampleStep) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                // HSL saturation = (max - min) / (255 - |max + min - 255|)
                $denom = 255 - abs($max + $min - 255);
                $sat = $denom > 0 ? (($max - $min) / $denom) * 255 : 0;

                $totalSat += $sat;
                $count++;
            }
        }

        imagedestroy($img);

        $avgSat = $count > 0 ? $totalSat / $count : 0;

        return [
            'width' => $w,
            'height' => $h,
            'avg_saturation' => round($avgSat, 1),
            'colorful' => $avgSat >= self::MIN_SATURATION,
        ];
    }

    private function guessExtension(string $url, string $bytes): string
    {
        // From URL
        if (preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url, $m)) {
            return strtolower($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        }
        // From magic bytes
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'png';
        }
        if (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 8, 4), 'WEBP')) {
            return 'webp';
        }

        return 'jpg';
    }
}
