<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\SlideshowAndIntelDropBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlideshowToScenesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_converts_slideshow_images_into_narration_scenes_with_order_preserved(): void
    {
        $teacher = User::factory()->create();

        $lessonId = DB::table('lessons')->insertGetId([
            'teacher_id'       => $teacher->id,
            'topic'            => 'Legacy',
            'subject'          => 'history',
            'grade_level'      => '9th',
            'slideshow_images' => json_encode([
                ['url' => '/img1.jpg', 'title' => 'Scene 1'],
                ['url' => '/img2.jpg', 'title' => 'Scene 2'],
            ]),
            'status'           => 'ready',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        (new SlideshowAndIntelDropBackfill())->backfill();

        $scenes = Scene::where('lesson_id', $lessonId)->orderBy('order')->get();

        $this->assertCount(2, $scenes);
        $this->assertSame('narration', $scenes[0]->kind);
        $this->assertSame('/img1.jpg', $scenes[0]->image_path);
        $this->assertSame(2, $scenes[1]->order);
    }

    public function test_expands_intel_drop_into_a_game_split_of_two_with_the_intel_script_seeded(): void
    {
        $teacher = User::factory()->create();
        $lessonId = DB::table('lessons')->insertGetId([
            'teacher_id'             => $teacher->id,
            'topic'                  => 'Intel test',
            'subject'                => 'history',
            'grade_level'            => '9th',
            'intel_drop_enabled'     => 1,
            'intel_drop_at_minutes'  => 5,
            'intel_drop_script'      => 'New intel: enemy is regrouping.',
            'status'                 => 'ready',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        (new SlideshowAndIntelDropBackfill())->backfill();

        $lesson = Lesson::find($lessonId);
        $this->assertSame(2, $lesson->game_split_count);

        $sceneScripts = Scene::where('lesson_id', $lessonId)->pluck('script_segment')->all();
        $this->assertContains('New intel: enemy is regrouping.', $sceneScripts);
    }
}
