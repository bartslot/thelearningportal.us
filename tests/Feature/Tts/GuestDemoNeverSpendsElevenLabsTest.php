<?php

declare(strict_types=1);

namespace Tests\Feature\Tts;

use App\Jobs\GenerateSceneAudio;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A demo guest never spends ElevenLabs credits.
 *
 * `/try` hands an anonymous visitor a throwaway teacher account and a copy of the demo lesson in
 * the real wizard. Re-narrating a scene there is one click, the account costs nothing to create,
 * and nothing rate-limits doing it a thousand times. ElevenLabs is billed per character.
 *
 * The demo itself is unaffected: its narration is generated ahead of time by `lessons:compose`
 * under a real teacher, so a visitor hears the ElevenLabs recording. Only NEW audio a guest asks
 * for is downgraded to Azure.
 */
class GuestDemoNeverSpendsElevenLabsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Scene, 1: User} */
    private function sceneNarratedBy(bool $guest): array
    {
        $teacher = User::factory()->create(['is_guest_demo' => $guest]);
        $avatar = Avatar::factory()->create([
            'voice_provider' => 'elevenlabs',
            'voice_id' => 'Gwv6uO8RNVuOAN68JgUb',
            'voice_speed' => 1.0,
        ]);
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'avatar_id' => $avatar->id,
            'title' => 'Test', 'topic' => 'Test', 'subject' => 'history',
            'language' => 'en', 'grade_level' => '7',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'pending',
            'script_segment' => 'A sentence long enough to be worth narrating aloud.',
        ]);

        return [$scene, $teacher];
    }

    /** Capture the provider TtsService is asked for, without calling any real API. */
    private function providerUsedFor(Scene $scene): string
    {
        $seen = '';
        $tts = Mockery::mock(TtsService::class)->makePartial();
        $tts->shouldReceive('prepareSpeechText')->andReturnUsing(fn ($t) => $t);
        $tts->shouldReceive('generateAudioRaw')
            ->andReturnUsing(function ($text, $voice, $speed, $provider, &$timing) use (&$seen) {
                $seen = $provider;

                return null;   // no audio: the job gives up, which is all this test needs
            });
        $tts->shouldReceive('generatedAudioExtension')->andReturn('mp3');

        try {
            (new GenerateSceneAudio($scene->id))->handle($tts);
        } catch (\Throwable) {
            // The job may bail once no audio comes back — the provider was already chosen.
        }

        return $seen;
    }

    public function test_a_guest_demo_lesson_is_narrated_by_azure_not_elevenlabs(): void
    {
        config(['services.tts.provider_override' => '']);
        [$scene] = $this->sceneNarratedBy(guest: true);

        $this->assertSame('azure', $this->providerUsedFor($scene));
    }

    public function test_a_real_teachers_lesson_still_uses_the_narrators_own_provider(): void
    {
        // The downgrade must not leak onto paying work — this is the half that would go unnoticed.
        config(['services.tts.provider_override' => '']);
        [$scene] = $this->sceneNarratedBy(guest: false);

        $this->assertSame('elevenlabs', $this->providerUsedFor($scene));
    }

    public function test_the_global_override_still_wins_over_everything(): void
    {
        // TTS_PROVIDER_OVERRIDE is the emergency switch; a guest check must not disarm it.
        config(['services.tts.provider_override' => 'azure']);
        [$scene] = $this->sceneNarratedBy(guest: false);

        $this->assertSame('azure', $this->providerUsedFor($scene));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
