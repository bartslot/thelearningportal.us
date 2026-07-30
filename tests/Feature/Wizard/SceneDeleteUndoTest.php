<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a scene from the rail, and taking it back.
 *
 * There is no confirm dialog on the way in — the toast offers Undo instead — so the undo has to be
 * exact: the scene returns to its old position, and the quiz questions that hung off it come with
 * it. A hard delete could not do that (quiz_questions.scene_id is nullOnDelete).
 */
class SceneDeleteUndoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Lesson} */
    private function lessonWithScenes(int $count = 4): array
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'The Dutch Revolt',
            'subject' => 'history',
            'grade_level' => '6',
        ]);

        foreach (range(1, $count) as $order) {
            Scene::create([
                'lesson_id' => $lesson->id,
                'order' => $order,
                'kind' => 'narration',
                'script_segment' => "Scene {$order}",
                'status' => 'ready',
            ]);
        }

        return [$teacher, $lesson];
    }

    private function orders(Lesson $lesson): array
    {
        return $lesson->scenes()->ordered()->pluck('script_segment', 'order')->all();
    }

    public function test_deleting_a_scene_closes_the_gap_in_the_running_order(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes();
        $second = $lesson->scenes()->ordered()->skip(1)->first();

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('deleteScene', $second->id);

        $this->assertSame([1 => 'Scene 1', 2 => 'Scene 3', 3 => 'Scene 4'], $this->orders($lesson));
        $this->assertTrue($second->fresh()->trashed(), 'the row survives so undo has something to restore');
    }

    public function test_the_delete_offers_undo_in_its_message(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes(2);
        $scene = $lesson->scenes()->ordered()->first();

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('deleteScene', $scene->id)
            ->assertDispatched('toast', function (string $event, array $params) {
                return $params['message'] === 'Scene deleted.'
                    && ($params['action']['event'] ?? null) === 'scene:undo-delete';
            });
    }

    public function test_undo_puts_the_scene_back_where_it_was(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes();
        $third = $lesson->scenes()->ordered()->skip(2)->first();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('deleteScene', $third->id)
            ->call('undoSceneDelete');

        $this->assertSame(
            [1 => 'Scene 1', 2 => 'Scene 2', 3 => 'Scene 3', 4 => 'Scene 4'],
            $this->orders($lesson),
        );
        $this->assertFalse($third->fresh()->trashed());
        $component->assertSet('deletedScene', null);
    }

    public function test_undo_keeps_the_scenes_quiz_questions(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes(2);
        $scene = $lesson->scenes()->ordered()->first();
        QuizQuestion::create([
            'lesson_id' => $lesson->id,
            'scene_id' => $scene->id,
            'question' => 'Who led the revolt?',
            'options' => ['William of Orange', 'Philip II'],
            'correct_index' => 0,
            'order' => 1,
        ]);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('deleteScene', $scene->id)
            ->call('undoSceneDelete');

        $this->assertSame(
            1,
            QuizQuestion::where('scene_id', $scene->id)->count(),
            'the question must still belong to the restored scene, not the lesson-wide pool',
        );
    }

    public function test_undo_does_nothing_when_no_scene_was_deleted(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes(3);
        $before = $this->orders($lesson);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('undoSceneDelete')
            ->call('undoSceneDelete');

        $this->assertSame($before, $this->orders($lesson));
    }

    /**
     * Cmd-Z routes through undoLastEdit, and the SERVER decides what that means. A pending delete
     * wins over a route edit — it is the newer change, and the page cannot make that call itself
     * without racing the response that tells it a delete happened.
     */
    public function test_cmd_z_takes_back_a_deleted_scene_before_anything_else(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes(3);
        $scene = $lesson->scenes()->ordered()->skip(1)->first();

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('deleteScene', $scene->id)
            ->call('undoLastEdit');

        $this->assertFalse($scene->fresh()->trashed());
        $this->assertSame([1 => 'Scene 1', 2 => 'Scene 2', 3 => 'Scene 3'], $this->orders($lesson));
    }

    /** A second delete replaces what undo will take back — it is one step deep, deliberately. */
    public function test_only_the_most_recent_delete_can_be_undone(): void
    {
        [$teacher, $lesson] = $this->lessonWithScenes();
        [$first, $second] = $lesson->scenes()->ordered()->take(2)->get()->all();

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('deleteScene', $first->id)
            ->call('deleteScene', $second->id)
            ->call('undoSceneDelete');

        $this->assertTrue($first->fresh()->trashed());
        $this->assertFalse($second->fresh()->trashed());
        $this->assertSame([1 => 'Scene 2', 2 => 'Scene 3', 3 => 'Scene 4'], $this->orders($lesson));
    }
}
