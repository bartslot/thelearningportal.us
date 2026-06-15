# Curated Topics Catalog (A1–A4) — Design

**Date:** 2026-06-15
**Backlog:** Epic A (A1 lock topics, A2 serve from DB, A3 validate era/region, A4 attribution)

## Goal
Replace free-text lesson topics with a **curated, Wikipedia-linked catalog** built from the
TimeMap's Cliopatria polities **plus notable figures** (rulers + people) fetched from Wikidata —
mirroring how oldmapsonline.org TimeMap drives its "Rulers" and "People" label layers.

## Why
- Free-text topics produced vague lessons and the **France→Egypt** wrong-article bug
  (slug-guessing in `WorldHistoryService`). A locked catalog with a stored `wikipedia_url`
  lets the pipeline fetch the **exact** article — kills that bug class permanently.
- Our Cliopatria polities are already **Wikidata-QID-keyed**, so rulers/people come from
  Wikidata the same way oldmapsonline does — no new linking layer.

## Decisions (locked with user)
1. **Polity list:** all polities **with a Wikipedia article** (~3014). Searchable picker, ranked
   by sitelinks. Every clickable map territory can become a lesson.
2. **Figures:** fetched from Wikidata for the curated polities, in **two kinds**, like oldmapsonline:
   - `ruler` — polity's `P35` (head of state) / `P6` (head of government) statements, reign-dated
     via qualifiers `P580`/`P582`. Time-accurate "who ruled here at year Y".
   - `person` — people with `P27` citizenship = polity QID, ranked by `wikibase:sitelinks`,
     birth/death from `P569`/`P570`.
   - The 2 avatars (Napoleon, Joan of Arc) map to figure rows.
3. **Lesson creation:** subject **locked to dropdown** (catalog topic) + optional **free-text focus**
   (e.g. "daily life of a soldier"). Grounds the source; keeps angle flexibility.
4. **Catalog location:** corpus (`pgsql_corpus`), built by an **idempotent Artisan command**
   (never a Laravel migration — we don't migrate the corpus). Keyed by QID for future
   map-click→topic (K-7).

## Data model

### `public.polities` — add columns (A3 region)
- `region_lat numeric`, `region_lng numeric`, `region_label text` (modern-day country, from Wikidata `P17`)
- `is_publishable boolean default true` (A3 flag; false = missing era/region, excluded from picker)
- Region source: Wikidata `P625` on the polity, else capital `P36`→`P625`. Backfill where null.

### `public.figures` — new table (A2)
```
qid text primary key, name text, figure_kind text,           -- 'ruler' | 'person'
parent_qid text,                                              -- the polity QID
wikipedia_url text, summary text,
era_start integer, era_end integer,                           -- reign or birth/death
region_lat numeric, region_lng numeric, region_label text,
sitelinks integer default 0, is_publishable boolean default true,
updated_at timestamptz
```

### `public.topics` — SQL VIEW (A2 single source for the picker)
UNION of publishable polities (`type='polity'`) + figures (`type='figure'`, carries `figure_kind`).
Columns: `id` (`'polity:'||qid` / `'figure:'||qid`), `type`, `figure_kind`, `name`, `qid`,
`parent_qid`, `wikipedia_url`, `era_start`, `era_end`, `region_lat`, `region_lng`, `region_label`,
`summary`, `sitelinks`, `source_attribution`. No duplication; always consistent.

### `lessons` — add columns (A1)
- `topic_id text` (catalog ref, e.g. `polity:Q2277`)
- `focus text` (optional angle)
- reuse existing `wikipedia_source` for the exact article URL; keep `topic`/`region`/`era` denormalized.

## Components

### A2 — `topics:build` command (`timemap:build-topics`)
1. `ensureSchema()` — add polity columns, create `figures` table + `topics` view (CREATE OR REPLACE).
2. Backfill polity region (Wikidata `P625`/capital) + `region_label` (`P17`) where null.
3. For each curated polity (sitelinks-ranked): SPARQL for **rulers** (P35/P6 + qualifiers) and
   top-N **people** (P27, sitelink-ranked, LIMIT 8) → upsert `figures`.
   `--resume` skips polities already processed; progress bar; descriptive UA; `--limit`.
4. Refresh the `articles.json` snapshot to include figure summaries (map can label them later).
- Networked + slow (~3014 polities) → run outside subagents, like the existing sync command.

### A3 — `topics:health` command
Walk `topics`; require `era_start` (sortable, BCE negative) + region present; set `is_publishable`
false + report counts ("N topics, M valid, K flagged"). Exclude non-publishable from the picker.

### A1 — wizard lock-down (`Step1Settings` + blade)
- Replace free-text `$topic` input with a searchable select bound to `$topicId` (queries `Topic`
  model / `topics` view). Add optional `$focus` text field.
- Server guard: `mount`/`save` rejects a `topic_id` not in `topics`.
- On select: denormalize name/era/region + store `wikipedia_url` on the lesson.
- `BuildLessonOutline`: when the lesson has a stored `wikipedia_source` URL, fetch **that exact
  article** instead of `WorldHistoryService` slug-guessing.

### A4 — attribution
- `topics.source_attribution` per type. Lesson player + wizard show the line:
  *Borders: Cliopatria (CC-BY 4.0) · Wikipedia (CC-BY-SA) · Wikidata (CC0)*.

## Testing
- `BuildTopicsTest` — Http::fake() Wikidata SPARQL/REST fixtures → asserts figures upserted with
  kind + era; polity region backfilled.
- `TopicHealthTest` — a topic missing era is flagged non-publishable; BCE sorts before CE.
- `TopicPickerTest` (Livewire) — search filters; selecting an unknown `topic_id` rejected;
  selecting a valid one stores `wikipedia_source`.
- Playwright — wizard shows no free-text topic field; typing filters the dropdown.

## Out of scope
- Re-scraping article bodies (use Wikipedia summary + on-demand fetch in the pipeline).
- Map "Rulers"/"People" label layers (future — data is ready after this).
