<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\EnhanceSkyboxImage;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnhanceSkyboxImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_upscayl_marks_status_failed_without_throwing(): void
    {
        Storage::fake('public');

        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X',
            'subject' => 'history',
            'grade_level' => '9th',
        ]);

        $path = "lessons/{$lesson->id}/scenes/1/skybox_panorama.png";
        Storage::disk('public')->put($path, 'ORIGINAL_BYTES');

        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'generating',
            'skybox_image_path' => $path,
        ]);

        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('enhance')
            ->once()
            ->andReturn('ORIGINAL_BYTES')
        );

        (new EnhanceSkyboxImage($scene->id))->handle(app(OpenAiImageService::class));

        $fresh = $scene->fresh();
        $this->assertSame('ready', $fresh->status);
        $this->assertSame('failed', $fresh->upscale_status);
        $this->assertNull($fresh->error_message);
        $this->assertSame('ORIGINAL_BYTES', Storage::disk('public')->get($path));
    }

    public function test_enhanced_bytes_overwrite_skybox_and_mark_done(): void
    {
        Storage::fake('public');

        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X',
            'subject' => 'history',
            'grade_level' => '9th',
        ]);

        $path = "lessons/{$lesson->id}/scenes/1/skybox_panorama.png";
        Storage::disk('public')->put($path, 'ORIGINAL_BYTES');

        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'generating',
            'skybox_image_path' => $path,
        ]);

        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('enhance')
            ->once()
            ->andReturn('ENHANCED_BYTES')
        );

        (new EnhanceSkyboxImage($scene->id))->handle(app(OpenAiImageService::class));

        $fresh = $scene->fresh();
        $this->assertSame('ready', $fresh->status);
        $this->assertSame('done', $fresh->upscale_status);
        $this->assertSame('ENHANCED_BYTES', Storage::disk('public')->get($path));
    }
}
