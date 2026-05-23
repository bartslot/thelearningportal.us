# Plan H — Step 4 Preview + Publish

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Step 4 placeholder with a fullscreen Preview that plays the lesson end-to-end (skybox crossfade, narration, animation, game timer overlay). The bottom timeline becomes read-only and the playhead animates with audio. A Publish button (top-right) sets `lesson.status=Published` once all scenes are ready.

**Architecture:** `Step4Preview` Livewire component reuses the same fullscreen layout + `<x-lesson.timeline>` (with `:editable="false"`) and the same `mountWizardScene()` bridge from Plan G. Adds a Play/Pause control. `SceneTimelinePlayer.playFrom(0)` orchestrates the run. A 30 Hz `timeupdate` event from the player drives a CSS-positioned playhead element. Publish is gated server-side.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, Plan F JS modules, shared Plan G blade partials.

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§4 Step 4, §8, §9).

**Depends on:** Plans A, F, G.

---

## File Structure

```
app/Livewire/Wizard/Step4Preview.php                       REWRITE (placeholder → real)
resources/views/livewire/wizard/step4-preview.blade.php    REWRITE
routes/web.php                                             MODIFY (publish action remains; ensure status gate uses Previewable)
tests/Feature/Wizard/Step4PreviewTest.php                  NEW
```

---

## Task 1: `Step4Preview` Livewire component

**Files:**
- Modify: `app/Livewire/Wizard/Step4Preview.php`
- Test: `tests/Feature/Wizard/Step4PreviewTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step4Preview;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->lesson  = Lesson::create([
        'teacher_id' => $this->teacher->id,
        'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        'status' => LessonStatus::Previewable,
    ]);
});

it('lists scenes for the player', function (): void {
    Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
        'year' => '1810', 'location' => 'Paris', 'image_path' => 'p.png',
        'audio_path' => 'a.mp3', 'audio_alignment' => [], 'duration_seconds' => 10,
        'status' => 'ready',
    ]);

    Livewire::actingAs($this->teacher)
        ->test(Step4Preview::class, ['lesson' => $this->lesson])
        ->assertViewHas('lesson')
        ->assertSee('1810')
        ->assertSee('Paris');
});

it('publishes the lesson only when all scenes are ready', function (): void {
    Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready',
        'script_segment' => 's', 'image_path' => 'a', 'audio_path' => 'b',
    ]);
    Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration', 'status' => 'failed',
    ]);

    Livewire::actingAs($this->teacher)
        ->test(Step4Preview::class, ['lesson' => $this->lesson])
        ->call('publish')
        ->assertSet('publishError', fn ($v) => is_string($v) && $v !== '');

    // Fix the failed scene → publish should succeed
    Scene::where('status', 'failed')->update([
        'status' => 'ready', 'script_segment' => 's', 'image_path' => 'a', 'audio_path' => 'b',
    ]);

    Livewire::actingAs($this->teacher)
        ->test(Step4Preview::class, ['lesson' => $this->lesson])
        ->call('publish');

    expect($this->lesson->fresh()->status)->toBe(LessonStatus::Published);
});

it('forbids another teacher from accessing the preview', function (): void {
    $other = User::factory()->create();

    Livewire::actingAs($other)
        ->test(Step4Preview::class, ['lesson' => $this->lesson]);
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step4Preview extends Component
{
    public Lesson $lesson;
    public string $publishError = '';

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        if ($this->lesson->status !== LessonStatus::Published) {
            $this->lesson->update(['status' => LessonStatus::Previewable, 'wizard_step' => 4]);
        }
    }

    #[Computed]
    public function scenes()
    {
        return $this->lesson->scenes()->ordered()->get();
    }

    #[Computed]
    public function allReady(): bool
    {
        return $this->scenes->isNotEmpty()
            && $this->scenes->every(fn ($s) => $s->status === 'ready');
    }

    public function publish(): void
    {
        if (! $this->allReady) {
            $this->publishError = 'All scenes must be in "ready" status before publishing.';
            return;
        }

        $this->lesson->update(['status' => LessonStatus::Published]);
        $this->publishError = '';
        $this->dispatch('lesson-published');
    }

    public function render()
    {
        return view('livewire.wizard.step4-preview');
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Wizard/Step4PreviewTest.php
git add app/Livewire/Wizard/Step4Preview.php tests/Feature/Wizard/Step4PreviewTest.php
git commit -m "feat(wizard): Step 4 Preview component"
```

---

## Task 2: Step 4 view

**Files:**
- Create: `resources/views/livewire/wizard/step4-preview.blade.php`

- [ ] **Step 1: Build the view**

```blade
<div class="contents" x-data="step4Preview()" x-init="init()">

    {{-- Fullscreen canvas wrapper (same as Step 3) --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root">
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none py-32"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
    </div>

    {{-- Publish button --}}
    <div class="fixed top-4 right-4 z-40 flex flex-col items-end gap-2">
        @if ($lesson->status === \App\Enums\LessonStatus::Published)
            <span class="px-3 py-2 rounded-xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 text-xs">Published ✓</span>
        @else
            <button wire:click="publish"
                    @disabled(! $this->allReady)
                    class="btn btn-sm bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-40">
                Publish
            </button>
            @if ($publishError)
                <span class="text-xs text-rose-300 max-w-xs text-right">{{ $publishError }}</span>
            @endif
        @endif
    </div>

    {{-- Play / Back floating --}}
    <div class="fixed bottom-28 inset-x-0 z-30 flex items-center justify-center gap-3">
        <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 3]) }}"
           wire:navigate class="btn btn-sm btn-outline">← Configure</a>
        <button type="button" @click="togglePlay()"
                class="btn btn-circle bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 w-14 h-14 text-2xl">
            <span x-show="!playing">▶</span>
            <span x-show="playing">⏸</span>
        </button>
        <span class="text-xs text-slate-300" x-text="readout"></span>
    </div>

    {{-- Read-only timeline --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="null" :editable="false" />

    @push('scripts')
    <script>
        function step4Preview() {
            return {
                playing: false,
                readout: '0:00 / 0:00',
                stage:   null,
                total:   0,

                async init() {
                    if (!window.LessonScene?.mountWizardScene) return
                    const scenes = @json($this->scenes->map->only([
                        'id','kind','year','location','image_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id',
                    ]))
                    const overlayEl = document.getElementById('lesson-overlay')
                    const timerEl   = document.getElementById('lesson-game-overlay')
                    const canvasEl  = document.getElementById('lesson-canvas')
                    this.stage = window.LessonScene.mountWizardScene({ canvasEl, overlayEl, timerEl, scenes })
                    this.total = this.stage.sequencer.totalSeconds()
                    this.readout = `0:00 / ${this._fmt(this.total)}`

                    this.stage.sequencer.on('scenechange', s => {
                        // Drive a playhead via CSS by setting custom property on the timeline root.
                        document.documentElement.style.setProperty('--playhead-scene-id', s.id)
                    })
                    this.stage.sequencer.on('timelineend', () => { this.playing = false })
                },

                async togglePlay() {
                    if (!this.stage) return
                    if (this.playing) {
                        this.stage.sequencer.pause()
                        this.playing = false
                    } else {
                        this.playing = true
                        await this.stage.sequencer.playFrom(0)
                    }
                },

                _fmt(s) {
                    const m = Math.floor(s / 60), r = Math.floor(s % 60)
                    return `${m}:${String(r).padStart(2, '0')}`
                },
            }
        }
    </script>
    @endpush
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/wizard/step4-preview.blade.php
git commit -m "feat(wizard): Step 4 view — fullscreen playback + publish"
```

---

## Task 3: Confirm the legacy publish route is unused / aligned

**Files:**
- Modify: `routes/web.php`

The existing PATCH route `/teacher/lessons/{lesson}/publish` (referenced by removed `LessonShow`) is no longer called. Since publish is now handled by Livewire `publish()` in Step 4, the route can be removed.

- [ ] **Step 1: Delete the legacy publish route**

In `routes/web.php`, remove:
```php
Route::patch('/lessons/{lesson}/publish', function (Lesson $lesson) {
    abort_unless($lesson->teacher_id === auth()->id(), 403);
    abort_unless($lesson->isReady(), 422, 'Lesson is not ready to publish');
    $lesson->update(['status' => LessonStatusEnum::Published]);
    return back()->with('success', 'Lesson published!');
})->name('lessons.publish');
```

- [ ] **Step 2: Add a test that no `teacher.lessons.publish` route exists**

```php
// tests/Feature/Wizard/PublishRouteTest.php
<?php

declare(strict_types=1);

it('removes the legacy publish PATCH route', function (): void {
    expect(Route::has('teacher.lessons.publish'))->toBeFalse();
});
```

- [ ] **Step 3: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Wizard/PublishRouteTest.php
git add routes/web.php tests/Feature/Wizard/PublishRouteTest.php
git commit -m "chore(wizard): drop legacy publish route"
```

---

## Task 4: Full system smoke

- [ ] **Step 1:** `composer test` and `npm test`
- [ ] **Step 2:** end-to-end smoke:
  1. `composer dev` + `php artisan queue:work`
  2. Visit `/teacher/lessons/create`
  3. Fill Step 1 → Generate
  4. Watch Step 2 fill in
  5. Step 3: drag-reorder a scene, edit a year/location, regenerate one image, re-narrate one scene
  6. Step 4: click Play → confirm scenes play with skybox crossfade + overlay + animation; game scene shows timer overlay; click Publish → lesson status flips to Published
- [ ] **Step 3:** verify `dashboard` lists the published lesson.

Done. The full pipeline is now live.
