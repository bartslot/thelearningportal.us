# Time-Map Phase 0 — Design Spec (J-1 → J-4, revised)

**Date:** 2026-06-04
**Slice:** Epic J, Phase 0 (the scrubbable history Time-Map — proof build)
**Backlog tickets covered:** J-1, J-2, J-3, J-4 (folds C1, D1; pulls J-6 PostGIS click forward)
**Status:** revised after discovering the populated corpus → ready for user review

> **Revision note.** The first draft assumed thin `history-topics.json` data, a hand-curated
> `stories` table, and vendored static GeoJSON. Connecting to Supabase revealed the ophof
> project (`ophofmkxmehmeojvsijc`) already holds a **populated history corpus with PostGIS**:
> 3,512 `history_articles`, 27,642 `history_facts`, 11,096 `entities`, 79,762
> `gazetteer_places`, plus empty-but-ready `boundaries` / `historical_maps` / `texts`
> tables. This spec is rebuilt around that reality.

---

## 1. Goal

Ship the product's exploratory **hero surface**: a MapLibre map where the teacher scrubs a
year slider, watches historical borders populate, clicks a region, and sees real corpus
articles for that place + era in a left column. Direct answer to the playtest critique
*"it's a static website — there's no real interaction."*

## 2. Scope

**In:**
- MapLibre GL JS map shell in a Livewire view, Alpine state, `window.__portal` test hook (J-1).
- Year slider over the corpus's era range + PostGIS border polygons that appear/disappear by
  `valid_from/valid_to` + a "≈ X years ago / ~N generations" readout (J-2, folds D1).
- Click the map → PostGIS `ST_Contains` + year-bracket → region → left column of real
  `history_articles` for that region + era; article links to its lesson/source (J-3, folds C1, J-6).
- An idempotent importer that loads border polygons into the existing PostGIS `boundaries`
  table, tagged with a polity_id, validity range, and macro-region (J-4 reframed).

**Out (later tickets):**
- Polity-level content precision (Phase-0 content is region+era granular — see §6.5).
- OHM continuous vector tiles + OHM time slider (J-5).
- Old-map overlays (J-7, the empty `historical_maps` table) and literature panel
  (J-8, the empty `texts` table).
- Writing/altering any corpus table; live generation on the click path (that is K-7).

## 3. Decisions locked

| Decision | Choice |
|---|---|
| Supabase access | App connects via the **session pooler** `aws-1-us-east-2.pooler.supabase.com:5432`, user `postgres.ophofmkxmehmeojvsijc`, db `postgres` (direct `db.<ref>.supabase.co` host is IPv6-only/dead). `.env` already fixed + verified. |
| App table location | **Dedicated `app` Postgres schema** in ophof. Laravel migrates there; corpus stays isolated in `public`. |
| Story source | **Read the real `history_articles`** (region + `era_start/era_end`). No `stories` table, no `history-topics.json` seed. |
| Borders | **Import polygons into PostGIS `boundaries`**; render from it; click via `ST_Contains` + year bracket (J-6 quality, pulled into Phase 0 since PostGIS is already enabled). |
| Test harness | Playwright (E2E/WebGL) + PHPUnit (feature/unit) + Vitest (JS math). No Dusk. |
| Era coverage | The corpus's own range (≈ −9000 → present); slider seeded with the ancient-spine anchor stops for the demo. |
| Test DB | **Open — recommend local Postgres+PostGIS** in `.env.testing` (see §6.3). |

## 4. Architecture

### 4.1 Two connections — corpus safety is structural, not careful-handling

`config/database.php` gains a second connection so a stray `migrate:fresh` can never touch
the corpus:

- **`pgsql`** (default, read-write): `search_path = app`. All Laravel migrations + app tables
  (users, lessons, modules, etc.) live here. `migrate:fresh` / `db:wipe` only see the `app`
  schema, so they cannot drop corpus tables. `search_path` becomes env-driven
  (`DB_SEARCH_PATH=app`).
- **`pgsql_corpus`** (same credentials): `search_path = public`. Used by corpus models.
  **Never migrated.** Corpus content tables (`history_articles`, `facts`, `entities`,
  `gazetteer_places`) are treated strictly read-only; the **only** sanctioned write is the
  boundary importer (§4.3) populating the currently-empty `boundaries` table.

Setup step (one-off, idempotent, run explicitly — *not* a destructive migration):
`CREATE SCHEMA IF NOT EXISTS app;` then `php artisan migrate` (writes to `app`).

### 4.2 Read-only corpus models (on `pgsql_corpus`)

- `App\Models\Corpus\Article` → `public.history_articles`
  (`title, summary, full_text, region, era_start, era_end, source_url, source_license`).
  `protected $connection = 'pgsql_corpus'`; guarded against writes.
- `App\Models\Corpus\Boundary` → `public.boundaries`
  (`polity_id, name, valid_from, valid_to, geom, color, source, license, extra jsonb`).

### 4.3 Border import (J-4 reframed)

`php artisan timemap:import-boundaries` — idempotent:
1. Reads `historical-basemaps` world GeoJSON (CC-BY-SA, attributed) for the seeded
   ancient-spine years.
2. For each polygon, upserts a `public.boundaries` row: `polity_id` (from feature NAME,
   slugified), `name`, `valid_from`/`valid_to` (the snapshot's era window),
   `geom = ST_GeomFromGeoJSON(...)`, `source`/`license` = historical-basemaps attribution,
   and a **macro-region tag** in `extra->>'region'` (one of the 7 corpus regions:
   Mediterranean, East Asia, Americas, South Asia, Northern Europe, Middle East, Africa).
3. The polity→macro-region tag is a small curated map (`resources/data/region-map.json`,
   ~dozens of entries) — the only hand-authored bridge left.

A guard test / `timemap:verify` asserts every imported boundary carries a `geom` and a
valid `extra.region` matching a real corpus region.

### 4.4 Era math

`App\Services\EraService` (pure PHP, PHPUnit-tested), authoritative:
`yearsAgo(int $year)`, `generations(int $year, int $per = 25)`,
`nearestEraYear(int $year, array $stops)`. BCE negative. A thin Vitest-tested JS mirror
(`resources/js/timemap/era.js`) drives the live slider readout; both tested so they can't drift.

### 4.5 Frontend (MapLibre + Alpine + Livewire)

- `npm i maplibre-gl`; new Vite entry `resources/js/timemap/index.js` in `vite.config.js`.
- Livewire `App\Livewire\TimeMap` + `resources/views/livewire/time-map.blade.php`.
- Route `Route::get('/teacher/timemap', TimeMap::class)->name('teacher.timemap')`, set as the
  default authenticated landing; dashboard stays one nav click away.
- Base style: MapLibre **demotiles** (no API key, no external tile dependency).
- Border layer: a thin JSON endpoint / Livewire payload returns
  `ST_AsGeoJSON` of boundaries valid at the current year
  (`valid_from <= year <= valid_to`); rendered as `fill` + `line`, cross-faded on year change.
- Alpine store `timemap { year, selectedRegion, zoom }`, mirrored to
  `window.__portal = { ready, year, selectedRegion }`.
- Year slider + readout from `era.js`.
- Map click → `(lng,lat)` → Livewire `storiesAt(lng, lat, year)`:
  `ST_Contains(geom, ST_SetSRID(ST_Point(lng,lat),4326))` AND year-bracket → boundary →
  `extra.region` → `Article::on('pgsql_corpus')->where('region', $region)`
  `->where('era_start','<=',$year)->where('era_end','>=',$year)` → left column (DOM/HTML over
  canvas — assertable, localizable). Empty state when none. Article row links its
  `source_url` (and later a generated lesson).
- Attribution control shows the boundary source licence + historical-basemaps credit.

### 4.6 Verification

**PHPUnit (feature/unit, against the test DB):**
- `EraServiceTest` — years-ago/generations/nearest-stop; BCE before CE.
- `BoundaryImportTest` — importer upserts geom + `extra.region`; idempotent (re-run = no dupes).
- `StoriesAtTest` — `Livewire::test(TimeMap::class)->call('storiesAt', lng, lat, year)`
  returns only region+era-bracketed articles; empty state outside any boundary.

**Playwright (E2E / WebGL, headless software GL):**
- `timemap-shell` — canvas, `window.__portal.ready`, no SEVERE console errors, screenshot.
- `timemap-slider` — drag → border set changes → readout updates.
- `timemap-click-stories` — click inside a boundary at a year → left column lists articles.

**Vitest:** `era.test.js` — JS math agrees with the PHP service.

## 5. Components & boundaries

| Unit | Responsibility | Connection / dep |
|---|---|---|
| `EraService` (PHP) / `era.js` | year math + readout | none |
| `Article`, `Boundary` (read-only models) | corpus access | `pgsql_corpus` |
| `timemap:import-boundaries` + `region-map.json` | load polygons → PostGIS, tag region | `pgsql_corpus` write to `public.boundaries` |
| `TimeMap` Livewire | state, `storiesAt`, border payload, view | both connections |
| `timemap/index.js` | MapLibre init, layers, slider, click, `__portal` | maplibre-gl, Alpine |

## 6. Prerequisites & risks

1. **`.env` — DONE & verified.** Pooler host/user/db/password fixed; connects (`db=postgres`).
2. **`app` schema + second connection (BLOCKER for migrating).** Must add `pgsql_corpus`,
   make `search_path` env-driven, `CREATE SCHEMA app`, then migrate. **Until then, run no
   migrations** — the default connection currently points at the corpus's database.
3. **Test DB — OPEN.** Recommend a **local Postgres+PostGIS** in `.env.testing` (seed fixture
   articles + boundaries, full `RefreshDatabase`, offline, zero risk to live corpus).
   Alternative: the `xpxjmzvvyyznokfxiqkh` test project (needs its pooler creds; remote =
   slower per test; must have PostGIS enabled).
4. **Org move is independent.** Moving ophof Westcloud → The Learning Portal org keeps the
   project ref `ophofmkxmehmeojvsijc`, so the pooler connection is unchanged. Do it in the
   dashboard whenever; it doesn't block this build.
5. **Content granularity.** `history_articles.region` is 7 macro-regions, not polities — so
   Phase-0 click→stories is region+era coarse (clicking anywhere in the Mediterranean polity
   cluster returns Mediterranean articles for that year). Polity precision is a later ticket
   via `history_facts`/`entities`/`gazetteer_places`. 973 articles have null region and 2,364
   null `era_start` — these are simply excluded from the map.
6. **Boundary licensing.** historical-basemaps is CC-BY-SA — attribution stored per row and
   shown on the map; upstream commit + licence recorded in `docs/`.

## 7. Definition of Done (this slice)

- [ ] `app` schema created; `pgsql_corpus` read-only connection added; `migrate` writes only
      to `app`; corpus `public` tables untouched (verified by row counts before/after).
- [ ] `timemap:import-boundaries` populates `public.boundaries` with geom + `extra.region`;
      idempotent; `timemap:verify` passes.
- [ ] `/teacher/timemap` renders a MapLibre map (demotiles), pans/zooms, no console errors.
- [ ] Year slider shows/hides boundaries by validity + cross-fades; readout correct for BCE/CE.
- [ ] Click inside a boundary at year Y → left column lists region+era-bracketed real articles;
      article links its source; empty state when none.
- [ ] PHPUnit (`EraServiceTest`, `BoundaryImportTest`, `StoriesAtTest`) green on the test DB.
- [ ] Playwright (`timemap-shell`, `timemap-slider`, `timemap-click-stories`) green; screenshot saved.
- [ ] Vitest `era.test.js` green; PHP/JS era math agree.
- [ ] `./vendor/bin/pint` clean; `npm run build` succeeds with maplibre-gl bundled.
- [ ] Attribution visible; upstream licence noted in `docs/`.
- [ ] No secrets committed; Supabase creds stay in `.env`; no corpus rows mutated except
      `boundaries` via the importer.
