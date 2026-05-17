<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Models\AnimationClip;
use App\Models\AvatarAnimationController;
use App\Models\Lesson;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step3SceneConfigurator extends Component
{
    private const EDITABLE_FIELDS = [
        'year', 'location', 'script_segment', 'image_prompt', 'image_style',
        'animation_clip_id', 'duration_seconds',
        'skybox_blur', 'skybox_opacity',
    ];

    public Lesson $lesson;
    public ?int   $selectedSceneId = null;
    /** @var array<string,mixed>|null */
    public ?array $selectedScene   = null;
    public bool   $inspectorOpen   = true;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
        $this->lesson->update(['status' => LessonStatus::Configuring, 'wizard_step' => 3]);

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
    public function selectedSceneModel(): ?Scene
    {
        return $this->selectedSceneId
            ? $this->lesson->scenes()->find($this->selectedSceneId)
            : null;
    }

    #[Computed]
    public function animationClips()
    {
        $avatar = $this->lesson->avatar;
        if (! $avatar) {
            return collect();
        }

        $ctrl = AvatarAnimationController::where('avatar_id', $avatar->id)->first();
        $ids  = collect($ctrl?->controller ?? [])->flatten()->all();
        return AnimationClip::whereIn('id', $ids)->orderBy('category')->orderBy('sort_order')->get();
    }

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    private function selectSceneInternal(int $id): void
    {
        $scene = $this->lesson->scenes()->findOrFail($id);
        $this->selectedSceneId = $id;
        $this->selectedScene   = $this->snapshot($scene);

        $this->dispatch('scene:load', payload: [
            'sceneId'           => $scene->id,
            'imageUrl'          => $scene->image_path ? asset('storage/' . $scene->image_path) : null,
            'audioUrl'          => $scene->audio_path ? asset('storage/' . $scene->audio_path) : null,
            'animationClipId'   => $scene->animation_clip_id,
            'animationClipUrl'  => $this->animationGlbUrlFor($scene),
            'year'              => $scene->year,
            'location'          => $scene->location,
            'kind'              => $scene->kind,
            'duration'          => $scene->duration_seconds,
            'skyboxBlur'        => (float) ($scene->skybox_blur    ?? 0.5),
            'skyboxOpacity'     => (float) ($scene->skybox_opacity ?? 1.0),
        ]);
    }

    /**
     * The GLB URL to load on the avatar for this scene. Falls back to a default idle
     * clip when no animation has been explicitly chosen — mirrors avatar-lab behavior.
     */
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

    public function playSelected(): void
    {
        if (! $this->selectedSceneModel) {
            return;
        }
        $s = $this->selectedSceneModel;
        if (! $s->audio_path) {
            return;
        }
        $this->dispatch('scene:play', payload: [
            'audioUrl'  => asset('storage/' . $s->audio_path),
            'alignment' => $s->audio_alignment ?? [],
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshot(Scene $scene): array
    {
        $snap = ['id' => $scene->id, 'kind' => $scene->kind, 'order' => $scene->order];
        foreach (self::EDITABLE_FIELDS as $f) {
            $snap[$f] = $scene->{$f};
        }
        $snap['image_path']         = $scene->image_path;
        $snap['audio_path']         = $scene->audio_path;
        $snap['status']             = $scene->status;
        $snap['game_segment_index'] = $scene->game_segment_index;
        return $snap;
    }

    public function saveSelected(): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }

        $scene = Scene::where('lesson_id', $this->lesson->id)
            ->findOrFail($this->selectedSceneId);

        $payload     = collect($this->selectedScene)->only(self::EDITABLE_FIELDS)->all();
        $scriptDirty = ($scene->script_segment ?? '') !== ($payload['script_segment'] ?? '');

        // Detect changes that should re-paint the 3D stage so the canvas updates.
        $stageDirty = (int) ($payload['animation_clip_id'] ?? 0) !== (int) ($scene->animation_clip_id ?? 0)
            || ($payload['year']     ?? null) !== ($scene->year     ?? null)
            || ($payload['location'] ?? null) !== ($scene->location ?? null);

        $scene->update($payload);

        if ($scriptDirty) {
            $scene->update(['audio_script_hash' => null]);
        }

        if ($stageDirty) {
            $this->selectSceneInternal($scene->id);
        }
    }

    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $idx => $id) {
                Scene::where('lesson_id', $this->lesson->id)
                    ->where('id', (int) $id)
                    ->update(['order' => -1 * ($idx + 1)]);
            }
            foreach ($orderedIds as $idx => $id) {
                Scene::where('lesson_id', $this->lesson->id)
                    ->where('id', (int) $id)
                    ->update(['order' => $idx + 1]);
            }
        });
    }

    public function regenerate(int $sceneId, string $asset): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $scene->update(['status' => 'generating', 'error_message' => null]);

        match ($asset) {
            'script' => GenerateSceneScript::dispatch($scene->id),
            'image'  => GenerateSceneImage::dispatch($scene->id),
            'audio'  => GenerateSceneAudio::dispatch($scene->id),
            default  => null,
        };
    }

    public function addScene(): void
    {
        $next = ((int) $this->lesson->scenes()->max('order')) + 1;
        $scene = Scene::create([
            'lesson_id'   => $this->lesson->id,
            'order'       => $next,
            'kind'        => 'narration',
            'image_style' => $this->lesson->image_style,
            'status'      => 'pending',
        ]);
        $this->selectSceneInternal($scene->id);
    }

    public function deleteScene(int $sceneId): void
    {
        $scene   = $this->lesson->scenes()->findOrFail($sceneId);
        $wasGame = $scene->kind === 'game';
        $scene->delete();

        $remaining = $this->lesson->scenes()->ordered()->get();
        DB::transaction(function () use ($remaining) {
            foreach ($remaining as $idx => $s) {
                $s->update(['order' => -1 * ($idx + 1)]);
            }
            foreach ($remaining as $idx => $s) {
                $s->update(['order' => $idx + 1]);
            }
        });

        if ($wasGame) {
            $games = $remaining->where('kind', 'game')->values();
            foreach ($games as $idx => $g) {
                $g->update(['game_segment_index' => $idx + 1]);
            }
            $this->lesson->update(['game_split_count' => max(1, $games->count())]);
        }

        if ($this->selectedSceneId === $sceneId) {
            $first = $this->lesson->scenes()->ordered()->first();
            if ($first) {
                $this->selectSceneInternal($first->id);
            } else {
                $this->selectedSceneId = null;
                $this->selectedScene   = null;
            }
        }
    }

    public function continueToPreview(): void
    {
        $this->lesson->update(['wizard_step' => 4, 'status' => LessonStatus::Previewable]);
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 4], navigate: true);
    }

    public function render()
    {
        return view('livewire.wizard.step3-scene-configurator');
    }
}
