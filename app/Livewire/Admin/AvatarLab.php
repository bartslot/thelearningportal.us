<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Jobs\GenerateAvatarPreview3d;
use App\Models\Avatar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

class AvatarLab extends Component
{
    // Avatar selection
    public ?int $avatarId = null;
    public bool $hasCharacter = false;

    // Settings (mirrors Avatar 3D fields)
    public string $presentationMode = 'framed';
    public int    $age              = 35;
    public string $gender           = 'male';
    public string $emotionStyle     = 'auto';
    public float  $expressiveness   = 1.2;
    public float  $speakingSpeed    = 1.0;
    public string $frameBackground  = '#0f172a';

    // Preview state
    public string  $testScript            = '';
    public string  $previewStatus         = 'idle'; // idle | generating | ready | error
    public ?string $previewAudioUrl       = null;
    public ?string $previewBlendShapesUrl = null;
    public ?string $previewError          = null;
    public bool    $polling               = false;

    public Collection $avatars;

    public function mount(): void
    {
        $this->avatars = Avatar::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($this->avatars->count() === 1) {
            $this->selectAvatar($this->avatars->first()->id);
        }
    }

    public function selectAvatar(int $id): void
    {
        $avatar = Avatar::findOrFail($id);

        $this->avatarId         = $id;
        $this->presentationMode = $avatar->presentation_mode ?? 'framed';
        $this->age              = $avatar->age              ?? 35;
        $this->gender           = $avatar->gender           ?? 'male';
        $this->emotionStyle     = $avatar->emotion_style    ?? 'auto';
        $this->expressiveness   = $avatar->expressiveness   ?? 1.2;
        $this->speakingSpeed    = $avatar->speaking_speed   ?? 1.0;
        $this->frameBackground  = $avatar->frame_background ?? '#0f172a';
        $this->hasCharacter     = file_exists(public_path("avatars/{$id}/character.glb"));

        $this->previewStatus         = 'idle';
        $this->previewAudioUrl       = null;
        $this->previewBlendShapesUrl = null;
        $this->previewError          = null;
        $this->polling               = false;
    }

    public function generatePreview(): void
    {
        $this->validate([
            'avatarId'   => 'required|integer|exists:avatars,id',
            'testScript' => 'required|string|min:5|max:2000',
        ]);

        $this->previewStatus = 'generating';
        $this->polling       = true;

        Cache::forget("avatar3d_preview_{$this->avatarId}");

        GenerateAvatarPreview3d::dispatch($this->avatarId, $this->testScript);
    }

    public function pollPreviewStatus(): void
    {
        if (! $this->polling || ! $this->avatarId) {
            return;
        }

        $cached = Cache::get("avatar3d_preview_{$this->avatarId}");

        if (! $cached) {
            return; // still queued
        }

        if ($cached['status'] === 'ready') {
            $this->previewStatus         = 'ready';
            $this->previewAudioUrl       = $cached['audio_url'];
            $this->previewBlendShapesUrl = $cached['blendshapes_url'];
            $this->polling               = false;
            $this->dispatch('avatar3d:previewReady',
                audioUrl: $this->previewAudioUrl,
                blendShapesUrl: $this->previewBlendShapesUrl,
            );
        }

        if ($cached['status'] === 'failed') {
            $this->previewStatus = 'error';
            $this->previewError  = $cached['error'];
            $this->polling       = false;
        }
    }

    public function saveSettings(): void
    {
        $this->validate([
            'avatarId'         => 'required|integer|exists:avatars,id',
            'age'              => 'required|integer|min:8|max:80',
            'expressiveness'   => 'required|numeric|min:0.1|max:2.0',
            'speakingSpeed'    => 'required|numeric|min:0.5|max:2.0',
            'frameBackground'  => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'presentationMode' => 'required|in:fullscreen,framed,hidden',
            'gender'           => 'required|in:male,female',
            'emotionStyle'     => 'required|in:auto,narrative,cheerful,serious,excited,empathetic,whispering',
        ]);

        Avatar::where('id', $this->avatarId)->update([
            'presentation_mode' => $this->presentationMode,
            'age'               => $this->age,
            'gender'            => $this->gender,
            'emotion_style'     => $this->emotionStyle,
            'expressiveness'    => $this->expressiveness,
            'speaking_speed'    => $this->speakingSpeed,
            'frame_background'  => $this->frameBackground,
        ]);

        session()->flash('message', 'Settings saved.');
    }

    public function render(): View
    {
        return view('livewire.admin.avatar-lab')
            ->layout('components.layouts.app', ['title' => '3D Avatar Lab']);
    }
}
