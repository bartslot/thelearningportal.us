<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikidataPolityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class SyncOhmPolities extends Command
{
    protected $signature = 'timemap:sync-ohm-polities';

    protected $description = 'Pre-enrich major OHM admin boundaries (admin_level<=4) into polities, keyed by osm_id';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(WikidataPolityResolver $resolver): int
    {
        $this->ensureTable();
        $flagsDir = public_path('flags');
        File::ensureDirectoryExists($flagsDir);
        $corpus = DB::connection('pgsql_corpus');

        // Europe admin boundaries, major levels only.
        $query = '[out:json][timeout:120];relation["boundary"="administrative"]["admin_level"~"^[1-4]$"]'
            .'(30.0,-25.0,72.0,50.0);out tags;';
        $elements = Http::withHeaders(['User-Agent' => self::UA])
            ->asForm()->post('https://overpass-api.openhistoricalmap.org/api/interpreter', ['data' => $query])
            ->json('elements', []);

        $count = 0;
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;
            if (! $name) {
                continue;
            }
            // OHM relations are stored as NEGATIVE osm_ids in the vector tiles; match that format.
            $osmId = ($el['type'] === 'relation' ? '-' : '').$el['id'];
            $qid = $tags['wikidata'] ?? null;

            $data = $qid ? $resolver->resolveByQid($qid) : $resolver->resolve($name);

            $flagPath = null;
            if (! empty($data['flag_commons'])) {
                $flagPath = "/flags/{$osmId}.png";
                $bytes = Http::withHeaders(['User-Agent' => self::UA])
                    ->get('https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($data['flag_commons']).'?width=80')->body();
                File::put($flagsDir.'/'.$osmId.'.png', $bytes);
            }

            $corpus->table('public.polities')->updateOrInsert(
                ['osm_id' => $osmId],
                [
                    'polity_id' => $osmId,
                    'label' => $data['label'] ?? $name,
                    'wikidata_id' => $data['wikidata_id'],
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
        }

        $this->info("synced {$count} OHM polities");

        return self::SUCCESS;
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
