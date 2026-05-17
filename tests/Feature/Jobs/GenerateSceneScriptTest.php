<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateSceneScript;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateSceneScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_script_segment_and_keeps_status_generating_or_ready(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'Napoleon',
            'subject'     => 'history',
            'grade_level' => '9th grade',
            'tone'        => 'dramatic',
            'outline'     => ['scene_briefs' => [['beat' => 'opening']]],
        ]);
        LessonSource::create([
            'lesson_id'      => $lesson->id,
            'kind'           => 'wikipedia',
            'extracted_text' => 'Napoleon was born in 1769.',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order'     => 1,
            'kind'      => 'narration',
            'year'      => '1769',
            'location'  => 'Corsica',
            'status'    => 'pending',
        ]);

        $this->mock(OpenAiLlmService::class, fn ($mock) =>
            $mock->shouldReceive('text')->once()->andReturn('Napoleon was born on Corsica in 1769.')
        );

        (new GenerateSceneScript($scene->id))->handle(app(OpenAiLlmService::class));

        $scene->refresh();
        $this->assertSame('Napoleon was born on Corsica in 1769.', $scene->script_segment);
        $this->assertContains($scene->status, ['generating', 'ready']);
    }

    public function test_marks_scene_failed_on_llm_exception(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'Test', 'subject' => 'history', 'grade_level' => '9th',
            'outline'     => ['scene_briefs' => [['beat' => 'x']]],
        ]);
        LessonSource::create(['lesson_id' => $lesson->id, 'kind' => 'wikipedia', 'extracted_text' => 'x']);
        $scene = Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'pending']);

        $this->mock(OpenAiLlmService::class, fn ($mock) =>
            $mock->shouldReceive('text')->andThrow(new \RuntimeException('boom'))
        );

        try {
            (new GenerateSceneScript($scene->id))->handle(app(OpenAiLlmService::class));
        } catch (\Throwable) {
            // expected
        }

        $scene->refresh();
        $this->assertSame('failed', $scene->status);
        $this->assertStringContainsString('boom', (string) $scene->error_message);
    }
}
