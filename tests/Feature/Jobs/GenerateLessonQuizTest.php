<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\LessonStatus;
use App\Jobs\GenerateLessonQuiz;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateLessonQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_quiz_rows_and_transitions_to_scenes_ready(): void
    {
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

        $this->assertSame(1, QuizQuestion::where('lesson_id', $lesson->id)->count());
        $this->assertSame(LessonStatus::ScenesReady, $lesson->fresh()->status);
    }

    public function test_retries_once_when_an_objective_is_left_untested(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'status' => LessonStatus::ScenesGenerating,
            'outline' => [
                'learning_objectives' => [
                    ['id' => 'LO1', 'text' => 'Explain A.'],
                    ['id' => 'LO2', 'text' => 'Explain B.'],
                ],
            ],
        ]);
        Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Napoleon ruled France.', 'status' => 'ready',
        ]);

        $this->mock(OpenAiLlmService::class, fn ($mock) =>
            $mock->shouldReceive('json')->twice()->andReturn(
                // Attempt 1: LO2 never tested → rejected.
                ['questions' => [
                    ['order' => 1, 'objective_id' => 'LO1', 'question' => 'Why did A happen?', 'options' => ['a','b','c','d'], 'correct_index' => 0],
                ]],
                // Attempt 2: both objectives covered → accepted.
                ['questions' => [
                    ['order' => 1, 'objective_id' => 'LO1', 'question' => 'Why did A happen?', 'options' => ['a','b','c','d'], 'correct_index' => 0],
                    ['order' => 2, 'objective_id' => 'LO2', 'question' => 'What did B cause?', 'options' => ['a','b','c','d'], 'correct_index' => 1],
                ]],
            )
        );

        (new GenerateLessonQuiz($lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(2, QuizQuestion::where('lesson_id', $lesson->id)->count());
    }

    public function test_drops_structurally_broken_questions(): void
    {
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
                    ['order' => 1, 'question' => 'Valid?', 'options' => ['a','b','c','d'], 'correct_index' => 0],
                    ['order' => 2, 'question' => 'Three options only', 'options' => ['a','b','c'], 'correct_index' => 0],
                    ['order' => 3, 'question' => 'Answer out of range', 'options' => ['a','b','c','d'], 'correct_index' => 7],
                    ['order' => 4, 'question' => '', 'options' => ['a','b','c','d'], 'correct_index' => 0],
                ],
            ])
        );

        (new GenerateLessonQuiz($lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(1, QuizQuestion::where('lesson_id', $lesson->id)->count());
    }
}
