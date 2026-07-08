# Map Quiz Questions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Students answer geography questions on the interactive MapLibre map inside the lesson player (click the territory or pick a numbered marker); teachers author them by clicking territories on a mini-map, with per-block naming mode (historical/modern) and a modern-borders overlay switch.

**Architecture:** New `game_type='map_quiz'` scene. Map questions are `QuizQuestion` rows with a `type` column + `map_payload` JSON; `options`/`correct_index` stay the answer contract (labels resolved from the payload per the scene's naming mode at autosave time), so the entire existing results pipeline (QuizAnswer snapshots, results hub, re-quiz, CSV, leaderboard) works unchanged. Player reuses `QuizOverlay` for scoring/snapshots with a thin map adapter for map interaction.

**Tech Stack:** Laravel 12 + Livewire 3 (authoring), MapLibre GL (existing `lesson-map.js` + Cliopatria tiles, QID-keyed, data reaches ToYear 2024), Alpine.js player, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-08-map-quiz-design.md` — read it first.

---

## Codebase orientation (read before Task 1)

| What | Where |
|---|---|
| Question model | `app/Models/QuizQuestion.php` (fillable: lesson_id, scene_id, order, question, options JSON, correct_index, asks_ahead, explanation, points) |
| Scene model | `app/Models/Scene.php` — `kind` narration/game/map, `game_type` quiz/strategy/debate, `config` JSON, `mapPayloadForLesson()` gives default qid/year |
| Teacher quiz editing | `app/Livewire/Wizard/Concerns/EditsQuizQuestions.php` trait (draft array, autosave = delete-and-recreate per scene, per-row validation in `$quizErrors`) |
| Scene configurator | `app/Livewire/Wizard/Step3SceneConfigurator.php` (`addScene()` ~line 842) + `resources/views/livewire/wizard/step3-scene-configurator.blade.php` (add-scene modal ~line 290, inspector branch ~line 196) |
| Inspector components | `resources/views/components/lesson/scene-inspector-{map,game,narration}.blade.php` |
| Player data | `resources/views/lesson/player.blade.php` lines 41 + 68–72 serialize questions via `->map->only([...])` into `window.LESSON` |
| Player engine | `resources/js/lesson-player.js` — `_playScene` routes `kind==='map'` → `_playMapScene` (line ~833); scene queue filter line ~747 drops scenes without audio; quiz flow `_beginQuizFlow` line ~1105 |
| Quiz overlay | `resources/js/scene/QuizOverlay.js` (577 lines) — renders questions, display-shuffle `mapping`, snapshots `{question_order, question_text, chosen_text, correct_text, was_correct, response_ms}`, submit to `lesson.quiz-score` |
| Map block | `resources/js/lesson-map.js` — `renderLessonMap(el,{qid,year,interactive})`, Cliopatria source `promoteId: {boundaries:'Wikidata'}`, `polityFilter(year)`, feature-state highlight, `boundaries-fill` transparent fill layer (added after terrain load) |
| Server polity registry | `app/Services/CliopatriaSpans.php` + `database/data/cliopatria-polities.json` — 1400 rows `{qid,name,from,to}`, same spans the tiles render |
| Submit endpoint | `app/Http/Controllers/QuizLeaderboardController.php` — validates `answers.*` snapshot fields (lines 45–52); **needs no changes** |

Run tests with: `composer test` (or `vendor/bin/phpunit --filter <Name>` for one test).

---

### Task 0: Branch

- [ ] **Step 1: Create feature branch**

```bash
cd /Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us
git checkout -b feat/map-quiz
```

Note: `main` has unrelated uncommitted changes (ElevenLabs/credits work). Do NOT stage or commit those files — always `git add` explicit paths.

---

### Task 1: Migration + QuizQuestion model (`type`, `map_payload`)

**Files:**
- Create: `database/migrations/2026_07_08_100001_add_map_fields_to_quiz_questions_table.php`
- Modify: `app/Models/QuizQuestion.php`
- Test: `tests/Unit/QuizQuestionMapTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizQuestionMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_mc_type_with_null_map_payload(): void
    {
        $lesson = Lesson::factory()->create();
        $q = QuizQuestion::create([
            'lesson_id' => $lesson->id,
            'order' => 0,
            'question' => 'Plain question?',
            'options' => ['A', 'B'],
            'correct_index' => 0,
        ]);

        $this->assertSame('mc', $q->fresh()->type);
        $this->assertNull($q->fresh()->map_payload);
        $this->assertFalse($q->fresh()->isMapType());
    }

    public function test_stores_map_payload_and_type(): void
    {
        $lesson = Lesson::factory()->create();
        $payload = [
            'answer_mode' => 'numbered',
            'target' => ['qid' => 'Q241', 'label_hist' => 'Cuba', 'label_modern' => 'Cuba'],
            'distractors' => [
                ['qid' => 'Q790', 'label_hist' => 'Haiti', 'label_modern' => 'Haiti'],
            ],
            'relation' => null,
        ];

        $q = QuizQuestion::create([
            'lesson_id' => $lesson->id,
            'order' => 0,
            'type' => 'map_locate',
            'question' => 'Which country is Cuba?',
            'options' => ['Cuba', 'Haiti'],
            'correct_index' => 0,
            'map_payload' => $payload,
        ]);

        $fresh = $q->fresh();
        $this->assertSame('map_locate', $fresh->type);
        $this->assertSame('Q241', $fresh->map_payload['target']['qid']);
        $this->assertTrue($fresh->isMapType());
    }
}
```

If `Lesson::factory()` does not exist, look at how `tests/Feature/QuizLeaderboardTest.php` creates lessons and copy that construction instead.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter QuizQuestionMapTest`
Expected: FAIL (unknown column `type` / null `type`)

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            // 'mc' | 'map_locate' | 'map_identify' | 'map_actor'
            $table->string('type', 20)->default('mc')->after('order');
            $table->json('map_payload')->nullable()->after('correct_index');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn(['type', 'map_payload']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/QuizQuestion.php`, add `'type'` and `'map_payload'` to `$fillable`, add the cast, add constants + helper:

```php
    public const TYPES = ['mc', 'map_locate', 'map_identify', 'map_actor'];
    public const MAP_TYPES = ['map_locate', 'map_identify', 'map_actor'];

    protected $fillable = [
        'lesson_id',
        'scene_id',
        'order',
        'type',
        'question',
        'options',
        'correct_index',
        'map_payload',
        'asks_ahead',
        'explanation',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'options'       => 'array',
            'correct_index' => 'integer',
            'map_payload'   => 'array',
            'points'        => 'integer',
        ];
    }

    public function isMapType(): bool
    {
        return in_array($this->type, self::MAP_TYPES, true);
    }
```

- [ ] **Step 5: Run migration + test**

Run: `php artisan migrate && vendor/bin/phpunit --filter QuizQuestionMapTest`
Expected: PASS

- [ ] **Step 6: Regression check + commit**

Run: `vendor/bin/phpunit --filter QuizLeaderboardTest` — Expected: PASS (existing rows default to `mc`).

```bash
git add database/migrations/2026_07_08_100001_add_map_fields_to_quiz_questions_table.php app/Models/QuizQuestion.php tests/Unit/QuizQuestionMapTest.php
git commit -m "feat(map-quiz): quiz_questions type + map_payload columns"
```

---

### Task 2: MapQuizOptions service (label resolution + validation)

The core pure logic: turn a `map_payload` into `options` + `correct_index` per naming mode, and validate payloads against the Cliopatria polity registry.

**Files:**
- Create: `app/Services/MapQuizOptions.php`
- Modify: `lang/nl.json` (one key)
- Test: `tests/Unit/MapQuizOptionsTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CliopatriaSpans;
use App\Services\MapQuizOptions;
use Tests\TestCase;

class MapQuizOptionsTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'answer_mode' => 'numbered',
            'target' => ['qid' => 'Q241', 'label_hist' => 'Cuba', 'label_modern' => 'Cuba'],
            'distractors' => [
                ['qid' => 'Q790', 'label_hist' => 'Saint-Domingue', 'label_modern' => 'Haiti'],
                ['qid' => 'Q766', 'label_hist' => 'Jamaica', 'label_modern' => 'Jamaica'],
                ['qid' => 'Q786', 'label_hist' => 'Santo Domingo', 'label_modern' => 'Dominican Republic'],
            ],
            'relation' => null,
        ], $overrides);
    }

    public function test_hist_mode_uses_historical_labels(): void
    {
        $svc = new MapQuizOptions;
        $r = $svc->resolve('map_locate', $this->payload(), 'hist', correctSlot: 0);

        $this->assertSame(['Cuba', 'Saint-Domingue', 'Jamaica', 'Santo Domingo'], $r['options']);
        $this->assertSame(0, $r['correct_index']);
        $this->assertSame(['Q241', 'Q790', 'Q766', 'Q786'], $r['slot_qids']);
    }

    public function test_modern_mode_uses_modern_labels(): void
    {
        $svc = new MapQuizOptions;
        $r = $svc->resolve('map_locate', $this->payload(), 'modern', correctSlot: 0);

        $this->assertSame(['Cuba', 'Haiti', 'Jamaica', 'Dominican Republic'], $r['options']);
    }

    public function test_hist_modern_mode_combines_when_labels_differ(): void
    {
        $svc = new MapQuizOptions;
        $r = $svc->resolve('map_locate', $this->payload(), 'hist_modern', correctSlot: 0);

        $this->assertSame('Cuba', $r['options'][0]);                       // identical labels: no suffix
        $this->assertSame('Saint-Domingue (now: Haiti)', $r['options'][1]); // differing labels combined
    }

    public function test_correct_slot_moves_target(): void
    {
        $svc = new MapQuizOptions;
        $r = $svc->resolve('map_locate', $this->payload(), 'hist', correctSlot: 2);

        $this->assertSame('Cuba', $r['options'][2]);
        $this->assertSame(2, $r['correct_index']);
        $this->assertSame('Q241', $r['slot_qids'][2]);
    }

    public function test_click_mode_has_single_option(): void
    {
        $svc = new MapQuizOptions;
        $r = $svc->resolve('map_locate', $this->payload(['answer_mode' => 'click']), 'hist');

        $this->assertSame(['Cuba'], $r['options']);
        $this->assertSame(0, $r['correct_index']);
        $this->assertSame(['Q241'], $r['slot_qids']);
    }

    public function test_identify_always_uses_text_options_even_with_click_mode(): void
    {
        $svc = new MapQuizOptions;
        $r = $svc->resolve('map_identify', $this->payload(['answer_mode' => 'click']), 'hist', correctSlot: 1);

        $this->assertCount(4, $r['options']);
        $this->assertSame(1, $r['correct_index']);
    }

    public function test_validate_flags_missing_target_and_wrong_lifespan(): void
    {
        $svc = new MapQuizOptions;
        $spans = app(CliopatriaSpans::class);

        // No target at all.
        $errors = $svc->validate('map_locate', ['answer_mode' => 'click'], $spans, 1820);
        $this->assertNotEmpty($errors);

        // Real polity, wrong year: Abbasid Caliphate is 750-1259 in the committed registry.
        $errors = $svc->validate('map_locate', [
            'answer_mode' => 'click',
            'target' => ['qid' => 'Q12536', 'label_hist' => 'Abbasid Caliphate', 'label_modern' => ''],
        ], $spans, 1820);
        $this->assertNotEmpty($errors);

        // Same polity, valid year: no errors.
        $errors = $svc->validate('map_locate', [
            'answer_mode' => 'click',
            'target' => ['qid' => 'Q12536', 'label_hist' => 'Abbasid Caliphate', 'label_modern' => ''],
        ], $spans, 900);
        $this->assertSame([], $errors);
    }

    public function test_validate_requires_three_distractors_for_numbered(): void
    {
        $svc = new MapQuizOptions;
        $spans = app(CliopatriaSpans::class);

        $p = $this->payload();
        $p['distractors'] = array_slice($p['distractors'], 0, 2);
        // Use a null year so lifespan checks don't interfere with this assertion.
        $errors = $svc->validate('map_locate', $p, $spans, null);
        $this->assertNotEmpty($errors);
    }
}
```

Note: the lifespan test uses the real committed `database/data/cliopatria-polities.json` (Q12536 = Abbasid Caliphate, 750–1259). The distractor QIDs in `payload()` may not all exist in the registry — that's why the three-distractor test passes `year: null` and only asserts the count error. `validate()` must only lifespan-check QIDs that exist in the registry when `$year` is non-null, and must always error on a target QID missing from the registry.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MapQuizOptionsTest`
Expected: FAIL ("Class MapQuizOptions not found")

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resolves a map question's answer options from its map_payload, and validates payloads
 * against the committed Cliopatria polity registry.
 *
 * The resolved options/correct_index are persisted on the QuizQuestion row, so the player,
 * QuizAnswer snapshots and the whole results pipeline read map questions exactly like
 * multiple-choice ones. `slot_qids` (persisted inside map_payload) aligns each option slot
 * with its territory so the player can place numbered markers.
 */
class MapQuizOptions
{
    public const NAMING_MODES = ['hist', 'hist_modern', 'modern'];
    public const ANSWER_MODES = ['click', 'numbered'];
    public const RELATIONS = ['enemy', 'partner'];

    private const DISTRACTOR_COUNT = 3;
    private const SLOT_COUNT = 4;

    /**
     * @param  array  $payload  map_payload shape (see spec §3.1)
     * @param  ?int  $correctSlot  keep an existing slot stable across re-saves; null = pick one
     * @return array{options: array<int,string>, correct_index: int, slot_qids: array<int,?string>}
     */
    public function resolve(string $type, array $payload, string $namingMode, ?int $correctSlot = null): array
    {
        $target = (array) ($payload['target'] ?? []);

        if ($this->isClickMode($type, $payload)) {
            return [
                'options' => [$this->label($target, $namingMode)],
                'correct_index' => 0,
                'slot_qids' => [$target['qid'] ?? null],
            ];
        }

        $distractors = array_slice(array_values((array) ($payload['distractors'] ?? [])), 0, self::DISTRACTOR_COUNT);
        $slot = $correctSlot ?? random_int(0, self::SLOT_COUNT - 1);
        $slot = max(0, min(count($distractors), min(self::SLOT_COUNT - 1, $slot)));

        $entries = $distractors;
        array_splice($entries, $slot, 0, [$target]);

        return [
            'options' => array_map(fn (array $t) => $this->label($t, $namingMode), $entries),
            'correct_index' => $slot,
            'slot_qids' => array_map(fn (array $t) => $t['qid'] ?? null, $entries),
        ];
    }

    /** Click-the-territory questions carry no text options; identify is always text MC. */
    public function isClickMode(string $type, array $payload): bool
    {
        return $type !== 'map_identify' && ($payload['answer_mode'] ?? 'click') === 'click';
    }

    /** The display name for a territory under the scene's naming mode. */
    public function label(array $territory, string $namingMode): string
    {
        $hist = trim((string) ($territory['label_hist'] ?? ''));
        $modern = trim((string) ($territory['label_modern'] ?? ''));

        return match ($namingMode) {
            'modern' => $modern !== '' ? $modern : $hist,
            'hist_modern' => ($modern !== '' && $modern !== $hist && $hist !== '')
                ? __(':hist (now: :modern)', ['hist' => $hist, 'modern' => $modern])
                : ($hist !== '' ? $hist : $modern),
            default => $hist !== '' ? $hist : $modern,
        };
    }

    /**
     * Authoring-time validation. Returns human-readable errors ([] = valid).
     * Lifespan checks only run for QIDs present in the registry and when $year is known —
     * the registry is authoritative for the tiles, so a known QID outside its lifespan
     * would be invisible on the quiz map.
     *
     * @return array<int,string>
     */
    public function validate(string $type, array $payload, CliopatriaSpans $spans, ?int $year): array
    {
        $errors = [];

        $target = (array) ($payload['target'] ?? []);
        $targetQid = trim((string) ($target['qid'] ?? ''));

        if ($targetQid === '') {
            $errors[] = 'Pick the answer territory on the map.';
        } elseif ($spans->for($targetQid) === null) {
            $errors[] = 'The answer territory is not a known map polity — re-pick it on the map.';
        } elseif ($year !== null && ! $this->aliveAt($spans->for($targetQid), $year)) {
            $errors[] = "The answer territory does not exist on the map in {$year} — re-pick it or change the scene year.";
        }

        if (trim((string) ($target['label_hist'] ?? '')) === '' && trim((string) ($target['label_modern'] ?? '')) === '') {
            $errors[] = 'The answer territory needs a name.';
        }

        if (! $this->isClickMode($type, $payload)) {
            $distractors = array_values((array) ($payload['distractors'] ?? []));
            if (count($distractors) !== self::DISTRACTOR_COUNT) {
                $errors[] = 'Numbered and identify questions need exactly 3 wrong territories.';
            }
            foreach ($distractors as $i => $d) {
                $qid = trim((string) ($d['qid'] ?? ''));
                $span = $qid !== '' ? $spans->for($qid) : null;
                if ($qid !== '' && $span !== null && $year !== null && ! $this->aliveAt($span, $year)) {
                    $n = $i + 1;
                    $errors[] = "Wrong territory #{$n} does not exist on the map in {$year}.";
                }
            }
        }

        $relation = $payload['relation'] ?? null;
        if ($type === 'map_actor' && ! in_array($relation, self::RELATIONS, true)) {
            $errors[] = 'Actor questions need a relation (enemy or partner).';
        }

        return $errors;
    }

    /** @param  array{from:?int,to:?int}  $span */
    private function aliveAt(array $span, int $year): bool
    {
        return ($span['from'] === null || $span['from'] <= $year)
            && ($span['to'] === null || $span['to'] >= $year);
    }
}
```

- [ ] **Step 4: Add the Dutch translation key**

In `lang/nl.json`, add (keep JSON valid — comma placement):

```json
":hist (now: :modern)": ":hist (nu: :modern)"
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter MapQuizOptionsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/MapQuizOptions.php tests/Unit/MapQuizOptionsTest.php lang/nl.json
git commit -m "feat(map-quiz): MapQuizOptions service - label resolution + payload validation"
```

---

### Task 3: Scene support — add-scene tile + defaults + draft sync

**Files:**
- Modify: `app/Livewire/Wizard/Step3SceneConfigurator.php` (`addScene()` ~line 842, quizQuestions payload ~line 137)
- Modify: `app/Livewire/Wizard/Concerns/EditsQuizQuestions.php` (`syncQuizDraftFor()` line 43, `loadQuizDraft()` line 65)
- Modify: `resources/views/livewire/wizard/step3-scene-configurator.blade.php` (picker modal ~line 320)
- Test: `tests/Feature/Wizard/MapQuizSceneTest.php`

- [ ] **Step 1: Write the failing test**

Look at existing tests in `tests/Feature/Wizard/` for how they build a lesson + mount `Step3SceneConfigurator` with Livewire (copy the setup of the nearest quiz-related test). Then:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MapQuizSceneTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_scene_creates_map_quiz_with_defaults(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['user_id' => $teacher->id]);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('addScene', 'game', 'map_quiz');

        $scene = $lesson->scenes()->where('game_type', 'map_quiz')->first();
        $this->assertNotNull($scene);
        $this->assertSame('game', $scene->kind);
        $this->assertSame('ready', $scene->status);
        $this->assertSame('hist', $scene->config['naming_mode']);
        $this->assertTrue($scene->config['modern_borders_switch']);
    }

    public function test_map_quiz_does_not_overwrite_lesson_game_type(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['user_id' => $teacher->id, 'game_type' => 'quiz']);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('addScene', 'game', 'map_quiz');

        $this->assertSame('quiz', $lesson->fresh()->game_type);
    }
}
```

Adjust the component mount parameters to whatever the existing wizard tests pass (`['lesson' => $lesson]` may need to match the component's `mount()` signature — check `Step3SceneConfigurator::mount()`).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MapQuizSceneTest`
Expected: FAIL (scene not created / game_type rejected)

- [ ] **Step 3: Extend `addScene()`**

In `Step3SceneConfigurator.php` line ~846, change the game-type whitelist and add map-quiz defaults. Replace:

```php
        $gameType = in_array($gameType, ['quiz', 'strategy', 'debate'], true) ? $gameType : null;
```

with:

```php
        $gameType = in_array($gameType, ['quiz', 'strategy', 'debate', 'map_quiz'], true) ? $gameType : null;
```

Then inside the `if ($kind === 'game')` block (line ~865), add a map-quiz early branch BEFORE the existing `$gameCount` logic:

```php
        if ($kind === 'game' && $gameType === 'map_quiz') {
            $mapDefaults = Scene::mapPayloadForLesson($this->lesson);
            $payload += [
                'game_type' => 'map_quiz',
                'duration_seconds' => 180,
                'year' => $mapDefaults['year'],
                'location' => $mapDefaults['location'] ?? null,
                'status' => 'ready',   // no generation pipeline — ready as soon as it has questions
                'config' => [
                    'qid' => $mapDefaults['config']['qid'] ?? null,
                    'year' => $mapDefaults['year'] ?? 1600,
                    'naming_mode' => 'hist',
                    'modern_borders_switch' => true,
                    'quiz_shuffle' => 'per_player',
                ],
            ];
            // Deliberately NOT touching lesson->include_game / lesson->game_type:
            // those drive the legacy single-game flow; map quiz is per-scene only.
            $scene = Scene::create($payload);
            $this->selectSceneInternal($scene->id);

            return;
        }
```

(`$payload['status']` was set to `'pending'` earlier for non-map kinds — the `+=` above does not override existing keys, so set status explicitly first: change the branch to assign `$payload['status'] = 'ready';` on its own line before `$payload += [...]`, and drop `'status'` from the `+=` array. `+=` keeps existing keys — this matters.)

- [ ] **Step 4: Accept map_quiz in the draft sync + question payload**

In `EditsQuizQuestions.php` line 43, replace:

```php
        $isQuiz = $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz';
```

with:

```php
        $isQuiz = $scene->kind === 'game'
            && in_array($scene->game_type ?? null, ['quiz', 'map_quiz'], true);
```

In `loadQuizDraft()` (line 74–82), add `type` and `map_payload` to the mapped draft row:

```php
        $this->quizDraft = $query->orderBy('order')->get()
            ->map(fn (QuizQuestion $q) => [
                'id' => $q->id,
                'type' => (string) ($q->type ?? 'mc'),
                'question' => (string) $q->question,
                'options' => $this->padOptions(is_array($q->options) ? $q->options : []),
                'correct_index' => (int) $q->correct_index,
                'asks_ahead' => (bool) $q->asks_ahead,
                'explanation' => (string) ($q->explanation ?? ''),
                'map_payload' => is_array($q->map_payload) ? $q->map_payload : null,
            ])->values()->all();
```

In `Step3SceneConfigurator.php` line ~140, the inspector's `quizQuestions` payload condition — find:

```php
            'quizQuestions' => $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz'
```

and widen it the same way (`in_array(..., ['quiz','map_quiz'], true)`).

- [ ] **Step 5: Add the picker tile**

In `step3-scene-configurator.blade.php` after the debate tile (line ~315–319), add (copy the exact classes of the neighbouring tiles so it matches):

```blade
                <button type="button" wire:click="addScene('game', 'map_quiz')"
                        class="{{-- same classes as the debate tile above --}}">
                    <span class="text-2xl">🗺️</span>
                    <span class="font-semibold">Map quiz</span>
                    <span class="text-xs text-slate-400">Students answer on the world map</span>
                </button>
```

- [ ] **Step 6: Run tests + commit**

Run: `vendor/bin/phpunit --filter MapQuizSceneTest`
Expected: PASS. Also run `vendor/bin/phpunit --filter Wizard` to catch regressions.

```bash
git add app/Livewire/Wizard/Step3SceneConfigurator.php app/Livewire/Wizard/Concerns/EditsQuizQuestions.php resources/views/livewire/wizard/step3-scene-configurator.blade.php tests/Feature/Wizard/MapQuizSceneTest.php
git commit -m "feat(map-quiz): map_quiz scene type - add-scene tile, defaults, draft sync"
```

---### Task 4: EditsMapQuizQuestions trait — draft mutations + autosave integration

**Files:**
- Create: `app/Livewire/Wizard/Concerns/EditsMapQuizQuestions.php`
- Modify: `app/Livewire/Wizard/Concerns/EditsQuizQuestions.php` (`autosaveQuiz()` line ~345, `addQuizQuestion()` line ~85)
- Modify: `app/Livewire/Wizard/Step3SceneConfigurator.php` (use the new trait)
- Test: `tests/Feature/Wizard/MapQuizAutosaveTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MapQuizAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private function mapQuizScene(Lesson $lesson): Scene
    {
        return Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'game',
            'game_type' => 'map_quiz',
            'status' => 'ready',
            'config' => ['year' => 900, 'naming_mode' => 'hist', 'modern_borders_switch' => true],
        ]);
    }

    public function test_complete_click_question_autosaves_with_resolved_options(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['user_id' => $teacher->id]);
        $scene = $this->mapQuizScene($lesson);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('addMapQuizQuestion')
            ->set('quizDraft.0.question', 'Click the Abbasid Caliphate!')
            // Q12536 = Abbasid Caliphate (750-1259) in database/data/cliopatria-polities.json
            ->call('setMapTarget', 0, 'Q12536', 'Abbasid Caliphate', 'Iraq');

        $q = $lesson->quizQuestions()->first();
        $this->assertNotNull($q);
        $this->assertSame('map_locate', $q->type);
        $this->assertSame(['Abbasid Caliphate'], $q->options);
        $this->assertSame(0, $q->correct_index);
        $this->assertSame('Q12536', $q->map_payload['target']['qid']);
        $this->assertSame($scene->id, $q->scene_id);
    }

    public function test_numbered_question_requires_three_distractors(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['user_id' => $teacher->id]);
        $scene = $this->mapQuizScene($lesson);

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('addMapQuizQuestion')
            ->set('quizDraft.0.question', 'Which is the Abbasid Caliphate?')
            ->call('setMapAnswerMode', 0, 'numbered')
            ->call('setMapTarget', 0, 'Q12536', 'Abbasid Caliphate', 'Iraq');

        // Incomplete: 0 of 3 distractors — held in draft, not persisted.
        $this->assertSame(0, $lesson->quizQuestions()->count());
        $this->assertNotEmpty($component->get('quizErrors')[0] ?? []);

        // Q389688 Achaemenid / Q244796 Achaean League exist in the registry but not in 900 —
        // validation is year-aware, so use registry polities alive around 900 if these fail;
        // check database/data/cliopatria-polities.json for three QIDs whose span covers 900.
        $component
            ->call('addMapDistractor', 0, 'Q9683', 'Tang Dynasty', 'China')
            ->call('addMapDistractor', 0, 'Q12544', 'Byzantine Empire', 'Turkey')
            ->call('addMapDistractor', 0, 'Q170174', 'Kingdom of the Franks', 'France');

        $q = $lesson->quizQuestions()->first();
        $this->assertNotNull($q);
        $this->assertSame('map_locate', $q->type);
        $this->assertCount(4, $q->options);
        $this->assertSame('Abbasid Caliphate', $q->options[$q->correct_index]);
        $this->assertCount(4, $q->map_payload['slot_qids']);
        $this->assertSame('Q12536', $q->map_payload['slot_qids'][$q->correct_index]);
    }

    public function test_naming_mode_change_regenerates_options(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['user_id' => $teacher->id]);
        $scene = $this->mapQuizScene($lesson);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('addMapQuizQuestion')
            ->set('quizDraft.0.question', 'Click the Abbasid Caliphate!')
            ->call('setMapTarget', 0, 'Q12536', 'Abbasid Caliphate', 'Iraq')
            ->call('setMapNamingMode', 'modern');

        $q = $lesson->quizQuestions()->first();
        $this->assertSame(['Iraq'], $q->options);
        $this->assertSame('modern', $scene->fresh()->config['naming_mode']);
    }
}
```

Before running: verify the three distractor QIDs above exist in `database/data/cliopatria-polities.json` with spans covering the year 900 (`python3 -c "import json; d={r['qid']:r for r in json.load(open('database/data/cliopatria-polities.json'))}; print([d.get(q) for q in ['Q9683','Q12544','Q170174']])"`). If any is missing or outside 750–1259 ∩ 900, substitute a QID from the file that is alive at 900 and update the test.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MapQuizAutosaveTest`
Expected: FAIL ("method addMapQuizQuestion does not exist")

- [ ] **Step 3: Create the trait**

`app/Livewire/Wizard/Concerns/EditsMapQuizQuestions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Services\CliopatriaSpans;
use App\Services\MapQuizOptions;

/**
 * Map-quiz question editing for the scene configurator's Map-Quiz inspector.
 *
 * Extends the EditsQuizQuestions draft with map-typed rows: the teacher picks territories
 * on a mini-map (QID + labels come from tile properties — never from an LLM), and autosave
 * resolves the row's options/correct_index from the map_payload via MapQuizOptions so the
 * player and the whole results pipeline read map questions like ordinary MC questions.
 *
 * Host component must also use EditsQuizQuestions (shared $quizDraft / autosaveQuiz()).
 */
trait EditsMapQuizQuestions
{
    public function addMapQuizQuestion(): void
    {
        if (count($this->quizDraft) >= 12) {   // same cap as EditsQuizQuestions::QUIZ_MAX_QUESTIONS
            return;
        }
        $this->quizSaved = false;
        $this->quizDraft[] = [
            'id' => null,
            'type' => 'map_locate',
            'question' => '',
            'options' => ['', '', '', ''],
            'correct_index' => 0,
            'asks_ahead' => false,
            'explanation' => '',
            'map_payload' => [
                'answer_mode' => 'click',
                'target' => null,
                'distractors' => [],
                'relation' => null,
            ],
        ];
    }

    public function setMapQuestionType(int $index, string $type): void
    {
        if (! isset($this->quizDraft[$index]) || ! in_array($type, QuizQuestion::MAP_TYPES, true)) {
            return;
        }
        $this->quizDraft[$index]['type'] = $type;
        if ($type !== 'map_actor') {
            $this->quizDraft[$index]['map_payload']['relation'] = null;
        }
        $this->autosaveQuiz();
    }

    public function setMapAnswerMode(int $index, string $mode): void
    {
        if (! isset($this->quizDraft[$index]) || ! in_array($mode, MapQuizOptions::ANSWER_MODES, true)) {
            return;
        }
        $this->quizDraft[$index]['map_payload']['answer_mode'] = $mode;
        $this->autosaveQuiz();
    }

    public function setMapRelation(int $index, ?string $relation): void
    {
        if (! isset($this->quizDraft[$index])
            || ($relation !== null && ! in_array($relation, MapQuizOptions::RELATIONS, true))) {
            return;
        }
        $this->quizDraft[$index]['map_payload']['relation'] = $relation;
        $this->autosaveQuiz();
    }

    /** Called from the mini-map JS: the clicked feature's QID + tile name + modern name. */
    public function setMapTarget(int $index, string $qid, string $labelHist, string $labelModern): void
    {
        if (! isset($this->quizDraft[$index]) || trim($qid) === '') {
            return;
        }
        $this->quizDraft[$index]['map_payload']['target'] = [
            'qid' => trim($qid),
            'label_hist' => trim($labelHist),
            'label_modern' => trim($labelModern),
        ];
        $this->autosaveQuiz();
    }

    public function addMapDistractor(int $index, string $qid, string $labelHist, string $labelModern): void
    {
        if (! isset($this->quizDraft[$index]) || trim($qid) === '') {
            return;
        }
        $existing = $this->quizDraft[$index]['map_payload']['distractors'] ?? [];
        if (count($existing) >= 3) {
            return;
        }
        $targetQid = $this->quizDraft[$index]['map_payload']['target']['qid'] ?? null;
        $known = array_merge([$targetQid], array_column($existing, 'qid'));
        if (in_array(trim($qid), $known, true)) {
            return;   // no duplicate territories in one question
        }
        $this->quizDraft[$index]['map_payload']['distractors'] = [...$existing, [
            'qid' => trim($qid),
            'label_hist' => trim($labelHist),
            'label_modern' => trim($labelModern),
        ]];
        $this->autosaveQuiz();
    }

    public function removeMapDistractor(int $index, int $dIndex): void
    {
        if (! isset($this->quizDraft[$index]['map_payload']['distractors'][$dIndex])) {
            return;
        }
        $d = $this->quizDraft[$index]['map_payload']['distractors'];
        unset($d[$dIndex]);
        $this->quizDraft[$index]['map_payload']['distractors'] = array_values($d);
        $this->autosaveQuiz();
    }

    // ── Scene-level settings (mirror quizShuffle()/setQuizShuffle() pattern) ──

    public function mapNamingMode(): string
    {
        $scene = $this->quizDraftSceneId ? Scene::find($this->quizDraftSceneId) : null;
        $mode = ($scene?->config ?? [])['naming_mode'] ?? 'hist';

        return in_array($mode, MapQuizOptions::NAMING_MODES, true) ? $mode : 'hist';
    }

    public function setMapNamingMode(string $mode): void
    {
        if (! $this->quizDraftSceneId || ! in_array($mode, MapQuizOptions::NAMING_MODES, true)) {
            return;
        }
        $scene = Scene::find($this->quizDraftSceneId);
        if ($scene) {
            $scene->update(['config' => array_merge($scene->config ?? [], ['naming_mode' => $mode])]);
            $this->autosaveQuiz();   // options carry the naming mode — regenerate
        }
    }

    public function mapModernBorders(): bool
    {
        $scene = $this->quizDraftSceneId ? Scene::find($this->quizDraftSceneId) : null;

        return (bool) (($scene?->config ?? [])['modern_borders_switch'] ?? true);
    }

    public function setMapModernBorders(bool $on): void
    {
        if (! $this->quizDraftSceneId) {
            return;
        }
        $scene = Scene::find($this->quizDraftSceneId);
        if ($scene) {
            $scene->update(['config' => array_merge($scene->config ?? [], ['modern_borders_switch' => $on])]);
        }
    }

    // ── Autosave hook (called from EditsQuizQuestions::autosaveQuiz for map-typed rows) ──

    /**
     * Clean one map-typed draft row for persistence.
     *
     * @return array{0: ?array, 1: array<int,string>}  [$cleanRow|null, $errors]
     *         null row + [] errors = untouched (skip silently);
     *         null row + errors = incomplete (hold in draft, show errors)
     */
    protected function cleanMapQuestion(array $q, string $type): array
    {
        $question = trim((string) ($q['question'] ?? ''));
        $payload = is_array($q['map_payload'] ?? null) ? $q['map_payload'] : [];
        $hasTarget = ! empty($payload['target']['qid'] ?? null);

        if ($question === '' && ! $hasTarget) {
            return [null, []];   // untouched row — no nagging
        }

        $svc = app(MapQuizOptions::class);
        $scene = $this->quizDraftSceneId ? Scene::find($this->quizDraftSceneId) : null;
        $year = isset($scene?->config['year']) ? (int) $scene->config['year'] : null;

        $errors = $svc->validate($type, $payload, app(CliopatriaSpans::class), $year);
        if ($question === '') {
            $errors[] = 'Question text is required.';
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        $resolved = $svc->resolve(
            $type,
            $payload,
            $this->mapNamingMode(),
            isset($payload['correct_slot']) ? (int) $payload['correct_slot'] : null,
        );

        return [[
            'type' => $type,
            'question' => $question,
            'options' => $resolved['options'],
            'correct_index' => $resolved['correct_index'],
            'asks_ahead' => (bool) ($q['asks_ahead'] ?? false),
            'explanation' => trim((string) ($q['explanation'] ?? '')) ?: null,
            'map_payload' => array_merge($payload, [
                'correct_slot' => $resolved['correct_index'],   // stable across re-saves
                'slot_qids' => $resolved['slot_qids'],
            ]),
        ], []];
    }
}
```

- [ ] **Step 4: Wire map rows into `autosaveQuiz()`**

In `EditsQuizQuestions::autosaveQuiz()` (line ~350), at the TOP of the `foreach ($this->quizDraft as $i => $q)` loop body, add:

```php
            $type = in_array($q['type'] ?? 'mc', QuizQuestion::TYPES, true) ? ($q['type'] ?? 'mc') : 'mc';
            if (in_array($type, QuizQuestion::MAP_TYPES, true)) {
                [$row, $errors] = $this->cleanMapQuestion($q, $type);
                if ($errors !== []) {
                    $this->quizErrors[$i] = $errors;
                }
                if ($row !== null) {
                    $clean[] = $row;
                }

                continue;
            }
```

And in the persistence loop at the bottom (line ~395), extend the `create([...])` with the two new columns (safe for mc rows via defaults):

```php
                $this->lesson->quizQuestions()->create([
                    'scene_id' => $this->quizDraftSceneId,
                    'order' => $order,
                    'type' => $row['type'] ?? 'mc',
                    'question' => $row['question'],
                    'options' => $row['options'],
                    'correct_index' => $row['correct_index'],
                    'map_payload' => $row['map_payload'] ?? null,
                    'asks_ahead' => $row['asks_ahead'],
                    'explanation' => $row['explanation'],
                    'points' => 10,
                ]);
```

Also add `'type' => 'mc',` and `'map_payload' => null,` to the blank row in `addQuizQuestion()` (line ~91) so mc drafts are explicitly typed.

`cleanMapQuestion` lives in the new trait but is called from `EditsQuizQuestions` — both traits are used by the same component, so the call resolves. Add `use EditsMapQuizQuestions;` next to `use EditsQuizQuestions;` in `Step3SceneConfigurator.php` (find the existing `use ...EditsQuizQuestions` statement inside the class body).

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter MapQuizAutosaveTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter Quiz` (all quiz-related) — Expected: PASS (mc path untouched).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Wizard/Concerns/EditsMapQuizQuestions.php app/Livewire/Wizard/Concerns/EditsQuizQuestions.php app/Livewire/Wizard/Step3SceneConfigurator.php tests/Feature/Wizard/MapQuizAutosaveTest.php
git commit -m "feat(map-quiz): map question drafts - territory setters + payload-driven autosave"
```

---

### Task 5: AI question-text drafting (MapQuizQuestionPrompt)

The LLM only words the question — it never picks QIDs or labels (hallucination guard, spec §5).

**Files:**
- Create: `app/Services/MapQuizQuestionPrompt.php`
- Modify: `app/Livewire/Wizard/Concerns/EditsMapQuizQuestions.php` (add `generateMapQuestionText`)
- Test: `tests/Feature/Wizard/MapQuizAiDraftTest.php`

- [ ] **Step 1: Write the failing test**

Check how existing tests fake the LLM: `grep -rn "OpenAiLlmService" tests/ | head`. They bind a mock/fake into the container. Follow that pattern:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class MapQuizAiDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_map_question_text_fills_question_only(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['user_id' => $teacher->id]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'game',
            'game_type' => 'map_quiz', 'status' => 'ready',
            'config' => ['year' => 900, 'naming_mode' => 'hist'],
        ]);

        $llm = Mockery::mock(OpenAiLlmService::class);
        $llm->shouldReceive('json')->once()->andReturn(['question' => 'Which realm did the caliphs rule?']);
        $this->app->instance(OpenAiLlmService::class, $llm);

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('addMapQuizQuestion')
            ->call('setMapTarget', 0, 'Q12536', 'Abbasid Caliphate', 'Iraq')
            ->call('generateMapQuestionText', 0);

        $this->assertSame('Which realm did the caliphs rule?', $component->get('quizDraft')[0]['question']);
        // Target untouched — the LLM never picks territories.
        $this->assertSame('Q12536', $component->get('quizDraft')[0]['map_payload']['target']['qid']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MapQuizAiDraftTest`
Expected: FAIL ("method generateMapQuestionText does not exist")

- [ ] **Step 3: Write the prompt service**

Read `app/Services/QuizQuestionDraftPrompt.php` first and mirror its structure/tone. Then:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/**
 * Prompt builder for AI-drafting a MAP question's text. The territory (QID + labels) is
 * already fixed by the teacher's map pick — the LLM only words the question. It must not
 * name the answer territory in the question (that would give it away).
 */
class MapQuizQuestionPrompt
{
    public static function system(Lesson $lesson): string
    {
        return <<<PROMPT
You write ONE short map-quiz question for a K-12 history lesson about "{$lesson->topic}".
Students answer ON A MAP by finding a territory — the question must ask WHERE something is
or WHO an actor was, and must NOT reveal the territory's name or its position.
Only use facts from the lesson context provided. If uncertain, keep the question generic — never invent.
Respond as JSON: {"question": "..."}
PROMPT;
    }

    /**
     * @param  string  $type  map_locate | map_identify | map_actor
     * @param  array<int,string>  $existing  other question texts (avoid duplicates)
     */
    public static function user(Lesson $lesson, string $type, string $targetLabel, ?string $relation, array $existing): string
    {
        $task = match ($type) {
            'map_identify' => "The territory \"{$targetLabel}\" will be HIGHLIGHTED on the map; ask what it is called. Do not name it.",
            'map_actor' => 'Ask who the '.($relation === 'partner' ? 'ally/partner' : 'enemy/rival')." of the lesson's protagonist was on this map. The correct answer territory is \"{$targetLabel}\" — do not name it in the question.",
            default => "Ask the student to find \"{$targetLabel}\" on the map WITHOUT naming its location. Example shape: \"Which territory did X rule?\" or \"Where did Y happen?\" — but grounded in this lesson.",
        };

        $avoid = $existing === [] ? '' : "\nAvoid duplicating these existing questions:\n- ".implode("\n- ", $existing);

        return "Lesson topic: {$lesson->topic}\nGrade: {$lesson->grade_level}\n{$task}{$avoid}";
    }
}
```

Check `Lesson` has `topic` and `grade_level` attributes (`grep -n "topic\|grade" app/Models/Lesson.php | head`); substitute the real attribute names if they differ.

- [ ] **Step 4: Add the Livewire method to `EditsMapQuizQuestions`**

```php
    /** AI-draft the question TEXT only — the territory stays exactly as picked on the map. */
    public function generateMapQuestionText(int $index): void
    {
        if (! isset($this->quizDraft[$index])) {
            return;
        }
        $q = $this->quizDraft[$index];
        $payload = $q['map_payload'] ?? [];
        $targetLabel = trim((string) ($payload['target']['label_hist'] ?? ''));
        if ($targetLabel === '') {
            $this->dispatch('toast', message: 'Pick the answer territory on the map first.', type: 'warning');

            return;
        }

        $existing = collect($this->quizDraft)
            ->reject(fn ($row, $i) => $i === $index)
            ->pluck('question')->filter()->values()->all();

        try {
            $result = app(\App\Services\OpenAiLlmService::class)->json(
                system: \App\Services\MapQuizQuestionPrompt::system($this->lesson),
                user: \App\Services\MapQuizQuestionPrompt::user(
                    $this->lesson,
                    (string) ($q['type'] ?? 'map_locate'),
                    $targetLabel,
                    $payload['relation'] ?? null,
                    $existing,
                ),
            );
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Question generation failed — try again.', type: 'error');

            return;
        }

        $question = trim((string) ($result['question'] ?? ''));
        if ($question === '') {
            $this->dispatch('toast', message: 'Question generation came back empty — try again.', type: 'error');

            return;
        }

        $this->quizDraft[$index]['question'] = $question;
        $this->autosaveQuiz();
    }
```

- [ ] **Step 5: Run tests + commit**

Run: `vendor/bin/phpunit --filter MapQuizAiDraftTest`
Expected: PASS

```bash
git add app/Services/MapQuizQuestionPrompt.php app/Livewire/Wizard/Concerns/EditsMapQuizQuestions.php tests/Feature/Wizard/MapQuizAiDraftTest.php
git commit -m "feat(map-quiz): AI drafts question text only - territories stay teacher-picked"
```

---

### Task 6: Authoring UI — map-quiz inspector with mini-map picker

**Files:**
- Create: `resources/views/components/lesson/scene-inspector-map-quiz.blade.php`
- Modify: `resources/views/livewire/wizard/step3-scene-configurator.blade.php` (inspector branch, line ~202)
- Modify: `resources/js/lesson-map.js` (add `onPolityClick` + `queryModernName` hooks — small)

No PHP test — this is Blade/JS; verified in Task 10 (test-ui). Keep each change small and commit at the end of the task.

- [ ] **Step 1: Add pick hooks to `renderLessonMap`**

In `resources/js/lesson-map.js`:

(a) Destructure the new option (line ~52):

```js
  const { qid = null, interactive = true, annotations = [], editable = false, onAnnotationsChange = null, projection = 'mercator', onPolityClick = null } = opts
```

(b) After the `map.on('idle', ...)` registration (line ~328), add:

```js
  // Territory pick (authoring + click-to-answer): resolve the clicked polity from the
  // transparent boundaries-fill layer. The layer is added late (after terrain), so guard.
  if (onPolityClick) {
    map.on('click', (e) => {
      if (!map.getLayer('boundaries-fill')) return
      const feats = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })
      const f = feats.find((x) => x.properties?.Wikidata)
      if (!f) return   // ocean / unnamed area — not an answer
      onPolityClick({
        qid: String(f.properties.Wikidata),
        name: String(f.properties.Name || ''),
        lngLat: [e.lngLat.lng, e.lngLat.lat],
      })
    })
    map.getCanvas().style.cursor = 'crosshair'
  }
```

(c) Add a modern-name resolver to the returned API (extend the `return {...}` at line ~354):

```js
    // The modern (year-2024) polity name at a point — used by the authoring picker to
    // prefill label_modern. Cliopatria's data reaches ToYear 2024, so "modern" is just
    // the same source filtered to that year. Returns '' when nothing matches.
    queryModernName: (lngLat) => {
      try {
        const pt = map.project({ lng: lngLat[0], lat: lngLat[1] })
        if (!map.getLayer('modern-probe')) {
          map.addLayer({
            id: 'modern-probe', type: 'fill', source: 'cliopatria', 'source-layer': 'boundaries',
            filter: polityFilter(2024), paint: { 'fill-opacity': 0 },
          })
        }
        const feats = map.queryRenderedFeatures(pt, { layers: ['modern-probe'] })
        return String(feats.find((f) => f.properties?.Name)?.properties.Name || '')
      } catch (_) { return '' }
    },
```

Note: `queryRenderedFeatures` may need a frame after `addLayer` before the probe layer is queryable. The picker (Step 3 below) calls it inside a `setTimeout(..., 350)` after the click — good enough for authoring; if it returns '' the teacher types the modern name by hand (the field is editable by design).

- [ ] **Step 2: Wire the inspector branch**

In `step3-scene-configurator.blade.php` line ~202, change the game branch to route map_quiz to its own component:

```blade
                @elseif ($sceneModel->kind === 'game' && $sceneModel->game_type === 'map_quiz')
                    <x-lesson.scene-inspector-map-quiz :scene="$sceneModel"
                        :quiz-draft="$quizDraft" :quiz-errors="$quizErrors" :quiz-saved="$quizSaved"
                        :naming-mode="$this->mapNamingMode()"
                        :modern-borders="$this->mapModernBorders()"
                        :quiz-shuffle="$this->quizShuffle()" />
                @elseif ($sceneModel->kind === 'game')
```

(i.e. insert the new branch BEFORE the existing `kind === 'game'` branch.)

- [ ] **Step 3: Create the inspector component**

`resources/views/components/lesson/scene-inspector-map-quiz.blade.php`. Read `scene-inspector-game.blade.php` first and copy its container/heading classes so the panel matches visually. Structure (full component):

```blade
@props(['scene', 'quizDraft' => [], 'quizErrors' => [], 'quizSaved' => false, 'namingMode' => 'hist', 'modernBorders' => true, 'quizShuffle' => 'per_player'])

<div class="space-y-4"
     x-data="mapQuizInspector({ year: {{ (int) (($scene->config['year'] ?? 1600)) }}, qid: @js($scene->config['qid'] ?? null) })">

    {{-- ── Scene settings ─────────────────────────────── --}}
    <div>
        <span class="text-[10px] uppercase tracking-widest text-slate-500">Map quiz settings</span>

        <label class="form-control mt-2">
            <span class="label-text text-xs">Territory names</span>
            <select class="select select-sm select-bordered"
                    wire:change="setMapNamingMode($event.target.value)">
                <option value="hist" @selected($namingMode === 'hist')>{{ __('Historical names only') }}</option>
                <option value="hist_modern" @selected($namingMode === 'hist_modern')>{{ __('Historical (now: modern)') }}</option>
                <option value="modern" @selected($namingMode === 'modern')>{{ __('Modern names only') }}</option>
            </select>
        </label>

        <label class="label cursor-pointer justify-start gap-2 mt-1">
            <input type="checkbox" class="toggle toggle-sm"
                   @checked($modernBorders)
                   wire:change="setMapModernBorders($event.target.checked)" />
            <span class="label-text text-xs">{{ __('Students may show modern borders') }}</span>
        </label>
    </div>

    {{-- ── Mini-map picker ────────────────────────────── --}}
    <div>
        <div class="flex items-center justify-between">
            <span class="text-[10px] uppercase tracking-widest text-slate-500">Pick territories</span>
            <span class="text-[10px] text-slate-400" x-text="pickHint"></span>
        </div>
        <div x-ref="minimap" wire:ignore class="mt-2 rounded-xl overflow-hidden border border-slate-700/60"
             style="height: 260px;"></div>
    </div>

    {{-- ── Questions ──────────────────────────────────── --}}
    <div class="space-y-3">
        @foreach ($quizDraft as $i => $q)
            @php $p = $q['map_payload'] ?? []; @endphp
            <div class="card bg-base-200 p-3 space-y-2 {{ ($quizErrors[$i] ?? []) ? 'border border-error/60' : '' }}">
                <div class="flex gap-2">
                    <select class="select select-xs select-bordered"
                            wire:change="setMapQuestionType({{ $i }}, $event.target.value)">
                        <option value="map_locate" @selected(($q['type'] ?? '') === 'map_locate')>{{ __('Locate') }}</option>
                        <option value="map_identify" @selected(($q['type'] ?? '') === 'map_identify')>{{ __('Identify') }}</option>
                        <option value="map_actor" @selected(($q['type'] ?? '') === 'map_actor')>{{ __('Actor') }}</option>
                    </select>

                    @if (($q['type'] ?? '') !== 'map_identify')
                        <select class="select select-xs select-bordered"
                                wire:change="setMapAnswerMode({{ $i }}, $event.target.value)">
                            <option value="click" @selected(($p['answer_mode'] ?? 'click') === 'click')>{{ __('Click the map') }}</option>
                            <option value="numbered" @selected(($p['answer_mode'] ?? '') === 'numbered')>{{ __('Numbered ①-④') }}</option>
                        </select>
                    @endif

                    @if (($q['type'] ?? '') === 'map_actor')
                        <select class="select select-xs select-bordered"
                                wire:change="setMapRelation({{ $i }}, $event.target.value)">
                            <option value="" @selected(empty($p['relation']))>{{ __('relation…') }}</option>
                            <option value="enemy" @selected(($p['relation'] ?? '') === 'enemy')>{{ __('Enemy') }}</option>
                            <option value="partner" @selected(($p['relation'] ?? '') === 'partner')>{{ __('Partner') }}</option>
                        </select>
                    @endif

                    <button type="button" class="btn btn-ghost btn-xs ml-auto" title="{{ __('AI draft question text') }}"
                            wire:click="generateMapQuestionText({{ $i }})">✨</button>
                    <button type="button" class="btn btn-ghost btn-xs" wire:click="removeQuizQuestion({{ $i }})">✕</button>
                </div>

                <input type="text" class="input input-sm input-bordered w-full"
                       placeholder="{{ __('Question — e.g. Which country is Cuba?') }}"
                       wire:model.blur="quizDraft.{{ $i }}.question" />

                {{-- Target --}}
                <div class="flex items-center gap-2 text-xs">
                    <button type="button"
                            class="btn btn-xs {{ ($p['target']['qid'] ?? null) ? 'btn-success btn-outline' : 'btn-primary' }}"
                            x-on:click="beginPick('target', {{ $i }})">
                        {{ ($p['target']['qid'] ?? null) ? '✓ '.($p['target']['label_hist'] ?: $p['target']['qid']) : __('Pick answer on map') }}
                    </button>
                    @if ($p['target']['qid'] ?? null)
                        <input type="text" class="input input-xs input-bordered w-28" placeholder="{{ __('historical name') }}"
                               wire:model.blur="quizDraft.{{ $i }}.map_payload.target.label_hist" />
                        <input type="text" class="input input-xs input-bordered w-28" placeholder="{{ __('modern name') }}"
                               wire:model.blur="quizDraft.{{ $i }}.map_payload.target.label_modern" />
                    @endif
                </div>

                {{-- Distractors (numbered / identify only) --}}
                @if (($q['type'] ?? '') === 'map_identify' || ($p['answer_mode'] ?? '') === 'numbered')
                    <div class="flex flex-wrap items-center gap-1 text-xs">
                        <span class="text-slate-500">{{ __('Wrong territories:') }}</span>
                        @foreach (($p['distractors'] ?? []) as $di => $d)
                            <span class="badge badge-sm badge-outline gap-1">
                                {{ $d['label_hist'] ?: $d['qid'] }}
                                <button type="button" wire:click="removeMapDistractor({{ $i }}, {{ $di }})">✕</button>
                            </span>
                        @endforeach
                        @if (count($p['distractors'] ?? []) < 3)
                            <button type="button" class="btn btn-xs btn-ghost" x-on:click="beginPick('distractor', {{ $i }})">
                                + {{ __('pick on map') }}
                            </button>
                        @endif
                    </div>
                @endif

                @foreach (($quizErrors[$i] ?? []) as $err)
                    <p class="text-xs text-error">{{ $err }}</p>
                @endforeach
            </div>
        @endforeach

        @if (count($quizDraft) < 12)
            <button type="button" class="btn btn-sm btn-outline w-full" wire:click="addMapQuizQuestion">
                + {{ __('Add map question') }}
            </button>
        @endif
        @if ($quizSaved)
            <p class="text-[10px] text-success text-right">{{ __('Saved') }}</p>
        @endif
    </div>
</div>

@script
<script>
    Alpine.data('mapQuizInspector', ({ year, qid }) => ({
        pickMode: null,      // null | 'target' | 'distractor'
        pickIndex: null,
        pickHint: '',
        _map: null,

        init() {
            // lesson-map.js is Vite-bundled with the composer; renderLessonMap is on window.
            const mount = () => {
                if (!window.renderLessonMap || !this.$refs.minimap) return
                this._map = window.renderLessonMap(this.$refs.minimap, {
                    qid, year, interactive: true,
                    onPolityClick: (f) => this.onPick(f),
                })
            }
            if (window.renderLessonMap) mount()
            else import('/resources/js/lesson-map.js').catch(() => {}).finally(mount)
        },

        beginPick(mode, index) {
            this.pickMode = mode
            this.pickIndex = index
            this.pickHint = mode === 'target' ? 'Click the ANSWER territory…' : 'Click a WRONG territory…'
        },

        onPick(f) {
            if (!this.pickMode) return
            const mode = this.pickMode, index = this.pickIndex
            this.pickMode = null
            this.pickHint = ''
            // Modern name resolves from the same tiles at year 2024 (may be '' — teacher can type it).
            setTimeout(() => {
                const modern = this._map?.queryModernName ? this._map.queryModernName(f.lngLat) : ''
                if (mode === 'target') this.$wire.setMapTarget(index, f.qid, f.name, modern)
                else this.$wire.addMapDistractor(index, f.qid, f.name, modern)
            }, 350)
        },

        destroy() { try { this._map?.destroy() } catch (_) {} },
    }))
</script>
@endscript
```

Check how the composer currently loads `lesson-map.js` (`grep -rn "lesson-map" resources/views resources/js/app.js vite.config.js | head`) — if it's a Vite entry, replace the `import('/resources/js/lesson-map.js')` fallback with the same mechanism the composer's map inspector uses (see `scene-inspector-map.blade.php` for the working pattern and copy it).

- [ ] **Step 4: Build + smoke check**

Run: `npm run build`
Expected: builds without errors.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/lesson/scene-inspector-map-quiz.blade.php resources/views/livewire/wizard/step3-scene-configurator.blade.php resources/js/lesson-map.js
git commit -m "feat(map-quiz): authoring inspector - mini-map territory picker + settings"
```

---

### Task 7: Player payload — serialize map fields

**Files:**
- Modify: `resources/views/lesson/player.blade.php` (lines 41, 68–72)
- Test: `tests/Feature/MapQuizPlayerPayloadTest.php`

- [ ] **Step 1: Write the failing test**

Look at how an existing player test requests the route (`grep -rn "lesson.player\|player" routes/web.php tests/Feature -l | head`). Then:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapQuizPlayerPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_serializes_map_question_fields(): void
    {
        $lesson = Lesson::factory()->create(['status' => 'published']);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'game',
            'game_type' => 'map_quiz', 'status' => 'ready',
            'config' => ['year' => 900, 'naming_mode' => 'hist', 'modern_borders_switch' => true],
        ]);
        $lesson->quizQuestions()->create([
            'scene_id' => $scene->id, 'order' => 0, 'type' => 'map_locate',
            'question' => 'Click the Abbasid Caliphate!',
            'options' => ['Abbasid Caliphate'], 'correct_index' => 0,
            'map_payload' => [
                'answer_mode' => 'click',
                'target' => ['qid' => 'Q12536', 'label_hist' => 'Abbasid Caliphate', 'label_modern' => 'Iraq'],
                'distractors' => [], 'relation' => null,
                'correct_slot' => 0, 'slot_qids' => ['Q12536'],
            ],
            'points' => 10,
        ]);

        $response = $this->get(route('lesson.player', ['lessonCode' => $lesson->lesson_code]));

        $response->assertOk();
        $response->assertSee('map_locate', false);
        $response->assertSee('Q12536', false);
        $response->assertSee('modern_borders_switch', false);
    }
}
```

Adjust the route name/params to the real player route (check `routes/web.php`; `LessonPlayerController` is the controller). The lesson may need `status`/publication fields set for the player to render — copy whatever an existing passing player/leaderboard test does.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MapQuizPlayerPayloadTest`
Expected: FAIL (map_locate not in payload — `->only([...])` strips it)

- [ ] **Step 3: Extend both serializations**

In `resources/views/lesson/player.blade.php`:

Line 41 — add the two fields:

```php
        'quiz_questions'        => $lesson->quizQuestions->whereNull('scene_id')->map->only(['question', 'options', 'correct_index', 'asks_ahead', 'explanation', 'type', 'map_payload'])->values(),
```

Lines 68–70 — same `->only([...])` list inside the scenes map:

```php
                'quiz_questions' => $s->kind === 'game'
                    ? ($lesson->quizQuestions->where('scene_id', $s->id)->values()->whenEmpty(fn () => $lesson->quizQuestions->whereNull('scene_id')->values()))
                        ->map->only(['question', 'options', 'correct_index', 'asks_ahead', 'explanation', 'type', 'map_payload'])->values()
                    : null,
```

(`config` is already serialized per scene at line 55, so `naming_mode`/`modern_borders_switch`/`year`/`qid` ride along for free.)

- [ ] **Step 4: Run test + commit**

Run: `vendor/bin/phpunit --filter MapQuizPlayerPayloadTest`
Expected: PASS

```bash
git add resources/views/lesson/player.blade.php tests/Feature/MapQuizPlayerPayloadTest.php
git commit -m "feat(map-quiz): player payload carries question type + map_payload"
```

---

### Task 8: Player — map-quiz adapter + QuizOverlay hooks + scene routing

The biggest JS task. One quiz engine (QuizOverlay keeps scoring, snapshots, streaks, submit); a thin adapter drives the map.

**Files:**
- Create: `resources/js/scene/map-quiz-adapter.js`
- Modify: `resources/js/scene/QuizOverlay.js` (adapter hooks — read the whole file first)
- Modify: `resources/js/lesson-player.js` (queue filter line ~747, `_playScene` routing line ~833, new `_playMapQuizScene`)

- [ ] **Step 1: Create the adapter**

`resources/js/scene/map-quiz-adapter.js`:

```js
/**
 * map-quiz-adapter.js — drives the lesson map during a map-quiz block.
 *
 * Owns everything map-side: numbered markers, click-to-answer, highlight feedback and the
 * modern-borders toggle. QuizOverlay stays the single quiz engine (scoring, snapshots,
 * streaks, submit) and calls into this adapter per question.
 *
 * NOTE ON COLORS: MapLibre paints canvas pixels — CSS variables don't reach it, so the
 * feedback colors are constants chosen to match the learningportal theme.
 */
import maplibregl from 'maplibre-gl'

const CORRECT_GREEN = '#2f9e44'
const WRONG_RED = '#c0392b'
const MODERN_BORDER = '#3b5bdb'

// Same lifespan filter lesson-map.js uses (not exported there — kept in sync by the
// "mirrors the Time-Map's filter" comment convention).
const polityFilter = (year) => ['all',
  ['==', ['get', 'Type'], 'POLITY'],
  ['!=', ['slice', ['get', 'Name'], 0, 1], '('],
  ['<=', ['to-number', ['get', 'FromYear']], year],
  ['>=', ['to-number', ['get', 'ToYear']], year],
]

export function createMapQuizAdapter (mapHandle, { stageEl, config = {} }) {
  const map = mapHandle.map
  let markers = []
  let clickHandler = null
  let feedbackState = []       // qids with feature-state set (for cleanup)
  let toggleEl = null

  const setState = (qid, state) => {
    if (!qid) return
    map.setFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id: qid }, state)
    feedbackState.push(qid)
  }

  const clearStates = () => {
    feedbackState.forEach((qid) => {
      try { map.removeFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id: qid }) } catch (_) {}
    })
    feedbackState = []
  }

  // Feedback paint: reuse lesson-map's highlight feature-state for the ring, and add our own
  // fill layer keyed on 'quiz' feature-states for green/red washes.
  const ensureFeedbackLayer = () => {
    if (map.getLayer('quiz-feedback-fill') || !map.getSource('cliopatria')) return
    map.addLayer({
      id: 'quiz-feedback-fill', type: 'fill', source: 'cliopatria', 'source-layer': 'boundaries',
      filter: polityFilter(Number(config.year) || 1600),
      paint: {
        'fill-color': ['case',
          ['boolean', ['feature-state', 'quizCorrect'], false], CORRECT_GREEN,
          ['boolean', ['feature-state', 'quizWrong'], false], WRONG_RED,
          'rgba(0,0,0,0)'],
        'fill-opacity': 0.35,
      },
    })
  }

  // Centroid of a polity's largest polygon part (mirrors lesson-map's largest-ring logic so
  // markers don't land on overseas exclaves).
  const centroidOf = (qid) => {
    const feats = map.querySourceFeatures('cliopatria', {
      sourceLayer: 'boundaries',
      filter: ['==', ['get', 'Wikidata'], qid],
    })
    let best = null
    feats.forEach((f) => {
      const polys = f.geometry?.type === 'Polygon' ? [f.geometry.coordinates]
        : f.geometry?.type === 'MultiPolygon' ? f.geometry.coordinates : []
      polys.forEach((poly) => {
        const ring = poly[0]
        if (!ring) return
        let minX = 180, minY = 90, maxX = -180, maxY = -90
        ring.forEach(([x, y]) => {
          if (x < minX) minX = x; if (x > maxX) maxX = x
          if (y < minY) minY = y; if (y > maxY) maxY = y
        })
        const area = (maxX - minX) * (maxY - minY)
        if (!best || area > best.area) best = { area, center: [(minX + maxX) / 2, (minY + maxY) / 2] }
      })
    })
    return best?.center || null
  }

  const clearQuestion = () => {
    markers.forEach((m) => { try { m.remove() } catch (_) {} })
    markers = []
    if (clickHandler) { map.off('click', clickHandler); clickHandler = null }
    clearStates()
  }

  return {
    /**
     * Show one question on the map.
     * @param {object} q       the question row (type, map_payload, options)
     * @param {number[]} mapping  QuizOverlay's display→original option index mapping
     * @param {object} hooks   { chooseDisplayIndex(i), answerByQid({qid, name}) }
     */
    presentQuestion (q, mapping, hooks) {
      clearQuestion()
      ensureFeedbackLayer()
      const payload = q.map_payload || {}
      const isClick = q.type !== 'map_identify' && (payload.answer_mode || 'click') === 'click'

      if (q.type === 'map_identify' || q.type === 'map_actor') {
        // Context highlight: identify highlights the ANSWER territory; actor highlights the
        // protagonist's territory (scene config qid) as reference.
        const ctxQid = q.type === 'map_identify' ? payload.target?.qid : (config.qid || null)
        if (ctxQid) setState(ctxQid, { highlight: true })
      }

      if (isClick) {
        clickHandler = (e) => {
          if (!map.getLayer('boundaries-fill')) return
          const feats = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })
          const f = feats.find((x) => x.properties?.Wikidata)
          if (!f) return   // ocean tap — ignored, no answer consumed
          hooks.answerByQid({ qid: String(f.properties.Wikidata), name: String(f.properties.Name || '') })
        }
        map.on('click', clickHandler)
        map.getCanvas().style.cursor = 'crosshair'
        return
      }

      // Numbered mode: DISPLAY order defines the marker numbers, so shuffle applies.
      const slotQids = payload.slot_qids || []
      mapping.forEach((originalIndex, displayIndex) => {
        const qid = slotQids[originalIndex]
        if (!qid) return
        const center = centroidOf(qid)
        if (!center) return
        const el = document.createElement('button')
        el.type = 'button'
        el.textContent = ['①', '②', '③', '④'][displayIndex] || String(displayIndex + 1)
        el.style.cssText = 'font-size:26px; line-height:1; background:#0f172a; color:#fbbf24; border:2px solid #fbbf24; border-radius:9999px; width:40px; height:40px; cursor:pointer;'
        el.addEventListener('click', (ev) => { ev.stopPropagation(); hooks.chooseDisplayIndex(displayIndex) })
        markers.push(new maplibregl.Marker({ element: el }).setLngLat(center).addTo(map))
      })
    },

    /** Green/red washes after an answer; shows the correct territory on a miss. */
    showFeedback ({ correctQid, chosenQid, wasCorrect }) {
      if (wasCorrect) {
        setState(correctQid, { quizCorrect: true })
      } else {
        if (chosenQid) setState(chosenQid, { quizWrong: true })
        setState(correctQid, { quizCorrect: true })
      }
    },

    /** The student-facing modern-borders switch (only when the teacher allows it). */
    addModernBordersToggle () {
      if (toggleEl || !stageEl) return
      toggleEl = document.createElement('label')
      toggleEl.className = 'absolute top-3 left-3 z-20 flex items-center gap-2 rounded-full bg-base-100/90 px-3 py-1.5 text-xs shadow cursor-pointer'
      toggleEl.innerHTML = '<input type="checkbox" class="toggle toggle-xs" /><span>Modern borders</span>'
      toggleEl.querySelector('input').addEventListener('change', (e) => this.setModernBorders(e.target.checked))
      stageEl.appendChild(toggleEl)
    },

    setModernBorders (on) {
      if (on && !map.getLayer('modern-borders')) {
        map.addLayer({
          id: 'modern-borders', type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(2024),
          paint: { 'line-color': MODERN_BORDER, 'line-width': 1, 'line-dasharray': [2, 2], 'line-opacity': 0.7 },
        })
      } else if (map.getLayer('modern-borders')) {
        map.setLayoutProperty('modern-borders', 'visibility', on ? 'visible' : 'none')
      }
    },

    clearQuestion,

    destroy () {
      clearQuestion()
      try { toggleEl?.remove() } catch (_) {}
      toggleEl = null
    },
  }
}
```

- [ ] **Step 2: Add adapter hooks to QuizOverlay**

Read ALL of `resources/js/scene/QuizOverlay.js` first — the exact insertion points depend on its internals. The contract to implement:

1. `show({ ..., mapAdapter = null })` — store `this._mapAdapter = mapAdapter`.
2. In the per-question render method (the one that builds `optionsHtml` around line 195): after rendering, when `this._mapAdapter && q.type?.startsWith('map_')`, call:

```js
      this._mapAdapter.presentQuestion(q, mapping, {
        chooseDisplayIndex: (i) => this._choose(i),          // use the overlay's real choose method name
        answerByQid: ({ qid, name }) => this._answerMapClick(qid, name),
      })
```

3. For CLICK-mode map questions (`q.type !== 'map_identify' && q.map_payload?.answer_mode === 'click'`), replace the options list in the card with a hint block (keep the question text, timer, read-gate intact):

```js
      const clickHint = `<div style="padding:14px; text-align:center; opacity:.8;">👆 Tap the territory on the map</div>`
```

Options answering for click mode goes through the new method:

```js
  // Click-mode map answer: correctness = clicked polity's QID vs the target QID. The
  // snapshot's chosen_text uses the clicked polity's tile name (historical register) —
  // for wrong answers we don't have teacher-curated labels for arbitrary territories.
  _answerMapClick (qid, name) {
    const q = this._questions[this._index]
    if (!q?.type || q.type === 'mc') return
    if (this._answered.has(this._index)) return
    // Respect the read-gate exactly like _choose does (copy the same gate check).
    const targetQid = q.map_payload?.target?.qid
    const correct = qid === targetQid
    // Record the answer + snapshot: mirror what _choose does (score, streak, answered map,
    // snapshot push) but with chosen_text = clicked territory name and was_correct = correct.
    // Then trigger feedback:
    this._mapAdapter?.showFeedback({ correctQid: targetQid, chosenQid: qid, wasCorrect: correct })
    // ...then the same "advance after delay" flow _choose uses.
  }
```

The body of `_answerMapClick` must reuse the existing internals — read `_choose`/the answer handler (around line 315: `const correct = mapping[displayIndex] === Number(q.correct_index)`) and factor its snapshot+score+advance tail into a private helper both paths call, e.g. `_recordAnswer({ correct, chosenText, correctText })`. Keep the refactor minimal: extract only the shared tail, don't restructure the file.

4. For numbered/identify map questions, after the existing answer handler runs, also call:

```js
      if (this._mapAdapter && q.type?.startsWith('map_')) {
        const slotQids = q.map_payload?.slot_qids || []
        this._mapAdapter.showFeedback({
          correctQid: slotQids[Number(q.correct_index)] || q.map_payload?.target?.qid,
          chosenQid: slotQids[mapping[displayIndex]] || null,
          wasCorrect: correct,
        })
      }
```

5. Layout: when `this._mapAdapter` is set, dock the card to the right third on desktop instead of centered. The overlay builds its card with inline styles — add:

```js
      const dock = this._mapAdapter
        ? 'position:absolute; right:16px; top:16px; bottom:16px; width:min(420px, 92vw); margin:0;'
        : ''   // default centered layout unchanged
```

and apply `dock` to the card container style. On small screens (`window.innerWidth < 768`) use bottom-docking instead: `position:absolute; left:8px; right:8px; bottom:8px; max-height:45%;` (map visible above — spec §4.1 mobile layout).

6. On question advance and on completion call `this._mapAdapter?.clearQuestion()`.

- [ ] **Step 3: Route map-quiz scenes in the player**

In `resources/js/lesson-player.js`:

(a) Queue filter line ~747 — map-quiz scenes have no audio but must stay queued:

```js
          .filter(s => s.audio_url || s.kind === 'map' || s.game_type === 'map_quiz')
```

(b) In `_playScene` right after the map early-return (line ~833):

```js
      if (scene.kind === 'game' && scene.game_type === 'map_quiz') { this._playMapQuizScene(index, scene); return }
```

(c) New method next to `_playMapScene` (model it on `_playMapScene` + `_beginQuizFlow`):

```js
    // ── Map-quiz block: fullscreen map + QuizOverlay docked right ─────────
    async _playMapQuizScene (index, scene) {
      if (this._audio && !this._audio.paused) { this._audio.pause(); this.audioPlaying = false }

      const cfg = scene.config || {}
      const questions = scene.quiz_questions || []
      if (!questions.length) { this._advanceScene(index); return }

      // Load the map module on demand (same pattern as _playMapScene).
      if (!window.renderLessonMap) {
        try { await import('./lesson-map.js') } catch (e) {
          console.warn('lesson-player: map quiz failed to load map — skipping block', e)
          this._advanceScene(index)
          return
        }
      }

      const stage = document.getElementById('lesson-map-stage')
      if (!stage || !window.renderLessonMap) { this._advanceScene(index); return }
      stage.style.display = 'block'
      stage.innerHTML = ''
      const inner = document.createElement('div')
      inner.style.width = '100%'
      inner.style.height = '100%'
      stage.appendChild(inner)
      _mapInstance = window.renderLessonMap(inner, {
        qid: cfg.qid || null,
        year: cfg.year ?? 1600,
        interactive: true,
      })

      const { createMapQuizAdapter } = await import('./scene/map-quiz-adapter.js')
      const adapter = createMapQuizAdapter(_mapInstance, { stageEl: stage, config: cfg })
      if (cfg.modern_borders_switch !== false) adapter.addModernBordersToggle()

      const host = document.getElementById('lesson-game-overlay')
      const { QuizOverlay } = await import('./scene/QuizOverlay.js')
      this._quizOverlay = this._quizOverlay || new QuizOverlay(host)
      this._quizOverlay.show({
        questions,
        submitUrl: lesson.quiz_score_url || null,
        leaderboardUrl: lesson.leaderboard_url || null,
        hasClassroom: lesson.has_classroom,
        shuffleMode: (cfg.quiz_shuffle || 'per_player'),
        mapAdapter: adapter,
        onComplete: () => {
          try { adapter.destroy() } catch (_) {}
          this._advanceFromMap(index)   // tears down _mapInstance + hides the stage, then advances
        },
      })
    },
```

Match the exact `show({...})` argument names to what `_beginQuizFlow` (line ~1110) passes — copy them, then add `mapAdapter`. Check whether `lesson` is in scope the same way it is inside `_beginQuizFlow` (it is referenced there as `lesson.quiz_questions`).

- [ ] **Step 4: Build + commit**

Run: `npm run build`
Expected: no errors.

```bash
git add resources/js/scene/map-quiz-adapter.js resources/js/scene/QuizOverlay.js resources/js/lesson-player.js
git commit -m "feat(map-quiz): player - map adapter, QuizOverlay map hooks, scene routing"
```

---

### Task 9: Submit-path regression test

The endpoint needs no code change — prove it.

**Files:**
- Test: `tests/Feature/MapQuizSubmitTest.php`

- [ ] **Step 1: Write the test** (this one should PASS immediately — it's a contract lock, not TDD)

Copy the request construction from `tests/Feature/QuizLeaderboardTest.php` (auth/join specifics live there), then:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapQuizSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_question_snapshots_are_accepted_and_stored(): void
    {
        $lesson = Lesson::factory()->create(['status' => 'published']);

        $response = $this->postJson(route('lesson.quiz-score', ['lessonCode' => $lesson->lesson_code]), [
            'nickname' => 'Alfonso T',
            'score' => 10,
            'correct' => 1,
            'total' => 2,
            'answers' => [
                [
                    'question_order' => 1,
                    'question_text' => 'Which country is Cuba?',
                    'chosen_text' => 'Cuba',
                    'correct_text' => 'Cuba',
                    'was_correct' => true,
                    'response_ms' => 4200,
                ],
                [
                    'question_order' => 2,
                    'question_text' => 'Who was the enemy on this map?',
                    'chosen_text' => 'Jamaica',          // clicked wrong territory (tile name)
                    'correct_text' => 'Saint-Domingue (now: Haiti)',
                    'was_correct' => false,
                    'response_ms' => 6100,
                ],
            ],
        ]);

        $response->assertSuccessful();
        $this->assertSame(2, \App\Models\QuizAnswer::count());
        $this->assertSame('Jamaica', \App\Models\QuizAnswer::orderBy('question_order')->get()[1]->chosen_text);
    }
}
```

Adjust required top-level fields (`nickname`/`score`/…) to the controller's actual validation rules — read `QuizLeaderboardController.php` lines 31–60 and mirror an existing passing test's payload.

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter MapQuizSubmitTest`
Expected: PASS with zero production-code changes. If it fails, the fix belongs in the TEST (payload shape), not the controller — the whole design guarantees the controller doesn't change.

- [ ] **Step 3: Full suite + commit**

Run: `composer test`
Expected: PASS across the board.

```bash
git add tests/Feature/MapQuizSubmitTest.php
git commit -m "test(map-quiz): lock submit contract - map snapshots flow through unchanged"
```

---

### Task 10: End-to-end UI verification + wrap-up

- [ ] **Step 1: Author a map quiz in the composer**

Use the test-ui / verify flow (preview_start on the Laravel dev server from `.claude/launch.json`, or `composer dev`):
1. Open a lesson in the composer → add scene → "Map quiz" tile.
2. Inspector shows: naming-mode select, modern-borders toggle, mini-map.
3. Add question → "Pick answer on map" → click a territory → button turns green with the tile name; hist/modern label inputs appear pre-filled.
4. Switch to numbered mode → pick 3 distractors → error clears, question autosaves (check `quiz_questions` row: `type`, `options` ×4, `map_payload.slot_qids` ×4).
5. ✨ AI-draft the question text.
6. Change naming mode to `hist_modern` → confirm options in DB regenerate with "(now: …)".

- [ ] **Step 2: Play it**

1. Open the lesson player with the lesson code.
2. Map-quiz block mounts: fullscreen map, question card docked right (desktop ≥ 1024px), modern-borders toggle top-left.
3. Click mode: tap wrong territory → red wash + correct territory green; tap ocean → nothing consumed.
4. Numbered mode: ①–④ markers on territories; tapping a marker or a panel option answers.
5. Resize to 375px width: card docks bottom, map stays visible on top.
6. Finish → score screen → submit with nickname → `quiz_scores` + `quiz_answers` rows exist.
7. Teacher results hub: the map questions appear in the lesson report (question drilldown reads snapshots).

- [ ] **Step 3: Fix what's broken, re-verify, then finish**

Iterate fix → build → recheck until all of Step 1–2 pass. Then:

```bash
composer test && npm run build
git add -A -- resources/ app/ tests/ lang/ database/ && git status  # verify ONLY map-quiz files staged
git commit -m "feat(map-quiz): polish from end-to-end verification"
```

Use the finishing-a-development-branch skill to decide merge/PR.

---

## Self-review checklist (done at authoring time)

- **Spec coverage:** archetypes ①②③ (Tasks 4/5/8), click+numbered modes (2/4/8), naming modes + translation (2), modern-borders teacher setting + student toggle (3/6/8), pick-on-map authoring + AI-text-only (5/6), results-pipeline-unchanged (9), mobile layout (8.2.5, 10), error handling: ocean taps ignored (8), map-load failure skips block (8 Step 3c), lifespan re-validation (2/4).
- **Out of scope honored:** no paper/print work, no open-ended answers, no mixing into text quizzes.
- **Type consistency:** `map_payload` keys (`answer_mode`, `target`, `distractors`, `relation`, `correct_slot`, `slot_qids`) identical across Tasks 1/2/4/7/8; naming modes `hist|hist_modern|modern` everywhere; `game_type` string `map_quiz` everywhere.
- **Known risks flagged in-task:** Livewire test mount signature (Task 3/4 note), distractor QIDs' lifespans (Task 4 note), QuizOverlay internals require read-before-edit (Task 8 Step 2), lesson-map load mechanism in composer (Task 6 Step 3 note).
