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
    /** A part below BOTH of these is raster noise, not territory. See withoutSpecks(). */
    private const SPECK_SHARE = 0.005;

    private const SPECK_AREA = 0.1;

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

        $existingClip = "{$work}/cliopatria_clipped.geojson";
        if ($this->option('keep-source') && File::exists($existingClip)) {
            $this->info('Reusing the existing coastline clip (--keep-source).');
            $clipped = $existingClip;
        } else {
            $clipped = $this->clipToCoastline($work, $geojson);
            if ($clipped === null) {
                return self::FAILURE;
            }
        }

        $clipped = $this->dropSpecks($work, $clipped);

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

    /**
     * Cliopatria carries raster-to-vector noise: alongside its real body, a polity-year is dotted
     * with 5-point specks of a few thousand square kilometres, scattered anywhere on the map. Nazi
     * Germany in 1939 has its own two real pieces (Germany, East Prussia) and 185 specks, as far
     * afield as Karelia, Greece, Lebanon and the open Atlantic. 76% of multipart polity-years in the
     * source have them.
     *
     * They are invisible in the source at world scale and unmistakable on the map: the tiles stop at
     * z4, so anything past that is over-zoomed, and a speck blows up into a hard-edged block of
     * colour sitting in the middle of another country.
     *
     * @see self::withoutSpecks() for the rule and why it is written as an AND.
     */
    private function dropSpecks(string $work, string $in): string
    {
        $out = "{$work}/cliopatria_despeckled.geojson";
        $this->info('Dropping raster specks…');

        $reader = fopen($in, 'r');
        $writer = fopen($out, 'w');
        $dropped = 0;
        $touched = 0;

        while (($line = fgets($reader)) !== false) {
            // Match on the brace, NOT on a spelling. The Cliopatria download writes
            // `{ "type": "Feature"` and mapshaper writes `{"type":"Feature"`, and a filter keyed to
            // one of them silently processes nothing when handed the other — which is exactly what
            // happened the first time this ran: "removed 0 specks", and a clean exit.
            $start = strpos($line, '{');
            if ($start === false) {
                fwrite($writer, $line);
                continue;
            }
            $tail = rtrim(rtrim(trim(substr($line, $start)), ','));
            $feature = json_decode($tail, true);
            if (! is_array($feature) || ($feature['type'] ?? '') !== 'Feature' || ($feature['geometry']['type'] ?? '') !== 'MultiPolygon') {
                fwrite($writer, $line);
                continue;
            }

            $before = count($feature['geometry']['coordinates']);
            $feature['geometry']['coordinates'] = self::withoutSpecks($feature['geometry']['coordinates']);
            $after = count($feature['geometry']['coordinates']);

            if ($after < $before) {
                $touched++;
                $dropped += $before - $after;
            }

            $suffix = str_ends_with(rtrim(trim(substr($line, $start))), ',') ? ",\n" : "\n";
            fwrite($writer, substr($line, 0, $start).json_encode($feature, JSON_UNESCAPED_SLASHES).$suffix);
        }

        fclose($reader);
        fclose($writer);
        $this->info("  removed {$dropped} specks across {$touched} polity-years");

        return $out;
    }

    /**
     * Drop a part only when it is BOTH a negligible share of the polity AND tiny in absolute terms.
     *
     * The AND is the whole point, and it is what makes this safe to run over every polity in the
     * set. A microstate — Monaco, San Marino, the Vatican — is far below the absolute threshold, but
     * it is its own largest part, so the relative test never fires and it always survives. An
     * absolute rule on its own would quietly delete every microstate on the map.
     *
     * Numbers, in square degrees of shoelace area: the specks measure 0.02–0.07, East Prussia (a
     * real exclave worth keeping) measures 5.86, and Germany proper 81.4. 0.5% of the largest part
     * puts the cut at 0.4 for Germany, well clear of both.
     *
     * @param  array  $polygons  a MultiPolygon's coordinates
     * @return array the same, minus the specks
     */
    public static function withoutSpecks(array $polygons): array
    {
        if (count($polygons) < 2) {
            return $polygons;
        }

        $areas = array_map(fn (array $polygon) => self::ringArea($polygon[0] ?? []), $polygons);
        $largest = max($areas);
        if ($largest <= 0.0) {
            return $polygons;
        }

        $kept = [];
        foreach ($polygons as $i => $polygon) {
            $isSpeck = $areas[$i] < $largest * self::SPECK_SHARE && $areas[$i] < self::SPECK_AREA;
            if (! $isSpeck) {
                $kept[] = $polygon;
            }
        }

        return $kept === [] ? $polygons : array_values($kept);
    }

    /** Shoelace area in square degrees — a ranking measure, not a real-world one. */
    private static function ringArea(array $ring): float
    {
        $n = count($ring);
        if ($n < 3) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = $ring[$i];
            $b = $ring[($i - 1 + $n) % $n];
            $sum += ($a[0] * $b[1]) - ($b[0] * $a[1]);
        }

        return abs($sum) / 2.0;
    }
}
