<?php

declare(strict_types=1);

namespace App\Services\Corpus;

use Illuminate\Database\Connection;

/**
 * Single source of truth for the curated `public.topics` catalog view and its backing
 * `catalog_events` table. Both `timemap:build-topics` and `timemap:build-events` recreate
 * the view on every run — keeping the definition here means neither command can silently
 * drop a branch the other added.
 *
 * The view UNIONs four grounded sources the lesson picker reads:
 *   polity  → historical states/empires (Cliopatria, keyed by Wikidata QID)
 *   figure  → notable rulers & people per polity
 *   place   → cities and sites
 *   event   → wars, revolutions, epidemics, treaties… (this is what teachers were missing)
 */
final class CorpusCatalogSchema
{
    /** Wars, revolutions, epidemics, etc. — populated by `timemap:build-events`. */
    public static function ensureEventsTable(Connection $corpus): void
    {
        $corpus->statement('
            CREATE TABLE IF NOT EXISTS public.catalog_events (
                qid text PRIMARY KEY,
                name text NOT NULL,
                summary text,
                aliases text,
                wikipedia_url text,
                era_start integer,
                era_end integer,
                sitelinks integer DEFAULT 0,
                is_publishable boolean DEFAULT true,
                created_at timestamptz DEFAULT now(),
                updated_at timestamptz
            )');

        // Synonyms ("Black Plague" → Black Death, "Spanish flu", "The Great War"): pipe-joined
        // Wikidata alt-labels, searched alongside the name. Added after the table shipped.
        $corpus->statement('ALTER TABLE public.catalog_events ADD COLUMN IF NOT EXISTS aliases text');
    }

    /**
     * Alt-labels on the entity branches, so a Dutch topic finds an English-named entry
     * ("Romeinse Rijk" → Roman Empire, "Willem van Oranje" → William the Silent). Events
     * have carried these since they shipped; polities and figures got them later, which is
     * why this is a separate ALTER rather than part of a CREATE.
     */
    public static function ensureAliasColumns(Connection $corpus): void
    {
        foreach (['polities', 'figures'] as $table) {
            $corpus->statement("ALTER TABLE public.{$table} ADD COLUMN IF NOT EXISTS aliases text");
        }
    }

    /**
     * The full topics view: polities UNION figures UNION places UNION events. Every branch
     * exposes the same column list so the picker (App\Models\Corpus\Topic) reads one shape.
     */
    public static function topicsViewSql(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE VIEW public.topics AS
              SELECT
                'polity:' || osm_id            AS id,
                'polity'                       AS type,
                NULL::text                     AS figure_kind,
                label                          AS name,
                osm_id                         AS qid,
                NULL::text                     AS parent_qid,
                wikipedia_url,
                inception                      AS era_start,
                dissolution                    AS era_end,
                region_lat, region_lng, region_label,
                summary, sitelinks,
                'Borders: Cliopatria (CC-BY 4.0) · Wikipedia (CC-BY-SA)' AS source_attribution,
                aliases
              FROM public.polities
              WHERE wikipedia_url IS NOT NULL
                AND osm_id LIKE 'Q%'
                AND COALESCE(is_publishable, true)
            UNION ALL
              SELECT
                'figure:' || qid || ':' || parent_qid AS id,
                'figure'                       AS type,
                figure_kind,
                name,
                qid,
                parent_qid,
                wikipedia_url,
                era_start, era_end,
                region_lat, region_lng, region_label,
                summary, sitelinks,
                'Wikidata (CC0) · Wikipedia (CC-BY-SA)' AS source_attribution,
                aliases
              FROM public.figures
              WHERE COALESCE(is_publishable, true)
            UNION ALL
              SELECT
                'place:' || qid                AS id,
                'place'                        AS type,
                NULL::text                     AS figure_kind,
                name,
                qid,
                NULL::text                     AS parent_qid,
                wikipedia_url,
                NULL::integer                  AS era_start,
                NULL::integer                  AS era_end,
                lat                            AS region_lat,
                lng                            AS region_lng,
                summary                        AS region_label,
                summary, sitelinks,
                'Wikidata (CC0) · Wikipedia (CC-BY-SA)' AS source_attribution,
                NULL::text                     AS aliases
              FROM public.catalog_places
              WHERE COALESCE(is_publishable, true)
                AND wikipedia_url IS NOT NULL
            UNION ALL
              SELECT
                'event:' || qid                AS id,
                'event'                        AS type,
                NULL::text                     AS figure_kind,
                name,
                qid,
                NULL::text                     AS parent_qid,
                wikipedia_url,
                era_start, era_end,
                NULL::numeric                  AS region_lat,
                NULL::numeric                  AS region_lng,
                NULL::text                     AS region_label,
                summary, sitelinks,
                'Wikidata (CC0) · Wikipedia (CC-BY-SA)' AS source_attribution,
                aliases
              FROM public.catalog_events
              WHERE COALESCE(is_publishable, true)
                AND wikipedia_url IS NOT NULL
            SQL;
    }
}
