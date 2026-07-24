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
    public Lesson $lesson;

    public ?int $selectedSceneId = null;

    public string $publishError = '';

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        if ($this->lesson->status !== LessonStatus::Published) {
            $this->lesson->update(['status' => LessonStatus::Previewable, 'wizard_step' => 5]);
        }

        // Start on the scene passed in via ?scene= (Play / "Edit scene" deep-link) so the preview
        // autoplays FROM the teacher's current scene — falling back to the first scene otherwise.
        $deepScene = request()->integer('scene');
        $scene = $deepScene ? $this->lesson->scenes()->whereKey($deepScene)->first() : null;
        $scene ??= $this->lesson->scenes()->ordered()->first();
        if ($scene) {
            $this->selectSceneInternal($scene->id);
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
            'sceneId' => $scene->id,
            'imageUrl' => $scene->image_path ? asset('storage/'.$scene->image_path) : null,
            'audioUrl' => $scene->audio_path ? asset('storage/'.$scene->audio_path) : null,
            'animationClipUrl' => $this->animationGlbUrlFor($scene),
            'year' => $scene->year,
            'location' => $scene->location,
            'kind' => $scene->kind,
            'gameType' => $scene->game_type,
            'duration' => $scene->duration_seconds,
            'skyboxBlur' => (float) ($scene->skybox_blur ?? 0.5),
            'skyboxOpacity' => (float) ($scene->skybox_opacity ?? 1.0),
            'backgroundColor' => (string) ($scene->background_color ?? '#000000'),
            'sceneView' => (string) ($scene->scene_view ?? 'skybox'),
            'kbAnimated' => (bool) ($scene->kb_animated ?? true),
            'kbDirection' => $scene->kb_direction,
            // Teacher text annotations — read-only here (editing lives in Configure).
            'textsReadonly' => (array) (($scene->config ?? [])['texts'] ?? []),
            // Quiz scenes preview their questions on the canvas, same as Configure.
            'quizQuestions' => $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz'
                ? $this->lesson->quizQuestions->map->only(['question', 'options', 'correct_index', 'explanation'])->values()->all()
                : [],
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

    /** Shown right after a successful publish: the "your lesson is live" moment. */
    public bool $showPublishSplash = false;

    public function publish(): void
    {
        if (! $this->allReady) {
            $this->publishError = 'All scenes must be in "ready" status before publishing.';

            return;
        }

        $incompleteGroups = $this->incompleteStoryGameGroups();
        if ($incompleteGroups !== []) {
            $this->publishError = __('Story game incomplete — choice :groups still missing game effects on an option. Open Configure and fill both options\' meter effects first.', [
                'groups' => implode(', ', $incompleteGroups),
            ]);

            return;
        }

        $this->lesson->update(['status' => LessonStatus::Published]);
        $this->publishError = '';
        $this->showPublishSplash = true;
        $this->dispatch('lesson-published');
    }

    /**
     * Story-game publish gate: every branch OPTION scene must carry meter deltas
     * (config.branch_effects.deltas) before the lesson can go live — otherwise the
     * player would show a choice with no consequences. Non-story_game lessons pass.
     *
     * @return list<int> incomplete branch group numbers, ascending
     */
    private function incompleteStoryGameGroups(): array
    {
        if ($this->lesson->game_type !== 'story_game') {
            return [];
        }

        return $this->lesson->scenes()
            ->whereIn('branch_role', ['option_a', 'option_b'])
            ->get()
            ->filter(fn (Scene $s) => empty((($s->config ?? [])['branch_effects']['deltas'] ?? null)))
            ->map(fn (Scene $s) => (int) ($s->branch_group ?? 0))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function dismissPublishSplash(): void
    {
        $this->showPublishSplash = false;
    }

    /** The teacher's own classes — for the publish modal's "Assign to class" picker. */
    #[Computed]
    public function classrooms()
    {
        return auth()->user()->classrooms()->orderBy('name')->get(['id', 'name']);
    }

    /** Ids of the classes this lesson is already assigned to (drives the ✓ in the picker). */
    #[Computed]
    public function assignedClassIds(): array
    {
        return $this->lesson->classrooms()->pluck('classrooms.id')->all();
    }

    /** Toggle this lesson's assignment to one of the teacher's classes (from the publish modal). */
    public function assignToClass(int $classroomId): void
    {
        $class = auth()->user()->classrooms()->find($classroomId);
        if (! $class) {
            return;
        }
        if (in_array($classroomId, $this->assignedClassIds, true)) {
            $class->lessons()->detach($this->lesson->id);
        } else {
            $class->lessons()->syncWithoutDetaching([$this->lesson->id => ['assigned_at' => now()]]);
        }
        unset($this->assignedClassIds);   // recompute the ✓ state
    }

    public function render()
    {
        return view('livewire.wizard.step4-preview');
    }
}
