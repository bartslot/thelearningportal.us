<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LessonStatus;
use App\Jobs\GenerateSceneAudio;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Build a complete, classroom-ready lesson from a declarative spec.
 *
 * The spec says WHAT the lesson contains; this service knows HOW the CMS stores it — so a lesson is
 * authored as data (resources/lessons/*.php) instead of hand-assembled scene rows. Every scene type
 * the platform supports is expressible:
 *
 *   quiz    — kind=game/quiz; `when: pre|post` decides order + scope (prior knowledge vs assessment)
 *   story   — narrated scene over a sourced historical image
 *   gallery — full-bleed story slide with a set of images
 *   map     — MapLibre scene with labelled pins (e.g. "Anne Frank House")
 *   3d      — narrated scene over a Sketchfab model background
 *   video   — narrated scene over a YouTube/Vimeo background
 *
 * Imagery is sourced from the paintings corpus + Wikimedia Commons (SceneImageSourcer), narration
 * from Azure TTS. Re-running rebuilds a lesson's scenes from scratch, so a spec edit is the unit of
 * change — never a hand-patched row.
 */
class LessonComposer
{
    public function __construct(
        private readonly SceneImageSourcer $images,
    ) {}

    /** Progress callback: fn(string $message): void */
    private $log = null;

    public function onProgress(callable $log): self
    {
        $this->log = $log;

        return $this;
    }

    private function say(string $message): void
    {
        if ($this->log) {
            ($this->log)($message);
        }
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    public function build(array $spec, User $teacher, bool $narrate = true): Lesson
    {
        $key = (string) ($spec['key'] ?? throw new \InvalidArgumentException('Spec needs a stable "key".'));

        $lesson = Lesson::withTrashed()->firstOrNew([
            'teacher_id' => $teacher->id,
            'topic' => $key,
        ]);

        $lesson->fill([
            'teacher_id' => $teacher->id,
            'title' => (string) ($spec['title'] ?? $key),
            'topic' => $key,
            'subject' => (string) ($spec['subject'] ?? 'history'),
            'grade_level' => (string) ($spec['grade_level'] ?? '6'),
            'tone' => (string) ($spec['tone'] ?? 'storytelling'),
            'map_style' => (string) ($spec['map_style'] ?? 'antique'),
            'source_mode' => 'internet',
            'include_game' => true,
            // A spec containing voyage legs must declare game_type=voyage — that is what makes the
            // player mount the animated route map instead of a plain slideshow.
            'game_type' => collect($spec['scenes'] ?? [])->contains(fn ($s) => ($s['type'] ?? '') === 'voyage')
                ? 'voyage'
                : 'quiz',
            'status' => LessonStatus::Draft,
        ]);
        if ($lesson->trashed()) {
            $lesson->restore();
        }
        $lesson->save();

        // The spec owns the structure — rebuild every scene (and its questions) on each run.
        $lesson->quizQuestions()->delete();
        $lesson->scenes()->forceDelete();

        $this->say("Building '{$lesson->title}' ({$lesson->lesson_code})…");

        $order = 0;
        $gameIndex = 0;
        foreach (($spec['scenes'] ?? []) as $sceneSpec) {
            $order++;
            $type = (string) ($sceneSpec['type'] ?? 'story');

            $scene = match ($type) {
                'quiz' => $this->quizScene($lesson, $sceneSpec, $order, ++$gameIndex),
                'gallery' => $this->galleryScene($lesson, $sceneSpec, $order),
                'map' => $this->mapScene($lesson, $sceneSpec, $order),
                'voyage' => $this->voyageScene($lesson, $sceneSpec, $order),
                '3d', 'video' => $this->embedScene($lesson, $sceneSpec, $order, $type),
                default => $this->storyScene($lesson, $sceneSpec, $order),
            };

            $this->say("  #{$order} {$type} — ".($scene->location ?: $scene->chapter_name ?: '…'));

            if ($narrate && $scene->script_segment) {
                $this->narrate($scene);
            }

            // Everything the spec produces is authored, not generated — so it is ready by definition.
            $scene->update(['status' => 'ready']);
        }

        $lesson->update([
            'game_split_count' => $lesson->scenes()->where('kind', 'game')->count(),
            'quiz_question_count' => 4,
        ]);

        $this->assignPoster($lesson, $spec);

        return $lesson->fresh();
    }

    /**
     * Narrate a scene with the configured TTS provider (Azure locally), tolerating failure.
     *
     * Rebuilding a lesson recreates its scenes, so the naive path re-synthesises every line even
     * when the script never changed. Audio is therefore cached by script hash under the LESSON
     * (not the scene) and copied back into place — editing one sentence in a spec then costs one
     * TTS call, not sixteen.
     */
    private function narrate(Scene $scene): void
    {
        $script = (string) $scene->script_segment;
        $hash = sha1($script);
        $cached = "lessons/{$scene->lesson_id}/narration-cache/{$hash}.mp3";
        $disk = Storage::disk('public');

        if ($disk->exists($cached)) {
            $path = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/narration.mp3";
            $disk->put($path, $disk->get($cached));
            $scene->update([
                'audio_path' => $path,
                'audio_script_hash' => $hash,
                'duration_seconds' => $this->estimateDuration($script),
            ]);
            $this->say('     ↳ narration reused from cache');

            return;
        }

        try {
            GenerateSceneAudio::dispatchSync($scene->id);
            $scene->refresh();
            if ($scene->audio_path && $disk->exists($scene->audio_path)) {
                $disk->put($cached, $disk->get($scene->audio_path));
            }
        } catch (Throwable $e) {
            $this->say('     ! narration failed: '.substr($e->getMessage(), 0, 90));
        }
    }

    /** Spoken length estimate (~2.6 words/sec) — mirrors GenerateSceneAudio's fallback. */
    private function estimateDuration(string $script): int
    {
        $words = max(1, str_word_count(strip_tags($script)));

        return (int) ceil(max(3.0, round($words / 2.6, 1)));
    }

    /**
     * Narrated scene over a sourced historical image.
     *
     * @param  array<string,mixed>  $s
     */
    private function storyScene(Lesson $lesson, array $s, int $order): Scene
    {
        $scene = $lesson->scenes()->create([
            'order' => $order,
            'kind' => 'narration',
            'scene_view' => 'slideshow',
            'location' => $s['location'] ?? null,
            'chapter_name' => $s['chapter'] ?? null,
            'year' => $s['year'] ?? null,
            'script_segment' => $s['script'] ?? null,
            'status' => 'pending',
            'kb_animated' => true,
            'config' => [],
        ]);

        if (! empty($s['image'])) {
            $this->attachBackground($scene, (string) $s['image'], $s['year'] ?? null, (string) ($s['prefer'] ?? 'auto'), $s['focus'] ?? null);
        }

        return $scene;
    }

    /**
     * Full-bleed story slide with a set of images (the platform's "gallery" scene).
     *
     * @param  array<string,mixed>  $s
     */
    private function galleryScene(Lesson $lesson, array $s, int $order): Scene
    {
        $images = [];
        foreach ((array) ($s['images'] ?? []) as $slot => $query) {
            $hit = $this->images->find((string) $query, $s['year'] ?? null, (string) ($s['prefer'] ?? 'auto'));
            if (! $hit) {
                $this->say("     ! no image for '{$query}'");

                continue;
            }
            $images[] = ['url' => $hit['url'], 'credit' => $hit['credit']];
        }

        return $lesson->scenes()->create([
            'order' => $order,
            'kind' => 'gallery',
            'scene_view' => 'slideshow',
            'location' => $s['location'] ?? null,
            'chapter_name' => $s['chapter'] ?? null,
            'year' => $s['year'] ?? null,
            'script_segment' => $s['script'] ?? null,
            'status' => 'pending',
            'config' => [
                'title' => (string) ($s['title'] ?? ''),
                'date_label' => (string) ($s['date_label'] ?? ''),
                'story' => (string) ($s['story'] ?? ''),
                'fit' => (string) ($s['fit'] ?? 'cover'),
                'images' => $images,
            ],
        ]);
    }

    /**
     * MapLibre scene. The camera is driven by the linked polity `qid` (there is no centre/zoom),
     * and each label becomes a 'focus' annotation pin.
     *
     * @param  array<string,mixed>  $s
     */
    private function mapScene(Lesson $lesson, array $s, int $order): Scene
    {
        $annotations = [];
        foreach ((array) ($s['labels'] ?? []) as $label) {
            // ['Anne Frank House', 4.884, 52.375] or ['label'=>…, 'lng'=>…, 'lat'=>…, 'historical'=>…]
            $text = (string) ($label['label'] ?? $label[0] ?? '');
            $lng = (float) ($label['lng'] ?? $label[1] ?? 0);
            $lat = (float) ($label['lat'] ?? $label[2] ?? 0);
            if ($text === '') {
                continue;
            }
            $annotations[] = [
                'type' => 'focus',
                'lng' => $lng,
                'lat' => $lat,
                'label' => $text,
                'historical' => $label['historical'] ?? $label[3] ?? null,
                // never true: the capital flag is auto-managed and would be wiped on territory edits
                'capital' => false,
            ];
        }

        return $lesson->scenes()->create([
            'order' => $order,
            'kind' => 'map',
            'location' => $s['location'] ?? null,
            'chapter_name' => $s['chapter'] ?? null,
            'year' => $s['year'] ?? null,
            'script_segment' => $s['script'] ?? null,
            'status' => 'ready',
            'config' => array_filter([
                'qid' => $s['qid'] ?? null,             // the ONLY camera control
                'year' => $s['year'] ?? null,
                'projection' => (string) ($s['projection'] ?? 'mercator'),
                'map_style' => $s['map_style'] ?? null,
                'playback_mode' => 'interactive',
                'annotations' => $annotations,
            ], fn ($v) => $v !== null && $v !== []),
        ]);
    }

    /**
     * One leg of an animated voyage: the ship sails the catalogue route to this landfall, where a
     * gallery of period imagery opens. The lesson must carry `game_type = voyage` for the player to
     * mount the route map, which `build()` sets when a spec declares a `voyage`.
     *
     * @param  array<string,mixed>  $s
     */
    private function voyageScene(Lesson $lesson, array $s, int $order): Scene
    {
        $images = [];
        foreach ((array) ($s['images'] ?? []) as $query) {
            $hit = $this->images->find((string) $query, $s['year'] ?? null, (string) ($s['prefer'] ?? 'auto'));
            if ($hit) {
                $images[] = ['url' => $hit['url'], 'credit' => $hit['credit']];
            }
        }

        return $lesson->scenes()->create([
            'order' => $order,
            'kind' => 'voyage',
            'status' => 'ready',
            'location' => $s['location'] ?? null,
            'chapter_name' => $s['chapter'] ?? null,
            'year' => $s['year'] ?? null,
            'script_segment' => $s['script'] ?? null,
            'config' => [
                'voyage' => (string) ($s['voyage'] ?? ''),
                'leg' => (int) ($s['leg'] ?? 0),
                'view' => (string) ($s['view'] ?? 'flat'),
                'intro' => (bool) ($s['intro'] ?? false),
                // The first few gallery images double as thumbnails pinned to the landfall.
                'stop_images' => array_slice(array_column($images, 'url'), 0, 3),
                'gallery' => [
                    'title' => (string) ($s['title'] ?? ($s['location'] ?? '')),
                    'date_label' => (string) ($s['date_label'] ?? ''),
                    'story' => (string) ($s['story'] ?? ''),
                    'images' => $images,
                ],
            ],
        ]);
    }

    /**
     * Narrated scene whose background is a Sketchfab model or a video, using the same
     * EmbedParser the teacher-facing paste box uses.
     *
     * @param  array<string,mixed>  $s
     */
    private function embedScene(Lesson $lesson, array $s, int $order, string $type): Scene
    {
        $parser = app(EmbedParser::class);
        $embed = null;

        if ($type === '3d' && ! empty($s['sketchfab'])) {
            $p = $parser->sketchfab((string) $s['sketchfab']);
            if ($p) {
                // Clean, autospinning, no chrome — it reads as a background, not a widget.
                $p['src'] = $p['src'].'?'.http_build_query([
                    'autostart' => 1, 'autospin' => 0.2, 'ui_controls' => 0, 'ui_infos' => 0,
                    'ui_watermark' => 0, 'ui_watermark_link' => 0, 'ui_hint' => 0, 'ui_ar' => 0,
                    'ui_help' => 0, 'ui_settings' => 0, 'ui_vr' => 0, 'ui_fullscreen' => 0,
                    'ui_annotations' => 0, 'ui_stop' => 0, 'scrollwheel' => 0, 'dnt' => 1,
                ]);
                $embed = $p;
            }
        }

        if ($type === 'video' && ! empty($s['youtube'])) {
            $p = $parser->video((string) $s['youtube']);
            if ($p) {
                $p['embed_base'] = $p['src'];
                $p += [
                    'autoplay' => (bool) ($s['autoplay'] ?? true),
                    'start' => (int) ($s['start'] ?? 0),
                    'end' => (int) ($s['end'] ?? 0),
                    'fit' => (string) ($s['fit'] ?? 'cover'),
                    'controls' => (bool) ($s['controls'] ?? false),
                ];
                $p['src'] = $parser->embedVideoSrc($p, $p);
                $embed = $p;
            }
        }

        if ($embed === null) {
            $this->say('     ! embed could not be parsed — falling back to a plain story scene');

            return $this->storyScene($lesson, $s, $order);
        }

        return $lesson->scenes()->create([
            'order' => $order,
            'kind' => 'narration',
            'scene_view' => 'embed',
            'location' => $s['location'] ?? null,
            'chapter_name' => $s['chapter'] ?? null,
            'year' => $s['year'] ?? null,
            'script_segment' => $s['script'] ?? null,
            'status' => 'pending',
            'background_color' => '#000000',
            'config' => ['bg_embed' => $embed],
        ]);
    }

    /**
     * Quiz scene + its questions. `when: pre` asks for prior knowledge (scope 'full', asks_ahead so
     * wrong answers stay encouraging); `when: post` assesses what was taught.
     *
     * @param  array<string,mixed>  $s
     */
    private function quizScene(Lesson $lesson, array $s, int $order, int $gameIndex): Scene
    {
        $isPre = (string) ($s['when'] ?? 'post') === 'pre';
        $questions = (array) ($s['questions'] ?? []);

        $scene = $lesson->scenes()->create([
            'order' => $order,
            'kind' => 'game',
            'game_type' => 'quiz',
            'game_segment_index' => $gameIndex,
            'quiz_question_count' => count($questions),
            'quiz_timing' => $isPre ? 'during' : 'after',
            'duration_seconds' => 180,
            'background_color' => '#0f172a',
            'chapter_name' => $s['chapter'] ?? ($isPre ? 'What do you already know?' : 'What did you learn?'),
            'location' => $s['location'] ?? null,
            'status' => 'ready',
            'config' => [
                'quiz_scope' => $isPre ? 'full' : 'taught',
                'quiz_shuffle' => 'per_player',
                'quiz_difficulty' => $isPre ? 1 : 2,
                'hide_identity' => true,
            ],
        ]);

        foreach (array_values($questions) as $i => $q) {
            $options = array_values((array) ($q['o'] ?? []));
            if (count($options) < 2) {
                continue;
            }
            $lesson->quizQuestions()->create([
                'scene_id' => $scene->id,     // scene-scoped, so the two quizzes never share a pool
                'order' => $i,
                'question' => (string) ($q['q'] ?? ''),
                'options' => $options,
                'correct_index' => (int) ($q['c'] ?? 0),
                'asks_ahead' => $isPre,
                'explanation' => $q['e'] ?? null,
                'points' => 10,
            ]);
        }

        return $scene;
    }

    /** Source, download and attach a scene background image. */
    private function attachBackground(Scene $scene, string $query, ?int $year, string $prefer, ?string $focus = null): void
    {
        $hit = $this->images->find($query, $year, $prefer);
        if (! $hit) {
            $this->say("     ! no image for '{$query}'");

            return;
        }

        $path = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/bg.jpg";
        if ($this->images->download($hit['url'], $path) === null) {
            $this->say("     ! download failed for '{$query}'");

            return;
        }

        $config = $scene->config ?? [];
        $config['image_credit'] = $hit['credit'];
        // Remember where it came from so `lessons:enhance-images` can re-fetch a bigger original.
        $config['image_source_url'] = $hit['url'];
        // Crop anchor. A portrait cropped to 16:9 from the centre loses the face — the sourcer
        // flags those, and a spec can still override with an explicit `focus`.
        $config['background_focus'] = (string) ($focus ?? $hit['focus'] ?? 'center');

        $scene->update(['image_path' => $path, 'config' => $config]);
    }

    /** Give the lesson a cover image (first gallery/story image that resolved). */
    private function assignPoster(Lesson $lesson, array $spec): void
    {
        if (! empty($lesson->poster_image)) {
            return;
        }
        $first = $lesson->scenes()->whereNotNull('image_path')->orderBy('order')->first();
        if ($first) {
            $lesson->update(['poster_image' => $first->image_path]);
        }
    }

    /** Publish when every scene is ready (the same gate the wizard's Publish button applies). */
    public function publish(Lesson $lesson): bool
    {
        $notReady = $lesson->scenes()->where('status', '!=', 'ready')->count();
        if ($notReady > 0) {
            $this->say("  ! {$notReady} scene(s) not ready — leaving unpublished");

            return false;
        }
        $lesson->update(['status' => LessonStatus::Published, 'scheduled_publish_at' => null]);

        return true;
    }
}
