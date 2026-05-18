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
}
