<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8',
            'status' => LessonStatus::Published,
        ]);
    }

    public function test_submits_a_score_and_returns_rank_and_top_list(): void
    {
        QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Emma', 'score' => 50, 'correct' => 5, 'total' => 5]);
        QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Noah', 'score' => 20, 'correct' => 2, 'total' => 5]);

        $response = $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Sofie', 'score' => 35, 'correct' => 3, 'total' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('rank', 2)
            ->assertJsonPath('players', 3)
            ->assertJsonPath('top.0.nickname', 'Emma')
            ->assertJsonPath('top.1.nickname', 'Sofie');
    }

    public function test_forged_scores_are_clamped_to_the_earnable_maximum(): void
    {
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Cheater', 'score' => 999999, 'correct' => 50, 'total' => 5,
        ])->assertCreated();

        $entry = QuizScore::sole();
        $this->assertSame(5 * 15, $entry->score, 'Score caps at total × max points per question');
        $this->assertSame(5, $entry->correct, 'Correct caps at total');
    }

    public function test_leaderboard_is_public_and_scoped_to_the_lesson(): void
    {
        $other = Lesson::create([
            'teacher_id' => $this->lesson->teacher_id,
            'topic' => 'Rome', 'subject' => 'history', 'grade_level' => '8',
            'status' => LessonStatus::Published,
        ]);
        QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Emma', 'score' => 50, 'correct' => 5, 'total' => 5]);
        QuizScore::create(['lesson_id' => $other->id, 'nickname' => 'Ghost', 'score' => 75, 'correct' => 5, 'total' => 5]);

        $this->getJson("/lesson/{$this->lesson->lesson_code}/leaderboard")
            ->assertOk()
            ->assertJsonCount(1, 'top')
            ->assertJsonPath('top.0.nickname', 'Emma');
    }

    public function test_integrity_telemetry_is_stored_but_only_visible_to_the_owning_teacher(): void
    {
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Sofie', 'score' => 20, 'correct' => 2, 'total' => 5,
            'integrity' => ['avg_ms' => 1400, 'rapid_guesses' => 3, 'same_letter_streak' => 4, 'focus_drops' => 2],
        ])->assertCreated();

        $this->assertSame(3, QuizScore::sole()->integrity['rapid_guesses']);

        // Public (student) view: no integrity keys, ever.
        $this->getJson("/lesson/{$this->lesson->lesson_code}/leaderboard")
            ->assertOk()
            ->assertJsonMissingPath('top.0.integrity');

        // A different logged-in teacher is still "public".
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson("/lesson/{$this->lesson->lesson_code}/leaderboard")
            ->assertJsonMissingPath('top.0.integrity');

        // The lesson's own teacher sees the flags.
        $this->actingAs($this->lesson->teacher)
            ->getJson("/lesson/{$this->lesson->lesson_code}/leaderboard")
            ->assertJsonPath('top.0.integrity.rapid_guesses', 3)
            ->assertJsonPath('top.0.integrity.focus_drops', 2);
    }

    public function test_draft_lessons_have_no_leaderboard(): void
    {
        $this->lesson->update(['status' => LessonStatus::Draft]);

        $this->getJson("/lesson/{$this->lesson->lesson_code}/leaderboard")->assertNotFound();
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Emma', 'score' => 10, 'correct' => 1, 'total' => 5,
        ])->assertNotFound();
    }

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
}
