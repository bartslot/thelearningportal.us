<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateSceneImage;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSceneImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_an_image_and_stores_path_on_scene(): void
    {
        Storage::fake('public');

        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'image_prompt' => 'Paris dusk',
            'image_style' => 'cinematic',
            'status' => 'generating',
        ]);

        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock->shouldReceive('json')->andThrow(new \RuntimeException('validation unavailable'))
        );

        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('generatePanoramaBytesFromPrompt')
            ->withArgs(function (string $prompt, bool $isGame = false, ?string $size = null): bool {
                return $prompt !== ''
                    && $isGame === false
                    && is_string($size);
            })
            ->andReturn('IMGDATA')
        );

        (new GenerateSceneImage($scene->id))->handle(
            app(OpenAiImageService::class),
            app(OpenAiLlmService::class),
        );

        $this->assertSame(
            "lessons/{$lesson->id}/scenes/{$scene->id}/skybox.png",
            $scene->fresh()->image_path,
        );
        Storage::disk('public')->assertExists("lessons/{$lesson->id}/scenes/{$scene->id}/skybox.png");
    }

    public function test_game_scenes_include_game_visual_hint_in_prompt(): void
    {
        Storage::fake('public');

        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'game',
            'image_prompt' => 'Battle',
            'image_style' => 'painted',
            'status' => 'generating',
        ]);

        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock->shouldReceive('json')->andThrow(new \RuntimeException('validation unavailable'))
        );

        $captured = ['prompt' => null, 'isGame' => null];
        $this->mock(OpenAiImageService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generatePanoramaBytesFromPrompt')
                ->withArgs(function (string $prompt, bool $isGame = false, ?string $_size = null) use (&$captured): bool {
                    $captured['prompt'] = $prompt;
                    $captured['isGame'] = $isGame;

                    return true;
                })
                ->andReturn('IMGDATA');
        });

        (new GenerateSceneImage($scene->id))->handle(
            app(OpenAiImageService::class),
            app(OpenAiLlmService::class),
        );

        $this->assertFalse($captured['isGame']);
        $this->assertIsString($captured['prompt']);
        $this->assertStringContainsString('battle/scene illustration', (string) $captured['prompt']);
    }
}
