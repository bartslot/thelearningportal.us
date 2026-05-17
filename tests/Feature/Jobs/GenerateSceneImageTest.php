<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateSceneImage;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateSceneImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_an_image_and_stores_path_on_scene(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic',
        ]);
        $scene = Scene::create([
            'lesson_id'    => $lesson->id,
            'order'        => 1,
            'kind'         => 'narration',
            'image_prompt' => 'Paris dusk',
            'image_style'  => 'cinematic',
            'status'       => 'generating',
        ]);

        $this->mock(OpenAiImageService::class, fn ($mock) =>
            $mock->shouldReceive('generate')
                ->withArgs(function ($seed, $style, $dest, $isGame = false) use ($lesson, $scene) {
                    return $seed === 'Paris dusk'
                        && $style === 'cinematic'
                        && $isGame === false
                        && str_starts_with($dest, "lessons/{$lesson->id}/scenes/{$scene->id}/");
                })
                ->andReturn("lessons/{$lesson->id}/scenes/{$scene->id}/skybox.png")
        );

        (new GenerateSceneImage($scene->id))->handle(app(OpenAiImageService::class));

        $this->assertSame(
            "lessons/{$lesson->id}/scenes/{$scene->id}/skybox.png",
            $scene->fresh()->image_path,
        );
    }

    public function test_passes_is_game_true_for_game_scenes(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id'    => $lesson->id,
            'order'        => 1,
            'kind'         => 'game',
            'image_prompt' => 'Battle',
            'image_style'  => 'painted',
            'status'       => 'generating',
        ]);

        $captured = ['isGame' => null];
        $this->mock(OpenAiImageService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')
                ->withArgs(function ($_s, $_st, $_d, $isGame = false) use (&$captured) {
                    $captured['isGame'] = $isGame;
                    return true;
                })
                ->andReturn('path.png');
        });

        (new GenerateSceneImage($scene->id))->handle(app(OpenAiImageService::class));

        $this->assertTrue($captured['isGame']);
    }
}
