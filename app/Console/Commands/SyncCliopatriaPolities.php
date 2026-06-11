<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikidataPolityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Pre-enrich the Cliopatria polities into public.polities, keyed by Wikidata QID (stored in the
 * osm_id column, which the polity endpoint looks up). Reads the committed unique-polity list
 * (database/data/cliopatria-polities.json), resolves each QID via Wikidata, downloads its flag,
 * and writes flags/manifest.json. Replaces the OHM Overpass sync — Cliopatria carries QIDs natively.
 */
class SyncCliopatriaPolities extends Command
{
    protected $signature = 'timemap:sync-cliopatria-polities {--limit=0 : Cap the number enriched (0 = all)} {--resume : Skip QIDs already enriched (have a summary)}';

    protected $description = 'Enrich Cliopatria polities (by Wikidata QID) into polities + download flags';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(WikidataPolityResolver $resolver): int
    {
        $list = json_decode(File::get(database_path('data/cliopatria-polities.json')), true);
        if (! is_array($list)) {
            $this->error('database/data/cliopatria-polities.json missing or invalid.');

            return self::FAILURE;
        }
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $list = array_slice($list, 0, $limit);
        }

        $this->ensureTable();
        $flagsDir = public_path('flags');
        File::ensureDirectoryExists($flagsDir);
        $corpus = DB::connection('pgsql_corpus');

        $resume = (bool) $this->option('resume');
        $done = $resume
            ? $corpus->table('public.polities')->whereNotNull('summary')->pluck('osm_id')->flip()
            : collect();

        $bar = $this->output->createProgressBar(count($list));
        $count = 0;
        foreach ($list as $row) {
            $qid = $row['qid'] ?? null;
            $name = $row['name'] ?? null;
            if (! $qid || ($resume && $done->has($qid))) {
                $bar->advance();

                continue;
            }

            $data = $resolver->resolveByQid($qid);

            $flagPath = null;
            if (! empty($data['flag_commons'])) {
                $flagPath = "/flags/{$qid}.png";
                $bytes = Http::withHeaders(['User-Agent' => self::UA])
                    ->get('https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($data['flag_commons']).'?width=120')->body();
                File::put($flagsDir.'/'.$qid.'.png', $bytes);
            }

            $corpus->table('public.polities')->updateOrInsert(
                ['osm_id' => $qid],
                [
                    'polity_id' => $qid,
                    'label' => $name ?? $data['label'],   // clean Cliopatria era-name
                    'wikidata_id' => $data['wikidata_id'] ?? $qid,
                    'flag_path' => $flagPath,
                    'summary' => $data['summary'],
                    'wikipedia_url' => $data['wikipedia_url'],
                    'inception' => $data['inception'],
                    'dissolution' => $data['dissolution'],
                    'predecessor' => $data['predecessor'],
                    'successor' => $data['successor'],
                    'sitelinks' => $data['sitelinks'],
                    'significant' => true,
                    'updated_at' => now(),
                ]
            );
            $count++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->writeFlagManifest($flagsDir);
        $this->info("enriched {$count} Cliopatria polities");

        return self::SUCCESS;
    }

    /** flags/manifest.json — the QIDs that have a downloaded flag, so the map draws icons without 404-probing. */
    private function writeFlagManifest(string $flagsDir): void
    {
        $ids = collect(File::glob($flagsDir.'/*.png'))
            ->map(fn (string $p) => pathinfo($p, PATHINFO_FILENAME))
            ->values();
        File::put($flagsDir.'/manifest.json', $ids->toJson());
    }

    private function ensureTable(): void
    {
        DB::connection('pgsql_corpus')->statement('
            CREATE TABLE IF NOT EXISTS public.polities (
                polity_id text primary key, osm_id text unique, label text, wikidata_id text, flag_path text,
                summary text, wikipedia_url text, inception integer, dissolution integer,
                predecessor text, successor text, sitelinks integer default 0,
                significant boolean default true, updated_at timestamptz
            )');
        DB::connection('pgsql_corpus')->statement('ALTER TABLE public.polities ADD COLUMN IF NOT EXISTS osm_id text');
    }
}
