# Time-Map data sources

## Historical borders

- **Source:** OpenHistoricalMap (`ohm_admin` tileset, source-layer `boundaries`).
- **Licence:** CC0 (OHM data is public-domain).
- **Attribution shown:** "Borders © OpenHistoricalMap contributors (CC0)" (MapLibre attribution control).
- **Lifespans:** Continuous per-feature lifespans via `start_decdate` / `end_decdate` OHM tags —
  no more discrete era snapshots.
- **Local mirror:** `php artisan timemap:fetch-ohm-tiles --maxzoom=4` downloads the `ohm_admin`
  and `osm_land` tilesets into `public/ohm-tiles/{tileset}/{z}/{x}/{y}.pbf`. The directory is
  gitignored (~66 MB), regenerated on deploy, and never committed. The map serves these files
  statically; there is no live dependency on the OHM tile server at runtime.
- **Enrichment:** `php artisan timemap:sync-ohm-polities` queries the OHM Overpass API for
  European admin boundaries (`admin_level` 1–4), reads each feature's `wikidata` QID, and
  enriches via `WikidataPolityResolver::resolveByQid` into `public.polities`, keyed by
  **`osm_id`** (the OHM relation's negative integer id as text). Features not yet enriched are
  lazy-enriched on click.
- **Regeneration (one-off / on data refresh):**
  ```
  php artisan timemap:fetch-ohm-tiles && php artisan timemap:sync-ohm-polities
  ```

## Corpus (read-only)
- `history_articles`, `history_facts`, `gazetteer_places` live in the Supabase ophof project
  (`public` schema), read via the `pgsql_corpus` connection. The app never writes them.
- `public.polities` is written only by `timemap:sync-ohm-polities` (keyed by `osm_id`).
- App tables (users, lessons, …) live in a separate `app` schema (`pgsql` connection,
  `DB_SEARCH_PATH=app`) so Laravel migrations can never touch the corpus.

## Region tagging
- `timemap:sync-ohm-polities` tags each enriched polity with one of the 7 corpus macro-regions
  (Mediterranean, East Asia, Americas, South Asia, Northern Europe, Middle East, Africa) by
  bucketing its **centroid** (lng/lat) via `WikidataPolityResolver`. This replaced the earlier
  brittle name→region lookup and the discrete-era centroid bucketing used by the retired
  `timemap:import-boundaries` command.
- The centroid buckets are deliberately coarse; refine the bucketing logic in
  `WikidataPolityResolver` if a region is mis-tagged.
