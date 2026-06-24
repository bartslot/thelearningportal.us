<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\EnhanceSkyboxImage;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Jobs\GenerateSkyboxImage;
use App\Jobs\GenerateWorldLabsScene;
use App\Models\AnimationClip;
use App\Models\AvatarAnimationController;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\StrategyGame;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Step3SceneConfigurator extends Component
{
    private const EDITABLE_FIELDS = [
        'config',
        'year', 'location', 'script_segment', 'image_prompt', 'image_style',
        'animation_clip_id', 'duration_seconds',
        'game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game_id', 'team_count',
        'skybox_blur', 'skybox_opacity', 'background_color', 'scene_view',
        'world_y_offset', 'world_scale', 'world_char_scale',
    ];

    public Lesson $lesson;

    public ?int $selectedSceneId = null;

    /** @var array<string,mixed>|null */
    public ?array $selectedScene = null;

    public bool $inspectorOpen = true;

    public bool $addSceneOpen = false;

    public ?string $prevSelectedStatus = null;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
        $this->lesson->update(['status' => LessonStatus::Configuring, 'wizard_step' => 4]);

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
        $ids = collect($ctrl?->controller ?? [])->flatten()->all();

        return AnimationClip::whereIn('id', $ids)->orderBy('category')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function games()
    {
        return StrategyGame::active()->orderBy('title')->get();
    }

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    private function selectSceneInternal(int $id): void
    {
        $scene = $this->lesson->scenes()->findOrFail($id);
        $this->selectedSceneId = $id;
        $this->selectedScene = $this->snapshot($scene);

        $ts = $scene->updated_at?->timestamp ?? '';
        $view = $scene->scene_view ?? 'slideshow';
        // Skybox view uses the equirectangular panorama if available; fall back to flat image.
        // Slideshow view always uses the flat image.
        $imagePath = ($view === 'skybox' && ! empty($scene->skybox_image_path))
            ? $scene->skybox_image_path
            : $scene->image_path;

        $this->dispatch('scene:load', payload: [
            'sceneId' => $scene->id,
            'imageUrl' => $imagePath ? asset('storage/'.$imagePath).'?v='.$ts : null,
            'hasSkyboxImage' => ! empty($scene->skybox_image_path),
            'audioUrl' => $scene->audio_path ? asset('storage/'.$scene->audio_path) : null,
            'animationClipId' => $scene->animation_clip_id,
            'animationClipUrl' => $this->animationGlbUrlFor($scene),
            'year' => $scene->year,
            'location' => $scene->location,
            'kind' => $scene->kind,
            'config' => $scene->config,
            'gameType' => $scene->game_type,
            'quizQuestionCount' => $scene->quiz_question_count,
            'quizTiming' => $scene->quiz_timing,
            'strategyGameId' => $scene->strategy_game_id,
            'teamCount' => $scene->team_count,
            'duration' => $scene->duration_seconds,
            'skyboxBlur' => (float) ($scene->skybox_blur ?? 0.5),
            'skyboxOpacity' => (float) ($scene->skybox_opacity ?? 1.0),
            'backgroundColor' => (string) ($scene->background_color ?? '#000000'),
            'sceneView' => (string) ($scene->scene_view ?? 'skybox'),
            'worldPanoUrl' => $scene->world_pano_path ? asset('storage/'.$scene->world_pano_path) : null,
            'worldSpzUrl' => $scene->world_spz_path ? asset('storage/'.$scene->world_spz_path) : null,
            'worldGlbUrl' => $scene->world_glb_path ? asset('storage/'.$scene->world_glb_path) : null,
            'worldLabsStatus' => (string) ($scene->world_labs_status ?? ''),
            'worldYOffset' => (float) ($scene->world_y_offset ?? 0),
            'worldScale' => (float) ($scene->world_scale ?? 1),
            'worldCharScale' => (float) ($scene->world_char_scale ?? 0.53),
            'worldSemantics' => [
                'groundPlaneOffset' => (float) (($scene->world_semantics ?? [])['ground_plane_offset'] ?? 0),
                'flipY' => (bool) (($scene->world_semantics ?? [])['flip_y'] ?? true),
                'metricScaleFactor' => (float) (($scene->world_semantics ?? [])['metric_scale_factor'] ?? 1),
            ],
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
            'audioUrl' => asset('storage/'.$s->audio_path),
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
        $snap['image_path'] = $scene->image_path;
        $snap['skybox_image_path'] = $scene->skybox_image_path;
        $snap['audio_path'] = $scene->audio_path;
        $snap['status'] = $scene->status;
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

        $payload = collect($this->selectedScene)->only(self::EDITABLE_FIELDS)->all();
        foreach (['animation_clip_id', 'strategy_game_id'] as $nullableId) {
            if (array_key_exists($nullableId, $payload) && $payload[$nullableId] === '') {
                $payload[$nullableId] = null;
            }
        }
        $scriptDirty = ($scene->script_segment ?? '') !== ($payload['script_segment'] ?? '');

        // Detect changes that should re-paint the 3D stage so the canvas updates.
        $stageDirty = (int) ($payload['animation_clip_id'] ?? 0) !== (int) ($scene->animation_clip_id ?? 0)
            || ($payload['year'] ?? null) !== ($scene->year ?? null)
            || ($payload['location'] ?? null) !== ($scene->location ?? null)
            || ($payload['scene_view'] ?? null) !== ($scene->scene_view ?? null);

        $scene->update($payload);

        if ($scriptDirty) {
            $scene->update(['audio_script_hash' => null]);
        }

        if ($stageDirty) {
            $this->selectSceneInternal($scene->id);
        }
    }

    // ── Map block: territory picker ──────────────────────────────────────
    // Search the corpus for a polity and link its Wikidata QID so the map fits + paints an
    // accurate historical boundary (red). Mirrors Step 2's hero picker. The map block's QID
    // otherwise only auto-fills from a `polity:` catalog topic, leaving city/free-text lessons blank.
    public string $territoryQuery = '';

    #[Computed]
    public function territoryResults()
    {
        $q = trim($this->territoryQuery);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        return \App\Models\Corpus\Topic::resilient(
            fn () => \App\Models\Corpus\Topic::query()
                ->where('id', 'like', 'polity:%')
                ->where('name', 'ilike', '%'.$q.'%')
                ->orderByRaw('length(name)')   // prefer the shortest (most exact) match first
                ->limit(8)
                ->get(['id', 'qid', 'name', 'region_label', 'era_start', 'era_end'])
        ) ?? collect();
    }

    public function linkTerritory(string $qid): void
    {
        if (! $this->selectedScene) {
            return;
        }

        $topic = \App\Models\Corpus\Topic::resilient(
            fn () => \App\Models\Corpus\Topic::query()
                ->where('id', 'like', 'polity:%')
                ->where('qid', $qid)
                ->first()
        );
        if (! $topic) {
            return;
        }

        $start = $topic->era_start;
        $end = $topic->era_end;
        $midYear = ($start !== null && $end !== null) ? (int) (($start + $end) / 2) : ($start ?? $end);

        $this->selectedScene['config']['qid'] = $topic->qid;
        // Seed the time slider to the polity's mid-life only if the teacher hasn't set a year.
        if ($midYear !== null && empty($this->selectedScene['config']['year'])) {
            $this->selectedScene['config']['year'] = $midYear;
        }
        $this->selectedScene['location'] = $topic->name;

        $this->territoryQuery = '';
        unset($this->territoryResults);
        $this->saveSelected();   // location change → stageDirty → scene:load → map re-renders with the new QID
    }

    public function unlinkTerritory(): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $this->selectedScene['config']['qid'] = null;
        $this->saveSelected();
    }

    // ── Map block: focus-city annotations ────────────────────────────────
    // The map preview (editable) drops/drags red "focus" dots and dispatches the whole array
    // back here. Annotations live in scene.config.annotations as:
    //   ['type' => 'focus', 'lng' => <float>, 'lat' => <float>, 'label' => <string ≤80>]
    // Designed for extension: unknown future types (arrows, markers) pass through untouched so
    // older data is never destroyed; phase 1 only coerces 'focus' items.
    private const FOCUS_LABEL_MAX = 80;

    #[On('annotationsChanged')]
    public function updateAnnotations(int $sceneId, array $annotations): void
    {
        $scene = $this->lesson->scenes()->find($sceneId);
        if (! $scene) {
            return;
        }

        $clean = $this->sanitizeAnnotations($annotations);

        $scene->config = array_merge($scene->config ?? [], ['annotations' => $clean]);
        $scene->save();

        // Keep the inspector list in sync if this is the scene currently open.
        if ($this->selectedSceneId === $sceneId && $this->selectedScene !== null) {
            $this->selectedScene['config'] = array_merge(
                $this->selectedScene['config'] ?? [],
                ['annotations' => $clean]
            );
        }
    }

    public function renameFocus(int $index, string $label): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $annotations = $this->selectedScene['config']['annotations'] ?? [];
        if (! array_key_exists($index, $annotations) || ($annotations[$index]['type'] ?? null) !== 'focus') {
            return;
        }
        $annotations[$index]['label'] = mb_substr(trim($label), 0, self::FOCUS_LABEL_MAX);
        $this->selectedScene['config']['annotations'] = $annotations;
        $this->saveSelected();
    }

    public function removeFocus(int $index): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $annotations = $this->selectedScene['config']['annotations'] ?? [];
        if (! array_key_exists($index, $annotations)) {
            return;
        }
        array_splice($annotations, $index, 1);
        $this->selectedScene['config']['annotations'] = array_values($annotations);
        $this->saveSelected();
    }

    /**
     * Coerce focus items to a safe shape; pass unknown types through untouched so future
     * annotation kinds survive a phase-1 save. Drops malformed focus items.
     *
     * @param  array<int,mixed>  $annotations
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeAnnotations(array $annotations): array
    {
        $clean = [];
        foreach ($annotations as $a) {
            if (! is_array($a)) {
                continue;
            }
            $type = $a['type'] ?? null;

            if ($type === 'focus') {
                if (! isset($a['lng'], $a['lat']) || ! is_numeric($a['lng']) || ! is_numeric($a['lat'])) {
                    continue;
                }
                $clean[] = [
                    'type' => 'focus',
                    'lng' => (float) $a['lng'],
                    'lat' => (float) $a['lat'],
                    'label' => mb_substr(trim((string) ($a['label'] ?? '')), 0, self::FOCUS_LABEL_MAX),
                ];

                continue;
            }

            // Unknown/future type — keep as-is so we never destroy data we don't yet understand.
            if ($type !== null) {
                $clean[] = $a;
            }
        }

        return $clean;
    }

    public function setSceneView(string $view): void
    {
        if ($this->selectedScene) {
            $this->selectedScene['scene_view'] = $view;
        }
        $this->saveSelected();
    }

    #[On('reorder')]
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

        $this->syncGameSceneIndexes();
    }

    public function generateSkyboxImage(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        if (! $scene->image_path) {
            $this->dispatch('toast', message: 'Generate the flat image first.', type: 'warning');

            return;
        }

        $scene->update(['status' => 'generating', 'error_message' => null]);
        GenerateSkyboxImage::dispatch($sceneId);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    public function enhanceSkybox(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        if (! $scene->skybox_image_path) {
            $this->dispatch('toast', message: 'Generate the panorama image first before enhancing.', type: 'warning');

            return;
        }

        $scene->update(['status' => 'generating', 'error_message' => null]);
        EnhanceSkyboxImage::dispatch($sceneId);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    public function regenerate(int $sceneId, string $asset): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $scene->update(['status' => 'generating', 'error_message' => null]);

        match ($asset) {
            'script' => GenerateSceneScript::dispatch($scene->id),
            'image' => GenerateSceneImage::dispatch($scene->id),
            'audio' => GenerateSceneAudio::dispatch($scene->id),
            'world' => $this->generateWorld($scene->id),
            default => null,
        };
    }

    public function generateWorld(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        if (! $scene->image_path) {
            $this->dispatch('toast', message: 'Generate the panorama image first before creating a WorldLabs world.', type: 'warning');

            return;
        }

        $scene->update(['world_labs_status' => 'pending', 'scene_view' => 'world']);
        GenerateWorldLabsScene::dispatch($scene->id);

        // Reload via selectSceneInternal so scene:load fires → canvas switches to waiting state
        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    public function addScene(string $kind = 'narration', ?string $gameType = null): void
    {
        $this->addSceneOpen = false;
        $kind = in_array($kind, ['game', 'map'], true) ? $kind : 'narration';
        $gameType = in_array($gameType, ['quiz', 'strategy', 'debate'], true) ? $gameType : null;
        $next = ((int) $this->lesson->scenes()->max('order')) + 1;

        $payload = [
            'lesson_id' => $this->lesson->id,
            'order' => $next,
            'kind' => $kind,
            'image_style' => $this->lesson->image_style,
            'status' => $kind === 'map' ? 'ready' : 'pending',
        ];

        if ($kind === 'map') {
            $payload += Scene::mapPayloadForLesson($this->lesson);
            $scene = Scene::create($payload);
            $this->selectSceneInternal($scene->id);

            return;
        }

        if ($kind === 'game') {
            $gameCount = $this->lesson->scenes()->where('kind', 'game')->count();
            $gameType ??= $this->lesson->game_type ?: 'quiz';

            $payload += [
                'game_type' => $gameType,
                'game_segment_index' => $gameCount + 1,
                'duration_seconds' => $gameType === 'strategy' ? 600 : 180,
                'quiz_question_count' => $gameType === 'quiz' ? (int) ($this->lesson->quiz_question_count ?? 4) : null,
                'quiz_timing' => $gameType === 'quiz' ? ($this->lesson->quiz_timing ?? 'after') : null,
                'strategy_game_id' => $gameType === 'strategy' ? $this->defaultStrategyGameId() : null,
                'team_count' => $gameType === 'strategy' ? (int) ($this->lesson->team_count ?? 2) : null,
            ];

            $this->lesson->fill([
                'include_game' => true,
                'game_type' => $this->lesson->game_type ?: $gameType,
                'game_split_count' => $gameCount + 1,
            ])->save();
            $this->lesson->refresh();
        }

        $scene = Scene::create($payload);

        $this->selectSceneInternal($scene->id);
    }

    public function deleteScene(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
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
            $this->syncGameSceneIndexes();
        }

        if ($this->selectedSceneId === $sceneId) {
            $first = $this->lesson->scenes()->ordered()->first();
            if ($first) {
                $this->selectSceneInternal($first->id);
            } else {
                $this->selectedSceneId = null;
                $this->selectedScene = null;
            }
        }
    }

    public function setSceneGameType(int $sceneId, string $gameType): void
    {
        if (! in_array($gameType, ['quiz', 'strategy', 'debate'], true)) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        if ($scene->kind !== 'game') {
            return;
        }

        $scene->update([
            'game_type' => $gameType,
            'duration_seconds' => $gameType === 'strategy'
                ? ($scene->duration_seconds ?: 600)
                : ($scene->duration_seconds ?: 180),
            'quiz_question_count' => $gameType === 'quiz' ? (int) ($scene->quiz_question_count ?? $this->lesson->quiz_question_count ?? 4) : null,
            'quiz_timing' => $gameType === 'quiz' ? ($scene->quiz_timing ?? $this->lesson->quiz_timing ?? 'after') : null,
            'strategy_game_id' => $gameType === 'strategy' ? ($scene->strategy_game_id ?? $this->defaultStrategyGameId()) : null,
            'team_count' => $gameType === 'strategy' ? (int) ($scene->team_count ?? $this->lesson->team_count ?? 2) : null,
        ]);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    private function syncGameSceneIndexes(): void
    {
        $games = $this->lesson->scenes()->where('kind', 'game')->ordered()->get();
        foreach ($games as $idx => $game) {
            $game->update(['game_segment_index' => $idx + 1]);
        }

        $this->lesson->update([
            'include_game' => $games->isNotEmpty(),
            'game_split_count' => max(1, $games->count()),
        ]);
        $this->lesson->refresh();
    }

    private function defaultStrategyGameId(): ?int
    {
        return $this->lesson->strategy_game_id
            ?? StrategyGame::matchForLesson($this->lesson)?->id
            ?? StrategyGame::active()->orderBy('title')->value('id');
    }

    public function saveWorldSettings(float $yOffset, float $scale, float $charScale): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        Scene::where('lesson_id', $this->lesson->id)
            ->findOrFail($this->selectedSceneId)
            ->update([
                'world_y_offset' => $yOffset,
                'world_scale' => $scale,
                'world_char_scale' => $charScale,
            ]);
        if ($this->selectedScene) {
            $this->selectedScene['world_y_offset'] = $yOffset;
            $this->selectedScene['world_scale'] = $scale;
            $this->selectedScene['world_char_scale'] = $charScale;
        }
    }

    /** Static music track catalogue (slot → file in public/sound/bg-music/). */
    public function musicTracks(): array
    {
        return [
            ['id' => 'default',    'label' => 'Ancient',     'file' => 'default.mp3', 'gradient_class' => 'vg-indigo'],
            ['id' => 'track2',     'label' => 'Epic',        'file' => 'default.mp3', 'gradient_class' => 'vg-violet'],
            ['id' => 'track3',     'label' => 'Mystical',    'file' => 'default.mp3', 'gradient_class' => 'vg-teal'],
            ['id' => 'track4',     'label' => 'Battle',      'file' => 'default.mp3', 'gradient_class' => 'vg-navy'],
            ['id' => 'track5',     'label' => 'Peaceful',    'file' => 'default.mp3', 'gradient_class' => 'vg-amber'],
            ['id' => 'track6',     'label' => 'Dramatic',    'file' => 'default.mp3', 'gradient_class' => 'vg-base'],
        ];
    }

    public function selectMusic(string $trackId): void
    {
        $track = collect($this->musicTracks())->firstWhere('id', $trackId);
        $this->lesson->update(['background_music' => $track ? $track['id'] : null]);
        $this->lesson->refresh();
    }

    public function continueToPreview(): void
    {
        $this->lesson->update(['wizard_step' => 5, 'status' => LessonStatus::Previewable]);
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 5], navigate: true);
    }

    /** Called on every poll tick — pushes updated world status to the canvas. */
    public function pollWorldStatus(): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->find($this->selectedSceneId);
        if (! $scene || $scene->scene_view !== 'world') {
            return;
        }

        $semantics = $scene->world_semantics ?? [];
        $this->dispatch('scene:worldstatus', payload: [
            'sceneId' => $scene->id,
            'worldLabsStatus' => (string) ($scene->world_labs_status ?? ''),
            'worldPanoUrl' => $scene->world_pano_path ? asset('storage/'.$scene->world_pano_path) : null,
            'worldSpzUrl' => $scene->world_spz_path ? asset('storage/'.$scene->world_spz_path) : null,
            'worldGlbUrl' => $scene->world_glb_path ? asset('storage/'.$scene->world_glb_path) : null,
            'worldSemantics' => [
                'groundPlaneOffset' => (float) ($semantics['ground_plane_offset'] ?? 0),
                'flipY' => (bool) ($semantics['flip_y'] ?? true),
                'metricScaleFactor' => (float) ($semantics['metric_scale_factor'] ?? 1),
            ],
        ]);
    }

    /** Re-fire scene:load whenever the selected scene's status changes. */
    private function pollSceneReady(): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->find($this->selectedSceneId);
        if (! $scene) {
            return;
        }

        $currentStatus = (string) $scene->status;

        if ($currentStatus !== $this->prevSelectedStatus) {
            $this->selectSceneInternal($scene->id);
        }

        $this->prevSelectedStatus = $currentStatus;
    }

    public function render()
    {
        $this->pollWorldStatus();
        $this->pollSceneReady();

        return view('livewire.wizard.step3-scene-configurator');
    }
}
