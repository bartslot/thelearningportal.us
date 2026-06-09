# Time-Map → European History Atlas — Design Spec

**Date:** 2026-06-09
**Slice:** Reshape the Time-Map into a TimeMap.org / oldmapsonline-style **history atlas**, European-curriculum focused.
**Reference the user pointed to:** https://www.oldmapsonline.org/ (TimeMap.org World History Atlas)
**Status:** approved design → ready for implementation plan (Phase 1)

---

## 1. The vision (from the reference)

A history atlas where:
- The map shows **whole polities** as muted, *distinct*, non-overlapping colored regions, each with a **name + date-range label + flag** at its centre (e.g. "Kingdom of Belgium · 1831 –").
- **Clicking a region opens a left panel with that polity's *own* information** in tabs — **Summary** (Wikipedia extract), **Wikipedia**, **Over Time** — with its flag and lifespan and a close button.
- A top **mode switcher** (Regions / Rulers / People / Battles) changes what's plotted.
- An **oldmapsonline-style time slider** (century ticks, draggable) drives the year.

**The key reframe:** clicking a region shows **the region's own summary**, not a fuzzy list of articles that happened nearby. This *eliminates by design* the "Ness of Brodgar shows for a polity that excludes Scotland" bug (which came from matching articles to a coarse 7-bucket macro-region with broken era ranges). Rulers / People / Battles become **separate, precisely-geocoded marker layers** instead of fuzzy region matches.

## 2. Scope — Phase 1 (this spec): the atlas core

In: polity enrichment (flags + Wikipedia summaries + lifespans), atlas restyle (distinct muted colors + name/date labels + flags), the tabbed left panel (Summary / Wikipedia / Over Time), the oldmapsonline time slider, and European focus (drop low-significance polities, centre on Europe).

Out (later phases): the Rulers / People / Battles mode-tab marker layers (Phase 2); Voyages (Phase 3); the existing `history_articles`/macro-region matching is **retired from the map** (the tables stay, the map stops using them).

## 3. Architecture — Phase 1

### 3.1 Data: polity enrichment (1:1, no fuzzy matching)

New table `polities` (corpus `public` schema, beside `boundaries`):

```
polities
  polity_id      text primary key   -- matches boundaries.polity_id
  label          text               -- display name
  wikidata_id    text null
  flag_path      text null          -- /flags/{polity_id}.png (downloaded), null if none
  summary        text null          -- Wikipedia REST extract
  wikipedia_url  text null
  inception      int null           -- Wikidata P571 (year)
  dissolution    int null           -- Wikidata P576 (year)
  predecessor    text null          -- label (P155)
  successor      text null          -- label (P156)
  sitelinks      int default 0      -- notability
  significant    boolean default true
  updated_at     timestamptz
```

Pipeline `php artisan timemap:enrich-polities` (offline, idempotent):
1. Read distinct polity names from `boundaries`.
2. Resolve each → Wikidata via `wbsearchentities` (label search); take the best match that is a country/state/former-country type.
3. Fetch the entity: flag `P41`, inception `P571`, dissolved `P576`, predecessor `P155`, successor `P156`, sitelink count.
4. Wikipedia REST summary (`/page/summary/{title}`) via the entity's enwiki sitelink → `summary`.
5. Download the flag image (Commons → PNG) to `public/flags/{polity_id}.png`.
6. Upsert `polities`. Names that don't resolve are stored with nulls (region still clickable, shows name + dates only).

Marking `significant`: low-significance polygons (nomadic / forager / generic "cultures") are flagged `significant = false` via a type/name rule (e.g. name contains "cultures", "nomads", "hunter-gatherers", or sitelinks below a threshold) and **excluded from the exported map**.

### 3.2 Map restyle — atlas look

`timemap:export-boundaries` (extended) writes, per era, **two** static files:
- `public/geo/boundaries/{year}.geojson` — significant polygons only; properties: `polity_id, label, year_start, year_end, color_key`.
- `public/geo/labels/{year}.geojson` — one **centroid point** per polity (`ST_PointOnSurface`) with properties `polity_id, label, date_label ("1831 – 1922"), flag_id`.

Frontend (`index.js`):
- **Fill**: distinct muted earthy palette assigned per polity (stable hash of `polity_id` → palette index); the hovered/selected region brightens (brand yellow accent on the selected outline). Non-overlapping flat fills + thin darker borders — atlas feel.
- **Labels + flags**: a MapLibre `symbol` layer over the labels source — `text-field` = label + date range, `icon-image` = the polity flag (loaded via `map.addImage` from `/flags/...`). Collision-managed so labels don't overlap; flags shown at low zoom, labels as room allows.

### 3.3 Tabbed left panel

Click region → `polity_id` (client-side via `queryRenderedFeatures`, as now) → fetch `GET /teacher/timemap/polity/{polity_id}` (cacheable JSON: label, flag, summary, wikipedia_url, inception/dissolution, predecessor/successor). Render the panel (Livewire or Alpine) with:
- Header: flag + label + lifespan ("1831 – · 195+ years"), close (×).
- Tabs: **Summary** (the Wikipedia extract), **Wikipedia** (open-in-Wikipedia link / button), **Over Time** (inception → dissolution, predecessor → successor chain).
- Empty/unenriched state when a polity didn't resolve (name + dates only).

### 3.4 Time slider (oldmapsonline-style)

Replace the plain range input with a scrubbable timeline: century-labelled ticks, a draggable handle, current-year readout, keeping the existing `EraService`/`era.js` "years-ago" line. (This was the queued slider todo; it lands here.)

### 3.5 European focus

- Export only `significant` polygons; bias the default viewport to Europe (centre ≈ [15, 50], zoom ≈ 4) with the rest of the world as muted context.
- Coverage gaps (Spain/France/etc.) improve naturally because Wikidata enrichment is richest for European polities; truly missing polities surface as unenriched and can be backfilled.

### 3.6 Testing

- Unit/feature: `enrich-polities` against a small Wikidata/Wikipedia fixture → a `polities` row with flag + summary + dates; unresolved name → null-but-present row.
- Feature: `polity/{id}` endpoint returns the enriched payload; significant filter excludes a "…cultures" polygon from the export.
- Playwright: click a region → panel opens with the polity name + Summary tab; switching tabs works; close (×) dismisses.
- **Regression**: the old fuzzy region→article matching is gone — clicking a region shows only that polity's own summary.

## 4. Phase 2 — mode tabs (Regions / Rulers / People / Battles)

Geocoded Wikidata marker layers, plotted by precise coordinate (the Events / People / Places work from the earlier brainstorm):
- `map_items(wikidata_id, kind[ruler|person|battle|event|place], title, summary, lat/lng + geom, year_start, year_end, wikipedia_url, image_url, sitelinks)` with a GIST index.
- `timemap:ingest-wikidata` — bbox-SPARQL over Europe + structured dates, notability-capped, Wikipedia-enriched.
- Top mode tabs toggle the marker layer; clicking a marker opens the same tabbed panel for that item. Markers are filtered by the slider era.
- This is where precise spatial matching lives — and it never mis-attributes, because each marker has its own coordinate.

## 5. Phase 3+ — Voyages & exploration routes (future)

Illustrate exploration and trade as **animated routes**, to tell the *politics and effects* of seafaring/exploration (not just borders):
- **Dotted polylines with ship icons** along historical sea/trade routes — the Silk Road, trade with China / the Indies by ship, and Age-of-Exploration voyages (e.g. rounding **Cape Non**, **Cape Bojador**).
- A **Voyages** mode; routes animate/draw along the time slider; clicking a route opens its story (expedition, sponsoring country, dates) in the panel — using the map as the entry point to *play a story*.
- Data: curated route `LineString`s + waypoints + dates + expedition/country (Wikidata voyages/expeditions where available, plus hand-authored routes). **Decompose into tickets before building.**

## 6. Decisions locked
- Content model: **region = polity (1:1 own info)**; rulers/people/battles = geocoded markers (Phase 2). Retire macro-region article matching.
- Source: **Wikidata + Wikipedia** (flags P41, summaries, dates P571/P576, notability via sitelinks).
- Style: muted, *distinct* atlas palette + name/date labels + flags; selected region accented in brand yellow.
- Focus: European-centred, low-significance polities dropped, world as context.

## 7. Open defaults (confirm at plan time)
- "Significant" rule: name-pattern + sitelink threshold — tune the threshold against real data.
- Distinct-color assignment: stable hash vs neighbour-aware graph coloring (start with hash; upgrade if adjacents clash).
- Panel implementation: Livewire vs pure Alpine fed by the JSON endpoint (lean Alpine likely; keep it cacheable).
