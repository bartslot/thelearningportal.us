<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LessonStartPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_flips_lesson_to_source_ready_and_dispatches_outline_job(): void
    {
        Bus::fake();

        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'status' => LessonStatus::Draft,
        ]);
        LessonSource::create([
            'lesson_id'      => $lesson->id,
            'kind'           => 'wikipedia',
            'extracted_text' => 'x',
        ]);

        $lesson->startGenerationPipeline();

        $this->assertSame(LessonStatus::SourceReady, $lesson->fresh()->status);
        Bus::assertDispatched(BuildLessonOutline::class, fn ($job) => $job->lessonId === $lesson->id);
    }

    public function test_refuses_to_start_when_no_source_exists(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);

        $this->expectException(\RuntimeException::class);
        $lesson->startGenerationPipeline();
    }
}
