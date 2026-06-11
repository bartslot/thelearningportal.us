# Time-Map data sources

## Historical borders

- **Source:** **Cliopatria** (Seshat Global History Databank) — curated world political boundaries
  3400 BCE → 2024 CE, ~1,600 polities. The cleaned dataset behind reference maps like
  oldmapsonline/TimeMap.org. github.com/Seshat-Global-History-Databank/cliopatria.
- **Licence:** **CC-BY 4.0** — attribution required.
- **Attribution shown:** "Borders © Cliopatria / Seshat (CC-BY 4.0) · Land © OpenStreetMap (CC0)".
- **Lifespans:** continuous per-feature `FromYear` / `ToYear` (integers, negative = BCE) → the map
  filters `FromYear ≤ year ≤ ToYear`. The map shows `Type=POLITY` features and skips composite/
  alliance extents (names in parentheses).
- **Tiles (committed):** `php artisan timemap:build-cliopatria-tiles` downloads the source and runs
  **tippecanoe** (z0–4, world, `--no-tile-compression`, keeping `Name,FromYear,ToYear,Wikidata,Type`)
  into `public/cliopatria-tiles/` (~43 MB, layer `boundaries`). The tiles **are committed** (the map
  needs them and SiteGround has no tippecanoe), so this command is a dev/CI regeneration tool.
  Requires `brew install tippecanoe`. The 165 MB source download is gitignored
  (`storage/app/cliopatria/`).
- **Land base:** OpenStreetMap coastlines (CC0), committed at `public/land-tiles/` (~5 MB).
- **Enrichment:** every Cliopatria feature carries a `Wikidata` QID, so
  `php artisan timemap:sync-cliopatria-polities` reads the committed unique-polity list
  (`database/data/cliopatria-polities.json`, 1,400 rows), resolves each QID via
  `WikidataPolityResolver::resolveByQid` into `public.polities` (keyed by QID in the `osm_id`
  column), downloads flags to `public/flags/{qid}.png`, and writes `public/flags/manifest.json`.
  `--resume` skips already-enriched QIDs. Features not yet enriched are lazy-enriched on click.
  (No Overpass dependency — replaced the OHM sync.)
- **Regeneration (one-off / on data refresh):**
  ```
  php artisan timemap:build-cliopatria-tiles && php artisan timemap:sync-cliopatria-polities
  ```

## Corpus (read-only)
- `history_articles`, `history_facts`, `gazetteer_places` live in the Supabase ophof project
  (`public` schema), read via the `pgsql_corpus` connection. The app never writes them.
- `public.polities` is written only by `timemap:sync-cliopatria-polities` (keyed by Wikidata QID,
  stored in the `osm_id` column).
- App tables (users, lessons, …) live in a separate `app` schema (`pgsql` connection,
  `DB_SEARCH_PATH=app`) so Laravel migrations can never touch the corpus.

## Retired
- The OpenHistoricalMap pipeline (`timemap:fetch-ohm-tiles`, `timemap:sync-ohm-polities`,
  `public/ohm-tiles/`) and the earlier `historical-basemaps` snapshot pipeline
  (`timemap:import-boundaries`/`export-boundaries`, `public/geo/`) have been removed in favour of
  Cliopatria. Their design/plan docs remain under `docs/superpowers/` for history.
