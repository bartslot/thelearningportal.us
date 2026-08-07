<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One player, one line on the board, however many quizzes the lesson holds.
 *
 * A lesson can carry several quiz scenes — the Canon lessons open with "Before we start" and close
 * with "What did you learn?". QuizOverlay resets its running score to zero every time a quiz scene
 * opens, and the controller called QuizScore::create() unconditionally, so the same child finished a
 * lesson as TWO players with two partial scores. The board read like two strangers had each done
 * half the work, and no total was ever shown.
 *
 * A quiz scene now contributes ONE row per player, so a second attempt at the same quiz replaces its
 * own contribution rather than stacking on top of it — otherwise replaying a lesson would inflate a
 * score without limit, which in a classroom competition is the same as breaking it.
 */
class QuizLeaderboardAccumulationTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    private Scene $firstQuiz;

    private Scene $secondQuiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id,
            'title' => 'Abel Tasman', 'topic' => 'Abel Tasman', 'subject' => 'history',
            'grade_level' => '7', 'status' => 'published', 'language' => 'en',
        ]);
        $this->firstQuiz = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'game',
            'game_type' => 'quiz', 'status' => 'ready',
        ]);
        $this->secondQuiz = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 9, 'kind' => 'game',
            'game_type' => 'quiz', 'status' => 'ready',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function submit(string $nickname, int $score, ?Scene $scene, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('lesson.quiz-score', ['lessonCode' => $this->lesson->lesson_code]), array_merge([
            'nickname' => $nickname,
            'score' => $score,
            'correct' => 3,
            'total' => 4,
            'quiz_scene_id' => $scene?->id,
        ], $extra));
    }

    private function board(): array
    {
        return $this->getJson(route('lesson.leaderboard', ['lessonCode' => $this->lesson->lesson_code]))->json();
    }

    public function test_two_quizzes_in_one_lesson_make_one_player_with_the_total(): void
    {
        $this->submit('Sanne', 40, $this->firstQuiz)->assertCreated();
        $this->submit('Sanne', 35, $this->secondQuiz)->assertCreated();

        $board = $this->board();

        $this->assertCount(1, $board['top'], 'the same child appeared on the board twice');
        $this->assertSame('Sanne', $board['top'][0]['nickname']);
        $this->assertSame(75, $board['top'][0]['score'], 'the two quizzes were not added together');
        $this->assertSame(1, $board['players']);
    }

    public function test_the_questions_answered_add_up_too(): void
    {
        // "6 / 8" has to mean the whole lesson, not whichever quiz happened to be submitted last.
        $this->submit('Sanne', 40, $this->firstQuiz, ['correct' => 3, 'total' => 4]);
        $this->submit('Sanne', 35, $this->secondQuiz, ['correct' => 4, 'total' => 4]);

        $row = $this->board()['top'][0];

        $this->assertSame(7, $row['correct']);
        $this->assertSame(8, $row['total']);
    }

    public function test_redoing_the_same_quiz_replaces_that_score_and_does_not_stack(): void
    {
        // Otherwise replaying a lesson inflates a score without limit, and the competition is over.
        $this->submit('Sanne', 40, $this->firstQuiz);
        $this->submit('Sanne', 55, $this->firstQuiz);

        $board = $this->board();

        $this->assertCount(1, $board['top']);
        $this->assertSame(55, $board['top'][0]['score'], 'the retry stacked instead of replacing');
    }

    public function test_different_children_stay_different_players(): void
    {
        $this->submit('Sanne', 40, $this->firstQuiz);
        $this->submit('Joris', 60, $this->firstQuiz);

        $board = $this->board();

        $this->assertCount(2, $board['top']);
        $this->assertSame('Joris', $board['top'][0]['nickname'], 'the board is not ordered by total');
        $this->assertSame(2, $board['players']);
    }

    public function test_the_same_name_typed_differently_is_the_same_child(): void
    {
        // A child types "sanne" the second time. Two rows would be a bug they cannot see or fix.
        $this->submit('Sanne', 40, $this->firstQuiz);
        $this->submit('sanne', 35, $this->secondQuiz);

        $board = $this->board();

        $this->assertCount(1, $board['top']);
        $this->assertSame(75, $board['top'][0]['score']);
    }

    public function test_a_submission_without_a_scene_still_works(): void
    {
        // Older clients, and any lesson whose quiz is not scene-scoped, must keep working.
        $this->submit('Sanne', 40, null)->assertCreated();
        $this->submit('Sanne', 35, null)->assertCreated();

        $this->assertCount(1, $this->board()['top'], 'the fallback path created a second player');
    }

    public function test_the_rank_returned_is_the_rank_of_the_total(): void
    {
        $this->submit('Joris', 60, $this->firstQuiz);
        $this->submit('Sanne', 40, $this->firstQuiz);
        // Sanne's second quiz should put her ahead of Joris.
        $response = $this->submit('Sanne', 50, $this->secondQuiz);

        $this->assertSame(1, $response->json('rank'), 'rank was computed from one quiz, not the total');
    }

    public function test_each_quiz_keeps_its_own_row_for_the_teacher_report(): void
    {
        // The board aggregates, but per-quiz detail has to survive: LessonReport reads answers off
        // individual QuizScore rows, and collapsing them would erase which quiz a mistake was in.
        $this->submit('Sanne', 40, $this->firstQuiz);
        $this->submit('Sanne', 35, $this->secondQuiz);

        $this->assertSame(2, QuizScore::where('lesson_id', $this->lesson->id)->count());
    }
}
