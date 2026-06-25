<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\City;
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
                'features' => City::whereNotNull('historical_name')
                    ->get(['name', 'lat', 'lng', 'scalerank', 'historical_name', 'historical_period'])
                    ->map(fn (City $city): array => [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [$city->lng, $city->lat],
                        ],
                        'properties' => [
                            'name' => $city->name,
                            'historical' => $city->historical_name,
                            'period' => $city->historical_period,
                            'scalerank' => $city->scalerank,
                        ],
                    ])
                    ->all(),
            ]
        );

        return response()->json($featureCollection);
    }
}
