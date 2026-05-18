# Plan G — Step 3 Scene Configurator

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Step 3 placeholder with a fullscreen 3D Scene Configurator: a three.js viewport that fills the viewport, year + location HUD, a bottom video-editor-style timeline with drag-to-reorder scene thumbs, a collapsible right-side Inspector drawer (narration or game variant), and per-asset regenerate buttons that re-dispatch single Plan C jobs.

**Architecture:** `Step3SceneConfigurator` Livewire component holds selected scene id + inspector-open state. Its blade renders a fixed-position canvas, mounts `Avatar3DPlayer` + `SkyboxSphere` + `SceneOverlay` + `GameTimerOverlay` from `window.LessonScene` (Plan F), and renders `<x-lesson.timeline editable>`. Drag-reorder uses SortableJS (existing in Avatar Lab). Inspector field saves debounce via Livewire `wire:model.blur`. Regenerate buttons dispatch existing jobs from Plan C.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js (existing), Tailwind v4 + DaisyUI, SortableJS, Plan F JS modules.

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§4 Step 3, §8).

**Depends on:** Plans A, C, D, F.

---

## File Structure

```
app/Livewire/Wizard/Step3SceneConfigurator.php                   REWRITE (placeholder → real)
resources/views/livewire/wizard/step3-scene-configurator.blade.php  REWRITE
resources/views/components/lesson/timeline.blade.php             NEW (shared with Step 4)
resources/views/components/lesson/scene-thumb.blade.php          NEW
resources/views/components/lesson/scene-inspector-narration.blade.php  NEW
resources/views/components/lesson/scene-inspector-game.blade.php       NEW
resources/js/scene/wizard-bridge.js                              NEW (glue: Livewire events ↔ LessonScene)
tests/Feature/Wizard/Step3SceneConfiguratorTest.php              NEW
```

---

## Task 1: `Step3SceneConfigurator` component

**Files:**
- Modify: `app/Livewire/Wizard/Step3SceneConfigurator.php`
- Test: `tests/Feature/Wizard/Step3SceneConfiguratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->lesson  = Lesson::create([
        'teacher_id' => $this->teacher->id,
        'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
    ]);
    $this->s1 = Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
        'year' => '1810', 'location' => 'Paris', 'script_segment' => 'Old script.',
        'image_path' => 'a.png', 'audio_path' => 'a.mp3', 'audio_script_hash' => sha1('Old script.'),
        'status' => 'ready',
    ]);
    $this->s2 = Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'game',
        'game_segment_index' => 1, 'duration_seconds' => 480,
        'script_segment' => 'Game intro.', 'image_path' => 'g.png', 'audio_path' => 'g.mp3',
        'status' => 'ready',
    ]);
});

it('selects the first scene on mount', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->assertSet('selectedSceneId', $this->s1->id);
});

it('updates scene fields via inspector', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->set('selectedSceneId', $this->s1->id)
        ->set('selectedScene.year', '1811')
        ->set('selectedScene.location', 'Versailles')
        ->call('saveSelected');

    $this->s1->refresh();
    expect($this->s1->year)->toBe('1811')->and($this->s1->location)->toBe('Versailles');
});

it('reorders scenes', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->call('reorder', [$this->s2->id, $this->s1->id]);

    expect($this->s1->fresh()->order)->toBe(2)
        ->and($this->s2->fresh()->order)->toBe(1);
});

it('regenerates a single asset via dispatch', function (): void {
    Bus::fake();
    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->call('regenerate', $this->s1->id, 'image');
    Bus::assertDispatched(GenerateSceneImage::class, fn ($j) => $j->sceneId === $this->s1->id);

    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->call('regenerate', $this->s1->id, 'audio');
    Bus::assertDispatched(GenerateSceneAudio::class, fn ($j) => $j->sceneId === $this->s1->id);
});

it('adds a blank narration scene at the end', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->call('addScene');

    expect($this->lesson->scenes()->count())->toBe(3)
        ->and(Scene::orderBy('order', 'desc')->first()->kind)->toBe('narration');
});

it('deletes a scene and reindexes game segments', function (): void {
    $g2 = Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'game',
        'game_segment_index' => 2, 'status' => 'ready',
    ]);
    $this->lesson->update(['game_split_count' => 2]);

    Livewire::actingAs($this->teacher)
        ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
        ->call('deleteScene', $this->s2->id);

    expect(Scene::find($this->s2->id))->toBeNull()
        ->and($g2->fresh()->game_segment_index)->toBe(1)
        ->and($this->lesson->fresh()->game_split_count)->toBe(1);
});
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Models\AnimationClip;
use App\Models\AvatarAnimationController;
use App\Models\Lesson;
use App\Models\Scene;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step3SceneConfigurator extends Component
{
    public Lesson $lesson;
    public ?int   $selectedSceneId = null;
    public ?Scene $selectedScene   = null;
    public bool   $inspectorOpen   = true;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
        $this->lesson->update(['status' => LessonStatus::Configuring, 'wizard_step' => 3]);

        $first = $this->lesson->scenes()->ordered()->first();
        if ($first) {
            $this->selectSceneInternal($first->id);
        }
    }

    #[Computed]
    public function scenes()
    {
        return $this->lesson->scenes()->ordered()->get();
    }

    #[Computed]
    public function animationClips()
    {
        $avatar = $this->lesson->avatar;
        if (! $avatar) return collect();

        $ctrl = AvatarAnimationController::where('avatar_id', $avatar->id)->first();
        $ids  = collect($ctrl?->controller ?? [])->flatten()->all();
        return AnimationClip::whereIn('id', $ids)->orderBy('category')->orderBy('sort_order')->get();
    }

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    private function selectSceneInternal(int $id): void
    {
        $scene = $this->lesson->scenes()->findOrFail($id);
        $this->selectedSceneId = $id;
        $this->selectedScene   = $scene;
        $this->dispatch('scene:load', payload: [
            'sceneId'         => $scene->id,
            'imageUrl'        => $scene->image_path ? asset('storage/' . $scene->image_path) : null,
            'animationClipId' => $scene->animation_clip_id,
            'year'            => $scene->year,
            'location'        => $scene->location,
            'kind'            => $scene->kind,
            'duration'        => $scene->duration_seconds,
        ]);
    }

    public function saveSelected(): void
    {
        if (! $this->selectedScene) return;
        $this->selectedScene->save();

        if ($this->selectedScene->isDirty('script_segment')) {
            $this->selectedScene->update([
                // mark audio stale so player refuses to play until re-narrate
                'audio_script_hash' => null,
            ]);
        }
    }

    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $idx => $id) {
            Scene::where('lesson_id', $this->lesson->id)
                ->where('id', (int) $id)
                ->update(['order' => $idx + 1]);
        }
    }

    public function regenerate(int $sceneId, string $asset): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $scene->update(['status' => 'generating', 'error_message' => null]);

        match ($asset) {
            'script' => GenerateSceneScript::dispatch($scene->id),
            'image'  => GenerateSceneImage::dispatch($scene->id),
            'audio'  => GenerateSceneAudio::dispatch($scene->id),
            default  => null,
        };
    }

    public function addScene(): void
    {
        $next = ((int) $this->lesson->scenes()->max('order')) + 1;
        $scene = Scene::create([
            'lesson_id'   => $this->lesson->id,
            'order'       => $next,
            'kind'        => 'narration',
            'image_style' => $this->lesson->image_style,
            'status'      => 'pending',
        ]);
        $this->selectSceneInternal($scene->id);
    }

    public function deleteScene(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $wasGame = $scene->kind === 'game';
        $scene->delete();

        // Reindex order
        $remaining = $this->lesson->scenes()->ordered()->get();
        foreach ($remaining as $idx => $s) {
            $s->update(['order' => $idx + 1]);
        }

        if ($wasGame) {
            $games = $remaining->where('kind', 'game')->values();
            foreach ($games as $idx => $g) {
                $g->update(['game_segment_index' => $idx + 1]);
            }
            $this->lesson->update(['game_split_count' => max(1, $games->count())]);
        }

        if ($this->selectedSceneId === $sceneId) {
            $first = $this->lesson->scenes()->ordered()->first();
            $this->selectedSceneId = $first?->id;
            $this->selectedScene   = $first;
        }
    }

    public function continueToPreview(): void
    {
        $this->lesson->update(['wizard_step' => 4, 'status' => LessonStatus::Previewable]);
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 4], navigate: true);
    }

    public function render()
    {
        return view('livewire.wizard.step3-scene-configurator');
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Wizard/Step3SceneConfiguratorTest.php
git add app/Livewire/Wizard/Step3SceneConfigurator.php tests/Feature/Wizard/Step3SceneConfiguratorTest.php
git commit -m "feat(wizard): Step 3 Scene Configurator component"
```

---

## Task 2: Shared `<x-lesson.timeline>` component

**Files:**
- Create: `resources/views/components/lesson/timeline.blade.php`
- Create: `resources/views/components/lesson/scene-thumb.blade.php`

- [ ] **Step 1: Scene thumb**

```blade
@props([
    'scene'      => null,
    'selected'   => false,
    'startTime'  => 0,
    'widthPct'   => 10,
])

<button type="button"
        wire:key="scene-thumb-{{ $scene->id }}"
        wire:click="selectScene({{ $scene->id }})"
        data-scene-id="{{ $scene->id }}"
        style="width: {{ $widthPct }}%;"
        @class([
            'group relative shrink-0 rounded-xl overflow-hidden aspect-video transition-all',
            'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'        => $selected,
            'ring-1 ring-slate-700/50 hover:ring-slate-500'                    => ! $selected && $scene->status !== 'failed',
            'ring-1 ring-rose-500/50'                                          => $scene->status === 'failed',
        ])>
    @if ($scene->kind === 'game')
        <div class="w-full h-full bg-teal-700/30 border border-teal-600/30 flex flex-col items-center justify-center text-white">
            <span class="text-2xl">🎲</span>
            <span class="text-[10px] font-bold tracking-widest mt-1">GAME</span>
            <span class="text-[9px] opacity-70">Seg {{ $scene->game_segment_index }}</span>
        </div>
    @else
        <img src="{{ $scene->image_path ? asset('storage/' . $scene->image_path) : asset('assets/scene-placeholder.png') }}"
             class="w-full h-full object-cover" alt="" />
        <span class="absolute bottom-0 inset-x-0 bg-black/55 text-[9px] text-white px-1 py-0.5">
            {{ $scene->year ?? '—' }} · {{ $scene->location ?? '—' }}
        </span>
    @endif

    @if ($scene->status === 'failed')
        <span class="absolute top-1 right-1 w-4 h-4 rounded-full bg-rose-500 text-white text-[8px] flex items-center justify-center">!</span>
    @endif
</button>
```

- [ ] **Step 2: Timeline component**

```blade
@props([
    'scenes'          => collect(),
    'selectedSceneId' => null,
    'editable'        => true,
])

@php
    $total = max(1, $scenes->sum(fn ($s) => $s->duration_seconds ?: 8));
@endphp

<div {{ $attributes->merge(['class' => 'fixed bottom-0 inset-x-0 z-30 bg-base-300/85 backdrop-blur border-t border-slate-700/40']) }}>
    <div class="max-w-screen-2xl mx-auto px-4 py-3">

        {{-- Ruler --}}
        <div class="relative h-3 mb-1">
            @for ($t = 0; $t <= $total; $t += 5)
                <span class="absolute top-0 text-[9px] text-slate-500"
                      style="left: {{ ($t / $total) * 100 }}%; transform: translateX(-50%);">
                    {{ sprintf('%d:%02d', intdiv($t, 60), $t % 60) }}
                </span>
            @endfor
        </div>

        {{-- Video track --}}
        <div id="timeline-track"
             class="flex gap-1 items-stretch min-h-[64px]"
             @if ($editable) wire:sortable="reorder" @endif>
            @php $cursor = 0; @endphp
            @foreach ($scenes as $scene)
                @php
                    $dur = $scene->duration_seconds ?: 8;
                    $w   = ($dur / $total) * 100;
                @endphp
                <x-lesson.scene-thumb :scene="$scene"
                                      :selected="$scene->id === $selectedSceneId"
                                      :start-time="$cursor"
                                      :width-pct="$w" />
                @php $cursor += $dur; @endphp
            @endforeach

            @if ($editable)
                <button type="button" wire:click="addScene"
                        class="shrink-0 w-9 h-9 rounded-lg border-2 border-slate-600 hover:border-amber-400 transition-all flex items-center justify-center text-slate-400 hover:text-amber-300"
                        title="Add scene">
                    +
                </button>
            @endif
        </div>

        {{-- Script track --}}
        <div class="flex gap-1 mt-1 text-[10px] text-slate-400">
            @php $cursor = 0; @endphp
            @foreach ($scenes as $scene)
                @php $dur = $scene->duration_seconds ?: 8; $w = ($dur / $total) * 100; @endphp
                <div style="width: {{ $w }}%;" class="truncate px-1">
                    {{ \Illuminate\Support\Str::limit($scene->script_segment ?? '', 60) }}
                </div>
            @endforeach
        </div>
    </div>
</div>

@if ($editable)
@push('scripts')
<script>
document.addEventListener('livewire:initialized', () => {
    const track = document.getElementById('timeline-track')
    if (!track || !window.Sortable) return
    new window.Sortable(track, {
        animation: 150,
        filter: '[data-no-drag]',
        onEnd: () => {
            const ids = [...track.querySelectorAll('[data-scene-id]')].map(el => el.dataset.sceneId)
            Livewire.dispatch('timeline:reordered', { ids })
        },
    })
})
</script>
@endpush
@endif
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/lesson/timeline.blade.php \
        resources/views/components/lesson/scene-thumb.blade.php
git commit -m "feat(wizard): shared lesson timeline component"
```

---

## Task 3: Inspector partials (narration + game variants)

**Files:**
- Create: `resources/views/components/lesson/scene-inspector-narration.blade.php`
- Create: `resources/views/components/lesson/scene-inspector-game.blade.php`

- [ ] **Step 1: Narration inspector**

```blade
@props(['scene' => null, 'clips' => collect()])

<div class="space-y-3 text-sm">
    <h3 class="text-amber-300 font-semibold">Scene {{ $scene->order }}</h3>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Year</span>
        <input type="text" wire:model.blur="selectedScene.year" wire:change="saveSelected"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Location</span>
        <input type="text" wire:model.blur="selectedScene.location" wire:change="saveSelected"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Style</span>
        <select wire:model.live="selectedScene.image_style" wire:change="saveSelected"
                class="select select-sm select-bordered bg-slate-900 mt-1">
            @foreach (['realistic','sketched','painted','cinematic','comic','animation'] as $s)
                <option value="{{ $s }}">{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Animation</span>
        <select wire:model.live="selectedScene.animation_clip_id" wire:change="saveSelected"
                class="select select-sm select-bordered bg-slate-900 mt-1">
            <option value="">— none —</option>
            @foreach ($clips as $clip)
                <option value="{{ $clip->id }}">{{ $clip->name }} ({{ $clip->category }})</option>
            @endforeach
        </select>
    </label>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Skybox</p>
        <div class="flex items-center gap-3">
            @if ($scene->image_path)
                <img src="{{ asset('storage/' . $scene->image_path) }}" class="w-20 h-12 rounded object-cover" />
            @endif
            <button type="button" wire:click="regenerate({{ $scene->id }}, 'image')"
                    class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">🔄 Regenerate</button>
        </div>
        <details class="text-xs">
            <summary class="cursor-pointer text-slate-400">Prompt</summary>
            <textarea wire:model.blur="selectedScene.image_prompt" wire:change="saveSelected" rows="3"
                      class="textarea textarea-sm textarea-bordered bg-slate-900 mt-1 w-full"></textarea>
        </details>
    </div>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Script</p>
        <textarea wire:model.blur="selectedScene.script_segment" wire:change="saveSelected" rows="8"
                  class="textarea textarea-sm textarea-bordered bg-slate-900 w-full"></textarea>
        <button type="button" wire:click="regenerate({{ $scene->id }}, 'audio')"
                class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">🔊 Re-narrate</button>
    </div>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this scene?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">⋯ Delete scene</button>
</div>
```

- [ ] **Step 2: Game inspector**

```blade
@props(['scene' => null])

<div class="space-y-3 text-sm">
    <header>
        <h3 class="text-amber-300 font-semibold flex items-center gap-2">🎲 {{ $scene->lesson->strategyGame?->title ?? 'Strategy Game' }}</h3>
        <p class="text-xs text-slate-400">Segment {{ $scene->game_segment_index }} of {{ $scene->lesson->game_split_count }}</p>
    </header>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Segment intro</p>
        <textarea wire:model.blur="selectedScene.script_segment" wire:change="saveSelected" rows="6"
                  class="textarea textarea-sm textarea-bordered bg-slate-900 w-full"></textarea>
        <button type="button" wire:click="regenerate({{ $scene->id }}, 'audio')"
                class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">🔊 Re-narrate</button>
    </div>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Background image</p>
        <div class="flex items-center gap-3">
            @if ($scene->image_path)
                <img src="{{ asset('storage/' . $scene->image_path) }}" class="w-20 h-12 rounded object-cover" />
            @endif
            <button type="button" wire:click="regenerate({{ $scene->id }}, 'image')"
                    class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">🔄 Regenerate</button>
        </div>
    </div>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Challenge duration (mm:ss)</span>
        <input type="text" x-data="{ v: '{{ sprintf('%d:%02d', intdiv((int) ($scene->duration_seconds ?? 0), 60), ((int) ($scene->duration_seconds ?? 0)) % 60) }}' }"
               x-model="v"
               @blur="
                   const [m, s] = v.split(':').map(Number)
                   $wire.set('selectedScene.duration_seconds', (m*60) + (s||0))
                   $wire.call('saveSelected')"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this game segment?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">⋯ Delete segment</button>
</div>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/lesson/scene-inspector-narration.blade.php \
        resources/views/components/lesson/scene-inspector-game.blade.php
git commit -m "feat(wizard): scene inspector partials"
```

---

## Task 4: Wizard bridge JS

**Files:**
- Create: `resources/js/scene/wizard-bridge.js`
- Modify: `resources/js/app.js` (import bridge)

- [ ] **Step 1: Implement bridge**

```js
// resources/js/scene/wizard-bridge.js
import * as THREE from 'three'

/**
 * Glue between Livewire dispatches and LessonScene modules.
 * Reuses the existing global window.Avatar3DPlayer instance (mounted by avatar-3d.js).
 */
export function mountWizardScene({ canvasEl, overlayEl, timerEl, scenes }) {
    const Scene = window.LessonScene
    const Three = THREE

    const player = window.__avatar3d  // assumed singleton from avatar-3d.js boot
    if (!player) {
        console.warn('[wizard-bridge] Avatar3DPlayer not initialized; aborting.')
        return null
    }

    const skybox  = new Scene.SkyboxSphere(player.scene)
    const overlay = new Scene.SceneOverlay(overlayEl);  overlay.mount()
    const timer   = new Scene.GameTimerOverlay(timerEl)

    const adapter = {
        skybox,
        overlay,
        timer,
        avatar: {
            setClip: (clipId) => Livewire.dispatch('avatar3d:setClip', { clipId }),
            speak:   ({ audioUrl, alignment, text }) => new Promise(resolve => {
                Livewire.dispatch('avatar3d:speak', { audioUrl, alignment, text })
                // resolve when audio finishes — avatar-3d.js fires a window event we listen for
                const handler = () => { window.removeEventListener('avatar3d:speakend', handler); resolve() }
                window.addEventListener('avatar3d:speakend', handler)
            }),
        },
    }

    const sequencer = new Scene.SceneTimelinePlayer({ scenes, ...adapter })

    // Livewire → bridge
    window.Livewire.on('scene:load', async ({ payload }) => {
        await skybox.crossfadeTo(payload.imageUrl, 600)
        overlay.update({ year: payload.year, location: payload.location })
        if (payload.kind === 'game') {
            timer.show({ durationSeconds: payload.duration || 0 })
        } else {
            timer.hide()
        }
    })

    return { sequencer, skybox, overlay, timer }
}
```

- [ ] **Step 2: Wire in `app.js`**

Append:
```js
import { mountWizardScene } from './scene/wizard-bridge.js'
window.LessonScene.mountWizardScene = mountWizardScene
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/scene/wizard-bridge.js resources/js/app.js
git commit -m "feat(scene): wizard bridge — Livewire ↔ LessonScene"
```

---

## Task 5: Step 3 view

**Files:**
- Create: `resources/views/livewire/wizard/step3-scene-configurator.blade.php`

- [ ] **Step 1: Build the view**

```blade
<div class="contents" x-data="step3SceneConfigurator()" x-init="init()">

    {{-- Fullscreen canvas wrapper --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root">
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
    </div>

    {{-- Inspector toggle chip --}}
    <button type="button" @click="inspectorOpen = !inspectorOpen"
            class="fixed top-4 right-4 z-40 px-3 py-2 rounded-xl bg-slate-900/80 backdrop-blur border border-slate-700 text-xs text-slate-200 hover:border-amber-400">
        ≡ Inspector
    </button>

    {{-- Inspector drawer --}}
    <aside x-show="inspectorOpen" x-transition.opacity
           class="fixed top-0 right-0 bottom-24 z-30 w-[360px] bg-base-300/90 backdrop-blur border-l border-slate-700/40 p-4 overflow-y-auto">
        @if ($selectedScene)
            @if ($selectedScene->kind === 'game')
                <x-lesson.scene-inspector-game :scene="$selectedScene" />
            @else
                <x-lesson.scene-inspector-narration :scene="$selectedScene" :clips="$this->animationClips" />
            @endif
        @else
            <p class="text-sm text-slate-400">No scene selected.</p>
        @endif
    </aside>

    {{-- Step nav floating buttons --}}
    <div class="fixed bottom-28 right-4 z-30 flex gap-2">
        <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2]) }}"
           wire:navigate class="btn btn-sm btn-outline">← Back</a>
        <button wire:click="continueToPreview"
                class="btn btn-sm bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Continue to Preview →</button>
    </div>

    {{-- Bottom timeline --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" editable />

    @push('scripts')
    <script>
        function step3SceneConfigurator() {
            return {
                inspectorOpen: true,
                async init() {
                    this.inspectorOpen = (localStorage.getItem('wizard.inspector') ?? '1') === '1'
                    this.$watch('inspectorOpen', v => localStorage.setItem('wizard.inspector', v ? '1' : '0'))

                    if (!window.LessonScene?.mountWizardScene) return
                    const scenes = @json($this->scenes->map->only([
                        'id','kind','year','location','image_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id',
                    ]))
                    const overlayEl = document.getElementById('lesson-overlay')
                    const timerEl   = document.getElementById('lesson-game-overlay')
                    const canvasEl  = document.getElementById('lesson-canvas')
                    window.__lessonStage = window.LessonScene.mountWizardScene({ canvasEl, overlayEl, timerEl, scenes })

                    // Initial scene
                    Livewire.on('scene:load', () => {})
                },
            }
        }

        window.addEventListener('timeline:reordered', e => Livewire.dispatch('reorder', { orderedIds: e.detail.ids }))
    </script>
    @endpush
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/wizard/step3-scene-configurator.blade.php
git commit -m "feat(wizard): Step 3 view — fullscreen canvas + inspector + timeline"
```

---

## Task 6: Sortable bridge for Livewire reorder

The timeline component already uses `window.Sortable` and dispatches `timeline:reordered`. The Step 3 view listens for it and forwards to Livewire `reorder`. Ensure `Sortable` is loaded.

- [ ] **Step 1: Check Sortable availability**

```bash
grep -R "Sortable" resources/js/ | head
```
Expected: Avatar Lab already imports `sortablejs` (existing). If not, install:
```bash
npm install --save sortablejs
```
And add to `app.js`:
```js
import Sortable from 'sortablejs'
window.Sortable = Sortable
```

- [ ] **Step 2: Build + commit (if sortable added)**

```bash
npm run build
git add package.json package-lock.json resources/js/app.js
git commit -m "chore(js): expose SortableJS globally"
```

---

## Task 7: Full suite

- [ ] **Step 1:** `composer test` and `npm test`
- [ ] **Step 2:** dev smoke: `composer dev` + visit `/teacher/lessons/{id}/wizard?step=3`, confirm fullscreen renders, scenes appear in timeline, drag works, inspector edits persist.

Done. Plan H consumes the same shared components for Step 4.
