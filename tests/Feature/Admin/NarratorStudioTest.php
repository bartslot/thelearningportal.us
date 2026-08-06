<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\NarratorStudio;
use App\Models\Narrator;
use App\Models\NarratorVoiceSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NarratorStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Narrator $narrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->narrator = Narrator::create([
            'name' => 'Test Narrator', 'slug' => 'test-narrator',
            'voice_provider' => 'elevenlabs',
            'voice_id' => 'someElevenLabsId', 'voice_speed' => 1.0,
            'subject' => 'all', 'is_active' => true,
        ]);
    }

    public function test_edge_catalog_leads_with_dutch_voices(): void
    {
        $voices = array_keys(Narrator::edgeTtsVoices());

        $this->assertSame('nl-NL-FennaNeural', $voices[0], 'Dutch pilot: nl voices first');
        $this->assertContains('nl-NL-MaartenNeural', $voices);
        $this->assertContains('nl-BE-ArnaudNeural', $voices);
    }

    public function test_voice_for_resolves_per_language_preference_with_provider_guard(): void
    {
        $this->narrator->update([
            'voice_provider' => 'edge_tts',
            'voice_id' => 'en-GB-RyanNeural',
            'voice_map' => ['nl' => 'nl-NL-FennaNeural', 'en' => 'elevenlabs-id-123'],
        ]);
        $a = $this->narrator->fresh();

        $this->assertSame('nl-NL-FennaNeural', $a->voiceFor('nl'), 'Dutch lesson gets the ticked Dutch voice');
        $this->assertSame('en-GB-RyanNeural', $a->voiceFor('en'), 'ElevenLabs id on an edge narrator is ignored');
        $this->assertSame('en-GB-RyanNeural', $a->voiceFor('fr'), 'Unmapped language falls back to base voice');
        $this->assertSame('en-GB-RyanNeural', $a->voiceFor(null));
    }

    public function test_use_voice_ticks_and_unticks_per_language(): void
    {
        // setPreferred + selectVoice were merged into the single useVoice action.
        $studio = Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(NarratorStudio::class, ['narrator' => $this->narrator])
            ->set('languageFilter', 'all')
            ->call('useVoice', 'nl', 'nl-NL-ColetteNeural');

        $this->assertSame('nl-NL-ColetteNeural', $this->narrator->fresh()->voice_map['nl'] ?? null);

        // Ticking the same voice again unticks it (base voice is left as-is).
        $studio->call('useVoice', 'nl', 'nl-NL-ColetteNeural');
        $this->assertArrayNotHasKey('nl', $this->narrator->fresh()->voice_map ?? []);

        // Unknown voice ids are rejected.
        $studio->call('useVoice', 'nl', 'bogus-voice');
        $this->assertArrayNotHasKey('nl', $this->narrator->fresh()->voice_map ?? []);
    }

    public function test_voice_rows_filter_by_language_and_sort_by_column(): void
    {
        $studio = Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(NarratorStudio::class, ['narrator' => $this->narrator]);

        // Featured default: only the shortlist.
        $featured = $studio->get('voiceRows');
        $this->assertSame(count(Narrator::edgeTtsFeatured()), count($featured));

        // Dutch filter: exactly the 5 nl voices.
        $studio->set('languageFilter', 'nl');
        $this->assertCount(5, $studio->get('voiceRows'));

        // Sort by name desc puts Maarten first among nl voices sorted desc.
        $studio->call('sortTable', 'name');
        $studio->call('sortTable', 'name'); // toggle to desc
        $names = array_column($studio->get('voiceRows'), 'name');
        $sorted = $names;
        rsort($sorted, SORT_FLAG_CASE | SORT_STRING);
        $this->assertSame($sorted, $names);
    }

    public function test_featured_shortlist_exists_in_catalog_with_pregenerated_samples(): void
    {
        $catalog = Narrator::edgeTtsVoices();
        $cards = collect(Narrator::edgeTtsVoicesForCards());

        foreach (Narrator::edgeTtsFeatured() as $id) {
            $this->assertArrayHasKey($id, $catalog, "Featured voice {$id} missing from catalog");
            $card = $cards->firstWhere('id', $id);
            $this->assertTrue($card['featured']);
            $this->assertNotSame('', $card['preview_url'], "Featured voice {$id} has no pre-generated sample (run voices:samples)");
        }
        // Non-featured voices exist and are marked so (the "show all" tier).
        $this->assertTrue($cards->contains(fn ($c) => ! $c['featured']));
    }

    public function test_using_a_voice_persists_it_as_the_base_voice_immediately(): void
    {
        // useVoice doubles as voice selection: picking a voice for a language also
        // makes it the narrator's base/active voice (persisted immediately).
        Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(NarratorStudio::class, ['narrator' => $this->narrator])
            ->call('useVoice', 'nl', 'nl-NL-FennaNeural')
            ->assertSet('voice_id', 'nl-NL-FennaNeural')
            ->assertSet('voice_provider', 'edge_tts')
            ->assertSet('previewVoiceId', 'nl-NL-FennaNeural');

        $this->narrator->refresh();
        $this->assertSame('edge_tts', $this->narrator->voice_provider);
        $this->assertSame('nl-NL-FennaNeural', $this->narrator->voice_id);
        $this->assertSame('nl-NL-FennaNeural', $this->narrator->voice_map['nl'] ?? null);
    }

    public function test_apply_voice_updates_the_narrator_provider_too(): void
    {
        // Was the bug: applyVoice set voice_id but left the narrator on the old provider,
        // so an edge voice id was handed to ElevenLabs at narration time.
        $sample = NarratorVoiceSample::create([
            'avatar_id' => $this->narrator->id, 'phrase' => 'Welkom, leerlingen.',
            'voice_id' => 'nl-NL-FennaNeural', 'voice_speed' => 1.0,
            'audio_path' => 'avatar-samples/test.mp3', 'audio_extension' => 'mp3',
            'settings_snapshot' => ['provider' => 'edge_tts', 'voice_label' => 'Fenna'],
        ]);

        Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(NarratorStudio::class, ['narrator' => $this->narrator])
            ->call('applyVoice', $sample->id);

        $this->narrator->refresh();
        $this->assertSame('edge_tts', $this->narrator->voice_provider);
        $this->assertSame('nl-NL-FennaNeural', $this->narrator->voice_id);
    }

    public function test_provider_tab_comes_from_the_query_string(): void
    {
        Livewire::actingAs($this->admin)
            ->withQueryParams(['provider' => 'edge_tts'])
            ->test(NarratorStudio::class, ['narrator' => $this->narrator])
            ->assertSet('previewProvider', 'edge_tts');
    }
}
