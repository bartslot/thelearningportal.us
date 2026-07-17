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
use App\Livewire\Wizard\Concerns\EditsQuizQuestions;
use App\Livewire\Wizard\Concerns\EditsSceneArtwork;
use App\Livewire\Wizard\Concerns\EditsStoryGame;
use App\Models\AnimationClip;
use App\Models\City;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\StrategyGame;
use App\Support\PolityCapitals;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Step3SceneConfigurator extends Component
{
    use EditsQuizQuestions;
    use EditsSceneArtwork;
    use EditsStoryGame;

    private const EDITABLE_FIELDS = [
        'config',
        'year', 'location', 'script_segment', 'image_prompt', 'image_style',
        'animation_clip_id', 'duration_seconds',
        'game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game_id', 'team_count',
        'skybox_blur', 'skybox_opacity', 'background_color', 'kb_animated', 'kb_direction', 'scene_view',
        'world_y_offset', 'world_scale', 'world_char_scale',
    ];

    /** Brand deep-navy — the solid backdrop a scene falls back to when its image is removed. */
    public const BRAND_BACKGROUND = '#0f172a';

    public Lesson $lesson;

    public ?int $selectedSceneId = null;

    /** @var array<string,mixed>|null */
    public ?array $selectedScene = null;

    public bool $inspectorOpen = true;

    public bool $addSceneOpen = false;

    /** Right-panel view: 'scene' (per-scene editor) or 'settings' (lesson-global Story + Music). */
    public string $panelView = 'scene';

    /** Transient Publish feedback banner (no app-wide toast system exists yet). */
    public ?string $publishNotice = null;

    public bool $publishOk = false;

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
    public function games()
    {
        return StrategyGame::active()->orderBy('title')->get();
    }

    public function selectScene(int $id): void
    {
        $this->selectSceneInternal($id);
    }

    /**
     * URL-form shots for the preview JS (scene:load payload AND the inert
     * scenes JSON in the blade — the bridge falls back to the latter when the
     * initial scene:load dispatch is missed during hydration).
     * Mirrors the lesson player's serialization in resources/views/lesson/player.blade.php.
     */
    public function serializeShots(Scene $scene): array
    {
        $ts = $scene->updated_at?->timestamp ?? '';

        // Asset titles for the object-list labels ("Windmill silhouette", not "Clipart").
        $assetIds = collect($scene->shots ?? [])
            ->flatMap(fn ($shot) => collect($shot['layers'] ?? [])->pluck('asset_id'))
            ->filter()->unique()->values();
        $titles = $assetIds->isNotEmpty()
            ? \App\Models\SvgAsset::whereIn('id', $assetIds)->pluck('title', 'id')
            : collect();

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
                'wobble' => isset($l['wobble']) ? (int) $l['wobble'] : null,
                'z' => isset($l['z']) ? (int) $l['z'] : null,
                // Drawing-mode ink controls (per layer).
                'ink_preset' => $l['ink_preset'] ?? null,
                'ink_fill' => $l['ink_fill'] ?? null,
                'draw_time' => isset($l['draw_time']) ? (float) $l['draw_time'] : null,
            ])->filter(fn ($l) => $l['url'])->values()->all() ?: null,
        ])->filter(fn ($shot) => $shot['image_url'])->values()->all();
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
            'shots' => $this->serializeShots($scene),
            'hasSkyboxImage' => ! empty($scene->skybox_image_path),
            'audioUrl' => $scene->audio_path ? asset('storage/'.$scene->audio_path) : null,
            'animationClipId' => $scene->animation_clip_id,
            'animationClipUrl' => $this->animationGlbUrlFor($scene),
            'year' => $scene->year,
            'location' => $scene->location,
            // Editable scene identity: a per-scene title override + a hide flag.
            'identityTitle' => $scene->config['identity_title'] ?? null,
            'hideIdentity' => (bool) ($scene->config['hide_identity'] ?? false),
            'kind' => $scene->kind,
            'config' => $scene->config,
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
            || ($payload['background_color'] ?? null) !== ($scene->background_color ?? null);

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
    #[On('sceneTextsChanged')]
    public function saveSceneTexts(?int $sceneId, array $texts): void
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

    public function openPaintingPicker(): void
    {
        $this->paintingPickerOpen = true;
        $this->paintingCommonsLoaded = false;
        $this->paintingQuery = '';
        $this->paintingKind = '';
        $this->paintingRegion = '';
        $this->matchTarget = [];
        $this->paintingReady = false;   // modal opens instantly; wire:init fills the grid
        $this->searchOpen = false;
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

        $term = trim($this->paintingQuery);
        $topicQid = preg_match('/^(?:figure|polity|event):(Q\d+)$/', (string) $this->lesson->topic_id, $m) ? $m[1] : null;
        $eventQid = preg_match('/^event:(Q\d+)$/', (string) $this->lesson->topic_id, $m) ? $m[1] : null;
        $location = trim((string) ($this->selectedScene['location'] ?? ''));

        $kind = $this->paintingKind ?: null;
        $target = $this->matchTarget;
        $preferRegions = $this->regionPreference();
        $corpus = \App\Models\Corpus\Topic::resilient(function () use ($term, $topicQid, $location, $kind, $target, $preferRegions) {
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
        });

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
            'thumb' => $art->renditionUrl(800),
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
        if ($this->paintingCommonsLoaded && $this->paintingKind !== 'city_map' && $tiles->count() < self::PAINTING_GRID_LIMIT) {
            $commons = app(\App\Services\CommonsImageService::class);
            $live = collect();
            if ($term !== '') {
                $live = collect($commons->searchText($term.' painting'));
            } else {
                if ($topicQid) {
                    $live = collect($commons->searchDepicting($topicQid));
                }
                if ($location !== '') {
                    $live = $live->concat($commons->searchText($location.' painting'));
                }
            }
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

        return $tiles->unique(fn ($t) => mb_strtolower($t['title']))
            ->take(self::PAINTING_GRID_LIMIT)
            ->values();
    }

    public function applyPaintingBackground(string $source, string $key): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        [$imageUrl, $credit, $fileStem] = match ($source) {
            'corpus' => $this->corpusPaintingPayload($key),
            'commons' => $this->commonsPaintingPayload($key),
            default => [null, null, null],
        };
        if (! $imageUrl) {
            $this->dispatch('toast', message: __('Painting not found — try another one.'), type: 'warning');

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
            $this->dispatch('toast', message: __('Could not download the painting — try another one.'), type: 'error');

            return;
        }

        $ext = str_contains((string) $response->header('Content-Type'), 'png') ? 'png' : 'jpg';
        $path = "lessons/{$this->lesson->id}/paintings/{$fileStem}.{$ext}";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $response->body());

        $config = array_merge($scene->config ?? [], [
            'background_credit' => $credit,
            'background_focus' => $credit['focus'] ?? 'center',   // 'top' anchors portraits
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
            'scene_view' => 'slideshow', // paintings are flat images
            'config' => $config,
        ]);

        $this->paintingPickerOpen = false;
        $this->selectSceneInternal($scene->id);
    }

    /** @return array{0:?string,1:?array,2:?string} [imageUrl base, credit payload, storage file stem] */
    private function corpusPaintingPayload(string $key): array
    {
        // Wikidata QIDs (paintings) or synthetic ids like 'bh-12345' (city plans).
        if (! preg_match('/^[A-Za-z0-9:_-]{1,64}$/', $key)) {
            return [null, null, null];
        }
        $artwork = \App\Models\Corpus\Topic::resilient(
            fn () => \App\Models\Corpus\Artwork::find($key)
        );
        if (! $artwork) {
            return [null, null, null];
        }

        // Portraits are usually taller than 16:9 with the face near the top — anchor the
        // background crop to the top so a cover-fit never decapitates the sitter.
        $tagList = is_array($artwork->tags)
            ? $artwork->tags
            : array_filter(explode(',', trim((string) $artwork->tags, '{}')));
        $isPortrait = array_intersect(
            ['portrait', 'group-portrait', 'equestrian-portrait', 'self-portrait'],
            $tagList,
        ) !== [];

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
            'focus' => $isPortrait ? 'top' : 'center',
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
        ], 'commons-'.substr(md5($meta['file_title']), 0, 12)];
    }

    /**
     * Set the scene background from a pasted image URL — downloads it into lesson storage
     * (no student-runtime hotlinking) and records the source.
     */
    public function applyImageUrl(int $sceneId, string $url): void
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url) || ! $this->isSafePublicUrl($url)) {
            $this->dispatch('toast', message: __('Enter a valid public image URL (http/https).'), type: 'warning');

            return;
        }
        $scene = $this->lesson->scenes()->findOrFail($sceneId);

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'LearningPortal/1.0 (thelearningportal.us; lesson backgrounds)',
            ])->timeout(30)->get($url);
            $type = (string) $response->header('Content-Type');
            if (! $response->successful() || ! str_starts_with($type, 'image/') || $response->body() === '') {
                throw new \RuntimeException('not an image ('.$type.')');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('Could not load that image URL — check the link.'), type: 'error');

            return;
        }

        $ext = str_contains($type, 'png') ? 'png' : (str_contains($type, 'webp') ? 'webp' : 'jpg');
        $path = "lessons/{$this->lesson->id}/url/".substr(md5($url), 0, 12).".{$ext}";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $response->body());

        $scene->update([
            'image_path' => $path,
            // Keep any clipart the teacher attached when swapping to a pasted-URL background.
            'shots' => $this->shotsPreservingArtwork($scene, $path),
            'scene_view' => 'slideshow',
            'config' => array_merge($scene->config ?? [], [
                'background_credit' => ['kind' => 'url', 'source_url' => $url],
            ]),
        ]);

        if ($this->selectedSceneId === $sceneId) {
            $this->selectSceneInternal($sceneId);
        }
        $this->dispatch('toast', message: __('Image set as the scene background.'), type: 'success');
    }

    /** Reject obvious SSRF targets (loopback / private / link-local / metadata hosts). */
    private function isSafePublicUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }
        $host = strtolower(trim($host, '[]'));
        if (in_array($host, ['localhost', '0.0.0.0', '::1'], true)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
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
            $this->dispatch('toast', message: __('Could not summarize the script — please try again.'), type: 'error');

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
            $this->dispatch('toast', message: __('The summary came back empty — please try again.'), type: 'warning');

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
        // Bullet summary sits on top of the panel — inset from the left so its markers
        // clear the editor's scene rail while staying within the left-half panel.
        $texts[] = [
            'id' => uniqid('txt_'),
            'text' => implode("\n", $points),
            'x' => 13,
            'y' => 18,
            'font' => 'sans',
            'size' => 'lg',
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
        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        $scene->update(['status' => 'generating', 'error_message' => null]);

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

    #[On('open-lesson-settings')]
    public function openSettings(): void
    {
        $this->panelView = 'settings';
    }

    /**
     * Publish the lesson from the toolbar. Gate: every scene must be "ready".
     * NOTE: public-visibility moderation (abuse/adult-content screening) is a
     * separate gate still to be built — see the lesson-publishing plan.
     */
    #[On('lesson:publish')]
    public function publish(): void
    {
        if ($this->lesson->scenes()->where('status', '!=', 'ready')->exists()) {
            $this->publishOk = false;
            $this->publishNotice = __('Every scene must be ready before publishing.');

            return;
        }

        $this->lesson->update(['status' => LessonStatus::Published]);
        $this->publishOk = true;
        $this->publishNotice = __('Lesson published.');
    }

    #[On('lesson:play')]
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
