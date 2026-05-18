<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use App\Services\ElevenLabsService;
use App\Services\TtsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class AvatarLab extends Component
{
    use WithFileUploads;

    // Sidebar state
    public ?int   $selectedAvatarId = null;
    public string $activeSection    = 'avatar'; // 'avatar' | 'animation-groups' | 'controller' | 'narration' | 'settings'

    // Viewport state
    public ?int    $previewClipId   = null;
    public ?string $previewClipName = null;

    // Settings (under Settings sidebar section)
    public string $gender          = 'male';
    public int    $age             = 35;
    public string $name            = '';
    public string $sceneBackground = '#f0f0f0';

    // Narration & Audio
    public string  $narrationScript       = '';
    public bool    $narrationBusy         = false;
    public ?string $narrationAudioUrl     = null;
    public string  $narrationCachedScript = '';    // script matching the cached audio
    public array   $narrationAlignment    = [];    // ElevenLabs character timings for cached audio
    public string  $voiceProvider         = 'elevenlabs';
    public string  $voiceId               = '';
    public float   $voiceSpeed            = 1.0;

    // Per-category file upload properties
    public $idleFile;
    public $expressionFile;
    public $danceFile;

    // ── New avatar upload modal state ─────────────────────────────────────────
    public ?int    $newAvatarId          = null;
    public string  $newAvatarName        = '';
    public string  $newAvatarGender      = 'male';
    public int     $newAvatarAge         = 30;
    public string  $newAvatarVoiceId     = '';
    public string  $newAvatarMorphStatus = 'processing';
    public string  $newAvatarModalTab    = 'info';
    public $newAvatarGlbFile;

    #[Computed]
    public function avatars(): Collection
    {
        return Avatar::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);
    }

    public function mount(): void
    {
        $first = $this->avatars->first();
        if ($first) {
            $this->selectAvatar($first->id);
        }
    }

    public function selectAvatar(int|string $id): void
    {
        $avatar = Avatar::findOrFail((int) $id);

        $this->selectedAvatarId      = $avatar->id;
        $this->gender                = $avatar->gender         ?? 'male';
        $this->age                   = $avatar->age            ?? 35;
        $this->name                  = $avatar->name;
        $this->voiceProvider         = 'elevenlabs';
        $this->voiceId               = $avatar->voice_id       ?? '';
        $this->voiceSpeed            = $avatar->voice_speed    ?? 1.0;
        $this->narrationAudioUrl     = null;
        $this->narrationCachedScript = '';
        $this->narrationAlignment    = [];
        $this->previewClipId         = null;
        $this->previewClipName       = null;

        $defaultScript = "Hey students! My name is {$avatar->name}. Today we're going on an incredible journey through history together. Are you ready? Let's begin!";
        $this->narrationScript = $defaultScript;

        // Sync Alpine store selectedId to the avatar's saved voice
        $this->dispatch('voice-selected', voiceId: $this->voiceId);

        // Tell the JS player to load this avatar's GLB
        $this->dispatch('avatar3d:load', characterUrl: "/avatars/{$avatar->id}/character.glb");
        $this->dispatch('avatar3d:setBg', hex: $this->sceneBackground);

        // Auto-play first idle matching the avatar's gender.
        // Prefer clips assigned to this avatar's controller; fall back to any gender-matching idle.
        $clipGender     = $this->gender === 'female' ? 'feminine' : 'masculine';
        $controller     = AvatarAnimationController::where('avatar_id', $avatar->id)->first();
        $controllerData = $controller?->controller ?? AvatarAnimationController::defaultControllerData();
        $assignedIds    = $controllerData['idle'] ?? [];

        $firstIdle = ! empty($assignedIds)
            ? AnimationClip::whereIn('id', $assignedIds)
                ->where('category', 'idle')
                ->where('gender', $clipGender)
                ->whereNotNull('glb_path')
                ->orderBy('sort_order')
                ->first()
            : null;

        $firstIdle ??= AnimationClip::where('category', 'idle')
            ->where('gender', $clipGender)
            ->whereNotNull('glb_path')
            ->orderBy('sort_order')
            ->first();

        if ($firstIdle) {
            $this->loadPreview($firstIdle->id);
        }
    }

    // ── Voice picker ──────────────────────────────────────────────────────

    #[Computed]
    public function voices(): array
    {
        $all = app(ElevenLabsService::class)->getVoices();

        // Filter to voices matching the avatar's gender (male/female).
        // Fall back to all voices if none match (e.g. gender not set or API labels missing).
        $filtered = array_values(array_filter($all, fn ($v) => $v['gender'] === $this->gender));
        $voices   = count($filtered) >= 2 ? $filtered : $all;

        // Cap at 4 samples and cache previews to the server for reliable playback.
        return $this->cacheVoicePreviews(array_slice($voices, 0, 4));
    }

    private function cacheVoicePreviews(array $voices): array
    {
        return array_map(function (array $v) {
            if (empty($v['preview_url'])) return $v;
            $localPath = "avatars/voice-previews/{$v['id']}.mp3";
            $absPath   = storage_path("app/public/{$localPath}");
            if (! file_exists($absPath)) {
                try {
                    $res = Http::timeout(10)->get($v['preview_url']);
                    if ($res->ok()) {
                        Storage::disk('public')->put($localPath, $res->body());
                    }
                } catch (\Throwable) {}
            }
            if (file_exists($absPath)) {
                $v['preview_url'] = '/storage/' . $localPath;
            }
            return $v;
        }, $voices);
    }

    #[Computed]
    public function newAvatarVoices(): array
    {
        $all      = app(ElevenLabsService::class)->getVoices();
        $filtered = array_values(array_filter($all, fn ($v) => $v['gender'] === $this->newAvatarGender));
        $voices   = count($filtered) >= 2 ? $filtered : $all;
        return $this->cacheVoicePreviews(array_slice($voices, 0, 4));
    }

    public function selectVoice(string $voiceId): void
    {
        $this->voiceId       = $voiceId;
        $this->voiceProvider = 'elevenlabs';

        // Persist immediately
        if ($this->selectedAvatarId) {
            Avatar::where('id', $this->selectedAvatarId)->update([
                'voice_provider' => $this->voiceProvider,
                'voice_id'       => $this->voiceId,
                'voice_speed'    => $this->voiceSpeed,
            ]);
        }

        // Reset cached audio — voice changed
        $this->narrationAudioUrl     = null;
        $this->narrationCachedScript = '';
        $this->narrationAlignment    = [];

        // Sync the Alpine store's selectedId without relying on DOM scope
        $this->dispatch('voice-selected', voiceId: $voiceId);
    }

    public function updatedName(string $value): void
    {
        $value = trim($value);
        if ($this->selectedAvatarId && $value !== '') {
            Avatar::where('id', $this->selectedAvatarId)->update(['name' => $value]);
            unset($this->avatars); // bust computed cache
        }
    }

    public function updatedVoiceSpeed(float $value): void
    {
        if ($this->selectedAvatarId) {
            Avatar::where('id', $this->selectedAvatarId)->update(['voice_speed' => $value]);
        }
        // Reset cached audio — speed changed
        $this->narrationAudioUrl     = null;
        $this->narrationCachedScript = '';
    }

    public function updatedSceneBackground(string $value): void
    {
        $this->dispatch('avatar3d:setBg', hex: $value);
    }

    // ── File upload lifecycle hooks ────────────────────────────────────────

    public function updatedIdleFile(): void
    {
        $this->processUpload('idle', $this->idleFile);
    }

    public function updatedExpressionFile(): void
    {
        $this->processUpload('expression', $this->expressionFile);
    }

    public function updatedDanceFile(): void
    {
        $this->processUpload('dance', $this->danceFile);
    }

    public function updatedNewAvatarGlbFile(): void
    {
        if (! $this->newAvatarGlbFile) {
            return;
        }

        if (strtolower($this->newAvatarGlbFile->getClientOriginalExtension()) !== 'glb') {
            session()->flash('error', 'Only .glb files are allowed.');
            $this->reset('newAvatarGlbFile');
            return;
        }

        if ($this->newAvatarGlbFile->getSize() > 50 * 1024 * 1024) {
            session()->flash('error', 'GLB file must be under 50 MB.');
            $this->reset('newAvatarGlbFile');
            return;
        }

        $avatar = Avatar::create([
            'name'           => 'New Avatar',
            'slug'           => 'new-avatar-' . Str::random(8),
            'gender'         => 'male',
            'age'            => 30,
            'voice_provider' => 'elevenlabs',
            'voice_id'       => '',
            'is_active'      => false,
            'sort_order'     => Avatar::max('sort_order') + 1,
            'morph_status'   => 'processing',
        ]);

        $dir = public_path("avatars/{$avatar->id}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->newAvatarGlbFile->move($dir, 'character.glb');

        unset($this->avatars); // bust computed cache

        $this->newAvatarId          = $avatar->id;
        $this->newAvatarName        = '';
        $this->newAvatarGender      = 'male';
        $this->newAvatarAge         = 30;
        $this->newAvatarVoiceId     = '';
        $this->newAvatarMorphStatus = 'processing';
        $this->newAvatarModalTab    = 'info';

        \App\Jobs\TransferAvatarMorphs::dispatch($avatar->id);

        $this->reset('newAvatarGlbFile');
    }

    public function saveNewAvatarMeta(): void
    {
        if (! $this->newAvatarId) {
            return;
        }

        $slug = Str::slug($this->newAvatarName ?: 'avatar') . '-' . $this->newAvatarId;

        Avatar::where('id', $this->newAvatarId)->update([
            'name'      => $this->newAvatarName ?: 'New Avatar',
            'slug'      => $slug,
            'gender'    => $this->newAvatarGender,
            'age'       => $this->newAvatarAge,
            'is_active' => (bool) $this->newAvatarName,
        ]);

        unset($this->avatars); // bust computed cache
    }

    public function saveNewAvatarVoice(string $voiceId): void
    {
        if (! $this->newAvatarId) {
            return;
        }

        $this->newAvatarVoiceId = $voiceId;

        Avatar::where('id', $this->newAvatarId)->update([
            'voice_provider' => 'elevenlabs',
            'voice_id'       => $voiceId,
        ]);
    }

    public function saveAndCloseNewAvatar(): void
    {
        $this->saveNewAvatarMeta();
        $this->closeNewAvatarModal();
    }

    public function closeNewAvatarModal(): void
    {
        if ($this->newAvatarId) {
            $avatar = Avatar::find($this->newAvatarId);
            if ($avatar && ($avatar->name === 'New Avatar' || ! $avatar->name)) {
                $dir = public_path("avatars/{$this->newAvatarId}");
                if (is_dir($dir)) {
                    File::deleteDirectory($dir);
                }
                $avatar->forceDelete();
            }
        }
        $this->reset('newAvatarId', 'newAvatarName', 'newAvatarGender', 'newAvatarAge', 'newAvatarVoiceId', 'newAvatarMorphStatus', 'newAvatarModalTab');
        unset($this->avatars); // bust computed cache
    }

    public function refreshAvatarList(): void
    {
        unset($this->avatars); // bust computed cache

        if ($this->newAvatarId) {
            $avatar = Avatar::find($this->newAvatarId);
            $this->newAvatarMorphStatus = $avatar?->morph_status ?? 'failed';
        }
    }

    private function processUpload(string $category, mixed $file): void
    {
        if (! $file) {
            return;
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'fbx') {
            session()->flash('error', 'Only .fbx files are allowed.');
            return;
        }

        if ($file->getSize() > 20 * 1024 * 1024) {
            session()->flash('error', 'File size must be under 20 MB.');
            return;
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $clip = AnimationClip::create([
            'name'       => $name,
            'category'   => $category,
            'fbx_path'   => '',
            'sort_order' => AnimationClip::where('category', $category)->max('sort_order') + 1,
        ]);

        $dir = public_path("avatars/animations/{$category}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dest = $dir . DIRECTORY_SEPARATOR . "{$clip->id}.fbx";
        // Use rename/copy so it works with both real HTTP uploads and Livewire
        // TemporaryUploadedFile instances (which fail move_uploaded_file in tests).
        $src = method_exists($file, 'getRealPath') ? $file->getRealPath() : $file->getPathname();
        if (! @rename($src, $dest)) {
            copy($src, $dest);
            @unlink($src);
        }
        $clip->update(['fbx_path' => "avatars/animations/{$category}/{$clip->id}.fbx"]);

        $this->dispatch('clip-uploaded', category: $category, clipId: $clip->id);
    }

    // ── Clip actions ──────────────────────────────────────────────────────

    public function deleteClip(int $id): void
    {
        $clip = AnimationClip::findOrFail($id);

        // Remove from all controllers
        AvatarAnimationController::all()->each(function (AvatarAnimationController $ctrl) use ($clip): void {
            $data = $ctrl->controller;
            foreach (['idle', 'expression', 'dance'] as $cat) {
                if (isset($data[$cat])) {
                    $data[$cat] = array_values(
                        array_filter($data[$cat], fn ($cid) => $cid !== (string) $clip->id)
                    );
                }
            }
            $ctrl->update(['controller' => $data]);
        });

        $abs = public_path($clip->fbx_path);
        if (file_exists($abs)) {
            unlink($abs);
        }

        if ($this->previewClipId === $id) {
            $this->previewClipId   = null;
            $this->previewClipName = null;
        }

        $clip->delete();
    }

    public function reorder(string $category, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            AnimationClip::where('id', (int) $id)
                ->where('category', $category)
                ->update(['sort_order' => $index]);
        }
    }

    public function loadPreview(int $clipId): void
    {
        $clip = AnimationClip::findOrFail($clipId);

        $this->previewClipId   = $clipId;
        $this->previewClipName = $clip->name;

        // Is this clip already in the controller pool?
        $controller = $this->selectedAvatarId
            ? AvatarAnimationController::where('avatar_id', $this->selectedAvatarId)->first()
            : null;
        $data       = $controller?->controller ?? AvatarAnimationController::defaultControllerData();
        $isAssigned = in_array((string) $clipId, $data[$clip->category] ?? [], true);

        $this->dispatch('preview-clip',
            clipId:          $clipId,
            clipName:        $clip->name,
            category:        $clip->category,
            fbxUrl:          $clip->fbx_path ? $clip->fbxUrl() : null,
            glbUrl:          $clip->glb_path ? asset($clip->glb_path) : null,
            isAssigned:      $isAssigned,
            speed:           $clip->speed           ?? 1.0,
            expressiveness:  $clip->expressiveness  ?? 1.0,
        );
    }

    public function bakeClip(int $clipId, float $speed, float $expressiveness): void
    {
        $clip = AnimationClip::findOrFail($clipId);

        $clip->update([
            'speed'          => round(max(0.25, min(2.0,  $speed)),          2),
            'expressiveness' => round(max(0.0,  min(1.5,  $expressiveness)), 2),
        ]);

        $this->dispatch('clip-baked', clipId: $clipId, speed: $clip->speed, expressiveness: $clip->expressiveness);
    }

    public function assignToController(int $avatarId, string $category, int $clipId): void
    {
        $controller = AvatarAnimationController::firstOrCreate(
            ['avatar_id' => $avatarId],
            ['controller' => AvatarAnimationController::defaultControllerData()],
        );

        $data = $controller->controller;

        if (! isset($data[$category])) {
            $data[$category] = [];
        }

        if (! in_array((string) $clipId, $data[$category], true)) {
            $data[$category][] = (string) $clipId;
        }

        $controller->update(['controller' => $data]);
        $this->dispatch('clip-assigned', clipId: $clipId, category: $category);
    }

    public function removeFromController(int $avatarId, string $category, int $clipId): void
    {
        $controller = AvatarAnimationController::where('avatar_id', $avatarId)->first();
        if (! $controller) {
            return;
        }

        $data = $controller->controller;

        if (isset($data[$category])) {
            $data[$category] = array_values(
                array_filter($data[$category], fn ($id) => $id !== (string) $clipId)
            );
        }

        $controller->update(['controller' => $data]);
        $this->dispatch('clip-removed', clipId: $clipId, category: $category);
    }

    public function useClip(): void
    {
        if (! $this->previewClipId || ! $this->selectedAvatarId) {
            return;
        }

        $clip       = AnimationClip::findOrFail($this->previewClipId);
        $controller = AvatarAnimationController::where('avatar_id', $this->selectedAvatarId)->first();
        $data       = $controller?->controller ?? AvatarAnimationController::defaultControllerData();
        $pool       = $data[$clip->category] ?? [];

        // Toggle: remove if already assigned, add if not
        if (in_array((string) $this->previewClipId, $pool, true)) {
            $this->removeFromController($this->selectedAvatarId, $clip->category, $this->previewClipId);
        } else {
            $this->assignToController($this->selectedAvatarId, $clip->category, $this->previewClipId);
        }
    }

    // ── Arrow key navigation ─────────────────────────────────────────────

    public function arrowNav(int $dir): void
    {
        match ($this->activeSection) {
            'avatar'            => $this->arrowNavAvatar($dir),
            'animation-groups'  => $this->arrowNavClip($dir),
            'narration'         => $this->arrowNavAvatar($dir),
            default             => null,
        };
    }

    private function arrowNavAvatar(int $dir): void
    {
        $ids = $this->avatars->pluck('id')->values();
        if ($ids->isEmpty()) return;
        $idx = $ids->search($this->selectedAvatarId) ?? 0;
        $next = ($idx + $dir + $ids->count()) % $ids->count();
        $this->selectAvatar($ids[$next]);
    }

    private function arrowNavClip(int $dir): void
    {
        if (! $this->previewClipId) return;
        $clip = AnimationClip::find($this->previewClipId);
        if (! $clip) return;
        $clips = AnimationClip::where('category', $clip->category)
            ->orderBy('sort_order')->orderBy('name')->pluck('id')->values();
        if ($clips->isEmpty()) return;
        $idx  = $clips->search($this->previewClipId) ?? 0;
        $next = ($idx + $dir + $clips->count()) % $clips->count();
        $this->loadPreview($clips[$next]);
    }

    // ── Narration ─────────────────────────────────────────────────────────

    public function previewVoiceWithAlignment(string $voiceId): void
    {
        $service    = app(ElevenLabsService::class);
        $previewUrl = $service->getVoicePreviewUrl($voiceId) ?? '';
        $result     = $service->generateWithTimestamps('Hello! I am your guide. Let me show you what I can do.', $voiceId);

        if ($result === null) {
            \Log::warning('[AvatarLab] previewVoiceWithAlignment: ElevenLabs returned null for voice ' . $voiceId . ', falling back to preview_url');
            // Fall back to the voice preview URL — amplitude jaw, no zoom, instant play
            if ($previewUrl !== '') {
                $this->dispatch('avatar3d:speak',
                    audioUrl:  $previewUrl,
                    alignment: [],
                    text:      '',
                    preview:   true,
                );
            }
            return;
        }

        $path = "avatars/lab-preview/{$voiceId}-preview.mp3";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $result['audio']);
        \Log::debug('[AvatarLab] previewVoiceWithAlignment: ' . count($result['alignment']) . ' alignment entries for voice ' . $voiceId);

        $this->dispatch('avatar3d:speak',
            audioUrl:  '/storage/' . $path,
            alignment: $result['alignment'],
            text:      '',
            preview:   true,
        );
    }

    public function speakScript(): void
    {
        if (! $this->selectedAvatarId || $this->narrationBusy) return;

        // Pre-generated audio ready — replay with cached alignment
        if ($this->narrationAudioUrl && $this->narrationCachedScript === $this->narrationScript) {
            $this->dispatch('avatar3d:speak',
                audioUrl:  $this->narrationAudioUrl,
                alignment: $this->narrationAlignment,
                text:      app(\App\Services\TtsService::class)->prepareSpeechText($this->narrationScript),
                preview:   true,  // camera already at head from tab switch — no re-zoom
            );
            return;
        }

        // Script changed — regenerate audio
        $this->narrationBusy = true;
        try {
            $avatar = Avatar::findOrFail($this->selectedAvatarId);
            $this->generateNarration($avatar, $this->narrationScript);
            if ($this->narrationAudioUrl) {
                $this->dispatch('avatar3d:speak',
                    audioUrl:  $this->narrationAudioUrl,
                    alignment: $this->narrationAlignment,
                    text:      app(\App\Services\TtsService::class)->prepareSpeechText($this->narrationScript),
                    preview:   true,  // camera already at head from tab switch — no re-zoom
                );
            }
        } finally {
            $this->narrationBusy = false;
        }
    }

    private function generateNarration(Avatar $avatar, string $script): void
    {
        $tts      = app(\App\Services\TtsService::class);
        $text     = $tts->prepareSpeechText($script);
        $voiceId  = $this->voiceId ?: $avatar->voice_id;
        $provider = $this->voiceProvider ?: $avatar->voice_provider;

        $timingData = null;
        $audio = $tts->generateAudioRaw($text, $voiceId, $avatar->voice_speed ?? 1.0, $provider, $timingData);
        if (! $audio) return;

        $ext  = $tts->lastExtension();
        $path = "avatars/lab-narration/{$avatar->id}-narration.{$ext}";
        Storage::disk('public')->put($path, $audio);
        $this->narrationAudioUrl     = '/storage/' . $path;
        $this->narrationCachedScript = $script;
        $this->narrationAlignment    = $timingData['character_timings'] ?? [];
    }

    // ── Render ────────────────────────────────────────────────────────────

    public function render(): View
    {
        // Only show clips that match the selected avatar's gender
        $clipGender      = $this->gender === 'female' ? 'feminine' : 'masculine';
        $clipsByCategory = AnimationClip::orderBy('sort_order')->orderBy('name')
            ->where('gender', $clipGender)
            ->get()
            ->groupBy('category');

        $controller = $this->selectedAvatarId
            ? AvatarAnimationController::where('avatar_id', $this->selectedAvatarId)->first()
            : null;

        $controllerData = $controller?->controller ?? AvatarAnimationController::defaultControllerData();

        $assignedClipIds = collect($controllerData)
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return view('livewire.admin.avatar-lab', compact(
            'clipsByCategory', 'controllerData', 'assignedClipIds',
        ))->layout('components.layouts.studio', ['title' => 'Avatar Lab']);
    }
}
