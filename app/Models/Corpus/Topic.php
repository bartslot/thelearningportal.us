<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view over public.topics (polities UNION figures) — the single curated source for
 * the lesson topic picker (A2). Never written by the app; built by `timemap:build-topics`.
 */
class Topic extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'topics';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'era_start' => 'integer',
        'era_end' => 'integer',
        'region_lat' => 'float',
        'region_lng' => 'float',
        'sitelinks' => 'integer',
    ];

    /**
     * Search the catalog by name. Ranking, best first: exact name → name prefix →
     * word prefix ("Empire of Rome" for "rome") → fuzzy word similarity ("Roman
     * Empire" for "rome", via pg_trgm) → plain substring. Ties broken by sitelinks
     * (fame). The old pure-substring match surfaced "Óscar Romero"/"Prome Kingdom"
     * for "Rome" while missing "Roman Empire" entirely.
     */
    public function scopeSearch($query, string $term, int $limit = 10)
    {
        $term = trim($term);
        if ($term === '') {
            return $query->orderByDesc('sitelinks')->limit($limit);
        }

        $like = str_replace(['%', '_'], ['\%', '\_'], $term);

        return $query
            // Match the name OR any pipe-joined alias ("Black Plague"/"Spanish flu"/"The Great
            // War"). Aliases are NULL for non-event rows, so COALESCE keeps them out of the way.
            ->whereRaw(
                '(name ILIKE ? OR word_similarity(?, name) > 0.55
                  OR COALESCE(aliases, \'\') ILIKE ? OR word_similarity(?, COALESCE(aliases, \'\')) > 0.6)',
                ['%'.$like.'%', $term, '%'.$like.'%', $term]
            )
            // Exact-name match always wins, whatever the type ("Napoleon" → the person, "Roman
            // Empire" → the polity).
            ->orderByRaw('CASE WHEN lower(name) = lower(?) THEN 0 ELSE 1 END', [$term])
            // Then relevance (prefix → word-prefix → alias → fuzzy → other) with a one-tier boost
            // for events, so a history lesson's usual subject — the event ("Black Death", "French
            // Revolution") — leads over a person/place among non-exact matches.
            ->orderByRaw(
                '(CASE
                    WHEN name ILIKE ? THEN 1
                    WHEN name ILIKE ? THEN 2
                    WHEN COALESCE(aliases, \'\') ILIKE ? THEN 3
                    WHEN word_similarity(?, name) > 0.55 THEN 4
                    ELSE 5
                  END) - CASE WHEN type = \'event\' THEN 1 ELSE 0 END',
                [$like.'%', '% '.$like.'%', '%'.$like.'%', $term]
            )
            ->orderByRaw("CASE type WHEN 'event' THEN 0 WHEN 'figure' THEN 1 WHEN 'polity' THEN 2 ELSE 3 END")
            ->orderByDesc('sitelinks')
            ->limit($limit);
    }

    /**
     * Run a corpus query, self-healing a dropped pooler connection by reconnecting once.
     * The remote pooler closes idle connections; the first query after a drop throws
     * "server closed the connection unexpectedly" — a reconnect + retry recovers transparently.
     *
     * @template T
     *
     * @param  callable():T  $query
     * @return T
     */
    public static function resilient(callable $query)
    {
        try {
            return $query();
        } catch (\Illuminate\Database\QueryException $e) {
            DB::connection('pgsql_corpus')->reconnect();

            return $query();
        }
    }

    /** A short "era · region" label for the picker. */
    public function eraLabel(): string
    {
        $fmt = function (?int $y): ?string {
            if ($y === null) {
                return null;
            }

            return $y < 0 ? abs($y).' BCE' : $y.' CE';
        };

        $start = $fmt($this->era_start);
        $end = $fmt($this->era_end);

        if ($start && $end) {
            return "{$start} – {$end}";
        }

        return $start ?? $end ?? '';
    }
}
