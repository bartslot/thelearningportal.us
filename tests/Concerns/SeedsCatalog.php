<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Creates a minimal topics catalog (public.polities + public.figures + public.topics view) in the
 * test corpus connection and seeds one polity + one figure, so wizard/lesson tests can exercise the
 * A1 catalog-locked flow without touching the real corpus.
 */
trait SeedsCatalog
{
    protected function seedCatalog(): void
    {
        $corpus = DB::connection('pgsql_corpus');

        $corpus->statement('
            CREATE TABLE IF NOT EXISTS public.polities (
                polity_id text, osm_id text, label text, wikipedia_url text,
                inception integer, dissolution integer, sitelinks integer DEFAULT 0,
                region_lat numeric, region_lng numeric, region_label text,
                is_publishable boolean DEFAULT true
            )');
        // A pre-existing polities table (from other tests) may lack the newer columns.
        foreach ([
            'osm_id text', 'label text', 'wikipedia_url text', 'inception integer',
            'dissolution integer', 'sitelinks integer DEFAULT 0', 'region_lat numeric',
            'region_lng numeric', 'region_label text', 'is_publishable boolean DEFAULT true',
        ] as $col) {
            $corpus->statement("ALTER TABLE public.polities ADD COLUMN IF NOT EXISTS {$col}");
        }
        $corpus->statement('
            CREATE TABLE IF NOT EXISTS public.figures (
                qid text, parent_qid text, name text, figure_kind text, wikipedia_url text,
                summary text, era_start integer, era_end integer,
                region_lat numeric, region_lng numeric, region_label text,
                sitelinks integer DEFAULT 0, is_publishable boolean DEFAULT true
            )');
        $corpus->statement("
            CREATE OR REPLACE VIEW public.topics AS
              SELECT 'polity:'||osm_id AS id, 'polity' AS type, NULL::text AS figure_kind,
                     label AS name, osm_id AS qid, NULL::text AS parent_qid, wikipedia_url,
                     inception AS era_start, dissolution AS era_end,
                     region_lat, region_lng, region_label, NULL::text AS summary, sitelinks,
                     'Borders: Cliopatria (CC-BY 4.0)' AS source_attribution
              FROM public.polities
              WHERE wikipedia_url IS NOT NULL AND osm_id LIKE 'Q%' AND COALESCE(is_publishable, true)
            UNION ALL
              SELECT 'figure:'||qid||':'||parent_qid, 'figure', figure_kind, name, qid, parent_qid,
                     wikipedia_url, era_start, era_end, region_lat, region_lng, region_label,
                     summary, sitelinks, 'Wikidata (CC0)'
              FROM public.figures
              WHERE COALESCE(is_publishable, true)
        ");

        $corpus->table('public.polities')->where('osm_id', 'Q2277')->delete();
        $corpus->table('public.polities')->insert([
            'polity_id' => 'Q2277', 'osm_id' => 'Q2277', 'label' => 'Roman Empire',
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/Roman_Empire',
            'inception' => -27, 'dissolution' => 476, 'sitelinks' => 250,
            'region_label' => 'Italy', 'is_publishable' => true,
        ]);

        $corpus->table('public.figures')->where('qid', 'Q517')->delete();
        $corpus->table('public.figures')->insert([
            'qid' => 'Q517', 'parent_qid' => 'Q2277', 'name' => 'Napoleon', 'figure_kind' => 'person',
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/Napoleon',
            'era_start' => 1769, 'era_end' => 1821, 'sitelinks' => 300, 'is_publishable' => true,
        ]);
    }
}
