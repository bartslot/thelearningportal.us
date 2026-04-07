<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WikipediaService
{
    private const API_BASE   = 'https://en.wikipedia.org/api/rest_v1';
    private const SEARCH_API = 'https://en.wikipedia.org/w/api.php';

    // Titles containing these words are almost certainly not educational articles
    private const SKIP_KEYWORDS = [
        'monument', 'memorial', 'statue', 'obelisk', 'arch', 'mural',
        'plaque', 'fountain', 'bridge', 'palace', 'castle', 'cathedral',
        'museum', 'landmark', 'cemetery', 'burial',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Fetch clean text facts for a topic.
     *
     * Stage 1: Try direct title lookups for all generated query variants.
     * Stage 2: Use Wikipedia's opensearch (autocomplete) to find canonical titles.
     * Stage 3: Full-text search with similarity scoring to pick best result.
     */
    public function fetchFacts(string $topic, ?string $title = null): ?string
    {
        try {
            $queries = $this->buildQuerySet($topic, $title);

            // ── Stage 1: Direct title lookups ─────────────────────────────────
            foreach ($queries as $q) {
                if ($text = $this->fetchSummary($q)) {
                    return $text;
                }
                if ($text = $this->fetchExtract($q)) {
                    return $text;
                }
            }

            // ── Stage 2: Opensearch → canonical title ─────────────────────────
            // Wikipedia's opensearch is the same engine powering the search bar.
            // It returns the real article title even for messy queries.
            foreach (array_slice($queries, 0, 4) as $q) {
                foreach ($this->openSearch($q) as $canonical) {
                    if ($this->isSkippable($canonical)) {
                        continue;
                    }
                    if ($text = $this->fetchSummary($canonical)) {
                        return $text;
                    }
                }
            }

            // ── Stage 3: Full-text search + similarity ranking ─────────────────
            // Search with the original topic and the extracted core subject,
            // then pick whichever result title is most similar to our query.
            $searchQueries = array_unique([$topic, ...$this->extractCoreSubjects($topic)]);

            foreach ($searchQueries as $sq) {
                $bestTitle = $this->bestSearchResult($sq);
                if ($bestTitle && ! $this->isSkippable($bestTitle)) {
                    if ($text = $this->fetchSummary($bestTitle)) {
                        return $text;
                    }
                    if ($text = $this->fetchExtract($bestTitle)) {
                        return $text;
                    }
                }
            }

            Log::warning("WikipediaService: no article found for topic '{$topic}'" . ($title ? " (title: '{$title}')" : ''));
            return null;

        } catch (\Throwable $e) {
            Log::error('WikipediaService::fetchFacts failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch the main image URL for a topic.
     */
    public function fetchImageUrl(string $topic, ?string $title = null): ?string
    {
        try {
            foreach ($this->buildQuerySet($topic, $title) as $q) {
                $slug = urlencode(str_replace(' ', '_', $q));
                $res  = Http::timeout(10)->get(self::API_BASE . "/page/summary/{$slug}");
                if ($res->successful() && $res->json('thumbnail.source')) {
                    return $res->json('thumbnail.source');
                }
            }

            foreach ($this->openSearch($topic) as $canonical) {
                $slug = urlencode(str_replace(' ', '_', $canonical));
                $res  = Http::timeout(10)->get(self::API_BASE . "/page/summary/{$slug}");
                if ($res->successful() && $res->json('thumbnail.source')) {
                    return $res->json('thumbnail.source');
                }
            }
        } catch (\Throwable $e) {
            Log::error('WikipediaService::fetchImageUrl failed: ' . $e->getMessage());
        }

        return null;
    }

    // ── Query building ────────────────────────────────────────────────────────

    /**
     * Build an ordered, deduplicated set of query strings to try.
     *
     * Priority:
     *   1. Clean variants of the original topic
     *   2. Core subjects extracted from "history of X" style phrases
     *   3. Roman numeral normalisation (World War 2 ↔ World War II)
     *   4. title argument variants
     */
    private function buildQuerySet(string $topic, ?string $title = null): array
    {
        $queries = [];

        foreach (array_filter([$topic, $title]) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $queries[] = $raw;

            // Strip leading articles
            $noArticle = trim((string) preg_replace('/^(the|a|an)\s+/i', '', $raw));
            if ($noArticle !== $raw) {
                $queries[] = $noArticle;
                $queries[] = ucfirst($noArticle);
            }

            // Strip common filler words teachers use
            $stripped = trim((string) preg_replace(
                '/\b(explained?|overview|summary|introduction|intro|lesson plan|for kids|in \d+ minutes?)\b/i',
                '',
                $raw,
            ));
            $stripped = trim((string) preg_replace('/\s+/', ' ', $stripped), " \t\n\r:-–|");
            if ($stripped !== '' && $stripped !== $raw) {
                $queries[] = $stripped;
                $queries[] = ucfirst($stripped);
            }

            // Roman numeral normalisation
            foreach ($this->romanVariants($raw) as $rv) {
                $queries[] = $rv;
            }

            // Core subjects: "The history of Spain" → "Spain"
            foreach ($this->extractCoreSubjects($raw) as $core) {
                $queries[] = $core;
                foreach ($this->romanVariants($core) as $rv) {
                    $queries[] = $rv;
                }
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * Extract the core educational subject from a teacher-written phrase.
     *
     * "The history of Spain"              → ["Spain"]
     * "History of the Roman Empire"       → ["Roman Empire"]
     * "Introduction to quantum physics"   → ["quantum physics", "Quantum physics"]
     * "The rise and fall of Julius Caesar"→ ["Julius Caesar"]
     * "Who was Cleopatra?"                → ["Cleopatra"]
     * "What caused World War 2?"          → ["World War 2"]
     */
    private function extractCoreSubjects(string $topic): array
    {
        $subjects = [];

        // Patterns where the subject follows a keyword
        $afterPatterns = [
            // "history / story / tale / account / origins of X"
            '/^(?:the\s+)?(?:history|story|tale|account|origins?|rise|fall|rise and fall|beginning|end|legacy|overview|summary)\s+of\s+(?:the\s+)?(.+)/i',
            // "introduction / intro / overview / understanding / exploring X"
            '/^(?:introduction|intro|overview|understanding|exploring|learning about|study of|about)\s+(?:to\s+|the\s+)?(.+)/i',
            // "who was/is X?" or "who were the X?"
            '/^who\s+(?:was|is|were)\s+(?:the\s+)?(.+?)\??$/i',
            // "what was/is (the) X?"
            '/^what\s+(?:was|is|were|caused|happened\s+(?:to|during)?)\s+(?:the\s+)?(.+?)\??$/i',
            // "why did/was X?"
            '/^why\s+(?:did|was|were|is|are)\s+(?:the\s+)?(.+?)\??$/i',
        ];

        foreach ($afterPatterns as $pattern) {
            if (preg_match($pattern, trim($topic), $m)) {
                $subject = trim($m[1], " \t\n\r.?!-–");
                if ($subject !== '' && mb_strlen($subject) >= 3) {
                    $subjects[] = $subject;
                    $subjects[] = ucfirst($subject);
                    // Also try stripping trailing articles/prepositions
                    $noTrail = trim((string) preg_replace('/\s+(of|the|a|an)$/i', '', $subject));
                    if ($noTrail !== $subject && $noTrail !== '') {
                        $subjects[] = $noTrail;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($subjects)));
    }

    /**
     * Generate Arabic ↔ Roman numeral variants for common war/event numbering.
     * "World War 2" → "World War II", and vice-versa.
     */
    private function romanVariants(string $topic): array
    {
        $map = [
            '1' => 'I',  '2' => 'II',  '3' => 'III',  '4' => 'IV',
            '5' => 'V',  '6' => 'VI',  '7' => 'VII',  '8' => 'VIII',
            '9' => 'IX', '10' => 'X',
            'I' => '1',  'II' => '2',  'III' => '3',  'IV' => '4',
            'V' => '5',  'VI' => '6',  'VII' => '7',  'VIII' => '8',
            'IX' => '9', 'X' => '10',
        ];

        $variants = [];
        foreach ($map as $from => $to) {
            $pattern = '/\b' . preg_quote((string) $from, '/') . '\b/';
            $replaced = preg_replace($pattern, (string) $to, $topic);
            if ($replaced && $replaced !== $topic) {
                $variants[] = $replaced;
            }
        }

        return $variants;
    }

    // ── Wikipedia API calls ───────────────────────────────────────────────────

    /**
     * Wikipedia opensearch — returns up to 5 canonical article titles.
     * This is the same engine as the Wikipedia search bar: great at fuzzy matching.
     */
    private function openSearch(string $query, int $limit = 5): array
    {
        try {
            $res = Http::timeout(8)->get(self::SEARCH_API, [
                'action'   => 'opensearch',
                'search'   => $query,
                'limit'    => $limit,
                'format'   => 'json',
                'redirects'=> 'resolve',
            ]);

            if ($res->successful()) {
                $data = $res->json();
                // opensearch response: [query, [titles], [descriptions], [urls]]
                return is_array($data[1] ?? null) ? $data[1] : [];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * Full-text search, then pick the result most similar to our query.
     * Returns the best canonical article title, or null.
     */
    private function bestSearchResult(string $query, int $limit = 8): ?string
    {
        try {
            $res = Http::timeout(10)->get(self::SEARCH_API, [
                'action'   => 'query',
                'list'     => 'search',
                'srsearch' => $query,
                'srlimit'  => $limit,
                'format'   => 'json',
            ]);

            if (! $res->successful()) {
                return null;
            }

            $results = $res->json('query.search') ?? [];
            if (empty($results)) {
                return null;
            }

            // Filter out skippable titles
            $results = array_values(array_filter(
                $results,
                fn (array $r) => ! $this->isSkippable((string) ($r['title'] ?? '')),
            ));

            if (empty($results)) {
                return null;
            }

            // Score each result by similarity to our query
            $queryLower = mb_strtolower(trim($query));
            usort($results, function (array $a, array $b) use ($queryLower) {
                $scoreA = $this->similarity($queryLower, mb_strtolower((string) ($a['title'] ?? '')));
                $scoreB = $this->similarity($queryLower, mb_strtolower((string) ($b['title'] ?? '')));

                if (abs($scoreA - $scoreB) > 2) {
                    return $scoreB <=> $scoreA;
                }

                // Tie-break on word count (longer articles are usually more comprehensive)
                return ((int) ($b['wordcount'] ?? 0)) <=> ((int) ($a['wordcount'] ?? 0));
            });

            return (string) ($results[0]['title'] ?? '');
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Similarity score between two strings (0–100) using similar_text().
     * Bonuses for exact/prefix/word matches to boost obviously correct results.
     */
    private function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $pct);

        // Exact match bonus
        if ($a === $b) {
            return 100.0;
        }

        // Prefix bonus (query is contained in title or vice-versa)
        if (str_starts_with($b, $a) || str_starts_with($a, $b)) {
            $pct += 15;
        }

        // Word overlap bonus
        $wordsA = array_filter(explode(' ', $a));
        $wordsB = array_filter(explode(' ', $b));
        $overlap = count(array_intersect($wordsA, $wordsB));
        $pct += $overlap * 3;

        return min($pct, 100.0);
    }

    private function fetchSummary(string $title): ?string
    {
        $slug = urlencode(str_replace(' ', '_', $title));

        try {
            $res = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(self::API_BASE . "/page/summary/{$slug}");

            if ($res->successful()) {
                $extract = $res->json('extract');
                return filled($extract) ? (string) $extract : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function fetchExtract(string $title): ?string
    {
        try {
            $res = Http::timeout(10)->get(self::SEARCH_API, [
                'action'      => 'query',
                'prop'        => 'extracts',
                'titles'      => $title,
                'explaintext' => 1,
                'exintro'     => 1,
                'redirects'   => 1,
                'format'      => 'json',
            ]);

            if (! $res->successful()) {
                return null;
            }

            $pages = $res->json('query.pages') ?? [];
            $page  = is_array($pages) ? reset($pages) : null;

            return (is_array($page) && filled($page['extract'] ?? null))
                ? (string) $page['extract']
                : null;
        } catch (\Throwable) {
        }

        return null;
    }

    private function isSkippable(string $title): bool
    {
        $lower = mb_strtolower($title);
        foreach (self::SKIP_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }
}
