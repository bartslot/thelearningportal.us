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
        -500  => 'world_bc500',
        200   => 'world_200',
        500   => 'world_500',
        1000  => 'world_1000',
        1880  => 'world_1880',
    ];

    public function handle(): int
    {
        $dir = $this->option('path') ?: base_path('database/data/historical-basemaps');
        $regionMap = json_decode(File::get(resource_path('data/region-map.json')), true);
        $years = array_keys($this->snapshots);
        sort($years);

        $corpus = DB::connection('pgsql_corpus');
        // Idempotent: clear only rows this importer owns.
        $corpus->statement("DELETE FROM public.boundaries WHERE source = 'historical-basemaps'");

        $imported = 0;
        foreach ($this->snapshots as $year => $file) {
            $path = "{$dir}/{$file}.geojson";
            if (! File::exists($path)) {
                $this->warn("missing snapshot {$file}.geojson — skipping");
                continue;
            }

            $idx = array_search($year, $years, true);
            $validTo = $years[$idx + 1] ?? 3000;

            $fc = json_decode(File::get($path), true);
            foreach ($fc['features'] ?? [] as $feature) {
                $name = $feature['properties']['NAME']
                    ?? $feature['properties']['name'] ?? null;
                if (! $name) {
                    continue;
                }
                $slug = Str::slug($name);
                if (! isset($regionMap[$slug])) {
                    continue; // outside the seeded spine
                }

                $corpus->statement(
                    'INSERT INTO public.boundaries
                       (polity_id,name,valid_from,valid_to,geom,source,license,extra,created_at,updated_at)
                     VALUES (?,?,?,?, ST_SetSRID(ST_GeomFromGeoJSON(?),4326), ?,?, ?::jsonb, now(), now())',
                    [
                        $slug,
                        $name,
                        $year,
                        $validTo,
                        json_encode($feature['geometry']),
                        'historical-basemaps',
                        'CC-BY-SA 4.0 — historical-basemaps (A. Ourednik)',
                        json_encode(['region' => $regionMap[$slug]]),
                    ]
                );
                $imported++;
            }
        }

        $this->info("imported {$imported} boundary features");

        return self::SUCCESS;
    }
}
