# Plan C — Generation Pipeline (Jobs + Bus::batch)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the queued job chain that, given a `Lesson` (Draft) and `LessonSource`, produces an outline (Scene rows), per-scene script/image/audio, and quiz questions. Each per-scene job is retryable independently from Step 3's "Regenerate" actions in Plan G.

**Architecture:** Five jobs + one orchestrator method on `Lesson`. `BuildLessonOutline` writes Scene rows then fans out a `Bus::batch` of three jobs per Scene. On batch success, `GenerateQuiz` runs and `lesson.status` flips to `ScenesReady`. Each job: 3 tries, exponential backoff, writes `scene.status` and `scene.error_message` on failure.

**Tech Stack:** Laravel 12 Queues, `Bus::batch`, `Queue::fake`, `OpenAiLlmService` + `OpenAiImageService` (Plan B), `ElevenLabsService` (existing), `WikipediaService` (existing).

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§5).

**Depends on:** Plan A, Plan B.

---

## File Structure

```
app/Jobs/
  BuildLessonOutline.php                 NEW
  GenerateSceneScript.php                NEW
  GenerateSceneImage.php                 NEW
  GenerateSceneAudio.php                 NEW
  GenerateLessonQuiz.php                 NEW   (renamed to avoid name clash with existing GenerateLesson)
app/Models/Lesson.php                    MODIFY (add `startGenerationPipeline()` helper)
app/Services/LessonOutlinePrompt.php     NEW   (system + user prompt builder for outline)
app/Services/SceneScriptPrompt.php       NEW   (system + user prompt builder per scene)
app/Services/QuizPrompt.php              NEW
tests/Feature/Jobs/
  BuildLessonOutlineTest.php             NEW
  GenerateSceneScriptTest.php            NEW
  GenerateSceneImageTest.php             NEW
  GenerateSceneAudioTest.php             NEW
  GenerateLessonQuizTest.php             NEW
  LessonStartPipelineTest.php            NEW
```

---

## Task 1: `LessonOutlinePrompt` builder

**Files:**
- Create: `app/Services/LessonOutlinePrompt.php`
- Test: skipped (covered by `BuildLessonOutlineTest` HTTP-assertion).

- [ ] **Step 1: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\StrategyGame;

final class LessonOutlinePrompt
{
    public static function system(): string
    {
        return <<<SYS
You are a K-12 curriculum writer producing structured lesson outlines.
Return ONLY a JSON object with this exact shape:
{
  "title": string,
  "scene_briefs": [
    {
      "order": integer (1-indexed),
      "kind": "narration" | "game",
      "year": string | null,
      "location": string | null,
      "beat": string (one-sentence summary of what happens in this scene),
      "image_prompt_seed": string | null,
      "game_segment_index": integer | null
    }
  ]
}

Rules:
- Only use facts from the provided source text. If uncertain, omit — never invent.
- Match the requested grade-level vocabulary and tone.
- Place game scenes at pedagogically sensible points.
SYS;
    }

    public static function user(Lesson $lesson, string $sourceText): string
    {
        $game        = $lesson->strategyGame;
        $teamCount   = $lesson->team_count ?? 0;
        $splitCount  = (int) ($lesson->game_split_count ?? 1);
        $duration    = $lesson->duration_seconds
            ? (int) round($lesson->duration_seconds / 60)
            : 10;

        $gameClause = '';
        if ($game instanceof StrategyGame && $splitCount > 0) {
            $gameClause = self::buildGameClause($game->title, $teamCount, $splitCount);
        }

        return <<<USR
Topic: {$lesson->topic}
Subject: {$lesson->subject}
Grade level: {$lesson->grade_level}
Tone: {$lesson->tone}
Teacher details: {$lesson->details}
Target duration: {$duration} minutes
{$gameClause}

Source text:
"""
{$sourceText}
"""

Produce the JSON outline now.
USR;
    }

    private static function buildGameClause(string $gameTitle, int $teamCount, int $splitCount): string
    {
        $base = "Strategy game: \"{$gameTitle}\" with {$teamCount} teams.";
        if ($splitCount <= 1) {
            return "{$base} Insert one game scene at a pedagogically sensible point.";
        }
        return "{$base} Split the game into {$splitCount} segments (kind=game, "
             . "game_segment_index 1..{$splitCount}). Between every two consecutive game segments, "
             . "insert one short narration scene (kind=narration) briefing students on what changed.";
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/LessonOutlinePrompt.php
git commit -m "feat(jobs): outline prompt builder"
```

---

## Task 2: `BuildLessonOutline` job

**Files:**
- Create: `app/Jobs/BuildLessonOutline.php`
- Test: `tests/Feature/Jobs/BuildLessonOutlineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateLessonQuiz;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->lesson  = Lesson::create([
        'teacher_id'  => $this->teacher->id,
        'topic'       => 'Napoleonic Campaigns',
        'subject'     => 'history',
        'grade_level' => '9th grade',
        'image_style' => 'painted',
        'source_mode' => 'wikipedia',
        'status'      => LessonStatus::SourceReady,
    ]);
    LessonSource::create([
        'lesson_id'      => $this->lesson->id,
        'kind'           => 'wikipedia',
        'extracted_text' => 'Napoleon Bonaparte was a French statesman…',
    ]);
});

it('writes scene rows from the LLM JSON and dispatches a batch', function (): void {
    $this->mock(OpenAiLlmService::class, function ($mock): void {
        $mock->shouldReceive('json')->once()->andReturn([
            'title'        => 'Napoleon: Rise and Fall',
            'scene_briefs' => [
                ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris', 'beat' => 'intro', 'image_prompt_seed' => 'Paris dusk 1810'],
                ['order' => 2, 'kind' => 'narration', 'year' => '1812', 'location' => 'Moscow', 'beat' => 'invasion', 'image_prompt_seed' => 'Burning Moscow'],
            ],
        ]);
    });

    Bus::fake();

    (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

    $this->lesson->refresh();
    expect($this->lesson->title)->toBe('Napoleon: Rise and Fall')
        ->and($this->lesson->status)->toBe(LessonStatus::ScenesGenerating)
        ->and(Scene::count())->toBe(2);

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 6;  // 3 jobs × 2 scenes
    });
});

it('marks the lesson Failed and writes error_message on LLM exception', function (): void {
    $this->mock(OpenAiLlmService::class, function ($mock): void {
        $mock->shouldReceive('json')->andThrow(new RuntimeException('OpenAI down'));
    });

    try {
        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));
    } catch (\Throwable) {
        // expected
    }

    $this->lesson->refresh();
    expect($this->lesson->status)->toBe(LessonStatus::Failed)
        ->and($this->lesson->error_message)->toContain('OpenAI down');
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Feature/Jobs/BuildLessonOutlineTest.php
```

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Services\LessonOutlinePrompt;
use App\Services\OpenAiLlmService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class BuildLessonOutline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('source', 'strategyGame')->findOrFail($this->lessonId);
        $lesson->update(['status' => LessonStatus::Outlining]);

        try {
            $sourceText = (string) ($lesson->source?->extracted_text ?? '');

            $outline = $llm->json(
                system: LessonOutlinePrompt::system(),
                user:   LessonOutlinePrompt::user($lesson, $sourceText),
            );

            $lesson->update([
                'title'   => $outline['title'] ?? $lesson->topic,
                'outline' => $outline,
                'status'  => LessonStatus::ScenesGenerating,
            ]);

            $scenes = [];
            foreach (($outline['scene_briefs'] ?? []) as $brief) {
                $scenes[] = Scene::create([
                    'lesson_id'          => $lesson->id,
                    'order'              => (int) ($brief['order'] ?? count($scenes) + 1),
                    'kind'               => $brief['kind'] ?? 'narration',
                    'year'               => $brief['year']     ?? null,
                    'location'           => $brief['location'] ?? null,
                    'image_prompt'       => $brief['image_prompt_seed'] ?? null,
                    'image_style'        => $lesson->image_style,
                    'game_segment_index' => $brief['game_segment_index'] ?? null,
                    'status'             => 'pending',
                ]);
            }

            $jobs = [];
            foreach ($scenes as $scene) {
                $jobs[] = new GenerateSceneScript($scene->id);
                $jobs[] = new GenerateSceneImage($scene->id);
                $jobs[] = new GenerateSceneAudio($scene->id);
            }

            Bus::batch($jobs)
                ->name("lesson:{$lesson->id}:scenes")
                ->then(fn (Batch $batch) => GenerateLessonQuiz::dispatch($lesson->id))
                ->dispatch();
        } catch (Throwable $e) {
            $lesson->update([
                'status'        => LessonStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Jobs/BuildLessonOutlineTest.php
git add app/Jobs/BuildLessonOutline.php tests/Feature/Jobs/BuildLessonOutlineTest.php
git commit -m "feat(jobs): BuildLessonOutline"
```

---

## Task 3: `GenerateSceneScript` job

**Files:**
- Create: `app/Jobs/GenerateSceneScript.php`
- Create: `app/Services/SceneScriptPrompt.php`
- Test: `tests/Feature/Jobs/GenerateSceneScriptTest.php`

- [ ] **Step 1: Implement `SceneScriptPrompt`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Scene;

final class SceneScriptPrompt
{
    public static function system(): string
    {
        return 'You write tight, vivid history narration in 80-150 words per scene. '
             . 'Only use facts from the provided source. If uncertain, omit — never invent.';
    }

    public static function user(Scene $scene, string $sourceText, string $tone, string $grade): string
    {
        $beat     = $scene->lesson->outline['scene_briefs'][$scene->order - 1]['beat'] ?? 'continue the story';
        $year     = $scene->year     ?? 'unknown year';
        $location = $scene->location ?? 'unknown location';

        return <<<USR
Scene {$scene->order} — {$year}, {$location}
Beat: {$beat}
Grade level: {$grade}
Tone: {$tone}

Source text (authoritative):
"""
{$sourceText}
"""

Write the narration for THIS scene only. 80-150 words. Plain prose, no headings, no scene number.
USR;
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Jobs\GenerateSceneScript;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('writes script_segment and flips status to ready', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id,
        'topic'       => 'Napoleon',
        'subject'     => 'history',
        'grade_level' => '9th grade',
        'tone'        => 'dramatic',
        'outline'     => ['scene_briefs' => [['beat' => 'opening']]],
    ]);
    LessonSource::create([
        'lesson_id'      => $lesson->id,
        'kind'           => 'wikipedia',
        'extracted_text' => 'Napoleon was born in 1769.',
    ]);
    $scene = Scene::create([
        'lesson_id' => $lesson->id,
        'order'     => 1,
        'kind'      => 'narration',
        'year'      => '1769',
        'location'  => 'Corsica',
        'status'    => 'pending',
    ]);

    $this->mock(OpenAiLlmService::class, fn ($mock) =>
        $mock->shouldReceive('text')->once()->andReturn('Napoleon was born on Corsica in 1769.')
    );

    (new GenerateSceneScript($scene->id))->handle(app(OpenAiLlmService::class));

    $scene->refresh();
    expect($scene->script_segment)->toBe('Napoleon was born on Corsica in 1769.')
        ->and($scene->status)->toBe('generating');  // not ready until script+audio+image done
});

it('marks the scene failed on LLM exception', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id,
        'topic'       => 'Test', 'subject' => 'history', 'grade_level' => '9th',
        'outline'     => ['scene_briefs' => [['beat' => 'x']]],
    ]);
    LessonSource::create(['lesson_id' => $lesson->id, 'kind' => 'wikipedia', 'extracted_text' => 'x']);
    $scene = Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'pending']);

    $this->mock(OpenAiLlmService::class, fn ($mock) =>
        $mock->shouldReceive('text')->andThrow(new RuntimeException('boom'))
    );

    try { (new GenerateSceneScript($scene->id))->handle(app(OpenAiLlmService::class)); }
    catch (\Throwable) {}

    expect($scene->fresh()->status)->toBe('failed')
        ->and($scene->fresh()->error_message)->toContain('boom');
});
```

- [ ] **Step 3: Run, confirm fail**

```bash
./vendor/bin/pest tests/Feature/Jobs/GenerateSceneScriptTest.php
```

- [ ] **Step 4: Implement the job**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\OpenAiLlmService;
use App\Services\SceneScriptPrompt;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSceneScript implements ShouldQueue
{
    use Dispatchable, Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 90;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $scene = Scene::with('lesson.source')->findOrFail($this->sceneId);
        $scene->update(['status' => 'generating']);

        try {
            $text = $llm->text(
                system: SceneScriptPrompt::system(),
                user:   SceneScriptPrompt::user(
                    $scene,
                    (string) ($scene->lesson->source?->extracted_text ?? ''),
                    (string) ($scene->lesson->tone ?? ''),
                    (string) ($scene->lesson->grade_level ?? '9th grade'),
                ),
            );

            $scene->update(['script_segment' => trim($text)]);
        } catch (Throwable $e) {
            $scene->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

- [ ] **Step 5: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Jobs/GenerateSceneScriptTest.php
git add app/Jobs/GenerateSceneScript.php app/Services/SceneScriptPrompt.php \
        tests/Feature/Jobs/GenerateSceneScriptTest.php
git commit -m "feat(jobs): GenerateSceneScript"
```

---

## Task 4: `GenerateSceneImage` job

**Files:**
- Create: `app/Jobs/GenerateSceneImage.php`
- Test: `tests/Feature/Jobs/GenerateSceneImageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Jobs\GenerateSceneImage;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiImageService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('generates an image, stores path on scene', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id,
        'topic'       => 'X', 'subject' => 'history', 'grade_level' => '9th',
        'image_style' => 'cinematic',
    ]);
    $scene = Scene::create([
        'lesson_id'   => $lesson->id,
        'order'       => 1,
        'kind'        => 'narration',
        'image_prompt'=> 'Paris dusk',
        'image_style' => 'cinematic',
        'status'      => 'generating',
    ]);

    $this->mock(OpenAiImageService::class, fn ($mock) =>
        $mock->shouldReceive('generate')
            ->withArgs(fn ($seed, $style, $dest, $isGame = false) =>
                $seed === 'Paris dusk'
                && $style === 'cinematic'
                && $isGame === false
                && str_starts_with($dest, "lessons/{$lesson->id}/scenes/{$scene->id}/")
            )
            ->andReturn("lessons/{$lesson->id}/scenes/{$scene->id}/skybox.png")
    );

    (new GenerateSceneImage($scene->id))->handle(app(OpenAiImageService::class));

    expect($scene->fresh()->image_path)->toBe("lessons/{$lesson->id}/scenes/{$scene->id}/skybox.png");
});

it('passes isGame=true for game scenes', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
    ]);
    $scene = Scene::create([
        'lesson_id'    => $lesson->id,
        'order'        => 1,
        'kind'         => 'game',
        'image_prompt' => 'Battle',
        'image_style'  => 'painted',
        'status'       => 'generating',
    ]);

    $this->mock(OpenAiImageService::class, fn ($mock) =>
        $mock->shouldReceive('generate')
            ->withArgs(fn ($_s, $_st, $_d, $isGame = false) => $isGame === true)
            ->andReturn('path.png')
    );

    (new GenerateSceneImage($scene->id))->handle(app(OpenAiImageService::class));
});
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\OpenAiImageService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSceneImage implements ShouldQueue
{
    use Dispatchable, Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiImageService $image): void
    {
        $scene = Scene::with('lesson')->findOrFail($this->sceneId);

        try {
            $destination = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/skybox.png";
            $path = $image->generate(
                seedPrompt:  (string) ($scene->image_prompt ?? $scene->location ?? $scene->lesson->topic),
                style:       (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic'),
                destination: $destination,
                isGame:      $scene->kind === 'game',
            );
            $scene->update(['image_path' => $path]);
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Pass + commit.**

```bash
./vendor/bin/pest tests/Feature/Jobs/GenerateSceneImageTest.php
git add app/Jobs/GenerateSceneImage.php tests/Feature/Jobs/GenerateSceneImageTest.php
git commit -m "feat(jobs): GenerateSceneImage"
```

---

## Task 5: `GenerateSceneAudio` job

**Files:**
- Create: `app/Jobs/GenerateSceneAudio.php`
- Test: `tests/Feature/Jobs/GenerateSceneAudioTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Jobs\GenerateSceneAudio;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\TtsService;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('produces audio, stores path + alignment + script hash, ready when all assets present', function (): void {
    $teacher = User::factory()->create();
    $avatar  = Avatar::create([
        'name' => 'Napoleon', 'slug' => 'napoleon-1', 'gender' => 'male',
        'voice_provider' => 'elevenlabs', 'voice_id' => 'voice-x',
        'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
    ]);
    $lesson = Lesson::create([
        'teacher_id' => $teacher->id, 'avatar_id' => $avatar->id,
        'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
    ]);
    $scene = Scene::create([
        'lesson_id'      => $lesson->id,
        'order'          => 1,
        'kind'           => 'narration',
        'script_segment' => 'Hello world.',
        'image_path'     => 'x.png',
        'status'         => 'generating',
    ]);

    $this->mock(TtsService::class, function ($mock): void {
        $mock->shouldReceive('prepareSpeechText')->andReturn('Hello world.');
        $mock->shouldReceive('generateAudioRaw')->andReturnUsing(function ($text, $voiceId, $speed, $provider, &$timing) {
            $timing = ['character_timings' => [['character' => 'H', 'start' => 0.0, 'end' => 0.1]]];
            return 'BINARYMP3';
        });
        $mock->shouldReceive('lastExtension')->andReturn('mp3');
    });

    (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

    $scene->refresh();
    expect($scene->audio_path)->toMatch('#^lessons/' . $lesson->id . '/scenes/' . $scene->id . '/narration\.mp3$#')
        ->and($scene->audio_alignment)->toBeArray()
        ->and($scene->audio_script_hash)->toBe(sha1('Hello world.'))
        ->and($scene->status)->toBe('ready');  // all three assets present
});
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\TtsService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSceneAudio implements ShouldQueue
{
    use Dispatchable, Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(TtsService $tts): void
    {
        $scene = Scene::with('lesson.avatar')->findOrFail($this->sceneId);

        try {
            $avatar  = $scene->lesson->avatar;
            $script  = (string) ($scene->script_segment ?? '');
            $text    = $tts->prepareSpeechText($script);

            $timing  = null;
            $audio   = $tts->generateAudioRaw(
                $text,
                $avatar?->voice_id ?? '',
                (float) ($avatar?->voice_speed ?? 1.0),
                $avatar?->voice_provider ?? 'elevenlabs',
                $timing,
            );
            if ($audio === null) {
                throw new \RuntimeException('TTS service returned no audio.');
            }

            $ext  = $tts->lastExtension();
            $path = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/narration.{$ext}";
            Storage::disk('public')->put($path, $audio);

            $scene->update([
                'audio_path'        => $path,
                'audio_alignment'   => $timing['character_timings'] ?? [],
                'audio_script_hash' => sha1($script),
            ]);

            $this->maybeMarkReady($scene->fresh());
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function maybeMarkReady(Scene $scene): void
    {
        $hasScript = (string) $scene->script_segment !== '';
        $hasImage  = $scene->image_path !== null;
        $hasAudio  = $scene->audio_path !== null;

        if ($hasScript && $hasImage && $hasAudio) {
            $scene->update(['status' => 'ready']);
        }
    }
}
```

> Note: the same `maybeMarkReady()` logic also fires from `GenerateSceneScript` and `GenerateSceneImage`. Add a corresponding call in both job files (Task 3 step 4, Task 4 step 3) — the existing implementations show only the failure branch; before the final `$scene->update(...)` of the happy path, call a private `maybeMarkReady($scene->fresh())` method copy-pasted from this task. **Implementer:** update both jobs accordingly and re-run their tests; this is the canonical version.

- [ ] **Step 4: Update Script + Image jobs to call `maybeMarkReady`**

In `app/Jobs/GenerateSceneScript.php` and `app/Jobs/GenerateSceneImage.php`, paste the `maybeMarkReady()` private method from `GenerateSceneAudio` (verbatim) and call `$this->maybeMarkReady($scene->fresh())` at the end of the happy path (after the field-writing `update()`).

Then re-run those tests; update the Script test's final assertion to:

```php
expect($scene->status)->toBeIn(['generating', 'ready']);
```

- [ ] **Step 5: Run audio test + full suite + commit**

```bash
./vendor/bin/pest tests/Feature/Jobs/GenerateSceneAudioTest.php
./vendor/bin/pest tests/Feature/Jobs/GenerateSceneScriptTest.php
./vendor/bin/pest tests/Feature/Jobs/GenerateSceneImageTest.php
git add app/Jobs/GenerateSceneAudio.php app/Jobs/GenerateSceneScript.php app/Jobs/GenerateSceneImage.php \
        tests/Feature/Jobs/GenerateSceneAudioTest.php
git commit -m "feat(jobs): GenerateSceneAudio + shared maybeMarkReady"
```

---

## Task 6: `GenerateLessonQuiz` job

**Files:**
- Create: `app/Jobs/GenerateLessonQuiz.php`
- Create: `app/Services/QuizPrompt.php`
- Test: `tests/Feature/Jobs/GenerateLessonQuizTest.php`

- [ ] **Step 1: Implement `QuizPrompt`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

final class QuizPrompt
{
    public static function system(): string
    {
        return <<<SYS
Return JSON only:
{
  "questions": [
    {
      "order": integer,
      "question": string,
      "options": [string, string, string, string],
      "correct_index": integer
    }
  ]
}
Write 5 multiple-choice questions strictly grounded in the lesson's combined scene scripts.
SYS;
    }

    public static function user(Lesson $lesson): string
    {
        $combined = $lesson->scenes->pluck('script_segment')->filter()->implode("\n\n");
        return "Lesson topic: {$lesson->topic}\nGrade: {$lesson->grade_level}\n\nCombined narration:\n{$combined}";
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Jobs\GenerateLessonQuiz;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('writes quiz rows and transitions to ScenesReady', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id' => $teacher->id,
        'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        'status' => LessonStatus::ScenesGenerating,
    ]);
    Scene::create([
        'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
        'script_segment' => 'Napoleon ruled France.', 'status' => 'ready',
    ]);

    $this->mock(OpenAiLlmService::class, fn ($mock) =>
        $mock->shouldReceive('json')->once()->andReturn([
            'questions' => [
                ['order' => 1, 'question' => 'Who ruled France?', 'options' => ['Napoleon','Caesar','Hitler','Churchill'], 'correct_index' => 0],
            ],
        ])
    );

    (new GenerateLessonQuiz($lesson->id))->handle(app(OpenAiLlmService::class));

    expect(QuizQuestion::where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::ScenesReady);
});
```

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Services\OpenAiLlmService;
use App\Services\QuizPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLessonQuiz implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 90;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('scenes')->findOrFail($this->lessonId);

        $result = $llm->json(
            system: QuizPrompt::system(),
            user:   QuizPrompt::user($lesson),
        );

        foreach ($result['questions'] ?? [] as $q) {
            QuizQuestion::create([
                'lesson_id'      => $lesson->id,
                'order'          => (int) ($q['order'] ?? 0),
                'question'       => (string) ($q['question'] ?? ''),
                'options'        => $q['options']  ?? [],
                'correct_answer' => (int)    ($q['correct_index'] ?? 0),
            ]);
        }

        $lesson->update(['status' => LessonStatus::ScenesReady]);
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Jobs/GenerateLessonQuizTest.php
git add app/Jobs/GenerateLessonQuiz.php app/Services/QuizPrompt.php \
        tests/Feature/Jobs/GenerateLessonQuizTest.php
git commit -m "feat(jobs): GenerateLessonQuiz + transition to ScenesReady"
```

---

## Task 7: `Lesson::startGenerationPipeline()` helper

**Files:**
- Modify: `app/Models/Lesson.php`
- Test: `tests/Feature/Jobs/LessonStartPipelineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('flips lesson to SourceReady and dispatches BuildLessonOutline', function (): void {
    Bus::fake();

    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id' => $teacher->id,
        'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        'status' => LessonStatus::Draft,
    ]);
    LessonSource::create(['lesson_id' => $lesson->id, 'kind' => 'wikipedia', 'extracted_text' => 'x']);

    $lesson->startGenerationPipeline();

    expect($lesson->fresh()->status)->toBe(LessonStatus::SourceReady);
    Bus::assertDispatched(BuildLessonOutline::class, fn ($job) => $job->lessonId === $lesson->id);
});

it('refuses to start when no source exists', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id' => $teacher->id,
        'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
    ]);

    $lesson->startGenerationPipeline();
})->throws(\RuntimeException::class);
```

- [ ] **Step 2: Implement on `Lesson`**

Add to `app/Models/Lesson.php`:

```php
public function startGenerationPipeline(): void
{
    if (! $this->source()->exists()) {
        throw new \RuntimeException('Cannot start pipeline: no LessonSource attached.');
    }

    $this->update(['status' => \App\Enums\LessonStatus::SourceReady]);
    \App\Jobs\BuildLessonOutline::dispatch($this->id);
}
```

- [ ] **Step 3: Pass + commit**

```bash
./vendor/bin/pest tests/Feature/Jobs/LessonStartPipelineTest.php
git add app/Models/Lesson.php tests/Feature/Jobs/LessonStartPipelineTest.php
git commit -m "feat(lessons): startGenerationPipeline helper"
```

---

## Task 8: Full suite + smoke test

- [ ] **Step 1:** `composer test`
- [ ] **Step 2:** seed a draft lesson via tinker, attach a LessonSource, run `$lesson->startGenerationPipeline()`, and watch `php artisan queue:work` produce ScenesReady (manual smoke; not committed).
- [ ] **Step 3:** confirm no regressions on existing `GenerateLesson` (legacy job stays for now until Plan D removes its route).

Done. Plan D consumes `startGenerationPipeline()` from the Settings UI.
