# Story Configuration & Narrative Spine — Design Spec

**Date:** 2026-06-19
**Status:** Approved (ready for implementation plan)
**Part:** Spec 1 of 3 in the "script quality system"

## Where this sits

The script-quality work is decomposed into three independently shippable specs, built in order:

1. **Story Configuration & Narrative Spine** ← *this document*
2. **Quality Grader & Self-Refine** — a strong judge model scores each scene against a rubric + cross-checks claims against corpus facts; weak scenes auto-revise. *(Out of scope here.)*
3. **Self-Improvement Accumulator** — exemplar bank (teacher-published high-scoring scenes become few-shot examples) + prompt evolution from accumulated critiques. *(Out of scope here.)*

Spec 1 gives the teacher a way to choose a story arc (and a real historical protagonist) that drives the lesson's dramatic structure, folds game configuration into the same step paired to the arc, and turns the old "Generate" step into a locked overlay on Configure.

---

## Goal

Let a teacher pick a **narrative arc** that shapes the generated lesson's dramatic structure; for the Hero's-journey arc, pick a **corpus-backed historical protagonist**; configure the **game** in the same step, pre-paired to the chosen arc; and restructure the wizard so generation runs as a **locked overlay** on the Configure step instead of a separate Generate step.

## Architecture (2-3 sentences)

A new `NarrativeFramework` enum + a `StorySpine` value object turn the teacher's arc choice into a concrete beat template, an And-But-Therefore causality rule, a game-placement hint, and a protagonist clause, all woven into `LessonOutlinePrompt`. The arc choice, protagonist, and (relocated) game settings live on a new wizard Step 2 ("Story"); the old Generate step disappears, its trigger moving to the Story step's primary button and its progress UI becoming a status-driven overlay that locks the Configure step until the first generation pass completes. Branching is supported in a "lite" single-reconverging form via three nullable `Scene` columns and skip-aware playback in the existing flat scene queue.

## Tech stack

Laravel 12, Livewire 3, Alpine.js, Tailwind v4 / DaisyUI 5, Pest (Postgres), Playwright. Corpus reads via the read-only `pgsql_corpus` connection. Wikidata via the existing `WikidataFiguresService`.

---

## Section 1 — Wizard flow & the Story step

### New flow (still 4 steps)

```
Settings → Story (new) → Configure (locked overlay during gen) → Preview
```

- The old **Generate step is removed**. Its trigger moves to the Story step's **"Generate lesson →"** button; its progress UI becomes the overlay on Configure.
- `LessonWizard` step mapping becomes: 1 Settings, 2 Story, 3 Configure, 4 Preview (still clamped 1-4; `wizard_step` persistence unchanged in shape).

### Story step layout

1. **Choose your story arc** — a Keynote-style card picker (the existing add-scene modal pattern). Five cards, no "Auto":
   - **Hero's journey** (default, pre-selected)
   - **In medias res**
   - **Rise & fall**
   - **Mystery**
   - **Branching story**
2. **Choose your hero** — shown only when Hero's journey is selected (so, by default). Corpus-backed picker (Section 3). Free-text fallback.
3. **Choose your game** — relocated out of Settings. Pre-selects the arc's paired game (override allowed). Per-type settings appear inline:
   - **Quiz checkpoint:** question count + timing toggle (**During** / **After**)
   - **Strategy:** which strategy game · team count · split-into-N segments
   - **Debate:** no extra fields (sides auto-framed from the lesson's central tension)
4. **Generate lesson →** — kicks off the pipeline and navigates to Configure.

When **Branching story** is selected, the hero panel is replaced by a one-line note ("the AI adds one choice point mid-lesson; you can edit both paths in Configure"); no extra config — the diamond is auto-placed.

Settings (Step 1) sheds its game section (topic, grade, tone, details, duration, image style remain).

### Locked Configure (Step 3)

- Advancing with "Generate lesson →" drops the teacher on Configure immediately, behind a **translucent overlay**: scene tiles populate live behind it while editing/inspector stay disabled.
- Overlay copy: "Generating scene X of N…" (count = scenes with `status='ready'` vs total).
- The lock keys off lesson `status`: held while `Outlining` / `ScenesGenerating`, released at `ScenesReady`.
- On `Failed`, the overlay swaps the spinner for the error + a **Retry** button (no infinite spin).

---

## Section 2 — Narrative spine (how each arc drives the outline)

The chosen arc is handed to `LessonOutlinePrompt` as a **beat template** the model must map its scenes onto. Beats are **dramatic functions, not a fixed scene count** — the model distributes its `target_narration_scenes` across the spine, expanding middle beats (Trials, Ascent, Clues) for longer lessons.

### Beat templates & game placement

| Arc (`narrative_framework`) | Beat spine | Paired game lands at | Default game |
|---|---|---|---|
| `heros_journey` (default) | Before → Call → Trials → **Crisis** → Return | Crisis | quiz |
| `in_medias_res` | Cold open → Rewind → Build → **Catch-up** → Aftermath | Catch-up | quiz |
| `rise_and_fall` | Origin → Ascent → **Peak** → Cracks → Collapse | Peak | strategy |
| `mystery` | Puzzle → Clues → Twist → **Reveal** → Meaning | Reveal | quiz (or debate) |
| `branching` | Setup → Approach → **Choice (A/B)** → Rejoin → End | the choice *is* the game | none |

### Rules layered on every arc

1. **And-But-Therefore causality.** Each beat must follow the last by *but* or *therefore* — never *and-then*. The single biggest engagement lever, expressed as one instruction.
2. **Protagonist through-line.** The hero is the spine's subject (Hero's journey) or a focal figure (other arcs, if chosen). When set, the protagonist's name + on-demand corpus blurb seed a protagonist clause.
3. **Game weaving.** The paired game's `kind=game` scene is placed at the tension beat and seeded from the arc's central conflict (e.g., a Debate's two sides = the arc's two interpretations).

### Implementation

- **`App\Enums\NarrativeFramework`** — `heros_journey` (default), `in_medias_res`, `rise_and_fall`, `mystery`, `branching`.
- **`App\Services\StorySpine`** — a value object: given a `NarrativeFramework`, returns `{ beatTemplate: list<Beat>, abtRule: string, gamePlacementBeat: string, protagonistClause: string }`. One focused file; no logic scattered into prompts.
- **`LessonOutlinePrompt::system()` / `::user()`** weave the spine + protagonist + game placement in. For the four linear arcs the output JSON shape is **unchanged** (`scene_briefs[]` with `phase`/`beat`) — the spine constrains *how* the model fills them, so the existing `SceneScriptPrompt` inherits the better structure for free. The `branching` arc additionally emits two consecutive option briefs (Section 3).

---

## Section 3 — Data model & corpus hero retrieval

### `Lesson` — one migration, three columns

`historical_figure` stays as the **avatar/narrator persona** (it renders as the narrator's name and defaults to "Narrator") — it is **not** overloaded for the protagonist.

| Column | Type | Default | Purpose |
|---|---|---|---|
| `narrative_framework` | string, after `game_type` | `'heros_journey'` | the chosen arc |
| `protagonist_qid` | string, nullable | — | corpus `figures.qid` link (null when free-text) |
| `protagonist_name` | string, nullable | — | hero display name + prompt input; also the free-text fallback value |

### `Scene` — one migration, three nullable columns (null = ordinary linear scene)

| Column | Type | Purpose |
|---|---|---|
| `branch_group` | unsignedInteger, nullable | groups the parallel option scenes of one diamond |
| `branch_role` | string, nullable | `option_a` / `option_b` |
| `branch_choice_label` | string, nullable | button text for that path ("Cross the Rubicon") |

Branch scenes generate exactly like normal scenes (own script/image/audio jobs). Both migrations are backward compatible: existing lessons become `heros_journey`, existing scenes stay linear, nothing regenerates.

### Corpus hero retrieval

New read-only model **`App\Models\Corpus\Figure`** over `public.figures` (mirrors `Article`/`Topic`; same `resilient()` reconnect for the pooler). Picker query, in priority order:

1. **By topic** — `where parent_qid = lesson.topic_id` ranked by `sitelinks` desc. If the topic *is itself* a figure, pre-select it.
2. **By region + era** — fallback: `region` match with era overlap (`era_start ≤ end AND era_end ≥ start`), ranked by fame (mirrors `Article::forRegionYear`).
3. **Free text** — nothing found → teacher types a name (`protagonist_name` set, `protagonist_qid` null).

At generation, `BuildLessonOutline` reads the figure's `summary` on demand via `Corpus\Figure::resilient(fn () => Figure::find($qid))` to enrich the protagonist clause — graceful: corpus unreachable or qid null → fall back to the name. No denormalized blurb column (DRY, consistent with how the pipeline already reads corpus for map blocks).

### Prerequisite — populate `public.figures` (Task 0)

`public.figures` is currently **empty (0 rows)** — without population the picker always falls back to free-text.

- **Data source confirmed:** identical stack to oldmapsonline TimeMap. **Cliopatria** supplies polities/territories + Wikidata QIDs + era spans (already this project's border source); **Wikidata** supplies the rulers/people, queried per polity QID and era-bracketed. No new dataset or scraping.
- **Mechanism already exists:** `app/Console/Commands/BuildTopics.php` + `app/Services/WikidataFiguresService.php` were written to mirror oldmapsonline's Rulers/People layers.
- **Action:** run `BuildTopics` **offline** (the corpus is read-only from the app — no request-path writes) to populate figures for `is_publishable` polities; verify `era_start`/`era_end` + `region_label` land. Because the topic picker only offers `is_publishable` polities, this coverage matches exactly the topics a teacher can pick.

---

## Section 4 — Player & configurator changes

### Student player — branching playback

The player's `_sceneQueue` stays a flat sequential array; branching adds skip-awareness, not a rewrite.

- `resources/views/lesson/player.blade.php` carries `branch_group`, `branch_role`, `branch_choice_label` into the scene payload (same pattern as `scene_view`).
- New player state `_choices = {}` (branch_group → chosen role).
- Before playing a scene with a `branch_role`: if its group has **no** recorded choice → render the **choice overlay** (two buttons from the group's two option labels) and halt auto-advance; if the group **is** decided → play only if this scene's role matches, else skip to the next scene.
- On button click → record `_choices[group] = role`, hide overlay, play the chosen option; the unchosen option is skipped; the next linear (Rejoin) scene plays normally.
- The choice is a real user tap, which satisfies the audio autoplay gate for the following scene — branching sidesteps the silent-playback problem rather than worsening it.

### Configurator — locked overlay + branch tiles

- `Step3SceneConfigurator`: computed `isLocked = status ∈ {Outlining, ScenesGenerating}`. The blade wraps timeline + inspector; when locked, a translucent overlay (spinner + "Generating scene X of N…") blocks interaction. `wire:poll` refreshes status while locked; at `ScenesReady` the overlay clears and editing enables; `Failed` → error + Retry.
- Branch scenes render as normal tiles with a small **"Path A/B" badge** + the choice label. No branch-logic authoring in v1 — the AI places it; the teacher edits each path's content like any scene.

---

## Testing strategy (TDD — Pest + Playwright)

| Layer | Tests |
|---|---|
| `StorySpine` / `NarrativeFramework` | unit — each framework returns the correct beat template, ABT rule, game-placement beat, protagonist clause |
| `LessonOutlinePrompt` | unit — prompt text includes the chosen spine + protagonist + game placement (string asserts, like existing `SceneScriptPrompt` tests) |
| `Corpus\Figure` scopes | integration — `forTopic` (parent_qid), `forRegionEra` (overlap), fame ranking — against a seeded local figures fixture |
| `BuildLessonOutline` branching | feature — a branching outline JSON creates scenes with correct `branch_group`/`branch_role`/`branch_choice_label` |
| Step 2 Story Livewire | framework select sets `narrative_framework`; hero select sets `protagonist_*`; game pairing; "Generate" dispatches `BuildLessonOutline` |
| Step 3 lock | `isLocked` true while generating, false at `ScenesReady` |
| Branch skip logic | **isolated/deterministic** JS test of the queue skip (not a full Playwright playthrough — avoids autoplay-gate flakiness) |
| Playwright | Story step happy path → land on locked Configure → overlay clears when ready |

---

## Components & files (new / changed)

**New**
- `app/Enums/NarrativeFramework.php`
- `app/Services/StorySpine.php`
- `app/Models/Corpus/Figure.php`
- `app/Livewire/Wizard/Step2Story.php` + `resources/views/livewire/wizard/step2-story.blade.php`
- migrations: `lessons` (+3 cols), `scenes` (+3 cols)

**Changed**
- `app/Livewire/LessonWizard.php` — step mapping (Story replaces Generate)
- `app/Livewire/Wizard/Step1Settings.php` (+ view) — remove the game section
- `app/Livewire/Wizard/Step3SceneConfigurator.php` (+ view) — `isLocked` overlay, branch tile badges
- `app/Services/LessonOutlinePrompt.php` — weave spine + protagonist + game placement
- `app/Jobs/BuildLessonOutline.php` — read protagonist blurb; map branch briefs → scene columns
- `app/Models/Lesson.php`, `app/Models/Scene.php` — fillable + casts
- `resources/js/lesson-player.js`, `resources/views/lesson/player.blade.php` — branch payload + choice overlay + skip-aware queue

**Removed / repurposed**
- `app/Livewire/Wizard/Step2Generate.php` (+ view) — trigger moves to the Story CTA; progress UI moves to the Configure overlay

---

## Non-goals (explicitly out of scope for Spec 1)

- The quality grader, rubric, and self-refine loop (Spec 2).
- The exemplar bank / prompt evolution (Spec 3).
- Full branching trees, multi-level branches, divergent endings (a later spec).
- Live Wikidata calls in the request path (figures population is offline only).
- Teacher authoring of branch logic / branch placement (AI-placed in v1).
- Grade-band adaptation of the arc structure (vocabulary already adapts via existing tone/grade handling).
