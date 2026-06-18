<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Build lake tiles from Natural Earth 10m lakes (public domain) into public/lake-tiles. Lakes are
 * essential alongside rivers — IJsselmeer, Lake Geneva, the north-Italian lakes, etc. Tiled with
 * tippecanoe (drop-densest) so small lakes appear as you zoom in.
 *
 * Tiles are committed (SiteGround has no tippecanoe), so this is a dev/CI regeneration tool.
 * Requires `tippecanoe` on PATH.
 */
class BuildLakeTiles extends Command
{
    protected $signature = 'timemap:build-lake-tiles {--min-zoom=0} {--max-zoom=8}';

    protected $description = 'Tile Natural Earth lakes into public/lake-tiles (needs tippecanoe)';

    private const NE_URL = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_10m_lakes.geojson';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(): int
    {
        if ((new Process(['which', 'tippecanoe']))->run() !== 0) {
            $this->error('tippecanoe not found on PATH. Install it: brew install tippecanoe');

            return self::FAILURE;
        }

        $cache = storage_path('app/naturalearth/ne_10m_lakes.geojson');
        if (! File::exists($cache)) {
            $this->info('Downloading Natural Earth 10m lakes…');
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache, Http::withHeaders(['User-Agent' => self::UA])->timeout(180)->get(self::NE_URL)->body());
        }

        $src = json_decode(File::get($cache), true);
        if (! is_array($src['features'] ?? null)) {
            $this->error('Could not read NE lakes geojson.');

            return self::FAILURE;
        }

        // Keep just name + scalerank (drop NE's ~40 localized name fields).
        $features = [];
        foreach ($src['features'] as $f) {
            $p = $f['properties'] ?? [];
            $features[] = [
                'type' => 'Feature',
                'geometry' => $f['geometry'],
                'properties' => [
                    'name' => $p['name'] ?? null,
                    'scalerank' => (int) ($p['scalerank'] ?? 5),
                ],
            ];
        }

        $work = storage_path('app/laketiles');
        File::ensureDirectoryExists($work);
        $geojson = "{$work}/lakes.geojson";
        File::put($geojson, json_encode(['type' => 'FeatureCollection', 'features' => $features]));
        $this->info(count($features).' lakes written to GeoJSON.');

        $out = public_path('lake-tiles');
        File::deleteDirectory($out);

        $this->info('Tiling with tippecanoe…');
        $tippecanoe = new Process([
            'tippecanoe', '-e', $out,
            '-l', 'lakes', '-Z'.(int) $this->option('min-zoom'), '-z'.(int) $this->option('max-zoom'),
            '--no-tile-compression',
            '--drop-densest-as-needed', '--coalesce-densest-as-needed', '--no-tile-size-limit',
            '--order-by=scalerank',
            '-y', 'name', '-y', 'scalerank',
            '--force', $geojson,
        ]);
        $tippecanoe->setTimeout(600);
        $tippecanoe->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $tippecanoe->isSuccessful()) {
            $this->error('tippecanoe failed.');

            return self::FAILURE;
        }

        $this->info('Built '.$out);

        return self::SUCCESS;
    }
}
