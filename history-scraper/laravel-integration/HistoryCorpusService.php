<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HistoryCorpusService
 *
 * Query the history corpus (Supabase PostgreSQL + pgvector) to retrieve
 * verified facts for lesson script generation.
 *
 * The corpus DB is configured as the 'corpus' connection in config/database.php.
 * See: history-scraper/laravel-integration/config/corpus_database.php
 *
 * Three query modes:
 *
 *   1. factsForLesson()      — keyword full-text search (BM25 ranking)
 *   2. factsForLessonHybrid()— Reciprocal Rank Fusion: BM25 + vector cosine
 *                              (best quality; requires embedding vector)
 *   3. factsForLessonSemantic() — pure vector cosine similarity
 *
 * Usage in lesson generator:
 *
 *   // Simple (no embedding needed):
 *   $facts = HistoryCorpusService::factsForLesson('Roman Empire', 'K8', 15);
 *
 *   // Hybrid (best quality — needs CorpusEmbeddingService):
 *   $embedding = CorpusEmbeddingService::embed('Roman Empire trade routes');
 *   $facts = HistoryCorpusService::factsForLessonHybrid('Roman Empire', $embedding, 'K8', 15);
 *
 *   // Build Claude grounding context:
 *   $groundingBlock = HistoryCorpusService::toGroundingPrompt($facts, 'Roman Empire');
 */
class HistoryCorpusService
{
    private const CONNECTION = 'corpus'; // DB connection in config/database.php

    // ── 1. Full-text keyword search ────────────────────────────────────────────

    /**
     * Retrieve verified facts using PostgreSQL full-text search (BM25 ranking).
     *
     * Suitable when you don't have an embedding vector available, e.g. simple
     * one-word topic queries like "Egypt" or "Vikings".
     *
     * @param  string  $topic       e.g. "Roman Empire", "French Revolution"
     * @param  string  $gradeLevel  "K6" | "K8" | "K12"
     * @param  int     $count       Facts to return (default 15)
     * @param  string  $confidence  "high" | "med" | "low"
     * @return Collection<array>
     */
    public static function factsForLesson(
        string $topic,
        string $gradeLevel = 'K8',
        int    $count      = 15,
        string $confidence = 'high',
    ): Collection {
        $allowedGrades       = self::gradeHierarchy($gradeLevel);
        $gradePlaceholders   = implode(',', array_fill(0, count($allowedGrades), '?'));

        $sql = "
            SELECT
                f.id,
                f.fact_text,
                f.people,
                f.places,
                f.dates,
                f.events,
                f.tags,
                f.location,
                f.start_year,
                f.end_year,
                f.year_display,
                f.grade_level,
                f.confidence,
                a.title           AS article_title,
                a.source_url,
                a.source_license,
                ts_rank_cd(
                    a.search_vector || f.search_vector,
                    plainto_tsquery('english', ?)
                ) AS relevance
            FROM history_facts f
            JOIN history_articles a ON a.id = f.article_id
            WHERE
                f.confidence    = ?::confidence_level
                AND f.grade_level IN ({$gradePlaceholders})
                AND (
                    a.search_vector @@ plainto_tsquery('english', ?)
                    OR f.search_vector @@ plainto_tsquery('english', ?)
                    OR a.tags && ARRAY[?]::varchar[]
                )
            ORDER BY relevance DESC
            LIMIT ?
        ";

        $bindings = array_merge(
            [$topic, $confidence],
            $allowedGrades,
            [$topic, $topic, strtolower($topic), $count]
        );

        $rows = DB::connection(self::CONNECTION)->select($sql, $bindings);
        return self::mapRows($rows);
    }

    // ── 2. Hybrid search (Reciprocal Rank Fusion) ──────────────────────────────

    /**
     * Hybrid search using RRF: BM25 full-text + pgvector cosine similarity.
     *
     * This is the highest-quality search mode. Use it when CorpusEmbeddingService
     * is available (local Ollama or OpenAI).
     *
     * Calls the hybrid_fact_search() SQL function created by supabase-setup.sql.
     *
     * @param  string  $topic           Plain text topic for BM25 leg
     * @param  array   $queryEmbedding  768-dim float array (nomic-embed-text)
     *                                  or 1536-dim (OpenAI text-embedding-3-small)
     * @param  string  $gradeLevel      "K6" | "K8" | "K12"
     * @param  int     $count           Facts to return
     * @return Collection<array>
     */
    public static function factsForLessonHybrid(
        string $topic,
        array  $queryEmbedding,
        string $gradeLevel = 'K8',
        int    $count      = 15,
    ): Collection {
        $allowedGrades = self::gradeHierarchy($gradeLevel);
        $gradesLiteral = "ARRAY['" . implode("','", $allowedGrades) . "']";
        $vectorStr     = '[' . implode(',', $queryEmbedding) . ']';

        // Call the hybrid_fact_search() function defined in supabase-setup.sql.
        // The function performs RRF fusion inside PostgreSQL — one round-trip.
        $sql = "
            SELECT *
            FROM hybrid_fact_search(
                query_text      := ?,
                query_embedding := ?::vector,
                grade_levels    := {$gradesLiteral},
                match_count     := ?
            )
        ";

        $rows = DB::connection(self::CONNECTION)
            ->select($sql, [$topic, $vectorStr, $count]);

        return self::mapRows($rows);
    }

    // ── 3. Pure semantic (vector only) ────────────────────────────────────────

    /**
     * Pure vector cosine similarity search using pgvector.
     *
     * Use this when the topic is better expressed semantically than lexically,
     * or when you already have a query embedding and want the fastest path.
     *
     * @param  array   $queryEmbedding  768 or 1536-dim float array
     * @param  string  $gradeLevel
     * @param  int     $count
     * @return Collection
     */
    public static function factsForLessonSemantic(
        array  $queryEmbedding,
        string $gradeLevel = 'K8',
        int    $count      = 15,
    ): Collection {
        $allowedGrades     = self::gradeHierarchy($gradeLevel);
        $gradePlaceholders = implode(',', array_fill(0, count($allowedGrades), '?'));
        $vectorStr         = '[' . implode(',', $queryEmbedding) . ']';

        $sql = "
            SELECT
                f.id,
                f.fact_text,
                f.people,
                f.places,
                f.dates,
                f.events,
                f.tags,
                f.location,
                f.start_year,
                f.end_year,
                f.year_display,
                f.grade_level,
                f.confidence,
                a.title         AS article_title,
                a.source_url,
                a.source_license,
                a.embedding     <=> ?::vector AS distance
            FROM history_articles a
            JOIN history_facts f ON f.article_id = a.id
            WHERE
                f.grade_level IN ({$gradePlaceholders})
                AND a.embedding IS NOT NULL
            ORDER BY distance ASC
            LIMIT ?
        ";

        $bindings = array_merge([$vectorStr], $allowedGrades, [$count]);

        $rows = DB::connection(self::CONNECTION)->select($sql, $bindings);
        return self::mapRows($rows);
    }

    // ── Grounding prompt builder ───────────────────────────────────────────────

    /**
     * Format retrieved facts as a grounding block for the Claude system prompt.
     *
     * Paste the returned string into the LLM system prompt before "ARTICLE TITLE:"
     * to ground all generation in verified facts.
     *
     * @param  Collection  $facts  Output of any factsForLesson*() method
     * @param  string      $topic  Topic label for the header
     * @return string
     */
    public static function toGroundingPrompt(Collection $facts, string $topic): string
    {
        $lines   = [];
        $lines[] = "VERIFIED HISTORICAL FACTS FOR: {$topic}";
        $lines[] = "";
        $lines[] = "IMPORTANT: Your lesson script MUST be grounded ONLY in the facts";
        $lines[] = "listed below. Do NOT add dates, people, or events not in this list.";
        $lines[] = "If a fact doesn't appear here, omit it — never invent.";
        $lines[] = "";

        foreach ($facts as $i => $fact) {
            $n        = $i + 1;
            $people   = implode(', ', $fact['people']  ?? []);
            $places   = implode(', ', $fact['places']  ?? []);
            $dates    = implode(', ', $fact['dates']   ?? []);
            $location = $fact['location'] ?? null;
            $year     = $fact['year']     ?? null;  // Scene.year-ready string

            $lines[] = "[FACT {$n}] {$fact['fact_text']}";
            if ($location) $lines[] = "  → Location : {$location}";
            if ($year)     $lines[] = "  → Year     : {$year}";
            if ($people)   $lines[] = "  → People   : {$people}";
            if ($places)   $lines[] = "  → Places   : {$places}";
            if ($dates)    $lines[] = "  → Dates    : {$dates}";
            $lines[] = "  → Source   : {$fact['article_title']} ({$fact['source_url']})";
            $lines[] = "  → License  : {$fact['source_license']}";
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Grade level hierarchy: a K8 lesson may use K6 facts (simpler),
     * a K12 lesson may use all facts.
     */
    private static function gradeHierarchy(string $gradeLevel): array
    {
        return match ($gradeLevel) {
            'K6'    => ['K6'],
            'K8'    => ['K6', 'K8'],
            'K12'   => ['K6', 'K8', 'K12'],
            default => ['K6', 'K8', 'K12'],
        };
    }

    /** Map raw DB rows to a consistent array shape. */
    private static function mapRows(array $rows): Collection
    {
        return collect($rows)->map(fn ($row) => [
            'fact_text'      => $row->fact_text,
            'people'         => self::parseArray($row->people  ?? null),
            'places'         => self::parseArray($row->places  ?? null),
            'dates'          => self::parseArray($row->dates   ?? null),
            'events'         => self::parseArray($row->events  ?? null),
            'tags'           => self::parseArray($row->tags    ?? null),
            // Scene-pipeline-ready fields
            'location'       => $row->location    ?? null,
            'year'           => $row->year_display ?? null,   // Scene.year string
            'start_year'     => $row->start_year  ?? null,    // int, negative = BCE
            'end_year'       => $row->end_year    ?? null,    // int, = start_year if single point
            'grade_level'    => $row->grade_level ?? null,
            'confidence'     => $row->confidence  ?? null,
            'article_title'  => $row->article_title ?? null,
            'source_url'     => $row->source_url  ?? null,
            'source_license' => $row->source_license ?? null,
        ]);
    }

    /**
     * Parse a PostgreSQL array string or JSON array into a PHP array.
     * Handles both formats returned by asyncpg: {item1,item2} and ["item1","item2"].
     */
    private static function parseArray(mixed $value): array
    {
        if (is_array($value))   return $value;
        if (is_null($value))    return [];
        $v = trim((string) $value);
        if ($v === '' || $v === '{}' || $v === '[]') return [];
        // PostgreSQL array format: {item1,"item with spaces",item2}
        if (str_starts_with($v, '{')) {
            $inner   = trim($v, '{}');
            $decoded = json_decode('[' . $inner . ']', true);
            if (is_array($decoded)) return $decoded;
            // Fallback: naïve CSV split (won't handle quoted commas correctly but rare)
            return array_map('trim', explode(',', $inner));
        }
        // JSON array
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : [];
    }
}
