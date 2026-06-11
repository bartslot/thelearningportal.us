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
 * Downloads the source GeoJSON, then tiles it with tippecanoe into public/cliopatria-tiles.
 * The tiles are committed (the map depends on them and SiteGround has no tippecanoe), so this
 * is a dev/CI regeneration tool — run it to refresh when Cliopatria releases a new version.
 *
 * Requires `tippecanoe` on PATH (brew install tippecanoe).
 */
class BuildCliopatriaTiles extends Command
{
    protected $signature = 'timemap:build-cliopatria-tiles {--keep-source : Skip re-downloading if the geojson already exists}';

    protected $description = 'Download Cliopatria + tile it into public/cliopatria-tiles (needs tippecanoe)';

    private const ZIP_URL = 'https://raw.githubusercontent.com/Seshat-Global-History-Databank/cliopatria/master/cliopatria.geojson.zip';

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

        $out = public_path('cliopatria-tiles');
        File::deleteDirectory($out);

        $this->info('Tiling with tippecanoe (z0-4, world)…');
        $tippecanoe = new Process([
            'tippecanoe', '-e', $out,
            '-l', 'boundaries', '-Z0', '-z4',
            '--no-tile-compression',          // serve static .pbf uncompressed (no gzip header)
            '--drop-densest-as-needed', '--coalesce-densest-as-needed', '--no-tile-size-limit',
            '-y', 'Name', '-y', 'FromYear', '-y', 'ToYear', '-y', 'Wikidata', '-y', 'Type',
            '--force', $geojson,
        ]);
        $tippecanoe->setTimeout(600);
        $tippecanoe->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $tippecanoe->isSuccessful()) {
            $this->error('tippecanoe failed.');

            return self::FAILURE;
        }

        $this->info('Built '.public_path('cliopatria-tiles'));

        return self::SUCCESS;
    }
}
