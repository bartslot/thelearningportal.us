<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a scene counts as finished.
 *
 * A narration scene over a solid background has no image file and never will. Requiring one left it
 * at 'generating' after its audio arrived, which showed as a permanent spinner in the Script panel
 * and made publishing refuse the lesson for a scene that was in fact complete.
 */
class MarksSceneReadyTest extends TestCase
{
    use RefreshDatabase;
    use MarksSceneReady;

    private function scene(array $attributes = []): Scene
    {
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id,
            'topic' => 'The VOC',
            'subject' => 'history',
            'grade_level' => '6',
        ]);

        return Scene::create(array_merge([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'generating',
            'script_segment' => 'The VOC was the first multinational.',
        ], $attributes));
    }

    public function test_a_scene_over_a_background_colour_is_ready_once_it_has_audio(): void
    {
        $scene = $this->scene(['background_color' => '#0f172a', 'audio_path' => 'lessons/1/a.mp3']);

        $this->maybeMarkReady($scene);

        $this->assertSame('ready', $scene->fresh()->status);
    }

    public function test_a_scene_built_from_shot_layers_counts_as_having_a_visual(): void
    {
        $scene = $this->scene([
            'shots' => [['image_path' => 'lessons/1/shot.webp']],
            'audio_path' => 'lessons/1/a.mp3',
        ]);

        $this->maybeMarkReady($scene);

        $this->assertSame('ready', $scene->fresh()->status);
    }

    public function test_a_scene_with_an_image_is_still_ready(): void
    {
        $scene = $this->scene(['image_path' => 'lessons/1/scene.webp', 'audio_path' => 'lessons/1/a.mp3']);

        $this->maybeMarkReady($scene);

        $this->assertSame('ready', $scene->fresh()->status);
    }

    public function test_a_scene_without_audio_is_not_ready_yet(): void
    {
        $scene = $this->scene(['background_color' => '#0f172a']);

        $this->maybeMarkReady($scene);

        $this->assertSame('generating', $scene->fresh()->status);
    }

    public function test_a_scene_with_nothing_to_show_is_not_ready(): void
    {
        $scene = $this->scene(['audio_path' => 'lessons/1/a.mp3']);

        $this->maybeMarkReady($scene);

        $this->assertSame('generating', $scene->fresh()->status);
    }
}
