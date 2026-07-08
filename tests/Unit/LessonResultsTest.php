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

    public function test_legacy_scores_without_answer_snapshots_fall_back_to_aggregate(): void
    {
        // total=0, no answers → gradableRate is null → excluded from needs_help.
        $noTotal = QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Zero T.', 'score' => 0, 'correct' => 0, 'total' => 0]);

        // total=2, correct=1, no answer snapshots → falls back to correct/total = 50%, not < 50 so NOT needs-help.
        $legacy = QuizScore::create(['lesson_id' => $this->lesson->id, 'nickname' => 'Leg Acy.', 'score' => 10, 'correct' => 1, 'total' => 2]);

        $results = new LessonResults($this->lesson);
        $players = collect($results->players());

        $zeroRow = $players->firstWhere('name', 'Zero T.');
        $this->assertNull($zeroRow['pct']);
        $this->assertFalse($zeroRow['needs_help']);

        $legacyRow = $players->firstWhere('name', 'Leg Acy.');
        $this->assertSame(50, $legacyRow['pct']);
        $this->assertFalse($legacyRow['needs_help']);

        $overviewNeedsHelp = array_column($results->overview()['needs_help'], 'name');
        $this->assertNotContains('Zero T.', $overviewNeedsHelp);
        $this->assertNotContains('Leg Acy.', $overviewNeedsHelp);
    }

    public function test_avg_correct_pct_is_answer_weighted_not_player_averaged(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'Y', 'subject' => 'history',
            'grade_level' => '8', 'status' => LessonStatus::Published,
        ]);

        // Player A: 4 non-ahead answers, 3 correct.
        $a = QuizScore::create(['lesson_id' => $lesson->id, 'nickname' => 'A.', 'score' => 30, 'correct' => 3, 'total' => 4]);
        foreach ([true, true, true, false] as $i => $correct) {
            QuizAnswer::create([
                'quiz_score_id' => $a->id, 'question_order' => $i + 1, 'question_text' => "A{$i}?",
                'chosen_text' => 'x', 'correct_text' => 'x', 'was_correct' => $correct, 'asks_ahead' => false,
            ]);
        }

        // Player B: 1 non-ahead answer, 0 correct.
        $b = QuizScore::create(['lesson_id' => $lesson->id, 'nickname' => 'B.', 'score' => 0, 'correct' => 0, 'total' => 1]);
        QuizAnswer::create([
            'quiz_score_id' => $b->id, 'question_order' => 1, 'question_text' => 'B0?',
            'chosen_text' => 'x', 'correct_text' => 'y', 'was_correct' => false, 'asks_ahead' => false,
        ]);

        $overview = (new LessonResults($lesson))->overview();

        // 3 correct of 5 total answers → 60%, NOT mean(75%, 0%) = 38%.
        $this->assertSame(60, $overview['avg_correct_pct']);
    }
}
