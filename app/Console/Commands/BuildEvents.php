<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Corpus\CorpusCatalogSchema;
use App\Services\WikidataEventsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds the historical-events branch of the curated topics catalog: wars, revolutions,
 * epidemics, treaties, disasters and the like, ranked by Wikipedia sitelinks. The catalog
 * was entity-only (people/polities/places), so common history topics ("Black Death",
 * "French Revolution") were unreachable in the lesson picker — this fills that gap.
 *
 * Networked (one Wikidata SPARQL scan). Idempotent: re-running upserts by QID.
 */
class BuildEvents extends Command
{
    protected $signature = 'timemap:build-events
        {--min-sitelinks=25 : Fame floor — only events with at least this many Wikipedia sitelinks}
        {--limit=4000 : Cap the number of events fetched}
        {--schema-only : Only (re)create the events table + topics view, then exit}';

    protected $description = 'Build the events branch of the topics catalog (wars, revolutions, epidemics…)';

    public function handle(WikidataEventsService $events): int
    {
        $corpus = DB::connection('pgsql_corpus');

        CorpusCatalogSchema::ensureEventsTable($corpus);
        $corpus->statement(CorpusCatalogSchema::topicsViewSql());
        $this->info('events table + topics view ready');

        if ($this->option('schema-only')) {
            return self::SUCCESS;
        }

        $floor = max(1, (int) $this->option('min-sitelinks'));
        $limit = max(1, (int) $this->option('limit'));

        $this->info("fetching events with ≥ {$floor} sitelinks (limit {$limit})…");
        $rows = $events->notableEvents($floor, $limit);

        if ($rows === []) {
            $this->warn('no events returned — Wikidata may be throttling; try again shortly');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar(count($rows));
        $n = 0;
        foreach ($rows as $e) {
            $corpus->table('public.catalog_events')->updateOrInsert(
                ['qid' => $e['qid']],
                [
                    'name' => $e['name'],
                    'summary' => $e['summary'],
                    'aliases' => $e['aliases'] ?? null,
                    'wikipedia_url' => $e['wikipedia_url'],
                    'era_start' => $e['era_start'],
                    'era_end' => $e['era_end'],
                    'sitelinks' => $e['sitelinks'],
                    'is_publishable' => true,
                    'updated_at' => now(),
                ]
            );
            $n++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("upserted {$n} events");

        return self::SUCCESS;
    }
}
