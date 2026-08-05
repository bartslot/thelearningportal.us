<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Opening the editor must not take a lesson off the air.
 *
 * Step3SceneConfigurator::mount() wrote status => Configuring unconditionally, so a teacher who
 * opened a PUBLISHED lesson just to look at it unpublished it — silently, on a GET, with no
 * warning and nothing in the interface to say so. Every student link for that lesson then
 * resolved to nothing, because the player only serves published lessons.
 *
 * Editing is not unpublishing. A lesson leaves the air when a teacher says so.
 */
class EditingKeepsPublishedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Lesson} */
    private function lesson(LessonStatus $status): array
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'The Dutch Revolt',
            'subject' => 'history',
            'grade_level' => '6',
            'status' => $status,
        ]);

        Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'script_segment' => 'Scene 1',
            'status' => 'ready',
        ]);

        return [$teacher, $lesson];
    }

    public function test_opening_the_editor_leaves_a_published_lesson_published(): void
    {
        [$teacher, $lesson] = $this->lesson(LessonStatus::Published);

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        $this->assertSame(
            LessonStatus::Published,
            $lesson->refresh()->status,
            'opening the editor took a published lesson off the air',
        );
    }

    public function test_the_student_player_still_finds_the_lesson_after_the_teacher_opens_it(): void
    {
        [$teacher, $lesson] = $this->lesson(LessonStatus::Published);

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        // The consequence that actually matters: the shared link keeps working.
        $this->get(route('lesson.play', ['lessonCode' => $lesson->lesson_code]))->assertOk();
    }

    public function test_opening_the_editor_still_records_which_step_the_teacher_is_on(): void
    {
        [$teacher, $lesson] = $this->lesson(LessonStatus::Published);

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        $this->assertSame(4, $lesson->refresh()->wizard_step);
    }

    public function test_an_unfinished_lesson_is_still_moved_into_configuring(): void
    {
        // A draft SHOULD advance: that is how the wizard remembers where the teacher got to.
        [$teacher, $lesson] = $this->lesson(LessonStatus::Draft);

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        $this->assertSame(LessonStatus::Configuring, $lesson->refresh()->status);
    }
}
