# Restore the scene configurator + Keynote-style "Add scene" picker

**Date:** 2026-06-19
**Status:** Approved (design) — pending spec review → writing-plans
**Owner:** Bart

## Context

Wizard step 3 was temporarily replaced by a `LessonComposer` Livewire component backed by a
new `lesson_modules` table (Epic K, commits `23d8cc9` / `968e2be`). That composer is a flat card
list ("Build Your Lesson / Add, arrange, and configure lesson modules") that **regressed** the
existing, far richer **scene configurator** (`Step3SceneConfigurator`): a fullscreen 3D preview, a
draggable per-scene Inspector, a drag-reorder timeline, per-scene generation, game/quiz config, and
a music strip.

The whole wizard is otherwise scene-based end to end (step 2 generates scenes, step 4 previews
scenes). Only step 3 was diverted.

**Decision:** restore the scene configurator and bring across the one thing worth keeping from the
composer — the **Keynote-style "Add" picker modal** — wired to the configurator's existing
`addScene()`. Remove the `lesson_modules` / `LessonComposer` code.

### Locked decisions
- **Picker tiles:** all five scene types — Story (`narration`), Quiz (`game`/`quiz`), Strategy game
  (`game`/`strategy`), Debate (`game`/`debate`), Map (`map`).
- **K-1/K-2 module code:** remove now (not left dormant).

## Current state (what exists)

- `app/Livewire/Wizard/Step3SceneConfigurator.php` — intact; `addScene(kind, gameType)` already
  supports `narration` | `game`(quiz/strategy/debate) | `map`, plus `reorder`, inspector, generation.
- `resources/views/components/lesson/timeline.blade.php` — the editable scene rail; **already has an
  "Add scene" dropdown** (lines ~24–54) with the five `addScene(...)` calls. This is the affordance
  the picker replaces.
- `resources/views/livewire/lesson-wizard.blade.php` — step 3 currently renders
  `<livewire:lesson-composer>` (the only line that diverted the wizard).
- The composer's picker modal + schematic thumbnails (`resources/views/lessons/modules/picker-thumb.blade.php`)
  are the visual we keep; everything else under the module system is removed.

## Design

### 1. Restore step 3
In `lesson-wizard.blade.php`, step 3 renders `<livewire:wizard.step3-scene-configurator :lesson="$lesson" … />`
again. The composer disappears; the real configurator (inspector, 3D, drag-reorder) returns.

### 2. Replace the timeline's add-dropdown with the picker modal
- **State:** add `public bool $addSceneOpen = false;` to `Step3SceneConfigurator`. `addScene()` sets it
  back to `false` after creating a scene (so the modal closes on pick). `addScene()` logic is unchanged.
- **Trigger:** in `timeline.blade.php`, replace the existing add-dropdown markup with a single "Add"
  button → `wire:click="$set('addSceneOpen', true)"`. (`wire:click` works here — the timeline is a
  Blade component rendered inside the Livewire configurator, same as today's `addScene` calls.)
- **Modal:** add the picker modal to `step3-scene-configurator.blade.php`, styled to match the
  configurator's dark/amber chrome (slate borders, `bg-base-300`, amber accents — not the light
  DaisyUI base the composer used). Open state driven by `$addSceneOpen` (Blade `modal-open`).
- **Tiles → scenes** (each tile is a `wire:click` to the existing method):

  | Tile | Call |
  |---|---|
  | Story | `addScene('narration')` |
  | Quiz | `addScene('game', 'quiz')` |
  | Strategy game | `addScene('game', 'strategy')` |
  | Debate | `addScene('game', 'debate')` |
  | Map | `addScene('map')` |

### 3. Schematic thumbnails (relocated + adapted to scene kinds)
Create `resources/views/components/lesson/scene-thumb.blade.php` (anonymous Blade component taking a
`kind` + optional `gameType`) holding the schematic mini-previews, salvaged from `picker-thumb`:
Story (narrator dot + text lines), Quiz (question + 2×2 pills, one correct), Map (timeline pin + map
block) carry over; **add** Strategy (board/grid glyph) and Debate (two facing speech bubbles). The
old `picker-thumb.blade.php` is then deleted with the rest of the module views.

### 4. Remove the `lesson_modules` / composer system
- **Delete:** `app/Livewire/LessonComposer.php`; `resources/views/livewire/lesson-composer.blade.php`;
  `resources/views/livewire/lesson-composer-card.blade.php`; `app/Models/LessonModule.php`;
  `app/Enums/ModuleType.php`; `app/Lessons/Modules/` (whole dir); `resources/views/lessons/modules/`
  (whole dir, after salvaging schematics into `scene-thumb`); `tests/Feature/Lessons/LessonComposerTest.php`;
  `tests/Feature/Lessons/LessonModuleModelTest.php`; `database/migrations/2026_06_18_000001_create_lesson_modules_table.php`.
- **Edit:** `app/Models/Lesson.php` — remove `modules()` + `reorderModules()`. `routes/web.php` —
  remove the `lessons.composer` route. `tests/Feature/LessonWizardRoutesTest.php` — drop the
  `'composer'` dataset row from the non-numeric-id guard.
- **Add:** a `…_drop_lesson_modules_table.php` migration using `Schema::dropIfExists('lesson_modules')`
  (the table was already migrated on dev — e.g. lesson 3 has backfilled rows — so it must be dropped,
  not just have its create-migration deleted; `dropIfExists` keeps fresh installs safe).

## Files summary

- **Edit:** `lesson-wizard.blade.php`, `Step3SceneConfigurator.php`, `step3-scene-configurator.blade.php`,
  `components/lesson/timeline.blade.php`, `app/Models/Lesson.php`, `routes/web.php`,
  `tests/Feature/LessonWizardRoutesTest.php`
- **Add:** `components/lesson/scene-thumb.blade.php`, `…_drop_lesson_modules_table.php`
- **Delete:** the module system files listed in §4

## Testing

- **Reactivated:** `tests/Feature/Wizard/Step3SceneConfiguratorTest.php` already covers `addScene`
  (narration/game/map) + reorder; it exercises the restored screen. Run it.
- **Wizard wiring:** a feature test asserting `…/wizard?step=3` renders the configurator (e.g. sees
  "Inspector") and **not** the composer ("Build Your Lesson").
- **Picker:** a Livewire test — `$set('addSceneOpen', true)` then `addScene('game','strategy')` creates
  a `game`/`strategy` scene and closes the modal. (Thumbnails are visual; no assertion.)
- **Removed:** the two `tests/Feature/Lessons/*` tests go away with their subjects.
- Full `php artisan test` green; `./vendor/bin/pint`; `npm run build`.

## Risks & notes

- **Dropping `lesson_modules` deletes backfilled rows** (e.g. lesson 3's). That's intended — scenes are
  the source of truth; the backfill was a one-way copy and scenes were never touched.
- **K-1/K-2 were committed and pushed to `origin/main`.** This is a forward cleanup commit (delete +
  drop migration), not a history rewrite.
- `.claude/epic-k-lesson-composer-plan.md` and the Epic K backlog note are now **superseded**; annotate
  or remove them as a minor follow-up (not blocking).
- Leave the configurator's existing map/ink and 3D code untouched (parallel Time-Map work).

## Out of scope

- Any new scene kind beyond the existing five.
- Touching step 2 (generation) or step 4 (preview).
- Reworking the inspector, 3D stage, or timeline drag (already work).
