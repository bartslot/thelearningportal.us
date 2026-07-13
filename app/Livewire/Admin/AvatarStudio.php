<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Avatar;
use App\Models\AvatarVoiceSample;
use App\Services\ElevenLabsService;
use App\Services\TtsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class AvatarStudio extends Component
{
    use WithFileUploads;

    public Avatar $avatar;

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

    // ── Portrait image upload (just the picture — avatars are image + ElevenLabs voice) ──
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

    public function mount(Avatar $avatar): void
    {
        $this->avatar = $avatar;

        $this->name          = $avatar->name;
        $this->short_name    = $avatar->short_name    ?? '';
        $this->avatar_title  = $avatar->avatar_title  ?? '';
        $this->description   = $avatar->description   ?? '';
        $this->voice_provider = $avatar->voice_provider;
        $this->voice_id       = $avatar->voice_id;
        $this->voice_speed    = $avatar->voice_speed;
        $this->subject        = $avatar->subject;
        $this->is_active      = $avatar->is_active;

        $this->previewVoiceId    = $avatar->voice_id;
        $this->previewVoiceSpeed = $avatar->voice_speed;
        // Provider tabs are full-page links (?provider=…) — see the blade comment.
        $requested = (string) request()->query('provider', '');
        $this->previewProvider = in_array($requested, ['elevenlabs', 'edge_tts', 'pocket_tts'], true)
            ? $requested
            : $avatar->voice_provider;

        $this->greetingScript = $avatar->greeting_text ?? '';
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
            'edge_tts'   => Avatar::edgeTtsVoicesForCards(),
            'pocket_tts' => Avatar::pocketTtsVoices(),
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
        $featured = Avatar::edgeTtsFeatured();

        return array_map(function (array $v) use ($featured): array {
            $samplePath = "voices/edge/{$v['id']}.mp3";

            return [
                ...$v,
                'preview_url' => is_file(public_path($samplePath)) ? asset($samplePath) : '',
                'featured'    => in_array($v['id'], $featured, true),
            ];
        }, Avatar::edgeTtsCatalog());
    }

    /** ElevenLabs / Pocket cards adapted to table rows (multilingual → lang 'multi'). */
    private function genericRows(): array
    {
        return array_map(function (array $card): array {
            $label = (string) ($card['label'] ?? $card['id']);
            // "Roger - Laid-Back, Casual, Resonant · american male"
            $name = trim(Str::before($label, ' - ')) ?: $label;
            $meta = trim(Str::after($label, ' · '));
            $note = trim(Str::between($label, ' - ', ' · '));

            return [
                'id'          => $card['id'],
                'name'        => $name,
                'language'    => 'Multilingual',
                'lang'        => 'multi',
                'gender'      => str_contains($meta, 'female') ? 'V' : (str_contains($meta, 'male') ? 'M' : ''),
                'flag'        => '🌍',
                'note'        => $note !== $label ? trim($note.' — '.$meta, ' —') : $meta,
                'preview_url' => (string) ($card['preview_url'] ?? ''),
                'featured'    => true, // curated lists are short — no featured tier needed
            ];
        }, $this->voices());
    }

    /** Distinct languages for the filter select (edge only has real languages). */
    #[Computed]
    public function voiceLanguages(): array
    {
        if ($this->previewProvider !== 'edge_tts') {
            return [];
        }

        $langs = [];
        foreach (Avatar::edgeTtsCatalog() as $v) {
            $langs[$v['lang']] = $v['language'];
        }

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
     * Tick a voice as the avatar's preferred narrator for a language.
     * Narration resolves this via Avatar::voiceFor(lesson locale).
     */
    public function setPreferred(string $lang, string $voiceId): void
    {
        $known = collect($this->voiceRows())->pluck('id')->all();
        if (! in_array($voiceId, $known, true) || mb_strlen($lang) > 8) {
            return;
        }

        $map = $this->avatar->voice_map ?? [];
        // Ticking the already-preferred voice unticks it.
        $map[$lang] = ($map[$lang] ?? null) === $voiceId ? null : $voiceId;
        $map = array_filter($map, fn ($v) => $v !== null);

        $this->avatar->update(['voice_map' => $map]);
        $this->flash(__('Preferred voices updated.'), false);
    }

    public function selectVoice(string $voiceId): void
    {
        $this->voice_id       = $voiceId;
        $this->voice_provider = $this->previewProvider;

        // The sample-generation controls read the preview fields — without this sync,
        // "Generate" kept synthesizing with the previously selected voice.
        $this->previewVoiceId = $voiceId;

        // Clicking a card both auditions AND chooses: persist immediately so the
        // studio needs no separate save step for the voice.
        $this->avatar->update([
            'voice_provider' => $this->voice_provider,
            'voice_id'       => $voiceId,
        ]);

        $this->flash(__('Voice set to:').' '.(collect($this->voices())->firstWhere('id', $voiceId)['label'] ?? $voiceId), false);
    }

    #[Computed]
    public function samplePhrases(): array
    {
        return Avatar::samplePhrases();
    }

    #[Computed]
    public function voiceSamples()
    {
        return $this->avatar->voiceSamples()->get();
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

        $this->avatar->update([
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
            $filename = 'avatar-samples/' . $this->avatar->id . '/' . Str::uuid() . '.' . $ext;
            Storage::disk('public')->put($filename, $audioContent);

            AvatarVoiceSample::create([
                'avatar_id'       => $this->avatar->id,
                'phrase'          => $phrase,
                'voice_id'        => $voiceId,
                'voice_speed'     => $speed,
                'audio_path'      => $filename,
                'audio_extension' => $ext,
                'settings_snapshot' => [
                    // The provider that actually SYNTHESIZED this sample (the active tab),
                    // not the avatar's saved provider — applyVoice() restores it from here.
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
            Log::error('AvatarStudio: sample generation failed', ['error' => $e->getMessage()]);
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
     * Apply a sample's voice settings to the active avatar configuration.
     */
    public function applyVoice(int $sampleId): void
    {
        $sample = AvatarVoiceSample::findOrFail($sampleId);

        // The provider MUST follow the voice: an edge-tts voice id on an avatar still set
        // to elevenlabs breaks narration (was the bug — provider never updated here).
        $provider = (string) ($sample->settings_snapshot['provider'] ?? $this->previewProvider);

        $this->voice_id       = $sample->voice_id;
        $this->voice_speed    = $sample->voice_speed;
        $this->voice_provider = $provider;

        $this->avatar->update([
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
        $sample = AvatarVoiceSample::findOrFail($sampleId);

        if ($sample->audio_path) {
            Storage::disk('public')->delete($sample->audio_path);
        }

        $sample->delete();
        unset($this->voiceSamples);
    }

    /**
     * Upload a new avatar image. Just stores the (resized) picture — no sprite/lip-sync
     * processing; avatars are a static image + an ElevenLabs voice.
     */
    public function uploadPortrait(): void
    {
        $this->validateOnly('portraitUpload', ['portraitUpload' => 'required|image|max:4096']);

        $this->uploadingPortrait = true;

        try {
            $imageBytes   = file_get_contents($this->portraitUpload->getRealPath());
            $resized      = app(\App\Services\AvatarService::class)->resizePortraitPublic($imageBytes);
            $portraitPath = "avatars/{$this->avatar->id}/portrait.jpg";
            Storage::disk('public')->put($portraitPath, $resized);

            $this->avatar->update(['portrait_path' => $portraitPath]);

            $this->portraitUpload = null;
            $this->flash('Portrait updated.', false);
        } catch (\Throwable $e) {
            Log::error('AvatarStudio: portrait upload failed', ['error' => $e->getMessage()]);
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
        $this->avatar = $this->avatar->fresh();

        return view('livewire.admin.avatar-studio')
            ->layout('components.layouts.app', ['title' => 'Avatar Studio — ' . $this->avatar->name]);
    }
}
