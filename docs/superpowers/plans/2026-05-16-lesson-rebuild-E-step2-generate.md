# Plan E — Step 2 Generate Live Progress

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the placeholder `Step2Generate` Livewire component with the live-progress view that polls scene statuses every 2 s, surfaces per-asset progress (script/image/audio/quiz), supports per-scene retry, and enables "Continue to Configure →" once at least one scene is fully ready.

**Architecture:** `Step2Generate` is a Livewire component on `<livewire:wizard.step2-generate>`. Polled via `wire:poll.2s`. Reads `Lesson::with('scenes')`. Per-scene retry dispatches the matching single job (`GenerateSceneScript`, `GenerateSceneImage`, `GenerateSceneAudio`) — same jobs from Plan C. Outline-level retry re-dispatches `BuildLessonOutline` and deletes prior Scene rows.

**Tech Stack:** Laravel 12, Livewire 3, DaisyUI 5.

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§7).

**Depends on:** Plan C, Plan D.

---

## File Structure

```
app/Livewire/Wizard/Step2Generate.php                          REWRITE (replaces placeholder)
resources/views/livewire/wizard/step2-generate.blade.php       REWRITE
tests/Feature/Wizard/Step2GenerateTest.php                     NEW
```

---

## Task 1: Replace the Step 2 placeholder

**Files:**
- Modify: `app/Livewire/Wizard/Step2Generate.php`
- Test: `tests/Feature/Wizard/Step2GenerateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Livewire\Wizard\Step2Generate;
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
        'status' => LessonStatus::ScenesGenerating,
    ]);
    Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'generating',
    ]);
    Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration',
        'script_segment' => 'ok', 'image_path' => 'x.png', 'audio_path' => 'a.mp3', 'status' => 'ready',
    ]);
    Scene::create([
        'lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration',
        'status' => 'failed', 'error_message' => 'boom',
    ]);
});

it('shows per-scene asset states', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step2Generate::class, ['lesson' => $this->lesson])
        ->assertSee('Scene 1')
        ->assertSee('Scene 2')
        ->assertSee('Scene 3')
        ->assertSee('boom');
});

it('enables Continue when at least one scene is ready', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step2Generate::class, ['lesson' => $this->lesson])
        ->assertSet('canContinue', true);
});

it('per-asset retry dispatches the matching job for one scene', function (): void {
    Bus::fake();
    $failed = Scene::where('status', 'failed')->first();

    Livewire::actingAs($this->teacher)
        ->test(Step2Generate::class, ['lesson' => $this->lesson])
        ->call('retryAsset', $failed->id, 'script');

    Bus::assertDispatched(GenerateSceneScript::class, fn ($j) => $j->sceneId === $failed->id);
    Bus::assertNotDispatched(GenerateSceneImage::class);
});

it('outline retry deletes scenes and re-dispatches BuildLessonOutline', function (): void {
    Bus::fake();
    $this->lesson->update(['status' => LessonStatus::Failed, 'error_message' => 'outline died']);

    Livewire::actingAs($this->teacher)
        ->test(Step2Generate::class, ['lesson' => $this->lesson])
        ->call('retryOutline');

    expect(Scene::where('lesson_id', $this->lesson->id)->count())->toBe(0);
    Bus::assertDispatched(BuildLessonOutline::class);
});
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

`app/Livewire/Wizard/Step2Generate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Models\Lesson;
use App\Models\Scene;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step2Generate extends Component
{
    public Lesson $lesson;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
    }

    #[Computed]
    public function scenes()
    {
        return $this->lesson->scenes()->ordered()->get();
    }

    #[Computed]
    public function canContinue(): bool
    {
        return $this->lesson->scenes()->where('status', 'ready')->exists();
    }

    #[Computed]
    public function overallProgress(): int
    {
        $scenes = $this->scenes;
        if ($scenes->isEmpty()) {
            return 0;
        }

        $total = $scenes->count() * 3; // script + image + audio per scene
        $done  = 0;
        foreach ($scenes as $s) {
            if ((string) $s->script_segment !== '') $done++;
            if ($s->image_path !== null)            $done++;
            if ($s->audio_path !== null)            $done++;
        }

        return (int) floor(($done / max(1, $total)) * 100);
    }

    public function retryAsset(int $sceneId, string $asset): void
    {
        $scene = Scene::where('lesson_id', $this->lesson->id)->findOrFail($sceneId);
        $scene->update(['status' => 'generating', 'error_message' => null]);

        match ($asset) {
            'script' => GenerateSceneScript::dispatch($scene->id),
            'image'  => GenerateSceneImage::dispatch($scene->id),
            'audio'  => GenerateSceneAudio::dispatch($scene->id),
            default  => null,
        };
    }

    public function retryOutline(): void
    {
        $this->lesson->scenes()->delete();
        $this->lesson->update([
            'status'        => LessonStatus::SourceReady,
            'error_message' => null,
            'outline'       => null,
        ]);
        BuildLessonOutline::dispatch($this->lesson->id);
    }

    public function continueToConfigure(): void
    {
        $this->lesson->update(['wizard_step' => 3]);
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 3], navigate: true);
    }

    public function render()
    {
        return view('livewire.wizard.step2-generate');
    }
}
```

- [ ] **Step 4: Build the view**

`resources/views/livewire/wizard/step2-generate.blade.php`:

```blade
<div class="pt-6 space-y-6" wire:poll.2s>

    @if ($lesson->status === \App\Enums\LessonStatus::Failed)
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-2xl p-6">
            <h2 class="text-lg font-semibold text-rose-300">Generation failed</h2>
            <p class="text-sm text-rose-200/80 mt-1">{{ $lesson->error_message }}</p>
            <button wire:click="retryOutline"
                    class="mt-4 btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Retry outline</button>
        </div>
    @else
        <header class="space-y-1">
            <h2 class="text-lg font-semibold text-amber-300">Generating your lesson…</h2>
            <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                <div class="h-full bg-amber-500 transition-all duration-500"
                     style="width: {{ $this->overallProgress }}%"></div>
            </div>
            <p class="text-xs text-slate-400">{{ $this->overallProgress }}% complete</p>
        </header>

        <div class="space-y-3">
            @foreach ($this->scenes as $scene)
                <div @class([
                    'rounded-2xl p-4 flex items-start gap-4 border',
                    'border-rose-500/40 bg-rose-500/5'    => $scene->status === 'failed',
                    'border-emerald-500/40 bg-emerald-500/5' => $scene->status === 'ready',
                    'border-slate-700 bg-slate-900/40'    => ! in_array($scene->status, ['failed','ready']),
                ])>
                    <div class="text-sm font-semibold text-slate-200 w-24 shrink-0">
                        Scene {{ $scene->order }}
                        @if ($scene->kind === 'game') <span class="ml-1 text-amber-400">🎲</span> @endif
                    </div>
                    <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                        @foreach (['script' => 'Script', 'image' => 'Image', 'audio' => 'Audio'] as $asset => $label)
                            @php
                                $has = match ($asset) {
                                    'script' => (string) $scene->script_segment !== '',
                                    'image'  => $scene->image_path !== null,
                                    'audio'  => $scene->audio_path !== null,
                                };
                            @endphp
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full
                                    {{ $has ? 'bg-emerald-400' : ($scene->status === 'failed' ? 'bg-rose-400' : 'bg-amber-400 animate-pulse') }}"></span>
                                <span class="text-slate-300">{{ $label }}</span>
                                @if (! $has && $scene->status === 'failed')
                                    <button wire:click="retryAsset({{ $scene->id }}, '{{ $asset }}')"
                                            class="ml-auto text-amber-300 hover:text-amber-200 underline">Retry</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($scene->error_message)
                    <p class="text-xs text-rose-300 px-4">{{ $scene->error_message }}</p>
                @endif
            @endforeach
        </div>
    @endif

    <div class="flex justify-end gap-3 pt-2">
        <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 1]) }}"
           wire:navigate class="btn btn-outline">← Edit Settings</a>
        <button wire:click="continueToConfigure"
                @disabled(! $this->canContinue)
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-40">
            Continue to Configure →
        </button>
    </div>

</div>
```

- [ ] **Step 5: Run + pass + commit**

```bash
./vendor/bin/pest tests/Feature/Wizard/Step2GenerateTest.php
git add app/Livewire/Wizard/Step2Generate.php \
        resources/views/livewire/wizard/step2-generate.blade.php \
        tests/Feature/Wizard/Step2GenerateTest.php
git commit -m "feat(wizard): Step 2 live generate progress"
```

---

## Task 2: Auto-redirect on ScenesReady

**Files:**
- Modify: `app/Livewire/Wizard/Step2Generate.php`
- Test: extend `Step2GenerateTest.php`

- [ ] **Step 1: Append test**

```php
it('auto-redirects to step 3 when the lesson reaches ScenesReady', function (): void {
    $this->lesson->update(['status' => \App\Enums\LessonStatus::ScenesReady]);

    Livewire::actingAs($this->teacher)
        ->test(\App\Livewire\Wizard\Step2Generate::class, ['lesson' => $this->lesson])
        ->assertRedirect(route('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 3]));
});
```

- [ ] **Step 2: Update `mount()` to redirect**

In `Step2Generate::mount()`, after the authorisation check, add:

```php
if ($lesson->status === LessonStatus::ScenesReady) {
    $lesson->update(['wizard_step' => 3]);
    $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 3], navigate: true);
    return;
}
```

- [ ] **Step 3: Run + commit**

```bash
./vendor/bin/pest tests/Feature/Wizard/Step2GenerateTest.php
git add app/Livewire/Wizard/Step2Generate.php tests/Feature/Wizard/Step2GenerateTest.php
git commit -m "feat(wizard): Step 2 auto-advance when ScenesReady"
```

Done.
