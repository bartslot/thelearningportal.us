<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishDueLessonsTest extends TestCase
{
    use RefreshDatabase;

    private function lessonWith(string $sceneStatus, ?\Carbon\Carbon $scheduledAt): Lesson
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create([
            'teacher_id' => $teacher->id,
            'status' => LessonStatus::Previewable,
            'scheduled_publish_at' => $scheduledAt,
        ]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'map', 'status' => $sceneStatus, 'config' => []]);

        return $lesson;
    }

    public function test_it_publishes_a_due_lesson_whose_scenes_are_ready(): void
    {
        $lesson = $this->lessonWith('ready', now()->subMinute());

        $this->artisan('lessons:publish-due')->assertsuccessful();

        $lesson->refresh();
        $this->assertSame(LessonStatus::Published, $lesson->status);
        $this->assertNull($lesson->scheduled_publish_at, 'schedule cleared after publishing');
    }

    public function test_it_leaves_a_future_scheduled_lesson_alone(): void
    {
        $lesson = $this->lessonWith('ready', now()->addDay());

        $this->artisan('lessons:publish-due')->assertSuccessful();

        $lesson->refresh();
        $this->assertNotSame(LessonStatus::Published, $lesson->status);
        $this->assertNotNull($lesson->scheduled_publish_at, 'still scheduled');
    }

    public function test_it_holds_a_due_lesson_with_an_unready_scene(): void
    {
        $lesson = $this->lessonWith('generating', now()->subMinute());

        $this->artisan('lessons:publish-due')->assertSuccessful();

        $lesson->refresh();
        $this->assertNotSame(LessonStatus::Published, $lesson->status, 'not published while a scene is unready');
        $this->assertNotNull($lesson->scheduled_publish_at, 'schedule kept so it can publish once ready');
    }

    public function test_it_ignores_lessons_with_no_schedule(): void
    {
        $lesson = $this->lessonWith('ready', null);

        $this->artisan('lessons:publish-due')->assertSuccessful();

        $this->assertNotSame(LessonStatus::Published, $lesson->refresh()->status);
    }
}
