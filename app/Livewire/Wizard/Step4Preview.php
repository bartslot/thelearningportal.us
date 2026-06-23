<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Models\AnimationClip;
use App\Models\Lesson;
use App\Models\Scene;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step4Preview extends Component
{
    public Lesson  $lesson;
    public ?int    $selectedSceneId = null;
    public string  $publishError    = '';

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        if ($this->lesson->status !== LessonStatus::Published) {
            $this->lesson->update(['status' => LessonStatus::Previewable, 'wizard_step' => 5]);
        }

        $first = $this->lesson->scenes()->ordered()->first();
        if ($first) {
            $this->selectSceneInternal($first->id);
        }
    }

    #[Computed]
    public function scenes()
    {
        return $this->lesson->scenes()->ordered()->get();
    }

    #[Computed]
    public function allReady(): bool
    {
        return $this->scenes->isNotEmpty()
            && $this->scenes->every(fn ($s) => $s->status === 'ready');
    }

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    private function selectSceneInternal(int $id): void
    {
        $scene = $this->lesson->scenes()->find($id);
        if (! $scene) {
            return;
        }
        $this->selectedSceneId = $id;
        $this->dispatch('scene:load', payload: [
            'sceneId'           => $scene->id,
            'imageUrl'          => $scene->image_path ? asset('storage/' . $scene->image_path) : null,
            'audioUrl'          => $scene->audio_path ? asset('storage/' . $scene->audio_path) : null,
            'animationClipUrl'  => $this->animationGlbUrlFor($scene),
            'year'              => $scene->year,
            'location'          => $scene->location,
            'kind'              => $scene->kind,
            'gameType'          => $scene->game_type,
            'duration'          => $scene->duration_seconds,
            'skyboxBlur'        => (float) ($scene->skybox_blur    ?? 0.5),
            'skyboxOpacity'     => (float) ($scene->skybox_opacity ?? 1.0),
            'backgroundColor'   => (string) ($scene->background_color ?? '#000000'),
            'sceneView'         => (string) ($scene->scene_view ?? 'skybox'),
        ]);
    }

    private function animationGlbUrlFor(Scene $scene): ?string
    {
        if ($scene->animation_clip_id) {
            $clip = AnimationClip::find($scene->animation_clip_id);
            if ($clip?->glb_path) {
                return $clip->glbUrl();
            }
        }
        $idlePath = AnimationClip::where('category', 'idle')
            ->whereNotNull('glb_path')
            ->orderBy('sort_order')
            ->value('glb_path');
        return $idlePath ? asset($idlePath) : null;
    }

    public function publish(): void
    {
        if (! $this->allReady) {
            $this->publishError = 'All scenes must be in "ready" status before publishing.';
            return;
        }

        $this->lesson->update(['status' => LessonStatus::Published]);
        $this->publishError = '';
        $this->dispatch('lesson-published');
    }

    public function render()
    {
        return view('livewire.wizard.step4-preview');
    }
}
