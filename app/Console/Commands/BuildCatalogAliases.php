<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Corpus\CorpusCatalogSchema;
use App\Services\Corpus\WikidataAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Teach the catalog the Dutch names for things.
 *
 * The catalog is named in English — "Roman Empire", "William the Silent" — while
 * teachers type "Romeinse Rijk" and "Willem van Oranje". Events already carried
 * Wikidata alt-labels; polities and figures did not, so a typed Dutch topic matched
 * nothing we hold and the lesson fell back to a fuzzy web search for its facts.
 *
 * Networked (Wikidata wbgetentities, 50 QIDs per call) and idempotent: re-running only
 * rewrites what it fetched. Safe to interrupt — rows already filled are skipped unless
 * --refresh is passed.
 */
class BuildCatalogAliases extends Command
{
    protected $signature = 'corpus:build-aliases
        {--type=* : Limit to polities and/or figures (default: both)}
        {--limit=0 : Stop after this many rows (0 = all)}
        {--refresh : Re-fetch rows that already have aliases}
        {--schema-only : Only add the columns and rebuild the view, then exit}';

    protected $description = 'Fetch Dutch and English alt-labels for catalog polities and figures so typed topics match';

    /** qid column and a human name, per table. */
    private const TABLES = [
        'polities' => ['qid' => 'osm_id', 'name' => 'label'],
        'figures' => ['qid' => 'qid', 'name' => 'name'],
    ];

    public function handle(WikidataAliases $aliases): int
    {
        $corpus = DB::connection('pgsql_corpus');

        CorpusCatalogSchema::ensureAliasColumns($corpus);
        $corpus->statement(CorpusCatalogSchema::topicsViewSql());
        $this->info('alias columns + topics view ready');

        if ($this->option('schema-only')) {
            return self::SUCCESS;
        }

        $tables = $this->option('type') ?: array_keys(self::TABLES);
        $limit = max(0, (int) $this->option('limit'));
        $filled = 0;

        foreach ($tables as $table) {
            if (! isset(self::TABLES[$table])) {
                $this->error("unknown table '{$table}' — expected polities or figures");

                return self::FAILURE;
            }

            $filled += $this->fill($corpus, $aliases, (string) $table, $limit);
        }

        $this->newLine();
        $this->info("filled aliases on {$filled} rows");

        return self::SUCCESS;
    }

    private function fill($corpus, WikidataAliases $aliases, string $table, int $limit): int
    {
        $qidColumn = self::TABLES[$table]['qid'];

        $query = $corpus->table("public.{$table}")
            ->select($qidColumn.' as qid')
            ->where($qidColumn, 'like', 'Q%')
            ->orderByDesc('sitelinks');

        if (! $this->option('refresh')) {
            $query->whereNull('aliases');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $qids = $query->pluck('qid')->all();
        if ($qids === []) {
            $this->line("  <fg=gray>{$table}: nothing to do</>");

            return 0;
        }

        $this->line("  {$table}: fetching alt-labels for ".count($qids).' entries…');
        $bar = $this->output->createProgressBar(count($qids));
        $filled = 0;

        // Chunked so an interrupted run still leaves the batches it finished — Wikidata
        // throttles, and the Supabase pooler drops long-idle connections.
        foreach (array_chunk($qids, 50) as $chunk) {
            foreach ($aliases->forQids($chunk) as $qid => $joined) {
                $corpus->table("public.{$table}")->where($qidColumn, $qid)->update(['aliases' => $joined]);
                $filled++;
            }
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();

        return $filled;
    }
}
