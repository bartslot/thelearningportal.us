<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Build the Time-Map / lesson-map "big cities" labels from Natural Earth populated places
 * (public domain). The gazetteer's place_type='city' is polluted with polities, so we use NE,
 * which carries clean city names + a scalerank (importance). Tiled with tippecanoe (z0-6,
 * drop-densest) into public/city-tiles so only the major, spread-out cities survive at low zoom.
 *
 * Tiles are committed (SiteGround has no tippecanoe), so this is a dev/CI regeneration tool.
 * Requires `tippecanoe` on PATH.
 */
class BuildCityTiles extends Command
{
    protected $signature = 'timemap:build-city-tiles {--min-zoom=0} {--max-zoom=6} {--max-scalerank=7}';

    protected $description = 'Tile Natural Earth populated places into public/city-tiles (needs tippecanoe)';

    private const NE_URL = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_populated_places.geojson';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(): int
    {
        if ((new Process(['which', 'tippecanoe']))->run() !== 0) {
            $this->error('tippecanoe not found on PATH. Install it: brew install tippecanoe');

            return self::FAILURE;
        }

        $this->info('Downloading Natural Earth populated places…');
        $src = Http::withHeaders(['User-Agent' => self::UA])->timeout(120)->get(self::NE_URL)->json();
        if (! is_array($src['features'] ?? null)) {
            $this->error('Could not fetch NE populated places.');

            return self::FAILURE;
        }

        $maxRank = (int) $this->option('max-scalerank');
        $features = [];
        foreach ($src['features'] as $f) {
            $p = $f['properties'] ?? [];
            $name = $p['NAME'] ?? $p['NAMEASCII'] ?? null;
            $rank = (int) ($p['SCALERANK'] ?? 99);
            if ($name === null || $name === '' || $rank > $maxRank) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => $f['geometry'],
                'properties' => [
                    'name' => $name,
                    'scalerank' => $rank,
                    'pop' => (int) ($p['POP_MAX'] ?? 0),
                ],
            ];
        }

        if ($features === []) {
            $this->error('No cities passed the scalerank filter.');

            return self::FAILURE;
        }

        $work = storage_path('app/citytiles');
        File::ensureDirectoryExists($work);
        $geojson = "{$work}/cities.geojson";
        File::put($geojson, json_encode(['type' => 'FeatureCollection', 'features' => $features]));
        $this->info(count($features).' cities written to GeoJSON (scalerank ≤ '.$maxRank.').');

        $out = public_path('city-tiles');
        File::deleteDirectory($out);

        $this->info('Tiling with tippecanoe…');
        $tippecanoe = new Process([
            'tippecanoe', '-e', $out,
            '-l', 'cities', '-Z'.(int) $this->option('min-zoom'), '-z'.(int) $this->option('max-zoom'),
            '--no-tile-compression',
            '--drop-densest-as-needed', '--extend-zooms-if-still-dropping', '--no-tile-size-limit',
            '--order-by=scalerank',           // keep the more-important cities when thinning
            '-y', 'name', '-y', 'scalerank', '-y', 'pop',
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
