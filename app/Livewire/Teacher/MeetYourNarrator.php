<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\Avatar;
use App\Models\AvatarTeacherFeedback;
use App\Services\TtsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MeetYourNarrator extends Component
{
    public bool $isGenerating = false;
    public bool $hasResponded = false;
    public bool $dismissed    = false;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function mount(): void
    {
        // If the teacher has already given feedback for the active avatar, hide the card
        $feedback = $this->feedback;
        if ($feedback && $feedback->hasResponded()) {
            $this->hasResponded = true;
        }
    }

    /**
     * Called automatically by wire:init — generates the greeting audio
     * the first time a teacher encounters this avatar.
     */
    public function generateGreeting(): void
    {
        $avatar   = $this->activeAvatar;
        $feedback = $this->feedback;

        if (! $avatar || ! $feedback) {
            return;
        }

        // Already generated — nothing to do
        if ($feedback->greeting_audio_path && Storage::disk('public')->exists($feedback->greeting_audio_path)) {
            return;
        }

        $this->isGenerating = true;

        try {
            $teacher   = auth()->user();
            $firstName = Str::of($teacher->name)->explode(' ')->first() ?? $teacher->name;
            $text      = $avatar->greetingText($firstName);

            /** @var TtsService $tts */
            $tts   = app(TtsService::class);
            $audio = $tts->generateAudioRaw(
                $text,
                $avatar->voice_id,
                $avatar->voice_speed,
                $avatar->voice_provider,
            );

            if ($audio) {
                $ext  = $tts->lastExtension(); // 'mp3' for edge-tts/kokoro, 'm4a' for macOS say
                $path = 'avatar-greetings/' . $avatar->id . '/' . $teacher->id . '.' . $ext;
                Storage::disk('public')->put($path, $audio);
                $feedback->update(['greeting_audio_path' => $path]);
            } else {
                Log::warning('MeetYourNarrator: no audio returned for greeting generation', [
                    'avatar_id' => $avatar->id,
                    'teacher_id' => $teacher->id,
                    'voice_provider' => $avatar->voice_provider,
                    'voice_id' => $avatar->voice_id,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('MeetYourNarrator: greeting generation failed', ['error' => $e->getMessage()]);
        } finally {
            $this->isGenerating = false;
            unset($this->feedback);  // clear computed cache so view reloads URL
        }
    }

    // ── Feedback actions ──────────────────────────────────────────────────────

    public function like(): void
    {
        $this->saveFeedback(true);
    }

    public function dislike(): void
    {
        $this->saveFeedback(false);
    }

    public function dismiss(): void
    {
        $this->dismissed = true;
    }

    private function saveFeedback(bool $liked): void
    {
        $feedback = $this->feedback;
        if ($feedback) {
            $feedback->update(['liked' => $liked]);
            $this->hasResponded = true;
        }
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    #[Computed]
    public function activeAvatar(): ?Avatar
    {
        return Avatar::active()->first();
    }

    #[Computed]
    public function feedback(): ?AvatarTeacherFeedback
    {
        $avatar = $this->activeAvatar;
        if (! $avatar) {
            return null;
        }

        return AvatarTeacherFeedback::firstOrCreate([
            'avatar_id'  => $avatar->id,
            'teacher_id' => auth()->id(),
        ]);
    }

    #[Computed]
    public function greetingText(): string
    {
        $avatar  = $this->activeAvatar;
        $teacher = auth()->user();

        if (! $avatar || ! $teacher) {
            return '';
        }

        $firstName = Str::of($teacher->name)->explode(' ')->first() ?? $teacher->name;
        return $avatar->greetingText($firstName);
    }

    public function render()
    {
        return view('livewire.teacher.meet-your-narrator');
    }
}
