<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Mirror OpenHistoricalMap vector tiles into public/ohm-tiles so the Time-Map serves them
 * statically (no live dependency). Low-zoom only — the map is an overview navigation tool.
 */
class FetchOhmTiles extends Command
{
    protected $signature = 'timemap:fetch-ohm-tiles {--maxzoom=4}';

    protected $description = 'Mirror OHM admin + land vector tiles to public/ohm-tiles';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    /** Europe-biased coverage box [west, south, east, north]; world at z0-2 for context. */
    private array $bbox = [-25.0, 30.0, 50.0, 72.0];

    /** tileset name => base URL. */
    private array $tilesets = [
        'ohm_admin' => 'https://vtiles.openhistoricalmap.org/maps/ohm_admin',
        'osm_land' => 'https://vtiles.openhistoricalmap.org/maps/osm_land',
    ];

    public function handle(): int
    {
        $maxZoom = (int) $this->option('maxzoom');
        $base = public_path('ohm-tiles');
        $got = 0;
        $bytes = 0;

        foreach ($this->tilesets as $set => $url) {
            for ($z = 0; $z <= $maxZoom; $z++) {
                [$x0, $y0, $x1, $y1] = $this->tileRange($z);
                for ($x = $x0; $x <= $x1; $x++) {
                    for ($y = $y0; $y <= $y1; $y++) {
                        $res = Http::withHeaders(['User-Agent' => self::UA])->get("{$url}/{$z}/{$x}/{$y}.pbf");
                        if (! $res->ok()) {
                            continue; // 404 = empty tile, skip
                        }
                        $dir = "{$base}/{$set}/{$z}/{$x}";
                        File::ensureDirectoryExists($dir);
                        File::put("{$dir}/{$y}.pbf", $res->body());
                        $got++;
                        $bytes += strlen($res->body());
                    }
                }
            }
            $this->info("{$set}: mirrored to z{$maxZoom}");
        }

        $this->info(sprintf('fetched %d tiles, %.1f MB', $got, $bytes / 1048576));

        return self::SUCCESS;
    }

    /** Web-mercator tile x/y range covering $this->bbox at zoom $z (world at z<=2). */
    private function tileRange(int $z): array
    {
        $n = 2 ** $z;
        if ($z <= 2) {
            return [0, 0, $n - 1, $n - 1]; // whole world for context at the lowest zooms
        }
        [$w, $s, $e, $north] = $this->bbox;
        $lon2x = fn (float $lon) => (int) floor(($lon + 180) / 360 * $n);
        $lat2y = function (float $lat) use ($n) {
            $r = deg2rad($lat);

            return (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n);
        };

        return [max(0, $lon2x($w)), max(0, $lat2y($north)), min($n - 1, $lon2x($e)), min($n - 1, $lat2y($s))];
    }
}
