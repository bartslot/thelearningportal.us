<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Narrator;
use App\Models\NarratorVoiceSample;
use App\Services\ElevenLabsService;
use App\Services\TtsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class NarratorStudio extends Component
{
    use WithFileUploads;

    public Narrator $narrator;

    // ── Editable settings ─────────────────────────────────────────────────────

    #[Validate('required|string|max:100')]
    public string $name = '';

    #[Validate('nullable|string|max:100')]
    public string $short_name = '';

    #[Validate('nullable|string|max:100')]
    public string $avatar_title = '';

    #[Validate('nullable|string|max:500')]
    public string $description = '';

    #[Validate('required|string')]
    public string $voice_provider = 'elevenlabs';

    #[Validate('required|string')]
    public string $voice_id = 'es-ES-AlvaroNeural';

    #[Validate('required|numeric|min:0.5|max:2.0')]
    public float $voice_speed = 0.92;

    #[Validate('required|in:all,history,science,literature,civics')]
    public string $subject = 'all';

    public bool $is_active = true;

    // ── Voice studio state ────────────────────────────────────────────────────

    public bool $generating       = false;
    public string $generatingPhrase = '';
    public string $generatingVoice  = '';
    public ?string $flashMessage  = null;
    public bool $flashError       = false;

    // ── Portrait image upload (just the picture — a narrator is an image plus an ElevenLabs voice) ──
    #[Validate('nullable|image|max:4096')]
    public $portraitUpload = null;

    public bool $uploadingPortrait = false;

    // Custom sample controls
    #[Validate('nullable|string|max:300')]
    public string $customPhrase = '';

    public string $previewVoiceId    = '';
    public float  $previewVoiceSpeed = 0.92;
    public string $previewProvider   = 'elevenlabs';

    // ── Greeting script ───────────────────────────────────────────────────────

    #[Validate('nullable|string|max:500')]
    public string $greetingScript = '';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function mount(Narrator $narrator): void
    {
        $this->narrator = $narrator;

        $this->name          = $narrator->name;
        $this->short_name    = $narrator->short_name    ?? '';
        $this->avatar_title  = $narrator->avatar_title  ?? '';
        $this->description   = $narrator->description   ?? '';
        $this->voice_provider = $narrator->voice_provider;
        $this->voice_id       = $narrator->voice_id;
        $this->voice_speed    = $narrator->voice_speed;
        $this->subject        = $narrator->subject;
        $this->is_active      = $narrator->is_active;

        $this->previewVoiceId    = $narrator->voice_id;
        $this->previewVoiceSpeed = $narrator->voice_speed;
        // Provider tabs are full-page links (?provider=…) — see the blade comment.
        $requested = (string) request()->query('provider', '');
        $this->previewProvider = in_array($requested, ['elevenlabs', 'edge_tts', 'pocket_tts'], true)
            ? $requested
            : $narrator->voice_provider;

        $this->greetingScript = $narrator->greeting_text ?? '';
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    // ── Voice table state (sortable thead + per-language filter) ─────────────

    public string $languageFilter = 'featured';

    public string $sortBy = 'language';

    public string $sortDir = 'asc';

    #[Computed]
    public function voices(): array
    {
        return match ($this->previewProvider) {
            'elevenlabs' => app(ElevenLabsService::class)->getVoices(),
            'edge_tts'   => Narrator::edgeTtsVoicesForCards(),
            'pocket_tts' => Narrator::pocketTtsVoices(),
            default      => app(ElevenLabsService::class)->getVoices(),
        };
    }

    /**
     * Uniform rows for the voice table, both providers:
     * {id, name, language, lang, gender, flag, note, preview_url, featured}.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function voiceRows(): array
    {
        $rows = $this->previewProvider === 'edge_tts'
            ? $this->edgeRows()
            : $this->genericRows();

        $rows = array_values(array_filter($rows, fn (array $r) => match ($this->languageFilter) {
            'featured' => $r['featured'],
            'all'      => true,
            default    => $r['lang'] === $this->languageFilter,
        }));

        $key = in_array($this->sortBy, ['name', 'language', 'gender', 'note'], true) ? $this->sortBy : 'language';
        usort($rows, fn (array $a, array $b) => $this->sortDir === 'desc'
            ? strcasecmp((string) $b[$key], (string) $a[$key]) <=> 0
            : strcasecmp((string) $a[$key], (string) $b[$key]) <=> 0);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function edgeRows(): array
    {
        $featured = Narrator::edgeTtsFeatured();

        return array_map(function (array $v) use ($featured): array {
            $samplePath = "voices/edge/{$v['id']}.mp3";

            return [
                ...$v,
                'preview_url' => is_file(public_path($samplePath)) ? asset($samplePath) : '',
                'featured'    => in_array($v['id'], $featured, true),
            ];
        }, Narrator::edgeTtsCatalog());
    }

    /** Accent → display language + flag. ElevenLabs voices are all multilingual; the ACCENT is what matters. */
    private const ACCENT_LANGUAGES = [
        'french'     => ['Frans accent', '🇫🇷'],
        'british'    => ['Brits accent', '🇬🇧'],
        'american'   => ['Amerikaans accent', '🇺🇸'],
        'australian' => ['Australisch accent', '🇦🇺'],
        'irish'      => ['Iers accent', '🇮🇪'],
        'german'     => ['Duits accent', '🇩🇪'],
        'italian'    => ['Italiaans accent', '🇮🇹'],
        'spanish'    => ['Spaans accent', '🇪🇸'],
        'swedish'    => ['Zweeds accent', '🇸🇪'],
        'dutch'      => ['Nederlands accent', '🇳🇱'],
    ];

    /** ElevenLabs / Pocket cards adapted to table rows — grouped by ACCENT, not "multilingual". */
    private function genericRows(): array
    {
        return array_map(function (array $card): array {
            $label = (string) ($card['label'] ?? $card['id']);
            // "Roger - Laid-Back, Casual, Resonant · american male"
            $name = trim(Str::before($label, ' - ')) ?: $label;
            $meta = mb_strtolower(trim(Str::after($label, ' · ')));
            $note = trim(Str::between($label, ' - ', ' · '));

            $accent = trim(str_replace(['female', 'male', 'neutral'], '', $meta === mb_strtolower($label) ? '' : $meta));
            [$language, $flag] = self::ACCENT_LANGUAGES[$accent] ?? [$accent !== '' ? ucfirst($accent).' accent' : '—', '🌍'];

            return [
                'id'          => $card['id'],
                'name'        => $name,
                'language'    => $language,
                'lang'        => $accent !== '' ? $accent : 'other',
                'gender'      => str_contains($meta, 'female') ? 'V' : (str_contains($meta, 'male') ? 'M' : ''),
                'flag'        => $flag,
                // The note repeats the name for custom voices without metadata — drop it then.
                'note'        => ($note === $label || $note === $name) ? '' : $note,
                'preview_url' => (string) ($card['preview_url'] ?? ''),
                'featured'    => true, // curated lists are short — no featured tier needed
            ];
        }, $this->voices());
    }

    /** Distinct languages (edge) or accents (ElevenLabs) for the filter select. */
    #[Computed]
    public function voiceLanguages(): array
    {
        $rows = $this->previewProvider === 'edge_tts' ? $this->edgeRows() : $this->genericRows();

        $langs = [];
        foreach ($rows as $r) {
            $langs[$r['lang']] = $r['language'];
        }
        ksort($langs);

        return $langs;
    }

    public function sortTable(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';

            return;
        }
        $this->sortBy = $column;
        $this->sortDir = 'asc';
    }

    /**
     * Mark a voice as USED for a language. This is the single voice-selection action:
     * it sets the per-language narrator (voice_map, resolved by Narrator::voiceFor) AND
     * makes it the narrator's base/active voice. "Preferred" and "active" were two names
     * for practically the same thing — this merges them.
     */
    public function useVoice(string $lang, string $voiceId): void
    {
        $known = collect($this->voiceRows())->pluck('id')->all();
        if ($lang === '' || mb_strlen($lang) > 8 || ! in_array($voiceId, $known, true)) {
            return;
        }

        $map = $this->narrator->voice_map ?? [];
        // Ticking the voice already used for this language clears it.
        $wasUsed = ($map[$lang] ?? null) === $voiceId;
        $map[$lang] = $wasUsed ? null : $voiceId;
        $map = array_filter($map, fn ($v) => $v !== null);

        $updates = ['voice_map' => $map];
        if (! $wasUsed) {
            // Choosing a voice also makes it the narrator's base/active voice.
            $this->voice_id = $voiceId;
            $this->voice_provider = $this->previewProvider;
            $this->previewVoiceId = $voiceId;
            $updates['voice_id'] = $voiceId;
            $updates['voice_provider'] = $this->previewProvider;
        }

        $this->narrator->update($updates);
        $this->flash(__('Voice updated.'), false);
    }

    #[Computed]
    public function samplePhrases(): array
    {
        return Narrator::samplePhrases();
    }

    #[Computed]
    public function voiceSamples()
    {
        return $this->narrator->voiceSamples()->get();
    }

    // ── Save settings ─────────────────────────────────────────────────────────

    public function saveSettings(): void
    {
        $this->validate([
            'name'           => 'required|string|max:100',
            'short_name'     => 'nullable|string|max:100',
            'avatar_title'   => 'nullable|string|max:100',
            'description'    => 'nullable|string|max:500',
            'voice_provider' => 'required|string',
            'voice_id'       => 'required|string',
            'voice_speed'    => 'required|numeric|min:0.5|max:2.0',
            'subject'        => 'required|in:all,history,science,literature,civics',
        ]);

        $this->narrator->update([
            'name'           => $this->name,
            'short_name'     => $this->short_name ?: null,
            'avatar_title'   => $this->avatar_title ?: null,
            'description'    => $this->description,
            'voice_provider' => $this->voice_provider,
            'voice_id'       => $this->voice_id,
            'voice_speed'    => $this->voice_speed,
            'subject'        => $this->subject,
            'is_active'      => $this->is_active,
        ]);

        $this->flash('Settings saved.', false);
    }

    // ── Generate a single voice sample ────────────────────────────────────────

    public function generateSample(string $phrase, string $voiceId, float $speed): void
    {
        $this->generating       = true;
        $this->generatingPhrase = Str::limit($phrase, 40);
        $this->generatingVoice  = $voiceId;

        try {
            /** @var TtsService $tts */
            $tts = app(TtsService::class);
            $timingData = null;

            $audioContent = $tts->generateAudioRaw($phrase, $voiceId, $speed, $this->previewProvider, $timingData);

            if ($audioContent === null) {
                $this->flash('Audio generation failed — check TTS service.', true);
                return;
            }

            $voiceLabel = collect($this->voices())->firstWhere('id', $voiceId)['label'] ?? $voiceId;

            $ext      = $tts->lastExtension();
            $filename = 'avatar-samples/' . $this->narrator->id . '/' . Str::uuid() . '.' . $ext;
            Storage::disk('public')->put($filename, $audioContent);

            NarratorVoiceSample::create([
                'avatar_id'       => $this->narrator->id,
                'phrase'          => $phrase,
                'voice_id'        => $voiceId,
                'voice_speed'     => $speed,
                'audio_path'      => $filename,
                'audio_extension' => $ext,
                'settings_snapshot' => [
                    // The provider that actually SYNTHESIZED this sample (the active tab),
                    // not the narrator's saved provider — applyVoice() restores it from here.
                    'provider'    => $this->previewProvider,
                    'voice_id'    => $voiceId,
                    'speed'       => $speed,
                    'voice_label' => $voiceLabel,
                    'timing_data' => is_array($timingData) ? $timingData : [],
                ],
            ]);

            unset($this->voiceSamples); // clear computed cache
            $this->flash('Sample generated!', false);
        } catch (\Throwable $e) {
            Log::error('NarratorStudio: sample generation failed', ['error' => $e->getMessage()]);
            $this->flash('Error: ' . $e->getMessage(), true);
        } finally {
            $this->generating = false;
        }
    }

    /**
     * Generate a custom phrase with the currently selected preview voice.
     */
    public function generateCustomSample(): void
    {
        $this->validateOnly('customPhrase', ['customPhrase' => 'required|string|min:5|max:300']);

        $this->generateSample(
            $this->customPhrase,
            $this->previewVoiceId,
            $this->previewVoiceSpeed
        );
    }

    /**
     * Apply a sample's voice settings to the active narrator configuration.
     */
    public function applyVoice(int $sampleId): void
    {
        $sample = NarratorVoiceSample::findOrFail($sampleId);

        // The provider MUST follow the voice: an edge-tts voice id on a narrator still set
        // to elevenlabs breaks narration (was the bug — provider never updated here).
        $provider = (string) ($sample->settings_snapshot['provider'] ?? $this->previewProvider);

        $this->voice_id       = $sample->voice_id;
        $this->voice_speed    = $sample->voice_speed;
        $this->voice_provider = $provider;

        $this->narrator->update([
            'voice_provider' => $provider,
            'voice_id'       => $sample->voice_id,
            'voice_speed'    => $sample->voice_speed,
        ]);

        $this->flash("Voice set to: {$sample->label()}", false);
    }

    /**
     * Delete a voice sample.
     */
    public function deleteSample(int $sampleId): void
    {
        $sample = NarratorVoiceSample::findOrFail($sampleId);

        if ($sample->audio_path) {
            Storage::disk('public')->delete($sample->audio_path);
        }

        $sample->delete();
        unset($this->voiceSamples);
    }

    /**
     * Upload a new narrator portrait. Just stores the (resized) picture — no sprite/lip-sync
     * processing; a narrator is a static image plus an ElevenLabs voice.
     */
    public function uploadPortrait(): void
    {
        $this->validateOnly('portraitUpload', ['portraitUpload' => 'required|image|max:4096']);

        $this->uploadingPortrait = true;

        try {
            $imageBytes   = file_get_contents($this->portraitUpload->getRealPath());
            $resized      = app(\App\Services\NarratorService::class)->resizePortraitPublic($imageBytes);
            $portraitPath = "avatars/{$this->narrator->id}/portrait.jpg";
            Storage::disk('public')->put($portraitPath, $resized);

            $this->narrator->update(['portrait_path' => $portraitPath]);

            $this->portraitUpload = null;
            $this->flash('Portrait updated.', false);
        } catch (\Throwable $e) {
            Log::error('NarratorStudio: portrait upload failed', ['error' => $e->getMessage()]);
            $this->flash('Upload failed: ' . $e->getMessage(), true);
        } finally {
            $this->uploadingPortrait = false;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function flash(string $message, bool $isError): void
    {
        $this->flashMessage = $message;
        $this->flashError   = $isError;
    }

    public function render()
    {
        $this->narrator = $this->narrator->fresh();

        return view('livewire.admin.narrator-studio')
            ->layout('components.layouts.app', ['title' => 'Narrator Studio — ' . $this->narrator->name]);
    }
}
