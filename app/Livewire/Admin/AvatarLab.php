<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use App\Services\TtsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

class AvatarLab extends Component
{
    use WithFileUploads;

    // Sidebar state
    public ?int   $selectedAvatarId = null;
    public string $activeSection    = 'animation-groups'; // 'animation-groups' | 'controller'

    // Viewport state
    public ?int    $previewClipId   = null;
    public ?string $previewClipName = null;

    // Settings (under Settings sidebar section)
    public string $gender = 'male';
    public int    $age    = 35;
    public string $name   = '';

    // Narration & Audio
    public string  $narrationScript       = '';
    public bool    $narrationBusy         = false;
    public ?string $narrationAudioUrl     = null;
    public string  $narrationCachedScript = '';    // script matching the cached audio
    public string  $voiceProvider         = 'edge_tts';
    public string  $voiceId               = '';
    public float   $voiceSpeed            = 1.0;

    // Per-category file upload properties
    public $idleFile;
    public $presentingFile;
    public $greetingFile;

    public Collection $avatars;

    public function mount(): void
    {
        $this->avatars = Avatar::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        if ($this->avatars->count() === 1) {
            $this->selectAvatar($this->avatars->first()->id);
        }
    }

    public function selectAvatar(int|string $id): void
    {
        $avatar = Avatar::findOrFail((int) $id);

        $this->selectedAvatarId      = $avatar->id;
        $this->gender                = $avatar->gender         ?? 'male';
        $this->age                   = $avatar->age            ?? 35;
        $this->name                  = $avatar->name;
        $this->voiceProvider         = $avatar->voice_provider ?? 'edge_tts';
        $this->voiceId               = $avatar->voice_id       ?? '';
        $this->voiceSpeed            = $avatar->voice_speed    ?? 1.0;
        $this->narrationAudioUrl     = null;
        $this->narrationCachedScript = '';
        $this->previewClipId         = null;
        $this->previewClipName       = null;

        $defaultScript = "Hey students! My name is {$avatar->name}. Today we're going on an incredible journey through history together. Are you ready? Let's begin!";
        $this->narrationScript = $defaultScript;

        // Tell the JS player to load this avatar's GLB
        $this->dispatch('avatar3d:load', characterUrl: "/avatars/{$avatar->id}/character.glb");

        // Auto-play first working idle: prefer clips assigned to this avatar's controller,
        // fall back to any global idle that has an FBX file on disk.
        $controller     = AvatarAnimationController::where('avatar_id', $avatar->id)->first();
        $controllerData = $controller?->controller ?? AvatarAnimationController::defaultControllerData();
        $assignedIds    = $controllerData['idle'] ?? [];

        $firstIdle = ! empty($assignedIds)
            ? AnimationClip::whereIn('id', $assignedIds)
                ->where('category', 'idle')
                ->where('fbx_path', '!=', '')
                ->orderBy('sort_order')
                ->first()
            : null;

        $firstIdle ??= AnimationClip::where('category', 'idle')
            ->where('fbx_path', '!=', '')
            ->orderBy('sort_order')
            ->first();

        if ($firstIdle) {
            $this->loadPreview($firstIdle->id);
        }

        // Pre-generate audio + timings for instant playback
        $this->generateNarration($avatar, $defaultScript);
    }

    // ── File upload lifecycle hooks ────────────────────────────────────────

    public function updatedIdleFile(): void
    {
        $this->processUpload('idle', $this->idleFile);
    }

    public function updatedPresentingFile(): void
    {
        $this->processUpload('presenting', $this->presentingFile);
    }

    public function updatedGreetingFile(): void
    {
        $this->processUpload('greeting', $this->greetingFile);
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
            foreach (['idle', 'presenting', 'greeting'] as $cat) {
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

    // ── Narration ─────────────────────────────────────────────────────────

    public function speakScript(): void
    {
        if (! $this->selectedAvatarId || $this->narrationBusy) return;

        // Pre-generated audio ready — dispatch text + URL for client-side Azure blend shapes
        if ($this->narrationAudioUrl && $this->narrationCachedScript === $this->narrationScript) {
            $this->dispatch('avatar3d:speak',
                audioUrl: $this->narrationAudioUrl,
                text:     app(\App\Services\TtsService::class)->prepareSpeechText($this->narrationScript),
            );
            return;
        }

        // Script changed — regenerate Pocket TTS audio
        $this->narrationBusy = true;
        try {
            $avatar = Avatar::findOrFail($this->selectedAvatarId);
            $this->generateNarration($avatar, $this->narrationScript);
            if ($this->narrationAudioUrl) {
                $this->dispatch('avatar3d:speak',
                    audioUrl: $this->narrationAudioUrl,
                    text:     app(\App\Services\TtsService::class)->prepareSpeechText($this->narrationScript),
                );
            }
        } finally {
            $this->narrationBusy = false;
        }
    }

    private function generateNarration(Avatar $avatar, string $script): void
    {
        $tts  = app(\App\Services\TtsService::class);
        $text = $tts->prepareSpeechText($script);

        if ($avatar->voice_provider === 'pocket_tts') {
            $audio = $this->callPocketTts($text, $avatar->voice_id);
            if (! $audio) return;
            $path = "avatars/lab-narration/{$avatar->id}-narration.wav";
            Storage::disk('public')->put($path, $audio);
            $this->narrationAudioUrl     = '/storage/' . $path;
            $this->narrationCachedScript = $script;
            return;
        }

        $audio = $tts->generateAudioRaw($script, $avatar->voice_id, $avatar->voice_speed, $avatar->voice_provider);
        if (! $audio) return;
        $ext  = $tts->lastExtension();
        $path = "avatars/lab-narration/{$avatar->id}-narration.{$ext}";
        Storage::disk('public')->put($path, $audio);
        $this->narrationAudioUrl     = '/storage/' . $path;
        $this->narrationCachedScript = $script;
    }

    private function callPocketTts(string $text, string $wavPath): ?string
    {
        $url = rtrim(config('services.pocket_tts.url', 'http://localhost:8001'), '/');

        if (! file_exists($wavPath)) {
            \Illuminate\Support\Facades\Log::warning("Pocket TTS: voice WAV not found at {$wavPath}");
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(90)
                ->attach('voice_wav', file_get_contents($wavPath), basename($wavPath))
                ->post("{$url}/tts", ['text' => $text]);

            return $response->successful() ? $response->body() : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Pocket TTS failed: ' . $e->getMessage());
            return null;
        }
    }

    // ── Render ────────────────────────────────────────────────────────────

    public function render(): View
    {
        $clipsByCategory = AnimationClip::orderBy('sort_order')->orderBy('name')
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
