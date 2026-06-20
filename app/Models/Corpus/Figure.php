<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view over public.figures — rulers + notable people per polity, sourced from Wikidata via
 * the Cliopatria QID linkage. Never written by the app; populated offline by `timemap:build-topics`.
 *
 * The underlying table is keyed by the composite (qid, parent_qid) — a person can belong to several
 * polities — so this model is only ever queried through scopes, never by primary key.
 */
class Figure extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'figures';

    protected $primaryKey = 'qid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'era_start' => 'integer',
        'era_end' => 'integer',
        'sitelinks' => 'integer',
        'is_publishable' => 'boolean',
    ];

    /**
     * A polity's figures, ranked for the hero picker: rulers first (they make better protagonists
     * than fame-ranked citizens), then by fame. Pass the polity's QID (e.g. 'Q12560' for the Ottomans).
     */
    public function scopeForTopic($query, string $parentQid)
    {
        return $query->where('parent_qid', $parentQid)
            ->orderByRaw("CASE WHEN figure_kind = 'ruler' THEN 0 ELSE 1 END")
            ->orderByDesc('sitelinks');
    }

    /**
     * Restrict to figures whose lifetime/reign overlaps [start, end]. Figures with an unknown era
     * (both bounds null) are kept. A no-op when both arguments are null.
     */
    public function scopeOverlappingEra($query, ?int $start, ?int $end)
    {
        if ($start === null && $end === null) {
            return $query;
        }

        return $query->where(function ($outer) use ($start, $end) {
            $outer->where(function ($known) use ($start, $end) {
                if ($end !== null) {
                    $known->where('era_start', '<=', $end);
                }
                if ($start !== null) {
                    $known->where('era_end', '>=', $start);
                }
            })->orWhere(function ($unknown) {
                $unknown->whereNull('era_start')->whereNull('era_end');
            });
        });
    }

    /**
     * Run a corpus query, self-healing a dropped pooler connection by reconnecting once. The remote
     * pooler closes idle connections; the first query after a drop throws and a reconnect recovers it.
     * Mirrors App\Models\Corpus\Topic::resilient.
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
}
