<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A published lesson with no scenes must never reach a class.
 *
 * The wizard checks this once, at the moment of publishing (Step4Preview::allReady). Nothing
 * re-checks it afterwards, so a lesson whose scenes are later deleted stays published, stays in the
 * catalogue and still opens — to nothing. That is exactly what lesson FRREV9 was doing: listed in
 * the manifest, 200 from the player, not a single scene to show. The rule belongs at the boundary
 * that serves students, not only at the button that publishes.
 */
class CatalogEmptyLessonTest extends TestCase
{
    use RefreshDatabase;

    private function publishedLesson(int $scenes): Lesson
    {
        $lesson = Lesson::factory()->create([
            'teacher_id' => User::factory()->create()->id,
            'status' => LessonStatus::Published,
        ]);

        for ($i = 0; $i < $scenes; $i++) {
            Scene::create(['lesson_id' => $lesson->id, 'order' => $i + 1, 'kind' => 'narration', 'status' => 'ready']);
        }

        return $lesson->refresh();
    }

    public function test_the_catalogue_does_not_list_a_published_lesson_with_no_scenes(): void
    {
        $empty = $this->publishedLesson(0);
        $playable = $this->publishedLesson(3);

        $codes = collect($this->getJson('/api/v1/catalog/manifest')->json('lessons'))->pluck('code');

        $this->assertContains($playable->lesson_code, $codes->all());
        $this->assertNotContains($empty->lesson_code, $codes->all(), 'an empty lesson is not a lesson');
    }

    public function test_the_player_does_not_open_a_published_lesson_with_no_scenes(): void
    {
        $empty = $this->publishedLesson(0);

        $this->get("/lesson/{$empty->lesson_code}")->assertNotFound();
    }
}
