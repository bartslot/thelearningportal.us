# Teacher Results & Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teachers can see per-lesson quiz results (leaderboard, difficult questions, needs-help, per-student drill-down), import paper answer sheets via AI photo extraction, export CSV, and re-quiz difficult questions — per spec `docs/superpowers/specs/2026-07-08-teacher-results-analytics-design.md`.

**Architecture:** Per-question answers are snapshotted into a new `quiz_answers` table at submit time (web) or import time (paper). An account-less `classroom_members` roster links runs to persistent identities when the lesson is assigned to a classroom. A pure-PHP `LessonResults` service computes all report math; two Livewire pages (`LessonReport`, `ResultsHub`) render it. Paper flow = print-CSS answer sheet + vision-model extraction into an editable review grid.

**Tech Stack:** Laravel 12, Livewire 3, DaisyUI, existing `OpenAiLlmService` (vision), PHPUnit. No new composer/npm dependencies.

**Plan-level notes:**
- One deliberate deviation from the spec: paper extraction runs **synchronously** inside the Livewire upload action (a class's photos take seconds; a queued job would force a polling state machine for v1). The service is named `PaperSheetExtractor` so a queued wrapper can be added later without renaming.
- Repo conventions: string columns instead of `enum()` (Postgres CHECK gotcha), `declare(strict_types=1)`, Form-Request-style validation in Livewire via `$this->validate()`, tests mirror `tests/Feature/QuizLeaderboardTest.php` style. Full suite has 7 errors + 11 failures that PRE-DATE this work — only compare your task's filtered runs.
- Run tests with `vendor/bin/phpunit --filter <Name>`. Build JS with `npm run build` (only tasks touching `resources/js`).

---

### Task 1: `quiz_answers` snapshots + `classroom_members` + `quiz_scores` extensions

**Files:**
- Create: `database/migrations/2026_07_08_000001_create_quiz_answers_table.php`
- Create: `database/migrations/2026_07_08_000002_create_classroom_members_table.php`
- Create: `database/migrations/2026_07_08_000003_extend_quiz_scores_for_results.php`
- Create: `app/Models/QuizAnswer.php`
- Create: `app/Models/ClassroomMember.php`
- Modify: `app/Models/QuizScore.php`
- Test: `tests/Unit/QuizAnswerModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LessonStatus;
use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizAnswerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_score_has_answers_and_optional_classroom_member(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history',
            'grade_level' => '8', 'status' => LessonStatus::Published,
        ]);
        $classroom = Classroom::create(['teacher_id' => $teacher->id, 'name' => '7B']);
        $member = ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'Emma V.']);

        $score = QuizScore::create([
            'lesson_id' => $lesson->id, 'nickname' => 'Emma V.', 'score' => 20,
            'correct' => 2, 'total' => 3,
            'classroom_member_id' => $member->id, 'source' => 'paper',
        ]);
        QuizAnswer::create([
            'quiz_score_id' => $score->id, 'question_order' => 1,
            'question_text' => 'Why did X happen?', 'chosen_text' => 'Because Y',
            'correct_text' => 'Because Y', 'was_correct' => true,
            'response_ms' => 3200, 'asks_ahead' => false,
        ]);

        $this->assertSame('paper', $score->fresh()->source);
        $this->assertCount(1, $score->answers);
        $this->assertTrue($score->answers->first()->was_correct);
        $this->assertSame('Emma V.', $score->member->display_name);
        $this->assertCount(1, $member->scores);
    }

    public function test_member_display_name_is_unique_per_classroom_normalized(): void
    {
        $teacher = User::factory()->create();
        $classroom = Classroom::create(['teacher_id' => $teacher->id, 'name' => '7B']);
        ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'Emma V.']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'emma v.']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter QuizAnswerModelTest`
Expected: ERROR "relation quiz_answers does not exist" (or class not found).

- [ ] **Step 3: Write the three migrations**

`database/migrations/2026_07_08_000001_create_quiz_answers_table.php`:

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
        Schema::create('quiz_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_score_id')->constrained()->cascadeOnDelete();
            // Best-effort link only: questions are deleted/recreated on regeneration,
            // so every reporting read uses the SNAPSHOT columns below, never a join.
            $table->foreignId('quiz_question_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('question_order');
            $table->text('question_text');
            $table->text('chosen_text');
            $table->text('correct_text');
            $table->boolean('was_correct');
            $table->unsignedInteger('response_ms')->nullable();   // null for paper imports
            $table->boolean('asks_ahead')->default(false);
            $table->timestamps();

            $table->index('quiz_score_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};
```

`database/migrations/2026_07_08_000002_create_classroom_members_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_members', function (Blueprint $table): void {
            // Account-less roster entry ("Emma V." — first name + last initial).
            // Deliberately NOT a users row: pilot schools do no account provisioning.
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 40);
            $table->timestamps();
        });
        // Uniqueness on the NORMALIZED name so "Emma V." and "emma v." collide.
        DB::statement('CREATE UNIQUE INDEX classroom_members_unique_name
            ON classroom_members (classroom_id, lower(display_name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_members');
    }
};
```

`database/migrations/2026_07_08_000003_extend_quiz_scores_for_results.php`:

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
        Schema::table('quiz_scores', function (Blueprint $table): void {
            $table->foreignId('classroom_member_id')->nullable()->after('nickname')
                ->constrained('classroom_members')->nullOnDelete();
            $table->string('source', 10)->default('web')->after('integrity');   // web | paper
        });
    }

    public function down(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('classroom_member_id');
            $table->dropColumn('source');
        });
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/QuizAnswer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    protected $fillable = [
        'quiz_score_id', 'quiz_question_id', 'question_order',
        'question_text', 'chosen_text', 'correct_text',
        'was_correct', 'response_ms', 'asks_ahead',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'integer',
            'was_correct' => 'boolean',
            'response_ms' => 'integer',
            'asks_ahead' => 'boolean',
        ];
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(QuizScore::class, 'quiz_score_id');
    }
}
```

`app/Models/ClassroomMember.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassroomMember extends Model
{
    protected $fillable = ['classroom_id', 'display_name'];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(QuizScore::class);
    }
}
```

Modify `app/Models/QuizScore.php` — replace the whole class body with:

```php
class QuizScore extends Model
{
    protected $fillable = [
        'lesson_id', 'nickname', 'classroom_member_id', 'score',
        'correct', 'total', 'integrity', 'source',
    ];

    protected function casts(): array
    {
        return ['score' => 'integer', 'correct' => 'integer', 'total' => 'integer', 'integrity' => 'array'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ClassroomMember::class, 'classroom_member_id');
    }

    public function answers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuizAnswer::class)->orderBy('question_order');
    }
}
```

(Add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports if you prefer the short form.)

- [ ] **Step 5: Migrate and run the test**

Run: `php artisan migrate && vendor/bin/phpunit --filter QuizAnswerModelTest`
Expected: 2 tests PASS. (Check `Classroom` model has `teacher_id`,`name` fillable — it does.)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_08_0000* app/Models/QuizAnswer.php app/Models/ClassroomMember.php app/Models/QuizScore.php tests/Unit/QuizAnswerModelTest.php
git commit -m "feat(results): quiz answer snapshots + account-less classroom roster"
```

---

### Task 2: Submit endpoint accepts `answers[]` + class-code attach

**Files:**
- Modify: `app/Http/Controllers/QuizLeaderboardController.php` (store method)
- Test: `tests/Feature/QuizLeaderboardTest.php` (append tests)

- [ ] **Step 1: Write the failing tests** (append inside `QuizLeaderboardTest`)

```php
    public function test_submit_stores_answer_snapshots(): void
    {
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Sofie', 'score' => 10, 'correct' => 1, 'total' => 2,
            'answers' => [
                ['question_order' => 1, 'question_text' => 'Why X?', 'chosen_text' => 'Y',
                 'correct_text' => 'Y', 'was_correct' => true, 'response_ms' => 4100, 'asks_ahead' => false],
                ['question_order' => 2, 'question_text' => 'Why Z?', 'chosen_text' => 'A',
                 'correct_text' => 'B', 'was_correct' => false, 'response_ms' => 900, 'asks_ahead' => true],
            ],
        ])->assertCreated();

        $score = \App\Models\QuizScore::sole();
        $this->assertCount(2, $score->answers);
        $this->assertFalse($score->answers[1]->was_correct);
        $this->assertSame('web', $score->source);
    }

    public function test_valid_class_code_attaches_a_persistent_member(): void
    {
        $classroom = \App\Models\Classroom::create(['teacher_id' => $this->lesson->teacher_id, 'name' => '7B']);
        $this->lesson->classrooms()->attach($classroom->id, ['assigned_at' => now()]);

        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Emma V.', 'score' => 10, 'correct' => 1, 'total' => 1,
            'class_code' => $classroom->join_code, 'member_name' => 'Emma V.',
        ])->assertCreated();

        $member = \App\Models\ClassroomMember::sole();
        $this->assertSame('Emma V.', $member->display_name);
        $this->assertSame($member->id, \App\Models\QuizScore::sole()->classroom_member_id);

        // Same name again → same member, no duplicate.
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Emma V.', 'score' => 15, 'correct' => 1, 'total' => 1,
            'class_code' => strtolower($classroom->join_code), 'member_name' => 'emma v.',
        ])->assertCreated();
        $this->assertSame(1, \App\Models\ClassroomMember::count());
    }

    public function test_invalid_class_code_is_rejected_with_422(): void
    {
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Emma V.', 'score' => 10, 'correct' => 1, 'total' => 1,
            'class_code' => 'WRONG1', 'member_name' => 'Emma V.',
        ])->assertUnprocessable()->assertJsonValidationErrors('class_code');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter QuizLeaderboardTest`
Expected: the 3 new tests FAIL (answers not stored / member not created / no validation error).

- [ ] **Step 3: Implement in `QuizLeaderboardController::store`**

Add to the `validate()` array:

```php
            'answers' => ['sometimes', 'array', 'max:50'],
            'answers.*.question_order' => ['required_with:answers', 'integer', 'min:1', 'max:50'],
            'answers.*.question_text' => ['required_with:answers', 'string', 'max:500'],
            'answers.*.chosen_text' => ['required_with:answers', 'string', 'max:200'],
            'answers.*.correct_text' => ['required_with:answers', 'string', 'max:200'],
            'answers.*.was_correct' => ['required_with:answers', 'boolean'],
            'answers.*.response_ms' => ['nullable', 'integer', 'min:0'],
            'answers.*.asks_ahead' => ['sometimes', 'boolean'],
            'class_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'member_name' => ['required_with:class_code', 'nullable', 'string', 'min:2', 'max:40'],
```

After validation, before `QuizScore::create`, resolve the member:

```php
        // Hybrid identity: a class code (from a lesson assigned to that classroom)
        // attaches the run to a persistent, account-less roster member.
        $memberId = null;
        if (filled($data['class_code'] ?? null)) {
            $classroom = $lesson->classrooms()
                ->where('join_code', strtoupper(trim($data['class_code'])))
                ->first();
            if (! $classroom) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'class_code' => 'Unknown class code for this lesson.',
                ]);
            }
            $name = \App\Services\Support\NameMatcher::canonical((string) $data['member_name']);
            $member = \App\Models\ClassroomMember::whereRaw(
                'classroom_id = ? AND lower(display_name) = ?',
                [$classroom->id, mb_strtolower($name)],
            )->first() ?? \App\Models\ClassroomMember::create([
                'classroom_id' => $classroom->id, 'display_name' => $name,
            ]);
            $memberId = $member->id;
        }
```

Add `'classroom_member_id' => $memberId,` to the `QuizScore::create` array, then after it:

```php
        foreach (array_values($data['answers'] ?? []) as $answer) {
            \App\Models\QuizAnswer::create([
                'quiz_score_id' => $entry->id,
                'question_order' => (int) $answer['question_order'],
                'question_text' => strip_tags((string) $answer['question_text']),
                'chosen_text' => strip_tags((string) $answer['chosen_text']),
                'correct_text' => strip_tags((string) $answer['correct_text']),
                'was_correct' => (bool) $answer['was_correct'],
                'response_ms' => isset($answer['response_ms']) ? (int) $answer['response_ms'] : null,
                'asks_ahead' => (bool) ($answer['asks_ahead'] ?? false),
            ]);
        }
```

`NameMatcher` doesn't exist yet — create it NOW with just `canonical()` (Task 9 adds `match()`):

`app/Services/Support/NameMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Roster name handling. Convention: first name + last-name initial ("Emma V.") —
 * disambiguates duplicate first names while staying AVG/GDPR-friendly.
 */
final class NameMatcher
{
    /** "emma visser" / "Emma  V" / "EMMA V." → "Emma V." ; single names pass through. */
    public static function canonical(string $raw): string
    {
        $parts = preg_split('/\s+/', trim(strip_tags($raw))) ?: [];
        $parts = array_values(array_filter($parts));
        if ($parts === []) {
            return '';
        }
        $first = mb_convert_case(mb_strtolower($parts[0]), MB_CASE_TITLE);
        if (count($parts) === 1) {
            return $first;
        }
        $initial = mb_strtoupper(mb_substr(end($parts), 0, 1));

        return "{$first} {$initial}.";
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter QuizLeaderboardTest`
Expected: ALL tests pass (old + 3 new). Note the `classrooms()` relation on Lesson already exists with the `classroom_lessons` pivot.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/QuizLeaderboardController.php app/Services/Support/NameMatcher.php tests/Feature/QuizLeaderboardTest.php
git commit -m "feat(results): per-question answer snapshots + class-code member attach on submit"
```

---

### Task 3: Player submits answers + class join fields (JS)

**Files:**
- Modify: `resources/js/scene/QuizOverlay.js`
- Modify: `resources/views/lesson/player.blade.php` (lessonData: `has_classroom` flag)

No JS test harness for this module — verified by Task 2's endpoint tests + manual run.

- [ ] **Step 1: Track answer snapshots in `_answer()`**

In `QuizOverlay._answer`, the `this._responses.push({...})` call already records `ms` and `displayIndex`. Extend it to a full snapshot — replace that push with:

```js
    this._responses.push({
      ms: openedAt !== undefined ? Math.round(performance.now() - openedAt) : -1,
      displayIndex,
      snapshot: {
        question_order: this._index + 1,
        question_text: String(q.question || ''),
        chosen_text: String((q.options || [])[mapping[displayIndex]] ?? ''),
        correct_text: String((q.options || [])[Number(q.correct_index)] ?? ''),
        was_correct: correct,
        response_ms: openedAt !== undefined ? Math.round(performance.now() - openedAt) : null,
        asks_ahead: Boolean(q.asks_ahead),
      },
    })
```

- [ ] **Step 2: Send answers + class fields in `submit()`**

In `_renderScoreScreen`'s `submit` closure, replace the `body:` line with:

```js
          body: JSON.stringify({
            nickname, score: this._score, correct, total,
            integrity: this._integritySummary(),
            answers: this._responses.map(r => r.snapshot).filter(Boolean),
            class_code: this._classCode || null,
            member_name: this._classCode ? nickname : null,
          }),
```

- [ ] **Step 3: Class-code field on the score screen**

In `show()`, accept and store the flag: change the signature line to

```js
  show({ questions, onComplete = null, submitUrl = null, leaderboardUrl = null, hasClassroom = false }) {
```

and after `this._leaderboardUrl = leaderboardUrl` add:

```js
    this._hasClassroom = hasClassroom
    this._classCode = (() => { try { return localStorage.getItem('lp_class_code') || '' } catch { return '' } })()
```

In `_renderScoreScreen`, inside the `joinHtml` template right BEFORE the nickname input row, add (only when the lesson has a classroom):

```js
        ${this._hasClassroom ? `
        <div style="display:flex; gap:8px; justify-content:center; margin-bottom:8px;">
          <input data-class-code type="text" maxlength="8" placeholder="Class code…" value="${this._escape(this._classCode)}"
                 style="width:130px; padding:10px 14px; border-radius:12px; border:1.5px solid rgba(255,255,255,0.2);
                        background:rgba(255,255,255,0.06); color:white; font-size:15px; outline:none; text-transform:uppercase;" />
        </div>` : ''}
```

and at the top of the `submit` closure, before the nickname check:

```js
      const classCodeEl = this.host.querySelector('[data-class-code]')
      this._classCode = (classCodeEl?.value || '').trim().toUpperCase()
      try { if (this._classCode) localStorage.setItem('lp_class_code', this._classCode) } catch { /* private mode */ }
```

In the submit `catch`, surface the 422 nicely — replace `if (errorEl) errorEl.textContent = 'Could not submit — try again.'` with:

```js
        if (errorEl) errorEl.textContent = err?.message === 'HTTP 422'
          ? 'Check the class code — ask your teacher.'
          : 'Could not submit — try again.'
```

- [ ] **Step 4: Pass the flag from the player**

`resources/views/lesson/player.blade.php` — add to `$lessonData`:

```php
        'has_classroom'         => $lesson->classrooms()->exists(),
```

`resources/js/lesson-player.js` — in `_beginQuizFlow`, add to the `show({...})` call:

```js
        hasClassroom: Boolean(lesson.has_classroom),
```

- [ ] **Step 5: Build + verify endpoint contract manually**

Run: `npm run build`
Expected: `✓ built`. Manual check (optional now, required before ship): play a quiz on a classroom-assigned lesson, submit with the class code, confirm a `classroom_members` row appears.

- [ ] **Step 6: Commit**

```bash
git add resources/js/scene/QuizOverlay.js resources/js/lesson-player.js resources/views/lesson/player.blade.php
git add -f public/build/manifest.json public/build/assets 2>/dev/null || git add -f public/build/manifest.json
git commit -m "feat(results): player submits answer snapshots + class-code join"
```

---

### Task 4: `LessonResults` service — all report math

**Files:**
- Create: `app/Services/LessonResults.php`
- Test: `tests/Unit/LessonResultsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use App\Models\User;
use App\Services\LessonResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonResultsTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history',
            'grade_level' => '8', 'status' => LessonStatus::Published,
        ]);

        // Emma: 2/2 correct. Daan: 0/2 (one asks-ahead wrong — must NOT count against him).
        $emma = QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Emma V.', 'score' => 25, 'correct' => 2, 'total' => 2]);
        QuizAnswer::create(['quiz_score_id' => $emma->id, 'question_order' => 1, 'question_text' => 'Q1?', 'chosen_text' => 'A', 'correct_text' => 'A', 'was_correct' => true, 'asks_ahead' => false]);
        QuizAnswer::create(['quiz_score_id' => $emma->id, 'question_order' => 2, 'question_text' => 'Q2?', 'chosen_text' => 'B', 'correct_text' => 'B', 'was_correct' => true, 'asks_ahead' => false]);

        $daan = QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Daan B.', 'score' => 0, 'correct' => 0, 'total' => 2]);
        QuizAnswer::create(['quiz_score_id' => $daan->id, 'question_order' => 1, 'question_text' => 'Q1?', 'chosen_text' => 'C', 'correct_text' => 'A', 'was_correct' => false, 'asks_ahead' => false]);
        QuizAnswer::create(['quiz_score_id' => $daan->id, 'question_order' => 2, 'question_text' => 'Qahead?', 'chosen_text' => 'C', 'correct_text' => 'B', 'was_correct' => false, 'asks_ahead' => true]);
    }

    public function test_overview_stats_and_needs_help_exclude_asks_ahead(): void
    {
        $results = new LessonResults($this->lesson);
        $overview = $results->overview();

        $this->assertSame(2, $overview['players']);
        // Non-asks-ahead answers: Emma 2/2, Daan 0/1 → 2 correct of 3 → 67%.
        $this->assertSame(67, $overview['avg_correct_pct']);
        // Daan: 0/1 non-ahead = 0% < 50% → needs help. Emma does not.
        $this->assertSame(['Daan B.'], array_column($overview['needs_help'], 'name'));
    }

    public function test_difficult_questions_ranked_and_asks_ahead_marked(): void
    {
        $results = new LessonResults($this->lesson);
        $difficult = $results->difficultQuestions();

        // Q1: 1/2 = 50% → not difficult (< 50 threshold is strict). Qahead: 0/1 = 0% but asks_ahead.
        $this->assertCount(1, $difficult);
        $this->assertSame('Qahead?', $difficult[0]['question_text']);
        $this->assertTrue($difficult[0]['asks_ahead']);
        $this->assertSame(0, $difficult[0]['correct_pct']);
    }

    public function test_players_and_drilldown(): void
    {
        $results = new LessonResults($this->lesson);
        $players = $results->players();

        $this->assertCount(2, $players);
        $daan = collect($players)->firstWhere('name', 'Daan B.');
        $this->assertTrue($daan['needs_help']);
        $this->assertCount(2, $results->drilldown($daan['score_id']));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter LessonResultsTest`
Expected: ERROR "Class LessonResults not found".

- [ ] **Step 3: Implement the service**

`app/Services/LessonResults.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * All report math for the teacher results pages. Pure reads over quiz_scores +
 * quiz_answers SNAPSHOTS (never joins live quiz_questions — they get regenerated).
 * Needs-help rule (Kahoot-inspired): correct-rate < 50% on non-asks-ahead answers.
 */
final class LessonResults
{
    public const NEEDS_HELP_BELOW_PCT = 50;

    public const DIFFICULT_BELOW_PCT = 50;

    public function __construct(
        private readonly Lesson $lesson,
        private readonly ?int $classroomId = null,
        private readonly ?CarbonInterface $from = null,
        private readonly ?CarbonInterface $to = null,
    ) {}

    /** @return Collection<int, QuizScore> */
    private function scores(): Collection
    {
        return QuizScore::with(['answers', 'member'])
            ->where('lesson_id', $this->lesson->id)
            ->when($this->classroomId, fn ($q) => $q->whereHas(
                'member', fn ($m) => $m->where('classroom_id', $this->classroomId),
            ))
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', $this->to))
            ->orderByDesc('score')->orderBy('id')
            ->get();
    }

    /** Correct-rate (0-100, rounded) over non-asks-ahead answers; null when no gradable answers. */
    private function gradableRate(QuizScore $score): ?int
    {
        $gradable = $score->answers->where('asks_ahead', false);
        if ($gradable->isEmpty()) {
            // Legacy rows without answer snapshots: fall back to the aggregate.
            return $score->total > 0 ? (int) round($score->correct / $score->total * 100) : null;
        }

        return (int) round($gradable->where('was_correct', true)->count() / $gradable->count() * 100);
    }

    /** @return array{players: int, avg_correct_pct: int, needs_help: list<array{name: string, score_id: int, pct: int}>, leaderboard: list<array<string, mixed>>} */
    public function overview(): array
    {
        $scores = $this->scores();

        $gradable = $scores->flatMap(fn (QuizScore $s) => $s->answers->where('asks_ahead', false));
        $avg = $gradable->isNotEmpty()
            ? (int) round($gradable->where('was_correct', true)->count() / $gradable->count() * 100)
            : 0;

        $needsHelp = $scores
            ->map(fn (QuizScore $s) => ['name' => $this->nameFor($s), 'score_id' => $s->id, 'pct' => $this->gradableRate($s)])
            ->filter(fn (array $row) => $row['pct'] !== null && $row['pct'] < self::NEEDS_HELP_BELOW_PCT)
            ->values()->all();

        $leaderboard = $scores->map(fn (QuizScore $s) => [
            'score_id' => $s->id,
            'name' => $this->nameFor($s),
            'score' => $s->score,
            'correct' => $s->correct,
            'total' => $s->total,
            'source' => $s->source,
            'integrity' => $s->integrity,
            'pct' => $this->gradableRate($s),
            'needs_help' => ($this->gradableRate($s) ?? 100) < self::NEEDS_HELP_BELOW_PCT,
            'played_at' => $s->created_at,
        ])->values()->all();

        return [
            'players' => $scores->count(),
            'avg_correct_pct' => $avg,
            'needs_help' => $needsHelp,
            'leaderboard' => $leaderboard,
        ];
    }

    /** @return list<array{question_text: string, correct_pct: int, answered: int, asks_ahead: bool, missed_by: list<string>}> */
    public function difficultQuestions(): array
    {
        return collect($this->questionBreakdown())
            ->filter(fn (array $q) => $q['correct_pct'] < self::DIFFICULT_BELOW_PCT)
            ->sortBy('correct_pct')->values()->all();
    }

    /** Every question (snapshot-grouped), ranked worst-first, with answer distribution. */
    public function questionBreakdown(): array
    {
        $answers = QuizAnswer::whereIn(
            'quiz_score_id', $this->scores()->pluck('id'),
        )->get()->groupBy('question_text');

        $scoresById = $this->scores()->keyBy('id');

        return $answers->map(function (Collection $group, string $questionText) use ($scoresById) {
            $distribution = $group->groupBy('chosen_text')
                ->map(fn (Collection $picks) => $picks->count())
                ->sortDesc()->all();

            return [
                'question_text' => $questionText,
                'answered' => $group->count(),
                'correct_pct' => (int) round($group->where('was_correct', true)->count() / max(1, $group->count()) * 100),
                'asks_ahead' => (bool) $group->first()->asks_ahead,
                'correct_text' => $group->first()->correct_text,
                'distribution' => $distribution,
                'missed_by' => $group->where('was_correct', false)
                    ->map(fn (QuizAnswer $a) => $this->nameFor($scoresById[$a->quiz_score_id] ?? null))
                    ->filter()->unique()->values()->all(),
            ];
        })->sortBy('correct_pct')->values()->all();
    }

    /** @return list<array{score_id: int, name: string, pct: ?int, needs_help: bool, source: string, integrity: ?array, played_at: mixed}> */
    public function players(): array
    {
        return $this->scores()->map(fn (QuizScore $s) => [
            'score_id' => $s->id,
            'name' => $this->nameFor($s),
            'score' => $s->score,
            'correct' => $s->correct,
            'total' => $s->total,
            'pct' => $this->gradableRate($s),
            'needs_help' => ($this->gradableRate($s) ?? 100) < self::NEEDS_HELP_BELOW_PCT,
            'source' => $s->source,
            'integrity' => $s->integrity,
            'played_at' => $s->created_at,
        ])->values()->all();
    }

    /** Per-question drill-down for one run. @return list<array<string, mixed>> */
    public function drilldown(int $scoreId): array
    {
        $score = QuizScore::with('answers')->where('lesson_id', $this->lesson->id)->findOrFail($scoreId);

        return $score->answers->map(fn (QuizAnswer $a) => [
            'question_order' => $a->question_order,
            'question_text' => $a->question_text,
            'chosen_text' => $a->chosen_text,
            'correct_text' => $a->correct_text,
            'was_correct' => $a->was_correct,
            'response_ms' => $a->response_ms,
            'asks_ahead' => $a->asks_ahead,
        ])->all();
    }

    private function nameFor(?QuizScore $score): ?string
    {
        return $score?->member?->display_name ?? $score?->nickname;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter LessonResultsTest`
Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LessonResults.php tests/Unit/LessonResultsTest.php
git commit -m "feat(results): LessonResults service - overview, difficult questions, drilldown"
```

---

### Task 5: Lesson report page (Livewire, 3 tabs) + routes + entry points

**Files:**
- Create: `app/Livewire/Teacher/LessonReport.php`
- Create: `resources/views/livewire/teacher/lesson-report.blade.php`
- Modify: `routes/web.php` (inside the `teacher.` group, near the wizard routes at ~line 182)
- Modify: `resources/views/components/app-nav.blade.php` (Results nav item, teacher block ~line 23)
- Modify: `resources/views/teacher/dashboard.blade.php` (📊 Results button per lesson card)
- Test: `tests/Feature/Teacher/LessonReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Livewire\Teacher\LessonReport;
use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LessonReportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id, 'topic' => 'Napoleon', 'subject' => 'history',
            'grade_level' => '8', 'status' => LessonStatus::Published,
        ]);
        $score = QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Daan B.', 'score' => 0, 'correct' => 0, 'total' => 1]);
        QuizAnswer::create(['quiz_score_id' => $score->id, 'question_order' => 1, 'question_text' => 'Why X?', 'chosen_text' => 'B', 'correct_text' => 'A', 'was_correct' => false, 'asks_ahead' => false]);
    }

    public function test_owner_sees_overview_with_needs_help_and_difficult_questions(): void
    {
        $this->actingAs($this->teacher)
            ->get("/teacher/lessons/{$this->lesson->id}/results")
            ->assertOk()
            ->assertSee('Daan B.')
            ->assertSee('Why X?');
    }

    public function test_other_teachers_get_403(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->get("/teacher/lessons/{$this->lesson->id}/results")
            ->assertForbidden();
    }

    public function test_player_drilldown_loads(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonReport::class, ['lesson' => $this->lesson])
            ->set('tab', 'players')
            ->call('openPlayer', QuizScore::sole()->id)
            ->assertSee('Why X?')
            ->assertSee('B');
    }

    public function test_csv_download_streams_rows(): void
    {
        $response = Livewire::actingAs($this->teacher)
            ->test(LessonReport::class, ['lesson' => $this->lesson])
            ->call('exportCsv');

        $response->assertFileDownloaded();
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter LessonReportTest`
Expected: ERROR class/route not found.

- [ ] **Step 3: Route + component**

`routes/web.php`, inside the teacher group directly under the `lessons/{lesson}/composer` route:

```php
    // Results & analytics (spec: docs/superpowers/specs/2026-07-08-teacher-results-analytics-design.md)
    Route::get('/lessons/{lesson}/results', \App\Livewire\Teacher\LessonReport::class)->name('lessons.results');
    Route::get('/results', \App\Livewire\Teacher\ResultsHub::class)->name('results.hub');
```

(`ResultsHub` is fleshed out in Task 6; Step 5 of THIS task creates a bootable stub so the routes file never references a missing class.)

`app/Livewire/Teacher/LessonReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\Lesson;
use App\Services\LessonResults;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LessonReport extends Component
{
    public Lesson $lesson;

    public string $tab = 'overview';           // overview | questions | players

    public ?int $classroomId = null;           // filter

    public string $range = '30';               // days: 7 | 30 | 90 | all

    public ?int $openScoreId = null;           // players tab drill-down

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
    }

    private function results(): LessonResults
    {
        return new LessonResults(
            $this->lesson,
            $this->classroomId,
            $this->range === 'all' ? null : now()->subDays((int) $this->range),
            null,
        );
    }

    #[Computed]
    public function overview(): array
    {
        return $this->results()->overview();
    }

    #[Computed]
    public function questionBreakdown(): array
    {
        return $this->results()->questionBreakdown();
    }

    #[Computed]
    public function players(): array
    {
        return $this->results()->players();
    }

    #[Computed]
    public function drilldown(): array
    {
        return $this->openScoreId ? $this->results()->drilldown($this->openScoreId) : [];
    }

    #[Computed]
    public function classrooms()
    {
        return $this->lesson->classrooms()->get();
    }

    public function openPlayer(int $scoreId): void
    {
        $this->openScoreId = $this->openScoreId === $scoreId ? null : $scoreId;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $players = $this->results()->players();
        $filename = 'results-'.$this->lesson->lesson_code.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($players): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'score', 'correct', 'total', 'correct_pct', 'needs_help', 'source', 'played_at']);
            foreach ($players as $row) {
                fputcsv($out, [
                    $row['name'], $row['score'], $row['correct'], $row['total'],
                    $row['pct'], $row['needs_help'] ? 'yes' : 'no', $row['source'], $row['played_at'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        return view('livewire.teacher.lesson-report')
            ->layout('components.layouts.app', ['title' => 'Results — '.($this->lesson->title ?? $this->lesson->topic)]);
    }
}
```

- [ ] **Step 4: The blade (three tabs, DaisyUI)**

`resources/views/livewire/teacher/lesson-report.blade.php`:

```blade
<div class="mx-auto max-w-5xl px-4 py-8 space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold">{{ $lesson->title ?? $lesson->topic }} — {{ __('Results') }}</h1>
            <p class="text-sm opacity-60">{{ __('Lesson code') }} {{ $lesson->lesson_code }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($this->classrooms->isNotEmpty())
                <select wire:model.live="classroomId" class="select select-sm select-bordered">
                    <option value="">{{ __('All classes') }}</option>
                    @foreach ($this->classrooms as $classroom)
                        <option value="{{ $classroom->id }}">{{ $classroom->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="range" class="select select-sm select-bordered">
                <option value="7">{{ __('Last 7 days') }}</option>
                <option value="30">{{ __('Last 30 days') }}</option>
                <option value="90">{{ __('Last 90 days') }}</option>
                <option value="all">{{ __('All time') }}</option>
            </select>
            <button wire:click="exportCsv" class="btn btn-sm btn-outline">⬇ CSV</button>
        </div>
    </div>

    <div role="tablist" class="tabs tabs-bordered">
        @foreach (['overview' => __('Overview'), 'questions' => __('Questions'), 'players' => __('Players')] as $key => $label)
            <button role="tab" wire:click="$set('tab', '{{ $key }}')"
                    class="tab {{ $tab === $key ? 'tab-active font-semibold' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        @php $o = $this->overview; @endphp
        <div class="grid grid-cols-3 gap-3">
            <div class="card bg-base-200 p-4 text-center"><span class="text-3xl font-extrabold">{{ $o['players'] }}</span><span class="text-xs opacity-60">{{ __('players') }}</span></div>
            <div class="card bg-base-200 p-4 text-center"><span class="text-3xl font-extrabold">{{ $o['avg_correct_pct'] }}%</span><span class="text-xs opacity-60">{{ __('avg correct') }}</span></div>
            <div class="card bg-base-200 p-4 text-center {{ count($o['needs_help']) ? 'border border-error/40' : '' }}">
                <span class="text-3xl font-extrabold {{ count($o['needs_help']) ? 'text-error' : '' }}">{{ count($o['needs_help']) }}</span>
                <span class="text-xs opacity-60">{{ __('need help') }}</span>
            </div>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider opacity-60">{{ __('Leaderboard') }}</h2>
                <div class="space-y-1.5">
                    @forelse ($o['leaderboard'] as $i => $row)
                        <div class="flex items-center gap-2 rounded-xl border px-3 py-2
                                    {{ $row['needs_help'] ? 'border-error/40' : 'border-base-300' }}">
                            <span class="w-6 text-sm font-bold opacity-60">{{ $i + 1 }}</span>
                            <span class="flex-1 truncate font-medium">{{ $row['name'] }}</span>
                            <x-results.integrity-chips :integrity="$row['integrity']" :source="$row['source']" />
                            <span class="font-bold text-warning">{{ $row['score'] }}</span>
                            <span class="text-xs opacity-60">{{ $row['correct'] }}/{{ $row['total'] }}</span>
                        </div>
                    @empty
                        <p class="text-sm opacity-60">{{ __('No plays yet — share the lesson link or import paper sheets.') }}</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider opacity-60">{{ __('Difficult questions') }}</h2>
                @php $difficult = collect($this->questionBreakdown)->filter(fn ($q) => $q['correct_pct'] < \App\Services\LessonResults::DIFFICULT_BELOW_PCT)->values(); @endphp
                <div class="space-y-2">
                    @forelse ($difficult as $q)
                        <div class="rounded-xl border border-base-300 p-3">
                            <p class="text-sm">{{ $q['asks_ahead'] ? '⤳ ' : '' }}{{ $q['question_text'] }}</p>
                            <progress class="progress {{ $q['correct_pct'] < 35 ? 'progress-error' : 'progress-warning' }} w-full"
                                      value="{{ $q['correct_pct'] }}" max="100"></progress>
                            <span class="text-xs {{ $q['correct_pct'] < 35 ? 'text-error' : 'text-warning' }}">{{ $q['correct_pct'] }}% {{ __('correct') }}</span>
                        </div>
                    @empty
                        <p class="text-sm opacity-60">{{ __('No difficult questions — nice!') }}</p>
                    @endforelse
                </div>
                @if ($difficult->isNotEmpty())
                    <button wire:click="requiz" class="btn btn-sm btn-outline mt-3">↻ {{ __('Re-quiz these questions') }}</button>
                @endif
            </div>
        </div>
    @elseif ($tab === 'questions')
        <div class="space-y-2">
            @forelse ($this->questionBreakdown as $q)
                <details class="rounded-xl border border-base-300 p-3">
                    <summary class="cursor-pointer text-sm">
                        {{ $q['asks_ahead'] ? '⤳ ' : '' }}{{ $q['question_text'] }}
                        <span class="{{ $q['correct_pct'] < 35 ? 'text-error' : ($q['correct_pct'] < 50 ? 'text-warning' : 'text-success') }} font-semibold">
                            {{ $q['correct_pct'] }}%
                        </span>
                    </summary>
                    <div class="mt-2 space-y-1 text-sm">
                        @foreach ($q['distribution'] as $option => $count)
                            <div class="flex items-center gap-2">
                                <span class="{{ $option === $q['correct_text'] ? 'text-success font-semibold' : '' }} flex-1 truncate">{{ $option }}</span>
                                <span class="opacity-60">{{ $count }}×</span>
                            </div>
                        @endforeach
                        @if ($q['missed_by'])
                            <p class="text-xs opacity-60">{{ __('Missed by') }}: {{ implode(', ', $q['missed_by']) }}</p>
                        @endif
                    </div>
                </details>
            @empty
                <p class="text-sm opacity-60">{{ __('No answers recorded yet.') }}</p>
            @endforelse
        </div>
    @else
        <div class="space-y-1.5">
            @forelse ($this->players as $row)
                <div class="rounded-xl border {{ $row['needs_help'] ? 'border-error/40' : 'border-base-300' }} px-3 py-2">
                    <button wire:click="openPlayer({{ $row['score_id'] }})" class="flex w-full items-center gap-2 text-left">
                        <span class="flex-1 font-medium">{{ $row['name'] }}</span>
                        <x-results.integrity-chips :integrity="$row['integrity']" :source="$row['source']" />
                        <span class="text-sm">{{ $row['pct'] }}%</span>
                        <span class="text-xs opacity-60">{{ $row['played_at']->format('d M H:i') }}</span>
                    </button>
                    @if ($openScoreId === $row['score_id'])
                        <div class="mt-2 space-y-1 border-t border-base-300 pt-2 text-sm">
                            @foreach ($this->drilldown as $a)
                                <div class="flex items-start gap-2">
                                    <span>{{ $a['was_correct'] ? '✅' : '❌' }}</span>
                                    <span class="flex-1">{{ $a['asks_ahead'] ? '⤳ ' : '' }}{{ $a['question_text'] }}
                                        <span class="opacity-60">— {{ $a['chosen_text'] }}@if(!$a['was_correct']) ({{ __('correct') }}: {{ $a['correct_text'] }})@endif</span>
                                    </span>
                                    @if ($a['response_ms'] !== null)<span class="text-xs opacity-50">{{ round($a['response_ms'] / 1000, 1) }}s</span>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm opacity-60">{{ __('No players yet.') }}</p>
            @endforelse
        </div>
    @endif
</div>
```

Create the chips partial `resources/views/components/results/integrity-chips.blade.php`:

```blade
@props(['integrity' => null, 'source' => 'web'])
<span class="flex shrink-0 gap-1 text-sm">
    @if ($source === 'paper')<span title="{{ __('Paper sheet') }}">📄</span>@endif
    @if (($integrity['rapid_guesses'] ?? 0) >= 2)<span title="{{ __(':n answers under 2 seconds', ['n' => $integrity['rapid_guesses']]) }}">⚡</span>@endif
    @if (($integrity['focus_drops'] ?? 0) >= 2)<span title="{{ __('Left the tab :n times', ['n' => $integrity['focus_drops']]) }}">👀</span>@endif
    @if (($integrity['same_letter_streak'] ?? 0) >= 4)<span title="{{ __('Same answer position :n times in a row', ['n' => $integrity['same_letter_streak']]) }}">🔁</span>@endif
</span>
```

Add a temporary no-op so the blade's `requiz` button doesn't crash before Task 8 — in `LessonReport`:

```php
    public function requiz(): void
    {
        // Implemented in the re-quiz task; button hidden behind difficult-question presence.
        $this->dispatch('toast', message: 'Re-quiz coming in the next task.', type: 'info');
    }
```

- [ ] **Step 5: Entry points**

`resources/views/components/app-nav.blade.php` — in the `$isTeacher` block after the 'Lessons' item:

```php
        $items[] = [
            'label' => 'Results',
            'route' => 'teacher.results.hub',
            'pattern' => 'teacher.results.*',
        ];
```

`resources/views/teacher/dashboard.blade.php` — inside each lesson card's action area (find the existing wizard/edit link per card and add beside it):

```blade
<a href="{{ route('teacher.lessons.results', $lesson) }}" class="btn btn-xs btn-outline">📊 {{ __('Results') }}</a>
```

Also create a minimal `ResultsHub` stub NOW so the route file stays valid (fleshed out next task) — `app/Livewire/Teacher/ResultsHub.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use Livewire\Component;

class ResultsHub extends Component
{
    public function render()
    {
        return view('livewire.teacher.results-hub')
            ->layout('components.layouts.app', ['title' => 'Results']);
    }
}
```

with a placeholder view `resources/views/livewire/teacher/results-hub.blade.php`:

```blade
<div class="mx-auto max-w-5xl px-4 py-8"><h1 class="text-xl font-bold">{{ __('Results') }}</h1></div>
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit --filter LessonReportTest`
Expected: 4 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Teacher routes/web.php resources/views/livewire/teacher resources/views/components/results resources/views/components/app-nav.blade.php resources/views/teacher/dashboard.blade.php tests/Feature/Teacher/LessonReportTest.php
git commit -m "feat(results): lesson report page - overview/questions/players + CSV + entry points"
```

---

### Task 6: Results hub

Class filtering happens after drill-in on the report page (the hub rows aggregate all classes); lesson + date filters live on the hub itself.

**Files:**
- Modify: `app/Livewire/Teacher/ResultsHub.php` (replace stub)
- Modify: `resources/views/livewire/teacher/results-hub.blade.php` (replace stub)
- Test: `tests/Feature/Teacher/ResultsHubTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_lists_own_lessons_activity_grouped_per_day_and_hides_other_teachers(): void
    {
        $teacher = User::factory()->create();
        $other = User::factory()->create();
        $mine = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        $theirs = Lesson::create(['teacher_id' => $other->id, 'topic' => 'Rome', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizScore::create(['lesson_id' => $mine->id, 'nickname' => 'Emma V.', 'score' => 20, 'correct' => 2, 'total' => 2]);
        QuizScore::create(['lesson_id' => $theirs->id, 'nickname' => 'Ghost', 'score' => 20, 'correct' => 2, 'total' => 2]);

        $this->actingAs($teacher)->get('/teacher/results')
            ->assertOk()
            ->assertSee('Napoleon')
            ->assertDontSee('Rome');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter ResultsHubTest`
Expected: FAIL (stub shows neither lesson).

- [ ] **Step 3: Implement**

`app/Livewire/Teacher/ResultsHub.php` (replace file):

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\QuizScore;
use App\Services\LessonResults;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ResultsHub extends Component
{
    public string $range = '30';    // days: 7 | 30 | 90 | all

    public ?int $lessonId = null;   // filter to one lesson

    /**
     * Recent activity: one row per lesson per calendar day (spec). needs_help is
     * computed with the same LessonResults rule so numbers match the report page.
     */
    #[Computed]
    public function activity(): array
    {
        $rows = QuizScore::query()
            ->select([
                'lesson_id',
                DB::raw('DATE(quiz_scores.created_at) as day'),
                DB::raw('COUNT(*) as players'),
            ])
            ->whereHas('lesson', fn ($q) => $q->where('teacher_id', auth()->id()))
            ->when($this->lessonId, fn ($q) => $q->where('lesson_id', $this->lessonId))
            ->when($this->range !== 'all', fn ($q) => $q->where('quiz_scores.created_at', '>=', now()->subDays((int) $this->range)))
            ->groupBy('lesson_id', 'day')
            ->orderByDesc('day')
            ->with('lesson')   // note: with() on aggregate needs lesson_id in select — it is
            ->get();

        return $rows->map(function ($row) {
            $results = new LessonResults(
                $row->lesson,
                null,
                \Carbon\Carbon::parse($row->day)->startOfDay(),
                \Carbon\Carbon::parse($row->day)->endOfDay(),
            );
            $overview = $results->overview();

            return [
                'lesson' => $row->lesson,
                'day' => $row->day,
                'players' => (int) $row->players,
                'avg_correct_pct' => $overview['avg_correct_pct'],
                'needs_help' => count($overview['needs_help']),
            ];
        })->all();
    }

    public function render()
    {
        return view('livewire.teacher.results-hub')
            ->layout('components.layouts.app', ['title' => 'Results']);
    }
}
```

`resources/views/livewire/teacher/results-hub.blade.php` (replace file):

```blade
<div class="mx-auto max-w-5xl px-4 py-8 space-y-4">
    <div class="flex items-center justify-between gap-2">
        <h1 class="text-xl font-bold">{{ __('Results') }}</h1>
        <div class="flex gap-2">
        <select wire:model.live="lessonId" class="select select-sm select-bordered">
            <option value="">{{ __('All lessons') }}</option>
            @foreach (\App\Models\Lesson::where('teacher_id', auth()->id())->orderBy('title')->get(['id','title','topic']) as $l)
                <option value="{{ $l->id }}">{{ $l->title ?? $l->topic }}</option>
            @endforeach
        </select>
        <select wire:model.live="range" class="select select-sm select-bordered">
            <option value="7">{{ __('Last 7 days') }}</option>
            <option value="30">{{ __('Last 30 days') }}</option>
            <option value="90">{{ __('Last 90 days') }}</option>
            <option value="all">{{ __('All time') }}</option>
        </select>
        </div>
    </div>

    <div class="space-y-1.5">
        @forelse ($this->activity as $row)
            <a href="{{ route('teacher.lessons.results', $row['lesson']) }}"
               class="flex items-center gap-3 rounded-xl border border-base-300 px-4 py-3 hover:border-warning/60 transition">
                <div class="flex-1">
                    <span class="font-semibold">{{ $row['lesson']->title ?? $row['lesson']->topic }}</span>
                    <span class="text-xs opacity-60 ml-2">{{ \Carbon\Carbon::parse($row['day'])->translatedFormat('d M Y') }}</span>
                </div>
                <span class="text-sm">{{ $row['players'] }} {{ __('players') }}</span>
                <span class="text-sm font-semibold">{{ $row['avg_correct_pct'] }}%</span>
                @if ($row['needs_help'] > 0)
                    <span class="badge badge-error badge-outline">{{ $row['needs_help'] }} {{ __('need help') }}</span>
                @else
                    <span class="badge badge-success badge-outline">{{ __('on track') }}</span>
                @endif
            </a>
        @empty
            <p class="text-sm opacity-60">{{ __('No quiz activity yet. Results appear here after students play.') }}</p>
        @endforelse
    </div>
</div>
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter ResultsHubTest`
Expected: PASS. (Postgres note: `DATE(...)` works; the `with('lesson')` eager load needs `lesson_id` selected — it is.)

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Teacher/ResultsHub.php resources/views/livewire/teacher/results-hub.blade.php tests/Feature/Teacher/ResultsHubTest.php
git commit -m "feat(results): results hub - per-lesson per-day activity rows"
```

---

### Task 7: Printable answer sheet (print-CSS)

**Files:**
- Create: `resources/views/teacher/answer-sheet.blade.php`
- Modify: `routes/web.php` (one route in the teacher group)
- Modify: `resources/views/livewire/teacher/lesson-report.blade.php` (header button)
- Test: `tests/Feature/Teacher/AnswerSheetTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_prints_questions_with_bubbles_and_is_owner_only(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizQuestion::create(['lesson_id' => $lesson->id, 'order' => 1, 'question' => 'Why did X happen?', 'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0]);

        $this->actingAs($teacher)->get("/teacher/lessons/{$lesson->id}/results/answer-sheet")
            ->assertOk()
            ->assertSee('Why did X happen?')
            ->assertSee($lesson->lesson_code)
            ->assertSee('Ⓐ');

        $this->actingAs(User::factory()->create())
            ->get("/teacher/lessons/{$lesson->id}/results/answer-sheet")
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit --filter AnswerSheetTest` → 404.

- [ ] **Step 3: Route + view**

Route (under the results routes):

```php
    Route::get('/lessons/{lesson}/results/answer-sheet', function (\App\Models\Lesson $lesson) {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $questions = $lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();

        return view('teacher.answer-sheet', compact('lesson', 'questions'));
    })->name('lessons.answer-sheet');
```

`resources/views/teacher/answer-sheet.blade.php` (full document — deliberately standalone for clean printing):

```blade
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Answer sheet') }} — {{ $lesson->title ?? $lesson->topic }}</title>
    <style>
        body { font-family: -apple-system, 'Segoe UI', sans-serif; color: #111; margin: 2rem; }
        .sheet { max-width: 700px; margin: 0 auto; page-break-after: always; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 16px; }
        .name-line { border-bottom: 1.5px solid #111; min-width: 260px; display: inline-block; }
        .q { margin: 14px 0; }
        .bubbles { font-size: 22px; letter-spacing: 14px; margin-top: 4px; }
        .footer { margin-top: 24px; font-size: 11px; color: #555; display: flex; justify-content: space-between; }
        .no-print { margin: 0 auto 16px; max-width: 700px; }
        @media print { .no-print { display: none; } body { margin: 0.5cm; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">🖨 {{ __('Print') }}</button>
        {{ __('Tip: print one sheet per student. Show the answer options on the digibord.') }}
    </div>
    <div class="sheet">
        <div class="head">
            <div>
                <strong>{{ $lesson->title ?? $lesson->topic }}</strong><br>
                <small>{{ __('Class') }}: ______ &nbsp; {{ __('Date') }}: ______</small>
            </div>
            <div>{{ __('Name (first name + first letter of last name, e.g. "Emma V.")') }}<br><span class="name-line">&nbsp;</span></div>
        </div>
        @foreach ($questions as $i => $question)
            <div class="q">
                <div><strong>{{ $i + 1 }}.</strong> {{ $question->question }}</div>
                <div class="bubbles">Ⓐ Ⓑ Ⓒ Ⓓ</div>
            </div>
        @endforeach
        <div class="footer">
            <span>{{ $lesson->lesson_code }}</span>
            <span>thelearningportal.us · {{ __('sheet') }} v1</span>
        </div>
    </div>
</body>
</html>
```

Report header button (next to CSV in `lesson-report.blade.php`):

```blade
            <a href="{{ route('teacher.lessons.answer-sheet', $lesson) }}" target="_blank" class="btn btn-sm btn-outline">🖨 {{ __('Answer sheets') }}</a>
```

- [ ] **Step 4: Run tests** — `vendor/bin/phpunit --filter AnswerSheetTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/teacher/answer-sheet.blade.php routes/web.php resources/views/livewire/teacher/lesson-report.blade.php tests/Feature/Teacher/AnswerSheetTest.php
git commit -m "feat(results): printable answer sheet (print-CSS, bubbles + digibord options)"
```

---

### Task 8: Re-quiz difficult questions + shuffle setting

**Files:**
- Modify: `app/Livewire/Teacher/LessonReport.php` (real `requiz()`)
- Modify: `app/Jobs/GenerateLessonQuiz.php` (constructor: optional reinforce payload)
- Modify: `app/Services/QuizPrompt.php` (reinforce block in `user()`)
- Modify: `app/Livewire/Wizard/Concerns/EditsQuizQuestions.php` (`setQuizShuffle`)
- Modify: `resources/views/components/lesson/scene-inspector-game.blade.php` (shuffle select next to Scope)
- Modify: `resources/views/lesson/player.blade.php` + `resources/js/lesson-player.js` + `resources/js/scene/QuizOverlay.js` (shuffle mode wire-through)
- Test: `tests/Feature/Teacher/RequizTest.php`, extend `tests/Feature/Wizard/Step3SceneConfiguratorTest.php`

- [ ] **Step 1: Failing tests**

`tests/Feature/Teacher/RequizTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Livewire\Teacher\LessonReport;
use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RequizTest extends TestCase
{
    use RefreshDatabase;

    public function test_requiz_appends_a_new_quiz_scene_seeded_with_difficult_questions(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'script_segment' => 'Napoleon rises.', 'status' => 'ready']);
        $score = QuizScore::create(['lesson_id' => $lesson->id, 'nickname' => 'Daan B.', 'score' => 0, 'correct' => 0, 'total' => 1]);
        QuizAnswer::create(['quiz_score_id' => $score->id, 'question_order' => 1, 'question_text' => 'Why did the coup matter?', 'chosen_text' => 'B', 'correct_text' => 'A', 'was_correct' => false, 'asks_ahead' => false]);

        $captured = '';
        $this->mock(OpenAiLlmService::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('json')->once()
                ->withArgs(function ($system, $user) use (&$captured) { $captured = $user; return true; })
                ->andReturn(['questions' => [
                    ['order' => 1, 'question' => 'Why did the coup matter, again?', 'options' => ['a','b','c','d'], 'correct_index' => 0],
                ]]);
        });

        Livewire::actingAs($teacher)
            ->test(LessonReport::class, ['lesson' => $lesson])
            ->call('requiz');

        $requizScene = Scene::where('kind', 'game')->where('game_type', 'quiz')->latest('order')->first();
        $this->assertNotNull($requizScene);
        $this->assertStringContainsString('Why did the coup matter?', $captured);
        $this->assertStringContainsString('REINFORCE', $captured);
        $this->assertSame(1, \App\Models\QuizQuestion::where('scene_id', $requizScene->id)->count());
    }
}
```

Append to `Step3SceneConfiguratorTest`:

```php
    public function test_quiz_shuffle_setting_persists_and_rejects_garbage(): void
    {
        $this->s2->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizShuffle', 'once');
        $this->assertSame('once', $this->s2->fresh()->config['quiz_shuffle']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizShuffle', 'chaos');
        $this->assertSame('once', $this->s2->fresh()->config['quiz_shuffle']);
    }
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit --filter "RequizTest|test_quiz_shuffle_setting"` → FAIL.

- [ ] **Step 3: Job + prompt reinforce path**

`GenerateLessonQuiz` constructor becomes:

```php
    /**
     * @param  list<string>|null  $reinforceQuestions  difficult-question texts to re-ask (re-quiz)
     * @param  int|null  $onlySceneId  restrict generation to ONE quiz scene (re-quiz target)
     */
    public function __construct(
        public readonly int $lessonId,
        public readonly ?array $reinforceQuestions = null,
        public readonly ?int $onlySceneId = null,
    ) {}
```

In `handle()`, change the destructive delete to respect the restriction — replace `QuizQuestion::where('lesson_id', $lesson->id)->delete();` with:

```php
        QuizQuestion::where('lesson_id', $lesson->id)
            ->when($this->onlySceneId, fn ($q) => $q->where('scene_id', $this->onlySceneId))
            ->delete();
```

and filter the scenes loop — after `$quizScenes = ...->values();` add:

```php
        if ($this->onlySceneId) {
            $quizScenes = $quizScenes->where('id', $this->onlySceneId)->values();
        }
```

Thread the reinforce list into the focus text — replace the `$focus =` assignment with:

```php
                $focus = $previousQuizOrder !== null
                    ? 'This is a later checkpoint: prefer the narration AFTER the previous quiz, '
                      .'but reinforcing earlier material is allowed.'
                    : '';
                if ($this->reinforceQuestions) {
                    $focus .= "\nREINFORCE: the class struggled with these questions — write fresh variants "
                        ."that test the SAME facts with different wording and different distractors:\n- "
                        .implode("\n- ", $this->reinforceQuestions);
                }
```

- [ ] **Step 4: `requiz()` in `LessonReport`** (replace the stub)

```php
    public function requiz(): void
    {
        $difficult = collect($this->results()->difficultQuestions())
            ->reject(fn (array $q) => $q['asks_ahead'])
            ->pluck('question_text')->take(8)->all();

        if ($difficult === []) {
            $this->dispatch('toast', message: __('No difficult questions to re-quiz.'), type: 'info');

            return;
        }

        // Append a NEW quiz scene at the end — the original segment and results stay untouched.
        $lastOrder = (int) $this->lesson->scenes()->max('order');
        $scene = \App\Models\Scene::create([
            'lesson_id' => $this->lesson->id,
            'order' => $lastOrder + 1,
            'kind' => 'game',
            'game_type' => 'quiz',
            'quiz_question_count' => count($difficult),
            'quiz_timing' => 'after',
            'status' => 'ready',
            'config' => ['quiz_scope' => 'taught', 'requiz' => true],
        ]);

        try {
            (new \App\Jobs\GenerateLessonQuiz($this->lesson->id, $difficult, $scene->id))
                ->handle(app(\App\Services\OpenAiLlmService::class));
        } catch (\Throwable $e) {
            $scene->delete();
            $this->dispatch('toast', message: __('Re-quiz generation failed — try again.'), type: 'error');

            return;
        }

        $this->dispatch('toast', message: __('Re-quiz added to the end of the lesson.'), type: 'success');
    }
```

- [ ] **Step 5: Shuffle setting (trait + inspector + player)**

`EditsQuizQuestions` — add below `setQuizScope`:

```php
    /** off | once (same new order for the whole class) | per_player (digital only). */
    public function quizShuffle(): string
    {
        $scene = $this->quizDraftSceneId ? Scene::find($this->quizDraftSceneId) : null;
        $mode = ($scene?->config ?? [])['quiz_shuffle'] ?? 'per_player';

        return in_array($mode, ['off', 'once', 'per_player'], true) ? $mode : 'per_player';
    }

    public function setQuizShuffle(string $mode): void
    {
        if (! $this->quizDraftSceneId || ! in_array($mode, ['off', 'once', 'per_player'], true)) {
            return;
        }
        $scene = Scene::find($this->quizDraftSceneId);
        if ($scene) {
            $scene->update(['config' => array_merge($scene->config ?? [], ['quiz_shuffle' => $mode])]);
        }
    }
```

Inspector (`scene-inspector-game.blade.php`, next to the Scope join control; pass `:quiz-shuffle="$this->quizShuffle()"` from `step3-scene-configurator.blade.php` and add `'quizShuffle' => 'per_player'` to `@props`):

```blade
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] text-slate-400">{{ __('Shuffle') }}</span>
                    <select wire:change="setQuizShuffle($event.target.value)" class="select select-xs select-bordered bg-slate-900">
                        <option value="per_player" @selected($quizShuffle === 'per_player')>{{ __('Per player') }}</option>
                        <option value="once" @selected($quizShuffle === 'once')>{{ __('Same for class (digibord/paper)') }}</option>
                        <option value="off" @selected($quizShuffle === 'off')>{{ __('Off') }}</option>
                    </select>
                </div>
```

Player wire-through — `player.blade.php` scenes map, next to `quiz_questions`:

```php
                'quiz_shuffle' => $s->kind === 'game' ? (($s->config ?? [])['quiz_shuffle'] ?? 'per_player') : null,
```

`lesson-player.js` scene queue map: add `quiz_shuffle: s.quiz_shuffle ?? 'per_player',` and pass to the overlay in `_beginQuizFlow`: `shuffleMode: scene.quiz_shuffle,` (grab `scene` from `_sceneQueue[this._sceneIndex]` — it is already in scope at the `_afterSceneAudio` call site; thread it through `_beginQuizFlow(questions, scene.quiz_shuffle)`).

`QuizOverlay.show({...})` — accept `shuffleMode = 'per_player'` and replace the `this._display = ...` line with:

```js
    this._display = this._questions.map((q, qi) => {
      const n = (q.options || []).length || 4
      if (shuffleMode === 'off') return Array.from({ length: n }, (_, i) => i)
      if (shuffleMode === 'once') return QuizOverlay._seededShuffle(n, qi + 1)   // same order for everyone
      return QuizOverlay._shuffledIndices(n)                                     // per player
    })
```

with the deterministic helper next to `_shuffledIndices`:

```js
  // Mulberry32-seeded Fisher-Yates: identical order on every device (digibord + paper safe).
  static _seededShuffle(n, seed) {
    let s = seed >>> 0
    const rnd = () => { s = (s + 0x6D2B79F5) >>> 0; let t = Math.imul(s ^ (s >>> 15), 1 | s); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296 }
    const arr = Array.from({ length: n }, (_, i) => i)
    for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(rnd() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]] }
    return arr
  }
```

- [ ] **Step 6: Run tests + build**

Run: `vendor/bin/phpunit --filter "RequizTest|Step3SceneConfiguratorTest|GenerateLessonQuizTest" && npm run build`
Expected: all PASS, `✓ built`.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Teacher/LessonReport.php app/Jobs/GenerateLessonQuiz.php app/Services/QuizPrompt.php app/Livewire/Wizard/Concerns/EditsQuizQuestions.php resources/views/components/lesson/scene-inspector-game.blade.php resources/views/livewire/wizard/step3-scene-configurator.blade.php resources/views/lesson/player.blade.php resources/js tests
git add -f public/build/manifest.json
git commit -m "feat(results): re-quiz difficult questions + 3-level answer shuffle"
```

---

### Task 9: `NameMatcher::match` (fuzzy roster matching)

**Files:**
- Modify: `app/Services/Support/NameMatcher.php`
- Test: `tests/Unit/NameMatcherTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\NameMatcher;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    public function test_canonicalizes_to_first_name_plus_initial(): void
    {
        $this->assertSame('Emma V.', NameMatcher::canonical('emma visser'));
        $this->assertSame('Emma V.', NameMatcher::canonical('  EMMA   V. '));
        $this->assertSame('Emma', NameMatcher::canonical('emma'));
    }

    public function test_matches_roster_entries_with_typos_and_full_surnames(): void
    {
        $roster = ['Emma V.', 'Emma B.', 'Daan K.'];

        $this->assertSame('Emma V.', NameMatcher::match('Emma Visser', $roster));
        $this->assertSame('Emma V.', NameMatcher::match('emma v', $roster));
        $this->assertSame('Daan K.', NameMatcher::match('Dan K.', $roster));      // 1 typo
        $this->assertNull(NameMatcher::match('Sofie J.', $roster));               // not on roster
        $this->assertNull(NameMatcher::match('Emma', $roster));                   // ambiguous: two Emmas
    }
}
```

- [ ] **Step 2: Verify failure** — `vendor/bin/phpunit --filter NameMatcherTest` → ERROR `match` undefined.

- [ ] **Step 3: Implement** — add to `NameMatcher`:

```php
    /**
     * Find the roster entry for a handwritten name. Normalizes both sides to
     * "first + initial", accepts Levenshtein ≤ 2 on the normalized form, and
     * refuses ambiguous matches (two roster entries equally close).
     *
     * @param  list<string>  $roster
     */
    public static function match(string $raw, array $roster): ?string
    {
        $needle = mb_strtolower(self::canonical($raw));
        if ($needle === '') {
            return null;
        }

        $scored = [];
        foreach ($roster as $entry) {
            $candidate = mb_strtolower(self::canonical($entry));
            $distance = levenshtein($needle, $candidate);
            // A bare first name may not silently claim "First X." — require the initial
            // unless exactly one roster entry starts with that first name.
            $scored[$entry] = $distance;
        }
        asort($scored);
        $best = array_key_first($scored);
        $bestDistance = $scored[$best];

        if ($bestDistance > 2) {
            // Bare-first-name fallback: unique prefix match ("Daan" → "Daan K.").
            $prefixMatches = array_values(array_filter($roster, fn (string $entry) => str_starts_with(
                mb_strtolower(self::canonical($entry)), $needle.' ',
            )));

            return count($prefixMatches) === 1 ? $prefixMatches[0] : null;
        }

        // Ambiguity guard: another entry within the same distance → give up, let the teacher pick.
        $ties = array_keys(array_filter($scored, fn (int $d) => $d === $bestDistance));

        return count($ties) === 1 ? $best : null;
    }
```

- [ ] **Step 4: Run** — `vendor/bin/phpunit --filter NameMatcherTest` → PASS. (If the "Emma" ambiguity case fails: both "emma v." and "emma b." are distance 3 from "emma" → falls to prefix branch → two prefix matches → null. Verify the distances; adjust the test expectation ONLY if the arithmetic genuinely differs.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Support/NameMatcher.php tests/Unit/NameMatcherTest.php
git commit -m "feat(results): fuzzy roster name matching (canonical + levenshtein + ambiguity guard)"
```

---

### Task 10: Paper import — vision extraction + review grid + confirm

**Files:**
- Create: `app/Services/PaperSheetExtractor.php`
- Modify: `app/Livewire/Teacher/LessonReport.php` (upload, review rows, confirm)
- Modify: `resources/views/livewire/teacher/lesson-report.blade.php` (import modal)
- Test: `tests/Feature/Teacher/PaperImportTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Livewire\Teacher\LessonReport;
use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\QuizScore;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PaperImportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create(['teacher_id' => $this->teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizQuestion::create(['lesson_id' => $this->lesson->id, 'order' => 1, 'question' => 'Why X?', 'options' => ['Right', 'W1', 'W2', 'W3'], 'correct_index' => 0]);
        QuizQuestion::create(['lesson_id' => $this->lesson->id, 'order' => 2, 'question' => 'Why Z?', 'options' => ['W1', 'Right2', 'W2', 'W3'], 'correct_index' => 1]);

        $classroom = Classroom::create(['teacher_id' => $this->teacher->id, 'name' => '7B']);
        $this->lesson->classrooms()->attach($classroom->id, ['assigned_at' => now()]);
        ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'Emma V.']);
    }

    public function test_upload_extracts_review_rows_and_confirm_imports_scores(): void
    {
        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('describeImage')->once()->andReturn(json_encode([
                'sheets' => [
                    ['name' => 'Emma Visser', 'answers' => ['A', 'B']],
                    ['name' => 'Onbekend Kind', 'answers' => ['B', null]],
                ],
            ])));

        $component = Livewire::actingAs($this->teacher)
            ->test(LessonReport::class, ['lesson' => $this->lesson])
            ->set('paperPhotos', [UploadedFile::fake()->image('sheets.jpg')])
            ->call('extractPaper');

        $rows = $component->get('paperRows');
        $this->assertCount(2, $rows);
        $this->assertSame('Emma V.', $rows[0]['matched_name'], 'Fuzzy-matched to roster');
        $this->assertNull($rows[1]['matched_name'], 'Unknown kid stays unmatched (amber)');

        // Teacher fixes row 2's name + missing answer, then confirms.
        $component->set('paperRows.1.matched_name', 'Daan K.')
            ->set('paperRows.1.answers.1', 'C')
            ->call('confirmPaperImport');

        $this->assertSame(2, QuizScore::where('source', 'paper')->count());
        $emma = QuizScore::where('nickname', 'Emma V.')->sole();
        $this->assertSame(2, $emma->correct);              // A + B were both correct options
        $this->assertNotNull($emma->classroom_member_id);
        $this->assertCount(2, $emma->answers);
        $this->assertNull($emma->answers[0]->response_ms); // paper has no timing
    }
}
```

- [ ] **Step 2: Verify failure** — `vendor/bin/phpunit --filter PaperImportTest` → FAIL (properties missing).

- [ ] **Step 3: The extractor service**

`app/Services/PaperSheetExtractor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Services\Support\NameMatcher;
use Illuminate\Support\Collection;

/**
 * Reads photographed answer sheets with the vision model and prepares review rows.
 * Synchronous by design for v1 (a class's photos take seconds); wrap in a queued
 * job later if uploads grow. Every row is teacher-editable before import — the
 * model proposes, the teacher disposes.
 */
final class PaperSheetExtractor
{
    public function __construct(private readonly OpenAiLlmService $llm) {}

    /**
     * @param  list<string>  $imageDataUrls  base64 data URLs of the uploaded photos
     * @param  list<string>  $roster  classroom member display names for fuzzy matching
     * @return list<array{raw_name: string, matched_name: ?string, answers: list<?string>}>
     */
    public function extract(array $imageDataUrls, int $questionCount, array $roster): array
    {
        $rows = [];
        foreach ($imageDataUrls as $dataUrl) {
            $raw = $this->llm->describeImage($dataUrl, $this->instruction($questionCount));
            $parsed = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', trim($raw)), true);
            foreach ((array) ($parsed['sheets'] ?? []) as $sheet) {
                if (! is_array($sheet)) {
                    continue;
                }
                $answers = array_map(
                    fn ($a) => in_array($a, ['A', 'B', 'C', 'D'], true) ? $a : null,
                    array_pad(array_slice((array) ($sheet['answers'] ?? []), 0, $questionCount), $questionCount, null),
                );
                $rawName = trim((string) ($sheet['name'] ?? ''));
                $rows[] = [
                    'raw_name' => $rawName,
                    'matched_name' => NameMatcher::match($rawName, $roster),
                    'answers' => $answers,
                ];
            }
        }

        return $rows;
    }

    private function instruction(int $questionCount): string
    {
        return "These are filled-in quiz answer sheets. Each sheet has a handwritten name (top right) "
            ."and {$questionCount} questions, each with bubbles A B C D — the filled/circled bubble is "
            ."the answer. Return ONLY JSON: {\"sheets\":[{\"name\":\"...\",\"answers\":[\"A\"|\"B\"|\"C\"|\"D\"|null,...]}]} "
            ."with exactly {$questionCount} answers per sheet (null when unreadable/blank). "
            .'Multiple sheets may appear in one photo — return each separately.';
    }
}
```

- [ ] **Step 4: Livewire upload + review + confirm** — add to `LessonReport`:

```php
    use \Livewire\WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $paperPhotos = [];

    /** @var list<array{raw_name: string, matched_name: ?string, answers: list<?string>}> */
    public array $paperRows = [];

    public bool $paperModalOpen = false;

    public function extractPaper(): void
    {
        $this->validate(['paperPhotos.*' => ['image', 'max:10240']]);

        $questions = $this->lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();
        if ($questions->isEmpty()) {
            $this->dispatch('toast', message: __('This lesson has no quiz questions.'), type: 'warning');

            return;
        }

        $dataUrls = array_map(
            fn ($photo) => 'data:'.$photo->getMimeType().';base64,'.base64_encode($photo->get()),
            $this->paperPhotos,
        );

        $this->paperRows = app(\App\Services\PaperSheetExtractor::class)
            ->extract($dataUrls, $questions->count(), $this->rosterNames());
        $this->paperModalOpen = true;
    }

    /** @return list<string> */
    private function rosterNames(): array
    {
        return \App\Models\ClassroomMember::whereIn(
            'classroom_id', $this->lesson->classrooms()->pluck('classrooms.id'),
        )->pluck('display_name')->all();
    }

    public function confirmPaperImport(): void
    {
        $questions = $this->lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get()->values();
        $letters = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
        $memberByName = \App\Models\ClassroomMember::whereIn(
            'classroom_id', $this->lesson->classrooms()->pluck('classrooms.id'),
        )->get()->keyBy(fn ($m) => mb_strtolower($m->display_name));

        foreach ($this->paperRows as $row) {
            $name = \App\Services\Support\NameMatcher::canonical(
                (string) ($row['matched_name'] ?: $row['raw_name']),
            );
            if ($name === '') {
                continue;
            }

            $answers = [];
            $correct = 0;
            foreach ($questions as $index => $question) {
                $letter = $row['answers'][$index] ?? null;
                $chosenIndex = $letter !== null ? ($letters[$letter] ?? null) : null;
                $chosenText = $chosenIndex !== null ? (string) ($question->options[$chosenIndex] ?? '') : '';
                $wasCorrect = $chosenIndex !== null && $chosenIndex === (int) $question->correct_index;
                if ($wasCorrect) {
                    $correct++;
                }
                $answers[] = [
                    'quiz_question_id' => $question->id,
                    'question_order' => $index + 1,
                    'question_text' => $question->question,
                    'chosen_text' => $chosenText,
                    'correct_text' => (string) ($question->options[$question->correct_index] ?? ''),
                    'was_correct' => $wasCorrect,
                    'response_ms' => null,
                    'asks_ahead' => (bool) $question->asks_ahead,
                ];
            }

            $score = \App\Models\QuizScore::create([
                'lesson_id' => $this->lesson->id,
                'nickname' => $name,
                'classroom_member_id' => $memberByName[mb_strtolower($name)]->id ?? null,
                'score' => $correct * 10,
                'correct' => $correct,
                'total' => $questions->count(),
                'source' => 'paper',
            ]);
            $score->answers()->createMany($answers);
        }

        $this->paperRows = [];
        $this->paperPhotos = [];
        $this->paperModalOpen = false;
        $this->dispatch('toast', message: __('Paper answers imported.'), type: 'success');
    }
```

- [ ] **Step 5: The import modal** (append inside the root div of `lesson-report.blade.php`; button in the header next to CSV):

```blade
            <label for="paper-import" class="btn btn-sm bg-amber-500 text-slate-950 border-0 hover:bg-amber-400">📷 {{ __('Import paper answers') }}</label>
```

```blade
    {{-- Paper import: upload photos of filled answer sheets → review grid → confirm. --}}
    <div class="modal {{ $paperModalOpen || $paperPhotos ? 'modal-open' : '' }}">
        <div class="modal-box max-w-3xl">
            <h3 class="mb-3 text-lg font-semibold">📷 {{ __('Import paper answers') }}</h3>
            <input id="paper-import" type="file" wire:model="paperPhotos" multiple accept="image/*" class="file-input file-input-bordered w-full" />
            <button wire:click="extractPaper" wire:loading.attr="disabled" class="btn btn-sm mt-2 bg-amber-500 text-slate-950 border-0">
                <span wire:loading wire:target="extractPaper" class="loading loading-spinner loading-xs"></span>
                {{ __('Read sheets') }}
            </button>

            @if ($paperRows)
                <div class="mt-4 space-y-1.5">
                    @foreach ($paperRows as $i => $row)
                        <div class="flex items-center gap-2 rounded-xl border px-3 py-2
                                    {{ $row['matched_name'] ? 'border-success/40' : 'border-warning/50' }}">
                            <span>{{ $row['matched_name'] ? '✓' : '?' }}</span>
                            <input type="text" wire:model.blur="paperRows.{{ $i }}.matched_name"
                                   placeholder="{{ $row['raw_name'] ?: __('Name…') }}"
                                   class="input input-xs input-bordered w-36" />
                            <div class="flex flex-1 gap-1 font-mono">
                                @foreach ($row['answers'] as $qi => $answer)
                                    <select wire:model.blur="paperRows.{{ $i }}.answers.{{ $qi }}"
                                            class="select select-xs {{ $answer === null ? 'select-warning' : 'select-bordered' }}">
                                        <option value="">—</option>
                                        @foreach (['A','B','C','D'] as $letter)
                                            <option value="{{ $letter }}" @selected($answer === $letter)>{{ $letter }}</option>
                                        @endforeach
                                    </select>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <button wire:click="confirmPaperImport" class="btn mt-4 bg-amber-500 text-slate-950 border-0 hover:bg-amber-400">
                    {{ __('Confirm & import :n sheets', ['n' => count($paperRows)]) }}
                </button>
            @endif
            <div class="modal-action">
                <button wire:click="$set('paperModalOpen', false)" class="btn btn-ghost btn-sm">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
```

- [ ] **Step 6: Run** — `vendor/bin/phpunit --filter PaperImportTest` → PASS. (Note: `QuizQuestion` has no `asks_ahead`? It DOES — added in the chronology work; verify with `php artisan tinker --execute="echo Schema::hasColumn('quiz_questions','asks_ahead') ? 'yes' : 'no';"`.)

- [ ] **Step 7: Commit**

```bash
git add app/Services/PaperSheetExtractor.php app/Livewire/Teacher/LessonReport.php resources/views/livewire/teacher/lesson-report.blade.php tests/Feature/Teacher/PaperImportTest.php
git commit -m "feat(results): paper answer sheets - vision extraction + editable review grid + import"
```

---

### Task 11: Full-suite check + manual verification pass

**Files:** none (verification only)

- [ ] **Step 1: Full suite**

Run: `vendor/bin/phpunit`
Expected: only the pre-existing 7 errors + 11 failures (documented in plan notes). Any NEW failure = fix before proceeding.

- [ ] **Step 2: Build**

Run: `npm run build` → `✓ built`.

- [ ] **Step 3: Manual pass (composer dev running)**

1. Open a published lesson's report from the dashboard 📊 button → Overview renders.
2. Play the lesson's quiz in another tab with a class code → player appears on the report with member name.
3. Print the answer sheet (Chrome print preview looks clean, one page per sheet).
4. Photograph/scan a filled sheet (or photo of the screen), import, fix one name in the grid, confirm → 📄 row appears.
5. Click Re-quiz on a difficult question → new quiz scene at the end of the lesson timeline in Configure.

- [ ] **Step 4: Commit any manual-pass fixes, then final commit**

```bash
git add -A && git commit -m "chore(results): manual verification fixes" || echo "clean"
```
