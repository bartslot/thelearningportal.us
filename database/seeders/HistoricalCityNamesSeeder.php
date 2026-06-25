<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Enrich the cities table with curated historical names (database/data/historical_city_names.php).
 *
 * For each curated row, find the city by name (case-insensitive, ILIKE) and set
 * historical_name / historical_period / wikidata_qid. When Natural Earth never imported the
 * city (app:import-cities), a stub row is created with lat/lng 0 and a note in the country
 * column so it is obvious the coordinates are unset.
 *
 * Idempotent: re-running only overwrites the three historical columns.
 */
class HistoricalCityNamesSeeder extends Seeder
{
    /** Marker written to `country` on rows created here when NE had no match. */
    private const STUB_NOTE = 'curated stub (no coordinates)';

    public function run(): void
    {
        $path = database_path('data/historical_city_names.php');
        if (! File::exists($path)) {
            $this->command?->error('database/data/historical_city_names.php missing.');

            return;
        }

        /** @var array<int, array{modern: string, historical: string, period: string, qid: string}> $rows */
        $rows = require $path;

        $matched = 0;
        $created = 0;

        foreach ($rows as $row) {
            $modern = trim((string) ($row['modern'] ?? ''));
            if ($modern === '') {
                continue;
            }

            $city = City::whereRaw('name ILIKE ?', [$modern])->first();

            if ($city === null) {
                $city = new City([
                    'name' => $modern,
                    'lat' => 0.0,
                    'lng' => 0.0,
                    'country' => self::STUB_NOTE,
                ]);
                $created++;
            } else {
                $matched++;
            }

            $city->historical_name = $row['historical'] ?? null;
            $city->historical_period = $row['period'] ?? null;
            $city->wikidata_qid = $row['qid'] ?? null;
            $city->save();
        }

        $this->command?->info(
            "Historical names: {$matched} matched existing cities, {$created} created as stubs."
        );
    }
}
