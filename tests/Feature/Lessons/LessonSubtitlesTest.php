<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Narration subtitles.
 *
 * The teacher sets whether a lesson STARTS with them showing — a class that needs captions should
 * have them from the first scene, not once someone finds the button. The player reads that
 * starting state; students can still toggle it while it plays.
 */
class LessonSubtitlesTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(User $teacher): Lesson
    {
        $lesson = Lesson::factory()->create([
            'teacher_id' => $teacher->id,
            'status' => LessonStatus::Published->value,
        ]);
        // The player only opens a lesson that has something to play (see CatalogEmptyLessonTest);
        // these tests are about what the payload carries, so give them a lesson worth opening.
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);

        return $lesson->refresh();
    }

    public function test_a_lesson_starts_without_subtitles_unless_the_teacher_asks_for_them(): void
    {
        // fresh() because the column's default is applied by the database, not by the model.
        $this->assertFalse($this->lesson(User::factory()->create())->fresh()->subtitles);
    }

    public function test_the_teacher_can_turn_them_on_and_off_again(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->lesson($teacher);

        $component = Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        $component->call('setSubtitles', true);
        $this->assertTrue($lesson->fresh()->subtitles);

        $component->call('setSubtitles', false);
        $this->assertFalse($lesson->fresh()->subtitles);
    }

    public function test_the_player_is_told_the_starting_state(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->lesson($teacher);
        $lesson->update(['subtitles' => true]);

        $this->get(route('lesson.play', ['lessonCode' => $lesson->lesson_code]))
            ->assertOk()
            ->assertSee('"subtitles":true', false);
    }
}
