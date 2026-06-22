<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikidataFiguresService;
use App\Services\WikidataPolityResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the curated topics catalog (A2). Polities are already in public.polities (keyed by
 * Wikidata QID in osm_id); this command:
 *   1. adds region columns + is_publishable to polities, creates public.figures, and the
 *      public.topics VIEW (polities UNION figures) — the single source the lesson picker reads.
 *   2. backfills each curated polity's region (Wikidata P625 / P17) where missing.
 *   3. fetches notable rulers (P35/P6, reign-dated) + people (P27, sitelink-ranked) per polity
 *      into public.figures — mirroring oldmapsonline.org TimeMap's Rulers/People layers.
 *
 * Networked + slow (~3000 polities). Run outside subagents. Supports --resume / --limit.
 */
class BuildTopics extends Command
{
    protected $signature = 'timemap:build-topics
        {--limit=0 : Cap the number of polities processed (0 = all)}
        {--resume : Skip polities that already have figures (continue an interrupted build)}
        {--people=8 : Max notable people fetched per polity}
        {--schema-only : Only (re)create the schema + topics view, then exit}';

    protected $description = 'Build the curated topics catalog: polity regions + ruler/person figures (A2)';

    public function handle(WikidataFiguresService $figures, WikidataPolityResolver $resolver): int
    {
        $corpus = DB::connection('pgsql_corpus');
        $this->ensureSchema($corpus);
        $this->info('schema + topics view ready');

        if ($this->option('schema-only')) {
            return self::SUCCESS;
        }

        $query = $corpus->table('public.polities')
            ->whereNotNull('wikipedia_url')
            ->whereNotNull('osm_id')
            // Figures are fetched from Wikidata, so only polities keyed by a real Wikidata QID
            // can yield any. The catalog also holds OSM-keyed duplicate rows (negative numeric
            // ids) for the same countries — those can never produce figures, so skip them.
            ->where('osm_id', 'like', 'Q%')
            ->orderByDesc('sitelinks');

        if ($this->option('resume')) {
            // Resume = skip polities that already have figures, so re-runs continue from where an
            // interrupted build stopped (still fame-ordered, most-famous-first). The previous
            // behaviour filtered on whereNull('region_lat'), which skipped every region-having
            // polity — i.e. all the famous ones — and so never populated their figures.
            $haveFigures = $corpus->table('public.figures')->distinct()->pluck('parent_qid');
            if ($haveFigures->isNotEmpty()) {
                $query->whereNotIn('osm_id', $haveFigures->all());
            }
            $done = $haveFigures->flip();
        } else {
            $done = collect();
        }

        $polities = $query->get(['osm_id', 'label', 'region_lat']);
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $polities = $polities->take($limit);
        }

        $peopleLimit = (int) $this->option('people');
        $bar = $this->output->createProgressBar($polities->count());
        $figureCount = 0;

        foreach ($polities as $p) {
            $qid = $p->osm_id;
            if ($this->option('resume') && $done->has($qid)) {
                $bar->advance();

                continue;
            }

            try {
                $data = $figures->polityFigures($qid);

                // 1. Backfill polity region (resolve country QID → label).
                $region = $data['region'];
                $label = null;
                if ($region['label']) {
                    $label = $resolver->labelsFor([$region['label']])[$region['label']] ?? null;
                }
                if ($region['lat'] !== null || $label !== null) {
                    $corpus->table('public.polities')->where('osm_id', $qid)->update(array_filter([
                        'region_lat' => $region['lat'],
                        'region_lng' => $region['lng'],
                        'region_label' => $label,
                    ], fn ($v) => $v !== null));
                }

                // 2. Collect rulers (from claims) + people (SPARQL).
                $rulers = $data['rulers'];

                // The people query is a heavy SPARQL scan that times out for high-population
                // polities (e.g. modern countries). Isolate it so a people-fetch failure never
                // costs us the rulers + region we already resolved — rulers are the priority
                // for the hero picker anyway.
                $people = [];
                try {
                    $people = $figures->peopleFor($qid, $peopleLimit);
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("polity {$qid}: people fetch failed ({$e->getMessage()}) — keeping rulers only");
                }

                // 3. Hydrate ruler details (names, wiki, sitelinks, birth/death).
                $rulerQids = array_column($rulers, 'qid');
                $hydrated = $figures->hydrate($rulerQids);
                foreach ($rulers as &$r) {
                    $h = $hydrated[$r['qid']] ?? null;
                    if ($h) {
                        $r['name'] = $h['name'] ?? $r['name'];
                        $r['wikipedia_url'] = $h['wikipedia_url'];
                        $r['sitelinks'] = $h['sitelinks'];
                        // fall back to birth/death if no reign qualifier dates
                        $r['era_start'] ??= $h['birth'];
                        $r['era_end'] ??= $h['death'];
                    }
                }
                unset($r);

                $figureCount += $this->upsertFigures($corpus, $qid, array_merge($rulers, $people));
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("polity {$qid} failed: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("upserted {$figureCount} figures across {$polities->count()} polities");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string,mixed>>  $figures
     */
    private function upsertFigures(Connection $corpus, string $parentQid, array $figures): int
    {
        $n = 0;
        foreach ($figures as $f) {
            if (! ($f['qid'] ?? null) || ! ($f['wikipedia_url'] ?? null)) {
                continue;  // skip figures with no Wikipedia article (catalog requires one)
            }
            $corpus->table('public.figures')->updateOrInsert(
                ['qid' => $f['qid'], 'parent_qid' => $parentQid],
                [
                    'name' => $f['name'],
                    'figure_kind' => $f['kind'],
                    'wikipedia_url' => $f['wikipedia_url'],
                    'summary' => $f['summary'] ?? null,
                    'era_start' => $f['era_start'],
                    'era_end' => $f['era_end'],
                    'region_lat' => $f['region_lat'] ?? null,
                    'region_lng' => $f['region_lng'] ?? null,
                    'region_label' => $f['region_label'] ?? null,
                    'sitelinks' => $f['sitelinks'] ?? 0,
                    'is_publishable' => true,
                    'updated_at' => now(),
                ]
            );
            $n++;
        }

        return $n;
    }

    private function ensureSchema(Connection $corpus): void
    {
        // Polity region + publishable columns (A3).
        foreach ([
            'region_lat numeric',
            'region_lng numeric',
            'region_label text',
            'is_publishable boolean default true',
        ] as $col) {
            $corpus->statement("ALTER TABLE public.polities ADD COLUMN IF NOT EXISTS {$col}");
        }

        // Figures table (A2). Composite PK: a ruler can hold office in several polities.
        $corpus->statement('
            CREATE TABLE IF NOT EXISTS public.figures (
                qid text NOT NULL,
                parent_qid text NOT NULL,
                name text,
                figure_kind text,
                wikipedia_url text,
                summary text,
                era_start integer,
                era_end integer,
                region_lat numeric,
                region_lng numeric,
                region_label text,
                sitelinks integer DEFAULT 0,
                is_publishable boolean DEFAULT true,
                updated_at timestamptz,
                PRIMARY KEY (qid, parent_qid)
            )');

        // topics view: single source for the picker (polities UNION figures).
        $corpus->statement('
            CREATE OR REPLACE VIEW public.topics AS
              SELECT
                \'polity:\' || osm_id          AS id,
                \'polity\'                      AS type,
                NULL::text                     AS figure_kind,
                label                          AS name,
                osm_id                         AS qid,
                NULL::text                     AS parent_qid,
                wikipedia_url,
                inception                      AS era_start,
                dissolution                    AS era_end,
                region_lat, region_lng, region_label,
                summary, sitelinks,
                \'Borders: Cliopatria (CC-BY 4.0) · Wikipedia (CC-BY-SA)\' AS source_attribution
              FROM public.polities
              WHERE wikipedia_url IS NOT NULL
                AND osm_id LIKE \'Q%\'         -- canonical Cliopatria QID rows only (drop stale numeric-id dupes)
                AND COALESCE(is_publishable, true)
            UNION ALL
              SELECT
                \'figure:\' || qid || \':\' || parent_qid AS id,
                \'figure\'                      AS type,
                figure_kind,
                name,
                qid,
                parent_qid,
                wikipedia_url,
                era_start, era_end,
                region_lat, region_lng, region_label,
                summary, sitelinks,
                \'Wikidata (CC0) · Wikipedia (CC-BY-SA)\' AS source_attribution
              FROM public.figures
              WHERE COALESCE(is_publishable, true)
        ');
    }
}
