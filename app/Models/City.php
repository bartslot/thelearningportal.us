<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A city used by the Time-Map / lesson-map features. Seeded from Natural Earth populated
 * places and enriched with curated historical names. Coordinates are [lat, lng].
 */
class City extends Model
{
    protected $fillable = [
        'name',
        'lat',
        'lng',
        'scalerank',
        'pop',
        'is_capital',
        'country',
        'historical_name',
        'historical_period',
        'wikidata_qid',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'scalerank' => 'integer',
            'pop' => 'integer',
            'is_capital' => 'boolean',
        ];
    }
}
