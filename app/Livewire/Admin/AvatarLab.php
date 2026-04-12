<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use Illuminate\Support\Collection;
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

    // Audio section
    public string $testScript = '';

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

        $this->selectedAvatarId = $avatar->id;
        $this->gender           = $avatar->gender           ?? 'male';
        $this->age              = $avatar->age              ?? 35;
        $this->name             = $avatar->name;
        $this->previewClipId    = null;
        $this->previewClipName  = null;
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

        $this->dispatch('preview-clip',
            clipId:   $clipId,
            clipName: $clip->name,
            category: $clip->category,
            fbxUrl:   $clip->fbxUrl(),
        );
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

        $clip = AnimationClip::findOrFail($this->previewClipId);
        $this->assignToController($this->selectedAvatarId, $clip->category, $this->previewClipId);
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
        ))->layout('components.layouts.app', ['title' => 'Avatar Lab']);
    }
}
