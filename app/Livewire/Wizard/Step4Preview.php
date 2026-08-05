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
        abort_unless(auth()->user()?->canManage($lesson), 403);
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

    /** Memoised default idle clip path: identical for every scene, so look it up once. */
    private string|false|null $idleGlbPath = null;

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    /**
     * Every scene's payload, keyed by id, built once for the page.
     *
     * The Play step used to fetch this one scene at a time: clicking a thumbnail fired
     * wire:click="selectScene", waited for a round trip, and got back a payload derived entirely
     * from rows the page had already loaded — about a second of pure latency per click on
     * production. Handing the whole map to the browser up front lets the rail switch instantly;
     * see switchScene() in step4-preview.blade.php.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function scenePayloads(): array
    {
        // Warm what the payload builder reaches for, so this is a handful of queries and not one
        // per scene: quiz questions are a single relation load, animation clips one whereIn.
        $this->lesson->loadMissing('quizQuestions');

        $clipIds = $this->scenes->pluck('animation_clip_id')->filter()->unique();
        $clips = $clipIds->isEmpty()
            ? collect()
            : AnimationClip::whereKey($clipIds)->get()->keyBy('id');

        return $this->scenes
            ->mapWithKeys(fn (Scene $scene) => [$scene->id => $this->scenePayload($scene, $clips)])
            ->all();
    }

    private function selectSceneInternal(int $id): void
    {
        $scene = $this->lesson->scenes()->find($id);
        if (! $scene) {
            return;
        }
        $this->selectedSceneId = $id;
        $this->dispatch('scene:load', payload: $this->scenePayload($scene));
    }

    /**
     * One scene's stage payload. Shared by the server dispatch and the pre-built client map, so the
     * two can never describe a scene differently.
     *
     * @param  \Illuminate\Support\Collection<int,AnimationClip>|null  $clips  preloaded clips, when batching
     * @return array<string,mixed>
     */
    private function scenePayload(Scene $scene, ?\Illuminate\Support\Collection $clips = null): array
    {
        return [
            'sceneId' => $scene->id,
            'imageUrl' => $scene->image_path ? asset('storage/'.$scene->image_path) : null,
            'audioUrl' => $scene->audio_path ? asset('storage/'.$scene->audio_path) : null,
            'animationClipUrl' => $this->animationGlbUrlFor($scene, $clips),
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
            // Per-scene flags the stage reads straight off the config (background focus/fit, and
            // the video / 3D embed background).
            'config' => $scene->config,
            // Teacher text annotations — read-only here (editing lives in Configure).
            'textsReadonly' => (array) (($scene->config ?? [])['texts'] ?? []),
            // Quiz scenes preview their questions on the canvas, same as Configure.
            'quizQuestions' => $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz'
                ? $this->lesson->quizQuestions->map->only(['question', 'options', 'correct_index', 'explanation'])->values()->all()
                : [],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,AnimationClip>|null  $clips
     *   Preloaded clips, so building payloads for every scene is not one query per scene.
     */
    private function animationGlbUrlFor(Scene $scene, ?\Illuminate\Support\Collection $clips = null): ?string
    {
        if ($scene->animation_clip_id) {
            $clip = $clips
                ? $clips->get($scene->animation_clip_id)
                : AnimationClip::find($scene->animation_clip_id);

            if ($clip?->glb_path) {
                return $clip->glbUrl();
            }
        }

        // The default idle clip is the same for every scene; resolve it once per request.
        $this->idleGlbPath ??= AnimationClip::where('category', 'idle')
            ->whereNotNull('glb_path')
            ->orderBy('sort_order')
            ->value('glb_path') ?? false;

        return $this->idleGlbPath ? asset($this->idleGlbPath) : null;
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

    /** Clear a pending scheduled publish (from the preview's scheduled badge). */
    public function cancelSchedule(): void
    {
        $this->lesson->update(['scheduled_publish_at' => null]);
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
