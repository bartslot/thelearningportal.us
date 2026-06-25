<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Import major cities from Natural Earth populated places (public domain) into the `cities`
 * table. Mirrors the source + filter used by timemap:build-city-tiles (BuildCityTiles): the
 * same GeoJSON, the same User-Agent, and the same SCALERANK ≤ 7 importance cutoff, so the
 * data layer and the map tiles stay in sync.
 *
 * Upserts by (name, country) so re-running is idempotent and never duplicates a city. The
 * curated historical-name seeder runs separately and is not touched here.
 */
class ImportCities extends Command
{
    protected $signature = 'app:import-cities {--max-scalerank=7 : Keep cities with SCALERANK ≤ this}';

    protected $description = 'Import Natural Earth populated places into the cities table';

    private const NE_URL = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_populated_places.geojson';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    /** Rows per upsert batch — keeps the SQL statement and memory bounded. */
    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $this->info('Downloading Natural Earth populated places…');

        $response = Http::withHeaders(['User-Agent' => self::UA])
            ->timeout(120)
            ->get(self::NE_URL);

        if (! $response->successful()) {
            $this->error('Could not fetch NE populated places (HTTP '.$response->status().').');

            return self::FAILURE;
        }

        $features = $response->json('features');
        if (! is_array($features)) {
            $this->error('NE response did not contain a features array.');

            return self::FAILURE;
        }

        $maxRank = (int) $this->option('max-scalerank');

        // De-duplicate by the (name, country) conflict key before upserting: Natural Earth
        // ships a few places sharing a name within the same sovereign, and Postgres rejects a
        // batch that proposes the same constrained key twice. Keep the most important (lowest
        // scalerank) row per key.
        $unique = [];
        foreach ($features as $feature) {
            $row = $this->rowFromFeature($feature, $maxRank);
            if ($row === null) {
                continue;
            }

            $key = $row['name'].'|'.($row['country'] ?? '');
            $existing = $unique[$key] ?? null;
            if ($existing === null || $row['scalerank'] < $existing['scalerank']) {
                $unique[$key] = $row;
            }
        }

        if ($unique === []) {
            $this->warn('No cities passed the SCALERANK ≤ '.$maxRank.' filter.');

            return self::SUCCESS;
        }

        $imported = 0;
        foreach (array_chunk(array_values($unique), self::CHUNK_SIZE) as $chunk) {
            $imported += $this->flush($chunk);
        }

        $this->info("Imported {$imported} cities (SCALERANK ≤ {$maxRank}).");

        return self::SUCCESS;
    }

    /**
     * Map one GeoJSON feature to a City upsert row, or null when it should be skipped
     * (over the scalerank cutoff, missing a name, or missing coordinates).
     *
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>|null
     */
    private function rowFromFeature(array $feature, int $maxRank): ?array
    {
        $props = $feature['properties'] ?? [];

        $rank = (int) ($props['SCALERANK'] ?? 99);
        if ($rank > $maxRank) {
            return null;
        }

        $name = $props['NAME'] ?? $props['NAMEASCII'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }
        $name = trim($name);

        $coordinates = $feature['geometry']['coordinates'] ?? null;
        if (! is_array($coordinates) || ! isset($coordinates[0], $coordinates[1])) {
            return null;
        }
        // GeoJSON order is [lng, lat].
        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];

        $isCapital = ((int) ($props['ADM0CAP'] ?? 0)) === 1
            || stripos((string) ($props['FEATURECLA'] ?? ''), 'capital') !== false;

        $country = $props['SOV0NAME'] ?? $props['ADM0NAME'] ?? null;
        if (is_string($country) && trim($country) === '') {
            $country = null;
        }

        return [
            'name' => $name,
            'country' => $country,
            'lat' => $lat,
            'lng' => $lng,
            'scalerank' => $rank,
            'pop' => isset($props['POP_MAX']) ? (int) $props['POP_MAX'] : null,
            'is_capital' => $isCapital,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Upsert a batch of rows by (name, country) and return the number written.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        City::upsert(
            $rows,
            ['name', 'country'],
            ['lat', 'lng', 'scalerank', 'pop', 'is_capital', 'updated_at'],
        );

        return count($rows);
    }
}
