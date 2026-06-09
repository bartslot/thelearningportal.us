# Time-Map data sources

## Historical borders
- **Source:** historical-basemaps (https://github.com/aourednik/historical-basemaps)
- **Licence:** CC-BY-SA 4.0
- **Attribution shown:** "Borders © historical-basemaps (CC-BY-SA)" (MapLibre attribution control)
- **Vendored:** `database/data/historical-basemaps/world_*.geojson`; imported via
  `php artisan timemap:import-boundaries`. Re-run any time — it is idempotent (clears and
  re-inserts only rows tagged `source = 'historical-basemaps'`).
- **Seeded snapshot years:** −2000, −1500, −500, 200, 500, 1000, 1880 (ancient spine; `world_1`
  was unavailable upstream, so `world_200` is used for the turn-of-era stop).

## Corpus (read-only)
- `history_articles`, `history_facts`, `gazetteer_places` live in the Supabase ophof project
  (`public` schema), read via the `pgsql_corpus` connection. The app never writes them; the
  **only** sanctioned write is `boundaries` via the importer.
- App tables (users, lessons, …) live in a separate `app` schema (`pgsql` connection,
  `DB_SEARCH_PATH=app`) so Laravel migrations can never touch the corpus.

## Region tagging
- `timemap:import-boundaries` imports **every** polygon in each snapshot (~77–92 per era,
  ~658 total) and tags each with one of the 7 corpus macro-regions (Mediterranean, East Asia,
  Americas, South Asia, Northern Europe, Middle East, Africa) by bucketing its **centroid**
  (lng/lat) — see `ImportBoundaries::regionTaggingSql()`. This replaced an earlier brittle
  name→region lookup that matched only ~9 of the hundreds of historical polygon names.
- The centroid buckets are deliberately coarse; refine them in `regionTaggingSql()` if a
  region is mis-bucketed.
