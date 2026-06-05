<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportBoundaries extends Command
{
    protected $signature = 'timemap:import-boundaries {--path= : Override the GeoJSON snapshot directory}';

    protected $description = 'Load historical-basemaps GeoJSON snapshots into PostGIS public.boundaries';

    /**
     * Seeded snapshot year => filename (without extension). Edit alongside the vendored files.
     * Note: world_1 (404) substituted with world_200 (nearest available).
     */
    private array $snapshots = [
        -2000 => 'world_bc2000',
        -1500 => 'world_bc1500',
        -500 => 'world_bc500',
        200 => 'world_200',
        500 => 'world_500',
        1000 => 'world_1000',
        1880 => 'world_1880',
    ];

    public function handle(): int
    {
        $dir = $this->option('path') ?: base_path('database/data/historical-basemaps');
        $years = array_keys($this->snapshots);
        sort($years);

        $corpus = DB::connection('pgsql_corpus');
        // Idempotent: clear only rows this importer owns.
        $corpus->statement("DELETE FROM public.boundaries WHERE source = 'historical-basemaps'");

        $imported = 0;
        $skipped = 0;
        foreach ($this->snapshots as $year => $file) {
            $path = "{$dir}/{$file}.geojson";
            if (! File::exists($path)) {
                $this->warn("missing snapshot {$file}.geojson — skipping");

                continue;
            }

            // This snapshot is "valid" until the next seeded year (or +∞ for the last).
            $idx = array_search($year, $years, true);
            $validTo = $years[$idx + 1] ?? 3000;

            $fc = json_decode(File::get($path), true);
            foreach ($fc['features'] ?? [] as $feature) {
                $name = $feature['properties']['NAME']
                    ?? $feature['properties']['name'] ?? null;
                $geometry = $feature['geometry'] ?? null;
                if (! $name || ! $geometry) {
                    continue;
                }

                try {
                    $corpus->statement(
                        "INSERT INTO public.boundaries
                           (polity_id,name,valid_from,valid_to,geom,source,license,extra,created_at,updated_at)
                         VALUES (?,?,?,?, ST_SetSRID(ST_GeomFromGeoJSON(?),4326), ?,?, '{}'::jsonb, now(), now())",
                        [
                            Str::slug($name),
                            $name,
                            $year,
                            $validTo,
                            json_encode($geometry),
                            'historical-basemaps',
                            'CC-BY-SA 4.0 — historical-basemaps (A. Ourednik)',
                        ]
                    );
                    $imported++;
                } catch (\Throwable $e) {
                    $skipped++; // unsupported/invalid geometry — skip rather than abort
                }
            }
        }

        // Tag every imported boundary with one of the 7 corpus macro-regions by its
        // centroid, so each polygon resolves to the articles for that region.
        $corpus->statement($this->regionTaggingSql());

        $this->info("imported {$imported} boundary features".($skipped > 0 ? " ({$skipped} skipped: bad geometry)" : ''));

        return self::SUCCESS;
    }

    /** Bucket a polygon's centroid (lng=ST_X, lat=ST_Y) into a corpus macro-region. */
    private function regionTaggingSql(): string
    {
        return <<<'SQL'
            UPDATE public.boundaries AS b
            SET extra = jsonb_build_object('region', r.region)
            FROM (
                SELECT id,
                    CASE
                        WHEN ST_X(c) < -25 THEN 'Americas'
                        WHEN ST_X(c) >= 95 THEN 'East Asia'
                        WHEN ST_X(c) >= 60 AND ST_Y(c) < 35 THEN 'South Asia'
                        WHEN ST_X(c) >= 60 THEN 'East Asia'
                        WHEN ST_Y(c) >= 48 THEN 'Northern Europe'
                        WHEN ST_X(c) >= 35 THEN 'Middle East'
                        WHEN ST_Y(c) < 20 THEN 'Africa'
                        ELSE 'Mediterranean'
                    END AS region
                FROM (
                    -- geom is a PostGIS `geography` column; cast to geometry for ST_X/ST_Y.
                    SELECT id, ST_Centroid(geom::geometry) AS c
                    FROM public.boundaries
                    WHERE source = 'historical-basemaps'
                ) s
            ) r
            WHERE b.id = r.id
            SQL;
    }
}
