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
        $boundariesDir = public_path('geo/boundaries');
        $labelsDir = public_path('geo/labels');
        File::ensureDirectoryExists($boundariesDir);
        File::ensureDirectoryExists($labelsDir);

        $corpus = DB::connection('pgsql_corpus');
        $total = 0;

        foreach ($this->years as $year) {
            // Join polities so we can drop insignificant polygons and carry label/flag/date data.
            $rows = $corpus->select(
                'select b.polity_id,
                        coalesce(p.label, b.name) as label,
                        b.valid_from, b.valid_to,
                        coalesce(p.flag_path, null) as flag_path,
                        ST_AsGeoJSON(b.geom::geometry, 6) as geometry,
                        ST_AsGeoJSON(ST_PointOnSurface(b.geom::geometry)) as centroid
                 from public.boundaries b
                 left join public.polities p on p.polity_id = b.polity_id
                 where b.valid_from <= ? and b.valid_to >= ?
                   and coalesce(p.significant, true) = true',
                [$year, $year]
            );

            $features = [];
            $labels = [];
            foreach ($rows as $r) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'polity_id' => $r->polity_id,
                        'label' => $r->label,
                        'year_start' => (int) $r->valid_from,
                        'year_end' => (int) $r->valid_to,
                        'color_key' => abs(crc32($r->polity_id)) % 12,
                    ],
                    'geometry' => json_decode($r->geometry),
                ];
                $labels[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'polity_id' => $r->polity_id,
                        'label' => $r->label,
                        'date_label' => $this->dateLabel((int) $r->valid_from, (int) $r->valid_to),
                        'flag_id' => $r->flag_path ? $r->polity_id : '',
                    ],
                    'geometry' => json_decode($r->centroid),
                ];
            }

            File::put("{$boundariesDir}/{$year}.geojson", json_encode(['type' => 'FeatureCollection', 'features' => $features]));
            File::put("{$labelsDir}/{$year}.geojson", json_encode(['type' => 'FeatureCollection', 'features' => $labels]));
            $this->info(sprintf('wrote %d: %d polygons + labels', $year, count($features)));
            $total += count($features);
        }

        $this->info("exported {$total} significant polygons to {$boundariesDir}");

        return self::SUCCESS;
    }

    private function dateLabel(int $from, int $to): string
    {
        $fmt = fn (int $y) => $y < 0 ? abs($y).' BCE' : $y.' CE';

        return $fmt($from).' – '.$fmt($to);
    }
}
