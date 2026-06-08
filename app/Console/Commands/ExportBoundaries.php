<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Write the boundary polygons for each seeded era to static GeoJSON files under
 * public/geo/boundaries/{year}.geojson. The Time-Map then loads borders from these static
 * files (instant, browser-cacheable) instead of querying the remote Supabase DB on every load.
 *
 * Run after timemap:import-boundaries (and re-run if boundaries change).
 */
class ExportBoundaries extends Command
{
    protected $signature = 'timemap:export-boundaries';

    protected $description = 'Export boundary polygons to static per-era GeoJSON files for fast loading';

    /** Snapshot era years — must match ImportBoundaries::$snapshots keys. */
    private array $years = [-2000, -1500, -500, 200, 500, 1000, 1880];

    public function handle(): int
    {
        $dir = public_path('geo/boundaries');
        File::ensureDirectoryExists($dir);

        $corpus = DB::connection('pgsql_corpus');
        $total = 0;

        foreach ($this->years as $year) {
            // Full geometry, 6-decimal coords (~0.1 m — visually lossless, no simplification).
            $rows = $corpus->select(
                "select polity_id, name, extra->>'region' as region,
                        ST_AsGeoJSON(geom::geometry, 6) as geometry
                 from public.boundaries
                 where valid_from <= ? and valid_to >= ?",
                [$year, $year]
            );

            $features = array_map(fn ($r) => [
                'type' => 'Feature',
                'properties' => ['polity_id' => $r->polity_id, 'name' => $r->name, 'region' => $r->region],
                'geometry' => json_decode($r->geometry),
            ], $rows);

            $path = "{$dir}/{$year}.geojson";
            File::put($path, json_encode(['type' => 'FeatureCollection', 'features' => $features]));
            $this->info(sprintf('wrote %d.geojson (%d features, %.0f KB)', $year, count($rows), filesize($path) / 1024));
            $total += count($rows);
        }

        $this->info("exported {$total} boundary features to {$dir}");

        return self::SUCCESS;
    }
}
