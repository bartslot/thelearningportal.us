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

    public function test_draft_lessons_have_no_leaderboard(): void
    {
        $this->lesson->update(['status' => LessonStatus::Draft]);

        $this->getJson("/lesson/{$this->lesson->lesson_code}/leaderboard")->assertNotFound();
        $this->postJson("/lesson/{$this->lesson->lesson_code}/quiz-score", [
            'nickname' => 'Emma', 'score' => 10, 'correct' => 1, 'total' => 5,
        ])->assertNotFound();
    }
}
