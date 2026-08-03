<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\EnhanceSkyboxImage;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneScript;
use App\Jobs\GenerateSkyboxCandidates;
use App\Jobs\GenerateSkyboxImage;
use App\Jobs\GenerateWorldLabsScene;
use App\Livewire\Wizard\Concerns\BlocksGuestDemoSpending;
use App\Livewire\Wizard\Concerns\EditsQuizQuestions;
use App\Livewire\Wizard\Concerns\EditsSceneArtwork;
use App\Livewire\Wizard\Concerns\EditsStoryGame;
use App\Models\AnimationClip;
use App\Models\City;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\StrategyGame;
use App\Support\PolityCapitals;
use App\Support\PortraitFocus;
use App\Support\SafeOutboundUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Step3SceneConfigurator extends Component
{
    use BlocksGuestDemoSpending;
    use EditsQuizQuestions;
    use EditsSceneArtwork;
    use EditsStoryGame;
    use WithFileUploads;

    /** Teacher's own image file, uploaded from the Add-image modal. */
    public $uploadImage = null;

    private const EDITABLE_FIELDS = [
        'config',
        'year', 'location', 'chapter_name', 'script_segment', 'image_prompt', 'image_style',
        'animation_clip_id', 'duration_seconds',
        'game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game_id', 'team_count',
        'skybox_blur', 'skybox_opacity', 'background_color', 'kb_animated', 'kb_direction', 'scene_view',
        'world_y_offset', 'world_scale', 'world_char_scale',
    ];

    /** Brand deep-navy — the solid backdrop a scene falls back to when its image is removed. */
    public const BRAND_BACKGROUND = '#0f172a';

    /** Mirrors BuildLessonOutline::MAX_BRANCH_GROUPS — hand-authored branches obey the same ceiling. */
    private const MAX_BRANCH_GROUPS = 3;

    /**
     * Meters seeded on the first hand-authored branch. The generator invents topic-specific meters;
     * authoring by hand has no LLM to invent anything, and meter KEYS are immutable once set, so
     * these are deliberately generic — the teacher renames the labels/icons in the Meters panel.
     */
    private const DEFAULT_STORY_METERS = [
        ['key' => 'support', 'label' => 'Support', 'icon' => '🤝', 'start' => 50],
        ['key' => 'resources', 'label' => 'Resources', 'icon' => '💰', 'start' => 50],
        ['key' => 'risk', 'label' => 'Risk', 'icon' => '⚠️', 'start' => 20],
    ];

    public Lesson $lesson;

    // Kept in the URL (?scene=<id>) so a plain refresh stays on the scene the teacher was editing
    // instead of snapping back to the first one. Mount reads the same param for the preview deep-link.
    #[Url(as: 'scene')]
    public ?int $selectedSceneId = null;

    /** @var array<string,mixed>|null */
    public ?array $selectedScene = null;

    /** Text or backing-panel id whose controls fill the Format inspector. */
    public ?string $activeTextId = null;

    public bool $inspectorOpen = true;

    public bool $addSceneOpen = false;

    /**
     * The scene the teacher just deleted, as [id, order], so Cmd-Z (or the Undo in the toast) can put
     * it back where it was. One deep: the rail's delete is a single click, and a teacher who wants a
     * whole batch back reaches for the browser's back button, not a history stack.
     *
     * @var array{id: int, order: int}|null
     */
    public ?array $deletedScene = null;

    /** Right-panel view: 'scene' (per-scene editor) or 'settings' (lesson-global Story + Music). */
    public string $panelView = 'scene';

    /** Transient Publish feedback banner (no app-wide toast system exists yet). */
    public ?string $publishNotice = null;

    public bool $publishOk = false;

    public ?string $prevSelectedStatus = null;

    /** Set from ?modal=1 on a preview deep-link — the JS reopens the landfall gallery once mounted. */
    public bool $openModalOnLoad = false;

    public function mount(Lesson $lesson): void
    {
        abort_unless(auth()->user()?->canManage($lesson), 403);
        $this->lesson = $lesson;
        $this->lesson->update(['status' => LessonStatus::Configuring, 'wizard_step' => 4]);

        // Repair legacy lessons where two voyage scenes shared one leg (older "add leg" reused the
        // last catalog leg) — so editing one leg's destination no longer drags another's.
        $this->ensureUniqueVoyageLegs();

        // Deep-link from the owner-preview "Edit scene" (?scene=&modal=): open the EXACT scene the
        // teacher was viewing, and remember whether its gallery/landfall modal was open.
        $deepScene = request()->integer('scene');
        $target = ($deepScene && $this->lesson->scenes()->whereKey($deepScene)->exists())
            ? $deepScene
            : optional($this->lesson->scenes()->ordered()->first())->id;

        if ($target) {
            $this->selectSceneInternal($target);
        }
        $this->openModalOnLoad = request()->boolean('modal');
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
    public function games()
    {
        return StrategyGame::active()->orderBy('title')->get();
    }

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    #[On('scene:add')]
    public function openAddScenePicker(): void
    {
        $this->addSceneOpen = true;
    }

    /**
     * URL-form shots for the preview JS (scene:load payload AND the inert
     * scenes JSON in the blade — the bridge falls back to the latter when the
     * initial scene:load dispatch is missed during hydration).
     * Mirrors the lesson player's serialization in resources/views/lesson/player.blade.php.
     */
    public function serializeShots(Scene $scene, ?Collection $titles = null): array
    {
        $ts = $scene->updated_at?->timestamp ?? '';

        // Asset titles for the object-list labels ("Windmill silhouette", not "Clipart").
        // Callers rendering MANY scenes pass a pre-batched title map (one query for all scenes);
        // a single-scene caller passes null and we look up just this scene's assets.
        $titles ??= $this->assetTitlesFor([$scene]);

        return collect($scene->shots ?? [])->map(fn ($shot) => [
            'image_url' => ! empty($shot['image_path']) ? asset('storage/'.$shot['image_path']).'?v='.$ts : null,
            // bg_url/hero_url (E3b story-pack shots) — parallax layers, see ParallaxScene.js.
            'bg_url' => ! empty($shot['bg_path']) ? asset('storage/'.$shot['bg_path']).'?v='.$ts : null,
            'hero_url' => ! empty($shot['hero_path']) ? asset('storage/'.$shot['hero_path']).'?v='.$ts : null,
            'anchor_sentence' => $shot['anchor_sentence'] ?? null,
            // Multiplane layers (E3c): [{path|url, depth, kind, scale, height, sway}] back→front.
            'layers' => collect($shot['layers'] ?? [])->map(fn ($l) => [
                'url' => ! empty($l['path']) ? asset('storage/'.$l['path']).'?v='.$ts : ($l['url'] ?? null),
                // asset_id + x/y let the on-canvas editor identify and free-position each layer.
                'asset_id' => isset($l['asset_id']) ? (int) $l['asset_id'] : null,
                'title' => isset($l['asset_id']) ? ($titles[$l['asset_id']] ?? null) : null,
                'x' => isset($l['x']) ? (float) $l['x'] : null,
                'y' => isset($l['y']) ? (float) $l['y'] : null,
                'depth' => (float) ($l['depth'] ?? 1),
                'kind' => in_array($l['kind'] ?? 'cover', ['cover', 'figure', 'strip'], true) ? ($l['kind'] ?? 'cover') : 'cover',
                'scale' => (float) ($l['scale'] ?? 1),
                'height' => isset($l['height']) ? (float) $l['height'] : null,
                'sway' => (bool) ($l['sway'] ?? false),
                'blur' => isset($l['blur']) ? (float) $l['blur'] : null,
                'opacity' => isset($l['opacity']) ? (float) $l['opacity'] : null,
                'blend' => in_array($l['blend'] ?? null, ['multiply', 'screen', 'overlay', 'darken', 'lighten'], true) ? $l['blend'] : null,
                // Pinned to a place on the map beneath the stage, rather than to the stage itself.
                'anchor' => ($l['anchor'] ?? null) === 'map' ? 'map' : null,
                'lng' => isset($l['lng']) ? (float) $l['lng'] : null,
                'lat' => isset($l['lat']) ? (float) $l['lat'] : null,
                // Animate tab: entrance movement, its delay in seconds, and the easing key.
                'anim' => $l['anim'] ?? null,
                'anim_delay' => isset($l['anim_delay']) ? (float) $l['anim_delay'] : null,
                'anim_ease' => $l['anim_ease'] ?? null,
                'wobble' => isset($l['wobble']) ? (int) $l['wobble'] : null,
                // Colour treatment — knock out the paper, drain the colour, recolour the ink.
                // Tint reaches a line-art icon too: it floods INTO the source's alpha, which for
                // an icon is the strokes, so it is also what rescues black ink on a night scene.
                'white_key' => isset($l['white_key']) ? (float) $l['white_key'] : null,
                'grayscale' => ! empty($l['grayscale']),
                'tint' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($l['tint'] ?? '')) ? $l['tint'] : null,
                'z' => isset($l['z']) ? (int) $l['z'] : null,
                // Drawing-mode ink controls (per layer).
                'ink_preset' => $l['ink_preset'] ?? null,
                'ink_fill' => $l['ink_fill'] ?? null,
                'draw_time' => isset($l['draw_time']) ? (float) $l['draw_time'] : null,
                // Embed layers (3D / video) carry an iframe embed instead of an image url.
                'embed' => isset($l['embed']) && is_array($l['embed']) ? $l['embed'] : null,
            ])->filter(fn ($l) => $l['url'] || $l['embed'])->values()->all() ?: null,
            // Keep a shot when it has EITHER a background image OR clipart layers. Map-backed scenes
            // (voyage / map) carry layer-only shots — the MAP is the backdrop, so there's no image_url,
            // but the clipart layers must still reach the editor/player overlay.
        ])->filter(fn ($shot) => $shot['image_url'] || ! empty($shot['layers']))->values()->all();
    }

    /**
     * Batch-load SvgAsset id→title for every layer across the given scenes in ONE query
     * (avoids an N+1 of one SvgAsset lookup per scene when serializing the whole payload).
     *
     * @param  iterable<Scene>  $scenes
     * @return Collection<int,string>
     */
    private function assetTitlesFor(iterable $scenes): Collection
    {
        $ids = collect($scenes)
            ->flatMap(fn ($s) => collect($s->shots ?? [])
                ->flatMap(fn ($shot) => collect($shot['layers'] ?? [])->pluck('asset_id')))
            ->filter()->unique()->values();

        return $ids->isNotEmpty()
            ? \App\Models\SvgAsset::whereIn('id', $ids)->pluck('title', 'id')
            : collect();
    }

    /**
     * Full inert scenes payload for the first-paint hydration fallback (#step3-scenes-data).
     * Batches asset titles once for all scenes, then serializes each. Read once by the preview
     * bridge on initial paint; live edits flow through scene:load, not this blob.
     *
     * @return array<int,array<string,mixed>>
     */
    public function scenesData(): array
    {
        $scenes = $this->scenes;
        $titles = $this->assetTitlesFor($scenes);

        return $scenes->map(fn (Scene $s) => array_merge(
            $s->only(['id', 'kind', 'game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game_id', 'team_count', 'year', 'location', 'image_path', 'scene_view', 'skybox_blur', 'world_pano_path', 'audio_path', 'audio_alignment', 'duration_seconds', 'script_segment', 'animation_clip_id', 'background_color', 'kb_animated', 'kb_direction']),
            // config carries per-scene flags the first paint needs (background focus, clipart-on-top …).
            ['config' => $s->config ?? null],
            ['shots' => $this->serializeShots($s, $titles)],
        ))->all();
    }

    private function selectSceneInternal(int $id): void
    {
        if ($this->selectedSceneId !== $id) {
            $this->activeLayerId = null;
            $this->activeTextId = null;
        }

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
            'shots' => $this->serializeShots($scene),
            'hasSkyboxImage' => ! empty($scene->skybox_image_path),
            'audioUrl' => $scene->audio_path ? asset('storage/'.$scene->audio_path) : null,
            // The Script panel spins while narration is being made; without these it had no way to
            // learn the job had failed and kept spinning for good.
            'status' => (string) $scene->status,
            'errorMessage' => $scene->error_message,
            'animationClipId' => $scene->animation_clip_id,
            'animationClipUrl' => $this->animationGlbUrlFor($scene),
            'year' => $scene->year,
            'location' => $scene->location,
            'chapter_name' => $scene->chapter_name,
            // Editable scene identity: a per-scene title override + a hide flag.
            'identityTitle' => $scene->config['identity_title'] ?? null,
            'hideIdentity' => (bool) ($scene->config['hide_identity'] ?? false),
            'kind' => $scene->kind,
            // For voyages the projection is GLOBAL — inject the lesson-wide voyage_view so every
            // waypoint previews with the same flat/globe setting (the JS reads config.view).
            'config' => $scene->kind === 'voyage'
                ? array_merge($scene->config ?? [], ['view' => $this->voyageView()])
                : $scene->config,
            'lesson_map_style' => $this->lesson->map_style, // lesson-wide default a map block inherits
            'lesson_map_relief' => $this->lesson->map_relief, // 3D terrain exaggeration; 0 = flat
            // Voyage scenes preview against the lesson's editable route copy (falls back to the
            // shared catalog until the first edit clones it) — the wizard overlay passes this to
            // renderVoyageTour as `def`.
            'voyage_def' => $scene->kind === 'voyage' ? $this->voyageDef() : null,
            'route_line' => $scene->kind === 'voyage' ? $this->routeLine() : null,
            // Lesson-wide voyage-map detail (hide cities/borders, show place labels) + the landfall
            // labels themselves, derived from every voyage scene's location.
            'voyage_map' => $scene->kind === 'voyage' ? $this->voyageMap() : null,
            'leg_labels' => $scene->kind === 'voyage' ? $this->voyageLegLabels() : [],
            'voyage_fog' => $scene->kind === 'voyage' ? $this->voyageFog() : [],
            'gameType' => $scene->game_type,
            'quizQuestionCount' => $scene->quiz_question_count,
            // Quiz scenes preview their actual questions on the canvas.
            'quizQuestions' => $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz'
                ? $this->lesson->quizQuestions->where('scene_id', $scene->id)->values()
                    ->whenEmpty(fn () => $this->lesson->quizQuestions->whereNull('scene_id')->values())
                    ->map->only(['question', 'options', 'correct_index', 'asks_ahead', 'explanation'])->values()->all()
                : [],
            'quizTiming' => $scene->quiz_timing,
            'strategyGameId' => $scene->strategy_game_id,
            'teamCount' => $scene->team_count,
            'duration' => $scene->duration_seconds,
            'skyboxBlur' => (float) ($scene->skybox_blur ?? 0.5),
            'skyboxOpacity' => (float) ($scene->skybox_opacity ?? 1.0),
            'backgroundColor' => (string) ($scene->background_color ?? '#000000'),
            'kbAnimated' => (bool) ($scene->kb_animated ?? true),
            'kbDirection' => $scene->kb_direction,
            'focus' => $scene->config['background_focus'] ?? null,   // 'top' for portraits
            'slideshowMode' => (string) (($scene->config ?? [])['slideshow_mode']
                ?? ((($scene->config ?? [])['parallax'] ?? false) ? 'parallax' : 'standard')),
            'parallax' => (($scene->config ?? [])['slideshow_mode'] ?? null) === 'parallax'
                || (bool) (($scene->config ?? [])['parallax'] ?? false),
            'texts' => (array) (($scene->config ?? [])['texts'] ?? []),
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

        // Keep the quiz-question draft in step with the selected scene: loads on a real switch,
        // preserved across status-poll re-selects so unsaved edits aren't wiped mid-typing.
        $this->syncQuizDraftFor($scene);
        // Same discipline for the story-game effects draft (branch option scenes).
        $this->syncStoryGameDraftFor($scene);
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

    /**
     * Set the solid backdrop color in ONE round trip. The old two-call flow
     * ($wire.set + $wire.call) re-rendered between requests with the stale DB color,
     * which snapped the picker back until a second click.
     */
    public function setSceneBackgroundColor(string $color): void
    {
        if (! $this->selectedSceneId || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return;
        }

        $this->selectedScene['background_color'] = $color;
        $this->saveSelected();
    }

    /**
     * Animate tab (scene): how this scene replaces the one before it.
     *
     * One field per call so a select and a slider can each autosave on change. Written into the
     * scene config SNAPSHOT and saved through saveSelected — writing straight to the model would
     * be overwritten by the next save, which rebuilds config from that snapshot.
     */
    public function setSceneTransition(string $key, string $value): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        // The vocabulary is shared with resources/js/scene/animations.js, which plays it back.
        $clean = match ($key) {
            'type' => in_array($value, ['crossfade', 'slide-left', 'slide-right', 'slide-up', 'slide-down', 'cut'], true) ? $value : null,
            'ease' => in_array($value, ['enter', 'move', 'exit', 'pop', 'linear'], true) ? $value : null,
            // A transition longer than a few seconds stops reading as a transition and starts
            // reading as the lesson having stalled.
            'duration' => max(0.0, min(3.0, (float) $value)),
            default => null,
        };

        if ($clean === null) {
            return;
        }

        $config = $this->selectedScene['config'] ?? [];
        $transition = ($config['transition'] ?? []) + ['type' => 'crossfade', 'duration' => 0.8, 'ease' => 'move'];
        $transition[$key] = $clean;

        $config['transition'] = $transition;
        $this->selectedScene['config'] = $config;
        $this->saveSelected();
    }

    /** The "Questions" number field must add/remove draft slots, not just save a number. */
    public function updatedSelectedScene($value, string $key): void
    {
        if ($key === 'quiz_question_count') {
            $this->syncQuizDraftCount((int) $value);
        }
    }

    public function saveSelected(): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }

        $scene = Scene::where('lesson_id', $this->lesson->id)
            ->findOrFail($this->selectedSceneId);

        $payload = collect($this->selectedScene)->only(self::EDITABLE_FIELDS)->all();
        foreach (['animation_clip_id', 'strategy_game_id', 'kb_direction'] as $nullable) {
            if (array_key_exists($nullable, $payload) && $payload[$nullable] === '') {
                $payload[$nullable] = null;
            }
        }
        $scriptDirty = ($scene->script_segment ?? '') !== ($payload['script_segment'] ?? '');

        // Detect changes that should re-paint the 3D stage so the canvas updates.
        $stageDirty = (int) ($payload['animation_clip_id'] ?? 0) !== (int) ($scene->animation_clip_id ?? 0)
            || ($payload['year'] ?? null) !== ($scene->year ?? null)
            || ($payload['location'] ?? null) !== ($scene->location ?? null)
            || ($payload['scene_view'] ?? null) !== ($scene->scene_view ?? null)
            // Motion + backdrop settings re-paint the canvas so the teacher SEES the change.
            || (bool) ($payload['kb_animated'] ?? true) !== (bool) ($scene->kb_animated ?? true)
            || ($payload['kb_direction'] ?? null) !== ($scene->kb_direction ?? null)
            || ($payload['background_color'] ?? null) !== ($scene->background_color ?? null)
            // Config carries the map block's year/projection (and other per-kind settings) —
            // without this, editing the map YEAR saved fine but never re-fired scene:load,
            // so the live preview kept filtering borders/cities by the old year.
            || ($payload['config'] ?? []) != ($scene->config ?? []);

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

    /** Map block: live query for the focus-city typeahead (searches the cities corpus). */
    public string $cityQuery = '';

    /** The scene's chosen year (drives which polities existed then). */
    private function sceneYear(): ?int
    {
        $y = $this->selectedScene['config']['year'] ?? null;

        return is_numeric($y) ? (int) $y : null;
    }

    /**
     * Reference point for "surrounding-first" ranking: the already-linked territory's centroid, else
     * the first focus city placed on the map. Lets neighbours of what's on screen rank above far-off
     * homonyms.
     *
     * @return array{0: float, 1: float}|null [lat, lng]
     */
    private function territoryRefPoint(): ?array
    {
        $qid = $this->selectedScene['config']['qid'] ?? null;
        if ($qid) {
            $t = \App\Models\Corpus\Topic::resilient(
                fn () => \App\Models\Corpus\Topic::query()
                    ->where('id', 'like', 'polity:%')->where('qid', $qid)
                    ->first(['region_lat', 'region_lng'])
            );
            if ($t && $t->region_lat !== null && $t->region_lng !== null) {
                return [(float) $t->region_lat, (float) $t->region_lng];
            }
        }
        foreach (($this->selectedScene['config']['annotations'] ?? []) as $a) {
            if (($a['type'] ?? null) === 'focus' && isset($a['lat'], $a['lng']) && is_numeric($a['lat']) && is_numeric($a['lng'])) {
                return [(float) $a['lat'], (float) $a['lng']];
            }
        }

        return null;
    }

    #[Computed]
    public function territoryResults()
    {
        $q = trim($this->territoryQuery);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $year = $this->sceneYear();
        $ref = $this->territoryRefPoint();
        $tol = 10; // small slack only — a polity must be ACTIVE at the year; one that ended decades
        // earlier (e.g. Kingdom of Hungary †1546 for a 1614 scene) must not appear

        // Pull a wider set, then rank in PHP by name match + proximity (surrounding) + era closeness.
        $fetch = fn (bool $byYear) => \App\Models\Corpus\Topic::query()
            ->where('id', 'like', 'polity:%')
            ->where('name', 'ilike', '%'.$q.'%')
            ->when($byYear && $year !== null, function ($query) use ($year, $tol) {
                // keep only polities whose lifespan overlaps [year-tol, year+tol] (unknown era passes)
                $query->where(fn ($w) => $w->whereNull('era_start')->orWhere('era_start', '<=', $year + $tol))
                    ->where(fn ($w) => $w->whereNull('era_end')->orWhere('era_end', '>=', $year - $tol));
            })
            ->limit(40)
            ->get(['id', 'qid', 'name', 'region_label', 'era_start', 'era_end', 'region_lat', 'region_lng', 'sitelinks']);

        return \App\Models\Corpus\Topic::resilient(function () use ($fetch, $q, $year, $ref) {
            // In-era only — never surface out-of-era homonyms (a 20th-c. state for a 1614 scene).
            // If nothing existed then, the picker stays empty and the view nudges to the ruling empire.
            $rows = $fetch(true);
            $ql = mb_strtolower($q);

            return $rows->sortBy(function ($t) use ($ql, $year, $ref) {
                $name = mb_strtolower($t->name);
                $score = str_starts_with($name, $ql) ? 0.0 : 2.0;                 // name-prefix matches first
                if ($ref && $t->region_lat !== null && $t->region_lng !== null) { // surrounding first
                    $dLat = (float) $t->region_lat - $ref[0];
                    $dLng = ((float) $t->region_lng - $ref[1]) * cos(deg2rad($ref[0]));
                    $score += sqrt($dLat * $dLat + $dLng * $dLng) * 0.03;
                }
                if ($year !== null && $t->era_start !== null && $t->era_end !== null) { // closest era next
                    $score += abs((($t->era_start + $t->era_end) / 2) - $year) * 0.0015;
                }
                $score += mb_strlen((string) $t->name) * 0.01;
                $score -= min((int) ($t->sitelinks ?? 0), 200) * 0.0008;          // nudge well-known polities up

                return $score;
            })->take(8)->values();
        }) ?? collect();
    }

    /**
     * Map preview click → link the clicked Cliopatria polity as this block's territory.
     * The QID comes straight from the tile feature, so it may not exist in the topic
     * catalog — linkTerritory()'s $fallbackName covers that case.
     */
    #[On('mapTerritoryClicked')]
    public function linkTerritoryFromMap(?int $sceneId = null, ?string $qid = null, ?string $name = null): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        if ($sceneId !== null && $sceneId !== $this->selectedSceneId) {
            return; // stale click from a previously selected scene's map
        }
        if (! is_string($qid) || ! preg_match('/^Q\d+$/', $qid)) {
            return;
        }

        $fallback = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null;
        $this->linkTerritory($qid, $fallback);

        // A qid change alone isn't "stage dirty" (see unlinkTerritory) — re-fire scene:load so
        // the preview re-mounts with the new QID even when the location string didn't change.
        $this->selectSceneInternal($this->selectedSceneId);
    }

    public function linkTerritory(string $qid, ?string $fallbackName = null): void
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
            if ($fallbackName === null) {
                return;
            }
            // Clicked polity isn't in the curated catalog — link the raw QID with the
            // tile feature's name. No era seeding (the tiles don't define one canonical span).
            $this->selectedScene['config']['qid'] = $qid;
            $this->selectedScene['location'] = $fallbackName;
            $this->applyCapitalFocus($qid);
            $this->territoryQuery = '';
            $this->saveSelected();
            $this->dispatch('focusAnnotationsRefresh', annotations: $this->selectedScene['config']['annotations'] ?? []);

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

        // Auto-add the polity's capital as a ★ focus marker (if it's in our cities corpus).
        $this->applyCapitalFocus($topic->qid);

        $this->territoryQuery = '';
        unset($this->territoryResults);
        $this->saveSelected();   // location change → stageDirty → scene:load → map re-renders with the new QID
        // Push the fresh annotations to the live preview so the capital marker appears without a re-mount.
        $this->dispatch('focusAnnotationsRefresh', annotations: $this->selectedScene['config']['annotations'] ?? []);
    }

    public function unlinkTerritory(): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $this->selectedScene['config']['qid'] = null;
        // Remove the auto-added capital marker now that no territory is linked.
        $this->applyCapitalFocus(null);
        $this->saveSelected();
        // Re-fire scene:load so the map re-mounts with qid=null — otherwise the old red boundary
        // lingers (a bare qid change doesn't mark the scene "stage dirty").
        $this->selectSceneInternal($this->selectedSceneId);
        $this->dispatch('focusAnnotationsRefresh', annotations: $this->selectedScene['config']['annotations'] ?? []);
    }

    // ── Map block: focus-city annotations ────────────────────────────────
    // The map preview (editable) drops/drags red "focus" dots and dispatches the whole array
    // back here. Annotations live in scene.config.annotations as:
    //   ['type' => 'focus', 'lng' => <float>, 'lat' => <float>, 'label' => <string ≤80>]
    // Designed for extension: unknown future types (arrows, markers) pass through untouched so
    // older data is never destroyed; phase 1 only coerces 'focus' items.
    private const FOCUS_LABEL_MAX = 80;

    /** Hard cap on persisted annotations — the array arrives from the browser unauthenticated
     *  by anything but the session, so it must not be a vector for unbounded config growth. */
    private const ANNOTATIONS_MAX = 50;

    /**
     * Typeahead over the cities corpus: matches modern OR historical name, well-known cities
     * first (scalerank). Returns nothing under 2 chars so we don't run a wildcard on a single
     * letter. Dual-name display ("Constantinople (Istanbul)") is built from name + historical_name.
     */
    #[Computed]
    public function cityResults()
    {
        if (mb_strlen(trim($this->cityQuery)) < 2) {
            return collect();
        }

        $q = trim($this->cityQuery);

        return City::query()
            ->where(fn ($w) => $w->where('name', 'ilike', '%'.$q.'%')
                ->orWhere('historical_name', 'ilike', '%'.$q.'%'))
            // Never offer coordinate-less stub rows (seeded 0/0 when the NE import is missing) —
            // their focus pin would land at (0,0) in the Gulf of Guinea and drag the camera there.
            // Run cities:backfill-coords to fix stubs.
            ->whereNot(fn ($w) => $w->where('lat', 0)->where('lng', 0))
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('scalerank')
            ->limit(8)
            ->get(['id', 'name', 'lat', 'lng', 'historical_name']);
    }

    /**
     * Add a city from the typeahead as a focus annotation. Stores the historical period name so
     * the marker can render a dual label; `capital` is false (only auto-added capitals set it).
     */
    public function addFocusCity(int $cityId): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $c = City::find($cityId);
        if (! $c) {
            return;
        }
        // Belt + braces with the cityResults filter: never store a pin without real coordinates.
        if ($c->lat === null || $c->lng === null || ((float) $c->lat === 0.0 && (float) $c->lng === 0.0)) {
            return;
        }

        $annotations = $this->selectedScene['config']['annotations'] ?? [];
        $annotations[] = [
            'type' => 'focus',
            'lng' => (float) $c->lng,
            'lat' => (float) $c->lat,
            'label' => $c->name,
            'historical' => $c->historical_name,
        ];
        $this->selectedScene['config']['annotations'] = array_values($annotations);

        $this->cityQuery = '';
        unset($this->cityResults);
        $this->saveSelected();
        $this->dispatch('focusAnnotationsRefresh', annotations: $this->selectedScene['config']['annotations'] ?? []);
    }

    /**
     * Sync the auto-added capital marker to the linked territory. Always strips the previous
     * capital first (so re-linking never leaves a stale ★), then appends the new one when the
     * polity QID resolves to a curated capital that exists in our cities corpus. A null QID just
     * removes the capital (used on unlink). The capital marker carries `capital => true` so it
     * survives sanitize and renders with a gold ring + ★.
     */
    private function applyCapitalFocus(?string $qid): void
    {
        if (! $this->selectedScene) {
            return;
        }

        $annotations = $this->selectedScene['config']['annotations'] ?? [];
        // Drop any existing auto-added capital — there is at most one at a time.
        $annotations = array_values(array_filter(
            $annotations,
            fn ($a) => ! (is_array($a) && ($a['capital'] ?? false) === true)
        ));

        if ($qid !== null) {
            $row = PolityCapitals::for($qid);
            if ($row) {
                $city = City::query()
                    ->where('wikidata_qid', $row['qid'])
                    ->orWhere('name', 'ilike', $row['city'])
                    ->first(['id', 'name', 'lat', 'lng', 'historical_name', 'wikidata_qid']);
                if ($city) {
                    $annotations[] = [
                        'type' => 'focus',
                        'lng' => (float) $city->lng,
                        'lat' => (float) $city->lat,
                        'label' => $city->name,
                        'historical' => $city->historical_name,
                        'capital' => true,
                    ];
                }
            }
        }

        $this->selectedScene['config']['annotations'] = array_values($annotations);
    }

    /** Teacher text annotations ([T] tool) — persisted per scene in config['texts']. */
    #[On('scene:selection-changed')]
    public function selectInspectorTarget(?string $objectId = null): void
    {
        $this->activeLayerId = null;
        $this->activeTextId = null;
        $this->panelView = 'scene';

        if (! $objectId) {
            return;
        }

        if (preg_match('/^art_(\d+)$/', $objectId, $matches)) {
            $assetId = (int) $matches[1];
            if (collect($this->sceneArtworkLayers())->contains('asset_id', $assetId)) {
                $this->activeLayerId = $assetId;
            }

            return;
        }

        if (collect($this->selectedScene['config']['texts'] ?? [])
            ->contains(fn ($text) => is_array($text) && ($text['id'] ?? null) === $objectId)) {
            $this->activeTextId = $objectId;
        }
    }

    public function clearActiveText(): void
    {
        $this->activeTextId = null;
    }

    /** The selected text box or backing panel, read from the current scene snapshot. */
    #[Computed]
    public function activeText(): ?array
    {
        if (! $this->activeTextId) {
            return null;
        }

        return collect($this->selectedScene['config']['texts'] ?? [])
            ->first(fn ($text) => is_array($text) && ($text['id'] ?? null) === $this->activeTextId);
    }

    /** Update one selected text or backing-panel formatting property from the Format panel. */
    public function updateSceneText(string $textId, string $field, mixed $value): void
    {
        if (! $this->selectedSceneId || $textId === '') {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $config = $scene->config ?? [];
        $texts = array_values((array) ($config['texts'] ?? []));
        $index = collect($texts)->search(fn ($text) => is_array($text) && ($text['id'] ?? null) === $textId);

        if ($index === false || ! is_array($texts[$index])) {
            return;
        }

        $text = $texts[$index];
        $isPanel = ($text['kind'] ?? null) === 'rect';
        $valid = $isPanel
            ? ['side', 'color', 'opacity']
            : ['font', 'size', 'align', 'list', 'bg', 'bgColor', 'bgOpacity', 'x', 'y', 'w'];

        if (! in_array($field, $valid, true)) {
            return;
        }

        $next = match ($field) {
            'font' => in_array($value, ['sans', 'history', 'cinzel'], true) ? $value : $text['font'] ?? 'sans',
            'size' => in_array($value, ['sm', 'md', 'lg', 'xl'], true) ? $value : $text['size'] ?? 'md',
            'align' => in_array($value, ['left', 'center', 'right'], true) ? $value : $text['align'] ?? 'left',
            'list' => in_array($value, ['none', 'bullet', 'number'], true) ? $value : $text['list'] ?? 'none',
            'bg' => $value === true || $value === '1' || $value === 1 ? 'glass' : 'none',
            'bgColor', 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value)
                ? (string) $value : ($text[$field] ?? '#0f172a'),
            'bgOpacity', 'opacity' => max(0.05, min(0.95, (float) $value)),
            'side' => $value === 'right' ? 'right' : 'left',
            'x', 'y' => max(0.0, min(100.0, (float) $value)),
            'w' => max(5.0, min(95.0, (float) $value)),
        };

        $text[$field] = $next;
        $texts[$index] = $text;
        $config['texts'] = $texts;
        $scene->update(['config' => $config]);

        if ($this->selectedScene !== null) {
            $this->selectedScene['config'] = $config;
        }
        unset($this->activeText);

        $this->dispatch('scene:text-updated', sceneId: $scene->id, textId: $textId, text: $text);
    }

    public function deleteSceneText(string $textId): void
    {
        if (! $this->selectedSceneId || $textId === '') {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $config = $scene->config ?? [];
        $texts = collect($config['texts'] ?? [])
            ->reject(fn ($text) => is_array($text) && ($text['id'] ?? null) === $textId)
            ->values()
            ->all();

        if (count($texts) === count($config['texts'] ?? [])) {
            return;
        }

        $config['texts'] = $texts;
        $scene->update(['config' => $config]);
        if ($this->selectedScene !== null) {
            $this->selectedScene['config'] = $config;
        }
        $this->activeTextId = null;
        unset($this->activeText);

        $this->dispatch('scene:text-removed', sceneId: $scene->id, textId: $textId);
    }

    /**
     * One delete for any object-list item, routed by its id: `art_<assetId>` → a clipart/image
     * layer, `txt_`/`rect_` → a text box / backing panel, `__bg__` → the background (not
     * deletable). Fired from the object-list row's × and the Backspace/Delete shortcut.
     */
    #[On('scene:delete-object')]
    public function deleteObject(string $objectId): void
    {
        if ($objectId === '' || $objectId === '__bg__') {
            return;
        }
        if (preg_match('/^art_(\d+)$/', $objectId, $m)) {
            $this->detachArtwork((int) $m[1]);

            return;
        }
        $this->deleteSceneText($objectId);
    }

    /** Teacher text annotations ([T] tool) — persisted per scene in config['texts']. */
    #[On('sceneTextsChanged')]
    public function saveSceneTexts(?int $sceneId, array $texts, ?string $selectedTextId = null): void
    {
        // Fall back to the open scene — the JS layer can fire before its first scene:load
        // has stamped a scene id (e.g. [T] pressed right after page load).
        $sceneId ??= $this->selectedSceneId;

        $scene = $sceneId ? $this->lesson->scenes()->find($sceneId) : null;
        if (! $scene) {
            return;
        }

        $clean = collect($texts)
            // Keep text boxes with content and rectangle panels (which carry no text).
            ->filter(fn ($t) => is_array($t) && (
                ($t['kind'] ?? null) === 'rect' || trim((string) ($t['text'] ?? '')) !== ''
            ))
            ->map(function (array $t) {
                // The id is client-supplied — cap it so it can't be used to bloat scene config.
                $id = mb_substr((string) ($t['id'] ?? ''), 0, 64);

                // Rectangle backing panel: side + colour + opacity, no text/position.
                if (($t['kind'] ?? null) === 'rect') {
                    $color = (string) ($t['color'] ?? '#0f172a');

                    return [
                        'id' => $id !== '' ? $id : uniqid('rect_'),
                        'kind' => 'rect',
                        'side' => ($t['side'] ?? 'left') === 'right' ? 'right' : 'left',
                        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0f172a',
                        'opacity' => max(0.1, min(0.95, (float) ($t['opacity'] ?? 0.5))),
                        // Stacking order set by drag-reorder in the object list (higher = in front).
                        'z' => (isset($t['z']) && is_numeric($t['z'])) ? (int) $t['z'] : null,
                    ];
                }

                // Map-pinned labels carry a lng/lat; anything malformed falls back to screen.
                $anchor = ($t['anchor'] ?? 'screen') === 'map' ? 'map' : 'screen';
                $lng = isset($t['lng']) && is_numeric($t['lng']) ? max(-180.0, min(180.0, (float) $t['lng'])) : null;
                $lat = isset($t['lat']) && is_numeric($t['lat']) ? max(-85.0, min(85.0, (float) $t['lat'])) : null;
                if ($anchor === 'map' && ($lng === null || $lat === null)) {
                    $anchor = 'screen';
                    $lng = $lat = null;
                }
                $font = $t['font'] ?? 'sans';
                $size = $t['size'] ?? 'md';
                $list = $t['list'] ?? 'none';
                $bg = $t['bg'] ?? 'none';
                $bgColor = (string) ($t['bgColor'] ?? '#0f172a');
                $align = $t['align'] ?? 'left';

                return [
                    'id' => $id !== '' ? $id : uniqid('txt_'),
                    // A little more room for multi-line lists; still capped against abuse.
                    'text' => mb_substr(trim((string) $t['text']), 0, 600),
                    'x' => max(0, min(100, (float) ($t['x'] ?? 40))),
                    'y' => max(0, min(100, (float) ($t['y'] ?? 40))),
                    // Optional resized width (% of host); null = auto (default max-width).
                    'w' => (isset($t['w']) && is_numeric($t['w'])) ? max(5.0, min(95.0, (float) $t['w'])) : null,
                    'font' => in_array($font, ['sans', 'history', 'cinzel'], true) ? $font : 'sans',
                    'size' => in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md',
                    'list' => in_array($list, ['bullet', 'number'], true) ? $list : 'none',
                    'align' => in_array($align, ['center', 'right'], true) ? $align : 'left',
                    'bg' => $bg === 'glass' ? 'glass' : 'none',
                    'bgColor' => preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor) ? $bgColor : '#0f172a',
                    'bgOpacity' => (isset($t['bgOpacity']) && is_numeric($t['bgOpacity'])) ? max(0.05, min(0.95, (float) $t['bgOpacity'])) : 0.3,
                    'anchor' => $anchor,
                    'lng' => $anchor === 'map' ? $lng : null,
                    'lat' => $anchor === 'map' ? $lat : null,
                    // Stacking order set by drag-reorder in the object list (higher = in front).
                    'z' => (isset($t['z']) && is_numeric($t['z'])) ? (int) $t['z'] : null,
                ];
            })
            ->take(12)
            ->values()
            ->all();

        $scene->config = array_merge($scene->config ?? [], ['texts' => $clean]);
        $scene->save();

        if ($this->selectedSceneId === $sceneId && $this->selectedScene !== null) {
            $this->selectedScene['config'] = array_merge($this->selectedScene['config'] ?? [], ['texts' => $clean]);
        }

        if ($selectedTextId && collect($clean)->contains(fn ($text) => ($text['id'] ?? null) === $selectedTextId)) {
            $this->activeLayerId = null;
            $this->activeTextId = $selectedTextId;
            $this->panelView = 'scene';
            unset($this->activeText);
        }
    }

    /**
     * Edit / hide the scene identity overlay (flag + title + year + location) from the canvas.
     * year/location map to the scene columns; a title override and hide flag live in config.
     */
    #[On('sceneIdentityChanged')]
    public function updateSceneIdentity(array $patch): void
    {
        $sceneId = (int) ($patch['sceneId'] ?? $this->selectedSceneId);
        $scene = $sceneId ? $this->lesson->scenes()->find($sceneId) : null;
        if (! $scene) {
            return;
        }

        if (array_key_exists('year', $patch)) {
            $scene->year = mb_substr(trim((string) $patch['year']), 0, 40) ?: null;
        }
        if (array_key_exists('location', $patch)) {
            $scene->location = mb_substr(trim((string) $patch['location']), 0, 120) ?: null;
        }

        $config = $scene->config ?? [];
        if (array_key_exists('title', $patch)) {
            $title = mb_substr(trim((string) $patch['title']), 0, 80);
            if ($title !== '') {
                $config['identity_title'] = $title;
            } else {
                unset($config['identity_title']);
            }
        }
        if (array_key_exists('hidden', $patch)) {
            $config['hide_identity'] = (bool) $patch['hidden'];
        }
        $scene->config = $config;
        $scene->save();

        // Keep the inspector's year/location fields in step with a canvas edit.
        if ($this->selectedSceneId === $scene->id && $this->selectedScene !== null) {
            $this->selectedScene['year'] = $scene->year;
            $this->selectedScene['location'] = $scene->location;
            $this->selectedScene['config'] = $scene->config;
        }
    }

    /** Inspector toggle: show/hide the on-canvas caption (flag · title · year · location). */
    public function toggleCaption(): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $config = $scene->config ?? [];
        $config['hide_identity'] = ! (bool) ($config['hide_identity'] ?? false);
        $scene->config = $config;
        $scene->save();

        // Re-dispatch scene:load so the canvas overlay reflects the new hide flag.
        $this->selectSceneInternal($scene->id);
    }

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
        // Push the fresh annotations back to the live preview so the marker label updates without a re-mount.
        $this->dispatch('focusAnnotationsRefresh', annotations: $this->selectedScene['config']['annotations'] ?? []);
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
        // Push the fresh annotations back to the live preview so the removed marker disappears immediately.
        $this->dispatch('focusAnnotationsRefresh', annotations: $this->selectedScene['config']['annotations'] ?? []);
    }

    /** Map block: flip the projection between flat 2D (mercator) and 3D globe; persists in scene.config. */
    public function setProjection(string $type): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $this->selectedScene['config']['projection'] = in_array($type, ['mercator', 'globe'], true) ? $type : 'mercator';
        $this->saveSelected();
    }

    /**
     * Lesson-wide map palette (soft-atlas / antique / pen-ink / night / satellite) — one setting for
     * every map block in the lesson, mirroring the front-end Time-Map's five styles. Clears any
     * per-block override on the selected scene so the lesson choice is what shows.
     */
    public function setLessonMapStyle(string $name): void
    {
        $allowed = ['soft-atlas', 'antique', 'pen-ink', 'night', 'satellite'];
        $this->lesson->update(['map_style' => in_array($name, $allowed, true) ? $name : 'soft-atlas']);

        if ($this->selectedScene && ($this->selectedScene['config']['map_style'] ?? null) !== null) {
            unset($this->selectedScene['config']['map_style']);
            $this->saveSelected();   // config changed → re-fires scene:load → preview repaints
        } elseif ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId); // repaint the preview to the new default
        }
    }

    /**
     * Lesson-wide 3D terrain: how far the height map is exaggerated under every map in the lesson.
     * 0 = flat. Above ~6 the mountains turn to spikes, so that is the ceiling.
     *
     * The preview has already raised its ground live (lessonmap:relief) by the time this lands —
     * this call is only about persistence, so it deliberately does NOT re-select the scene: that
     * would re-fire scene:load and yank the camera the teacher just set.
     */
    public function setLessonMapRelief(float $relief): void
    {
        $this->lesson->update(['map_relief' => max(0.0, min(6.0, $relief))]);
    }

    // ── Voyage lesson: per-lesson editable route/fleet copy + scene editors ──────────────
    // Route geometry, legs (from/to) and fleet live in the shared, git-committed catalog
    // (resources/js/timemap/voyages.json) that the student player AND the standalone history
    // Time-Map both read. So the CMS never edits that file: on the first edit we clone the chosen
    // voyage into the lesson's own game_config['voyage_def'], and every reader (player + wizard
    // preview) prefers the lesson-local copy, falling back to the catalog by id.

    /** Per-scene stop-image pins and gallery images are capped so config can't grow unbounded. */
    private const STOP_IMAGES_MAX = 6;

    private const GALLERY_IMAGES_MAX = 12;

    /**
     * The voyage definition to render in the inspector + preview: the lesson's editable copy if
     * it exists, else the shared catalog entry (read-only until the first edit clones it).
     *
     * @return array<string,mixed>|null
     */
    #[Computed]
    public function voyageDef(): ?array
    {
        $gc = $this->lesson->game_config ?? [];
        if (isset($gc['voyage_def']['legs'])) {
            return $gc['voyage_def'];
        }

        return isset($gc['voyage']) ? $this->catalogVoyage((string) $gc['voyage']) : null;
    }

    /** Look up one voyage entry in the shared catalog by id. */
    private function catalogVoyage(string $id): ?array
    {
        $catalog = json_decode(File::get(resource_path('js/timemap/voyages.json')), true);

        return collect($catalog['voyages'] ?? [])->firstWhere('id', $id);
    }

    /**
     * Ensure the lesson has its own editable copy of the voyage definition, cloned verbatim from
     * the catalog on first edit (a FULL copy — waypoints + legs + fleet + unknown — so wp-index
     * lookups against catalog ship geometry stay consistent while only dates are editable in
     * phase 1). Returns the full game_config array with voyage_def guaranteed present when possible.
     *
     * @return array<string,mixed>
     */
    private function ensureVoyageDef(): array
    {
        $gc = $this->lesson->game_config ?? [];
        if (isset($gc['voyage_def']['legs'])) {
            return $gc;
        }
        $entry = isset($gc['voyage']) ? $this->catalogVoyage((string) $gc['voyage']) : null;
        if (! $entry) {
            return $gc;
        }
        $gc['voyage_def'] = $entry;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);   // bust the computed cache

        return $gc;
    }

    /**
     * Give every voyage scene its OWN leg. Legacy lessons could have two scenes pointing at the same
     * leg (the old "add leg" reused the last catalog leg), which made their destinations move together.
     * For each duplicate, append a fresh leg continuing from the shared leg's landfall and repoint it.
     * Idempotent: once every scene owns a distinct leg it does nothing (and never clones the catalog).
     */
    private function ensureUniqueVoyageLegs(): void
    {
        if (($this->lesson->game_type ?? null) !== 'voyage') {
            return;
        }
        // The itinerary sails nothing — its `leg` is only a seed for the map's era and style, so it
        // is NOT a claim on leg 0. Counting it as one made it collide with the first real leg, and
        // the repair below dutifully pushed that leg's scene to the end of the voyage.
        $scenes = $this->lesson->scenes()->where('kind', 'voyage')->orderBy('order')->get()
            ->reject(fn ($s) => ! empty($s->config['overview']))
            ->values();

        // Cheap scan first — bail (no catalog clone) unless a leg index is genuinely shared.
        $seen = [];
        $hasDupe = false;
        foreach ($scenes as $s) {
            $leg = (int) ($s->config['leg'] ?? 0);
            if (isset($seen[$leg])) {
                $hasDupe = true;
                break;
            }
            $seen[$leg] = true;
        }
        if (! $hasDupe) {
            return;
        }

        $gc = $this->ensureVoyageDef();
        if (empty($gc['voyage_def']['waypoints']) || empty($gc['voyage_def']['legs'])) {
            return;
        }
        $wps = array_values($gc['voyage_def']['waypoints']);
        $legs = array_values($gc['voyage_def']['legs']);

        $used = [];
        foreach ($scenes as $scene) {
            $leg = (int) ($scene->config['leg'] ?? 0);
            if (! isset($used[$leg]) && isset($legs[$leg])) {
                $used[$leg] = true;

                continue;
            }
            // Duplicate (or dangling) leg → append a new one continuing from this leg's landfall.
            $src = $legs[$leg] ?? end($legs);
            $startIdx = (int) ($src['wp'][1] ?? 0);
            $startWp = $wps[$startIdx] ?? [0.0, 20.0];
            $wps[] = [max(-179.0, min(179.0, (float) $startWp[0] + 12.0)), (float) $startWp[1]];
            $endIdx = count($wps) - 1;
            $depart = $src['arrive'] ?? '1500-01-01';
            $arrive = date('Y-m-d', strtotime($depart.' +14 days'));
            $legs[] = ['depart' => $depart, 'arrive' => $arrive, 'wp' => [$startIdx, $endIdx]];
            $newLeg = count($legs) - 1;
            $used[$newLeg] = true;

            $cfg = $scene->config ?? [];
            $cfg['leg'] = $newLeg;
            $scene->update(['config' => $cfg]);
        }

        $gc['voyage_def']['waypoints'] = $wps;
        $gc['voyage_def']['legs'] = $legs;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
    }

    /** Default look of the sailed route line, merged over the lesson's saved overrides. */
    private const ROUTE_LINE_DEFAULTS = [
        'enabled' => true, 'color' => '#7c2d12', 'opacity' => 0.9, 'thickness' => 3.0, 'wobble' => 0.3, 'curve' => 'bezier',
    ];

    /**
     * The route-line style for this voyage lesson (one setting for every leg), defaults filled in.
     *
     * @return array<string,mixed>
     */
    #[Computed]
    public function routeLine(): array
    {
        return array_merge(self::ROUTE_LINE_DEFAULTS, $this->lesson->game_config['route_line'] ?? []);
    }

    /** Edit one route-line setting (lesson-wide). Coerces each field, then repaints the preview. */
    public function setRouteLine(string $key, $value): void
    {
        if (! array_key_exists($key, self::ROUTE_LINE_DEFAULTS)) {
            return;
        }
        $current = $this->routeLine();
        $coerced = match ($key) {
            'enabled' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) ? (string) $value : $current['color'],
            'curve' => in_array($value, ['bezier', 'straight'], true) ? $value : 'bezier',
            'opacity' => max(0.0, min(1.0, (float) $value)),
            'thickness' => max(0.5, min(12.0, (float) $value)),
            'wobble' => max(0.0, min(1.0, (float) $value)),
            default => $value,
        };

        $gc = $this->lesson->game_config ?? [];
        $gc['route_line'] = array_merge($this->routeLine(), [$key => $coerced]);
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->routeLine);

        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);   // re-fire scene:load → preview repaints
        }
    }

    // Lesson-wide voyage-map detail: hide anachronistic modern cities/borders and show period place
    // labels (drawn from the landfalls). Defaults keep the normal atlas so existing lessons don't change.
    private const VOYAGE_MAP_DEFAULTS = [
        'cities' => true, 'borders' => true, 'labels' => true,
        // Per-element style (applied live over the base atlas palette). Defaults match lesson-map.js.
        'label_color' => '#3a2c1a', 'label_size' => 1.0,
        'city_color' => '#3a2c1a', 'city_size' => 1.0,
        'border_color' => '#5b4a36', 'border_width' => 0.6, 'border_opacity' => 0.3,
        // Ship + camera (whole-voyage). ship_scale = on-screen size multiplier; ship_anchored = the
        // ship is pinned to the map (grows/shrinks with zoom) instead of a constant screen size.
        // ocean_zoom (0–100) = how far the sailing camera pulls back: 0 = tight on the ship + a small
        // island, 100 = a continental view (never the whole world). cam_dolly_arrival = extra zoom in
        // as the ship makes landfall. The camera moves play during the sail (Preview / student player).
        'ship_scale' => 1.0, 'ship_anchored' => false,
        // How far the traveller's idle animation swings, 0–100. The rocking was tuned for an ocean
        // and can read as too much on a classroom projector, so the amount is the teacher's call.
        'motion' => 100,
        // Where the date and place are written: 'bottom' is the design's single quiet line in the
        // corner, 'top' the older centred date pill with the place beneath it. ONE of them, never
        // both — showing the pair said the same thing twice on one map.
        'info_position' => 'bottom',
        'ocean_zoom' => 30, 'cam_dolly_arrival' => false,
        // Fog-of-war: when true, ALL land along the route is "undiscovered" (water-coloured) except a
        // known-world set; the ship's range unmasks land as it nears + draws the coastline on landfall.
        // Replaces the manual fog paint. (Render engine: increments 2-4 in voyage-fog-of-war-plan.)
        'fog_auto' => false,
    ];

    /** @return array<string,bool> */
    #[Computed]
    public function voyageMap(): array
    {
        return array_merge(self::VOYAGE_MAP_DEFAULTS, $this->lesson->game_config['voyage_map'] ?? []);
    }

    /** Set one voyage-map option: a visibility toggle (cities/borders/labels) or a style value. */
    public function setVoyageMap(string $key, $value): void
    {
        if (! array_key_exists($key, self::VOYAGE_MAP_DEFAULTS)) {
            return;
        }
        $coerced = match (true) {
            in_array($key, ['cities', 'borders', 'labels', 'ship_anchored', 'cam_dolly_arrival', 'fog_auto'], true) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            in_array($key, ['ocean_zoom', 'motion'], true) => max(0, min(100, (int) $value)),
            $key === 'info_position' => in_array($value, ['bottom', 'top'], true) ? $value : 'bottom',
            str_ends_with($key, '_color') => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) ? (string) $value : self::VOYAGE_MAP_DEFAULTS[$key],
            $key === 'border_opacity' => max(0.0, min(1.0, (float) $value)),
            in_array($key, ['label_size', 'city_size'], true) => max(0.3, min(3.0, (float) $value)),
            $key === 'border_width' => max(0.0, min(8.0, (float) $value)),
            $key === 'ship_scale' => max(0.4, min(2.5, (float) $value)),
            default => $value,
        };

        $gc = $this->lesson->game_config ?? [];
        $gc['voyage_map'] = array_merge($this->voyageMap(), [$key => $coerced]);
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageMap);

        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);   // re-fire scene:load → preview repaints
        }
    }

    // Teacher-painted "undiscovered" fog regions (lesson-wide) — polygon rings [[lng,lat],…] the
    // teacher brushes onto the voyage map. Merged with the catalog fog at render.
    private const VOYAGE_FOG_MAX = 60;

    /** @return array<int,array<int,array{0:float,1:float}>> */
    #[Computed]
    public function voyageFog(): array
    {
        return array_values((array) ($this->lesson->game_config['voyage_fog'] ?? []));
    }

    /**
     * Append one painted region (a ring of [lng,lat] pairs). Sanitised: numeric coords only, in
     * range, capped in length; ignored if degenerate.
     *
     * @param  array<int,mixed>  $ring
     */
    public function addFogRegion(array $ring): void
    {
        $clean = [];
        foreach ($ring as $pt) {
            if (! is_array($pt) || count($pt) < 2) {
                continue;
            }
            $lng = (float) $pt[0];
            $lat = (float) $pt[1];
            if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
                continue;
            }
            $clean[] = [round($lng, 3), round($lat, 3)];
            if (count($clean) >= 400) {
                break;
            }
        }
        if (count($clean) < 4) {
            return;   // not a usable polygon
        }

        $fog = $this->voyageFog();
        if (count($fog) >= self::VOYAGE_FOG_MAX) {
            return;
        }
        $fog[] = $clean;

        $gc = $this->lesson->game_config ?? [];
        $gc['voyage_fog'] = array_values($fog);
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageFog);
    }

    /**
     * Replace the whole painted-fog set. The erase BRUSH recomputes the surviving rings client-side
     * (turf.difference of the brush footprint) and sends them here. Sanitised like addFogRegion.
     *
     * @param  array<int,mixed>  $rings
     */
    public function setVoyageFog(array $rings): void
    {
        $clean = [];
        foreach ($rings as $ring) {
            if (! is_array($ring)) {
                continue;
            }
            $pts = [];
            foreach ($ring as $pt) {
                if (! is_array($pt) || count($pt) < 2) {
                    continue;
                }
                $lng = (float) $pt[0];
                $lat = (float) $pt[1];
                if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
                    continue;
                }
                $pts[] = [round($lng, 3), round($lat, 3)];
                if (count($pts) >= 400) {
                    break;
                }
            }
            if (count($pts) >= 4) {
                $clean[] = $pts;
            }
            if (count($clean) >= self::VOYAGE_FOG_MAX) {
                break;
            }
        }

        $gc = $this->lesson->game_config ?? [];
        if (empty($clean)) {
            unset($gc['voyage_fog']);
        } else {
            $gc['voyage_fog'] = array_values($clean);
        }
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageFog);
    }

    /** Remove one painted region by index (legacy click-eraser path). Live, no re-mount. */
    public function removeFogRegion(int $index): void
    {
        $fog = $this->voyageFog();
        if ($index < 0 || $index >= count($fog)) {
            return;
        }
        array_splice($fog, $index, 1);

        $gc = $this->lesson->game_config ?? [];
        if (empty($fog)) {
            unset($gc['voyage_fog']);
        } else {
            $gc['voyage_fog'] = array_values($fog);
        }
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageFog);
    }

    /** Undo the last painted region (CMD-Z / Undo button). Re-fires scene:load so the preview follows. */
    /**
     * How many route edits Cmd-Z can walk back. Deep enough to rescue a run of accidental drags,
     * shallow enough that the history never bloats game_config.
     */
    private const VOYAGE_UNDO_DEPTH = 25;

    /**
     * Snapshot the route before changing it, so an accidental drag can be taken back.
     *
     * Fog painting had undo; dragging a waypoint or bending the sailing line did not, so a slip of
     * the mouse silently rewrote the route with no way back. Every mutator calls this FIRST.
     * History lives in game_config so it survives the Livewire round trip a drag ends with.
     */
    private function pushVoyageUndo(): void
    {
        $gc = $this->lesson->game_config ?? [];
        $def = $gc['voyage_def'] ?? null;

        // A lesson starts with NO edited voyage_def — it rides on the catalogue route until the
        // first drag materialises one. Record that absence as null rather than skipping the push,
        // or the very first edit (exactly the one a teacher fat-fingers) would be the one thing
        // undo could not reach.
        $stack = array_values($gc['voyage_undo'] ?? []);
        $stack[] = is_array($def) ? $def : null;
        if (count($stack) > self::VOYAGE_UNDO_DEPTH) {
            $stack = array_slice($stack, -self::VOYAGE_UNDO_DEPTH);
        }

        $gc['voyage_undo'] = $stack;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
    }

    /** True when there is a route edit to take back — drives the Undo button's disabled state. */
    #[Computed]
    public function canUndoVoyage(): bool
    {
        return ! empty(($this->lesson->game_config ?? [])['voyage_undo'] ?? []);
    }

    /** Cmd-Z for the route: restore the geometry as it was before the last edit. */
    public function undoVoyage(): void
    {
        $gc = $this->lesson->game_config ?? [];
        $stack = array_values($gc['voyage_undo'] ?? []);
        if (empty($stack)) {
            return;
        }

        $previous = array_pop($stack);
        if (is_array($previous)) {
            $gc['voyage_def'] = $previous;
        } else {
            unset($gc['voyage_def']);   // back to before any edit: ride the catalogue route again
        }
        if (empty($stack)) {
            unset($gc['voyage_undo']);
        } else {
            $gc['voyage_undo'] = $stack;
        }

        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->canUndoVoyage);

        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);   // re-fire scene:load → map follows
        }
        $this->dispatch('toast', type: 'info', message: __('Route change undone.'));
    }

    public function undoFog(): void
    {
        $fog = $this->voyageFog();
        if (empty($fog)) {
            return;
        }
        array_pop($fog);

        $gc = $this->lesson->game_config ?? [];
        if (empty($fog)) {
            unset($gc['voyage_fog']);
        } else {
            $gc['voyage_fog'] = array_values($fog);
        }
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageFog);

        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);   // re-fire scene:load → preview drops that region
        }
    }

    /** Clear all teacher-painted fog regions (back to just the catalog fog). */
    public function clearFog(): void
    {
        $gc = $this->lesson->game_config ?? [];
        unset($gc['voyage_fog']);
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageFog);

        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);   // re-fire scene:load → preview clears painted fog
        }
    }

    /**
     * Landfall labels for the voyage map: every voyage scene's location, tagged with its leg index
     * (the JS resolves the leg to its arrival waypoint coordinate).
     *
     * @return array<int,array{text:string,leg:int}>
     */
    public function voyageLegLabels(): array
    {
        return $this->lesson->scenes()->where('kind', 'voyage')->whereNotNull('location')->ordered()->get()
            ->map(fn ($s) => [
                'text' => (string) $s->location,
                'leg' => (int) ($s->config['leg'] ?? 0),
                // The overview scene names the port the voyage LEAVES from; every other scene names
                // the landfall its leg arrives at. Both sit on leg 0 to begin with, so without this
                // the start city's name was drawn on the first landfall instead of on itself.
                'at' => ($s->config['overview'] ?? false) ? 'depart' : 'arrive',
            ])
            ->all();
    }

    /**
     * The itinerary: every place the voyage calls at, in sailing order.
     *
     * Stop 1 is where it leaves from (the overview scene names it — that scene stops nowhere, so its
     * own location is the departure port); stop 2 onwards is each leg's landfall. Names come from the
     * leg's own title where the teacher set one, otherwise from the scene that plays that leg, which
     * is what the map labels already use — one name per place, not two that can disagree.
     *
     * @return array<int,array{n:int,leg:int|null,title:string,scene_id:int|null}>
     */
    public function voyageStops(): array
    {
        $def = $this->voyageDef();
        $legs = $def['legs'] ?? [];
        if (! $legs) {
            return [];
        }

        $scenes = $this->lesson->scenes()->where('kind', 'voyage')->ordered()->get();
        $overview = $scenes->first(fn ($s) => (bool) ($s->config['overview'] ?? false));
        $sceneForLeg = $scenes->reject(fn ($s) => (bool) ($s->config['overview'] ?? false))
            ->keyBy(fn ($s) => (int) ($s->config['leg'] ?? 0));

        $nameOfLeg = function (int $i) use ($legs, $sceneForLeg): string {
            $scene = $sceneForLeg[$i] ?? null;

            return trim((string) ($legs[$i]['title'] ?? '')) ?: (string) ($scene->location ?? '');
        };

        // Only the scenes ON the route are listed, numbered from the first landfall: stop 1 is the
        // first place the voyage arrives at, not the port it left from. The overview is not a place
        // the voyage calls at — it is the screen that shows them all — so it has no row of its own,
        // and the departure port carries no number of its own either (it is where stop 1 sails from).
        $stops = [];
        foreach ($legs as $i => $leg) {
            $scene = $sceneForLeg[$i] ?? null;
            $stops[] = [
                'n' => $i + 1,
                'leg' => $i,
                'title' => $nameOfLeg($i),
                'scene_id' => $scene?->id,
            ];
        }

        return $stops;
    }

    /** Does the last leg end where the first one began (within ~100 km)? */
    private function voyageReturnsHome(array $def): bool
    {
        $legs = array_values($def['legs'] ?? []);
        $points = $def['waypoints'] ?? [];
        if (count($legs) < 2) {
            return false;
        }
        $start = $points[$legs[0]['wp'][0]] ?? null;
        $end = $points[$legs[count($legs) - 1]['wp'][1]] ?? null;
        if (! is_array($start) || ! is_array($end)) {
            return false;
        }

        return abs((float) $start[0] - (float) $end[0]) < 1.0 && abs((float) $start[1] - (float) $end[1]) < 1.0;
    }

    /**
     * The name of the port a voyage leaves from, when nothing has named it yet.
     *
     * Most historical voyages are round trips, so the place the route ends at IS the place it began
     * — Tasman left Batavia and came home to Batavia. Reading the name back off the returning leg
     * beats making the teacher type a name the lesson already knows. A one-way voyage returns ''
     * and the row shows its placeholder instead of a wrong guess.
     *
     * @param  callable(int):string  $nameOfLeg
     */
    private function homePortName(array $def, callable $nameOfLeg): string
    {
        $legs = array_values($def['legs'] ?? []);

        return $this->voyageReturnsHome($def) ? $nameOfLeg(count($legs) - 1) : '';
    }

    /**
     * Re-sail the voyage in a new order of calls: `$order` is the leg indices as they should now run.
     *
     * A leg owns the waypoints from just after its departure up to and including its landfall — its
     * bends belong to that crossing — so reordering moves those BLOCKS and re-chains them onto the
     * origin. Each leg keeps its own bends, dates and title; what changes is where it sails from,
     * which is the whole point of reordering an itinerary. The scenes that play the legs are
     * renumbered and re-ordered to match, so the rail and the itinerary can never disagree.
     */
    /**
     * Add the itinerary scene — the whole trip on one screen, before anyone sets off.
     *
     * It carries no geometry of its own (the renderer derives the route and every stop from
     * voyage_def), so all it needs is the flag and a leg to seed the map's era and style from.
     */
    private function addVoyageOverviewScene(): void
    {
        $this->addSceneOpen = false;
        $scene = Scene::create([
            'lesson_id' => $this->lesson->id,
            'kind' => 'voyage',
            'status' => 'ready',
            'order' => ((int) $this->lesson->scenes()->max('order')) + 1,
            'title' => __('The whole voyage'),
            'config' => [
                'voyage' => $this->lesson->game_config['voyage'] ?? ($this->voyageDef()['id'] ?? null),
                'overview' => true,
                'leg' => 0,
                'view' => 'flat',
                'intro' => false,
            ],
        ]);
        $this->placeSceneAfterSelected($scene, $this->selectedSceneId);
        $this->lesson->refresh();
        $this->dispatch('scenesUpdated');
        $this->selectSceneInternal($scene->id);
    }

    /**
     * The itinerary scene's own animation: the route drawing itself, and its stops numbering as it
     * goes. Lives on the scene (Animate tab) because it is that scene's motion, not a map setting.
     */
    public function setOverviewAnim(string $key, $value): void
    {
        if (! $this->selectedScene || ($this->selectedScene['kind'] ?? null) !== 'voyage') {
            return;
        }
        $anim = ($this->selectedScene['config']['overview_anim'] ?? []) + ['route' => 'draw', 'duration' => 3.0, 'stops' => true];
        $anim[$key] = match ($key) {
            'route' => in_array($value, ['draw', 'none'], true) ? $value : 'draw',
            'duration' => max(0.5, min(10.0, (float) $value)),
            'stops' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => null,
        };
        if ($anim[$key] === null) {
            return;
        }
        $this->selectedScene['config']['overview_anim'] = $anim;
        $this->saveSelected();
    }

    /**
     * Give a leg that has no scene one, so the class can actually see that stop.
     *
     * A route can end up with more legs than scenes — an added crossing, a deleted scene — and the
     * itinerary is where that finally shows: a stop nobody can watch. The new scene is built like
     * every other leg scene (see addScene's voyage branch) and lands in sailing order.
     */
    public function addVoyageSceneForLeg(int $leg): void
    {
        $def = $this->voyageDef();
        $legs = $def['legs'] ?? [];
        if (! isset($legs[$leg])) {
            return;
        }
        $voyageScenes = $this->lesson->scenes()->where('kind', 'voyage')->ordered()->get();
        // Never a second scene for the same leg: two scenes on one leg is the bug this repairs.
        if ($voyageScenes->contains(fn ($s) => ! ($s->config['overview'] ?? false) && (int) ($s->config['leg'] ?? 0) === $leg)) {
            return;
        }

        $scene = Scene::create([
            'lesson_id' => $this->lesson->id,
            'kind' => 'voyage',
            'status' => 'ready',
            // Lesson-wide max, not the voyage scenes' — scene order is unique across the lesson, and
            // a quiz sitting after the last leg already owns that number.
            'order' => ($this->lesson->scenes()->max('order') ?? 0) + 1,
            'location' => trim((string) ($legs[$leg]['title'] ?? '')) ?: null,
            'config' => [
                'voyage' => $this->lesson->game_config['voyage'] ?? ($def['id'] ?? null),
                'leg' => $leg,
                'view' => 'flat',
                'intro' => false,
                'stop_images' => [],
                'gallery' => ['title' => '', 'date_label' => '', 'story' => '', 'images' => []],
            ],
        ]);

        $this->lesson->refresh();
        $this->dispatch('scenesUpdated');
        $this->selectSceneInternal($scene->id);
    }

    #[On('reorderVoyageStops')]
    public function reorderVoyageStops(array $order): void
    {
        $gc = $this->ensureVoyageDef();
        $def = $gc['voyage_def'] ?? $this->voyageDef();
        $legs = array_values($def['legs'] ?? []);
        $points = array_values($def['waypoints'] ?? []);
        $order = array_values(array_unique(array_map('intval', $order)));
        // Ignore anything that isn't a clean permutation — a half-applied route is worse than none.
        if (count($order) !== count($legs) || array_diff($order, array_keys($legs))) {
            return;
        }

        $this->applyLegOrder($order);
    }

    /**
     * Delete a stop: the crossing that ends there stops being part of the voyage.
     *
     * A stop only exists because a leg arrives at it, so removing the stop means removing that leg
     * and sailing straight on to the next one — which is exactly a re-chain over the legs that are
     * left. Its scene goes with it: a scene that points at a leg which no longer exists would show
     * an empty map. The last leg cannot go, or there would be no voyage.
     */
    public function deleteVoyageStop(int $leg): void
    {
        $def = $this->voyageDef();
        $legs = array_values($def['legs'] ?? []);
        if (count($legs) < 2 || ! array_key_exists($leg, $legs)) {
            return;
        }

        $this->lesson->scenes()
            ->where('kind', 'voyage')
            ->get()
            ->filter(fn ($s) => ! ($s->config['overview'] ?? false) && (int) ($s->config['leg'] ?? 0) === $leg)
            ->each(fn ($s) => $s->delete());

        $this->applyLegOrder(array_values(array_diff(array_keys($legs), [$leg])));
    }

    /**
     * Re-chain the route over `$order` — the legs to keep, in the order they are sailed.
     *
     * Shared by reordering (a permutation) and deleting (a subset). Each leg keeps its own bends and
     * dates; only where it starts and ends is recomputed, so dragging a crossing around the itinerary
     * never straightens the course the teacher drew.
     *
     * @param  int[]  $order  leg indices into the CURRENT def
     */
    private function applyLegOrder(array $order): void
    {
        $gc = $this->ensureVoyageDef();
        $def = $gc['voyage_def'] ?? $this->voyageDef();
        $legs = array_values($def['legs'] ?? []);
        $points = array_values($def['waypoints'] ?? []);
        if (! $order || array_diff($order, array_keys($legs))) {
            return;
        }

        $origin = $points[$legs[0]['wp'][0]] ?? null;
        if ($origin === null) {
            return;
        }

        $rebuiltPoints = [$origin];
        $rebuiltLegs = [];
        foreach ($order as $legIndex) {
            $leg = $legs[$legIndex];
            [$from, $to] = [(int) $leg['wp'][0], (int) $leg['wp'][1]];
            $block = array_slice($points, $from + 1, max(0, $to - $from));   // this leg's bends + its landfall
            if (! $block) {
                return;                                                      // malformed leg — leave the route alone
            }
            $start = count($rebuiltPoints) - 1;
            $rebuiltPoints = array_merge($rebuiltPoints, $block);
            $rebuiltLegs[] = array_merge($leg, ['wp' => [$start, count($rebuiltPoints) - 1]]);
        }

        $this->pushVoyageUndo();          // a reorder is a route edit like any other — take it back with Undo
        $gc = $this->lesson->game_config ?? [];
        $def['waypoints'] = $rebuiltPoints;
        $def['legs'] = $rebuiltLegs;
        $gc['voyage_def'] = $def;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);

        // Renumber the scenes onto their leg's new index, then lay them out in sailing order behind
        // the overview scene. Done in one pass so no scene is ever left pointing at a stale leg.
        $newIndexOf = array_flip($order);
        $scenes = $this->lesson->scenes()->where('kind', 'voyage')->ordered()->get();
        $legScenes = [];
        foreach ($scenes as $scene) {
            if ((bool) ($scene->config['overview'] ?? false)) {
                continue;
            }
            $old = (int) ($scene->config['leg'] ?? 0);
            if (! array_key_exists($old, $newIndexOf)) {
                continue;
            }
            $config = $scene->config;
            $config['leg'] = $newIndexOf[$old];
            $scene->update(['config' => $config]);
            $legScenes[$newIndexOf[$old]] = $scene;
        }
        ksort($legScenes);
        $order0 = $scenes->min('order');
        $next = $order0 + ($scenes->contains(fn ($s) => (bool) ($s->config['overview'] ?? false)) ? 1 : 0);
        // Park them past every existing order first: scene order is unique per lesson, so assigning
        // the final numbers in place collides with whichever scene still holds the one we want.
        $parking = ($this->lesson->scenes()->max('order') ?? 0) + 1;
        foreach ($legScenes as $scene) {
            $scene->update(['order' => $parking++]);
        }
        foreach ($legScenes as $scene) {
            $scene->update(['order' => $next++]);
        }

        $this->dispatch('scenesUpdated');
        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);   // re-fire scene:load → the live map re-chains
        }
    }

    /**
     * The voyage's map projection (flat 2D vs 3D globe). GLOBAL to the whole voyage — every waypoint
     * shares it, like all the other voyage map settings — so it lives in game_config, not scene.config.
     */
    public function voyageView(): string
    {
        $v = $this->lesson->game_config['voyage_view'] ?? null;
        // Back-compat: seed from the first voyage scene's old per-scene view if the global isn't set.
        if ($v === null) {
            $seed = $this->lesson->scenes()->where('kind', 'voyage')->ordered()->get()
                ->map(fn ($s) => $s->config['view'] ?? null)->filter()->first();
            $v = $seed ?: 'flat';
        }

        return in_array($v, ['flat', 'globe'], true) ? $v : 'flat';
    }

    /** Flip the voyage's map projection (flat 2D mercator vs 3D globe) — GLOBAL to the whole voyage. */
    public function setVoyageView(string $view): void
    {
        $gc = $this->lesson->game_config ?? [];
        $gc['voyage_view'] = in_array($view, ['flat', 'globe'], true) ? $view : 'flat';
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        // Re-fire scene:load so the running tour applies the new projection in place (no re-mount).
        $this->selectSceneInternal($this->selectedSceneId);
    }

    /**
     * Voyage scene: is this scene the itinerary (the whole trip on one screen) or one waypoint?
     *
     * Saved on the scene, not held in the panel: a teacher who sets a scene to the overview, walks
     * off to another scene and comes back must find it still on the overview — and so must the
     * student, since this same flag is what makes the player open the itinerary instead of sailing
     * a leg. The leg index is kept either way, so flipping back lands on the waypoint it came from.
     */
    public function setVoyageOverview(bool $on): void
    {
        if (! $this->selectedScene || ($this->selectedScene['kind'] ?? null) !== 'voyage') {
            return;
        }
        $this->selectedScene['config']['overview'] = $on;
        $this->saveSelected();
    }

    /** Voyage scene: toggle the "from space" intro fly-in (only meaningful on the first leg). */
    public function setVoyageIntro(bool $on): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $this->selectedScene['config']['intro'] = $on;
        $this->saveSelected();
    }

    /** Voyage scene: edit this leg's departure date (writes voyage_def, not scene.config). */
    public function setLegDepart(string $date): void
    {
        $this->setLegDate('depart', $date);
    }

    /** Voyage scene: edit this leg's arrival date (writes voyage_def, not scene.config). */
    public function setLegArrive(string $date): void
    {
        $this->setLegDate('arrive', $date);
    }

    /**
     * Shared leg-date writer. The leg lives in the lesson's voyage_def, not in scene.config, so
     * saveSelected() (which only diffs scene.config) would not repaint — we re-fire scene:load
     * ourselves after persisting.
     */
    private function setLegDate(string $field, string $date): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        // Only accept the YYYY-MM-DD shape renderVoyageTour's Date.parse relies on.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        if (! isset($gc['voyage_def']['legs'][$leg])) {
            return;
        }
        $gc['voyage_def']['legs'][$leg][$field] = $date;

        // Soft validation: a crossed pair still animates (duration is clamped) but shows a
        // nonsensical date chip — warn, don't block.
        $depart = $gc['voyage_def']['legs'][$leg]['depart'] ?? null;
        $arrive = $gc['voyage_def']['legs'][$leg]['arrive'] ?? null;
        if ($depart && $arrive && strtotime($arrive) <= strtotime($depart)) {
            $this->dispatch('toast', message: __('Arrival is on or before departure. Check the dates.'), type: 'warning');
        }

        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);   // repaint the voyage preview
    }

    /**
     * The waypoint's STOP TITLE — the landfall's name (e.g. "Hispaniola"). Stored in the voyage format
     * (voyage_def.legs[i].title), NOT the gallery title. It is the single source for the landfall name
     * everywhere: the green map label, the date chip and the scene-rail label. We mirror it into
     * scene.location too, because the map's place labels (voyageLegLabels) and the rail read from there.
     */
    public function setLegTitle(string $title): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        $title = trim($title);
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        if (! isset($gc['voyage_def']['legs'][$leg])) {
            return;
        }
        $gc['voyage_def']['legs'][$leg]['title'] = $title;
        $this->lesson->update(['game_config' => $gc]);
        // Keep the landfall name in sync on the scene itself → map place labels + rail update.
        $this->lesson->scenes()->whereKey($this->selectedSceneId)->update(['location' => $title !== '' ? $title : null]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);
    }

    // ── How this leg was travelled ────────────────────────────────────────────────────────────
    // Transport is a property of the CROSSING, not of the stop at either end: Marco Polo rode out
    // of Venice's hinterland, crossed the desert by camel and came home by sea, all between fixed
    // places. So it is edited per leg, and the marker swaps as the traveller passes each landfall.

    /**
     * The transport models a teacher can choose from, straight out of the shared catalogue that the
     * map renderer reads (resources/js/timemap/transports.json) — one list, so a model added there
     * shows up here without a second copy to keep in step.
     *
     * @return array<int,array{id:string,label:string,terrain:string,thumb:string,hint:string}>
     */
    #[Computed]
    public function transports(): array
    {
        $json = json_decode(File::get(resource_path('js/timemap/transports.json')), true);

        return collect($json['transports'] ?? [])
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn ($t) => [
                'id' => (string) $t['id'],
                'label' => (string) ($t['label'] ?? $t['id']),
                'terrain' => ($t['terrain'] ?? 'water') === 'land' ? 'land' : 'water',
                'thumb' => (string) ($t['thumb'] ?? ''),
                'hint' => (string) ($t['hint'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Set how THIS leg was travelled — a transport id from the catalogue.
     *
     * Choosing a model also clears any picture marker on the leg: the two are alternatives, and a
     * teacher who just picked the camel expects to see the camel, not the photo that quietly wins.
     */
    public function setLegTransport(string $transport): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        if (! in_array($transport, array_column($this->transports(), 'id'), true)) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        if (! isset($gc['voyage_def']['legs'][$leg])) {
            return;
        }
        $gc['voyage_def']['legs'][$leg]['transport'] = $transport;
        unset($gc['voyage_def']['legs'][$leg]['marker_image']);

        $this->persistVoyageDef($gc);
    }

    /**
     * Travel this leg as a PICTURE instead of a model — a painting or photo, drawn on the map as a
     * round portrait with a ring. For the journeys no model covers (on foot, by sledge, by canoe).
     */
    public function setLegMarkerImage(string $url): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        $url = trim($url);
        // Same gate as every other teacher-supplied URL that ends up in a lesson: http(s) or a path
        // under this app. A marker is fetched by the student's browser, so it must not become a way
        // to point one at file:// or a javascript: payload.
        if ($url === '' || ! (str_starts_with($url, '/') || preg_match('#^https?://#i', $url))) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        if (! isset($gc['voyage_def']['legs'][$leg])) {
            return;
        }
        $gc['voyage_def']['legs'][$leg]['marker_image'] = $url;

        $this->persistVoyageDef($gc);
    }

    /** Drop this leg's picture marker and fall back to its transport model. */
    public function clearLegMarkerImage(): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        if (! isset($gc['voyage_def']['legs'][$leg])) {
            return;
        }
        unset($gc['voyage_def']['legs'][$leg]['marker_image']);

        $this->persistVoyageDef($gc);
    }

    /**
     * Save an edited voyage_def and repaint. The leg lives in the lesson's game_config, not in
     * scene.config, so saveSelected() (which only diffs scene.config) would never fire — the
     * scene:load has to be re-fired here for the map to pick the change up.
     */
    private function persistVoyageDef(array $gc): void
    {
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal((int) $this->selectedSceneId);
    }

    /**
     * Move this leg's FROM or TO endpoint to a [lng,lat] — the teacher drags the landfall on the map.
     * Writes the shared voyage_def waypoint (so the FROM end is the previous leg's landfall too).
     */
    #[On('voyageLegEndpointMoved')]
    public function setLegWaypoint(int $sceneId, string $which, $lng, $lat): void
    {
        if (! $this->selectedScene || (int) ($this->selectedScene['id'] ?? 0) !== $sceneId) {
            return;
        }
        if (! in_array($which, ['from', 'to'], true)) {
            return;
        }
        $lng = (float) $lng;
        $lat = (float) $lat;
        if (! is_finite($lng) || $lat < -90 || $lat > 90) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        $wp = $gc['voyage_def']['legs'][$leg]['wp'] ?? null;
        if (! is_array($wp)) {
            return;
        }
        $idx = (int) ($which === 'from' ? ($wp[0] ?? 0) : ($wp[1] ?? 0));
        if (! isset($gc['voyage_def']['waypoints'][$idx])) {
            return;
        }
        // Antimeridian-safe: unwrap to sit within ±180° of the neighbouring waypoint (see setVoyageWaypoint).
        $ref = $gc['voyage_def']['waypoints'][$idx - 1][0] ?? ($gc['voyage_def']['waypoints'][$idx + 1][0] ?? null);
        $lng = self::unwrapLngNear($lng, $ref === null ? null : (float) $ref);
        $gc['voyage_def']['waypoints'][$idx] = [round($lng, 3), round($lat, 3)];
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);   // re-render the route to the new landfall
    }

    /**
     * Unwrap a longitude to within ±180° of a reference so a route stays contiguous across the
     * antimeridian. Pacific waypoints legitimately exceed ±180° (Tonga ≈ 185°E); storing them wrapped
     * to [-180,180] put them ~360° from their neighbours and drew the route around the whole world.
     */
    private static function unwrapLngNear(float $lng, ?float $ref): float
    {
        if ($ref === null || ! is_finite($lng)) {
            return $lng;
        }
        while ($lng - $ref > 180) {
            $lng -= 360;
        }
        while ($lng - $ref < -180) {
            $lng += 360;
        }

        return $lng;
    }

    /**
     * Recover a route tangled by edits: drop the lesson's edited voyage_def so the pristine CATALOG
     * route is used again. Map settings + fog are lesson-wide and untouched. Also re-contiguates any
     * already-stored waypoints as a belt-and-braces (older data saved before the antimeridian fix).
     */
    public function resetVoyageRoute(): void
    {
        if (! isset(($this->lesson->game_config ?? [])['voyage_def'])) {
            return;
        }
        $this->pushVoyageUndo();   // a reset throws away every edit, so it must be reversible too

        $gc = $this->lesson->game_config ?? [];
        unset($gc['voyage_def']);
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        if ($this->selectedSceneId) {
            $this->selectSceneInternal($this->selectedSceneId);
        }
    }

    /**
     * Move ANY of the current leg's waypoints (destination or an intermediate "bend") by its global
     * voyage_def index — the teacher drags a route point around land (e.g. through Gibraltar).
     */
    #[On('voyageWaypointMoved')]
    public function setVoyageWaypoint(int $sceneId, int $wpIndex, $lng, $lat): void
    {
        if (! $this->selectedScene || (int) ($this->selectedScene['id'] ?? 0) !== $sceneId) {
            return;
        }
        $lng = (float) $lng;
        $lat = (float) $lat;
        if (! is_finite($lng) || $lat < -90 || $lat > 90) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $this->pushVoyageUndo();
        $gc = $this->ensureVoyageDef();
        $wp = $gc['voyage_def']['legs'][$leg]['wp'] ?? null;
        // Only points that belong to THIS leg's span may be moved from here.
        if (! is_array($wp) || $wpIndex < (int) $wp[0] || $wpIndex > (int) $wp[1] || ! isset($gc['voyage_def']['waypoints'][$wpIndex])) {
            return;
        }
        // Unwrap the dragged longitude to sit within ±180° of the neighbour so a Pacific crossing stays
        // contiguous (Tonga ≈ 185°E) instead of jumping ~360° and drawing the route around the world.
        $ref = $gc['voyage_def']['waypoints'][$wpIndex - 1][0] ?? ($gc['voyage_def']['waypoints'][$wpIndex + 1][0] ?? null);
        $lng = self::unwrapLngNear($lng, $ref === null ? null : (float) $ref);
        $gc['voyage_def']['waypoints'][$wpIndex] = [round($lng, 3), round($lat, 3)];
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);
    }

    /**
     * Insert a NEW bend waypoint into the current leg at an arbitrary position — fired when the teacher
     * drags the route line itself to bend it around land. The bend lands just after $afterWpIndex (which
     * must be inside the current leg), and every later leg index shifts by one to stay valid.
     */
    #[On('voyageWaypointInserted')]
    public function insertVoyageWaypoint(int $sceneId, int $afterWpIndex, $lng, $lat): void
    {
        if (! $this->selectedScene || (int) ($this->selectedScene['id'] ?? 0) !== $sceneId) {
            return;
        }
        $lng = (float) $lng;
        $lat = (float) $lat;
        if (! is_finite($lng) || $lat < -90 || $lat > 90) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $this->pushVoyageUndo();
        $gc = $this->ensureVoyageDef();
        $wp = $gc['voyage_def']['legs'][$leg]['wp'] ?? null;
        // The bend must fall between two of THIS leg's own waypoints: start <= afterWpIndex < end.
        if (! is_array($wp) || $afterWpIndex < (int) $wp[0] || $afterWpIndex >= (int) $wp[1]) {
            return;
        }
        $wps = array_values($gc['voyage_def']['waypoints']);
        $legs = array_values($gc['voyage_def']['legs']);
        $insertAt = $afterWpIndex + 1;
        // Keep the bend contiguous with the point it follows (antimeridian-safe, see setVoyageWaypoint).
        $lng = self::unwrapLngNear($lng, isset($wps[$afterWpIndex][0]) ? (float) $wps[$afterWpIndex][0] : null);
        array_splice($wps, $insertAt, 0, [[round($lng, 3), round($lat, 3)]]);
        foreach ($legs as &$L) {
            if ((int) $L['wp'][0] >= $insertAt) {
                $L['wp'][0] = (int) $L['wp'][0] + 1;
            }
            if ((int) $L['wp'][1] >= $insertAt) {
                $L['wp'][1] = (int) $L['wp'][1] + 1;
            }
        }
        unset($L);

        $gc['voyage_def']['waypoints'] = $wps;
        $gc['voyage_def']['legs'] = $legs;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);
    }

    /**
     * Drop a bend the teacher double-clicked on the map. Only an INTERIOR point of the current leg
     * may go: its start is the previous landfall and its end is this leg's landfall, and removing
     * either would re-route a different scene.
     */
    #[On('voyageWaypointRemoved')]
    public function removeVoyageWaypoint(int $sceneId, int $wpIndex): void
    {
        if (! $this->selectedScene || (int) ($this->selectedScene['id'] ?? 0) !== $sceneId) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        // Push BEFORE materialising, exactly like the drag/insert handlers: ensureVoyageDef() re-reads
        // game_config, so the undo entry survives instead of being clobbered by a stale copy.
        $this->pushVoyageUndo();
        $gc = $this->ensureVoyageDef();
        $wp = $gc['voyage_def']['legs'][$leg]['wp'] ?? null;
        if (! is_array($wp) || $wpIndex <= (int) $wp[0] || $wpIndex >= (int) $wp[1] || ! isset($gc['voyage_def']['waypoints'][$wpIndex])) {
            return;
        }

        $wps = array_values($gc['voyage_def']['waypoints']);
        $legs = array_values($gc['voyage_def']['legs']);
        array_splice($wps, $wpIndex, 1);
        foreach ($legs as &$L) {
            if ((int) $L['wp'][0] > $wpIndex) {
                $L['wp'][0] = (int) $L['wp'][0] - 1;
            }
            if ((int) $L['wp'][1] > $wpIndex) {
                $L['wp'][1] = (int) $L['wp'][1] - 1;
            }
        }
        unset($L);

        $gc['voyage_def']['waypoints'] = $wps;
        $gc['voyage_def']['legs'] = $legs;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);
    }

    /**
     * Add a draggable "bend" waypoint to the current leg (just before its landfall), so the teacher
     * can steer the route around land. Inserted into the shared waypoints array with every later leg
     * index shifted to stay valid.
     */
    public function addLegWaypoint(): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        $leg = (int) ($this->selectedScene['config']['leg'] ?? 0);
        $gc = $this->ensureVoyageDef();
        if (! isset($gc['voyage_def']['legs'][$leg]['wp'])) {
            return;
        }
        $wps = array_values($gc['voyage_def']['waypoints']);
        $legs = array_values($gc['voyage_def']['legs']);
        [$start, $end] = [(int) $legs[$leg]['wp'][0], (int) $legs[$leg]['wp'][1]];
        // New bend halfway between the point before the landfall and the landfall.
        $a = $wps[$end - 1] ?? $wps[$start] ?? [0.0, 20.0];
        $b = $wps[$end] ?? [0.0, 20.0];
        $mid = [round(((float) $a[0] + (float) $b[0]) / 2, 3), round(((float) $a[1] + (float) $b[1]) / 2, 3)];

        $insertAt = $end;   // slot the bend just before the landfall
        array_splice($wps, $insertAt, 0, [$mid]);
        foreach ($legs as &$L) {
            if ((int) $L['wp'][0] >= $insertAt) {
                $L['wp'][0] = (int) $L['wp'][0] + 1;
            }
            if ((int) $L['wp'][1] >= $insertAt) {
                $L['wp'][1] = (int) $L['wp'][1] + 1;
            }
        }
        unset($L);

        $gc['voyage_def']['waypoints'] = $wps;
        $gc['voyage_def']['legs'] = $legs;
        $this->lesson->update(['game_config' => $gc]);
        $this->lesson->refresh();
        unset($this->voyageDef);
        $this->selectSceneInternal($this->selectedSceneId);
    }

    /** Voyage scene: append a coastal thumbnail pin (URL string) shown at the landfall. */
    public function addStopImage(string $url): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $url = trim($url);
        if ($url === '') {
            return;
        }
        $imgs = array_values((array) ($this->selectedScene['config']['stop_images'] ?? []));
        if (count($imgs) >= self::STOP_IMAGES_MAX) {
            $this->dispatch('toast', message: __('Maximum of :n stop images reached.', ['n' => self::STOP_IMAGES_MAX]), type: 'warning');

            return;
        }
        $imgs[] = $url;
        $this->selectedScene['config']['stop_images'] = $imgs;
        $this->saveSelected();
    }

    /** Voyage scene: remove the stop image at $index. */
    public function removeStopImage(int $index): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $imgs = array_values((array) ($this->selectedScene['config']['stop_images'] ?? []));
        if (! isset($imgs[$index])) {
            return;
        }
        array_splice($imgs, $index, 1);
        $this->selectedScene['config']['stop_images'] = array_values($imgs);
        $this->saveSelected();
    }

    /** Voyage scene: reorder a stop image from one slot to another. */
    public function moveStopImage(int $from, int $to): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $imgs = array_values((array) ($this->selectedScene['config']['stop_images'] ?? []));
        if (! isset($imgs[$from]) || $to < 0 || $to >= count($imgs)) {
            return;
        }
        $moved = array_splice($imgs, $from, 1);
        array_splice($imgs, $to, 0, $moved);
        $this->selectedScene['config']['stop_images'] = array_values($imgs);
        $this->saveSelected();
    }

    // Two gallery shapes share these mutators: a VOYAGE landfall gallery lives at
    // config.gallery.images (opened by the hotspot), while a STANDALONE story slide (kind 'gallery',
    // the intro/outro) keeps its images at config.images. Route to the right path by scene kind so
    // the same add/remove/reorder buttons work for both.
    private function galleryImagesAtRoot(): bool
    {
        return ($this->selectedScene['kind'] ?? null) === 'gallery';
    }

    /** @return array<int,array{url:string,credit:string}> */
    private function galleryImages(): array
    {
        $cfg = $this->selectedScene['config'] ?? [];
        $imgs = $this->galleryImagesAtRoot() ? ($cfg['images'] ?? []) : ($cfg['gallery']['images'] ?? []);

        return array_values((array) $imgs);
    }

    /** @param  array<int,mixed>  $imgs */
    private function setGalleryImages(array $imgs): void
    {
        if ($this->galleryImagesAtRoot()) {
            $this->selectedScene['config']['images'] = array_values($imgs);
        } else {
            $this->selectedScene['config']['gallery']['images'] = array_values($imgs);
        }
        $this->saveSelected();
    }

    /** How gallery images sit in the frame: 'cover' (fill, may crop) or 'fit' (letterbox + blur). */
    public function setGalleryFit(string $fit): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $fit = $fit === 'cover' ? 'cover' : 'fit';
        if ($this->galleryImagesAtRoot()) {
            $this->selectedScene['config']['fit'] = $fit;
        } else {
            $this->selectedScene['config']['gallery']['fit'] = $fit;
        }
        $this->saveSelected();
    }

    /** Voyage gallery: append an image ({url, credit}). */
    public function addGalleryImage(string $url, string $credit = ''): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $url = trim($url);
        if ($url === '') {
            return;
        }
        $imgs = $this->galleryImages();
        if (count($imgs) >= self::GALLERY_IMAGES_MAX) {
            $this->dispatch('toast', message: __('Maximum of :n gallery images reached.', ['n' => self::GALLERY_IMAGES_MAX]), type: 'warning');

            return;
        }
        $imgs[] = ['url' => $url, 'credit' => trim($credit)];
        $this->setGalleryImages($imgs);
    }

    /** Voyage gallery: remove the image at $index. */
    public function removeGalleryImage(int $index): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $imgs = $this->galleryImages();
        if (! isset($imgs[$index])) {
            return;
        }
        array_splice($imgs, $index, 1);
        $this->setGalleryImages($imgs);
    }

    /** Voyage gallery: reorder an image from one slot to another. */
    public function moveGalleryImage(int $from, int $to): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $imgs = $this->galleryImages();
        if (! isset($imgs[$from]) || $to < 0 || $to >= count($imgs)) {
            return;
        }
        $moved = array_splice($imgs, $from, 1);
        array_splice($imgs, $to, 0, $moved);
        $this->setGalleryImages($imgs);
    }

    /** Voyage gallery: edit the credit line on the image at $index. */
    public function setGalleryImageCredit(int $index, string $credit): void
    {
        if (! $this->selectedScene) {
            return;
        }
        $imgs = $this->galleryImages();
        if (! isset($imgs[$index])) {
            return;
        }
        $imgs[$index]['credit'] = trim($credit);
        $this->setGalleryImages($imgs);
    }

    /**
     * Inline-edit the gallery title / date / story straight on the scene (the teacher edits the
     * text where it's shown, not only in the side drawer). Fired by the gallery overlay's
     * contenteditable fields via the 'galleryTextChanged' event.
     */
    #[On('galleryTextChanged')]
    public function setGalleryText(int $sceneId, string $field, string $value): void
    {
        if (! $this->selectedScene || (int) ($this->selectedScene['id'] ?? 0) !== $sceneId) {
            return;
        }
        if (! in_array($field, ['title', 'date_label', 'story'], true)) {
            return;
        }
        $this->selectedScene['config']['gallery'][$field] = $value;
        $this->saveSelected();
    }

    /**
     * Persist the teacher-dragged position of a voyage leg's info hotspot (the gallery opener), as a
     * geo coordinate on the scene config. `config.hotspot` is excluded from the JS re-mount key, so
     * saving it applies live without glitching the map.
     */
    #[On('voyageHotspotMoved')]
    public function setVoyageHotspot(int $sceneId, $lng, $lat): void
    {
        if (! $this->selectedScene || (int) ($this->selectedScene['id'] ?? 0) !== $sceneId) {
            return;
        }
        $lng = (float) $lng;
        $lat = (float) $lat;
        if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
            return;
        }
        $this->selectedScene['config']['hotspot'] = ['lng' => round($lng, 4), 'lat' => round($lat, 4)];
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
            if (count($clean) >= self::ANNOTATIONS_MAX) {
                break;
            }
            if (! is_array($a)) {
                continue;
            }
            $type = $a['type'] ?? null;

            if ($type === 'focus') {
                if (! isset($a['lng'], $a['lat']) || ! is_numeric($a['lng']) || ! is_numeric($a['lat'])) {
                    continue;
                }
                // Preserve the dual-name period and the auto-capital flag alongside the core fields.
                $historical = isset($a['historical']) && $a['historical'] !== null && $a['historical'] !== ''
                    ? mb_substr(trim((string) $a['historical']), 0, self::FOCUS_LABEL_MAX)
                    : null;
                $clean[] = [
                    'type' => 'focus',
                    'lng' => (float) $a['lng'],
                    'lat' => (float) $a['lat'],
                    'label' => mb_substr(trim((string) ($a['label'] ?? '')), 0, self::FOCUS_LABEL_MAX),
                    'historical' => $historical,
                    'capital' => (bool) ($a['capital'] ?? false),
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
        if ($this->guestDemoCannotGenerate()) {
            return;
        }

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

    /**
     * Kick off the 4-candidate panorama flow. The teacher will pick one of the results,
     * which then becomes the scene's skybox via selectSkyboxCandidate().
     */
    public function generateSkyboxCandidates(int $sceneId): void
    {
        if ($this->guestDemoCannotGenerate()) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        if (! $scene->image_path) {
            $this->dispatch('toast', message: 'Generate the flat image first.', type: 'warning');

            return;
        }

        $scene->update(['status' => 'generating', 'error_message' => null]);
        GenerateSkyboxCandidates::dispatch($sceneId);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    /**
     * Promote a chosen candidate to the scene's skybox: it becomes skybox_image_path
     * (which the player/preview render on the 3D sphere) and the candidate set is cleared.
     */
    public function selectSkyboxCandidate(int $sceneId, int $index): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        $cands = $scene->skybox_candidates ?? [];
        if (! isset($cands[$index])) {
            return;
        }

        $scene->update([
            'skybox_image_path' => $cands[$index],
            'skybox_candidates' => null,
            'status' => 'ready',
        ]);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    public function enhanceSkybox(int $sceneId): void
    {
        if ($this->guestDemoCannotGenerate()) {
            return;
        }

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

    /**
     * Remove a scene's background image (and its shot storyboard). The scene falls back to
     * the solid brand-navy backdrop; Regenerate brings an image back at any time.
     */
    public function deleteSceneImage(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        $paths = array_filter(array_merge(
            [$scene->image_path],
            array_column($scene->shots ?? [], 'image_path'),
        ));
        foreach ($paths as $path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }

        $scene->update([
            'image_path' => null,
            'shots' => null,
            'background_color' => self::BRAND_BACKGROUND,
        ]);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
    }

    // ── Painting backgrounds (corpus artworks) ───────────────────────────
    // Public-domain paintings from Wikimedia Commons, pre-linked to corpus QIDs.
    // Picking one downloads the 1920px rendition into lesson storage (no hotlinking
    // at student runtime) and records attribution on the scene — zero generation credits.

    public bool $paintingPickerOpen = false;

    public string $paintingQuery = '';

    /**
     * Live Wikimedia Commons results are opt-in per picker session: fetching them
     * inside the open/render path made that request slow enough for the 3s status
     * poll's stale response to morph the modal shut again (open-state race).
     */
    public bool $paintingCommonsLoaded = false;

    /** Wikimedia rate-limited the last search, so an empty grid means "ask again", not "none exist". */
    public bool $paintingSearchThrottled = false;

    /** Grid filter: '' = everything, 'painting' = paintings, 'city_map' = Braun & Hogenberg-style city plans. */
    public string $paintingKind = '';

    /** The scene's derived match target {core, themes, actor_qids, era} — drives the scored grid. */
    public array $matchTarget = [];

    /**
     * Region focus for the picker: '' = Auto (follow the lesson subject — colonisation reaches
     * the Americas, VOC reaches Asia), 'european' to force Europe, 'americas'|'asia'|'africa' to
     * browse another region, or 'all'. Region is only a soft ranking nudge — the subject decides.
     */
    public string $paintingRegion = '';

    /** False until the wire:init prep runs — lets the modal paint instantly with a skeleton. */
    public bool $paintingReady = false;

    /** Whether the collapsible search bar is open (entangled with Alpine so it survives re-renders). */
    public bool $searchOpen = false;

    /** Which subject region(s) to PREFER in scoring for the current focus (soft, never a gate). */
    private function regionPreference(): array
    {
        return match ($this->paintingRegion) {
            'all' => ['european', 'americas', 'asia', 'africa', 'universal'],
            'european' => ['european'],
            'americas' => ['americas'],
            'asia' => ['asia'],
            'africa' => ['africa'],
            default => $this->matchTarget['regions'] ?? ['european'],   // Auto: from the lesson subject
        };
    }

    private const PAINTING_GRID_LIMIT = 30;

    /**
     * Width to request for a picker grid card, in pixels.
     *
     * The cards are ~230 CSS px wide, so 400 still has pixels to spare on a 2× display. The grid
     * used to ask for 800: on one measured painting that is 186 KB per card against 47 KB, so a
     * full grid pulled megabytes before the teacher had picked anything. The full-size image is
     * fetched only once, when a painting is actually chosen.
     */
    private const PAINTING_THUMB_WIDTH = 400;

    /** 'background' — the chosen painting replaces the scene background; 'layer' — it's added as an
     *  editable image layer on top (the "Image" header button). Routed in applyPaintingChoice(). */
    public string $paintingPickerMode = 'background';

    public function openPaintingPicker(): void   // "Browse paintings" → set as the background
    {
        $this->paintingPickerMode = 'background';
        $this->openPaintingPickerCommon();
    }

    /** "Image" header button → pick a public-domain painting and drop it on as an image LAYER. */
    #[On('open-image-library')]
    public function openImageLibrary(): void
    {
        $this->paintingPickerMode = 'layer';
        $this->openPaintingPickerCommon();
    }

    /** Voyage inspector "+ Add image" → pick art to pin at the landfall as a stop image. */
    public function openStopImagePicker(): void
    {
        $this->paintingPickerMode = 'voyage_stop';
        $this->openPaintingPickerCommon();
        $this->seedLandfallImageSearch();
    }

    /**
     * "3D model" → search Sketchfab in OUR modal.
     *
     * This used to be a window.prompt asking for a pasted link, which sent the teacher off to
     * another site to hunt for a URL and copy it back. Same modal as the paintings, same grid,
     * same search box; only the source behind it differs.
     */
    public function openModelPicker(): void
    {
        $this->paintingPickerMode = 'model3d';
        $this->openPaintingPickerCommon();
        // Seed from what the scene is about, so the grid opens on something relevant.
        $scene = $this->selectedSceneModel();
        $this->paintingQuery = trim((string) ($scene?->location ?: $this->lesson->topic ?: ''));
        $this->revealSeededSearch();
    }

    /** Voyage inspector "Use a picture" → pick the art that travels this leg instead of a model. */
    public function openMarkerImagePicker(): void
    {
        $this->paintingPickerMode = 'voyage_marker';
        $this->openPaintingPickerCommon();
        $this->seedLandfallImageSearch();
    }

    /** Gallery inspector "+ Add image" → pick art to append to the gallery slideshow. */
    public function openGalleryImagePicker(): void
    {
        $this->paintingPickerMode = 'gallery_image';
        $this->openPaintingPickerCommon();
        $this->seedGalleryImageSearch();
    }

    /**
     * Seed the picker for whichever gallery the teacher opened it from.
     *
     * A voyage landfall seeds from its place name. A STANDALONE gallery scene has no place and no
     * voyage, so seeding it the landfall way produced an empty query — and because that seeder also
     * force-enables Commons search, the grid opened completely blank with "No paintings found".
     * Seed from the most specific text the teacher has already written instead.
     */
    private function seedGalleryImageSearch(): void
    {
        $scene = $this->selectedSceneModel();

        if ($scene?->kind === 'voyage') {
            $this->seedLandfallImageSearch();

            return;
        }

        $config = $scene?->config ?? [];
        $term = collect([
            $config['title'] ?? null,          // what the teacher called this gallery
            $scene?->location ?? null,          // the place, if the scene names one
            $this->lesson->topic ?? null,       // else the lesson's own subject
        ])->map(fn ($v) => trim((string) $v))->first(fn (string $v) => $v !== '') ?? '';

        $this->paintingQuery = $term;
        // Only reach past the corpus to Commons once there is something to search for; an empty
        // term with Commons on is exactly what produced the blank grid.
        $this->paintingCommonsLoaded = $term !== '';
        $this->revealSeededSearch();
    }

    /**
     * Auto-search the picker for a voyage landfall: pre-fill the query from the scene's place
     * (e.g. "Mauritius") and turn on Commons live-search, since landfalls are usually too obscure
     * for the paintings corpus. Runs AFTER openPaintingPickerCommon() (which clears both).
     */
    private function seedLandfallImageSearch(): void
    {
        $scene = $this->selectedSceneModel();
        // Place, minus any parenthetical qualifier: "Moordenaarsbaai (Golden Bay, NZ)" → "Moordenaarsbaai".
        $place = trim(preg_replace('/\s*\(.*$/', '', (string) ($scene?->location ?? '')));
        // Pair it with the voyage/explorer name so the search lands on PERIOD works ("Mauritius Abel
        // Tasman" → Tasman's chart of Mauritius) instead of the modern namesake (HMS Mauritius, 1942).
        $voyageName = trim((string) ($this->voyageDef()['name'] ?? ''));
        $this->paintingQuery = trim($place.' '.$voyageName);
        $this->paintingCommonsLoaded = true;   // landfalls are usually too obscure for the corpus alone
        $this->revealSeededSearch();
    }

    /**
     * Show the search bar whenever the picker was opened with a pre-filled query.
     *
     * The bar is hidden behind a magnifier by default, which is right for an unfiltered grid — but
     * a SEEDED grid is already filtered by a term the teacher never typed and cannot see. They are
     * then left guessing why a gallery called "The three ships" turned up nursery illustrations,
     * with no obvious way to change it. Reveal the term so it reads as an editable starting point.
     */
    private function revealSeededSearch(): void
    {
        $this->searchOpen = trim($this->paintingQuery) !== '';
    }

    /**
     * Teacher uploaded their own image in the Add-image modal — store it and apply it to whatever
     * the modal was opened for (landfall thumbnail, gallery image, or the scene background).
     * Fires automatically on file selection (wire:model="uploadImage").
     */
    public function updatedUploadImage(): void
    {
        $this->validate(['uploadImage' => 'image|mimes:jpg,jpeg,png,webp,gif|max:8192']);   // 8 MB

        $mode = $this->paintingPickerMode;
        $this->paintingPickerOpen = false;

        // Landfall/gallery images are referenced by URL in scene config, so host them on Cloudinary
        // (falls back to local storage when Cloudinary isn't configured). The scene BACKGROUND uses a
        // relative image_path rendered via asset('storage/…'), so that one stays local for now.
        if (in_array($mode, ['voyage_stop', 'voyage_marker', 'gallery_image'], true)) {
            $cloud = app(\App\Services\CloudinaryService::class);
            $url = $cloud->configured()
                ? $cloud->uploadBytes($this->uploadImage->get(), "lessons/{$this->lesson->id}")
                : null;
            $url ??= '/storage/'.$this->uploadImage->store("lessons/{$this->lesson->id}/uploads", 'public');
            $this->reset('uploadImage');
            match ($mode) {
                'voyage_stop' => $this->addStopImage($url),
                'voyage_marker' => $this->setLegMarkerImage($url),
                default => $this->addGalleryImage($url, ''),
            };

            return;
        }

        $path = $this->uploadImage->store("lessons/{$this->lesson->id}/uploads", 'public');
        $this->reset('uploadImage');
        $this->applyUploadedBackground($path);
    }

    /** Set an uploaded image as the selected scene's flat background (map/narration scenes). */
    private function applyUploadedBackground(string $path): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $cfg = $scene->config ?? [];
        unset($cfg['bg_embed']);   // an image background replaces any 3D / video embed
        $scene->update([
            'image_path' => $path,
            'shots' => $this->shotsPreservingArtwork($scene, $path),
            'skybox_image_path' => null,
            'scene_view' => 'slideshow',
            'status' => 'ready',
            'config' => $cfg,
        ]);
        $this->selectSceneInternal($scene->id);
    }

    /**
     * Reuse an image ALREADY in this lesson (from posterCandidates) as the selected scene's flat
     * background. Local /storage URLs reuse their path directly; external URLs (Cloudinary / gallery)
     * are fetched once into this lesson's uploads so the background stays a local storage path.
     */
    public function useLessonImageBackground(string $url): void
    {
        $url = trim($url);
        // Called from the browser, so the URL is checked against what we own — this reuses an
        // image the lesson already has, it is not a place to name an arbitrary host.
        if ($url === '' || ! $this->selectedSceneId || ! $this->isOwnImageUrl($url)) {
            return;
        }
        $path = $this->libraryImagePath($url);
        if ($path) {
            $this->applyUploadedBackground($path);
        }
    }

    /** Store an embed as the scene's background (mutually exclusive with an image/skybox). */
    private function applyBgEmbed(array $embed): void
    {
        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $cfg = $scene->config ?? [];
        $cfg['bg_embed'] = $embed;
        $scene->update([
            'config' => $cfg,
            'image_path' => null,
            'skybox_image_path' => null,
            'scene_view' => 'embed',
            'status' => 'ready',
        ]);
        $this->selectSceneInternal($scene->id);
    }

    /** Set a Sketchfab 3D model (pasted model link or full <iframe> embed) as the scene background. */
    public function setSketchfabEmbed(string $input): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $p = app(\App\Services\EmbedParser::class)->sketchfab($input);
        if (! $p) {
            $this->dispatch('toast', message: __('That doesn\'t look like a Sketchfab model link.'), type: 'warning');

            return;
        }
        // Autostart + gently autospin, and hide ALL viewer chrome so it reads as a clean background
        // (no controls, no info panel, no watermark link, no hint/help/AR/VR/settings/fullscreen).
        $p['src'] = $p['src'].'?'.http_build_query([
            'autostart' => 1,
            'autospin' => 0.2,
            'ui_controls' => 0,
            'ui_infos' => 0,
            'ui_watermark' => 0,
            'ui_watermark_link' => 0,
            'ui_hint' => 0,
            'ui_ar' => 0,
            'ui_help' => 0,
            'ui_settings' => 0,
            'ui_vr' => 0,
            'ui_fullscreen' => 0,
            'ui_annotations' => 0,
            'ui_stop' => 0,
            'scrollwheel' => 0,
            'dnt' => 1,
        ]);
        $this->applyBgEmbed($p);
    }

    /** Set a video (YouTube / Vimeo / pasted iframe) as the scene background, with default settings. */
    public function setVideoEmbed(string $input): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $parser = app(\App\Services\EmbedParser::class);
        $p = $parser->video($input);
        if (! $p) {
            $this->dispatch('toast', message: __('Paste a YouTube, Vimeo or embed link.'), type: 'warning');

            return;
        }
        $p['embed_base'] = $p['src'];   // bare src, so settings can be re-applied later
        $p += ['autoplay' => true, 'start' => 0, 'end' => 0, 'fit' => 'cover', 'controls' => true];
        $p['src'] = $parser->embedVideoSrc($p, $p);
        $this->applyBgEmbed($p);
    }

    /** Live-update a video background's playback settings (autoplay / start / end / fit / controls). */
    public function setEmbedOptions(array $opts): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $cfg = $scene->config ?? [];
        $e = $cfg['bg_embed'] ?? null;
        if (! is_array($e) || ($e['kind'] ?? '') !== 'video') {
            return;
        }
        $e['autoplay'] = (bool) ($opts['autoplay'] ?? $e['autoplay'] ?? true);
        $e['start'] = max(0, (int) ($opts['start'] ?? $e['start'] ?? 0));
        $e['end'] = max(0, (int) ($opts['end'] ?? $e['end'] ?? 0));
        $e['fit'] = in_array($opts['fit'] ?? '', ['cover', 'fit'], true) ? $opts['fit'] : ($e['fit'] ?? 'cover');
        $e['controls'] = (bool) ($opts['controls'] ?? $e['controls'] ?? true);
        $e['src'] = app(\App\Services\EmbedParser::class)->embedVideoSrc(
            ['provider' => $e['provider'] ?? 'other', 'src' => $e['embed_base'] ?? $e['src']],
            $e,
        );
        $cfg['bg_embed'] = $e;
        $scene->update(['config' => $cfg]);
        $this->selectSceneInternal($scene->id);
    }

    /** Remove an embed background (back to a plain/image background). */
    public function clearBgEmbed(): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $cfg = $scene->config ?? [];
        unset($cfg['bg_embed']);
        $scene->update(['config' => $cfg, 'scene_view' => null]);
        $this->selectSceneInternal($scene->id);
    }

    private function openPaintingPickerCommon(): void
    {
        $this->paintingPickerOpen = true;
        $this->paintingCommonsLoaded = false;
        $this->paintingQuery = '';
        $this->paintingKind = '';
        $this->paintingRegion = '';
        $this->matchTarget = [];
        $this->paintingReady = false;   // modal opens instantly; wire:init fills the grid
        $this->searchOpen = false;
        $this->dispatch('painting-picker:open');
    }

    /** Thumbnail click → route to background-swap / add-as-layer / voyage-stop / gallery per mode. */
    public function applyPaintingChoice(string $source, string $key): void
    {
        // A library pick is a picture we already host, so it never goes through the
        // download-and-credit path the corpus and Commons need.
        if ($source === 'library') {
            $this->applyLibraryImage($key);

            return;
        }

        match ($this->paintingPickerMode) {
            'layer' => $this->applyPaintingAsLayer($source, $key),
            'voyage_stop' => $this->applyPaintingAsStopImage($source, $key),
            'voyage_marker' => $this->applyPaintingAsMarkerImage($source, $key),
            // A Sketchfab uid is all addEmbedLayer needs — EmbedParser reads the 32-hex id.
            'model3d' => $this->applyModelChoice($key),
            'gallery_image' => $this->applyPaintingAsGalleryImage($source, $key),
            default => $this->applyPaintingBackground($source, $key),
        };
    }

    /** Download a picked painting to lesson storage and pin it as this voyage leg's stop image. */
    public function applyPaintingAsStopImage(string $source, string $key): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $this->paintingPickerOpen = false;
        $stored = $this->storePickedPainting($source, $key, 'stop');
        if ($stored) {
            $this->addStopImage($stored['url']);
        }
    }

    /** Drop the chosen Sketchfab model onto the scene as a free-positioned 3D layer. */
    public function applyModelChoice(string $uid): void
    {
        $this->paintingPickerOpen = false;
        $this->addEmbedLayer($uid, '3d');
    }

    /** Download a picked painting to lesson storage and make it this leg's travelling marker. */
    public function applyPaintingAsMarkerImage(string $source, string $key): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $this->paintingPickerOpen = false;
        $stored = $this->storePickedPainting($source, $key, 'marker');
        if ($stored) {
            $this->setLegMarkerImage($stored['url']);
        }
    }

    /** Download a picked painting to lesson storage and append it to this gallery scene. */
    public function applyPaintingAsGalleryImage(string $source, string $key): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $this->paintingPickerOpen = false;
        $stored = $this->storePickedPainting($source, $key, 'gallery');
        if ($stored) {
            $this->addGalleryImage($stored['url'], $stored['credit']);
        }
    }

    /**
     * Download a picked corpus/Commons painting into lesson storage and return its public URL + a
     * one-line credit. Shared by the voyage stop-image and gallery-image flows so picked art is
     * stored locally (no student-runtime hotlinking), mirroring applyPaintingBackground().
     *
     * @return array{url:string,credit:string}|null
     */
    private function storePickedPainting(string $source, string $key, string $prefix): ?array
    {
        [$imageUrl, $credit, $fileStem] = match ($source) {
            'corpus' => $this->corpusPaintingPayload($key),
            'commons' => $this->commonsPaintingPayload($key),
            default => [null, null, null],
        };
        if (! $imageUrl) {
            $this->dispatch('toast', message: __('Painting not found. Try another one.'), type: 'warning');

            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'LearningPortal/1.0 (thelearningportal.us; lesson voyage images)',
            ])->timeout(30)->get($imageUrl.'?width=1600');
            if (! $response->successful() || $response->body() === '') {
                throw new \RuntimeException('HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('We could not download that painting. Try another one.'), type: 'error');

            return null;
        }

        $creditLine = trim(($credit['title'] ?? '')
            .(! empty($credit['creator']) ? ' — '.$credit['creator'] : ''));

        // Host on Cloudinary (off our storage/bandwidth); fall back to a root-relative local path.
        $cloud = app(\App\Services\CloudinaryService::class);
        $url = $cloud->configured() ? $cloud->uploadBytes($response->body(), "lessons/{$this->lesson->id}") : null;
        if (! $url) {
            $ext = str_contains((string) $response->header('Content-Type'), 'png') ? 'png' : 'jpg';
            $path = "lessons/{$this->lesson->id}/voyage/{$prefix}-{$fileStem}.{$ext}";
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $response->body());
            // Root-relative (NOT Storage::url(), which prepends APP_URL → a host-fragile absolute URL).
            $url = '/storage/'.$path;
        }

        return ['url' => $url, 'credit' => $creditLine];
    }

    /**
     * Fill the scored grid AFTER the modal has painted (called via wire:init), so opening the
     * picker is instant. Derives the scene's match target — one LLM call, cached on the scene —
     * which is the slow bit that used to block the open.
     */
    public function preparePaintings(): void
    {
        $scene = $this->selectedSceneModel();
        if ($scene) {
            try {
                $this->matchTarget = app(\App\Services\Corpus\SceneMatchTargetService::class)->for($scene, $this->lesson);
            } catch (\Throwable $e) {
                $this->matchTarget = [];
            }
        }
        $this->paintingReady = true;
    }

    // ── Ink artwork library ──────────────────────────────────────────────
    // Teacher-imported public-domain SVGs (Wikimedia Commons / freesvg.org),
    // drawn line-by-line in the ink style. See App\Livewire\SvgAssetLibrary.

    public bool $svgLibraryOpen = false;

    #[On('open-svg-library')]
    public function openSvgLibrary(): void
    {
        $this->svgLibraryOpen = true;
    }

    /**
     * Best-effort numeric year for the selected scene, used to surface paintings that
     * depict the same era. Handles "1453", "1204 CE", "500 BCE", and "16th century"
     * (mapped to its mid-point, ~1550). Returns null when nothing parseable is present.
     */
    private function sceneApproxYear(): ?int
    {
        $raw = trim((string) ($this->selectedScene['year'] ?? ''));
        if ($raw === '') {
            return null;
        }

        $isBce = (bool) preg_match('/\b(BCE|BC)\b/i', $raw);

        if (preg_match('/(\d+)\s*(?:st|nd|rd|th)\s*century/i', $raw, $m)) {
            $century = (int) $m[1];
            $year = ($century - 1) * 100 + 50; // mid-point of the century
        } elseif (preg_match('/\b(\d{1,4})\b/', $raw, $m)) {
            $year = (int) $m[1];
        } else {
            return null;
        }

        return $isBce ? -$year : $year;
    }

    /**
     * Picker grid: curated corpus paintings first, then live Wikimedia Commons
     * results (depicts-search by topic QID + text search) to fill the grid.
     * Every tile is normalized to one shape so the blade renders a single list.
     *
     * @return \Illuminate\Support\Collection<int,array{source:string,key:string,thumb:string,title:string,caption:string}>
     */
    #[Computed]
    public function paintingResults()
    {
        // Nothing to compute until the modal has painted and wire:init prep has run — keeps
        // opening the picker instant (the skeleton shows meanwhile).
        if (! $this->paintingPickerOpen || ! $this->paintingReady) {
            return collect();
        }

        // 3D models come from Sketchfab, not the artwork corpus — same modal, different shelf.
        if ($this->paintingPickerMode === 'model3d') {
            return $this->modelResults();
        }

        // "Library" is our own imagery — no corpus, no Commons.
        if ($this->paintingKind === 'library') {
            return $this->libraryResults();
        }

        try {
            $term = trim($this->paintingQuery);
            $topicQid = preg_match('/^(?:figure|polity|event):(Q\d+)$/', (string) $this->lesson->topic_id, $m) ? $m[1] : null;
            $eventQid = preg_match('/^event:(Q\d+)$/', (string) $this->lesson->topic_id, $m) ? $m[1] : null;
            $location = trim((string) ($this->selectedScene['location'] ?? ''));

            $kind = $this->paintingKind ?: null;
            $target = $this->matchTarget;
            $preferRegions = $this->regionPreference();
            // Corpus failure (down, or the artworks table absent) must NOT take down the Commons
            // top-up below — obscure landfalls (Mauritius, Tonga) live on Commons, not the corpus.
            $corpus = collect();
            // The corpus lives on Supabase in us-east-2: a warm round trip is ~350ms and a search
            // that returns a full grid ~1.1s. Livewire re-evaluates this computed property on
            // EVERY render, so without a cache the same query is paid again on each keystroke,
            // each poll tick and each unrelated re-render. Commons has had a cache all along;
            // this gives the corpus the same treatment, keyed on everything that shapes the query.
            $corpusKey = 'picker.corpus.v1.'.md5(serialize([
                $term, $kind, $target, $preferRegions, $topicQid, $location,
                $this->sceneApproxYear(), self::PAINTING_GRID_LIMIT,
            ]));
            try {
                $corpus = Cache::remember($corpusKey, now()->addMinutes(15), fn () => \App\Models\Corpus\Topic::resilient(function () use ($term, $topicQid, $location, $kind, $target, $preferRegions) {
                    if ($term !== '') {
                        // Search: the term filters, but rank by the lesson's subject/region context so
                        // "Revolution" in a French-Revolution lesson floats French works to the top.
                        $found = \App\Models\Corpus\Artwork::matchScene(
                            [],                              // no core gate — the term is the filter
                            $target['themes'] ?? [],
                            $target['actor_qids'] ?? [],
                            $target['era'] ?? null,
                            self::PAINTING_GRID_LIMIT,
                            $kind,
                            $preferRegions,
                            $term,
                        )->get();

                        // Untagged works aren't in the scored index — fall back to a plain search only
                        // when the scored search finds nothing.
                        return $found->isNotEmpty()
                            ? $found
                            : \App\Models\Corpus\Artwork::search($term, self::PAINTING_GRID_LIMIT, $kind)->get();
                    }
                    // Match-scored view: gate on what the scene must SHOW (core tags), then rank by
                    // correctness (theme + actor + era + quality). This is the source-blind ranker.
                    if (! empty($target['core'])) {
                        $scored = \App\Models\Corpus\Artwork::matchScene(
                            $target['core'],
                            $target['themes'] ?? [],
                            $target['actor_qids'] ?? [],
                            $target['era'] ?? null,
                            self::PAINTING_GRID_LIMIT,
                            $kind,
                            $preferRegions,
                        )->get();
                        if ($scored->isNotEmpty()) {
                            return $scored;
                        }
                    }
                    // Fallback blend (no derived target, or the gate found nothing): depicts-by-topic
                    // + near-era + location cityscapes.
                    $suggested = collect();
                    if ($topicQid) {
                        $suggested = \App\Models\Corpus\Artwork::depicting($topicQid, self::PAINTING_GRID_LIMIT, $kind)->get();
                    }
                    $approxYear = $this->sceneApproxYear();
                    if ($approxYear !== null && $suggested->count() < self::PAINTING_GRID_LIMIT) {
                        $suggested = $suggested
                            ->concat(\App\Models\Corpus\Artwork::nearYear($approxYear, 60, self::PAINTING_GRID_LIMIT, $kind)->get())
                            ->unique('qid')
                            ->values();
                    }
                    if ($location !== '' && $suggested->count() < self::PAINTING_GRID_LIMIT) {
                        $suggested = $suggested
                            ->concat(\App\Models\Corpus\Artwork::search($location, self::PAINTING_GRID_LIMIT, $kind)->get())
                            ->unique('qid')
                            ->values();
                    }

                    return $suggested;
                }));
            } catch (\Throwable $corpusErr) {
                report($corpusErr);   // degrade to Commons-only rather than an empty grid
            }

            // Hand-matched story images (scraped → resolved to open Commons originals) lead the
            // grid for an event lesson — the teacher still sees the rest of the corpus below them.
            // Skipped while searching so a typed term keeps priority.
            if ($eventQid && $term === '') {
                $curated = \App\Models\Corpus\Topic::resilient(
                    fn () => \App\Models\Corpus\Artwork::storyFor($eventQid, self::PAINTING_GRID_LIMIT, $kind)->get()
                );
                if ($curated->isNotEmpty()) {
                    $corpus = $curated->concat($corpus)->unique('qid')->values()->take(self::PAINTING_GRID_LIMIT);
                }
            }

            // "Correctness n/m": soft criteria satisfied (themes + actors + era) out of the total.
            $softMax = count($target['themes'] ?? []) + count($target['actor_qids'] ?? []) + (! empty($target['era']) ? 1 : 0);

            $tiles = $corpus->map(fn ($art) => [
                'source' => 'corpus',
                'key' => $art->qid,
                'thumb' => $art->renditionUrl(self::PAINTING_THUMB_WIDTH),
                'title' => $art->title ?? __('Untitled'),
                'caption' => $art->caption(),
                'kind' => $art->kind,
                'provenance' => $art->collection ?: __('Wikimedia'),
                'correctness' => (isset($art->soft_hits) && $softMax > 0)
                    ? min($softMax, (int) $art->soft_hits).'/'.$softMax
                    : null,
            ]);

            // Top up from Commons live search — finds files the Wikidata harvest can't
            // (most Commons images have no Wikidata item of their own). City-map browsing
            // stays corpus-only: the curated Braun & Hogenberg set IS the map catalog.
            //
            // A TYPED term always reaches Commons, without waiting for the teacher to press a
            // button. The corpus is a sample, not a canon (it holds 94 Rembrandts but not The
            // Night Watch), so "nothing in the curated collection" was a dead end in front of an
            // archive that has the painting. Results are cached per term, so the cost lands once.
            $searchingWide = $term !== '' && mb_strlen($term) >= 3;
            if (($this->paintingCommonsLoaded || $searchingWide) && $this->paintingKind !== 'city_map' && $tiles->count() < self::PAINTING_GRID_LIMIT) {
                $commons = app(\App\Services\CommonsImageService::class);
                $live = collect();
                // Landfall art is often maps / engravings / views, not "paintings" — appending that
                // word buries the good period results, so search the raw term for voyage modes.
                $isLandfall = in_array($this->paintingPickerMode, ['voyage_stop', 'gallery_image'], true);
                if ($term !== '') {
                    $live = collect($commons->searchText($isLandfall ? $term : $term.' painting'));
                } else {
                    if ($topicQid) {
                        $live = collect($commons->searchDepicting($topicQid));
                    }
                    if ($location !== '') {
                        $live = $live->concat($commons->searchText($location.' painting'));
                    }
                }
                // Remember a throttle so the empty grid can say so. "No paintings found" in front
                // of an archive that HAS the painting is the worst answer we can give.
                $this->paintingSearchThrottled = $commons->lastError === 'throttled';
                $tiles = $tiles->concat($live->unique('file_title')->map(fn ($f) => [
                    'source' => 'commons',
                    'key' => $f['file_title'],
                    'thumb' => $f['thumb_url'],
                    'title' => $f['title'],
                    'caption' => trim(($f['artist'] ?? '').' · '.$f['license'], ' ·'),
                    'kind' => 'painting',
                    'provenance' => __('Wikimedia Commons'),
                    'correctness' => null,
                ]));
            }

            $grid = $tiles->unique(fn ($t) => mb_strtolower($t['title']))
                ->take(self::PAINTING_GRID_LIMIT)
                ->values();

            // Neither shelf had anything. Show what we already own rather than an empty grid —
            // the teacher can always use one of our own pictures.
            return $grid->isEmpty() ? $this->libraryResults() : $grid;
        } catch (\Throwable $e) {
            // Corpus / Commons unavailable (e.g. a dropped Supabase pooler connection that didn't
            // recover on the single retry) — fall back to our own library so the picker still
            // OPENS with something in it instead of rendering a 500 that reads as "can't open
            // Browse paintings".
            report($e);

            return $this->libraryResults();
        }
    }

    /**
     * Sketchfab search results shaped like painting tiles, so the existing grid renders them
     * unchanged. The author's name rides in the caption — attribution travels with the model.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function modelResults(): Collection
    {
        $term = trim($this->paintingQuery);
        if ($term === '') {
            return collect();
        }

        $sketchfab = app(\App\Services\SketchfabService::class);
        $models = $sketchfab->search($term, self::PAINTING_GRID_LIMIT);
        $this->paintingSearchThrottled = $sketchfab->lastError === 'throttled';

        return collect($models)->map(fn (array $m) => [
            'source' => 'sketchfab',
            'key' => $m['uid'],
            'thumb' => $m['thumb'],
            'title' => $m['name'],
            'caption' => trim($m['author'].($m['license'] !== '' ? ' · '.$m['license'] : ''), ' ·'),
            'kind' => '3d',
            'provenance' => 'Sketchfab',
            'correctness' => null,
        ])->values();
    }

    /**
     * The Library shelf: pictures we already have. This lesson's own imagery comes first (the
     * teacher just put it there, so it is the likeliest pick), then everything on our Cloudinary
     * account. Shaped like painting tiles so the one grid renders all three shelves.
     *
     * This is also the fallback when the corpus and Commons both come up empty — "nothing found"
     * in front of a collection we own is a dead end we can always avoid.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function libraryResults(): Collection
    {
        $term = trim($this->paintingQuery);
        $cloud = app(\App\Services\CloudinaryService::class);

        $tiles = collect($this->lesson->posterCandidates())
            ->filter(fn (array $c) => $term === '' || mb_stripos($c['label'].' '.$c['url'], $term) !== false)
            ->map(fn (array $c) => [
                'source' => 'library',
                'key' => $c['url'],
                'thumb' => $cloud->thumb($this->libraryImageSrc($c['url'])),
                'title' => $c['label'],
                'caption' => __('In this lesson'),
                'kind' => 'library',
                'provenance' => __('This lesson'),
                'correctness' => null,
            ]);

        $tiles = $tiles->concat(collect($cloud->library($term))->map(fn (array $img) => [
            'source' => 'library',
            'key' => $img['url'],
            'thumb' => $img['thumb'],
            'title' => $img['title'],
            'caption' => __('Our collection'),
            'kind' => 'library',
            'provenance' => __('Our collection'),
            'correctness' => null,
        ]));

        // A lesson image that lives on Cloudinary appears in both shelves under two different
        // delivery transforms, so the same picture would show up twice. The public id (the last
        // path segment) is what identifies it; a local /storage image dedupes on its own URL.
        return $tiles
            ->unique(fn (array $t) => str_contains($t['key'], 'res.cloudinary.com')
                ? pathinfo((string) parse_url($t['key'], PHP_URL_PATH), PATHINFO_FILENAME)
                : $t['key'])
            ->take(self::PAINTING_GRID_LIMIT)
            ->values();
    }

    /**
     * Use one of our own pictures — routed to whatever the picker was opened for. Nothing is
     * downloaded from a third party here: the image is already on our CDN or our public disk.
     */
    private function applyLibraryImage(string $url): void
    {
        $url = trim($url);
        // The key arrives from the browser, so it is checked against what we actually own rather
        // than trusted — otherwise this would fetch any URL a crafted call names.
        if ($url === '' || ! $this->selectedSceneId || ! $this->isOwnImageUrl($url)) {
            return;
        }
        $this->paintingPickerOpen = false;

        match ($this->paintingPickerMode) {
            'layer' => $this->addLibraryImageLayer($url),
            'voyage_stop' => $this->addStopImage($url),
            'voyage_marker' => $this->setLegMarkerImage($url),
            'gallery_image' => $this->addGalleryImage($url),
            default => $this->useLessonImageBackground($url),
        };
    }

    /**
     * Drop one of our own pictures on the slide as an editable image layer. Mirrors
     * applyPaintingAsLayer(), minus the download and the attribution — this picture is ours.
     */
    private function addLibraryImageLayer(string $url): void
    {
        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        if (! $scene->hasBackdrop()) {
            $this->dispatch('toast', message: __('Generate a scene background first, then add an image on top of it.'), type: 'warning');

            return;
        }

        $path = $this->libraryImagePath($url);
        if (! $path) {
            $this->dispatch('toast', message: __('We could not use that image. Try another one.'), type: 'error');

            return;
        }

        // The layer machinery (move / scale / reorder / delete) is keyed on an SvgAsset, so wrap
        // the picture in one. The URL is the dedupe key, hashed to fit the source_ref column.
        $asset = $this->upsertPictureAsset('library', md5($url), [
            'source_url' => $url,
            'title' => pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: __('Image'),
            'license' => 'own',
            'attribution' => null,
            'svg_path' => $path,
        ]);

        $this->attachArtwork($asset->id);
    }

    /**
     * The asset behind a picture layer, created or refreshed.
     *
     * withTrashed, because (user, source, source_ref) is unique and a soft delete leaves the row
     * in place: a plain updateOrCreate on a picture the teacher had deleted inserted a second one
     * and died on the constraint — a 500 in the middle of "add an image". Picking it again is a
     * perfectly reasonable way to say "I want it back", so un-delete it.
     */
    private function upsertPictureAsset(string $source, string $ref, array $attributes): \App\Models\SvgAsset
    {
        $asset = \App\Models\SvgAsset::withTrashed()->firstOrNew([
            'user_id' => (int) auth()->id(),
            'source' => $source,
            'source_ref' => $ref,
        ]);

        $asset->fill($attributes)->forceFill(['deleted_at' => null])->save();

        return $asset;
    }

    /**
     * Public-disk path for an image we already own — what a scene background and an artwork layer
     * both store. An image already on the public disk is reused where it lies; the other two homes
     * our own pictures have (public/ itself, for imagery a lesson builder shipped, and our
     * Cloudinary CDN) are copied into this lesson's uploads once.
     */
    private function libraryImagePath(string $url): ?string
    {
        $relative = $this->ownRelativePath($url);
        if ($relative !== null) {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            if ($disk->exists($relative)) {
                return $relative;
            }

            // public/lessons/<slug>/… — shipped with the lesson, next to the public disk rather
            // than on it. The storage disk cannot see those files, so copy the bytes in.
            $absolute = public_path($relative);

            return is_file($absolute)
                ? $this->storeLibraryImage((string) file_get_contents($absolute), pathinfo($absolute, PATHINFO_EXTENSION))
                : null;
        }

        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(15)->get($url);
            if (! $resp->successful() || $resp->body() === '') {
                return null;
            }

            return $this->storeLibraryImage($resp->body(), pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        } catch (\Throwable $e) {
            report($e);   // network / decode failure

            return null;
        }
    }

    /** Copy a picture we own into this lesson's uploads and return its public-disk path. */
    private function storeLibraryImage(string $bytes, string $extension): string
    {
        $ext = strtolower($extension);
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true)) {
            $ext = 'jpg';
        }
        $path = "lessons/{$this->lesson->id}/uploads/reuse-".\Illuminate\Support\Str::random(10).'.'.$ext;
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /**
     * Is this a picture we host? Our own public disk and our own Cloudinary cloud always are, and
     * so is anything already sitting in this lesson (a gallery image may be hosted elsewhere).
     */
    private function isOwnImageUrl(string $url): bool
    {
        $cloud = app(\App\Services\CloudinaryService::class)->cloudName();
        if ($this->ownRelativePath($url) !== null
            || ($cloud && str_starts_with($url, "https://res.cloudinary.com/{$cloud}/"))) {
            return true;
        }

        return collect($this->lesson->posterCandidates())->contains(fn (array $c) => $c['url'] === $url);
    }

    /**
     * The path behind an image reference of ours, relative to the public root, or null when it
     * names another host. Voyage and gallery configs hold every shape: a /storage URL, a
     * root-relative public path ("/lessons/tasman/mauritius-voc.jpg"), and the bare path with no
     * leading slash — the last two were fetched over HTTP as if they were URLs, which sent the
     * picker looking for a host called "lessons" and told the teacher the image was unusable.
     */
    private function ownRelativePath(string $url): ?string
    {
        $path = preg_match('#/storage/(.+)$#', $url, $m)
            ? rawurldecode($m[1])
            : (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? null : ltrim($url, '/'));

        // "/storage/../../.env" is a path on the disk too. It is not one of ours.
        return ($path === null || $path === '' || str_contains($path, '..')) ? null : $path;
    }

    /**
     * Display URL for a library tile. Same shapes as ownRelativePath() — a bare path would
     * otherwise resolve against the wizard's own URL and render as a broken thumbnail.
     */
    private function libraryImageSrc(string $url): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*://|/)#i', $url)) {
            return $url;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        return $disk->exists($url) ? $disk->url($url) : '/'.$url;
    }

    public function applyPaintingBackground(string $source, string $key): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        // Dismiss immediately: a download can take a while, and a poll response must not leave
        // the picker covering the scene after the teacher has made a choice.
        $this->paintingPickerOpen = false;

        [$imageUrl, $credit, $fileStem] = match ($source) {
            'corpus' => $this->corpusPaintingPayload($key),
            'commons' => $this->commonsPaintingPayload($key),
            default => [null, null, null],
        };
        if (! $imageUrl) {
            $this->dispatch('toast', message: __('Painting not found. Try another one.'), type: 'warning');

            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'LearningPortal/1.0 (thelearningportal.us; lesson backgrounds)',
            ])->timeout(30)->get($imageUrl.'?width=1920');
            if (! $response->successful() || $response->body() === '') {
                throw new \RuntimeException('HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('We could not download that painting. Try another one.'), type: 'error');

            return;
        }

        $ext = str_contains((string) $response->header('Content-Type'), 'png') ? 'png' : 'jpg';
        $path = "lessons/{$this->lesson->id}/paintings/{$fileStem}.{$ext}";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $response->body());

        // Now the file is local, its SHAPE can be measured — the one signal the corpus cannot give.
        // Corpus artworks carry tags but no dimensions, so a full-length standing portrait tagged
        // {battle, soldiers, war} came through as 'center' and rendered cropped to the sitter's
        // waist. Upgrade to a top crop whenever the pixels say portrait, keeping any 'top' the
        // tags or title already established.
        $focus = $credit['focus'] ?? PortraitFocus::CENTER;
        if ($focus !== PortraitFocus::TOP) {
            $size = @getimagesize(\Illuminate\Support\Facades\Storage::disk('public')->path($path));
            if ($size !== false && PortraitFocus::isPortraitShape($size[0] ?? null, $size[1] ?? null)) {
                $focus = PortraitFocus::TOP;
            }
        }
        $credit['focus'] = $focus;

        $config = array_merge($scene->config ?? [], [
            'background_source' => 'painting',
            'background_credit' => $credit,
            'background_focus' => $focus,   // 'top' anchors portraits
        ]);
        // City plans double as layout ground truth: the image/3D pipeline reads
        // config.layout_reference to keep generated streets & squares historically placed.
        if (($credit['kind'] ?? null) === 'city_map') {
            $config['layout_reference'] = [
                'type' => 'historical_city_plan',
                'title' => $credit['title'],
                'city' => $credit['city'] ?? null,
                'atlas' => $credit['atlas'] ?? null,
                'image_url' => $credit['source_url'],
            ];
        }

        $scene->update([
            'image_path' => $path,
            // A painting replaces the storyboard, but keep any clipart the teacher attached.
            'shots' => $this->shotsPreservingArtwork($scene, $path),
            // A generated equirectangular skybox belongs to the old background and can otherwise
            // reappear when the canvas or its view mode refreshes.
            'skybox_image_path' => null,
            'skybox_candidates' => null,
            'scene_view' => 'slideshow', // paintings are flat images
            'config' => $config,
            'status' => 'ready',
            'upscale_status' => null,
            'error_message' => null,
        ]);

        $this->selectSceneInternal($scene->id);
    }

    /**
     * Add the chosen corpus/Commons painting as an editable IMAGE LAYER on the scene — above the
     * background, below the text — instead of replacing the background. The download is stored and
     * wrapped in an SvgAsset so it reuses the whole clipart-layer machinery (move / scale / reorder
     * / delete via the object list), then attached with attachArtwork().
     */
    public function applyPaintingAsLayer(string $source, string $key): void
    {
        if (! $this->selectedSceneId) {
            return;
        }
        $this->paintingPickerOpen = false;

        [$imageUrl, $credit, $fileStem] = match ($source) {
            'corpus' => $this->corpusPaintingPayload($key),
            'commons' => $this->commonsPaintingPayload($key),
            default => [null, null, null],
        };
        if (! $imageUrl) {
            $this->dispatch('toast', message: __('Painting not found. Try another one.'), type: 'warning');

            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        // A map, voyage or panorama scene has a backdrop without carrying an image_path, and a
        // picture is just as valid on top of a map as on top of a generated background. Checked
        // here as well as in attachArtwork so the teacher hears about it before the download, not
        // after — the two must agree, hence one shared rule on the model.
        if (! $scene->hasBackdrop()) {
            $this->dispatch('toast', message: __('Generate a scene background first, then add an image on top of it.'), type: 'warning');

            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'LearningPortal/1.0 (thelearningportal.us; lesson image layers)',
            ])->timeout(30)->get($imageUrl.'?width=1600');
            if (! $response->successful() || $response->body() === '') {
                throw new \RuntimeException('HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('We could not download that painting. Try another one.'), type: 'error');

            return;
        }

        $ext = str_contains((string) $response->header('Content-Type'), 'png') ? 'png' : 'jpg';
        $path = "lessons/{$this->lesson->id}/paintings/layer-{$fileStem}.{$ext}";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $response->body());

        // Wrap the painting in an asset so it becomes a first-class artwork layer (move / scale /
        // reorder / delete). Deduped on the (user, source, source_ref) unique key; a Commons file
        // title is bounded, so only a hand-built key long enough to overflow the column is hashed.
        $asset = $this->upsertPictureAsset(
            'commons',
            'painting:'.(mb_strlen($key) > 200 ? md5($key) : $key),
            [
                'source_url' => $credit['source_url'] ?? '',
                'title' => $credit['title'] ?? __('Painting'),
                'license' => $credit['license'] ?? 'Public domain',
                'attribution' => $credit['creator'] ?? null,
                'svg_path' => $path,
            ],
        );

        $this->attachArtwork($asset->id);   // appends it to shots[0].layers (below text) + reloads the scene
    }

    /** @return array{0:?string,1:?array,2:?string} [imageUrl base, credit payload, storage file stem] */
    private function corpusPaintingPayload(string $key): array
    {
        // Keys are Wikidata QIDs, synthetic ids ('bh-12345'), or synthetic 'commons:<filename>'
        // qids whose filenames carry spaces, accents, apostrophes and punctuation
        // (e.g. "commons:Anon - 'Les Noyades de Nantes' … c. 1799.jpg"). find() is a parameterized
        // lookup and the file stem is separately sanitised, so we only bound the length and reject
        // control characters here — a strict allowlist silently dropped every Commons-ingested work.
        if (mb_strlen($key) > 200 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return [null, null, null];
        }
        $artwork = \App\Models\Corpus\Topic::resilient(
            fn () => \App\Models\Corpus\Artwork::find($key)
        );
        if (! $artwork) {
            return [null, null, null];
        }

        $tagList = is_array($artwork->tags)
            ? $artwork->tags
            : array_filter(explode(',', trim((string) $artwork->tags, '{}')));

        return [$artwork->image_url, [
            'kind' => $artwork->kind,
            'qid' => $artwork->qid,
            'title' => $artwork->title,
            'creator' => $artwork->creator_name,
            'year' => $artwork->inception_year,
            'city' => $artwork->extra['city'] ?? null,
            'atlas' => $artwork->extra['atlas'] ?? null,
            'license' => 'public domain',
            'source' => 'Wikimedia Commons',
            'source_url' => $artwork->image_url,
            // A cover-fit into the 16:9 stage decapitates a centred sitter, so portraits anchor top.
            'focus' => PortraitFocus::forTags($tagList),
        ], preg_replace('/[^A-Za-z0-9_-]/', '-', $key)];
    }

    /** @return array{0:?string,1:?array,2:?string} */
    private function commonsPaintingPayload(string $fileTitle): array
    {
        $meta = app(\App\Services\CommonsImageService::class)->fileMeta($fileTitle);
        if (! $meta) {
            return [null, null, null];
        }

        return [$meta['image_url'], [
            'kind' => 'painting',
            'file_title' => $meta['file_title'],
            'title' => $meta['title'],
            'creator' => $meta['artist'],
            'license' => $meta['license'],
            'source' => 'Wikimedia Commons',
            'source_url' => $meta['file_page'],
            // Commons files carry no corpus tags, so the portrait test uses the title and the canvas
            // shape. Omitting this was the bug behind a teacher-picked Columbus portrait rendering
            // with his face cropped out of frame.
            'focus' => PortraitFocus::forImage(
                $meta['title'].' '.$meta['file_title'],
                $meta['width'] ?? null,
                $meta['height'] ?? null,
            ),
        ], 'commons-'.substr(md5($meta['file_title']), 0, 12)];
    }

    /**
     * Set the scene background from a pasted image URL — downloads it into lesson storage
     * (no student-runtime hotlinking) and records the source.
     */
    public function applyImageUrl(int $sceneId, string $url): void
    {
        $url = trim($url);
        $guard = app(SafeOutboundUrl::class);
        if (! $guard->isAllowed($url)) {
            $this->dispatch('toast', message: __('Enter a valid public image URL (http/https).'), type: 'warning');

            return;
        }
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'LearningPortal/1.0 (thelearningportal.us; lesson backgrounds)',
            ])
                // Image URLs redirect constantly (http→https, CDN shuffles), so redirects stay ON —
                // but every hop is re-checked, because validating only the URL the teacher typed let
                // a public host 302 the request into internal space after the guard had passed.
                ->withOptions(['allow_redirects' => $guard->redirectGuard()])
                ->timeout(30)->get($url);
            $type = (string) $response->header('Content-Type');
            if (! $response->successful() || ! str_starts_with($type, 'image/') || $response->body() === '') {
                throw new \RuntimeException('not an image ('.$type.')');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('We could not load that image. Check the link.'), type: 'error');

            return;
        }

        $ext = str_contains($type, 'png') ? 'png' : (str_contains($type, 'webp') ? 'webp' : 'jpg');
        $path = "lessons/{$this->lesson->id}/url/".substr(md5($url), 0, 12).".{$ext}";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $response->body());

        $scene->update([
            'image_path' => $path,
            // Keep any clipart the teacher attached when swapping to a pasted-URL background.
            'shots' => $this->shotsPreservingArtwork($scene, $path),
            'skybox_image_path' => null,
            'skybox_candidates' => null,
            'scene_view' => 'slideshow',
            'config' => array_merge($scene->config ?? [], [
                'background_source' => 'url',
                'background_credit' => ['kind' => 'url', 'source_url' => $url],
            ]),
            'status' => 'ready',
            'upscale_status' => null,
            'error_message' => null,
        ]);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
        $this->dispatch('toast', message: __('Image set as the scene background.'), type: 'success');
    }

    /**
     * Summarize the scene's narration into a short bullet list and drop it onto the slide
     * as a frosted-glass text card (auto-blurs the moving image behind it).
     */
    public function summarizeScriptToList(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $script = trim((string) $scene->script_segment);
        if ($script === '') {
            $this->dispatch('toast', message: __('This scene has no script to summarize yet.'), type: 'warning');

            return;
        }

        try {
            $result = app(\App\Services\OpenAiLlmService::class)->json(
                'You condense a lesson narration into an on-screen summary for students. '
                .'Return JSON {"points": ["…"]} with 3 to 5 very short bullet points, max ~8 words each, '
                .'plain text (no markdown, no leading bullets/numbers), in the SAME language as the narration.',
                $script,
            );
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('We could not summarise the script. Please try again.'), type: 'error');

            return;
        }

        $points = collect($result['points'] ?? [])
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->map(fn ($p) => mb_substr(trim(preg_replace('/^[\s\-\*•\d\.\)]+/u', '', $p)), 0, 120))
            ->filter()
            ->take(6)
            ->values()
            ->all();

        if ($points === []) {
            $this->dispatch('toast', message: __('The summary came back empty. Please try again.'), type: 'warning');

            return;
        }

        $texts = $scene->config['texts'] ?? [];
        // Backing panel — covers the left half, dark navy at 80% opacity.
        $texts[] = [
            'id' => uniqid('rect_'),
            'kind' => 'rect',
            'side' => 'left',
            'color' => '#0f172a',
            'opacity' => 0.8,
        ];
        // Bullet summary sits on top of the panel — a small left inset (~40px on a typical stage)
        // and size "M" (medium) so it stays readable without dominating the slide. All named sizes
        // are viewport-responsive (clamp() with a vw middle term) in TextOverlayLayer.
        $texts[] = [
            'id' => uniqid('txt_'),
            'text' => implode("\n", $points),
            'x' => 4,
            'y' => 18,
            'font' => 'sans',
            'size' => 'md',
            'list' => 'bullet',
            'bg' => 'none',
            'anchor' => 'screen',
        ];
        $scene->config = array_merge($scene->config ?? [], ['texts' => array_slice($texts, 0, 12)]);
        $scene->save();

        // Refresh the live overlay (and the inspector snapshot) so the card appears at once.
        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
        $this->dispatch('toast', message: __('Summary added to the slide.'), type: 'success');
    }

    public function regenerate(int $sceneId, string $asset): void
    {
        if ($this->guestDemoCannotGenerate()) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $updates = ['status' => 'generating', 'error_message' => null];
        if ($asset === 'image') {
            $config = $scene->config ?? [];
            unset($config['background_source'], $config['background_credit'], $config['background_focus'], $config['layout_reference']);
            $updates['config'] = $config;
        }
        $scene->update($updates);

        match ($asset) {
            'script' => GenerateSceneScript::dispatch($scene->id),
            // Multi-shot storyboard when enabled; the job itself falls back to the single image.
            'image' => \App\Jobs\GenerateSceneShots::dispatch($scene->id),
            'audio' => GenerateSceneAudio::dispatch($scene->id),
            'world' => $this->generateWorld($scene->id),
            default => null,
        };
    }

    public function generateWorld(int $sceneId): void
    {
        if ($this->guestDemoCannotGenerate()) {
            return;
        }

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

    public function addScene(string $kind = 'narration', ?string $gameType = null, $destLng = null, $destLat = null): void
    {
        $this->addSceneOpen = false;
        $isVoyageLesson = ($this->lesson->game_type ?? null) === 'voyage';
        // The itinerary is not its own kind — it is a route scene that stops nowhere, flagged with
        // `overview`. Adding it from the picker is the one place that distinction has a name.
        $wantsOverview = $kind === 'voyage-overview';
        if ($wantsOverview) {
            $kind = 'voyage';
        }
        $allowed = $isVoyageLesson ? ['game', 'map', 'voyage', 'gallery'] : ['game', 'map', 'gallery', 'branch'];
        $kind = in_array($kind, $allowed, true) ? $kind : 'narration';
        if ($wantsOverview && $kind === 'voyage') {
            $this->addVoyageOverviewScene();

            return;
        }

        // A branch is three scenes, not one, so it has its own builder.
        if ($kind === 'branch') {
            $this->addBranchGroup();

            return;
        }
        $gameType = in_array($gameType, ['quiz', 'strategy', 'debate'], true) ? $gameType : null;
        $insertAfterSceneId = $this->selectedSceneId;
        $next = ((int) $this->lesson->scenes()->max('order')) + 1;

        $payload = [
            'lesson_id' => $this->lesson->id,
            'order' => $next,
            'kind' => $kind,
            'image_style' => $this->lesson->image_style,
            // map/voyage/gallery generate nothing — their content is authored by hand — so they are
            // born ready. Creating them 'pending' would make them permanent publish blockers: the
            // publish gate demands every scene be ready, and there is no "generate" button to press.
            'status' => in_array($kind, ['map', 'voyage', 'gallery'], true) ? 'ready' : 'pending',
        ];

        if ($kind === 'voyage') {
            // Append a REAL new leg to the lesson's editable voyage copy: it sails from the previous
            // landfall (the last waypoint) to a NEW waypoint dropped just east of it, which the teacher
            // then drags to the real landfall. Dates continue from the previous leg. No generation.
            $gc = $this->ensureVoyageDef();
            $def = $gc['voyage_def'] ?? null;
            if (! $def || empty($def['waypoints'])) {
                return;   // no catalog geometry to extend
            }
            $wps = array_values($def['waypoints']);
            $legs = array_values($def['legs'] ?? []);
            $last = ! empty($legs) ? $legs[count($legs) - 1] : null;
            $startIdx = $last ? (int) ($last['wp'][1] ?? 0) : 0;
            $startWp = $wps[$startIdx] ?? [0.0, 20.0];
            // Drop the new landfall at the teacher's current map centre (they pan to roughly where the
            // leg should end); fall back to 12° east of the previous landfall when no centre is given.
            $hasDest = is_numeric($destLng) && is_numeric($destLat)
                && (float) $destLng >= -180 && (float) $destLng <= 180 && (float) $destLat >= -90 && (float) $destLat <= 90;
            $wps[] = $hasDest
                ? [round((float) $destLng, 3), round((float) $destLat, 3)]
                : [max(-179.0, min(179.0, (float) $startWp[0] + 12.0)), (float) $startWp[1]];
            $endIdx = count($wps) - 1;
            $depart = $last['arrive'] ?? '1500-01-01';
            $arrive = date('Y-m-d', strtotime($depart.' +14 days'));
            $legs[] = ['depart' => $depart, 'arrive' => $arrive, 'wp' => [$startIdx, $endIdx]];
            $def['waypoints'] = $wps;
            $def['legs'] = $legs;
            $gc['voyage_def'] = $def;
            $this->lesson->update(['game_config' => $gc]);
            $this->lesson->refresh();
            unset($this->voyageDef);

            $payload += [
                'location' => null,
                'config' => [
                    'voyage' => $gc['voyage'] ?? ($def['id'] ?? null),
                    'leg' => count($legs) - 1,
                    'view' => 'flat',
                    'intro' => false,
                    'stop_images' => [],
                    'gallery' => ['title' => '', 'date_label' => '', 'story' => '', 'images' => []],
                ],
            ];
            $scene = Scene::create($payload);
            $this->placeSceneAfterSelected($scene, $insertAfterSceneId);
            $this->selectSceneInternal($scene->id);

            return;
        }

        if ($kind === 'gallery') {
            $payload += [
                'scene_view' => 'slideshow',
                'config' => ['title' => '', 'date_label' => '', 'story' => '', 'fit' => 'cover', 'images' => []],
            ];
            $scene = Scene::create($payload);
            $this->placeSceneAfterSelected($scene, $insertAfterSceneId);
            $this->selectSceneInternal($scene->id);

            return;
        }

        if ($kind === 'map') {
            $payload += Scene::mapPayloadForLesson($this->lesson);
            $scene = Scene::create($payload);
            $this->placeSceneAfterSelected($scene, $insertAfterSceneId);
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

        $this->placeSceneAfterSelected($scene, $insertAfterSceneId);
        $this->selectSceneInternal($scene->id);
    }

    /**
     * Add a hand-authored branch: one question scene followed by two option scenes.
     *
     * Until now `BuildLessonOutline` was the ONLY code that ever wrote branch_group/branch_role, so
     * a branching story could not exist without a working LLM — and with the writing service out of
     * credit there was no way to make one at all. This is the same manual escape hatch map/gallery/
     * voyage scenes already have.
     *
     * The three scenes are plain narration scenes carrying branch metadata, which is exactly what
     * the generator produces, so the player, the game-pack PDF and the publish gate all read them
     * without knowing which route built them.
     */
    private function addBranchGroup(): void
    {
        $used = $this->lesson->scenes()
            ->whereNotNull('branch_group')
            ->pluck('branch_group')
            ->map(fn ($g) => (int) $g);

        $group = ($used->max() ?? 0) + 1;
        if ($group > self::MAX_BRANCH_GROUPS) {
            $this->dispatch('toast', type: 'error', message: __('A story can hold at most :n decision points.', ['n' => self::MAX_BRANCH_GROUPS]));

            return;
        }

        // The effects panel only opens for option scenes of a story_game, and the meters it edits
        // are lesson-level. Promote the lesson and seed meters here or the teacher would be left
        // with option scenes whose effects can never be set — and the publish gate demands every
        // option carry deltas, so the lesson would be permanently unpublishable.
        $this->lesson->fill([
            'include_game' => true,
            'game_type' => 'story_game',
            'narrative_framework' => 'branching',
        ]);
        if (($this->lesson->game_config['meters'] ?? []) === []) {
            $this->lesson->game_config = array_merge($this->lesson->game_config ?? [], [
                'meters' => self::DEFAULT_STORY_METERS,
            ]);
        }
        $this->lesson->save();
        $this->lesson->refresh();

        $insertAfterSceneId = $this->selectedSceneId;
        $next = ((int) $this->lesson->scenes()->max('order')) + 1;

        $rows = [
            ['role' => 'question', 'title' => __('Decision :n', ['n' => $group]), 'label' => null],
            ['role' => 'option_a', 'title' => __('Decision :n — first choice', ['n' => $group]), 'label' => __('First choice')],
            ['role' => 'option_b', 'title' => __('Decision :n — second choice', ['n' => $group]), 'label' => __('Second choice')],
        ];

        $created = [];
        foreach ($rows as $i => $row) {
            $created[] = Scene::create([
                'lesson_id' => $this->lesson->id,
                'order' => $next + $i,
                'kind' => 'narration',
                'title' => $row['title'],
                'image_style' => $this->lesson->image_style,
                'branch_group' => $group,
                'branch_role' => $row['role'],
                'branch_choice_label' => $row['label'],
                // Same as a hand-added Story scene: the teacher writes the script, then narrates.
                'status' => 'pending',
            ]);
        }

        // Keep the trio together, in order, after whatever was selected.
        $after = $insertAfterSceneId;
        foreach ($created as $scene) {
            $this->placeSceneAfterSelected($scene, $after);
            $after = $scene->id;
        }

        $this->selectSceneInternal($created[0]->id);
        $this->dispatch('toast', message: __('Decision point added. Write the question, then set what each choice changes.'));
    }

    private function placeSceneAfterSelected(Scene $scene, ?int $afterSceneId): void
    {
        if (! $afterSceneId) {
            return;
        }

        $sceneIds = $this->lesson->scenes()->ordered()->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $afterIndex = array_search($afterSceneId, $sceneIds, true);

        if ($afterIndex === false) {
            return;
        }

        $sceneIds = array_values(array_filter($sceneIds, fn (int $id) => $id !== $scene->id));
        array_splice($sceneIds, $afterIndex + 1, 0, [$scene->id]);

        DB::transaction(function () use ($sceneIds): void {
            foreach ($sceneIds as $index => $sceneId) {
                Scene::where('lesson_id', $this->lesson->id)
                    ->where('id', $sceneId)
                    ->update(['order' => -1 * ($index + 1)]);
            }
            foreach ($sceneIds as $index => $sceneId) {
                Scene::where('lesson_id', $this->lesson->id)
                    ->where('id', $sceneId)
                    ->update(['order' => $index + 1]);
            }
        });

        if ($scene->kind === 'game') {
            $this->syncGameSceneIndexes();
        }
    }

    public function deleteScene(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $wasGame = $scene->kind === 'game';
        $wasOrder = (int) $scene->order;

        // Park the row's order out of the way before soft-deleting it: (lesson_id, order) is unique,
        // and the renumbering below would otherwise collide with the scene we just took out.
        $scene->update(['order' => $this->parkingOrder()]);
        $scene->delete();
        $this->deletedScene = ['id' => $sceneId, 'order' => $wasOrder];

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

        // No confirm dialog on the way in — the way back out is the message itself, so it stays up
        // longer than a plain notification: the teacher has to read it and decide.
        $this->dispatch('toast', type: 'info', message: __('Scene deleted.'), duration: 9000, action: [
            'label' => __('Undo'),
            'event' => 'scene:undo-delete',
        ]);
    }

    /** An order no live scene holds, so a soft-deleted row cannot collide with the renumbering. */
    private function parkingOrder(): int
    {
        return (int) $this->lesson->scenes()->withTrashed()->max('order') + 1000;
    }

    /**
     * Cmd-Z / Ctrl-Z. The SERVER picks what to take back, newest kind of edit first, because the
     * browser cannot know: a delete and its undo can be a keystroke apart, and asking the page
     * "is a delete pending?" raced the response that would have told it.
     */
    public function undoLastEdit(): void
    {
        if ($this->deletedScene) {
            $this->undoSceneDelete();

            return;
        }

        $this->undoVoyage();
    }

    /**
     * Put the last deleted scene back where it was. Reached by Cmd-Z or the Undo in the toast — the
     * same path, so the shortcut and the link can never disagree.
     */
    public function undoSceneDelete(): void
    {
        $pending = $this->deletedScene;
        $this->deletedScene = null;
        if (! $pending) {
            return;
        }

        $scene = $this->lesson->scenes()->withTrashed()->find($pending['id']);
        if (! $scene || ! $scene->trashed()) {
            return;
        }

        $target = max(1, (int) $pending['order']);

        // Make room at `target` by pushing everything from there down, working from the END so each
        // move lands on a free slot — (lesson_id, order) is unique, so order matters.
        DB::transaction(function () use ($scene, $target) {
            $shift = $this->lesson->scenes()->ordered()->get()
                ->filter(fn (Scene $s) => (int) $s->order >= $target)
                ->reverse();

            foreach ($shift as $s) {
                $s->update(['order' => (int) $s->order + 1]);
            }

            $scene->restore();
            $scene->update(['order' => $target]);
        });

        if ($scene->kind === 'game') {
            $this->syncGameSceneIndexes();
        }

        $this->selectSceneInternal($scene->id);
        $this->dispatch('toast', type: 'success', message: __('Scene restored.'));
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

    /**
     * Play a music bed under the narration (or don't).
     *
     * There is one soundtrack (config/audio.php) and the player shuffles it, so the only decision
     * here is whether a class hears it at all. Off by default: most lessons run on a classroom
     * speaker, where music under a voice makes the narration harder to follow, not richer.
     */
    public function setBackgroundMusic(bool $on): void
    {
        $this->lesson->update(['background_music' => $on]);
        $this->lesson->refresh();
        $this->dispatch('toast', message: $on ? __('Background music is on for this lesson.') : __('Background music is off for this lesson.'), type: 'success');
    }

    /** Override the lesson poster — only a URL that's actually one of the lesson's own images. */
    public function selectPoster(string $url): void
    {
        $url = trim($url);
        $isOwn = collect($this->lesson->posterCandidates())->contains(fn ($c) => $c['url'] === $url);
        if ($url === '' || ! $isOwn) {
            return;
        }
        $this->lesson->update(['poster_image' => $url]);
        $this->lesson->refresh();
    }

    /** Clear the override → the lesson auto-picks its poster again. */
    public function resetPoster(): void
    {
        $this->lesson->update(['poster_image' => null]);
        $this->lesson->refresh();
    }

    #[On('open-lesson-settings')]
    public function openSettings(): void
    {
        $this->panelView = 'settings';
    }

    /**
     * Start this lesson with narration subtitles showing (or not).
     *
     * The teacher decides the starting state — a class that needs captions should not have to
     * hunt for the button once the lesson is already playing — and students can still toggle
     * them mid-lesson.
     */
    public function setSubtitles(bool $on): void
    {
        $this->lesson->update(['subtitles' => $on]);
        $this->lesson->refresh();
        $this->dispatch('toast', message: $on ? __('Subtitles are on for this lesson.') : __('Subtitles are off for this lesson.'), type: 'success');
    }

    #[On('open-lesson-format')]
    public function openFormat(): void
    {
        $this->panelView = 'scene';
    }

    /**
     * Inline script edit from the Script panel — persist the edited narration text.
     * Guards against data loss: a focusout can fire while the panel is being rebuilt on a
     * scene switch, dispatching empty or stale text. Never wipe a scene to empty, and never
     * apply a save whose originating scene is no longer the selected one.
     */
    #[On('scene:update-script')]
    public function updateSceneScript(string $text, ?int $sceneId = null): void
    {
        if (! $this->selectedScene || ! $this->selectedSceneId) {
            return;
        }
        if ($sceneId !== null && $sceneId !== $this->selectedSceneId) {
            return;   // stale save from a panel we already switched away from
        }
        if (trim($text) === '') {
            return;   // inline editing never clears all narration (delete the scene instead)
        }
        $this->selectedScene['script_segment'] = trim($text);
        $this->saveSelected();
    }

    /**
     * Re-narrate a scene's audio after its script was edited in the Script panel.
     * Flips the scene to "generating"; the status poll re-fires scene:load with the
     * fresh audio URL, and the Script panel reloads its waveform from that payload.
     */
    #[On('scene:renarrate')]
    public function renarrateScene(int $sceneId): void
    {
        $scene = $this->lesson->scenes()->find($sceneId);
        if (! $scene) {
            return;
        }
        $this->regenerate($sceneId, 'audio');
    }

    /**
     * Rewrite ONE focused script paragraph from a short teacher prompt (Script panel toolbar).
     * Stateless: returns the rewritten paragraph to the client via scene:paragraph-result — the
     * Script editor swaps it into that box and saves through the normal update-script path.
     */
    #[On('scene:regenerate-paragraph')]
    public function regenerateScriptParagraph(int $sceneId, string $text, string $prompt = ''): void
    {
        if ($sceneId !== $this->selectedSceneId) {
            return;   // stale request from a scene we already left
        }
        $text = trim($text);
        if ($text === '') {
            $this->dispatch('scene:paragraph-result', sceneId: $sceneId, text: null);

            return;
        }

        $instruction = trim($prompt) !== '' ? trim($prompt) : 'Improve the flow and clarity without changing the meaning.';

        try {
            $rewritten = app(\App\Services\OpenAiLlmService::class)->text(
                'You rewrite ONE paragraph of a lesson narration that is read aloud by text-to-speech. '
                .'Rules: spoken prose only — no markdown, headings, lists, quotes, or bracket tags; only use '
                .'facts already present in the paragraph (never invent); keep roughly the same length unless the '
                .'instruction says otherwise; reply in the SAME language as the paragraph. '
                .'Reply with ONLY the rewritten paragraph text, nothing else.',
                "Paragraph:\n".$text."\n\nInstruction: ".$instruction,
            );
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('We could not rewrite the paragraph. Please try again.'), type: 'error');
            $this->dispatch('scene:paragraph-result', sceneId: $sceneId, text: null);

            return;
        }

        // Guard against the model wrapping the answer in quotes / stray tags.
        $clean = trim((string) preg_replace('/\s*\n\s*/', ' ', strip_tags((string) $rewritten)));
        $clean = trim($clean, "\"'“”‘’ \t");

        $this->dispatch('scene:paragraph-result', sceneId: $sceneId, text: $clean !== '' ? $clean : null);
    }

    /** Script-panel entry point for the existing summarize-to-list; signals the toolbar to stop spinning. */
    #[On('scene:summarize-to-list')]
    public function summarizeToListFromScript(int $sceneId): void
    {
        if ($sceneId === $this->selectedSceneId) {
            $this->summarizeScriptToList($sceneId);
        }
        $this->dispatch('scene:summarize-done', sceneId: $sceneId);
    }

    /**
     * Name the asset a story scene is actually waiting on, or null when the status speaks for itself.
     *
     * A narration scene only becomes 'ready' once it has script + image + audio (MarksSceneReady),
     * so a hand-built scene with a written script and narration but no background image sits at
     * 'generating' forever. Reporting that as "still generating" tells the teacher to wait for
     * something that is never coming; naming the missing piece tells them what to click.
     */
    private function missingAssetReason(Scene $scene): ?string
    {
        if ($scene->kind !== 'narration' || $scene->status === 'failed') {
            return null;
        }

        $missing = [];
        if (trim((string) $scene->script_segment) === '') {
            $missing[] = __('a script');
        }
        if ($scene->image_path === null) {
            $missing[] = __('a background image');
        }
        if ($scene->audio_path === null) {
            $missing[] = __('narration audio');
        }

        if ($missing === []) {
            return null;   // nothing identifiable missing → let the raw status describe it
        }

        // Comma-separate all but the last ("a script, a background image and narration audio").
        // A space-padded `__(' and ')` key was both fragile for translators and gave the
        // three-item case "a script and a background image and narration audio".
        $last = array_pop($missing);
        $list = $missing === []
            ? $last
            : implode(', ', $missing).' '.__('and').' '.$last;

        return __('needs :list', ['list' => $list]);
    }

    /**
     * Publish the lesson from the toolbar. Gate: every scene must be "ready".
     * NOTE: public-visibility moderation (abuse/adult-content screening) is a
     * separate gate still to be built — see the lesson-publishing plan.
     */
    #[On('lesson:publish')]
    public function publish(): void
    {
        // A landing-page guest is editing a throwaway copy. Publishing it would put an anonymous
        // visitor's edits on the public catalogue, so the control is hidden from them and the
        // method refuses them.
        if (auth()->user()?->isGuestDemo()) {
            $this->publishOk = false;
            $this->publishNotice = __('Create an account to publish a lesson.');

            return;
        }

        $notReady = $this->lesson->scenes()
            ->where('status', '!=', 'ready')
            ->orderBy('order')
            ->get(['id', 'order', 'kind', 'game_type', 'status', 'script_segment', 'image_path', 'audio_path']);

        if ($notReady->isNotEmpty()) {
            // Name the offending scenes (and why) so the teacher knows exactly what to fix,
            // instead of a blanket "every scene must be ready".
            $labels = $notReady->map(function (Scene $s): string {
                $kind = $s->kind === 'game'
                    ? ucfirst((string) ($s->game_type ?: 'game'))
                    : ucfirst((string) $s->kind);
                $why = $this->missingAssetReason($s) ?? match ((string) $s->status) {
                    'generating' => __('still generating'),
                    'failed' => __('failed, regenerate it'),
                    'pending' => __('not generated yet'),
                    default => (string) $s->status,
                };

                return __('Scene :n (:kind): :why', ['n' => $s->order, 'kind' => $kind, 'why' => $why]);
            })->all();

            $this->publishOk = false;
            $this->publishNotice = __('These scenes aren\'t ready: :list. Generate or remove them, then publish.', [
                'list' => implode('; ', $labels),
            ]);

            return;
        }

        $this->lesson->update(['status' => LessonStatus::Published, 'scheduled_publish_at' => null]);
        $this->publishOk = true;
        $this->publishNotice = __('Lesson published.');
    }

    /** Schedule the lesson to auto-publish at a future time (lessons:publish-due does the flip). */
    public function schedulePublish(string $when): void
    {
        if (auth()->user()?->isGuestDemo()) {
            $this->publishOk = false;
            $this->publishNotice = __('Create an account to publish a lesson.');

            return;
        }

        if ($this->lesson->scenes()->where('status', '!=', 'ready')->exists()) {
            $this->publishOk = false;
            $this->publishNotice = __('All scenes must be ready before you can schedule publishing.');

            return;
        }
        try {
            $at = \Illuminate\Support\Carbon::parse($when);
        } catch (\Throwable) {
            $this->publishOk = false;
            $this->publishNotice = __('That date/time could not be read.');

            return;
        }
        if ($at->isPast()) {
            $this->publishOk = false;
            $this->publishNotice = __('Pick a time in the future.');

            return;
        }
        $this->lesson->update(['scheduled_publish_at' => $at]);
        $this->publishOk = true;
        $this->publishNotice = __('Scheduled to publish on :when.', ['when' => $at->isoFormat('D MMM YYYY, HH:mm')]);
    }

    /** Clear a pending publish schedule. */
    public function cancelSchedule(): void
    {
        $this->lesson->update(['scheduled_publish_at' => null]);
        $this->publishOk = true;
        $this->publishNotice = __('Publish schedule cleared.');
    }

    #[On('lesson:play')]
    public function continueToPreview(): void
    {
        $this->lesson->update(['wizard_step' => 5, 'status' => LessonStatus::Previewable]);
        // Carry the scene the teacher is on → the preview autoplays FROM here, not the first scene.
        $this->redirectRoute('teacher.lessons.wizard', array_filter([
            'lesson' => $this->lesson->id,
            'step' => 5,
            'scene' => $this->selectedSceneId,
        ]), navigate: true);
    }

    /** Called on every poll tick — pushes updated world status to the canvas. */
    public function pollWorldStatus(?Scene $scene): void
    {
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
    private function pollSceneReady(?Scene $scene): void
    {
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
        // Fetch the selected scene ONCE per tick and share it with both status pollers
        // (was two identical queries every 3s).
        $selected = $this->selectedSceneId ? $this->lesson->scenes()->find($this->selectedSceneId) : null;
        $this->pollWorldStatus($selected);
        $this->pollSceneReady($selected);

        return view('livewire.wizard.step3-scene-configurator');
    }
}
