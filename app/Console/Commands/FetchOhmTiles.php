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
    protected $signature = 'timemap:fetch-ohm-tiles {--maxzoom=4} {--skip-existing : Only fetch tiles missing from disk (backfill/resume)}';

    protected $description = 'Mirror OHM admin + land vector tiles to public/ohm-tiles';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    /** tileset name => base URL. */
    private array $tilesets = [
        'ohm_admin' => 'https://vtiles.openhistoricalmap.org/maps/ohm_admin',
        'osm_land' => 'https://vtiles.openhistoricalmap.org/maps/osm_land',
    ];

    public function handle(): int
    {
        $maxZoom = (int) $this->option('maxzoom');
        $skipExisting = (bool) $this->option('skip-existing');
        $base = public_path('ohm-tiles');
        $got = 0;
        $bytes = 0;

        $skipped = 0;
        foreach ($this->tilesets as $set => $url) {
            for ($z = 0; $z <= $maxZoom; $z++) {
                [$x0, $y0, $x1, $y1] = $this->tileRange($z);
                for ($x = $x0; $x <= $x1; $x++) {
                    $dir = "{$base}/{$set}/{$z}/{$x}";
                    for ($y = $y0; $y <= $y1; $y++) {
                        $path = "{$dir}/{$y}.pbf";
                        if ($skipExisting && File::exists($path)) {
                            continue; // already mirrored — backfill only the holes
                        }
                        $body = $this->fetchTile("{$url}/{$z}/{$x}/{$y}.pbf", $skipped);
                        if ($body === null) {
                            continue; // empty (404) or gave up after retries
                        }
                        File::ensureDirectoryExists($dir);
                        File::put($path, $body);
                        $got++;
                        $bytes += strlen($body);
                        usleep(40_000); // ~25 req/s — polite, avoids OHM rate-limiting
                    }
                }
            }
            $this->info("{$set}: mirrored to z{$maxZoom}");
        }

        $this->info(sprintf('fetched %d tiles, %.1f MB%s', $got, $bytes / 1048576,
            $skipped > 0 ? " ({$skipped} gave up after retries)" : ''));

        return self::SUCCESS;
    }

    /**
     * Fetch one tile, distinguishing an empty tile (404 → null, normal) from throttling/transient
     * errors (429/5xx → back off and retry, honouring Retry-After). $skipped counts give-ups.
     */
    private function fetchTile(string $url, int &$skipped): ?string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $res = Http::withHeaders(['User-Agent' => self::UA])->timeout(30)->get($url);
            if ($res->ok()) {
                return $res->body();
            }
            if ($res->status() === 404) {
                return null; // empty ocean/no-data tile
            }
            // 429 (rate limit) or 5xx — wait and retry.
            $wait = max(1, (int) ($res->header('Retry-After') ?: $attempt * 2));
            sleep(min($wait, 15));
        }

        $skipped++;

        return null;
    }

    /**
     * Whole-world tile x/y range at zoom $z. The map caps zoom at the overview level, so the full
     * world stays bounded (z0-4 = 341 tiles/tileset); empty ocean tiles 404 and are skipped. This
     * gives every continent land + borders + markers, not just Europe.
     */
    private function tileRange(int $z): array
    {
        $n = 2 ** $z;

        return [0, 0, $n - 1, $n - 1];
    }
}
