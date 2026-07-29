<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * LessonPlayerController caches the whole eager-loaded player payload — including every scene — for
 * 30 minutes. Lesson saves bust that key, but SCENE saves did not, so a teacher who edited a scene
 * and pressed Play kept watching the previous version for up to half an hour.
 */
class PlayerCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    private Scene $scene;

    protected function setUp(): void
    {
        parent::setUp();

        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Columbus', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::Published,
        ]);
        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Original.', 'image_path' => 'a.png', 'audio_path' => 'a.mp3',
            'status' => 'ready',
        ]);
    }

    private function cacheKey(): string
    {
        return 'lesson.player.'.strtoupper($this->lesson->lesson_code);
    }

    private function warmCache(): void
    {
        Cache::put($this->cacheKey(), $this->lesson, now()->addMinutes(30));
        $this->assertTrue(Cache::has($this->cacheKey()), 'cache should be warm before the edit');
    }

    public function test_editing_a_scene_busts_the_cached_player_payload(): void
    {
        $this->warmCache();

        $this->scene->update(['script_segment' => 'Rewritten by the teacher.']);

        $this->assertFalse(Cache::has($this->cacheKey()));
    }

    public function test_changing_a_scene_background_busts_the_cached_player_payload(): void
    {
        $this->warmCache();

        // The exact edit that exposed this: a teacher-picked painting plus its portrait crop anchor.
        $this->scene->update([
            'image_path' => 'lessons/1/paintings/commons-abc.png',
            'config' => ['background_focus' => 'top'],
        ]);

        $this->assertFalse(Cache::has($this->cacheKey()));
    }

    public function test_adding_a_scene_busts_the_cached_player_payload(): void
    {
        $this->warmCache();

        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'gallery',
            'status' => 'ready', 'config' => ['images' => []],
        ]);

        $this->assertFalse(Cache::has($this->cacheKey()));
    }

    public function test_deleting_a_scene_busts_the_cached_player_payload(): void
    {
        $this->warmCache();

        $this->scene->delete();

        $this->assertFalse(Cache::has($this->cacheKey()));
    }
}
