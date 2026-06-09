# Time-Map Borders → OpenHistoricalMap (J-5) — Design Spec

**Date:** 2026-06-09
**Slice:** Replace the Time-Map's border data (static `historical-basemaps` snapshots) with **OpenHistoricalMap (OHM)** continuous, time-accurate vector tiles — mirrored/cached locally.
**Status:** approved design → ready for implementation plan

---

## 1. Why

The current borders are low quality and structurally wrong for a history atlas:
- **7 discrete snapshots** → era mismatch (Achaemenid Empire shows at 235 BCE because we load the 500 BCE snapshot).
- **Overlapping polygons** → Egypt buried under Achaemenid, not clickable.
- **Date labels show the snapshot window** ("500 BCE – 200 CE"), not real lifespans.
- **`historical-basemaps` data is rough** — Gaul missing, Carthage extent wrong.

OHM (CC0) provides **continuous, curated, time-accurate** admin borders 6000 BCE → now, each feature carrying its own `start_date`/`end_date`. This fixes all four at the source.

**Product framing:** the Time-Map is a low-detail **navigation tool** for teachers to start a lesson — overview-level, not detailed cartography. So we need only **low zoom levels**.

## 2. Scope

**In:** OHM admin boundaries as the border source, mirrored to local low-zoom tiles; continuous date filtering driven by the slider; atlas styling (distinct muted colors, flags, real-lifespan labels) on top; enrichment re-keyed off OHM (`wikidata` via Overpass + lazy-on-click); the existing tabbed panel; retiring the `historical-basemaps` static pipeline.

**Out:** high-zoom detail; the Phase-2 mode tabs (Rulers/People/Battles) and Phase-3 Voyages (unchanged roadmap); editing OHM data.

## 3. Architecture

### 3.1 Map sources — local OHM tile mirror (no live dependency)

A one-off `php artisan timemap:fetch-ohm-tiles`:
- Downloads OHM **`ohm_admin`** (`boundaries` source-layer) and **`osm_land`** vector tiles for **z0–5** (overview only) over a Europe-biased coverage box (world at the lowest zooms for context) into `public/ohm-tiles/{tileset}/{z}/{x}/{y}.pbf`.
- Sends a descriptive User-Agent (OHM/Wikimedia block default UAs — see the existing `WikidataPolityResolver`).
- Serves the tiles **statically** from `public/`, browser-cacheable. MapLibre points at our local tiles, **not** `vtiles.openhistoricalmap.org`.
- The cached tiles still carry `start_decdate`/`end_decdate`, so time-filtering runs entirely client-side. Re-run to refresh when OHM improves.
- **Tile size:** OHM packs ~300 localized `name_xx` fields per feature (a z5 Europe tile ≈ 1.46 MB). Mitigation: (a) low zoom only, (b) **re-tile to keep just `name`, `name_en`, `admin_level`, `start_decdate`, `end_decdate`, `osm_id`, `has_label`, `type`** (drop the other `name_xx`) to shrink tiles, (c) **gitignore `public/ohm-tiles/`** and regenerate on deploy (do NOT commit 100s of MB). The spike measures actual sizes and sets the final zoom bound.

### 3.2 Continuous time filter

Add the **`maplibre-gl-dates`** plugin. The slider's year drives `map.filterByDate(year)`, which filters every layer by `start_decdate`/`end_decdate` → only era-correct features render. Replaces `snapshotFor()` and the static per-era files. (BCE handling verified in the spike.)

### 3.3 Styling (keep the atlas look)

- Base: `osm_land` styled parchment + a light-blue water background (replaces demotiles).
- `boundaries` fill: distinct muted palette per polity via a stable hash of `osm_id`; hover/selected → brand yellow; thin sepia outline.
- A `symbol` layer for **flags + name/date labels**, filtered to prominent features (`has_label = true`, or `admin_level ≤ 4`) to avoid clutter; `text-field` from `name`/`name_en`; **date label from the feature's own `start_date`/`end_date`** (real lifespan).

### 3.4 Enrichment (`polities`, re-keyed off OHM)

- **Pre-enrich majors** — `timemap:sync-ohm-polities` queries the **OHM Overpass API** for European admin boundaries (`admin_level ≤ 4` or `has_label`), reading each feature's `name`, **`wikidata` QID**, and `osm_id`. It enriches via Wikidata **by QID** (precise — no name-matching) into `polities`, **keyed by `osm_id`**, reusing `WikidataPolityResolver` (extended to accept a QID directly). Flags download as today.
- **Lazy on click** — clicking an un-enriched feature resolves it on the fly (by name, or `osm_id → OHM API → wikidata`), caches into `polities`, and shows a spinner; instant thereafter.

### 3.5 Click → panel (existing UI)

Click a `boundaries` feature → `osm_id` + `name` + real `start/end_date` → `GET /teacher/timemap/polity/{osm_id}` (lookup, or lazy-enrich) → the existing **Summary / Wikipedia / Over Time** panel, now with correct lifespans.

### 3.6 Retire the old pipeline

Remove `timemap:import-boundaries`, `timemap:export-boundaries`, `public/geo/boundaries`, `public/geo/labels`, the vendored `historical-basemaps` GeoJSON, and the `boundaries` table. Keep `polities` (now keyed by `osm_id`), `EraService`/`era.js`, the slider, and the panel.

### 3.7 European focus

Centre on Europe (≈ [15, 50], zoom ~4); the world shows at the lowest zooms as muted context.

## 4. Components & boundaries

| Unit | Responsibility |
|---|---|
| `timemap:fetch-ohm-tiles` | mirror + re-tile OHM tiles to `public/ohm-tiles/` |
| `timemap:sync-ohm-polities` | Overpass → `polities` (QID-keyed enrichment of majors) |
| `WikidataPolityResolver` (extend) | resolve by **QID** (not just name) → flag/summary/dates/labels |
| `index.js` | OHM sources + `filterByDate` + atlas styling + click → panel |
| `polity/{osm_id}` endpoint | enriched payload; lazy-enrich on miss |

## 5. Testing

- `sync-ohm-polities` against a small **Overpass JSON fixture** (`Http::fake`) → a `polities` row keyed by `osm_id` with QID-resolved flag/summary.
- `WikidataPolityResolver::resolveByQid` unit test (`Http::fake`).
- Polity endpoint returns the payload by `osm_id`; lazy-enrich path faked.
- Playwright: map loads from local OHM tiles, `filterByDate` changes visible features as the slider moves, click → panel opens with real dates.
- **Spike checks** (Phase 0, see §6) are recorded, not just asserted.

## 6. Phase-0 spike (first task — gates the rest)

Before building, verify OHM is good enough:
1. Fetch OHM `ohm_admin` tiles at **235 BCE, 1000 CE, 1850 CE** over Europe; confirm **Gaul, Carthage, Rome, the German states** appear with sane extents (the failures that motivated this).
2. Confirm `maplibre-gl-dates` `filterByDate` works for **CE and BCE** years.
3. Measure tile counts + sizes for z0–5 Europe (with and without dropping `name_xx`) → set the zoom/field bound.
4. Confirm Overpass returns `wikidata` tags for major European polities.
If OHM's ancient coverage disappoints, surface it before the full build.

## 7. Decisions locked
- Source: **OHM `ohm_admin`**, CC0; **mirrored locally** (no live dependency), **low zoom only** (navigation tool).
- Time: **continuous** `filterByDate` (maplibre-gl-dates), retiring snapshots.
- Enrichment: **QID-precise** via OHM Overpass for majors + lazy-on-click; `polities` keyed by `osm_id`.
- Keep the atlas UI (colors, flags, real-lifespan labels, panel, slider); retire the `historical-basemaps` pipeline.
- Tiles **gitignored + regenerated**, not committed.
