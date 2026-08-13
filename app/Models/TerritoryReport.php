<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's report that a territory on the Time-Map is wrong.
 *
 * See the migration for why each column is here. The short version: a report is only worth having
 * if it arrives with enough context to act on without going back to ask.
 */
class TerritoryReport extends Model
{
    protected $fillable = [
        'user_id', 'territory_id', 'wikidata_qid', 'label',
        'source', 'year', 'confidence', 'chosen_source', 'comment',
    ];

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** True when the report is about a shape we drew ourselves, rather than one we imported. */
    public function isHandAuthored(): bool
    {
        return $this->source === 'hand';
    }
}
