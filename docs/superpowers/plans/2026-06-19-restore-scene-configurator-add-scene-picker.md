# Restore Scene Configurator + Add-Scene Picker — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the scene-based wizard step 3 (`Step3SceneConfigurator`), replace the timeline's plain "Add scene" dropdown with the Keynote-style picker modal wired to the existing `addScene()`, and delete the K-1/K-2 `lesson_modules` / `LessonComposer` system.

**Architecture:** The wizard is scene-based end to end; only step 3 was diverted to a `lesson_modules` composer. We flip step 3's wiring back, move the proven picker modal into the configurator (driven by a new `$addSceneOpen` flag, tiles calling the already-tested `addScene(kind, gameType)`), and remove the now-dead module code plus its table.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, Tailwind v4 + DaisyUI 5, Pest (PHPUnit-style) against Postgres, SortableJS (already global via `resources/js/app.js`).

**Spec:** `docs/superpowers/specs/2026-06-19-restore-scene-configurator-add-scene-picker-design.md`

---

## Naming correction (read first)

The spec named the new thumbnail component `scene-thumb.blade.php`. **That name is already taken** —
`resources/views/components/lesson/scene-thumb.blade.php` renders a real scene's thumbnail in the
timeline rail. This plan uses **`scene-type-thumb.blade.php`** for the picker glyphs to avoid a clobber.

## File Structure

**Modify:**
- `resources/views/livewire/lesson-wizard.blade.php` — step 3 renders the configurator again
- `app/Livewire/Wizard/Step3SceneConfigurator.php` — add `$addSceneOpen`; close it in `addScene()`
- `resources/views/livewire/wizard/step3-scene-configurator.blade.php` — add the picker modal
- `resources/views/components/lesson/timeline.blade.php` — swap the add-dropdown for a modal trigger
- `app/Models/Lesson.php` — remove `modules()` + `reorderModules()`
- `routes/web.php` — remove the `lessons.composer` route
- `tests/Feature/LessonWizardRoutesTest.php` — drop the `'composer'` data row

**Create:**
- `resources/views/components/lesson/scene-type-thumb.blade.php` — schematic glyph per scene type
- `database/migrations/2026_06_19_000001_drop_lesson_modules_table.php`
- `tests/Feature/Wizard/WizardStep3WiringTest.php` — asserts step 3 renders the configurator

**Delete:**
- `app/Livewire/LessonComposer.php`
- `resources/views/livewire/lesson-composer.blade.php`
- `resources/views/livewire/lesson-composer-card.blade.php`
- `app/Models/LessonModule.php`
- `app/Enums/ModuleType.php`
- `app/Lessons/Modules/` (Audience, LessonModuleType, ModuleRegistry, Types/IntroModule, Types/QuizMcqModule)
- `resources/views/lessons/modules/` (intro + quiz-mcq views + picker-thumb)
- `tests/Feature/Lessons/LessonComposerTest.php`
- `tests/Feature/Lessons/LessonModuleModelTest.php`
- `database/migrations/2026_06_18_000001_create_lesson_modules_table.php`

---

## Task 1: Restore the scene configurator at step 3

**Files:**
- Test: `tests/Feature/Wizard/WizardStep3WiringTest.php`
- Modify: `resources/views/livewire/lesson-wizard.blade.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Wizard/WizardStep3WiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardStep3WiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_3_renders_the_scene_configurator_not_the_composer(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id]);

        $this->actingAs($teacher)
            ->get(route('teacher.lessons.wizard', ['lesson' => $lesson, 'step' => 3]))
            ->assertOk()
            ->assertSee('Inspector')              // the configurator's panel header
            ->assertDontSee('Build Your Lesson');  // the removed composer heading
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WizardStep3Wiring`
Expected: FAIL — step 3 currently renders `<livewire:lesson-composer>`, so it sees "Build Your Lesson" and not "Inspector".

- [ ] **Step 3: Restore the configurator wiring**

In `resources/views/livewire/lesson-wizard.blade.php`, change the step-3 branch:

```blade
        @elseif ($step === 3)
            <livewire:wizard.step3-scene-configurator :lesson="$lesson" :key="'step3-' . $lesson?->id" />
```

(It currently reads `<livewire:lesson-composer :lesson="$lesson" :key="'step3-' . $lesson?->id" />`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WizardStep3Wiring`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Wizard/WizardStep3WiringTest.php resources/views/livewire/lesson-wizard.blade.php
git commit -m "fix(wizard): restore scene configurator at step 3"
```

---

## Task 2: Add-scene picker modal

### 2a — `$addSceneOpen` state closes on add

**Files:**
- Test: `tests/Feature/Wizard/Step3SceneConfiguratorTest.php` (append)
- Modify: `app/Livewire/Wizard/Step3SceneConfigurator.php`

- [ ] **Step 1: Write the failing test**

Append this method to `tests/Feature/Wizard/Step3SceneConfiguratorTest.php` (inside the class):

```php
    public function test_adding_a_scene_closes_the_picker(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id]);

        Livewire::actingAs($teacher)
            ->test(\App\Livewire\Wizard\Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->set('addSceneOpen', true)
            ->call('addScene', 'narration')
            ->assertSet('addSceneOpen', false);

        $this->assertSame('narration', $lesson->scenes()->latest('id')->first()->kind);
    }
```

(If `User`, `Lesson`, `Livewire` aren't imported in that file, add `use App\Models\User;`, `use App\Models\Lesson;`, `use Livewire\Livewire;`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="Step3SceneConfigurator::test_adding_a_scene_closes_the_picker"`
Expected: FAIL — property `addSceneOpen` does not exist yet.

- [ ] **Step 3: Add the property and close it on add**

In `app/Livewire/Wizard/Step3SceneConfigurator.php`, add the property near the other public props (after `public bool $inspectorOpen = true;`):

```php
    public bool $addSceneOpen = false;
```

Then, in the `addScene()` method, add this as the **first** line of the method body (before the `$kind = …` normalization):

```php
        $this->addSceneOpen = false;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter="Step3SceneConfigurator::test_adding_a_scene_closes_the_picker"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Wizard/Step3SceneConfigurator.php tests/Feature/Wizard/Step3SceneConfiguratorTest.php
git commit -m "feat(wizard): add addSceneOpen picker state to the configurator"
```

### 2b — `scene-type-thumb` glyph component

**Files:**
- Create: `resources/views/components/lesson/scene-type-thumb.blade.php`

- [ ] **Step 1: Create the component**

Create `resources/views/components/lesson/scene-type-thumb.blade.php`:

```blade
@props(['kind' => 'narration', 'gameType' => null])
@php
    // narration | map | quiz | strategy | debate
    $key = $kind === 'game' ? ($gameType ?? 'quiz') : $kind;
@endphp
<div class="flex h-16 w-full items-center justify-center overflow-hidden rounded-md bg-base-100 ring-1 ring-slate-700/60">
    @switch($key)
        @case('narration')
            <div class="flex w-full items-start gap-1.5 p-2">
                <div class="h-4 w-4 shrink-0 rounded-full bg-amber-400"></div>
                <div class="flex flex-1 flex-col gap-1 pt-0.5">
                    <div class="h-1 w-full rounded-sm bg-slate-600"></div>
                    <div class="h-1 w-5/6 rounded-sm bg-slate-600"></div>
                    <div class="h-1 w-2/3 rounded-sm bg-slate-600"></div>
                </div>
            </div>
            @break

        @case('quiz')
            <div class="flex w-full flex-col gap-1 p-2">
                <div class="h-1.5 w-3/4 rounded-sm bg-slate-500"></div>
                <div class="grid grid-cols-2 gap-1">
                    <div class="h-2 rounded-sm bg-amber-400"></div>
                    <div class="h-2 rounded-sm bg-slate-600"></div>
                    <div class="h-2 rounded-sm bg-slate-600"></div>
                    <div class="h-2 rounded-sm bg-slate-600"></div>
                </div>
            </div>
            @break

        @case('strategy')
            <div class="grid grid-cols-3 grid-rows-3 gap-0.5 p-2">
                @foreach ([0,1,2,3,4,5,6,7,8] as $cell)
                    <div class="h-2.5 w-2.5 rounded-[2px] {{ $cell === 4 ? 'bg-amber-400' : 'bg-slate-600' }}"></div>
                @endforeach
            </div>
            @break

        @case('debate')
            <div class="flex w-full items-center justify-center gap-2 p-2">
                <div class="h-6 w-9 rounded-md rounded-br-none bg-amber-400/80"></div>
                <div class="h-6 w-9 rounded-md rounded-bl-none bg-slate-600"></div>
            </div>
            @break

        @case('map')
            <div class="flex w-full flex-col justify-center gap-1.5 p-2">
                <div class="relative h-1 w-full rounded-sm bg-slate-600">
                    <div class="absolute -top-1 left-1/3 h-3 w-3 rounded-full border-2 border-amber-400 bg-base-100"></div>
                </div>
                <div class="h-6 w-full rounded-sm bg-indigo-500/30"></div>
            </div>
            @break
    @endswitch
</div>
```

- [ ] **Step 2: Build to verify it compiles**

Run: `npm run build`
Expected: `✓ built` with no Blade/compile error.

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/lesson/scene-type-thumb.blade.php
git commit -m "feat(wizard): scene-type glyphs for the add-scene picker"
```

### 2c — Picker modal in the configurator + timeline trigger

**Files:**
- Modify: `resources/views/livewire/wizard/step3-scene-configurator.blade.php`
- Modify: `resources/views/components/lesson/timeline.blade.php`

- [ ] **Step 1: Add the modal to the configurator view**

In `resources/views/livewire/wizard/step3-scene-configurator.blade.php`, add this block immediately
**before** the `<script type="application/json" id="step3-scenes-data">` line (still inside the root
`<div class="contents" …>`):

```blade
    {{-- Add-scene picker (Keynote-style). Open state driven by $addSceneOpen; tiles call addScene(). --}}
    <div class="modal modal-bottom sm:modal-middle {{ $addSceneOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-lg border border-slate-700/70 bg-base-300/95 backdrop-blur-xl">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-100">Add a scene</h2>
                <button type="button" class="btn btn-ghost btn-sm btn-circle text-slate-400"
                        aria-label="Close" wire:click="$set('addSceneOpen', false)">✕</button>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <button type="button" wire:click="addScene('narration')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="narration" />
                    <span class="text-sm font-medium text-slate-200">Story</span>
                </button>

                <button type="button" wire:click="addScene('game', 'quiz')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="game" game-type="quiz" />
                    <span class="text-sm font-medium text-slate-200">Quiz</span>
                </button>

                <button type="button" wire:click="addScene('game', 'strategy')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="game" game-type="strategy" />
                    <span class="text-sm font-medium text-slate-200">Strategy game</span>
                </button>

                <button type="button" wire:click="addScene('game', 'debate')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="game" game-type="debate" />
                    <span class="text-sm font-medium text-slate-200">Debate</span>
                </button>

                <button type="button" wire:click="addScene('map')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="map" />
                    <span class="text-sm font-medium text-slate-200">Map</span>
                </button>
            </div>
        </div>

        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('addSceneOpen', false)"></button>
    </div>
```

- [ ] **Step 2: Replace the timeline add-dropdown with a modal trigger**

In `resources/views/components/lesson/timeline.blade.php`, replace the entire `@if ($editable) … @endif`
block that renders the dropdown (the `<div class="dropdown dropdown-top …">` … through its closing
`</div>`, currently lines ~19–60) with:

```blade
            @if ($editable)
                <button type="button"
                        data-no-drag
                        wire:click="$set('addSceneOpen', true)"
                        class="h-[72px] w-32 shrink-0 rounded-lg border-2 border-white/20 text-white/40 transition-all hover:border-amber-400 hover:text-amber-300"
                        title="Add scene">
                    <span class="block text-2xl leading-none">+</span>
                    <span class="mt-1 block text-[9px] font-semibold uppercase tracking-widest">Add</span>
                </button>
            @endif
```

Leave the rest of the file (the `#timeline-track` Sortable script, the script track) unchanged.

- [ ] **Step 3: Build + run the configurator tests**

Run: `npm run build && php artisan test --filter=Step3SceneConfigurator`
Expected: `✓ built`, and all Step3 tests PASS (the existing addScene/reorder tests + the new close-on-add test).

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/wizard/step3-scene-configurator.blade.php resources/views/components/lesson/timeline.blade.php
git commit -m "feat(wizard): Keynote-style add-scene picker modal"
```

---

## Task 3: Remove the lesson_modules / composer system

**Files:**
- Delete: the module-system files (see list)
- Modify: `app/Models/Lesson.php`, `routes/web.php`, `tests/Feature/LessonWizardRoutesTest.php`
- Create: `database/migrations/2026_06_19_000001_drop_lesson_modules_table.php`

- [ ] **Step 1: Remove the `modules()` + `reorderModules()` methods from `Lesson`**

In `app/Models/Lesson.php`, delete the `modules()` relation block:

```php
    /** Ordered lesson modules (Epic K) — the modular replacement for scenes. */
    public function modules(): HasMany
    {
        return $this->hasMany(LessonModule::class)->orderBy('order');
    }
```

and delete the `reorderModules()` method block:

```php
    /**
     * Persist a new module order from a list of module ids. Uses a two-phase shift to avoid the
     * (lesson_id, order) unique clash (same pattern as Scene::insertDefaultMapBlock). Ids not
     * belonging to this lesson are ignored.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderModules(array $orderedIds): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                $this->modules()->whereKey($id)->update(['order' => -1 * ($index + 1)]);
            }
            foreach ($orderedIds as $index => $id) {
                $this->modules()->whereKey($id)->update(['order' => $index]);
            }
        });
    }
```

- [ ] **Step 2: Remove the composer route**

In `routes/web.php`, delete this line:

```php
    Route::get('/lessons/{lesson}/composer', \App\Livewire\LessonComposer::class)->name('lessons.composer');
```

- [ ] **Step 3: Remove the `'composer'` data row from the route guard test**

In `tests/Feature/LessonWizardRoutesTest.php`, in the `nonNumericLessonUrls()` provider, delete this row:

```php
            'composer' => ['/teacher/lessons/null/composer'],
```

- [ ] **Step 4: Delete the module-system files**

Run:

```bash
git rm app/Livewire/LessonComposer.php \
  resources/views/livewire/lesson-composer.blade.php \
  resources/views/livewire/lesson-composer-card.blade.php \
  app/Models/LessonModule.php \
  app/Enums/ModuleType.php \
  tests/Feature/Lessons/LessonComposerTest.php \
  tests/Feature/Lessons/LessonModuleModelTest.php \
  database/migrations/2026_06_18_000001_create_lesson_modules_table.php
git rm -r app/Lessons/Modules resources/views/lessons
```

- [ ] **Step 5: Add the drop-table migration**

Create `database/migrations/2026_06_19_000001_drop_lesson_modules_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lesson_modules');
    }

    public function down(): void
    {
        // One-way removal — lesson_modules is superseded by scenes. No rebuild.
    }
};
```

- [ ] **Step 6: Verify no dangling references remain**

Run: `grep -rn "LessonModule\|ModuleType\|ModuleRegistry\|lesson_modules\|lessons.composer\|->modules(\|reorderModules" app routes tests resources --include=*.php --include=*.blade.php`
Expected: no matches (only the new drop migration's table name, which is fine). Fix any straggler.

- [ ] **Step 7: Migrate dev + run the full suite**

Run: `php artisan migrate && php artisan test`
Expected: the drop migration runs; full suite GREEN (the two deleted `Lessons/*` tests are gone; everything else passes).

- [ ] **Step 8: Pint + build**

Run: `./vendor/bin/pint && npm run build`
Expected: Pint clean; `✓ built`.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor(lessons): remove lesson_modules composer; drop table (back to scenes)"
```

---

## Task 4: Mark the Epic K module plan as superseded

**Files:**
- Modify: `.claude/epic-k-lesson-composer-plan.md`, `.claude/backlog.md`

- [ ] **Step 1: Annotate the plan doc**

At the very top of `.claude/epic-k-lesson-composer-plan.md`, add:

```markdown
> **SUPERSEDED (2026-06-19):** The `lesson_modules` composer was reverted to the scene configurator.
> See `docs/superpowers/specs/2026-06-19-restore-scene-configurator-add-scene-picker-design.md`.
> The K-1/K-2 module code and table were removed; "modules" remain the existing `scenes`.
```

- [ ] **Step 2: Annotate the backlog Epic K note**

In `.claude/backlog.md`, under the `# EPIC K` heading, add a line:

```markdown
> **Update (2026-06-19):** lesson_modules reverted — scenes remain the lesson building blocks; the
> Epic K detail below is historical.
```

- [ ] **Step 3: Commit**

```bash
git add .claude/epic-k-lesson-composer-plan.md .claude/backlog.md
git commit -m "docs: mark Epic K lesson_modules plan as superseded"
```

---

## Self-Review

**Spec coverage:**
- Restore step 3 → Task 1. ✓
- Picker modal wired to `addScene` (all five tiles) → Task 2c. ✓
- `$addSceneOpen` + close-on-add → Task 2a. ✓
- Schematic thumbnails (Story/Quiz/Strategy/Debate/Map) → Task 2b (`scene-type-thumb`). ✓
- Remove module system + `modules()`/`reorderModules()` + route + route-test row → Task 3 steps 1–4. ✓
- `dropIfExists('lesson_modules')` migration → Task 3 step 5. ✓
- Tests: configurator reactivated + wiring test + close-on-add; deleted `Lessons/*` → Tasks 1, 2a, 3. ✓
- Supersede the K plan doc → Task 4. ✓

**Placeholder scan:** No TBD/TODO; every code step has complete code; the drop migration is fully written.

**Type/name consistency:** `addSceneOpen` (bool) used in 2a (component), 2c (view + timeline), consistent.
`scene-type-thumb` props `kind` + `gameType` (kebab `game-type` in markup) match the component's `@props`.
`addScene(kind, gameType)` signature matches existing method. Route name `lessons.composer` matches the
line added in K-2. The picker-thumb collision is resolved (uses `scene-type-thumb`, not `scene-thumb`).

**Note for the executor:** `Step3SceneConfigurator.addScene()` already re-normalizes `$kind`/`$gameType`
and selects the new scene; adding `$this->addSceneOpen = false;` as the first line does not affect that.
The timeline's SortableJS rail-reorder (`timeline:reordered` → `reorder`) is untouched.
