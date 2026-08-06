<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\City;
use App\Support\HistoricalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Serves the curated historical cities as a GeoJSON FeatureCollection for the lesson atlas
 * overlay (labelled "Constantinople (Istanbul)" markers above the normal city labels).
 *
 * Reference data — public and unauthenticated. Cached for a day since the gazetteer is static;
 * keyed on `map.historical_cities.geojson`.
 */
final class HistoricalCitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $featureCollection = Cache::remember(
            'map.historical_cities.geojson',
            now()->addDay(),
            fn (): array => [
                'type' => 'FeatureCollection',
                // A city whose coordinate never resolved is stored at 0,0, and 0,0 is a real place on
                // a map: the Gulf of Guinea. Every unresolved city therefore drew as a labelled dot
                // off the coast of Nigeria — for a while that included Constantinople. Skip them
                // here rather than downstream, so no amount of bad seed data can put a city in the
                // sea again. A city genuinely at Null Island does not exist.
                'features' => City::whereNotNull('historical_name')
                    ->whereNotNull('lat')->whereNotNull('lng')
                    ->whereNot(fn ($q) => $q->where('lat', 0)->where('lng', 0))
                    ->get(['name', 'lat', 'lng', 'scalerank', 'historical_name', 'historical_period'])
                    ->map(function (City $city): array {
                        // The curated period is prose ("330–1453 CE", "Roman era"); the map needs two
                        // numbers to filter on, or it draws Stalingrad beside Persepolis. Unparseable
                        // periods stay visible at every year rather than vanishing — see HistoricalPeriod.
                        [$from, $to] = HistoricalPeriod::parse($city->historical_period) ?? [-99999, 99999];

                        return [
                            'type' => 'Feature',
                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [$city->lng, $city->lat],
                            ],
                            'properties' => [
                                'name' => $city->name,
                                'historical' => $city->historical_name,
                                'period' => $city->historical_period,
                                'from' => $from,
                                'to' => $to,
                                'scalerank' => $city->scalerank,
                            ],
                        ];
                    })
                    ->all(),
            ]
        );

        return response()->json($featureCollection);
    }
}
