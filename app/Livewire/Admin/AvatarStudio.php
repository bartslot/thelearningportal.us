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
        $this->previewProvider   = $avatar->voice_provider;

        $this->greetingScript = $avatar->greeting_text ?? '';
    }

    // ── Computed ──────────────────────────────────────────────────────────────

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

    public function selectVoice(string $voiceId): void
    {
        $this->voice_id       = $voiceId;
        $this->voice_provider = $this->previewProvider;
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
                    'provider'    => $this->voice_provider,
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

        $this->voice_id    = $sample->voice_id;
        $this->voice_speed = $sample->voice_speed;

        $this->avatar->update([
            'voice_id'    => $sample->voice_id,
            'voice_speed' => $sample->voice_speed,
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
