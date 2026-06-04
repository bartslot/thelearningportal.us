<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view over the existing corpus table public.history_articles.
 * Never written by the app.
 */
class Article extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'history_articles';

    public $timestamps = false;

    protected $guarded = [];

    /** Articles whose era brackets the given year, for a macro-region. */
    public function scopeForRegionYear($query, string $region, int $year)
    {
        return $query->where('region', $region)
            ->whereNotNull('era_start')
            ->whereNotNull('era_end')
            ->where('era_start', '<=', $year)
            ->where('era_end', '>=', $year);
    }
}
