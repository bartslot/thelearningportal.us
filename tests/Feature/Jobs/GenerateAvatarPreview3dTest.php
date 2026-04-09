<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Exceptions\AzureTtsException;
use App\Jobs\GenerateAvatarPreview3d;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Services\AzureTtsService;
use App\Services\SsmlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateAvatarPreview3dTest extends TestCase
{
    use RefreshDatabase;

    public function test_caches_ready_status_on_success(): void
    {
        Storage::fake('local');

        $avatar = Avatar::factory()->create([
            'gender'         => 'male',
            'age'            => 35,
            'emotion_style'  => 'auto',
            'expressiveness' => 1.2,
            'speaking_speed' => 1.0,
        ]);

        $this->mock(AzureTtsService::class, function ($mock) use ($avatar) {
            $mock->shouldReceive('synthesize')
                ->once()
                ->andReturn([
                    'audio_3d_path'    => "previews/{$avatar->id}/audio_3d.mp3",
                    'blendshapes_path' => "previews/{$avatar->id}/blendshapes.json",
                ]);
        });

        (new GenerateAvatarPreview3d($avatar->id, 'Hello world.'))->handle(
            app(SsmlBuilder::class),
            app(AzureTtsService::class),
        );

        $cached = Cache::get("avatar3d_preview_{$avatar->id}");
        $this->assertSame('ready', $cached['status']);
        $this->assertNotEmpty($cached['audio_url']);
        $this->assertNotEmpty($cached['blendshapes_url']);
    }

    public function test_caches_failed_status_on_exception(): void
    {
        $avatar = Avatar::factory()->create();

        $job = new GenerateAvatarPreview3d($avatar->id, 'Hello.');
        $job->failed(new AzureTtsException('Azure returned 401'));

        $cached = Cache::get("avatar3d_preview_{$avatar->id}");
        $this->assertSame('failed', $cached['status']);
        $this->assertStringContainsString('401', $cached['error']);
    }

    public function test_updates_lesson_when_lesson_id_provided(): void
    {
        Storage::fake('local');

        $avatar = Avatar::factory()->create();
        $lesson = Lesson::factory()->create();

        $this->mock(AzureTtsService::class, function ($mock) use ($lesson) {
            $mock->shouldReceive('synthesize')
                ->once()
                ->andReturn([
                    'audio_3d_path'    => "lessons/{$lesson->id}/audio_3d.mp3",
                    'blendshapes_path' => "lessons/{$lesson->id}/blendshapes.json",
                ]);
        });

        (new GenerateAvatarPreview3d($avatar->id, 'Text.', $lesson->id))->handle(
            app(SsmlBuilder::class),
            app(AzureTtsService::class),
        );

        $this->assertDatabaseHas('lessons', [
            'id'               => $lesson->id,
            'audio_3d_path'    => "lessons/{$lesson->id}/audio_3d.mp3",
            'blendshapes_path' => "lessons/{$lesson->id}/blendshapes.json",
        ]);
    }
}
