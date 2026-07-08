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
                    ['order' => 1, 'question' => 'Why did the coup matter, again?', 'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0],
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
