# Time-Map Borders → Cliopatria (J-6) — Design Spec

**Date:** 2026-06-11
**Slice:** Replace the OpenHistoricalMap (OHM) border layer with the **Cliopatria** dataset
(Seshat Global History Databank) — the cleaned, curated political-boundary data that powers
reference maps like oldmapsonline/TimeMap.org and hanshack's "Point in History".
**Status:** approved direction (user picked "switch to Cliopatria" + "replace OHM entirely"),
spike GO → ready for implementation plan.

---

## 1. Why

OHM borders are time-accurate but sparse/uneven for many eras (1600 looks thin vs the reference;
naming inconsistent). Cliopatria is the cleaned derivative of Ourednik's historical-basemaps used
by the reference maps the user likes: clean non-overlapping polities, consistent English names,
per-polity validity ranges, and a Wikidata QID on ~99% of features.

## 2. Spike findings (GO)

- File: `cliopatria.geojson.zip` (44 MB) → `cliopatria_polities_only.geojson` (165 MB), 13,765
  features, ~1,600 polities, **CC-BY 4.0** (attribution required). Source:
  github.com/Seshat-Global-History-Databank/cliopatria (Zenodo DOI 10.5281/zenodo.14714684).
- Fields per feature: `Name`, `FromYear`, `ToYear` (int, negative = BCE), `Area`, `Type`
  (POLITY | RELATION), `Wikipedia`, `Wikidata` (QID), `SeshatID`, `Components`, `MemberOf`.
- **99% have a `Wikidata` QID** → enrichment via the existing `resolveByQid`; the Overpass sync is
  no longer needed.
- Tiled with **tippecanoe** (`-Z0 -z4`, world, `--no-tile-compression`, keep
  `Name,FromYear,ToYear,Wikidata,Type`) → layer `boundaries`, **43 MB uncompressed** for the whole
  world. Decode confirms 1600 = HRE, Dutch Republic, France, Spain, Ottoman, Ming, Joseon, etc.
- Tiles are **uncompressed** to match how we serve static `.pbf` (no gzip header), same as the OHM
  mirror.

## 3. Architecture

### 3.1 Tiles
- `php artisan timemap:build-cliopatria-tiles` (dev/CI tool): download the zip → unzip →
  tippecanoe → `public/cliopatria-tiles/{z}/{x}/{y}.pbf` (+ `metadata.json`). Requires `tippecanoe`.
- Tiles are **committed** (43 MB, stable dataset) so SiteGround (no tippecanoe) deploys work.
- Source-layer is `boundaries` (same name as OHM — minimal map churn).

### 3.2 Base map (land/water)
- Cliopatria is borders only. Keep the existing **OHM `osm_land`** coastline tiles
  (`public/ohm-tiles/osm_land`, CC0) as the neutral parchment land base. Delete the OHM
  `ohm_admin` border tiles. (Land geography ≠ the border data we're replacing.)

### 3.3 Map (`index.js`)
- Replace the `ohm` vector source with `cliopatria` (`promoteId: { boundaries: 'Wikidata' }`).
- **Continuous date filter:** `['all', ['<=', FromYear, year], ['>=', ToYear, year]]`.
- **Base filter (clean, non-overlapping):** `Type == 'POLITY'` and name not starting with `(`
  (the `(…)` rows are composite/alliance extents that overlap their members).
- **Fill colour:** hash the QID — `['%', ['to-number', ['slice', ['coalesce',['get','Wikidata'],'Q0'], 1]], N]`.
- **Labels:** `Name` (already English); existing one-centroid-per-polity dedup + flags stay,
  re-keyed to `Wikidata`.
- **Hover/selected/click:** feature-state + enrichment keyed by `Wikidata` QID.

### 3.4 Enrichment (`polities`, re-keyed by QID)
- `timemap:sync-cliopatria-polities` reads the unique `Wikidata` QIDs from the geojson →
  `resolveByQid` → `public.polities` keyed by **`wikidata_id`** + downloads flags to
  `/flags/{qid}.png` + writes `flags/manifest.json` (qids). Replaces `sync-ohm-polities` +
  the Overpass dependency.
- Click → QID → `GET /teacher/timemap/polity/{qid}` (lookup by `wikidata_id`, else lazy
  `resolveByQid`) → existing Summary / Article / Over Time panel.

### 3.5 Markers
- Keep the supplemental-marker mechanism for gaps. **Drop Eastern Han** (Cliopatria has the Han
  Dynasty). **Keep Gaul** (Cliopatria lacks it, like OHM). Markers still support QID or a curated
  `article_url` (worldhistory.org).

### 3.6 Retire OHM borders
- Delete `public/ohm-tiles/ohm_admin`, `timemap:fetch-ohm-tiles` (or slim it to land-only),
  `timemap:sync-ohm-polities`. Keep `osm_land`. Update attribution to
  "Borders © Cliopatria / Seshat (CC-BY 4.0) · Land © OpenStreetMap (CC0)".

## 4. Testing
- Decode-spike recorded (this doc). Playwright: map loads from local Cliopatria tiles, scrubbing
  to 1600 shows the named European states, click → panel with QID enrichment, flags render.
- `resolveByQid` already unit-tested; add a `sync-cliopatria-polities` fixture test.

## 5. Decisions locked
- Source **Cliopatria** (CC-BY), tiled locally with tippecanoe, **committed** (43 MB).
- **Replace OHM borders entirely**; keep OHM `osm_land` as the neutral land base.
- Continuous `FromYear`/`ToYear` filter; show `Type=POLITY` non-composite.
- Enrichment **keyed by Wikidata QID** (drop Overpass). Keep labels/flags/panel/markers.
