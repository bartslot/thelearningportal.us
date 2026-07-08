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

    public function test_csv_export_neutralizes_formula_injection_in_nickname(): void
    {
        QuizScore::create([
            'lesson_id' => $this->lesson->id, 'nickname' => '=1+1', 'score' => 10, 'correct' => 1, 'total' => 1,
        ]);

        $response = Livewire::actingAs($this->teacher)
            ->test(LessonReport::class, ['lesson' => $this->lesson])
            ->call('exportCsv');

        $csv = base64_decode((string) data_get($response->effects, 'download.content'));

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertDoesNotMatchRegularExpression('/(?:^|,)=1\+1(?:,|$)/m', $csv);
    }
}
