<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Avatar;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateAvatar extends Component
{
    use WithFileUploads;

    public int $step = 1;

    public string $name = '';

    public string $short_name = '';

    public string $avatar_title = '';

    public string $description = '';

    public string $subject = 'all';

    public $portrait = null;

    public $intro_video = null;

    /**
     * Which service speaks. Every narrator already in the catalogue is 'elevenlabs'; edge_tts is
     * the free local option, and the curated list below is its voices.
     */
    public string $voice_provider = 'elevenlabs';

    public string $voice_id = 'nl-NL-FennaNeural';

    /**
     * An ElevenLabs voice is a 20-character id from their dashboard rather than a name we can list:
     * a cloned voice belongs to one account and nothing enumerates it for us. So it is typed in.
     */
    public string $elevenlabs_voice_id = '';

    public float $voice_speed = 0.95;

    public bool $is_active = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'avatar_title' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'subject' => ['required', Rule::in(['all', 'history', 'science', 'literature', 'civics'])],
            'portrait' => ['required', 'image', 'max:4096'],
            'intro_video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
            'voice_provider' => ['required', Rule::in(['elevenlabs', 'edge_tts'])],
            // Only the chosen provider's field is required, so switching provider cannot leave the
            // form stuck on a validation error for a field the teacher can no longer see.
            'voice_id' => [Rule::requiredIf($this->voice_provider === 'edge_tts'), Rule::in(array_keys(Avatar::edgeTtsVoices()))],
            'elevenlabs_voice_id' => [
                Rule::requiredIf($this->voice_provider === 'elevenlabs'),
                'nullable', 'string', 'regex:/^[A-Za-z0-9]{20}$/',
            ],
            'voice_speed' => ['required', 'numeric', 'min:0.5', 'max:2'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'elevenlabs_voice_id.regex' => 'An ElevenLabs voice id is 20 letters and digits, copied from the voice in your ElevenLabs dashboard.',
        ];
    }

    /** The voice id actually stored, whichever provider was chosen. */
    private function chosenVoiceId(array $validated): string
    {
        return $this->voice_provider === 'elevenlabs'
            ? trim($validated['elevenlabs_voice_id'])
            : $validated['voice_id'];
    }

    public function nextStep(): void
    {
        $fields = match ($this->step) {
            1 => ['name', 'short_name', 'avatar_title', 'description', 'subject'],
            2 => ['portrait', 'intro_video'],
            3 => ['voice_provider', 'voice_id', 'elevenlabs_voice_id', 'voice_speed'],
            default => [],
        };

        if ($fields !== []) {
            $this->validateOnlyFields($fields);
        }

        $this->step = min(4, $this->step + 1);
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step < $this->step) {
            $this->step = $step;
        }
    }

    private function validateOnlyFields(array $fields): void
    {
        $rules = $this->rules();
        $this->validate(array_intersect_key($rules, array_flip($fields)));
    }

    public function createAvatar()
    {
        $validated = $this->validate();
        $slug = $this->uniqueSlug($validated['name']);
        $portraitPath = $this->portrait->store('avatars/portraits', 'public');
        $introVideoPath = null;
        $avatar = null;

        try {
            $avatar = Avatar::create([
                'name' => $validated['name'],
                'short_name' => $validated['short_name'] ?: null,
                'avatar_title' => $validated['avatar_title'] ?: null,
                'slug' => $slug,
                'description' => $validated['description'] ?: null,
                'portrait_path' => $portraitPath,
                'subject' => $validated['subject'],
                'voice_provider' => $this->voice_provider,
                'voice_id' => $this->chosenVoiceId($validated),
                'voice_speed' => $validated['voice_speed'],
                'voice_pitch' => 1.0,
                'is_active' => $validated['is_active'],
                'sort_order' => (int) Avatar::max('sort_order') + 1,
                'presentation_mode' => 'framed',
            ]);

            if ($this->intro_video) {
                $extension = strtolower($this->intro_video->getClientOriginalExtension()) ?: 'mp4';
                $introVideoPath = $this->intro_video->storeAs(
                    "avatar-introductions/{$avatar->id}",
                    "introduction.{$extension}",
                    'public'
                );
                $avatar->update(['intro_video_path' => $introVideoPath]);
            }
        } catch (\Throwable $exception) {
            $avatar?->forceDelete();
            Storage::disk('public')->delete($portraitPath);
            if ($introVideoPath) {
                Storage::disk('public')->delete($introVideoPath);
            }
            throw $exception;
        }

        session()->flash('success', "{$avatar->name} was created. You can fine-tune the narrator in the Studio.");

        return $this->redirectRoute('admin.avatars.studio', $avatar, navigate: true);
    }

    #[Computed]
    public function voices(): array
    {
        $featured = Avatar::edgeTtsFeatured();

        return array_values(array_filter(
            Avatar::edgeTtsCatalog(),
            fn (array $voice): bool => in_array($voice['id'], $featured, true)
        ));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'narrator';
        $slug = $base;
        $suffix = 2;

        while (Avatar::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function render()
    {
        return view('livewire.admin.create-avatar')
            ->layout('components.layouts.app', ['title' => 'Add avatar']);
    }
}
