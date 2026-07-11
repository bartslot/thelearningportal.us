<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AvatarStudio;
use App\Models\Avatar;
use App\Models\AvatarVoiceSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AvatarStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Avatar $avatar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->avatar = Avatar::create([
            'name' => 'Test Narrator', 'slug' => 'test-narrator',
            'voice_provider' => 'elevenlabs',
            'voice_id' => 'someElevenLabsId', 'voice_speed' => 1.0,
            'subject' => 'all', 'is_active' => true,
        ]);
    }

    public function test_edge_catalog_leads_with_dutch_voices(): void
    {
        $voices = array_keys(Avatar::edgeTtsVoices());

        $this->assertSame('nl-NL-FennaNeural', $voices[0], 'Dutch pilot: nl voices first');
        $this->assertContains('nl-NL-MaartenNeural', $voices);
        $this->assertContains('nl-BE-ArnaudNeural', $voices);
    }

    public function test_selecting_a_voice_syncs_provider_and_preview_state(): void
    {
        Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(AvatarStudio::class, ['avatar' => $this->avatar])
            ->call('selectVoice', 'nl-NL-FennaNeural')
            ->assertSet('voice_id', 'nl-NL-FennaNeural')
            ->assertSet('voice_provider', 'edge_tts')
            ->assertSet('previewVoiceId', 'nl-NL-FennaNeural');
    }

    public function test_apply_voice_updates_the_avatar_provider_too(): void
    {
        // Was the bug: applyVoice set voice_id but left the avatar on the old provider,
        // so an edge voice id was handed to ElevenLabs at narration time.
        $sample = AvatarVoiceSample::create([
            'avatar_id' => $this->avatar->id, 'phrase' => 'Welkom, leerlingen.',
            'voice_id' => 'nl-NL-FennaNeural', 'voice_speed' => 1.0,
            'audio_path' => 'avatar-samples/test.mp3', 'audio_extension' => 'mp3',
            'settings_snapshot' => ['provider' => 'edge_tts', 'voice_label' => 'Fenna'],
        ]);

        Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(AvatarStudio::class, ['avatar' => $this->avatar])
            ->call('applyVoice', $sample->id);

        $this->avatar->refresh();
        $this->assertSame('edge_tts', $this->avatar->voice_provider);
        $this->assertSame('nl-NL-FennaNeural', $this->avatar->voice_id);
    }

    public function test_provider_tab_comes_from_the_query_string(): void
    {
        Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(AvatarStudio::class, ['avatar' => $this->avatar])
            ->assertSet('previewProvider', 'edge_tts');
    }
}
