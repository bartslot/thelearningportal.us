# The Learning Portal (thelearningportal.us) — Backlog (post-Alfonso playtest)

**Source:** Playtest + interviews with Alfonso (history/geography teacher, ~30, teaches 12–18, currently at a French international school in Rome following the French system). Two sessions: (1) teaching-practice interview, (2) hands-on product playtest. Transcripts: `leticia_interview1/2.txt`, `audio_transcribe(2).txt`.

**The pivot in one line:** Move the product from a *storyteller avatar* ("you give him info, he gives it back, but it's a static website — no real interaction, no way to know if kids understood") toward a **time-map you explore, that feeds teacher-assembled, grounded, comprehension-checked lessons.**

**Product shape (the spine this backlog now follows) — three stages:**
1. **Explore.** The **Time-Map is the hero / main tool** (Epic J): the teacher moves around the globe, sets the era on a time slider, and the map retrieves everything in the database for that time and place.
2. **Assemble.** From a map selection the system scaffolds a **lesson made of ordered modules** the teacher picks and reorders (Epic K — this replaces the old "scenes"). Module content is **pre-generated and cached** from the curated database (worldhistory.org + competences + linked real images/3D), then teacher-refined — *not* free-generated live (that reintroduces the vague/costly problem the playtest exposed). A typical lesson: intro → *what do you already know?* → timeline+map → comic story (timeline overlaid) → quiz → conclusion.
3. **Present.** The teacher runs it as a **slideshow** (click next), one module per screen.

Alfonso framed his own teaching as two axes — **history = time, geography = space** — and the Time-Map *is* those two axes fused into one surface. Comprehension is bookended: a *what-do-you-already-know* pre-quiz (Epic K) and a closing check (Epic G) give a pre/post learning gain.

---

## How to use this backlog

Each ticket is written to be **dispatched to a single coding agent** (e.g. Claude Code) and is self-contained: it states *why*, *scope (in/out)*, *acceptance criteria*, and a **Smoke** block — the agent's self-check before it reports done.

**Dispatch contract (paste into each agent run):**
> Work in the repo at `/Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us`. Implement this ticket. When done, run the **Smoke** steps as real commands (`php artisan test --filter=…`, `php artisan dusk --filter=…`, and `npm run build` if JS/CSS/Three.js changed) and paste the actual output. If a test can't run because the harness/feature doesn't exist yet, create the minimum harness (see INFRA-1), then run it. Run `./vendor/bin/pint` before finishing. Do not mark done until the smoketest passes. Report any acceptance criterion you couldn't meet and why.

**Global Definition of Done (applies to every ticket):**
- [ ] Acceptance criteria met
- [ ] `php artisan test` (Pest/PHPUnit) green for new/affected feature + unit tests
- [ ] The ticket's Dusk smoke test green (`php artisan dusk --filter=…`); output pasted into the PR/commit
- [ ] `./vendor/bin/pint` clean; `npm run build` succeeds if Blade/Alpine/Tailwind/Three.js touched
- [ ] No new browser console errors on the affected screen
- [ ] No secrets committed; Supabase credentials stay in `.env` (the `pgsql` connection), never inline in code
- [ ] Changed strings are localizable via Laravel `__()` / lang files (see F4) — no new hardcoded English in Blade/Livewire

**Priorities:** `P0` foundation/blocker · `P1` core value · `P2` polish · `P3` future/roadmap.

**Ticket template (for adding your own):**
```
### [ID] Title
P? · Epic · depends: [IDs]
Why: one or two sentences, ideally a teacher quote.
Scope — in: … / out: …
Approach: …
AC: [ ] … [ ] …
Smoke: run … → expect …
```

---

## Stack & smoketest conventions (TALL + Three.js)

**Stack:** Laravel + Livewire + Alpine + Tailwind (TALL), Three.js bundled via Vite. **Tests:** Pest (feature/unit) + **Laravel Dusk** (browser/E2E); optional **Vitest** for pure JS/Three.js math. Each ticket's **Smoke** block describes the *check*; run it using the matching pattern below — that's how "exact commands" resolves for every ticket.

**Laravel ↔ Supabase:** point a `pgsql` connection in `config/database.php` at the Supabase Postgres instance (host / port / db / user / password, `sslmode=require`) and use Eloquent + `php artisan migrate`. Use the **direct/session connection** for migrations; the transaction pooler (port 6543) is fine for app runtime. Don't reach for PostGIS yet — for v1 store plain `lat` / `lng` (+ optional `bbox` jsonb) numerics; enable PostGIS later only if you need spatial queries.

**Test database:** never test against live Supabase. Wire a **dedicated Postgres test DB** (local Docker Postgres, or a separate Supabase staging project) into `.env.testing` / `.env.dusk.local` so `RefreshDatabase` is safe. **Avoid SQLite for tests** — you rely on JSONB / Postgres types it won't replicate.

**Smoke type → exact command:**
- **unit / feature** (models, services, Livewire): `php artisan test --filter=ClassOrMethod`. Livewire components: `Livewire::test(Component::class)->set(...)->call(...)->assertSee(...)` inside a Pest test.
- **integration** (routes/endpoints): Pest feature test — `$this->get(...)/post(...)->assertStatus(...)->assertJson(...)`.
- **E2E** (real DOM, Alpine, navigation): `php artisan dusk --filter=TestName`.
- **JS math** (timeline/era pure functions, *only if client-side*): `npm run test` (Vitest).

**3D / WebGL verification (E1, E2, E3, C2, avatar work) — the special case.** You can't assert WebGL pixels in Dusk, so:
1. Expose minimal state to the DOM for tests: have Alpine/Three set `window.__portal = { ready, mode, avatar, expression, panelText, bgGenerated }`.
2. Dusk asserts the **canvas exists**, `window.__portal.ready === true`, and the relevant fields (`$browser->assertScript("window.__portal.mode") /* === 'comic' */`).
3. Assert **no console errors**: read browser logs in the test and fail on `SEVERE`.
4. Capture a screenshot (`$browser->screenshot('ticket-id')`) for visual review.
5. Headless WebGL: launch ChromeDriver with `--enable-unsafe-swiftshader` (software GL) so it renders in CI.

**Comic bubbles = DOM, not WebGL text** (architecture + testability win): render speech bubbles as Tailwind/Alpine HTML overlaid on the `<canvas>`, not as Three.js text geometry. Cheaper, accessible, localizable, and **assertable** — `$browser->assertSeeIn('.bubble', $firstSegment)`. Same for the timeline (D1) and map UI (C1): keep them DOM/SVG over the canvas, not inside WebGL.

**Audio (H1):** drive the HTML5 `<audio>` / Web Audio element via Alpine; the stop/mute control is real DOM → `$browser->click('@stop')->assertScript("document.querySelector('audio')?.paused") /* true */`.

**Toon shading:** Three.js `MeshToonMaterial` + a small gradient map; keep the avatar a single face/bust mesh (E1) so the scene stays light.

**Cost telemetry (E4):** wrap LLM / image / TTS calls in a service that writes token + asset cost to a `generation_costs` table; a feature test asserts a row is written and that comic mode logs a lower total than the legacy skybox path.

**Terminology in older tickets:** where a ticket says `region_geo`, that means the `lat` / `lng` (+ optional `bbox`) columns from A2. Where it says "API endpoint", that's usually a Livewire action or a thin route — not a separate REST API.

---

## Assumptions & open decisions (read before dispatching)

Confirmed: **TALL stack (Laravel + Livewire + Alpine + Tailwind) + Three.js**, repo at `…/apps/thelearningportal.us`. Smoke commands now target Pest + Laravel Dusk (see conventions above). Remaining assumptions — correct any that are wrong and the affected tickets shift:
- **Supabase access** is via a direct Postgres connection from Laravel (Eloquent + migrations), not the Supabase REST/JS client. If you're actually going through the JS client / RLS, A2 / A3 / B1 change.
- **Topics JSON confirmed** at `resources/data/history-topics.json` — a static file read by a Livewire component today. A2 backs it with an Eloquent `Topic` model on Supabase Postgres while keeping the same JSON shape (step one is a one-off import of this file into the table). Which topics it *should* contain is a content-strategy decision — see the research findings shared separately (anchor to a chronological ancient-world spine).

Decisions only you can make (flagged again in their tickets):
1. **worldhistory.org licensing.** Scraping + republishing derived lesson content has licensing/attribution implications. Confirm the terms (and what attribution string lessons must show) before lessons go live. → affects A2/A4.
2. **Avatar IP.** "Rick (Rick & Morty), stormtrooper, Darth Vader" are copyrighted/trademarked characters — fine as *internal mood references*, not safe to ship as actual avatar art in a commercial product. Recommend shipping **original characters inspired by the archetypes** (eccentric-scientist bot, sci-fi droid, friendly alien). → affects E1.
3. **Map geography:** modern borders vs historical borders. Cheap path = modern map + "modern-day X" label; richer path = historical map layers per era. → affects C1/C2.
4. **Country competence data:** France/Netherlands have published standard frameworks; **Italy does not** (Alfonso: Italian teachers "have a lot of power", set their own). So the competence system must support *teacher-defined* competences, not only lookups. → affects B1.

---

## Suggested sequencing

A fast, low-risk path:
1. **INFRA-1** (test harness) + **H1** (stop audio — #1 reported confusion, tiny fix, instant credibility).
2. **A1 → A2 → A3 → A4** (lock topics to the curated list and back it with clean Supabase data — everything downstream needs era/region/competence fields to exist).
3. **F1** (grade as primary selector — small, unblocks visual defaults + competence lookup).
4. **B1 → B2 → B3** (competences + the thumbs up/down refinement — the core "less generic" win).
5. **G1 → G2** (comprehension check + fix the dead-end after the avatar talks — Alfonso: *"the most important is check what they understood"*).
6. **Epic J Phase 0** (the hero Time-Map — explore time + space; absorbs C + D).
7. **K-1 → K-2 → K-3 → K-7** (modular lessons + composer + slideshow player + the map→lesson scaffolder — replaces "scenes"; the core authoring/presenting loop), with **K-4** (what-do-you-already-know) alongside **G1**.
8. **E1 → E2 → E3 → E4** (comic visual system — delivers the story/peasant modules from real images — + cost guardrails) and **K-5** (Sketchfab 3D module).
9. **F2–F8** (UX polish), **J Phase 1+** (OHM tiles + PostGIS), and **I1–I2** (the bigger interactive roadmap).

---

# INFRA

### INFRA-1 Smoketest harness (do first, if absent)
P0 · Infra · depends: —
**Why:** Every ticket below ends in a self-check; the agent needs the runners wired up.
**Scope — in:** Pest (Laravel 11+ ships it; else `composer require pestphp/pest --dev`) with one example feature test; **Laravel Dusk** (`composer require --dev laravel/dusk` → `php artisan dusk:install` → `php artisan dusk:chrome-driver --detect`) with one example E2E that boots the app and asserts the dashboard renders; a **dedicated Postgres test connection** wired to `.env.testing` / `.env.dusk.local` so `RefreshDatabase` never touches prod; optional **Vitest** for pure Three.js / timeline math. **out:** full coverage; SQLite as the test DB (won't replicate JSONB / Postgres types).
**AC:**
- [ ] `php artisan test` runs Pest feature/unit tests against the test Postgres DB.
- [ ] `php artisan dusk` runs E2E; ChromeDriver launches with `--enable-unsafe-swiftshader` so WebGL renders headless.
- [ ] One example Dusk test loads the app and asserts a known dashboard element is visible.
- [ ] A documented local command (and/or CI step) runs both.
**Smoke:** `php artisan test && php artisan dusk` → both exit 0; the example Dusk test's screenshot shows the dashboard.

---

# EPIC A — Content foundation: curated topics, backed by Supabase

*Came from:* your decision to stop free-form generation ("lessons become too vague and general"), plus Alfonso never asking for free topics — he wants depth, not breadth.

### A1 Lock lesson topics to the curated list (remove free-form topic entry)
P0 · A · depends: —
**Why:** Free-text topics produce vague lessons and can't be enriched with era/region/sources. The curated list is now the single source of truth.
**Scope — in:** replace the free-text topic field with a searchable select bound to the topic list; **server-side guard** rejecting any lesson whose topic isn't in the canonical list. **out:** the Supabase wiring (A2).
**Approach:** searchable dropdown/typeahead over the topic list; generation endpoint validates `topic_id ∈ topics` before doing anything.
**AC:**
- [ ] No free-text topic input exists anywhere in lesson creation.
- [ ] API rejects an unknown topic with a 4xx + clear error.
- [ ] Picker is searchable/filterable (the list will be long).
**Smoke:** (unit) call create-lesson with `topic_id="not-real"` → expect 4xx; with a valid id → 2xx. (E2E) open creation → assert no free-text topic field; type in search → list filters.

### A2 Serve the topic list from Supabase (worldhistory.org content)
P0 · A · depends: A1 · **decision: licensing (see open decisions #1)**
**Why:** The list + its content should come from your Supabase DB populated from worldhistory.org, not a static file.
**Scope — in:** `topics` table + a topic→content relation; an endpoint/query returning the list in the same shape the picker already consumes; content retrievable for generation. **out:** building the scraper (assume it runs/has run — A3 validates its output).
**Approach:** a Laravel migration + `Topic` Eloquent model on the Supabase `pgsql` connection: `topics(id, slug, title, era_start int, era_end int, era_label, region, lat numeric null, lng numeric null, bbox jsonb null, summary, source_url, source_attribution)` + a related `topic_content(topic_id, section, body)` table or a JSONB `content` column. Serve the list from a Livewire component / read route in the same shape the picker already consumes. Optionally cache to a generated JSON (`Storage`) for cold-start cost.
**AC:**
- [ ] Topic list is served from Supabase (verify by editing a row → UI reflects it).
- [ ] Each topic exposes era + region + content fields (even if some are null pending A3).
- [ ] Frontend contract unchanged or adapter added so A1's picker still works.
**Smoke:** (integration) hit topics endpoint → count matches `select count(*) from topics`; fetch one topic's content → non-empty. (manual) change a title in Supabase → reload → new title shows.

### A3 Validate & normalize scraped content (fills era/region/sources)
P0 · A · depends: A2
**Why:** Maps (C), timeline (D), and competence-mapping (B) all need clean, present `era_*`, `region`, `region_geo`, and `summary` fields. Scrapes are messy.
**Scope — in:** a validation/normalization pass + a report of topics missing required fields; backfill era + region where derivable; a schema check that fails loudly. **out:** manual content authoring.
**Approach:** an Artisan command (`php artisan topics:health`) that walks `topics`, validates required fields (a dedicated rule set / DTO), normalizes era to numeric years (BCE = negative `era_start`), geocodes `region` → `lat`/`lng` where missing, and writes a `topic_health` report (table or log). Exclude failing topics from the picker via an `is_publishable` flag/scope.
**AC:**
- [ ] Schema defined; every shipped topic passes it (or is flagged + excluded from the picker).
- [ ] `era_start`/`era_end` are numeric and sortable across BCE/CE.
- [ ] `region_geo` present for every topic exposed to children.
**Smoke:** run the validator → prints "N topics, M valid, K flagged"; assert 0 *exposed* topics fail schema. Spot-check: a BCE topic sorts before a CE topic.

### A4 Source attribution + "sources" surface
P0 · A · depends: A2 · **decision: licensing**
**Why:** Alfonso distinguished source types ("Wikipedia one way, bot sources another"); and worldhistory.org likely *requires* attribution.
**Scope — in:** store + display a source/attribution line per topic and on the rendered lesson. **out:** multi-source merging.
**AC:**
- [ ] Each lesson shows its source attribution (worldhistory.org + any others).
- [ ] Attribution string is configurable per the license terms.
**Smoke:** render any lesson → attribution line present and matches the topic's `source_attribution`.

---

# EPIC B — Competences (per-country curriculum)

*Came from:* "Competences are given by the ministry of education in the specific country, and the teacher has to follow this." Soft skills Alfonso listed: discussing, formulating questions, teamwork on a project, analyzing a (graphical) document, expressing an argument/opinion. He also splits **theoretical knowledge vs practical competences**.

### B1 Competence data model (country × grade × subject)
P1 · B · depends: — · **decision: Italy has no standard set (#4)**
**Why:** Lessons must target the official competences for the teacher's country/grade; this is what makes them non-generic.
**Scope — in:** schema for competences keyed by country (`GB, US, CA, FR, DE, IT`), grade band, subject (history/geography); seed **FR** (well-defined) + a **generic** fallback; support **teacher-defined** competences (for IT and gaps). Include soft-skill competences. **out:** scraping every ministry site — seed FR + generic now, expand later.
**Approach:** Laravel migrations + Eloquent models `Competence(id, country, grade_band, subject, type ['knowledge'|'skill'], text)` + `TeacherCompetence(...)` for custom, on the Supabase `pgsql` connection. Seed FR + a generic fallback via a `CompetenceSeeder`. Tag each as knowledge vs practical skill.
**AC:**
- [ ] Query `(country, grade_band, subject)` → structured list.
- [ ] All six countries resolvable (FR + generic seeded; others may start from generic).
- [ ] Teacher can add a custom competence; it's queryable alongside official ones.
**Smoke:** (unit) query FR/history/middle → non-empty, typed list; query IT → base/generic set; insert a teacher competence → appears in the query result.

### B2 Teacher refinement flow — thumbs up/down on 3–4 AI questions
P1 · B · depends: A2, B1
**Why:** Your core ask. Today's script is "too general." After the teacher picks a topic, the AI asks 3–4 targeted questions about what students must actually learn; the teacher 👍/👎 each; the answers sharpen the lesson's objectives.
**Scope — in:** generate 3–4 questions from `topic content + competences(country,grade)`; render each with 👍/👎 (and optionally a one-line edit); persist the verdicts; produce a refined objective set. **out:** the generation prompt injection (B3).
**Approach:** LLM call → up to 4 candidate learning-focus statements grounded in the topic + competences; teacher votes; store accepted ones as `lesson.objectives`.
**AC:**
- [ ] Flow shows 3–4 topic-specific questions (not generic boilerplate).
- [ ] 👍/👎 captured and stored per question.
- [ ] Accepted items become a stored, structured objective list on the lesson.
**Smoke:** (E2E) pick a topic → 3–4 questions appear and reference the topic by name; vote 👍×2/👎×2 → reload → verdicts persisted; lesson record has `objectives` = the up-voted items.

### B3 Inject refined competences into generation (+ make them visible)
P1 · B · depends: B2
**Why:** The whole point is the *output* gets more specific and the competences are explicit (they also feed the quiz in G1).
**Scope — in:** add the accepted objectives + competences to the generation prompt; carry them through to the lesson JSON so they're displayed and reusable. **out:** the quiz (G1).
**AC:**
- [ ] Generated lesson JSON contains an `objectives`/`competences` array.
- [ ] Generated content demonstrably addresses the up-voted objectives (not just the topic title).
- [ ] Objectives are shown to the teacher on the lesson.
**Smoke:** generate with a known objective set → assert the JSON's `objectives` match and that body text references each objective's keywords. (Optional eval) compare specificity of a lesson generated with vs without objectives.

---

# EPIC C — Space: maps & region (geography axis)

> **Superseded by Epic J (Time-Map)** — space and time are now one scrubbable map, not two separate features. The C tickets below are kept for context/detail; build them via Epic J instead (mapping: C1 → J-1 base map + J-3 click-region→stories; C2 → J-5 OHM time-accurate borders).

*Came from:* your "region in the world must be selected by the children" + Alfonso's "geography = space," his use of context/adaptation ("Incas in the Andes → adapt to mountains"), and the screen as "a window to the world outside the classroom."

### C1 Region selection on a map (child-facing activity)
P1 · C · depends: A3 (needs `region_geo`)
**Why:** Anchors *where* a topic happened; turns location into an interactive comprehension moment rather than a static backdrop.
**Scope — in:** render a map focused on the topic's region; let the child select/confirm the region; validate against the correct answer with feedback. **out:** historical border layers (C2).
**Approach:** lightweight map (Leaflet + OSM tiles, or vector tiles); topic provides `region_geo`; "where in the world did this happen?" → child picks → correct/incorrect feedback. Keep it cheap (no per-lesson image generation).
**AC:**
- [ ] Map renders centered on the topic's region.
- [ ] Child can select a region/point; correct selection → positive feedback, wrong → corrective hint.
- [ ] Selection result is recorded (feeds G1 aggregate).
**Smoke:** (E2E) open a topic with `region_geo` → map centers on it; select correct region → success state; select wrong → corrective state. (unit) topics missing `region_geo` are excluded from the map activity.

### C2 Historical / era-appropriate map context
P2 · C · depends: C1 · **decision: modern vs historical borders (#3)**
**Why:** Modern borders mislead ("this country didn't exist then").
**Scope — in:** at minimum a "modern-day X" annotation; stretch: swap to a historical map layer per era. **out:** building a full historical GIS.
**AC:**
- [ ] Map labels modern-day equivalents, or loads an era-appropriate layer when available.
**Smoke:** load an ancient topic → either historical layer loads or a "modern-day X" annotation is shown.

---

# EPIC D — Time: era & timeline (history axis)

> **Superseded by Epic J (Time-Map)** — the standalone timeline/overlay becomes the map's time slider. D tickets kept for context; build via Epic J (mapping: D1 → J-2 year slider + "years-ago" readout; D2 → recap reuse inside J). The D1 brainstorm options (a–e) still inform the slider/readout design.

*Came from:* your "ERA … on a time scale is super important … maybe an intro or overlay?" + Alfonso's "history = time."

### D1 Era timeline + intro overlay (with relatable anchors)
P1 · D · depends: A3 (needs `era_*`)
**Why:** Kids struggle to grasp *how long ago* something was. A to-scale timeline with familiar anchors makes "2,000 years ago" mean something.

**Brainstorm — how to use the era (pick 1–2 to build now, leave the rest as hooks):**
- **(a) "Time machine" intro overlay** before the lesson: a horizontal timeline from *today* zooming back to the period. Cheap, high-impact, reusable.
- **(b) Persistent timeline ribbon** along the top/bottom showing where this lesson sits relative to anchor events.
- **(c) Relatable-units conversion:** "~2,000 years ago ≈ 80 generations / ~27 grandparents-of-grandparents." Convert years → generations/lifetimes.
- **(d) Comparative markers:** drop 2–3 other known events on the same axis for context (pyramids, fall of Rome, moon landing).
- **(e) Assessment hook:** "drag this event to the right spot on the timeline" → reuse as a quiz item in G1.

**Recommended for v1:** build a reusable `<Timeline focusRange anchors>` component (used by both the intro overlay and the quiz), and ship (a) + (c).
**Scope — in:** era metadata already present (A3); reusable timeline component; intro overlay using it with a few fixed anchor events. **out:** editable anchors per lesson (later).
**AC:**
- [ ] Topic's era renders on a to-scale timeline relative to the present.
- [ ] At least 2 relatable anchors shown; a "X years ago / ~N generations" line is computed correctly.
- [ ] Component is reusable (imported by the overlay; ready for G1).
**Smoke:** (unit) feed era_start=-44 (44 BCE) → "years ago" ≈ current_year+44, generation count computed; BCE/CE positioned correctly on the axis. (E2E) start a lesson → overlay shows the era + anchors before content.

### D2 Two-pass pacing tie-in (timeline frames the recap)
P2 · D · depends: D1, G3
**Why:** Alfonso runs videos twice — whole first, then stop-and-check. The timeline is a natural "where are we / how far have we come" frame for the second pass.
**Scope — in:** surface the timeline at the recap step of G3. **out:** —
**AC:** [ ] Recap step shows the timeline with the lesson's era highlighted.
**Smoke:** reach the recap → timeline visible with era highlighted.

---

# EPIC E — Visual system: comic/manga, leaner avatar, lower cost

*Came from:* the strongest playtest signal. "This is not Napoleon"; the avatar looks "like a GTA3 image," animation is "old for the standards"; dancing/walking "would distract"; **drop the humanoid**, lean into a robot/caricature ("I'm a robot, here to help you with all human knowledge — so you don't expect Napoleon/Cleopatra"); **change facial expressions** (angry/happy) instead of body animation; comics with **speech bubbles** help because reading + listening reinforce each other; **middle school ≈ 90% would love comics**, high school may prefer animation.

### E1 Drop full-body 3D avatar → face/bust + non-humanoid presets
P1 · E · depends: — · **decision: avatar IP (#2)**
**Why:** Body animation distracts and looks dated; expressive faces are what matter; a frankly non-human "knowledge robot" sidesteps the uncanny "that's not really Napoleon" problem.
**Scope — in:** stop rendering the full body; render a **face/bust** (2D or toon-3D); ship **2–3 original non-humanoid presets** (eccentric-scientist bot, sci-fi droid, friendly alien) with at least neutral/happy/serious expressions. **out:** lip-sync (explicitly not needed — comic bubbles cover it; see E2).
**AC:**
- [ ] No full-body model/dance animation anywhere.
- [ ] Avatar is face/bust with switchable expressions (≥3 states).
- [ ] ≥2 original presets selectable; none are recognizable third-party IP.
**Smoke:** (E2E) start a lesson → no walking/dancing; avatar visible; switch expression → face changes; switch preset → avatar changes. (unit/asset) avatar asset payload smaller than the current full-body baseline (assert < threshold).

### E2 Comic mode with speech bubbles
P1 · E · depends: E1
**Why:** "Reading and listening at the same time helps"; manga with bubbles is the format kids already love. Bubbles also remove any need for lip-sync.
**Scope — in:** render the script as sequential comic **panels** with **speech bubbles** carrying the text, synced to narration segments; apply a **toon/cel shader** to any 3D; advance on tap or audio progress. **out:** the reusable asset library (E3).
**AC:**
- [ ] A lesson can render in comic mode: panels + bubbles, bubble text == script segment.
- [ ] Toon shading applied; no realistic full-scene render required.
- [ ] Narration stays in sync with the visible bubble (and is stoppable per H1).
**Smoke:** (E2E) switch a lesson to comic mode → panels + bubbles render; assert first bubble's text equals the first script segment. (cost) assert **no skybox/large-background generation call** fires in comic mode (see E4).

### E3 Pre-generated manga asset library (compose, don't generate)
P1 · E · depends: E2
**Why:** Your cost driver is "huge background skyboxes … costing a lot per lesson." Composing from a curated library of characters/expressions/backgrounds removes most per-lesson generation.
**Scope — in:** an asset catalog (character poses/faces, expression sprites, a set of reusable backgrounds/props) + a composition layer that builds panels from catalog assets keyed by scene tags. **out:** an art-production pipeline for hundreds of assets — seed a starter set.
**AC:**
- [ ] Asset catalog exists and is referenced by the renderer.
- [ ] Comic panels are assembled from catalog assets (no per-lesson background generation in the default path).
- [ ] Adding a new asset to the catalog makes it usable without code changes.
**Smoke:** render a comic lesson → network/log shows panels sourced from the catalog, **zero** generative-image calls. Drop a new background into the catalog → it can be selected.

### E4 Per-lesson cost guardrails + telemetry
P1 · E · depends: E2
**Why:** You explicitly want to cut per-lesson cost and stop depending on skyboxes — you can't manage what you don't measure.
**Scope — in:** log estimated cost per lesson (LLM tokens + any image/audio generation); a configurable budget threshold that **warns/blocks**; a simple readout (log line or small admin view). **out:** billing integration.
**AC:**
- [ ] Each generation logs an estimated cost breakdown.
- [ ] Threshold configurable; exceeding it warns (and blocks in a test mode).
- [ ] Comic mode (E2/E3) logs **lower** cost than the legacy skybox path.
**Smoke:** generate legacy vs comic for the same topic → comic cost strictly lower (assert). Set threshold to ~0 → generation flagged/blocked.

### E5 Grade-aware visual default (comic for middle, animation option for high)
P2 · E · depends: E2, F1
**Why:** Alfonso: middle school loves comics (~90%); high schoolers lean toward animation and "what they like in the world." Default by grade, allow override.
**Scope — in:** default the visual style from the selected grade (middle→comic, high→animation/cinematic), overridable; clarify the "recommended for this grade" hint (it confused him — see F1). **out:** —
**AC:**
- [ ] Changing grade updates the recommended visual style with a clear label.
- [ ] Teacher can override the recommendation.
**Smoke:** select a middle grade → comic recommended/labeled; select high → animation recommended; override persists.

---

# EPIC F — UX & lesson settings / polish

> **Note:** the lesson *creation flow* is now the Composer (K-2) + map scaffolder (K-7); F3's single-page config folds into the Composer. The remaining F tickets are cross-cutting **settings & polish** that apply to the Composer and Player — don't build a parallel creation UI here.

*Came from:* the playtest UX notes.

### F1 Grade as the primary selector (age secondary, for content-gating only)
P0 · F · depends: —
**Why:** "Grade is more important than age — same class can hold different ages, and age ≠ maturity." He also hit a bug: he "saw only age" at the start and "it didn't click to grade … it was not logical."
**Scope — in:** grade is the primary control; map grade → school-system band; recommendations (visual style, competences) key off grade; **age** used only to gate minor-vs-adult content appropriateness. **out:** —
**AC:**
- [ ] Grade is the primary selector; no age-only step that fails to map to grade.
- [ ] Recommendations update when grade changes.
- [ ] Age, if shown, is derived from grade and used only for content gating.
**Smoke:** (E2E) open creation → grade selector is primary; change grade → recommendations update; confirm no standalone age-only step exists.

### F2 Tone presets — Academic by default; guard "inspiring"/"humorous"
P1 · F · depends: —
**Why:** "Academic should be the default — it's school stuff." Storytelling/drama are additive. He was wary of **"inspiring"** in history ("history is a dangerous discipline … it touches hate … easy to convert kids to Nazi SS") and of **"humorous"** ("depends on the thematic"). "Casual" is fine for direct Q&A with the bot.
**Scope — in:** presets = **Academic (default)**, +Storytelling, +Drama, Casual(for Q&A); remove or strongly guard "Inspiring" for history; add a sensitive-theme guardrail line to the generation prompt. **out:** —
**AC:**
- [ ] New lesson defaults to Academic.
- [ ] Storytelling/Drama are modifiers layered on the academic base.
- [ ] "Inspiring" is removed or gated for history; sensitive-topic guardrail present in the prompt.
**Smoke:** new lesson → tone = Academic; select Storytelling → prompt includes the modifier but retains the academic base instruction; assert guardrail text is in the prompt for a flagged topic.

### F3 Single-page lesson configuration
P1 · F · depends: —
**Why:** "Put an interface easier to complete — what's the text, the voice — in one page, not 'go here, here, here' to change it to French."
**Scope — in:** consolidate script/text, voice, language, tone, and style onto one screen. **out:** —
**AC:**
- [ ] All core lesson config lives on one screen; no navigation hop to change language/voice/tone.
**Smoke:** (E2E) change language + voice + tone from a single screen without route changes.

### F4 Language selection at start + localizable UI
P2 · F · depends: F3
**Why:** Non-English teachers; "a flag to change the language"; an international-school login implies a language; the bot should answer in the chosen language ("like GPT — ask in French, get French").
**Scope — in:** up-front language picker (flag); i18n scaffold so UI strings localize; generation + bot replies respect the selected language; **default from the teacher's profile/school**. **out:** translating all existing copy now (scaffold + a couple of locales).
**AC:**
- [ ] Language selectable at start; default pulled from profile.
- [ ] UI strings are externalized (no hardcoded English added).
- [ ] Generated script + bot replies come back in the selected language.
**Smoke:** set FR → UI strings + a generated script are French; log in as a FR-profile teacher → language defaults to FR.

### F5 Onboarding hints / hover tooltips (for non-technical, often older teachers)
P2 · F · depends: —
**Why:** "We forget that we're monkeys — if something can fail, it will," and "old teachers … are the majority." He wanted not a full tutorial but hover hints: "pass the mouse on the dashboard → a small script tells you what you'll find."
**Scope — in:** tooltips/hover hints on key dashboard controls; optional dismissible first-run coachmarks. **out:** a forced full-screen tutorial.
**AC:**
- [ ] Key controls show concise hover hints.
- [ ] First-run hints appear and are dismissible; dismissal persists.
**Smoke:** hover a key control → hint shows; first run → coachmarks appear; dismiss → not shown on reload.

### F6 Fix stretched imagery; avatar in a bounded panel
P1 · F · depends: —
**Why:** "The images are too stretched, it didn't look well"; "if smaller it'll look better"; he suggested the bot in a **corner/square panel** (YouTube-style) rather than full-bleed — "the whole image is too much."
**Scope — in:** preserve aspect ratios (no CSS stretch); render the avatar/bot in a sized, bounded panel; responsive layout. **out:** —
**AC:**
- [ ] No stretched/distorted images on the lesson screen.
- [ ] Avatar sits in a bounded panel, not full-screen, and stays correct across viewport sizes.
**Smoke:** render at desktop + mobile widths → assert images keep aspect ratio (no stretch) and the avatar panel stays within bounds (snapshot/visual check).

### F7 Avatar gallery / preview ("say hello")
P2 · F · depends: E1
**Why:** "It'd be nice to see the avatars in real life and say hello when you select them."
**Scope — in:** a selection screen previewing each avatar with a short greeting (uses the expression states from E1). **out:** —
**AC:** [ ] Each avatar previews with a greeting on selection.
**Smoke:** open avatar gallery → each option previews + greets on hover/select.

### F8 Optional: "model-teacher" style reference for the bot
P3 · F · depends: F2
**Why:** Alfonso suggested seeding the bot's delivery from a well-known communicator ("a teacher like Barbero in Italy … a way of talking"). He noted it's "more prompt, specific of the bot."
**Scope — in:** an optional text field where the teacher describes a delivery style (no impersonation of a named living person in output — use it to flavor *style*, not to mimic identity); injected into the prompt. **out:** —
**AC:** [ ] Optional style field flows into generation and changes delivery style; never claims to *be* a real named person.
**Smoke:** set a style note → generated narration reflects the style; assert output doesn't assert a real person's identity.

---

# EPIC G — Comprehension, assessment & lesson structure

*Came from:* the single most important theme. "There's no quiz option, no publish option"; after the avatar talks "there's a moment where you don't know how to continue"; "**the most important is to check what they understood** — I can talk until the hour ends but never know if they got it." Plus his actual method (two passes) and his point that **oral reveals real understanding** while AI-written homework hides cheating.

### G1 End-of-lesson comprehension check (quiz)
P1 · G · depends: B3 (objectives), and ideally C1 + D1 for richer item types
**Why:** Closes the loop — turns a monologue into something that verifies learning.
**Scope — in:** auto-generate **3–5 questions from the lesson's objectives/competences**; present after the lesson; capture answers; show the teacher per-student + class-aggregate results. Reuse a **timeline-placement** item (D1) and a **map** item (C1) where relevant. **out:** a full gradebook.
**AC:**
- [ ] After a lesson, a 3–5 question check appears, each item tied to a stated objective (not generic).
- [ ] Answers captured + scored; teacher sees an aggregate ("who understood what").
- [ ] At least one non-MCQ item type available (timeline drag or map pick).
**Smoke:** (E2E) finish a lesson → quiz of 3–5 items appears; each item references an objective; answer items → scored; teacher view shows aggregate. (unit) generating a quiz for a lesson with N objectives yields items mapped to those objectives.

### G2 Fix the post-lesson dead-end (clear next actions, incl. publish/save)
P1 · G · depends: —
**Why:** "After it starts to talk … and then what? There's no publish option." The flow currently strands the user.
**Scope — in:** explicit end-of-lesson actions: **Review / Take the check (G1) / Next lesson / Save (publish)**. **out:** sharing/permissions (deliberately out — that's a sensitive action).
**AC:**
- [ ] Lesson end shows clear next-step actions; no dead-end.
- [ ] Save/publish persists the lesson to the teacher's library.
**Smoke:** (E2E) reach lesson end → next-step buttons present and functional; Save → lesson appears in library on reload.

### G3 Two-pass lesson structure (overview first, then stop-and-check)
P2 · G · depends: G1
**Why:** Alfonso's repeated method: "first time, show it whole — they hear how it ends; second time, pause and ask *what did you understand?*, then add specific info and targets." Encoding this pacing fits how he actually teaches.
**Scope — in:** structure a lesson as **Pass 1 (whole overview, uninterrupted)** → **Pass 2 (segmented, with stop-and-check prompts / mini-questions between segments)**. **out:** —
**AC:**
- [ ] A lesson can run in two passes; pass 2 inserts at least one stop-and-check between segments.
- [ ] Pass 1 is uninterrupted; pass 2 is segmented.
**Smoke:** (E2E) run a lesson → pass 1 plays through; pass 2 pauses with a check between segments.

### G4 Oral / spoken comprehension check (reuse the voice stack)
P2 · G · depends: G1
**Why:** Strong, differentiated insight: "**in an oral you know if he knows or doesn't**," whereas AI-written homework makes cheating undetectable. You already have a TTS/voice stack from the avatar — repurpose it from *narration* to *asking the student to explain back*.
**Scope — in:** an optional spoken-response mode where the bot asks the student to explain a concept aloud; capture audio → transcribe → a lightweight rubric check against the objective. **out:** high-stakes grading/proctoring.
**AC:**
- [ ] A check item can be answered by speaking; response is transcribed and evaluated against the objective.
- [ ] Result feeds the same aggregate as G1.
**Smoke:** answer a spoken item → transcript produced → scored against the objective; appears in the teacher aggregate.

### G5 Document/image-analysis activity
P2 · G · depends: A3
**Why:** For history he works from a single powerful image — "Delacroix's *Massacre at Chios* … why? what happened? what is it showing us?" — and "analyzing a graphical document / extracting information" was on his competence list.
**Scope — in:** an activity that shows a topic-relevant image/source and asks guided "what do you see / what does it show / why" questions tied to an analysis competence. **out:** sourcing licensed artwork at scale (use what's in the topic's content/sources; respect licensing).
**AC:**
- [ ] An analysis activity can attach to a topic, posing 2–3 guided questions about an image/source.
- [ ] Responses map to an "analyze a document" competence and feed the aggregate.
**Smoke:** open a topic with a source image → analysis prompts appear; answers recorded against the analysis competence.

---

# EPIC H — Quick-win bug fixes

### H1 Stop / pause / mute narration + music
P1 (do early) · H · depends: —
**Why:** His answer to "most confusing thing?" was blunt: "**how to stop the music … or the voice.**" Smallest possible fix, biggest immediate relief.
**Scope — in:** visible play/pause/stop + mute/volume for both narration and background music; a keyboard shortcut (e.g. space/esc). **out:** —
**AC:**
- [ ] User can stop/pause/mute narration and music at any time from a discoverable control.
- [ ] Control is visible without scrolling during playback.
**Smoke:** (E2E) during playback → click stop → audio halts; mute → silent; control visible in viewport.

---

# EPIC I — Interaction roadmap (future / larger)

*Came from:* his one-sentence summary — "**a storyteller teacher: you give him info, he gives it to students**" — and the gap he named: "there's no real interaction … it's a static website, not an application." These are bigger; break them down when the core above lands.

### I1 Two-way student interaction
P3 · I · depends: G-epic
**Why:** Turn the monologue into a dialogue — students ask, answer, and the bot responds within the lesson.
**Scope:** define the interaction model (student questions + answers + bot responses grounded in the lesson); this is an epic to decompose, not a single ticket.
**AC (epic-level):** [ ] A student can ask a question mid-lesson and get a grounded answer. [ ] Decomposed into implementable tickets before build.

### I2 Source upload + chat-with-sources (RAG) + handwriting OCR
P3 · I · depends: A-epic
**Why:** "Give him a PDF book and chat with him using your sources … like a local app." Plus a powerful accessibility story he told: a dyslexic student's handwriting he couldn't read, where AI photo→text recovered ~90% — OCR for handwriting could be a real differentiator.
**Scope:** RAG over teacher-uploaded sources; OCR for photographed/handwritten work; chat grounded in those sources. Decompose before build.
**AC (epic-level):** [ ] Teacher uploads a source; bot answers grounded in it with citations. [ ] A photographed handwritten page is OCR'd to editable text. [ ] Broken into implementable tickets.

---

# EPIC J — Time-Map (the scrubbable history map; absorbs C + D)

*Came from:* your oldmapsonline.org / runningreality.org vision — a map where you control time, watch regions populate, scrub back and forth, and click a civilisation to open pre-generated stories + old maps + period literature in a left column. This unifies the Time and Space axes into one interface and becomes the product's exploratory surface. It directly answers Alfonso's "it's a static website — there's no real interaction."

**Engine & data decisions baked in (from the research):**
- **Map engine: MapLibre GL JS** (open Mapbox-GL fork), bundled via Vite. NOT Three.js — MapLibre is already WebGL and purpose-built for this; Three.js stays for the avatar/comic lessons (Epic E).
- **Primary time-aware data: OpenHistoricalMap** vector tiles (`https://vtiles.openhistoricalmap.org/`, ODbL / mostly CC0) + its time slider; features carry `start_date` / `end_date`, back to 6000 BCE. **Phase-0 fallback:** the `historical-basemaps` GeoJSON (CC-BY-SA) — nearest-year polygons, simple and offline-capable.
- **Old-map overlays: Allmaps** (open, MIT/GPL) + IIIF collections (David Rumsey, NLS, BL, Leventhal). Store Georeference Annotations; overlay via the Allmaps MapLibre plugin. **Do NOT scrape raw map images** (copyright + georeferencing burden). Verify each collection's licence (Rumsey ≈ CC-BY-NC-SA → fine for education with attribution).
- **Gazetteers / clickable points:** World Historical Gazetteer + Pleiades (CC-BY, ancient world) + Wikidata (CC0, events/people with coords + dates).
- **Literature "from that time and space":** Project Gutenberg / Perseus / Wikisource (public domain / CC), tagged by place + era.
- **Historical images:** Wikimedia Commons + open museum collections (the Met Open Access, Rijksmuseum, NYPL, Library of Congress, Europeana) — mostly CC/PD, always attribute. **Not Encyclopaedia Britannica** (copyrighted — don't scrape/reuse its images). A consistent comic frame (panel borders, captions, light toon filter) is what unifies the mixed source styles.
- **3D models:** embed *existing, professionally-captured* models via Sketchfab (and Smithsonian 3D / museum viewers) with attribution — e.g. the Pompeii House of the Tragic Poet. **Never generate 3D** (inaccurate + costly — see "Not building"). This is the K-5 module.
- **PostGIS:** this epic reverses the earlier "skip PostGIS for v1" call — enable the PostGIS extension in Supabase for spatial-temporal click lookups (Phase 1+). Phase 0 avoids it by joining on a polygon's `polity_id` property.
- **Running Reality:** supplementary source only, and only after its data-reuse licence is confirmed (Phase 3, J-10). You cannot restyle it by embedding — render the data in your own MapLibre UI.

**Epic-level open decisions (confirm before/while building):**
1. **Placement — RESOLVED: hero / main tool.** The Time-Map is the product's home; teachers land here and drill into lessons. The old standalone lesson-creator flow is replaced — lessons are scaffolded *from* a map selection (Epic K, ticket K-7). Route it as the default authenticated landing page.
2. **Launch fidelity.** Discrete-era GeoJSON (Phase 0, simple/fast) vs OHM continuous timeline (Phase 1, richer/heavier). Recommend shipping GeoJSON first.
3. **Era coverage at launch.** Which years to seed — suggest the ancient-spine anchors: ~3500 BCE, 2500 BCE, 1500 BCE, 500 BCE, 1 CE, 500 CE, 1000 CE, plus modern.
4. **Old-map & RR licences** — verify per source before ingesting.

---

## Phase 0 — proof (build first; fully specified)

### J-1 MapLibre map shell + Alpine state
P1 · J · depends: INFRA-1
**Why:** Everything else hangs off a working, state-driven map inside a Livewire view.
**Scope — in:** a Livewire `TimeMap` component rendering a MapLibre GL JS map (Vite-bundled) on a modern base style (free / Natural-Earth-derived, or the MapLibre demo style); an Alpine store holding `{ year, selectedPolity, zoom }`; a `window.__portal` test hook mirroring that state; working pan/zoom. **out:** historical layers (J-2), clicks (J-3).
**Approach:** `npm i maplibre-gl`; init the map in an Alpine `x-init`; keep state in an Alpine store; on change set `window.__portal = { ready:true, year, selectedPolity }` for Dusk.
**AC:**
- [ ] A route/page renders a MapLibre map that pans/zooms with no console errors.
- [ ] Alpine store holds year/selectedPolity/zoom; `window.__portal` mirrors it.
- [ ] `npm run build` succeeds with maplibre-gl bundled.
**Smoke:** `php artisan dusk --filter=TimeMapShell` → canvas present, `window.__portal.ready === true`, no `SEVERE` console logs, screenshot saved. (See conventions → "3D / WebGL verification".)

### J-2 Year slider + discrete-era boundaries + "years-ago" readout
P1 · J · depends: J-1 · folds D1
**Why:** The core "control time, watch regions populate, scrub back and forth" gesture.
**Scope — in:** an Alpine year slider; bundle/host the `historical-basemaps` GeoJSON for the seeded era years; on change, load the nearest-year file and cross-fade the polygon layer; a readout computing "≈ X years ago / ~N generations". **out:** continuous OHM borders (J-5).
**Approach:** store the per-year GeoJSON under `public/` or `storage`; a small PHP `EraService` computes years-ago + generations (Pest-testable) and resolves the nearest available era year; Alpine swaps the MapLibre GeoJSON source with a fill-opacity transition.
**AC:**
- [ ] Moving the slider changes visible borders to the nearest seeded era and cross-fades.
- [ ] Readout updates ("~3,000 years ago ≈ ~120 generations") and is correct for BCE and CE.
- [ ] Polygons animate in/out as they enter/leave their era.
**Smoke:** (unit) `php artisan test --filter=EraService` → years-ago for -1500 ≈ current_year + 1500; generation count computed; nearest-era resolver picks correctly. (E2E) `php artisan dusk --filter=TimeMapSlider` → drag slider → boundary source changes; readout updates; screenshot.

### J-3 Click-region → stories left column
P1 · J · depends: J-2, J-4 · folds C1
**Why:** The payoff — click a civilisation at a year and your pre-generated lessons appear.
**Scope — in:** clicking a polygon reads its `polity_id` / `name`; a Livewire panel queries `stories` where region/polity matches AND era overlaps the current `year`; renders the left column; clicking a story opens/links its lesson (Epic E). **out:** true spatial reverse-geocode (J-6); old-map + literature panels (J-7 / J-8).
**Approach:** Phase-0 lookup joins on the GeoJSON feature's `polity_id` (no PostGIS yet); Livewire action `storiesFor(polityId, year)`; render results with the lesson link.
**AC:**
- [ ] Clicking a region at year Y shows stories whose region matches and era brackets Y.
- [ ] Empty state when none; clicking a story opens/links the lesson.
- [ ] Changing the year, then clicking the same region, updates the list.
**Smoke:** (feature) `Livewire::test(TimeMap::class)->call('storiesFor','rome',-50)->assertSee(...)`. (E2E) `php artisan dusk --filter=TimeMapClickStories` → click region at a year → left column lists matching stories; click one → lesson opens.

### J-4 Seed stories with region + era tags (bridge from A2)
P1 · J · depends: A2
**Why:** J-3 needs content keyed to map regions; this links the ancient-spine topics to polities/eras.
**Scope — in:** ensure each Phase-0 era's headline civilisation has ≥1 `story` carrying `polity_id` / `region` + `era_start` / `era_end`; a one-off importer/seeder from `resources/data/history-topics.json`. **out:** scraping new content.
**AC:**
- [ ] Every seeded era year resolves to ≥1 story for its headline civilisation (Sumer, Egypt, Indus, China, Greece, Rome, …).
- [ ] Stories carry `polity_id` / `region` + era fields matching the GeoJSON polygon ids.
**Smoke:** (feature) `php artisan test --filter=TimeMapSeed` → for each seeded year, `storiesFor(headlinePolity, year)` returns ≥1, and the era brackets the year.

---

## Phase 1 — high-fidelity borders + true spatial click (specified)

### J-5 Swap to OpenHistoricalMap vector tiles + OHM time slider
P2 · J · depends: J-2 · folds C2
**Why:** Continuous, time-accurate, editable borders 6000 BCE → now instead of discrete snapshots.
**Scope — in:** add the OHM vtiles source + an OHM-derived style; wire the OHM time slider (`mbgl-timeslider`) to filter features by `start_date` / `end_date`; keep the J-2 GeoJSON as an offline fallback. **out:** —
**AC:** [ ] Scrubbing filters OHM features live by date; ODbL/OHM attribution shown. [ ] GeoJSON fallback still works offline.
**Smoke:** `php artisan dusk --filter=TimeMapOHM` → set date → feature set changes; attribution visible; no console errors; screenshot.

### J-6 PostGIS spatial-temporal click → story
P2 · J · depends: J-5, A2 · **enables PostGIS**
**Why:** Replace prop-based lookup with real "what was here, then" reverse-geocoding.
**Scope — in:** enable PostGIS in Supabase; `boundaries(geom geometry, valid_from int, valid_to int, polity_id, name)` with a GIST index; on click, `ST_Contains(geom, clickPoint)` AND `valid_from <= year <= valid_to` → polity → stories. **out:** —
**AC:** [ ] Click→story uses a spatial + temporal query; correct polity for the year. [ ] Migration enables PostGIS and creates `boundaries` with a spatial index.
**Smoke:** (feature) seed a boundary for Rome valid -509..476; `php artisan test --filter=SpatialClick` → `ST_Contains` at a Rome point returns Rome for year -50 and the then-current polity for 1000 CE.

---

## Phase 2 — overlays & sources (specified)

### J-7 Old-map overlays via Allmaps + `historical_maps`
P2 · J · depends: J-1 · **decision: per-collection licence**
**Why:** Your "old maps from the region" — done legally, without manual GIS.
**Scope — in:** `historical_maps(iiif_url, georef_annotation jsonb, bbox, year, region/polity_id, source, license, attribution)`; the Allmaps MapLibre plugin warps annotations onto the map; an opacity/toggle control; "maps available for this region/era" surfaced in the left column; a curated starter set (a Nile map, a Mediterranean map, …). **out:** bulk ingest.
**AC:** [ ] A georeferenced historical map overlays aligned with an opacity slider + attribution. [ ] Clicking a region/era lists its available maps.
**Smoke:** `php artisan dusk --filter=TimeMapOldMaps` → toggle overlay → warped map renders aligned; opacity works; attribution shown.

### J-8 Literature panel (`texts`)
P3 · J · depends: J-3
**Why:** Your "literature from that time and space" — and a ready-made document-analysis activity (G5).
**Scope — in:** `texts(title, region/polity_id, era_start, era_end, source_url, license)`; surface period texts (Perseus / Gutenberg / Wikisource) for the clicked region/era in the left column. **out:** hosting full texts.
**AC:** [ ] Clicking a region/era surfaces ≥1 period text with source + licence link.
**Smoke:** (feature) `textsFor('mesopotamia',-2000)` returns a Gilgamesh-era text with attribution.

---

## Phase 3 — flourishes & RR (roadmap; decompose before build)

### J-9 Animated transitions + exploration modes
P3 · J · depends: J-5
**Scope:** tweened population/expansion as the slider runs; deck.gl migration/trade arcs (Silk Road, Roman roads); "Follow a civilisation" auto-animation; swipe-between-two-dates; MapLibre globe projection for the zoom-out moment. Decompose into tickets first.
**AC (epic-level):** [ ] At least one mode (e.g. Follow-a-civilisation) ships with smooth transitions and updates the story panel. [ ] Broken into implementable tickets first.

### J-10 Running Reality data ingestion (gated)
P3 · J · depends: J-6 · **decision: RR data-reuse licence (blocker)**
**Why:** RR's model could enrich `boundaries` / events — *if* the licence permits reuse.
**Scope — in:** confirm the licence first; if cleared, ingest RR GeoJSON/RDF exports into `boundaries` / `events` with attribution. **out:** anything before licence confirmation.
**AC:** [ ] Licence verified + recorded; ingestion proceeds only if reuse is permitted. [ ] Ingested data carries source + attribution.
**Smoke:** n/a until licence cleared; then a feature test asserts ingested boundaries carry RR attribution.

---

# EPIC K — Lesson Composer & Slideshow (modular lessons; replaces "scenes")

> **Detailed, repo-grounded execution spec (built for Haiku 4.5, one ticket at a time):**
> [`epic-k-lesson-composer-plan.md`](epic-k-lesson-composer-plan.md). Dispatch from there — it reconciles
> these tickets with the existing `Lesson`/`Scene`/wizard code and adds K-1b, K-2a, K-8…K-10.

*Came from:* your decision to drop auto-generated "scenes" and let the teacher assemble a lesson from components, then present it as a click-next slideshow. A lesson is now an **ordered list of modules**; the teacher picks and reorders them; each module renders in the composer (edit) and in the player (present). Content is pre-generated and cached from the DB (grounded), then teacher-refined.

**Module library (types + who delivers them):**
- Intro — K-6
- "What do you already know?" pre-quiz — K-4 (new)
- Timeline + Map — Epic J, embedded at a fixed era/region, conforming to the K module contract
- Comic story (timeline overlaid) — Epic E, incl. the day-in-the-life-as-a-peasant narrative composed from real period images
- Image / document analysis — Epic G5
- 3D model viewer (Sketchfab embed) — K-5 (new)
- Quiz (comprehension) — Epic G1
- Conclusion — K-6

Every module implements the **K-1 module contract** so the composer and player treat them uniformly.

### K-1 Lesson + module data model (replaces "scenes")
P1 · K · depends: —
**Why:** The backbone for assembling and presenting modular lessons.
**Scope — in:** Eloquent `lessons` + `lesson_modules(lesson_id, order, type, config jsonb, content_ref)`; a `LessonModule` contract every type implements — `renderEditor()` / `renderSlide()` + `defaultConfig()`; migrate away from any "scenes" structure. **out:** the composer UI (K-2), the player (K-3).
**AC:**
- [ ] A lesson persists an ordered list of typed modules with per-module config.
- [ ] A documented `LessonModule` contract; ≥2 types implement it (Intro + one other).
- [ ] Reordering persists; old "scenes" data (if any) is migrated or cleanly dropped.
**Smoke:** (feature) `php artisan test --filter=LessonModuleModel` → create a lesson with 3 ordered modules, reorder, assert persisted order; assert each `type` resolves to a class implementing the contract.

### K-2 Lesson Composer UI
P1 · K · depends: K-1
**Why:** Teachers assemble lessons by picking + ordering modules.
**Scope — in:** a Livewire composer with a module palette, drag-to-order (Alpine), a per-module config panel, and a live preview; add/remove/reorder/duplicate modules. **out:** —
**AC:**
- [ ] Teacher can add modules from a palette, reorder by drag, configure each, and save.
- [ ] Live preview reflects changes; order matches what the player will show.
**Smoke:** (E2E) `php artisan dusk --filter=LessonComposer` → add Intro + Quiz + Conclusion, drag to reorder, save → reload shows the saved order.

### K-3 Slideshow player (click-next presentation)
P1 · K · depends: K-1
**Why:** How the teacher actually runs the lesson in class.
**Scope — in:** a fullscreen player rendering modules in order; next/prev buttons + keyboard (←/→, space); a progress indicator; the comic-story module shows the timeline as an overlay; the map module stays interactive in-slide. **out:** —
**AC:**
- [ ] Player renders each module full-screen; next/prev + keyboard advance through them in order.
- [ ] Comic-story slides overlay the timeline; map slides remain interactive.
- [ ] Runs from first slide to conclusion with no dead-end (ties to G2).
**Smoke:** (E2E) `php artisan dusk --filter=SlideshowPlayer` → open a 4-module lesson → arrow-key through all four → assert each renders and the conclusion is reachable; screenshot.

### K-4 "What do you already know?" pre-quiz module (new)
P1 · K · depends: K-1, G1
**Why:** Activates prior knowledge and sets a baseline; pairs with the closing check (G1) for a pre/post learning gain.
**Scope — in:** a module posing 2–4 quick questions before the content (open or MCQ), storing responses as the baseline, comparable to the end-of-lesson quiz. **out:** the closing quiz itself (G1).
**AC:**
- [ ] Pre-quiz renders as an early module; responses stored as baseline.
- [ ] Teacher view shows the baseline; the same (or mapped) items appear in the closing quiz for pre/post comparison.
**Smoke:** (feature) `php artisan test --filter=PreQuizModule` → answers stored as baseline; pre/post pairing resolves. (E2E) the module appears before content in the player.

### K-5 3D model viewer module (Sketchfab embed) — replaces splat
P2 · K · depends: K-1 · **decision: per-model licence**
**Why:** Accurate, zero-cost 3D of real sites/artifacts (e.g. the Pompeii House of the Tragic Poet) instead of generating it.
**Scope — in:** a module that embeds a Sketchfab (or Smithsonian 3D) model by id/URL with required attribution rendered; an allowlist of vetted providers/models; lazy-loaded iframe; a caption field. **out:** hosting models ourselves; any 3D generation.
**Approach:** store `{ provider, model_id, title, author, source_url, licence, attribution, caption }` (e.g. the Pompeii *House of the Tragic Poet* by **Arqueomodel3D**, model id `c3ae8f329c49486cb9a7a222a670e3bd`); render the official embed iframe `src="https://sketchfab.com/models/{model_id}/embed"` with `allow="autoplay; fullscreen; xr-spatial-tracking"`, `allowfullscreen`, `web-share`, `loading="lazy"`. **Don't hard-sandbox it** — Sketchfab's WebGL viewer needs scripts + same-origin, so a bare `sandbox` attribute breaks the embed; if you sandbox, include `allow-scripts allow-same-origin allow-popups`. Render Sketchfab's required credit beneath the viewer (model link + author + "on Sketchfab") and store the per-model licence — prefer CC / explicitly-embeddable models for a commercial product.
**AC:**
- [ ] A vetted Sketchfab model renders in-slide and in the composer preview, with visible attribution (author + source + licence).
- [ ] Only allowlisted providers/models embed; licence stored per model.
- [ ] The iframe lazy-loads and doesn't block the slide.
**Smoke:** (E2E) `php artisan dusk --filter=ThreeDModule` → add the Pompeii model → renders in an iframe with the attribution line. (feature) a non-allowlisted provider is rejected.

### K-6 Intro & Conclusion modules
P2 · K · depends: K-1
**Why:** The standard framing bookends of every lesson.
**Scope — in:** a simple Intro (title, era/region, learning goals from B3) and Conclusion (recap + what's next) module, both implementing the contract. **out:** —
**AC:** [ ] Both render in composer + player; Intro pulls the lesson's objectives (B3); Conclusion shows a recap.
**Smoke:** (feature) `php artisan test --filter=FramingModules` → Intro renders objectives; Conclusion renders a recap.

### K-7 Scaffold a lesson from a map selection (J → K bridge)
P1 · K · depends: J-3, K-1, A2
**Why:** This *is* the new "generation" flow — selecting an era/region on the hero map produces a ready-to-edit lesson, not a blank page.
**Scope — in:** from a map selection (polity + year), create a **draft lesson** pre-populated with the default module order (intro → pre-quiz → timeline+map → comic story → quiz → conclusion), each filled with **pre-generated, cached** content for that era/region from the DB; the teacher then edits/reorders in the composer. **out:** live per-click generation (explicitly not this).
**Approach:** a `LessonScaffolder` service — given (polity, year), pull matching `stories`/topic content + competences + linked images/3D, assemble default modules, persist a draft; reuse cached generation where it exists, enqueue a background job where it doesn't, surface a "refine" step (B2 thumbs).
**AC:**
- [ ] "Build a lesson here" from the map creates a draft with the default modules pre-filled from cached DB content for that era/region.
- [ ] No live free-form generation on the click path; missing content is queued, not blocked-on.
- [ ] The draft opens in the composer (K-2) for editing.
**Smoke:** (feature) `php artisan test --filter=LessonScaffolder` → scaffold for (rome, -50) → a draft lesson with the default ordered modules and non-empty content for each from the DB; assert no synchronous generation call on the request path.

---

### Not building (decided against, with the reason)
- **Dancing/walking avatar cinematics** — "would distract; I'd lose time watching the dancing." (E1 removes the body.)
- **Realistic, lip-synced humanoid historical figures** — "this is not Napoleon"; uncanny + costly. Lean into the non-human "knowledge robot" instead.
- **Per-lesson generated skybox backgrounds as the default path** — cost. Compose from the asset library (E3); keep generation as an opt-in, budget-gated extra (E4).
- **Free-form "any topic" generation** — vague, un-enrichable. Curated list only (A1).
- **Generative 3D / Gaussian-splat "worlds"** — dropped. Costly, GPU-heavy, still rough for complex scenes, and (the dealbreaker for a *history* product) it invents anachronistic material culture no matter how much metadata you feed it. Embed real captured models instead (K-5); reserve splatting for capture of real sites/artifacts only, if ever.
- **Live, free-form generation per map click** — reintroduces the "too general / vague" problem and per-click cost. Content is pre-generated, cached, and teacher-refined (Epic K + B2).