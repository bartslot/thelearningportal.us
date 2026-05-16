# Plan D — LessonWizard scaffolding + Step 1 Settings

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the `LessonWizard` parent Livewire component, its routes, and the fully-functional Step 1 Settings child. Step 1 persists a `Lesson(status=Draft)`, attaches a `LessonSource`, and kicks off `startGenerationPipeline()` when the teacher clicks "Generate lesson →".

**Architecture:** One parent Livewire `LessonWizard` owns `$lesson` and `$step`. Children are full-page Livewire pages too, routed via `?step=N`. Step 1 is a single form Livewire component. Avatar picker, style picker, strategy game picker, intel-drop replacement (game_split_count), portrait, lesson_code, duration. Validation via `#[Validate]` attributes. Old `CreateLesson` and `LessonSettings` routes are removed.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, Tailwind v4 + DaisyUI 5 (`learningportal` theme), `phpoffice/phpword` + `smalot/pdfparser` (already installed in Plan B).

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§2, §4, §6, §10).

**Depends on:** Plan A (Lesson columns, LessonSource model, LessonStatus cases), Plan B (DocumentExtractor, GradeBandStyleRecommender).

---

## File Structure

```
routes/web.php                                                  MODIFY
app/Livewire/
  LessonWizard.php                                              NEW (parent)
  Wizard/
    Step1Settings.php                                           NEW
app/Http/Requests/
  StoreWizardSettingsRequest.php                                NEW  (Form Request for Step 1)
resources/views/
  livewire/
    lesson-wizard.blade.php                                     NEW
    wizard/
      step1-settings.blade.php                                  NEW
  components/
    wizard/
      step-indicator.blade.php                                  NEW
      style-picker.blade.php                                    NEW
      avatar-picker.blade.php                                   NEW
      game-picker.blade.php                                     NEW
app/Livewire/CreateLesson.php                                   DELETE
app/Livewire/LessonSettings.php                                 DELETE
resources/views/livewire/create-lesson.blade.php                DELETE
resources/views/livewire/lesson-settings.blade.php              DELETE
tests/Feature/
  LessonWizardRoutesTest.php                                    NEW
  Wizard/
    Step1SettingsTest.php                                       NEW
```

---

## Task 1: Routes — replace `CreateLesson` with wizard

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/LessonWizardRoutesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('renders the wizard at /teacher/lessons/create', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/teacher/lessons/create')
        ->assertOk()
        ->assertSeeLivewire(\App\Livewire\LessonWizard::class);
});

it('resumes the wizard for an existing lesson', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id' => $teacher->id, 'topic' => 'X',
        'subject' => 'history', 'grade_level' => '9th',
    ]);

    $this->actingAs($teacher)
        ->get("/teacher/lessons/{$lesson->id}/wizard?step=1")
        ->assertOk()
        ->assertSeeLivewire(\App\Livewire\LessonWizard::class);
});

it('forbids accessing another teacher\'s wizard', function (): void {
    $owner   = User::factory()->create();
    $other   = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id' => $owner->id, 'topic' => 'X',
        'subject' => 'history', 'grade_level' => '9th',
    ]);

    $this->actingAs($other)
        ->get("/teacher/lessons/{$lesson->id}/wizard?step=1")
        ->assertForbidden();
});

it('removes the legacy create route\'s component reference', function (): void {
    expect(class_exists(\App\Livewire\CreateLesson::class))->toBeFalse();
    expect(class_exists(\App\Livewire\LessonSettings::class))->toBeFalse();
});
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Edit `routes/web.php`**

Inside the `Route::middleware(['auth'])->prefix('teacher')->name('teacher.')->group(...)` block, replace these lines:

```php
Route::get('/lessons/create', CreateLesson::class)->name('lessons.create');
Route::get('/lessons/{lesson}', LessonShow::class)->name('lessons.show');
```

with:

```php
Route::get('/lessons/create', \App\Livewire\LessonWizard::class)
    ->name('lessons.create');

Route::get('/lessons/{lesson}/wizard', \App\Livewire\LessonWizard::class)
    ->name('lessons.wizard');
```

Remove the `LessonShow` and `LessonSettings` route imports + routes (the existing `lessons.publish` and `lessons.retry` patch/post routes stay).

- [ ] **Step 4: Delete legacy components and views**

```bash
rm app/Livewire/CreateLesson.php
rm app/Livewire/LessonSettings.php
rm app/Livewire/LessonShow.php
rm -f resources/views/livewire/create-lesson.blade.php
rm -f resources/views/livewire/lesson-settings.blade.php
rm -f resources/views/livewire/lesson-show.blade.php
```

- [ ] **Step 5: Re-run the test (some sub-assertions still fail until Task 2 lands the wizard component)**

```bash
./vendor/bin/pest tests/Feature/LessonWizardRoutesTest.php
```
Expected: tests fail at `assertSeeLivewire`. The "class_exists … false" assertions should already pass.

- [ ] **Step 6: Commit work-in-progress**

```bash
git add routes/web.php app/Livewire tests/Feature/LessonWizardRoutesTest.php \
        resources/views/livewire
git commit -m "feat(wizard): remove legacy create/settings/show; route to LessonWizard (WIP)"
```

---

## Task 2: `LessonWizard` parent component

**Files:**
- Create: `app/Livewire/LessonWizard.php`
- Create: `resources/views/livewire/lesson-wizard.blade.php`
- Create: `resources/views/components/wizard/step-indicator.blade.php`

- [ ] **Step 1: Implement the parent**

`app/Livewire/LessonWizard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Lesson;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class LessonWizard extends Component
{
    public ?Lesson $lesson = null;

    #[Url(as: 'step')]
    public int $step = 1;

    public function mount(?Lesson $lesson = null): void
    {
        if ($lesson?->exists) {
            abort_unless($lesson->teacher_id === auth()->id(), 403);
            $this->lesson = $lesson;
            $this->step   = max(1, min(4, (int) ($lesson->wizard_step ?? $this->step ?: 1)));
        }
    }

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(4, $step));
        if ($this->lesson) {
            $this->lesson->update(['wizard_step' => $this->step]);
        }
    }

    public function render(): View
    {
        return view('livewire.lesson-wizard')
            ->layout('components.layouts.app', ['title' => 'Create Lesson']);
    }
}
```

- [ ] **Step 2: Parent view**

`resources/views/livewire/lesson-wizard.blade.php`:

```blade
<div class="min-h-screen bg-base-200 text-base-content" wire:key="lesson-wizard-{{ $lesson?->id ?? 'new' }}">

    <x-wizard.step-indicator :step="$step" />

    <div class="max-w-5xl mx-auto px-4 pb-24">
        @if ($step === 1)
            <livewire:wizard.step1-settings :lesson="$lesson" :key="'step1-' . ($lesson?->id ?? 'new')" />
        @elseif ($step === 2)
            <livewire:wizard.step2-generate :lesson="$lesson" :key="'step2-' . $lesson?->id" />
        @elseif ($step === 3)
            <livewire:wizard.step3-scene-configurator :lesson="$lesson" :key="'step3-' . $lesson?->id" />
        @elseif ($step === 4)
            <livewire:wizard.step4-preview :lesson="$lesson" :key="'step4-' . $lesson?->id" />
        @endif
    </div>

</div>
```

> Note: child components 2/3/4 are scaffolded as placeholders in this plan (Task 8) so the wizard renders end-to-end. Plans E/G/H replace those placeholders.

- [ ] **Step 3: Step indicator partial**

`resources/views/components/wizard/step-indicator.blade.php`:

```blade
@props(['step' => 1])

@php
    $steps = [
        1 => 'Settings',
        2 => 'Generate',
        3 => 'Configure',
        4 => 'Preview',
    ];
@endphp

<div class="bg-base-300 border-b border-base-100/10">
    <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-3 text-xs">
        @foreach ($steps as $n => $label)
            <div class="flex items-center gap-2">
                <span @class([
                    'w-7 h-7 rounded-full flex items-center justify-center font-semibold',
                    'bg-amber-500 text-slate-950' => $n === $step,
                    'bg-slate-700 text-slate-300' => $n !== $step,
                ])>{{ $n }}</span>
                <span @class([
                    'text-amber-300' => $n === $step,
                    'text-slate-400' => $n !== $step,
                ])>{{ $label }}</span>
            </div>
            @unless ($loop->last)
                <span class="text-slate-600">›</span>
            @endunless
        @endforeach
    </div>
</div>
```

- [ ] **Step 4: Commit (test still red — child components missing)**

```bash
git add app/Livewire/LessonWizard.php resources/views/livewire/lesson-wizard.blade.php \
        resources/views/components/wizard/step-indicator.blade.php
git commit -m "feat(wizard): LessonWizard parent + step indicator"
```

---

## Task 3: `StoreWizardSettingsRequest` Form Request

**Files:**
- Create: `app/Http/Requests/StoreWizardSettingsRequest.php`

- [ ] **Step 1: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Support\ImageStyleTemplate;
use Illuminate\Foundation\Http\FormRequest;

class StoreWizardSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Livewire handles auth context; route enforces teacher scope
    }

    public function rules(): array
    {
        return [
            'topic'            => ['required', 'string', 'min:3', 'max:100'],
            'subject'          => ['required', 'in:history,science,literature,civics'],
            'grade_level'      => ['required', 'string', 'max:50'],
            'tone'             => ['nullable', 'string', 'max:150'],
            'details'          => ['nullable', 'string', 'max:500'],
            'source_mode'      => ['required', 'in:wikipedia,upload,both'],
            'sourceUpload'     => ['nullable', 'file', 'mimes:pdf,docx', 'max:10240'],
            'image_style'      => ['required', 'in:' . implode(',', ImageStyleTemplate::styles())],
            'avatar_id'        => ['required', 'exists:avatars,id'],
            'strategy_game_id' => ['nullable', 'exists:strategy_games,id'],
            'team_count'       => ['nullable', 'integer', 'min:1', 'max:8'],
            'game_split_count' => ['required', 'integer', 'min:1', 'max:4'],
            'lesson_code'      => ['required', 'string', 'alpha_num', 'min:4', 'max:8'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:59'],
            'portrait'         => ['nullable', 'image', 'max:4096'],
        ];
    }
}
```

(Rules are reused by Livewire via the request class's `rules()` array.)

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/StoreWizardSettingsRequest.php
git commit -m "feat(wizard): StoreWizardSettingsRequest"
```

---

## Task 4: Style picker, avatar picker, game picker components

**Files:**
- Create: `resources/views/components/wizard/style-picker.blade.php`
- Create: `resources/views/components/wizard/avatar-picker.blade.php`
- Create: `resources/views/components/wizard/game-picker.blade.php`

- [ ] **Step 1: Style picker**

```blade
@props([
    'styles'   => [],      // array of ['key' => 'realistic', 'label' => 'Realistic', 'thumb' => '/assets/style-realistic.png']
    'selected' => 'realistic',
    'recommended' => [],   // array of style keys
    'wireModel' => 'image_style',
])

<div class="grid grid-cols-3 md:grid-cols-6 gap-3">
    @foreach ($styles as $s)
        <button type="button"
                wire:click="$set('{{ $wireModel }}', '{{ $s['key'] }}')"
                @class([
                    'relative aspect-square rounded-xl overflow-hidden transition-all',
                    'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900' => $selected === $s['key'],
                    'ring-1 ring-slate-700/50 hover:ring-slate-500' => $selected !== $s['key'],
                ])>
            <img src="{{ $s['thumb'] }}" alt="{{ $s['label'] }}" class="w-full h-full object-cover" />
            <span class="absolute bottom-1 left-1 right-1 text-[10px] uppercase tracking-wider text-white drop-shadow">{{ $s['label'] }}</span>
            @if (in_array($s['key'], $recommended, true))
                <span class="absolute top-1 right-1 text-[8px] bg-amber-500 text-slate-950 font-bold px-1.5 py-0.5 rounded">RECOMMENDED</span>
            @endif
        </button>
    @endforeach
</div>
```

- [ ] **Step 2: Avatar picker**

```blade
@props([
    'avatars'    => collect(),
    'selectedId' => null,
])

<div class="flex gap-3 overflow-x-auto pb-2">
    @foreach ($avatars as $avatar)
        <button type="button"
                wire:click="$set('avatar_id', {{ $avatar->id }})"
                @class([
                    'shrink-0 w-20 h-20 rounded-xl overflow-hidden transition-all relative',
                    'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900' => $selectedId === $avatar->id,
                    'ring-1 ring-slate-700/50 hover:ring-slate-500' => $selectedId !== $avatar->id,
                ])>
            <img src="{{ $avatar->portrait_url ?? asset('assets/avatar-fallback.png') }}"
                 alt="{{ $avatar->name }}"
                 class="w-full h-full object-cover" />
            <span class="absolute bottom-0 left-0 right-0 text-[9px] bg-black/55 text-white py-0.5">{{ $avatar->name }}</span>
        </button>
    @endforeach
</div>
```

- [ ] **Step 3: Game picker**

```blade
@props([
    'games'        => collect(),
    'selectedId'   => null,
    'teamCount'    => null,
    'splitCount'   => 1,
    'maxSplit'     => 4,
])

<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div>
        <label class="text-xs uppercase tracking-wider text-slate-400">Strategy game</label>
        <select wire:model.live="strategy_game_id"
                class="select select-bordered w-full bg-slate-900 mt-1">
            <option value="">— no game —</option>
            @foreach ($games as $g)
                <option value="{{ $g->id }}">{{ $g->title }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="text-xs uppercase tracking-wider text-slate-400">Teams</label>
        <input type="number" wire:model="team_count" min="1" max="8"
               class="input input-bordered w-full bg-slate-900 mt-1" />
    </div>

    <div class="md:col-span-2">
        <label class="text-xs uppercase tracking-wider text-slate-400">Split game into segments</label>
        <div class="flex gap-2 mt-1">
            @for ($i = 1; $i <= $maxSplit; $i++)
                <button type="button"
                        wire:click="$set('game_split_count', {{ $i }})"
                        @class([
                            'flex-1 py-2 rounded-lg border-2 text-sm font-semibold transition-all',
                            'border-amber-400 bg-amber-500/10 text-amber-300' => $splitCount === $i,
                            'border-slate-600 text-slate-300 hover:border-slate-400' => $splitCount !== $i,
                        ])>{{ $i }}</button>
            @endfor
        </div>
        <p class="text-xs text-slate-500 mt-1">Game pauses for narration between segments — useful for drip-feeding new information.</p>
    </div>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/wizard/
git commit -m "feat(wizard): style/avatar/game pickers"
```

---

## Task 5: `Step1Settings` Livewire component

**Files:**
- Create: `app/Livewire/Wizard/Step1Settings.php`
- Test: `tests/Feature/Wizard/Step1SettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Livewire\Wizard\Step1Settings;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\StrategyGame;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->teacher = User::factory()->create();
    $this->avatar  = Avatar::create([
        'name' => 'Test', 'slug' => 'test-1', 'gender' => 'male',
        'voice_provider' => 'elevenlabs', 'voice_id' => 'v',
        'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
    ]);
    $this->game = StrategyGame::create([
        'slug' => 'g', 'title' => 'G', 'description' => 'x', 'subject' => 'History',
        'topic_keywords' => [], 'instructions' => 'i',
    ]);
});

it('saves a Wikipedia-only lesson as draft', function (): void {
    Livewire::actingAs($this->teacher)
        ->test(Step1Settings::class, ['lesson' => null])
        ->set('topic',            'Roman Empire')
        ->set('subject',          'history')
        ->set('grade_level',      '7th grade')
        ->set('source_mode',      'wikipedia')
        ->set('image_style',      'painted')
        ->set('avatar_id',        $this->avatar->id)
        ->set('strategy_game_id', $this->game->id)
        ->set('team_count',       2)
        ->set('game_split_count', 1)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $lesson = Lesson::where('teacher_id', $this->teacher->id)->first();
    expect($lesson)->not->toBeNull()
        ->and($lesson->status)->toBe(LessonStatus::Draft)
        ->and($lesson->image_style)->toBe('painted')
        ->and($lesson->game_split_count)->toBe(1);

    expect(LessonSource::where('lesson_id', $lesson->id)->where('kind', 'wikipedia')->exists())->toBeTrue();
});

it('saves an upload-mode lesson and stores extracted text', function (): void {
    $file = UploadedFile::fake()->createWithContent(
        'source.pdf',
        file_get_contents(base_path('tests/Fixtures/documents/sample.pdf')),
    );

    Livewire::actingAs($this->teacher)
        ->test(Step1Settings::class, ['lesson' => null])
        ->set('topic',            'Napoleon')
        ->set('subject',          'history')
        ->set('grade_level',      '9th grade')
        ->set('source_mode',      'upload')
        ->set('sourceUpload',     $file)
        ->set('image_style',      'cinematic')
        ->set('avatar_id',        $this->avatar->id)
        ->set('game_split_count', 1)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $source = LessonSource::first();
    expect($source->kind)->toBe('pdf')
        ->and($source->extracted_text)->toContain('Napoleon');
});

it('dispatches BuildLessonOutline on generate', function (): void {
    Bus::fake();

    Livewire::actingAs($this->teacher)
        ->test(Step1Settings::class, ['lesson' => null])
        ->set('topic',            'Roman Empire')
        ->set('subject',          'history')
        ->set('grade_level',      '7th grade')
        ->set('source_mode',      'wikipedia')
        ->set('image_style',      'painted')
        ->set('avatar_id',        $this->avatar->id)
        ->set('game_split_count', 1)
        ->call('generate')
        ->assertHasNoErrors();

    Bus::assertDispatched(BuildLessonOutline::class);
});

it('rejects upload over 10MB or wrong mime', function (): void {
    $tooBig = UploadedFile::fake()->create('big.pdf', 11 * 1024);
    $wrong  = UploadedFile::fake()->create('thing.xls', 100);

    Livewire::actingAs($this->teacher)
        ->test(Step1Settings::class, ['lesson' => null])
        ->set('source_mode', 'upload')
        ->set('sourceUpload', $tooBig)
        ->call('saveDraft')
        ->assertHasErrors(['sourceUpload']);

    Livewire::actingAs($this->teacher)
        ->test(Step1Settings::class, ['lesson' => null])
        ->set('source_mode', 'upload')
        ->set('sourceUpload', $wrong)
        ->call('saveDraft')
        ->assertHasErrors(['sourceUpload']);
});
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

`app/Livewire/Wizard/Step1Settings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Http\Requests\StoreWizardSettingsRequest;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\StrategyGame;
use App\Services\DocumentExtractor;
use App\Services\Support\GradeBandStyleRecommender;
use App\Services\Support\ImageStyleTemplate;
use App\Services\WikipediaService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class Step1Settings extends Component
{
    use WithFileUploads;

    public ?Lesson $lesson = null;

    public string $topic            = '';
    public string $subject          = 'history';
    public string $grade_level      = '9th grade';
    public string $tone             = '';
    public string $details          = '';
    public string $source_mode      = 'wikipedia';
    public        $sourceUpload     = null;
    public string $image_style      = 'realistic';
    public ?int   $avatar_id        = null;
    public ?int   $strategy_game_id = null;
    public ?int   $team_count       = null;
    public int    $game_split_count = 1;
    public string $lesson_code      = '';
    public ?int   $duration_minutes = null;
    public ?int   $duration_seconds = null;
    public        $portrait         = null;

    public function mount(?Lesson $lesson = null): void
    {
        if ($lesson?->exists) {
            $this->lesson           = $lesson;
            $this->topic            = $lesson->topic ?? '';
            $this->subject          = $lesson->subject ?? 'history';
            $this->grade_level      = $lesson->grade_level ?? '9th grade';
            $this->tone             = $lesson->tone ?? '';
            $this->details          = $lesson->details ?? '';
            $this->source_mode      = $lesson->source_mode ?? 'wikipedia';
            $this->image_style      = $lesson->image_style ?? 'realistic';
            $this->avatar_id        = $lesson->avatar_id;
            $this->strategy_game_id = $lesson->strategy_game_id;
            $this->team_count       = $lesson->team_count;
            $this->game_split_count = (int) ($lesson->game_split_count ?? 1);
            $this->lesson_code      = $lesson->lesson_code ?? '';
            if ($lesson->duration_seconds !== null) {
                $this->duration_minutes = (int) floor($lesson->duration_seconds / 60);
                $this->duration_seconds = $lesson->duration_seconds % 60;
            }
        } else {
            $this->lesson_code = strtoupper(Str::random(6));
            $this->image_style = GradeBandStyleRecommender::recommend($this->grade_level)[0];
        }
    }

    #[Computed]
    public function avatars()
    {
        return Avatar::where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function games()
    {
        return StrategyGame::active()->orderBy('title')->get();
    }

    #[Computed]
    public function styleOptions(): array
    {
        return array_map(fn (string $k) => [
            'key'   => $k,
            'label' => ucfirst($k),
            'thumb' => asset("assets/style-{$k}.png"),
        ], ImageStyleTemplate::styles());
    }

    #[Computed]
    public function recommendedStyles(): array
    {
        return GradeBandStyleRecommender::recommend($this->grade_level);
    }

    protected function rules(): array
    {
        return (new StoreWizardSettingsRequest())->rules();
    }

    public function saveDraft(): Lesson
    {
        $this->validate();
        return $this->persist(LessonStatus::Draft);
    }

    public function generate(): void
    {
        $lesson = $this->persist(LessonStatus::Draft);
        $lesson->refresh()->startGenerationPipeline();
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2], navigate: true);
    }

    private function persist(LessonStatus $status): Lesson
    {
        $lesson = $this->lesson ?? new Lesson();

        $lesson->fill([
            'teacher_id'       => auth()->id(),
            'topic'            => trim($this->topic),
            'subject'          => $this->subject,
            'grade_level'      => $this->grade_level,
            'tone'             => trim($this->tone) ?: null,
            'details'          => trim($this->details) ?: null,
            'source_mode'      => $this->source_mode,
            'image_style'      => $this->image_style,
            'avatar_id'        => $this->avatar_id,
            'strategy_game_id' => $this->strategy_game_id,
            'team_count'       => $this->team_count,
            'game_split_count' => $this->game_split_count,
            'lesson_code'      => strtoupper($this->lesson_code),
            'duration_seconds' => $this->duration_minutes !== null
                ? ($this->duration_minutes * 60) + ($this->duration_seconds ?? 0)
                : null,
            'status'           => $status,
            'wizard_step'      => 1,
        ]);
        $lesson->save();

        if ($this->portrait) {
            $path = $this->portrait->storeAs("lessons/{$lesson->id}", 'portrait.jpg', 'public');
            $lesson->update(['portrait_path' => $path]);
        }

        $this->buildSource($lesson);

        $this->lesson = $lesson;
        return $lesson;
    }

    private function buildSource(Lesson $lesson): void
    {
        $source = $lesson->source()->firstOrNew(['lesson_id' => $lesson->id]);

        $combinedText = '';
        $kind         = $this->source_mode;
        $filePath     = null;
        $original     = null;

        if (in_array($this->source_mode, ['upload', 'both'], true) && $this->sourceUpload) {
            $extension = strtolower($this->sourceUpload->getClientOriginalExtension());
            $kindUp    = $extension === 'docx' ? 'docx' : 'pdf';

            $filePath = $this->sourceUpload->storeAs("lessons/{$lesson->id}", "source.{$extension}", 'public');
            $original = $this->sourceUpload->getClientOriginalName();

            $absolute     = storage_path('app/public/' . $filePath);
            $combinedText = app(DocumentExtractor::class)->extractFromPath($absolute);

            if ($this->source_mode === 'upload') {
                $kind = $kindUp;
            }
        }

        if (in_array($this->source_mode, ['wikipedia', 'both'], true)) {
            $wiki = app(WikipediaService::class)->fetchFacts($lesson->topic) ?? '';
            $combinedText = trim($combinedText . "\n\n" . $wiki);
        }

        $source->fill([
            'lesson_id'         => $lesson->id,
            'kind'              => $kind,
            'original_filename' => $original,
            'file_path'         => $filePath,
            'extracted_text'    => $combinedText,
            'wikipedia_topic'   => in_array($this->source_mode, ['wikipedia', 'both'], true) ? $lesson->topic : null,
        ])->save();
    }

    public function render()
    {
        return view('livewire.wizard.step1-settings');
    }

    public function updatedGradeLevel(): void
    {
        // Refresh recommended style; do not overwrite teacher's manual pick if they've already changed it.
    }
}
```

- [ ] **Step 4: Run + pass.**

```bash
./vendor/bin/pest tests/Feature/Wizard/Step1SettingsTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Wizard/Step1Settings.php tests/Feature/Wizard/Step1SettingsTest.php
git commit -m "feat(wizard): Step1Settings component"
```

---

## Task 6: Step 1 view

**Files:**
- Create: `resources/views/livewire/wizard/step1-settings.blade.php`

- [ ] **Step 1: Build the view**

```blade
<div class="space-y-8 pt-6">

    {{-- 1. Topic & Source --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">1. Topic & Source</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Topic</span>
                <input type="text" wire:model="topic"
                       class="input input-bordered bg-slate-900 mt-1" />
                @error('topic') <span class="text-rose-400 text-xs mt-1">{{ $message }}</span> @enderror
            </label>

            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Subject</span>
                <select wire:model="subject" class="select select-bordered bg-slate-900 mt-1">
                    <option value="history">History</option>
                    <option value="science">Science</option>
                    <option value="literature">Literature</option>
                    <option value="civics">Civics</option>
                </select>
            </label>

            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Grade level</span>
                <input type="text" wire:model.live.debounce.500ms="grade_level"
                       class="input input-bordered bg-slate-900 mt-1" />
            </label>

            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Tone (optional)</span>
                <input type="text" wire:model="tone"
                       class="input input-bordered bg-slate-900 mt-1" />
            </label>

            <label class="form-control md:col-span-2">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Teacher details (optional)</span>
                <textarea wire:model="details" rows="3"
                          class="textarea textarea-bordered bg-slate-900 mt-1"></textarea>
            </label>
        </div>

        <div class="space-y-2">
            <p class="text-xs uppercase tracking-wider text-slate-400">Source</p>
            <div class="flex flex-wrap gap-3">
                @foreach (['wikipedia' => 'Wikipedia only', 'upload' => 'My document only', 'both' => 'Both combined'] as $val => $label)
                    <button type="button"
                            wire:click="$set('source_mode', '{{ $val }}')"
                            @class([
                                'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                                'border-amber-400 bg-amber-500/10 text-amber-300' => $source_mode === $val,
                                'border-slate-600 text-slate-300 hover:border-slate-400' => $source_mode !== $val,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($source_mode !== 'wikipedia')
                <div class="mt-2">
                    <input type="file" wire:model="sourceUpload" accept=".pdf,.docx"
                           class="file-input file-input-bordered w-full bg-slate-900" />
                    @error('sourceUpload') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>
    </section>

    {{-- 2. Visual Style --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">2. Visual Style</h2>
        <x-wizard.style-picker :styles="$this->styleOptions"
                               :selected="$image_style"
                               :recommended="$this->recommendedStyles" />
    </section>

    {{-- 3. Avatar --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">3. Avatar</h2>
        <x-wizard.avatar-picker :avatars="$this->avatars" :selected-id="$avatar_id" />
        @error('avatar_id') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
    </section>

    {{-- 4. Strategy Game --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">4. Strategy Game</h2>
        <x-wizard.game-picker :games="$this->games"
                              :selected-id="$strategy_game_id"
                              :team-count="$team_count"
                              :split-count="$game_split_count" />
    </section>

    {{-- 5. Lesson Meta --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">5. Lesson Meta</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Lesson code</span>
                <div class="flex gap-2 mt-1">
                    <input type="text" wire:model="lesson_code"
                           class="input input-bordered bg-slate-900 flex-1" />
                    <button type="button" wire:click="$set('lesson_code', strtoupper(\Illuminate\Support\Str::random(6)))"
                            class="btn btn-square">🔄</button>
                </div>
            </label>

            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Duration (min)</span>
                <input type="number" wire:model="duration_minutes" min="1" max="60"
                       class="input input-bordered bg-slate-900 mt-1" />
            </label>

            <label class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Seconds</span>
                <input type="number" wire:model="duration_seconds" min="0" max="59"
                       class="input input-bordered bg-slate-900 mt-1" />
            </label>

            <label class="form-control md:col-span-3">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Portrait (optional)</span>
                <input type="file" wire:model="portrait" accept="image/*"
                       class="file-input file-input-bordered w-full bg-slate-900 mt-1" />
            </label>
        </div>
    </section>

    {{-- Actions --}}
    <div class="flex justify-end gap-3 pt-2">
        <button type="button" wire:click="saveDraft"
                class="btn btn-outline">Save as draft</button>
        <button type="button" wire:click="generate"
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Generate lesson →</button>
    </div>

</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/wizard/step1-settings.blade.php
git commit -m "feat(wizard): Step 1 view"
```

---

## Task 7: Placeholder children for Steps 2/3/4 so the wizard renders

**Files:**
- Create: `app/Livewire/Wizard/Step2Generate.php`
- Create: `app/Livewire/Wizard/Step3SceneConfigurator.php`
- Create: `app/Livewire/Wizard/Step4Preview.php`
- Create: matching `resources/views/livewire/wizard/step{2,3,4}-*.blade.php`

These placeholders satisfy the parent's `<livewire:wizard.stepN-...>` tags so navigation works during Plan D. Plans E/G/H replace each with the real implementation.

- [ ] **Step 1: Create skeletons**

```php
<?php
declare(strict_types=1);
namespace App\Livewire\Wizard;
use App\Models\Lesson;
use Livewire\Component;

class Step2Generate extends Component
{
    public ?Lesson $lesson = null;
    public function render() { return view('livewire.wizard.step2-generate'); }
}
```

Repeat with class names `Step3SceneConfigurator`, `Step4Preview` and view names `step3-scene-configurator`, `step4-preview`. Views can each be a single `<div class="p-8 text-center text-slate-400">Step N placeholder — implemented in Plan E/G/H.</div>`.

- [ ] **Step 2: Commit**

```bash
git add app/Livewire/Wizard/Step2Generate.php app/Livewire/Wizard/Step3SceneConfigurator.php \
        app/Livewire/Wizard/Step4Preview.php resources/views/livewire/wizard/step{2,3,4}-*.blade.php
git commit -m "feat(wizard): scaffold Step2/3/4 placeholders"
```

---

## Task 8: Re-run route tests + manual smoke

- [ ] **Step 1:** `./vendor/bin/pest tests/Feature/LessonWizardRoutesTest.php` — expect all green.
- [ ] **Step 2:** `composer test` — expect all green (or known-failing legacy tests now removed alongside the deleted components; remove their files too if found).
- [ ] **Step 3:** start the dev server (`composer dev`), visit `/teacher/lessons/create`, fill the form, click **Save as draft** → confirm DB row; click **Generate lesson →** → confirm redirect to `?step=2` and `php artisan queue:work` runs the pipeline.

Done.
