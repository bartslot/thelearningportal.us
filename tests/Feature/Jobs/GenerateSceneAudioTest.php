<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateSceneAudio;
use App\Models\Lesson;
use App\Models\Narrator;
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
        $narrator = Narrator::create([
            'name' => 'Napoleon', 'slug' => 'napoleon-1', 'gender' => 'male',
            'voice_provider' => 'elevenlabs', 'voice_id' => 'voice-x',
            'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'avatar_id' => $narrator->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'script_segment' => 'Hello world.',
            'image_path' => 'x.png',
            'status' => 'generating',
        ]);

        $this->mock(TtsService::class, function ($mock): void {
            $mock->shouldReceive('prepareSpeechText')->andReturn('Hello world.');
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $provider, &$timing) {
                    // The shape ElevenLabsService really returns. This fixture used to say
                    // `start`/`end`, keys no provider ever produced, which is exactly why the
                    // duration bug below stayed green for months.
                    $timing = ['character_timings' => [
                        ['character' => 'H', 'start_time' => 0.0, 'end_time' => 0.1],
                        ['character' => 'i', 'start_time' => 0.1, 'end_time' => 7.4],
                    ]];

                    return 'BINARYMP3';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('mp3');
            $mock->shouldReceive('lastProvider')->andReturn('elevenlabs');
            $mock->shouldReceive('lastVoice')->andReturn('voice-x');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        $scene->refresh();
        $this->assertMatchesRegularExpression(
            '#^lessons/'.$lesson->id.'/scenes/'.$scene->id.'/narration\.mp3$#',
            (string) $scene->audio_path,
        );
        $this->assertSame(sha1('Hello world.'), $scene->audio_script_hash);
        $this->assertSame('ready', $scene->status);

        // The timings survive the round trip in the canonical shape. (Loose comparison: the JSON
        // column brings 0.0 back as int 0 — NarrationTiming casts it, consumers never see that.)
        $this->assertEquals(
            ['character' => 'H', 'start_time' => 0.0, 'end_time' => 0.1],
            $scene->audio_alignment[0],
        );
        // ...and the duration comes from the last end_time (7.4 -> 8), NOT the two-word
        // count estimate that the old `$last['end']` lookup silently fell back to.
        $this->assertSame(8, $scene->duration_seconds);
    }

    public function test_records_the_provider_that_actually_produced_the_audio(): void
    {
        $scene = $this->sceneWithElevenLabsNarrator('napoleon-3');

        $this->mock(TtsService::class, function ($mock): void {
            $mock->shouldReceive('prepareSpeechText')->andReturn('Hello world.');
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $provider, &$timing) {
                    $timing = ['character_timings' => [['character' => 'H', 'start_time' => 0.0, 'end_time' => 4.0]]];

                    return 'BINARYMP3';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('mp3');
            $mock->shouldReceive('lastProvider')->andReturn('elevenlabs');
            $mock->shouldReceive('lastVoice')->andReturn('voice-x');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        $scene->refresh();
        $this->assertSame('elevenlabs', $scene->audio_provider);
        $this->assertSame('voice-x', $scene->audio_voice);
        $this->assertNull($scene->error_message);
        $this->assertFalse($scene->narrationWasDowngraded());
    }

    public function test_flags_the_scene_when_the_chain_falls_through_to_another_provider(): void
    {
        // The lesson names an ElevenLabs narrator, but every rung above macOS `say` failed.
        // Before this, that produced a robot-voiced scene marked 'ready' with nothing to show
        // for it — the failure this whole fix exists to make impossible.
        $scene = $this->sceneWithElevenLabsNarrator('napoleon-4');

        $this->mock(TtsService::class, function ($mock): void {
            $mock->shouldReceive('prepareSpeechText')->andReturn('Hello world.');
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $provider, &$timing) {
                    $timing = null;   // `say` has no timings

                    return 'BINARYM4A';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('m4a');
            $mock->shouldReceive('lastProvider')->andReturn('macos_say');
            $mock->shouldReceive('lastVoice')->andReturn('say');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        $scene->refresh();
        $this->assertSame('macos_say', $scene->audio_provider);
        $this->assertTrue($scene->narrationWasDowngraded());
        $this->assertStringContainsString('macos_say', (string) $scene->error_message);
    }

    public function test_a_lesson_without_a_narrator_is_read_in_its_own_language_not_american(): void
    {
        // A lesson with avatar_id = NULL resolves to an empty voice id. Passed down the chain it
        // used to reach Azure's old 'en-US-GuyNeural' default, so a FRENCH lesson came back read
        // in US English — 14 live scenes did exactly that. Narration is never US-accented.
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id, 'avatar_id' => null,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Le vingt-quatre novembre, les navires aperçoivent enfin une côte inconnue dans la brume.',
            'image_path' => 'x.png', 'status' => 'generating',
        ]);

        $captured = [];
        $this->mock(TtsService::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('prepareSpeechText')->andReturnUsing(fn ($t) => $t);
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $provider, &$timing) use (&$captured) {
                    $captured = ['voiceId' => $voiceId, 'provider' => $provider];
                    $timing = null;

                    return 'BINARYMP3';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('mp3');
            $mock->shouldReceive('lastProvider')->andReturn('azure');
            $mock->shouldReceive('lastVoice')->andReturn('fr-FR-Remy:DragonHDLatestNeural');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        // A French script gets the French narrator, chosen before the request leaves the job.
        $this->assertSame('azure', $captured['provider']);
        $this->assertSame('fr-FR-Remy:DragonHDLatestNeural', $captured['voiceId']);
        $this->assertStringStartsNotWith('en-US-', $captured['voiceId']);

        // ...and the row records the voice that actually spoke, never an empty string.
        $scene->refresh();
        $this->assertSame('fr', $scene->audio_locale);
        $this->assertSame('fr-FR-Remy:DragonHDLatestNeural', $scene->audio_voice);
    }

    public function test_a_narrator_less_lesson_on_azure_is_not_called_a_downgrade(): void
    {
        // No avatar means no named narrator, so Azure on the native voice is the CORRECT outcome,
        // not a substitution. Calling it a downgrade flagged 77 correctly-narrated production
        // scenes; a warning that cries wolf is worth less than no warning.
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id, 'avatar_id' => null,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Hello world.', 'image_path' => 'x.png', 'status' => 'generating',
            // A stale warning left behind by an earlier, genuinely bad run.
            'error_message' => 'Narrated by azure, not the elevenlabs voice this lesson uses.',
        ]);

        $this->mockTts('azure', 'en-GB-Ollie:DragonHDLatestNeural');

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        $scene->refresh();
        $this->assertFalse($scene->narrationWasDowngraded());
        // A successful run clears the stale warning instead of leaving it forever.
        $this->assertNull($scene->error_message);
    }

    /** Mock TtsService to return audio from a given provider/voice with no timings. */
    private function mockTts(string $provider, string $voice): void
    {
        $this->mock(TtsService::class, function ($mock) use ($provider, $voice): void {
            $mock->shouldReceive('prepareSpeechText')->andReturnUsing(fn ($t) => $t);
            $mock->shouldReceive('generateAudioRaw')->andReturnUsing(
                function ($text, $voiceId, $speed, $p, &$timing) {
                    $timing = null;

                    return 'BINARYMP3';
                }
            );
            $mock->shouldReceive('lastExtension')->andReturn('mp3');
            $mock->shouldReceive('lastProvider')->andReturn($provider);
            $mock->shouldReceive('lastVoice')->andReturn($voice);
        });
    }

    private function sceneWithElevenLabsNarrator(string $slug): Scene
    {
        $narrator = Narrator::create([
            'name' => 'Napoleon', 'slug' => $slug, 'gender' => 'male',
            'voice_provider' => 'elevenlabs', 'voice_id' => 'voice-x',
            'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id, 'avatar_id' => $narrator->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
        ]);

        return Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Hello world.', 'image_path' => 'x.png', 'status' => 'generating',
        ]);
    }

    public function test_tts_override_forces_backup_provider_and_voice_over_narrator(): void
    {
        config()->set('services.tts.provider_override', 'azure');
        config()->set('services.tts.provider_override_voice', 'en-US-GuyNeural');

        $narrator = Narrator::create([
            'name' => 'Napoleon', 'slug' => 'napoleon-2', 'gender' => 'male',
            'voice_provider' => 'elevenlabs', 'voice_id' => 'eleven-voice-id',
            'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id, 'avatar_id' => $narrator->id,
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
            $mock->shouldReceive('lastProvider')->andReturn('azure');
            $mock->shouldReceive('lastVoice')->andReturn('en-US-GuyNeural');
        });

        (new GenerateSceneAudio($scene->id))->handle(app(TtsService::class));

        // The narrator is ElevenLabs, but the override routes to Azure with an Azure-native voice.
        $this->assertSame('azure', $captured['provider']);
        $this->assertSame('en-US-GuyNeural', $captured['voiceId']);

        // Azure delivering what the override asked for is not a downgrade — the override IS the
        // instruction, so no scene gets flagged just for honouring it.
        $scene->refresh();
        $this->assertSame('azure', $scene->audio_provider);
        $this->assertFalse($scene->narrationWasDowngraded());
        $this->assertNull($scene->error_message);
    }
}
