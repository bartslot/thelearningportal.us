<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Build the Time-Map border tiles from the Cliopatria dataset (Seshat, CC-BY 4.0).
 * Downloads the source GeoJSON, clips it to the coastline, then tiles it with tippecanoe into
 * public/cliopatria-tiles. The tiles are committed (the map depends on them and SiteGround has no
 * tippecanoe), so this is a dev/CI regeneration tool — run it to refresh when Cliopatria releases
 * a new version.
 *
 * WHY THE CLIP. Cliopatria's polygons are generalised political extents, not coastlines: Corsica's
 * ruler is a shape that contains Corsica rather than the island itself. Drawn as a fill, every one
 * of them bleeds into the sea — a pale wash around every coast, which reads as a rendering fault
 * and is really the data saying "somewhere around here".
 *
 * It is fixed HERE rather than in the renderer because the renderer cannot fix it. The fills are
 * ordinary MapLibre vector fills, so there is no shader to mask them in; drawing them under the
 * ocean layer does not work either, because that layer deliberately fades out below ~180 km to hand
 * over to real imagery — exactly the zoom where the bleed is most obvious. Clipping the geometry
 * once fixes every style, every zoom and every device, and costs nothing at runtime.
 *
 * The clip is against MODERN coastlines (Natural Earth 50m), which is a small anachronism: the
 * Zuiderzee was open water in 1600 and the Dutch polders were not there. Being a few kilometres
 * generous inland beats bleeding tens of kilometres out to sea, and no historical coastline dataset
 * covers the whole Cliopatria range.
 *
 * Requires `tippecanoe` on PATH (brew install tippecanoe). mapshaper comes from npm.
 */
class BuildCliopatriaTiles extends Command
{
    protected $signature = 'timemap:build-cliopatria-tiles {--keep-source : Skip re-downloading if the geojson already exists}';

    protected $description = 'Download Cliopatria, clip it to the coastline + tile it into public/cliopatria-tiles (needs tippecanoe)';

    private const ZIP_URL = 'https://raw.githubusercontent.com/Seshat-Global-History-Databank/cliopatria/master/cliopatria.geojson.zip';

    /** Land polygons to clip against — the same Natural Earth 50m the coastline and graticule use. */
    private const LAND_URL = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_land.geojson';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(): int
    {
        if ((new Process(['which', 'tippecanoe']))->run() !== 0) {
            $this->error('tippecanoe not found on PATH. Install it: brew install tippecanoe');

            return self::FAILURE;
        }

        $work = storage_path('app/cliopatria');
        File::ensureDirectoryExists($work);
        $geojson = "{$work}/cliopatria_polities_only.geojson";

        if (! ($this->option('keep-source') && File::exists($geojson))) {
            $this->info('Downloading Cliopatria source…');
            $zip = "{$work}/cliopatria.geojson.zip";
            File::put($zip, Http::withHeaders(['User-Agent' => self::UA])->timeout(300)->get(self::ZIP_URL)->body());

            $archive = new ZipArchive;
            if ($archive->open($zip) !== true) {
                $this->error('Could not open the downloaded zip.');

                return self::FAILURE;
            }
            $archive->extractTo($work);
            $archive->close();
        }

        if (! File::exists($geojson)) {
            $this->error("Expected {$geojson} after unzip — Cliopatria layout may have changed.");

            return self::FAILURE;
        }

        $clipped = $this->clipToCoastline($work, $geojson);
        if ($clipped === null) {
            return self::FAILURE;
        }

        $out = public_path('cliopatria-tiles');
        File::deleteDirectory($out);

        $this->info('Tiling with tippecanoe (z0-4, world)…');
        $tippecanoe = new Process([
            'tippecanoe', '-e', $out,
            '-l', 'boundaries', '-Z0', '-z4',
            '--no-tile-compression',          // serve static .pbf uncompressed (no gzip header)
            '--drop-densest-as-needed', '--coalesce-densest-as-needed', '--no-tile-size-limit',
            '-y', 'Name', '-y', 'FromYear', '-y', 'ToYear', '-y', 'Wikidata', '-y', 'Type',
            '--force', $clipped,
        ]);
        $tippecanoe->setTimeout(1800);
        $tippecanoe->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $tippecanoe->isSuccessful()) {
            $this->error('tippecanoe failed.');

            return self::FAILURE;
        }

        $this->info('Built '.public_path('cliopatria-tiles'));

        return self::SUCCESS;
    }

    /**
     * Cut every polity back to the shoreline, returning the path to the clipped GeoJSON.
     *
     * mapshaper rather than turf: this is 13,765 polygons against ~1,400 land shapes, and mapshaper
     * does it topologically in one pass where a per-feature intersect loop is O(n·m) and falls over
     * on the self-touching rings Cliopatria contains. It needs the bigger heap — the source is
     * ~160 MB and the result is ~470 MB, because clipping writes the coastline into every polygon
     * that meets it.
     *
     * Measured on the 2026-08-11 dataset: 9,446 of 13,765 polities lost area, 1.7% of the total.
     * A small number, and the right shape for it — the bleed is a thin rim around a coast, which is
     * a rounding error by area and the first thing you see on screen.
     */
    private function clipToCoastline(string $work, string $geojson): ?string
    {
        $land = storage_path('app/naturalearth/ne_50m_land.geojson');
        File::ensureDirectoryExists(dirname($land));
        if (! File::exists($land)) {
            $this->info('Downloading Natural Earth 50m land…');
            File::put($land, Http::withHeaders(['User-Agent' => self::UA])->timeout(300)->get(self::LAND_URL)->body());
        }

        $mapshaper = base_path('node_modules/mapshaper/bin/mapshaper');
        if (! File::exists($mapshaper)) {
            $this->error('mapshaper not found. Install it: npm install');

            return null;
        }

        $clipped = "{$work}/cliopatria_clipped.geojson";
        $this->info('Clipping polities to the coastline…');
        $clip = new Process([
            'node', '--max-old-space-size=8192', $mapshaper,
            $geojson, '-clip', $land, '-o', $clipped,
        ], base_path());
        $clip->setTimeout(1800);
        $clip->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $clip->isSuccessful() || ! File::exists($clipped)) {
            $this->error('mapshaper clip failed.');

            return null;
        }

        return $clipped;
    }
}
