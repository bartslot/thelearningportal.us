# Time-Map Phase 0 — Design Spec (J-1 → J-4)

**Date:** 2026-06-04
**Slice:** Epic J, Phase 0 (the scrubbable history Time-Map — proof build)
**Backlog tickets covered:** J-1, J-2, J-3, J-4 (folds C1 region-select + D1 era-timeline)
**Status:** approved design → ready for implementation plan

---

## 1. Goal

Ship the product's exploratory **hero surface**: a MapLibre map where the teacher
scrubs a year slider, watches historical borders populate, clicks a civilisation, and
sees the pre-generated stories for that place + era in a left column. This is the
direct answer to the playtest critique *"it's a static website — there's no real
interaction."* Phase 0 proves the loop with discrete-era GeoJSON snapshots; later phases
(out of scope) swap in continuous OHM tiles (J-5) and PostGIS spatial click (J-6).

## 2. Scope

**In:**
- MapLibre GL JS map shell inside a Livewire view, Alpine-driven state (J-1).
- Year slider over discrete seeded era years + cross-fading border polygons + a
  "≈ X years ago / ~N generations" readout (J-2, folds D1).
- Click a region polygon → left story column listing stories whose `polity_id` matches
  and whose era brackets the current year; click a story → open its lesson (J-3, folds C1).
- A `stories` table + seeder bridging `history-topics.json` (ancient spine) to
  `polity_id` + numeric eras (J-4).
- Supabase Postgres as the app DB; Playwright + PHPUnit + Vitest verification.

**Out (later tickets):**
- OpenHistoricalMap continuous vector tiles + OHM time slider (J-5).
- PostGIS spatial-temporal `ST_Contains` click → polity (J-6).
- Old-map overlays / literature panels (J-7, J-8).
- Border accuracy beyond the vendored discrete snapshots; non-ancient eras
  (US/UK/modern topics stay unmapped this slice).
- Live generation of lesson content from a click (that is K-7, and is explicitly *not*
  on the click path — the map only reads pre-existing stories).

## 3. Decisions locked (from brainstorm)

| Decision | Choice |
|---|---|
| Test harness | **Reuse Playwright (E2E/WebGL) + PHPUnit (feature/unit) + Vitest (JS math)** — no Dusk install. Meets the same Smoke checks the backlog describes. |
| Era coverage | **Ancient-spine anchors:** −3500, −2500, −1500, −500, 1, 500, 1000 CE + modern. |
| Story source | **New lean `stories` table** (separate from the heavy `Lesson` model); links to a lesson via nullable `lesson_code`. |
| Database | **Supabase Postgres now** (project ref `ophofmkxmehmeojvsijc`), via the Laravel `pgsql` connection + a separate test DB. |
| Slug→polity bridge | **Curated `timemap-spine.json`** (~8 civilisations), hand-authored, deterministic, testable. |
| Landing | Time-Map becomes the **default authenticated landing** (`teacher.timemap`); dashboard stays reachable from nav. |

## 4. Architecture

### 4.1 Data layer (Supabase Postgres)

New migration `create_stories_table`:

```
stories
  id              bigint pk
  slug            string unique
  title           string
  polity_id       string        # matches a GeoJSON polygon NAME, e.g. "Rome", "Egypt"
  region          string        # original topic slug, e.g. "ancient-greece"
  era_start       integer       # BCE negative, e.g. -480
  era_end         integer       # e.g. -323
  era_label       string        # human label, e.g. "Classical Period (480–323 BCE)"
  summary         text  null
  lesson_code     string null   # links to lessons.lesson_code -> /lesson/{lessonCode}
  source_attribution string null
  is_publishable  boolean default true
  timestamps
  index (polity_id)
  index (era_start, era_end)
```

No PostGIS this phase. The click lookup is a plain WHERE join on `polity_id` +
era-bracket. `lesson_code` is nullable so map content can exist before a full lesson does.

### 4.2 Era/polity bridge

`resources/data/timemap-spine.json` — curated array, one entry per spine civilisation:

```json
{
  "polity_id": "Egypt",
  "region": "egypt",
  "era_start": -2686,
  "era_end": -1070,
  "era_label": "Ancient Egypt (Old–New Kingdom)",
  "geojson_year": -1500
}
```

Headline civilisations (≥1 story each, covering every seeded year):
Sumer/Mesopotamia, Egypt, Indus Valley, Shang China, Ancient Greece, Rome
(+ extend to fill any seeded-year gaps). `polity_id` values **must** match polygon
`NAME` properties in the vendored GeoJSON for that year.

`App\Services\EraService` (pure PHP, PHPUnit-tested), authoritative:
- `yearsAgo(int $year): int` — `currentYear − year` (BCE negative ⇒ adds).
- `generations(int $year, int $yearsPerGen = 25): int`.
- `nearestEraYear(int $year, array $seededYears): int`.

A thin JS mirror (`resources/js/timemap/era.js`, Vitest-tested) computes the same
years-ago/generations for the live slider readout without a server round-trip. Both are
tested so they can't drift silently.

### 4.3 Border data

Vendor `historical-basemaps` world GeoJSON (CC-BY-SA, attributed) under
`public/geo/world_{year}.geojson` for the seeded years (negative years as
`world_bc{n}.geojson` per upstream naming, normalised on copy). Simplify if a file is
large. Each polygon feature exposes a `NAME` we treat as `polity_id`. Files are static
assets fetched by the front-end for the current nearest year. A `docs/` note records the
upstream commit + licence + attribution string.

### 4.4 Frontend (MapLibre + Alpine + Livewire)

- `npm i maplibre-gl`; new Vite entry `resources/js/timemap/index.js` added to
  `vite.config.js` input.
- Livewire `App\Livewire\TimeMap` + `resources/views/livewire/time-map.blade.php`.
- Route `Route::get('/teacher/timemap', TimeMap::class)->name('teacher.timemap')`; set as
  the post-login landing / primary nav target. Dashboard link preserved.
- Alpine store `timemap`: `{ year, selectedPolity, zoom }`. On every change, mirror to
  `window.__portal = { ready, year, selectedPolity }` for Playwright.
- Base style: MapLibre **demotiles** (no API key, ships with the lib) so there is zero
  external tile dependency for the proof.
- Historical GeoJSON added as a `fill` + `line` layer for the current nearest year; on
  year change, swap the GeoJSON source and cross-fade via `fill-opacity` transition.
- Year slider (Alpine `range` input) bound to the store; readout
  "≈ 3,000 years ago · ~120 generations" from the JS era mirror.
- Polygon click → Alpine sets `selectedPolity` → Livewire action
  `storiesFor(string $polityId, int $year)` returns stories where
  `polity_id = ? AND era_start <= year <= era_end AND is_publishable`. Rendered in the
  left column (DOM/HTML over the canvas, not WebGL text — assertable & localizable).
  Empty state when none. Story row links `/lesson/{lesson_code}` when present, else a
  disabled "lesson coming soon" state.
- CC-BY-SA attribution shown via a small MapLibre attribution/credit control.

### 4.5 Verification

**PHPUnit (feature/unit, against the Postgres test DB):**
- `EraServiceTest` — years-ago for −1500 ≈ currentYear + 1500; generations computed;
  `nearestEraYear` resolver picks the correct seeded year for in-between inputs;
  BCE sorts before CE.
- `TimeMapStoriesTest` — `Livewire::test(TimeMap::class)->call('storiesFor','Rome',-50)`
  returns era-bracketed matches only; empty state for a year outside any era.
- `TimeMapSeedTest` — after seeding, every seeded year resolves to ≥1 story for its
  headline polity, and that story's era brackets the year.

**Playwright (E2E / WebGL, headless with software GL):**
- `timemap-shell` — canvas present, `window.__portal.ready === true`, **no SEVERE
  console errors**, screenshot saved.
- `timemap-slider` — drag slider → GeoJSON source changes → readout updates.
- `timemap-click-stories` — click a region at a year → left column lists matching
  stories; click a story → navigates to the lesson route.

**Vitest:** `era.test.js` — JS years-ago/generations match the PHP service for sample years.

## 5. Components & boundaries

| Unit | Responsibility | Depends on |
|---|---|---|
| `EraService` (PHP) | year math, nearest-era resolution | — |
| `era.js` (JS) | live readout mirror of EraService | — |
| `StorySeeder` + `timemap-spine.json` | bridge topics → stories | `history-topics.json`, Story model |
| `Story` model + migration | persistence + `storiesFor` query scope | Postgres |
| `TimeMap` Livewire | state, `storiesFor` action, view | Story, EraService |
| `timemap/index.js` | MapLibre init, layers, slider, click, `__portal` | maplibre-gl, Alpine store |
| vendored GeoJSON | discrete border snapshots | historical-basemaps (CC-BY-SA) |

Each is independently testable: the services by unit tests, the model/Livewire by feature
tests, the map JS by Playwright + Vitest.

## 6. Prerequisites & risks

1. **`.env` Postgres connection (BLOCKER for running anything).** As of writing, `.env`
   is still `DB_CONNECTION=sqlite`. Implementation requires:
   ```
   DB_CONNECTION=pgsql
   DB_HOST=db.ophofmkxmehmeojvsijc.supabase.co   # direct 5432 for migrations
   DB_PORT=5432
   DB_DATABASE=postgres
   DB_USERNAME=postgres
   DB_PASSWORD=********        # Supabase DB password — user supplies
   DB_SSLMODE=require
   ```
   Plus `.env.testing` pointing at a **separate** Postgres test DB/schema
   (e.g. `learningportal_test`) so `RefreshDatabase` never touches real data.
   The Supabase **MCP** is connected (for agent-side SQL/introspection) but does **not**
   provide the app's runtime Eloquent connection — the password above is still required.
2. **polity_id ↔ GeoJSON NAME mismatch.** The curated bridge must use exact polygon
   names from each vendored file, or clicks return nothing. Mitigation: a tiny
   `php artisan timemap:verify` check (or test) asserting every spine `polity_id` exists
   in its `geojson_year` file.
3. **GeoJSON file size.** Some historical-basemaps years are large; simplify on vendor
   if a file noticeably slows the map. demotiles base avoids any tile-server dependency.
4. **Landing-page swap** is user-facing — keep the dashboard one nav click away; don't
   delete the existing dashboard route.

## 7. Definition of Done (this slice)

- [ ] `stories` table + seeder; ≥1 story per seeded spine year; `timemap:verify` passes.
- [ ] `/teacher/timemap` renders a MapLibre map (demotiles), pans/zooms, no console errors.
- [ ] Year slider cross-fades borders to the nearest seeded era; readout correct for BCE/CE.
- [ ] Click region → left column lists era-bracketed stories; story → opens lesson route;
      empty state when none.
- [ ] PHPUnit (`EraServiceTest`, `TimeMapStoriesTest`, `TimeMapSeedTest`) green on Postgres.
- [ ] Playwright (`timemap-shell`, `timemap-slider`, `timemap-click-stories`) green; shell
      screenshot saved.
- [ ] Vitest `era.test.js` green; PHP/JS era math agree.
- [ ] `./vendor/bin/pint` clean; `npm run build` succeeds with maplibre-gl bundled.
- [ ] CC-BY-SA attribution visible on the map; upstream licence noted in `docs/`.
- [ ] No secrets committed; Supabase creds stay in `.env`.
