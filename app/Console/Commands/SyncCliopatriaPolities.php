<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CliopatriaSpans;
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
    protected $signature = 'timemap:sync-cliopatria-polities {--limit=0 : Cap the number enriched (0 = all)} {--resume : Skip QIDs already enriched (have a summary)} {--dates-only : Realign every polity era to the Cliopatria span + rewrite the snapshot, no Wikidata calls}';

    protected $description = 'Enrich Cliopatria polities (by Wikidata QID) into polities + download flags';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(WikidataPolityResolver $resolver, CliopatriaSpans $spans): int
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
        $corpus = DB::connection('pgsql_corpus');

        // Fast path: only realign eras to the Cliopatria span (no Wikidata/flag traffic). Use this to
        // apply a span correction to an already-enriched corpus without a full re-sync.
        if ($this->option('dates-only')) {
            return $this->refreshDates($corpus, $spans);
        }

        $flagsDir = public_path('flags');
        File::ensureDirectoryExists($flagsDir);

        $resume = (bool) $this->option('resume');
        $done = $resume
            ? $corpus->table('public.polities')->whereNotNull('summary')->whereNotNull('osm_id')->pluck('osm_id')->flip()
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
                    // Era = the Cliopatria span the tiles render by, so the panel matches the map.
                    // Wikidata P571/P576 is only a fallback for polities the list does not span.
                    'inception' => $row['from'] ?? $data['inception'],
                    'dissolution' => $row['to'] ?? $data['dissolution'],
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
        $this->writeArticlesSnapshot($corpus, $spans);
        $this->info("enriched {$count} Cliopatria polities");

        return self::SUCCESS;
    }

    /**
     * Realign every stored polity era to its Cliopatria span and rewrite the article snapshot,
     * without re-querying Wikidata (summaries/flags are left untouched). Idempotent.
     */
    private function refreshDates(\Illuminate\Database\Connection $corpus, CliopatriaSpans $spans): int
    {
        // One UPDATE per chunk (a VALUES join), not ~1400 single-row round-trips: the Supabase pooler
        // drops a long single-row loop mid-run ("server closed the connection unexpectedly").
        $updated = 0;
        foreach (array_chunk($spans->all(), 200, true) as $chunk) {
            $rows = [];
            $bindings = [];
            foreach ($chunk as $qid => $span) {
                $rows[] = '(?::text, ?::integer, ?::integer)';
                $bindings[] = $qid;
                $bindings[] = $span['from'];
                $bindings[] = $span['to'];
            }
            $values = implode(',', $rows);
            $updated += $corpus->update(
                "UPDATE public.polities AS p
                 SET inception = v.f, dissolution = v.t, updated_at = now()
                 FROM (VALUES {$values}) AS v(qid, f, t)
                 WHERE p.osm_id = v.qid",
                $bindings
            );
        }

        $this->writeArticlesSnapshot($corpus, $spans);
        $this->info("realigned {$updated} polity eras to the Cliopatria span");

        return self::SUCCESS;
    }

    /** Static article cache (public/timemap/articles.json) keyed by QID — the map prefetches this
     *  instead of querying the slow corpus pooler at page load. */
    private function writeArticlesSnapshot(\Illuminate\Database\Connection $corpus, CliopatriaSpans $spans): void
    {
        $out = [];
        foreach ($corpus->table('public.polities')->where('osm_id', 'like', 'Q%')->whereNotNull('summary')->get() as $p) {
            // The Cliopatria span is authoritative for the era (it is what the tiles render by), so
            // overlay it onto the stored columns — keeps the snapshot correct even if a row still
            // holds a stale Wikidata-derived date. Falls back to the column for non-list polities.
            $span = $spans->for($p->osm_id);
            $inception = $span['from'] ?? ($p->inception !== null ? (int) $p->inception : null);
            $dissolution = $span['to'] ?? ($p->dissolution !== null ? (int) $p->dissolution : null);
            $out[$p->osm_id] = [
                'osm_id' => $p->osm_id, 'label' => $p->label, 'summary' => $p->summary,
                'flag_path' => $p->flag_path, 'wikipedia_url' => $p->wikipedia_url,
                'inception' => $inception, 'dissolution' => $dissolution,
                'predecessor' => $p->predecessor, 'successor' => $p->successor,
            ];
        }
        File::ensureDirectoryExists(public_path('timemap'));
        File::put(public_path('timemap/articles.json'), json_encode($out));
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
