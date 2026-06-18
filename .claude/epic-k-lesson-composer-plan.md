# Epic K — Lesson Composer & Slideshow — Haiku 4.5 Execution Spec

> **What this is.** A refined, repo-grounded rewrite of the original "Codex build plan" for the
> mobile-first lesson creation experience, re-scoped to **Epic K** in [`.claude/backlog.md`](backlog.md)
> (tickets K-1…K-7) and the **actual codebase**. It is optimized to be executed by **Claude Haiku 4.5**
> running one ticket at a time.
>
> **Why it differs from the Codex plan.** The Codex plan was written blind. This repo already has a
> `Lesson` model, a 4-step `LessonWizard`, a `Scene` system (the current "modules"), `QuizQuestion`,
> `StrategyGame`, a `LessonStatus` enum, named routes, a Pest harness, and `sortablejs` installed.
> Building the Codex plan literally would create a **parallel, duplicate stack**. This spec instead
> *extends what exists*. Read §1 and §2 before any ticket.

---

## 0. How Haiku must execute this (read first, every dispatch)

You (Haiku) will be dispatched **one ticket at a time**. For each ticket:

1. **Read before you write.** Open this file's §1, §2, §3, then the exact files named in the ticket's
   *Files* block. Do **not** infer structure — it is given to you. Do **not** read the whole repo.
2. **Reuse, never recreate.** If §1 says a model/route/component exists, extend it. Creating a second
   `Lesson` table, a second wizard, or a second quiz system is a **failure**, not progress.
3. **Smallest change that satisfies the AC.** No speculative abstractions (YAGNI). No refactors outside
   the ticket's *Files* block.
4. **Fill the provided shapes.** Migrations, the `LessonModuleType` contract, and component signatures
   are scaffolded in §3. Copy them; fill the body. Don't redesign them.
5. **Run the Smoke block as real commands** and paste the actual output. Then run `./vendor/bin/pint`.
   Run `npm run build` only if you touched Blade/Alpine/Tailwind/JS.
6. **STOP and ask** (do not guess) if: a Smoke test fails twice for a reason you can't localize; a
   ticket conflicts with code you find; or a `STOP-if` note in the ticket fires. A wrong guess that
   ships is worse than a question.
7. **Definition of Done** (§6) applies to every ticket — strict types, named routes, `__()` strings,
   DaisyUI components, immutable updates, no `dd()`/`var_dump` left behind.

**One ticket = one PR-sized change.** Keep context small. Don't pull future tickets forward.

---

## 1. What already exists — REUSE THIS (do not rebuild)

| Concern | It already exists as | Implication for Epic K |
|---|---|---|
| Lesson entity | `App\Models\Lesson` (`app/Models/Lesson.php`), table `lessons`, 30+ migrations, soft-deletes, `lesson_code`, `wizard_step`, relations `scenes()`, `quizQuestions()`, `strategyGame()`, `teams()`, `studentProgress()` | **Add columns only.** Never create a new `lessons` table. |
| The 4-step creation flow | `App\Livewire\LessonWizard` + `Wizard\Step1Settings`, `Step2Generate`, `Step3SceneConfigurator`, `Step4Preview`. Routes `lessons.create`, `lessons.wizard`, `lessons.show`. Step persisted to `lessons.wizard_step`. | The Codex "Step 1 Class Setup / Step 2 Settings / Step 3 Generation" **already map** to Step1Settings / Step2Generate. **The Composer (K-2) replaces Step3SceneConfigurator.** Do not build a new wizard shell. |
| "Modules" today | `App\Models\Scene` (table `scenes`): `kind`, `order`, `config` (jsonb), `script_segment`, per-lesson ordered, `(lesson_id, order)` unique. `Scene::insertDefaultMapBlock()` shows the two-phase reorder shift pattern. | `Scene` **is** the predecessor of `lesson_modules`. K-1 generalizes it. Transition is **additive + backfill** (see §2 decision D2), not a risky drop. |
| Quiz | `App\Models\QuizQuestion` (table `quiz_questions`): `question`, `options` json, `correct_answer`, `order`, `lesson_id`. | The end-of-lesson comprehension quiz (Epic G1) keeps using `QuizQuestion`. A *module-native* MCQ stores its content in the module `config` (§2 D4). |
| Strategy game | `App\Models\StrategyGame` + `lesson.strategy_game_id`, `Scene.strategy_game_id`, `resources/views/components/wizard/game-picker.blade.php` | The `strategy_game` module references an existing `StrategyGame` row via `content_ref`. Don't reinvent it. |
| Status | `App\Enums\LessonStatus` — already has `Draft, Generating, Ready, Failed, Published, Previewable, ScenesReady, …` | Reuse. Map Codex's `ready_for_review → Previewable`, `generation_failed → Failed`. Add **only** `Archived` if a ticket needs it. |
| Student player | `App\Http\Controllers\LessonPlayerController` + route `lesson.play` (`/lesson/{lessonCode}`, public). | The K-3 slideshow player evolves from / sits beside this. Reuse the public-by-code pattern. |
| Progress UI | `App\Livewire\LessonStatus`, jobs `BuildLessonOutline`, `GenerateScene*`, `LessonStatus` enum | Codex "Step 3 Generation Progress" already exists. K-7 scaffolder feeds it; don't build a new progress screen. |
| Drag-and-drop | **`sortablejs@^1.15.7` is already in `package.json`.** | K-2 uses the installed SortableJS. Do **not** add a new DnD library. |
| Styling | DaisyUI 5, active theme `learningportal`; brand utilities `.lp-grain`, `.lp-bg-card`, etc. (see `CLAUDE.md` "UI Components"). | The Codex line "do not copy visual style" is **void here.** The DaisyUI `learningportal` theme **is** the design system. DaisyUI-first; never raw Tailwind color utilities. |
| Tests | Pest (`composer test` → `php artisan test`). Livewire testable via `Livewire::test()`. Dusk only if installed. | Smoke blocks below prefer **Pest feature + `Livewire::test`**. Use Dusk only where noted *and* if `php artisan dusk` exists; otherwise assert via Livewire/feature tests. |
| i18n | Laravel `__()` + lang files (project DoD). | All new teacher/student-facing strings go through `__()`. No hardcoded English in Blade/Livewire. |

---

## 2. Reconciliation decisions (Codex plan → this repo) — these are FINAL, do not re-litigate

- **D1 — No new `lessons` table.** Use `App\Models\Lesson`. Epic K adds at most a few nullable columns
  (see K-1). Class-name/subject/grade/topic/period/region from the Codex "Step 1" already exist as
  `lessons` columns (`subject`, `grade_level`, `topic`, `region`, `era`, `details`, `historical_figure`)
  or are set in `Step1Settings`. If a Codex field has no column, add it as nullable in K-1 — don't fork the model.

- **D2 — `lesson_modules` is additive, then cuts over.** Create `lesson_modules` (K-1) **alongside**
  `scenes`. Backfill existing `scenes` → `lesson_modules` in the same migration. The player keeps reading
  `scenes` until K-3 flips to `lesson_modules`. Full `scenes` removal is its own later ticket (**K-1b**),
  gated on K-3 shipping. This avoids breaking the live generation pipeline.

- **D3 — Module content lives in `config` (jsonb), not `content_json`.** Mirror `Scene.config`. The
  column is named **`config`** for consistency with `Scene`. (Codex's `content_json` name is rejected.)
  An optional **`content_ref`** string points to a heavy related row when needed (e.g. a `strategy_games`
  id, or a cached story id) — exactly like `Scene.strategy_game_id`.

- **D4 — Quiz duplication rule.** *Module-native* quiz (`quiz_mcq`, `quiz_image`, `quiz_map`) stores its
  question/answers/feedback **inline in `config`** (matches the Codex JSON examples — simplest for the
  composer). The separate `QuizQuestion` table remains the home of the **end-of-lesson G1 comprehension
  quiz**. Do not migrate `QuizQuestion` into modules; do not duplicate G1 into a module.

- **D5 — Statuses.** Reuse `LessonStatus`. Module status is a plain string column on `lesson_modules`
  with values `ready | needs_review | missing_content | generation_failed` (Codex's set). Lesson
  `ready_for_review` → use existing `LessonStatus::Previewable`. Add `LessonStatus::Archived` only when
  a ticket explicitly needs archive (K-9 export/library is fine without it — defer).

- **D6 — One Composer, not a second wizard.** K-2 Composer **replaces** `Step3SceneConfigurator` inside
  the existing `LessonWizard`. Keep the wizard shell, step persistence, and routes. The Composer may also
  be reachable standalone at `lessons.composer` for editing an existing draft.

- **D7 — Mobile-first is a constraint, not a rewrite.** Every Composer/Player view must work at 390px:
  one column, sticky bottom action bar, DaisyUI `card`/`btn`/`modal`/`drawer`. Drag handle **plus**
  move-up/move-down/duplicate/delete buttons on every card (drag is the enhancement, buttons are the
  guarantee). This is baked into K-2 / K-2a, not a separate redesign.

- **D8 — "Build from scratch" vs "Scaffold from map."** Two entry points, both produce a draft of
  `lesson_modules`: (a) the map → lesson scaffolder (K-7, the primary "generation" path, pre-generated
  cached content) and (b) a blank draft the teacher fills from the palette (K-2). No live free-form
  per-click generation (backlog "Not building").

---

## 3. Canonical data model & contract (copy these shapes)

### 3.1 Migration — `lesson_modules` (K-1)

```php
// database/migrations/2026_06_18_000001_create_lesson_modules_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // App\Enums\ModuleType value
            $table->string('title')->nullable();
            $table->unsignedInteger('order')->default(0);
            // Match the column TYPE used by scenes.config for cross-driver consistency
            // (jsonb on pgsql). Open the scenes migration and mirror it exactly.
            $table->json('config')->nullable();
            $table->string('content_ref')->nullable();          // optional pointer to a heavy row
            $table->unsignedInteger('estimated_duration_seconds')->default(0);
            $table->string('status')->default('ready');         // ready|needs_review|missing_content|generation_failed
            $table->timestamps();
            $table->unique(['lesson_id', 'order']);
        });

        // D2 backfill: copy existing scenes into lesson_modules so nothing is lost.
        // Map scenes.kind -> ModuleType (see the mapping table in K-1). Keep scenes intact.
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_modules');
    }
};
```

> **Reorder gotcha (reuse the existing pattern):** `(lesson_id, order)` is unique. To reorder, use the
> **two-phase shift** already proven in `Scene::insertDefaultMapBlock()` (negate orders, then reassign).
> Copy that approach; don't invent a new one.

### 3.2 Enum — `App\Enums\ModuleType`

```php
declare(strict_types=1);
namespace App\Enums;

enum ModuleType: string
{
    // MVP set (K-1, K-4, K-6)
    case TitleBlock        = 'title_block';
    case Intro             = 'intro';
    case PriorKnowledge    = 'prior_knowledge';   // "What do you already know?" (K-4)
    case StoryBlock        = 'story_block';
    case QuizMcq           = 'quiz_mcq';
    case Reflection        = 'reflection';
    case Conclusion        = 'conclusion';
    // Richer set (later phases)
    case TimelineMap       = 'timeline_map';      // Epic J embed
    case QuizImage         = 'quiz_image';
    case QuizMap           = 'quiz_map';
    case HotspotImage      = 'hotspot_image';
    case StrategyGame      = 'strategy_game';     // references StrategyGame via content_ref
    case MapChallenge      = 'map_challenge';
    case ThreeDModel       = 'three_d_model';     // Sketchfab embed (K-5)
    case ImageAnalysis     = 'image_analysis';    // Epic G5
    case TeacherInstruction= 'teacher_instruction';
    case StudentTask       = 'student_task';

    public function label(): string { /* return __() teacher-facing label */ }
}
```

### 3.3 Contract — `App\Lessons\Modules\LessonModuleType`

Every module type implements this. A registry maps `ModuleType` → implementation.

```php
declare(strict_types=1);
namespace App\Lessons\Modules;

use App\Models\LessonModule;
use Illuminate\Contracts\View\View;

interface LessonModuleType
{
    public static function type(): \App\Enums\ModuleType;
    public static function label(): string;            // __()-localized, teacher-facing
    public static function defaultConfig(): array;     // shape used when a fresh module is added

    /** Composer (edit) view for one module. */
    public function renderEditor(LessonModule $module): View;

    /**
     * Player (present) view for one module.
     * $audience hides answers / teacher notes for students (K-8).
     */
    public function renderSlide(LessonModule $module, Audience $audience): View;

    public function estimatedDuration(LessonModule $module): int;
}

enum Audience: string { case Teacher = 'teacher'; case Student = 'student'; }
```

Registry: `App\Lessons\Modules\ModuleRegistry::for(ModuleType): LessonModuleType` — a simple `match`.
New types are registered here; the Composer palette and Player both read the registry (one source of truth).

### 3.4 Example `config` payloads (validate before save — never trust AI/teacher input raw)

```jsonc
// quiz_mcq
{ "question": "...", "answers": [ {"label":"A","text":"...","is_correct":false}, ... ],
  "feedback_correct": "...", "feedback_wrong": "...", "teacher_note": "..." }
// story_block
{ "narrator": "Julius Caesar", "text": "...", "historical_notes": ["..."], "image_ref": null }
// three_d_model  (K-5; allowlist providers, store attribution)
{ "provider": "sketchfab", "model_id": "c3ae8f329...", "title": "...", "author": "...",
  "source_url": "...", "license": "...", "attribution": "...", "caption": "..." }
```

> **Validation:** every module type ships a `config` validator (a Form Request, a rule array, or a small
> DTO). Invalid AI output must **not** break the composer — mark the module
> `status = needs_review` / `missing_content` and render a warning card, never a fatal error.

---

## 4. Tickets (dispatch one at a time)

> Format mirrors `backlog.md`: each is self-contained with *Files*, *Reuse*, *AC*, *Smoke*, *STOP-if*.
> IDs align with backlog Epic K; new sub-tickets carry letters (K-2a) or extend the range (K-8…K-10).

### K-1 — `LessonModule` model + migration + contract  ·  depends: —
**Goal:** the backbone — a lesson owns an ordered list of typed modules.
**Files (create):** `database/migrations/..._create_lesson_modules_table.php`, `app/Models/LessonModule.php`,
`app/Enums/ModuleType.php`, `app/Lessons/Modules/LessonModuleType.php` (+ `Audience`),
`app/Lessons/Modules/ModuleRegistry.php`, and **two** concrete types to prove the contract:
`app/Lessons/Modules/Types/IntroModule.php` + `app/Lessons/Modules/Types/QuizMcqModule.php`
(+ their editor/slide Blade views under `resources/views/lessons/modules/`).
**Files (edit):** `app/Models/Lesson.php` — add `modules(): HasMany` ordered by `order`; add any missing
nullable Codex columns (`lesson_length_minutes`, `narration_mode`, `interactivity_level`,
`difficulty_level`) only if absent.
**Reuse:** copy the `(lesson_id, order)` + two-phase reorder pattern from `Scene`. Mirror `scenes.config`
column type. Cast `config` to `array` in the model (see `Scene::casts()`).
**Backfill (D2):** in the migration, copy `scenes` → `lesson_modules` mapping `kind`→`type`
(`title→title_block`, `quiz→quiz_mcq`, `map→timeline_map`, `game→strategy_game`, `story/script→story_block`,
unknown→`story_block` + `status=needs_review`). Leave `scenes` untouched.
**AC:** a lesson persists ≥3 ordered typed modules; reordering persists; `ModuleType` resolves via
`ModuleRegistry` to a class implementing the contract; Intro + QuizMcq both implement it.
**Smoke:** `php artisan test --filter=LessonModuleModel` — create lesson + 3 modules, reorder, assert
persisted order; assert `ModuleRegistry::for(ModuleType::Intro)` returns an instance of the contract.
Paste output. **STOP-if:** `scenes.config` column type is ambiguous, or backfill would touch >0 production
rows you can't account for.

### K-2 — Lesson Composer UI (replaces Step3SceneConfigurator)  ·  depends: K-1
**Goal:** teacher assembles a lesson: palette → add → drag-reorder → configure → save, with live preview.
**Files (create):** `app/Livewire/LessonComposer.php`, `resources/views/livewire/lesson-composer.blade.php`,
a module-card partial, and an `add-module` bottom-sheet (DaisyUI `drawer`/`modal`).
**Files (edit):** `app/Livewire/LessonWizard.php` / `resources/views/livewire/lesson-wizard.blade.php` to
render the Composer at step 3 in place of `Step3SceneConfigurator`; add route `lessons.composer`.
**Reuse:** **SortableJS (already installed)** via an Alpine `x-init` wrapper; emit a Livewire call on drop
to persist order (two-phase shift). DaisyUI `card`, `btn`, `badge`, `modal`. Each module's editor comes
from `renderEditor()` (don't hardcode per-type forms in the composer).
**Card shows:** type label, title, short preview, est. duration, status badge
(`ready/needs_review/missing_content`), and actions **Edit · Duplicate · Regenerate · Delete · ↑ · ↓**.
**AC:** add modules from palette; reorder by drag; configure each via its editor; save; reload shows saved
order; live preview reflects edits; works at 390px (one column, sticky bottom save bar).
**Smoke:** `php artisan test --filter=LessonComposer` using `Livewire::test(LessonComposer::class)` —
add Intro+Quiz+Conclusion, call reorder, `assertSet` order; assert duplicate creates a copy at the right
position. Paste output. (If Dusk is installed, add a 390px drag E2E and screenshot.)
**STOP-if:** removing `Step3SceneConfigurator` breaks the wizard's step navigation or the generation
pipeline — if so, keep Step3 reachable behind a flag and report.

### K-2a — Mobile reorder fallback + accessibility  ·  depends: K-2
**Goal:** drag is never the only way; keyboard + buttons fully reorder.
**Files (edit):** the K-2 card partial + `LessonComposer`.
**Reuse:** the same persist-order action behind the buttons that drag uses.
**AC:** ↑/↓ buttons move a module and persist; **Move to position** works without dragging; drag handle has
`aria-label`; all actions reachable by keyboard; no hover-only actions; status badges have text, not just
color. Verified at 390px.
**Smoke:** `php artisan test --filter=ComposerReorderButtons` — call `moveUp`/`moveDown`/`moveTo`, assert
persisted order each time. Paste output.

### K-3 — Slideshow Player (click-next presentation; cutover to modules)  ·  depends: K-1
**Goal:** run the lesson full-screen, one module per slide, next/prev + keyboard.
**Files (create):** `app/Livewire/LessonPlayer.php` (or extend `LessonPlayerController`),
`resources/views/livewire/lesson-player.blade.php`.
**Files (edit):** route the player to read `lesson_modules` (the **D2 cutover**). Keep `lesson.play`
public-by-code behavior.
**Reuse:** `renderSlide($module, Audience::Student)` for each slide; DaisyUI `progress` for the indicator;
Alpine for keyboard (←/→/space). Timeline-map and 3D modules stay interactive in-slide.
**AC:** renders each module full-screen in order; next/prev + keyboard advance; progress indicator; reaches
the conclusion with **no dead-end** (ties to backlog G2); first→last works.
**Smoke:** `php artisan test --filter=SlideshowPlayer` — open a 4-module lesson, assert each renders in
order and conclusion is reachable. (Dusk if available: arrow-key through 4 slides + screenshot.) Paste output.
**STOP-if:** the legacy `scenes`-based player path is still referenced elsewhere — list the references,
don't silently delete them (that's K-1b).

### K-1b — Remove `scenes` after player cutover (cleanup)  ·  depends: K-3
**Goal:** retire the dual system once `lesson_modules` is authoritative.
**Scope:** drop `scenes` reads from the player/pipeline/Step3; migration to drop `scenes` (or mark
deprecated) **only after** confirming K-3 reads modules in production-shaped tests.
**AC:** no runtime path reads `scenes`; tests green; `Scene` model removed or clearly marked deprecated.
**Smoke:** `php artisan test` full suite green; grep shows no `->scenes(`/`Scene::` in player/pipeline.
**STOP-if:** any job (`GenerateScene*`, `BuildLessonOutline`) still writes scenes — migrate those first or
report. This ticket is **last**, not early.

### K-4 — "What do you already know?" pre-quiz module  ·  depends: K-1 (and backlog G1 for pairing)
**Goal:** 2–4 quick questions before content; stored as a baseline for pre/post gain.
**Files (create):** `app/Lessons/Modules/Types/PriorKnowledgeModule.php` + editor/slide views.
**Reuse:** the `quiz_mcq` config shape (open or MCQ); store responses keyed for comparison with the G1
closing quiz.
**AC:** renders as an early module; responses stored as baseline; teacher view shows the baseline; items
pair with the closing quiz.
**Smoke:** `php artisan test --filter=PreQuizModule` — answers stored as baseline; pairing resolves. Paste output.

### K-5 — 3D model viewer module (Sketchfab embed)  ·  depends: K-1  ·  decision: per-model licence
**Goal:** embed a vetted, attributed Sketchfab model in-slide (no 3D generation).
**Files (create):** `app/Lessons/Modules/Types/ThreeDModelModule.php` + editor/slide views; a provider
**allowlist** config.
**Reuse:** the `three_d_model` config shape in §3.4. Render the official iframe
`src="https://sketchfab.com/models/{model_id}/embed"`, `allow="autoplay; fullscreen; xr-spatial-tracking"`,
`allowfullscreen`, `loading="lazy"`. **Do not hard-`sandbox`** (breaks the viewer); if sandboxing, include
`allow-scripts allow-same-origin allow-popups`. Render required credit (author + "on Sketchfab" + source).
**AC:** vetted model renders in slide + composer preview with visible attribution; only allowlisted
providers embed; iframe lazy-loads. Example: Pompeii *House of the Tragic Poet* by Arqueomodel3D,
id `c3ae8f329c49486cb9a7a222a670e3bd`.
**Smoke:** `php artisan test --filter=ThreeDModule` — allowlisted provider accepted, non-allowlisted
rejected; attribution present in rendered slide. Paste output.

### K-6 — Intro & Conclusion modules  ·  depends: K-1
**Goal:** standard bookends.
**Files (create):** `ConclusionModule` (Intro already built in K-1) + views.
**Reuse:** Intro pulls the lesson's objectives (backlog B3) if present; Conclusion shows a recap + "what's
next" (ties to G2 next-actions).
**AC:** both render in composer + player; Intro shows objectives; Conclusion shows a recap.
**Smoke:** `php artisan test --filter=FramingModules`. Paste output.

### K-7 — Scaffold a lesson from a map selection (J → K bridge)  ·  depends: K-1, backlog J-3, A2
**Goal:** the real "generation" flow — a map selection (polity + year) produces a ready-to-edit draft of
default modules pre-filled from **cached DB content**, not a blank page and not live generation.
**Files (create):** `app/Services/LessonScaffolder.php`.
**Reuse:** `Scene::mapPayloadForLesson()` shows how a polity QID + representative year is derived from a
`Corpus\Topic` — reuse that resolver. Default module order: `intro → prior_knowledge → timeline_map →
story_block → quiz_mcq → conclusion`. Pull matching stories/topic content + competences + linked
images/3D from the DB; where content is missing, **enqueue a background job** and mark the module
`missing_content` — never block the click.
**AC:** "Build a lesson here" creates a draft with the default ordered modules pre-filled from cached
content; no synchronous generation on the request path; the draft opens in the Composer (K-2).
**Smoke:** `php artisan test --filter=LessonScaffolder` — scaffold for `(rome, -50)` yields a draft with
the default modules and non-empty content from the DB; assert **no** synchronous generation call on the
request path. Paste output.
**STOP-if:** the curated `Corpus\Topic` data for the test polity isn't seeded — seed the minimum or report;
don't fabricate content inline.

### K-8 — Teacher vs Student preview modes  ·  depends: K-2, K-3
**Goal:** one toggle, two audiences; student view hides answers + teacher notes.
**Files (create):** routes `lessons.preview.teacher` / `lessons.preview.student` (or one route + `?as=`),
a thin preview wrapper around the K-3 player.
**Reuse:** `renderSlide($module, Audience::Teacher|Student)`. Student must NOT see: correct answers before
answering, `teacher_note` fields, generation settings. Teacher sees: full structure, timing, narration,
answers, teacher notes, student-task instructions.
**AC:** toggle switches audience live; student preview hides answers/notes; teacher preview shows them.
**Smoke:** `php artisan test --filter=PreviewAudience` — render a `quiz_mcq` slide as Student → assert
`is_correct`/`teacher_note` absent from output; as Teacher → present. Paste output.

### K-9 — Results structure (placeholder, wire later)  ·  depends: K-1
**Goal:** the data shape for student attempts so future quiz submissions connect; a placeholder results
screen. Structure only.
**Files (create):** migration `module_attempts` (`student_id, lesson_id, lesson_module_id, question_ref,
given_answer, correct_answer, score, completed_at`), `App\Models\ModuleAttempt`, a Livewire
`LessonResults` + view, route `lessons.results`.
**Reuse:** existing `StudentProgress` for lesson-level rollup; `module_attempts` is the per-question grain.
Render as **cards on mobile, table on desktop** (DaisyUI `table` only ≥ md).
**AC:** model + migration exist; teacher opens a placeholder results screen; future submissions can attach.
**Smoke:** `php artisan test --filter=ModuleAttempt` — create an attempt, assert it relates to a module +
lesson; results route renders for the owning teacher. Paste output.

### K-10 — Export actions (transcript real, PDF/link placeholders)  ·  depends: K-1, K-3
**Goal:** the lesson-overview actions from the Codex prototype.
**Files (edit):** add to the Composer/overview an action bar: **Export transcript** (real), **Download PDF**
(placeholder unless a PDF lib already exists), **Copy lesson link** (uses `lesson_code`), **Start classroom
session** (placeholder).
**Reuse:** transcript = plain text concatenated from `story_block` + narration `config.text` in module
order. Copy-link reuses `lesson.play` + `lesson_code`.
**AC:** transcript downloads as plain text from story modules in order; copy-link yields a working
`lesson.play` URL; PDF/session buttons exist (placeholder OK, labeled as such).
**Smoke:** `php artisan test --filter=LessonTranscript` — assert exported text contains each story module's
text in order. Paste output.

---

## 5. Module-type build order (don't build all 17 at once)

| Phase | Types | Ticket |
|---|---|---|
| MVP (prove contract + flow) | `intro`, `quiz_mcq` | K-1 |
| Core lesson | `prior_knowledge`, `story_block`, `conclusion`, `reflection`, `title_block` | K-4, K-6 |
| Rich/interactive | `timeline_map`, `three_d_model`, `image_analysis` | K-5, +J/G epics |
| Extended quiz/challenge | `quiz_image`, `quiz_map`, `hotspot_image`, `strategy_game`, `map_challenge` | later |
| Teacher/student scaffolding | `teacher_instruction`, `student_task` | with K-8 |

Each new type = one small ticket: a `…Module.php` implementing the contract + editor view + slide view +
a `config` validator + a Pest test. Register it in `ModuleRegistry`. The palette and player pick it up
automatically. **Never** add a type without its validator and test.

---

## 6. Global Definition of Done (every ticket)

- [ ] AC met; smallest change; no out-of-scope edits.
- [ ] `declare(strict_types=1);` on new PHP; typed props/returns; PSR-12.
- [ ] **Immutable updates** — `$model->update([...])` / new arrays; never mutate shared state in place.
- [ ] **DaisyUI-first** with the `learningportal` theme; semantic classes (`card`, `btn`, `modal`,
      `drawer`, `badge`, `progress`, `table`). **No raw Tailwind color utilities** for chrome.
- [ ] **Mobile-first**: works at 390px, one column, sticky bottom action bar, no horizontal scroll;
      tables become stacked cards below `md`.
- [ ] **A11y**: labels on inputs; `aria-label` on drag handles; keyboard reorder; status conveyed by text
      not color alone; errors tied to fields; no hover-only actions.
- [ ] **Validation at the boundary** — Form Request / rule array / DTO for every `config` write; invalid
      AI/teacher input degrades to `needs_review`, never a fatal error.
- [ ] All new strings via `__()`; no hardcoded English in Blade/Livewire.
- [ ] Named routes only; thin controllers; logic in Livewire/services.
- [ ] `php artisan test --filter=…` green; output pasted. `./vendor/bin/pint` clean.
      `npm run build` succeeds if JS/Blade/Tailwind touched. No new console errors.
- [ ] No `dd()`/`dump()`/`var_dump()`/`die()` left; no secrets committed.

---

## 7. Recommended execution order

```
K-1  →  K-2  →  K-2a  →  K-3            (model → composer → mobile fallback → player)
        ↘ K-6 (intro/conclusion alongside K-2)
K-4  →  K-8                              (pre-quiz, then teacher/student preview)
K-7                                      (map → lesson scaffolder: the real "generate")
K-5                                      (3D module)
K-9  →  K-10                             (results structure, export actions)
K-1b                                     (remove scenes — LAST, after K-3 cutover proven)
```

**Final acceptance (whole epic):** on a phone, a teacher can open creation, scaffold a lesson from the map
(or start blank), review generated modules, drag **or** button-reorder them, edit a quiz, add a 3D/strategy
module, preview as teacher, preview as student, and save the lesson as **ready for review
(`LessonStatus::Previewable`)** — all without leaving 390px width.
