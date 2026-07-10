<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateSceneAudio;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSceneAudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_produces_audio_alignment_and_marks_ready_when_all_assets_present(): void
    {
        $teacher = User::factory()->create();
        $avatar  = Avatar::create([
            'name' => 'Napoleon', 'slug' => 'napoleon-1', 'gender' => 'male',
            'voice_provider' => 'elevenlabs', 'voice_id' => 'voice-x',
            'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'avatar_id' => $avatar->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id'      => $lesson->id,
            'order'          => 1,
            'kind'           => 'narration',
            'script_segment' => 'Hello world.',
            'image_path'     => 'x.png',
            'status'         => 'generating',
        ]);

        $this->mock(TtsService::class, function ($mock): void {
            $mock->shouldReceive('prepareSpeechText')->andReturn('Hello world.');
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $provider, &$timing) {
                    $timing = ['character_timings' => [['character' => 'H', 'start' => 0.0, 'end' => 0.1]]];
                    return 'BINARYMP3';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('mp3');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        $scene->refresh();
        $this->assertMatchesRegularExpression(
            '#^lessons/' . $lesson->id . '/scenes/' . $scene->id . '/narration\.mp3$#',
            (string) $scene->audio_path,
        );
        $this->assertIsArray($scene->audio_alignment);
        $this->assertSame(sha1('Hello world.'), $scene->audio_script_hash);
        $this->assertSame('ready', $scene->status);
    }

    public function test_tts_override_forces_backup_provider_and_voice_over_avatar(): void
    {
        config()->set('services.tts.provider_override', 'azure');
        config()->set('services.tts.provider_override_voice', 'en-US-GuyNeural');

        $avatar = Avatar::create([
            'name' => 'Napoleon', 'slug' => 'napoleon-2', 'gender' => 'male',
            'voice_provider' => 'elevenlabs', 'voice_id' => 'eleven-voice-id',
            'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id, 'avatar_id' => $avatar->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Hello world.', 'image_path' => 'x.png', 'status' => 'generating',
        ]);

        $captured = [];
        $this->mock(TtsService::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('prepareSpeechText')->andReturn('Hello world.');
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $provider, &$timing) use (&$captured) {
                    $captured = ['voiceId' => $voiceId, 'provider' => $provider];
                    $timing = null;

                    return 'BINARYMP3';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('mp3');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        // The avatar is ElevenLabs, but the override routes to Azure with an Azure-native voice.
        $this->assertSame('azure', $captured['provider']);
        $this->assertSame('en-US-GuyNeural', $captured['voiceId']);
    }
}
