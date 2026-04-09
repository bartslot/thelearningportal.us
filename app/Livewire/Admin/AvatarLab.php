<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Jobs\ConvertAnimationClip;
use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use App\Services\FbxConversionService;
use Livewire\Component;

class AvatarLab extends Component
{
    public int    $avatarId;
    public string $activeTab         = 'preview';
    public ?int   $selectedClipId    = null;
    public bool   $polling           = false;
    public bool   $fbx2gltfInstalled = true;

    public function mount(Avatar $avatar): void
    {
        $this->avatarId          = $avatar->id;
        $this->fbx2gltfInstalled = app(FbxConversionService::class)->isInstalled();
    }

    public function selectClip(int $clipId): void
    {
        $this->selectedClipId = $clipId;
        $clip = AnimationClip::findOrFail($clipId);

        $this->dispatch('movement:clipSelected',
            clipId: $clipId,
            status: $clip->status,
            glbUrl: $clip->isConverted() ? asset($clip->glb_path) : null
        );
    }

    public function convertClip(int $clipId): void
    {
        $clip = AnimationClip::findOrFail($clipId);
        $clip->update(['status' => 'converting', 'conversion_error' => null]);
        ConvertAnimationClip::dispatch($clipId);
        $this->polling = true;
    }

    public function pollConversionStatus(): void
    {
        if (! $this->selectedClipId || ! $this->polling) {
            return;
        }

        $clip = AnimationClip::findOrFail($this->selectedClipId);

        if ($clip->status === 'ready') {
            $this->polling = false;
            $this->dispatch('movement:clipReady', glbUrl: asset($clip->glb_path));
        } elseif ($clip->status === 'failed') {
            $this->polling = false;
        }
    }

    public function assignClipToSlot(int $clipId, string $slot): void
    {
        $clip       = AnimationClip::findOrFail($clipId);
        $controller = AvatarAnimationController::firstOrCreate(
            ['avatar_id' => $this->avatarId],
            ['controller' => AvatarAnimationController::defaultControllerData()]
        );

        $data = $controller->controller;

        if (! isset($data['slots'][$slot])) {
            $data['slots'][$slot] = ['mode' => 'random', 'clips' => []];
        }

        if (! in_array($clip->clip_id, $data['slots'][$slot]['clips'], true)) {
            $data['slots'][$slot]['clips'][] = $clip->clip_id;
        }

        $controller->update(['controller' => $data]);
    }

    public function removeClipFromSlot(int $clipId, string $slot): void
    {
        $clip       = AnimationClip::findOrFail($clipId);
        $controller = AvatarAnimationController::where('avatar_id', $this->avatarId)->first();

        if (! $controller) {
            return;
        }

        $data = $controller->controller;

        if (isset($data['slots'][$slot])) {
            $data['slots'][$slot]['clips'] = array_values(
                array_filter($data['slots'][$slot]['clips'], fn($id) => $id !== $clip->clip_id)
            );
        }

        $controller->update(['controller' => $data]);
    }

    public function setSlotMode(string $slot, string $mode): void
    {
        $controller = AvatarAnimationController::where('avatar_id', $this->avatarId)->first();

        if (! $controller) {
            return;
        }

        $data = $controller->controller;

        if (isset($data['slots'][$slot])) {
            $data['slots'][$slot]['mode'] = $mode;
        }

        $controller->update(['controller' => $data]);
    }

    public function render(): \Illuminate\View\View
    {
        $avatar       = Avatar::findOrFail($this->avatarId);
        $clips        = AnimationClip::orderBy('clip_id')->get();
        $controller   = AvatarAnimationController::where('avatar_id', $this->avatarId)->first();
        $selectedClip = $this->selectedClipId ? AnimationClip::find($this->selectedClipId) : null;

        // Build flat set of assigned clip_ids for the left panel status indicators
        $assignedClipIds = collect();
        if ($controller) {
            foreach ($controller->controller['slots'] ?? [] as $slot) {
                $assignedClipIds = $assignedClipIds->merge($slot['clips'] ?? []);
            }
        }
        $assignedClipIds = $assignedClipIds->unique()->values();

        return view('livewire.admin.avatar-lab', compact(
            'avatar', 'clips', 'controller', 'selectedClip', 'assignedClipIds'
        ))->layout('components.layouts.app', ['title' => 'Avatar Lab — ' . $avatar->name]);
    }
}
