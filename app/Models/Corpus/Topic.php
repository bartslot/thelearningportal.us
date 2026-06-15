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
     * Search the catalog by name. Prefix matches rank above substring matches; ties broken by
     * sitelinks (fame). Returns up to $limit rows.
     */
    public function scopeSearch($query, string $term, int $limit = 10)
    {
        $term = trim($term);
        if ($term === '') {
            return $query->orderByDesc('sitelinks')->limit($limit);
        }

        $like = str_replace(['%', '_'], ['\%', '\_'], $term);

        return $query
            ->where('name', 'ILIKE', '%'.$like.'%')
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$like.'%'])
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
