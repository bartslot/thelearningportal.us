<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Services\Support\PhpSpecPrinter;

/**
 * Turn a lesson in the database back into an authored spec — the inverse of {@see LessonComposer}.
 *
 * `lessons:compose` only ever ran one way, so anything built or edited in the wizard lived in one
 * database and could not travel: production reads its own Postgres and never saw it. Exporting to
 * resources/lessons/*.php makes a lesson a committable file again, and composing it on the server
 * rebuilds it there.
 *
 * Two things make the round trip faithful rather than approximate:
 *
 *  - Images are exported as the exact resolved URL plus its credit, not as the search query that
 *    originally found them. Re-running a query is a fresh roll of the dice against Commons and the
 *    corpus; a teacher who picked a particular painting keeps that painting.
 *  - Config the composer does not itself write (wizard extras like `transition` or `overview`, and
 *    artwork layers in `shots`) is carried through verbatim in `extra_config` / `shots`, so a
 *    rebuild does not quietly delete work the wizard added.
 */
class LessonExporter
{
    public function __construct(
        private readonly PhpSpecPrinter $printer,
    ) {}

    /**
     * Config keys each scene kind's composer branch writes for itself. Everything else a scene
     * carries is wizard work, and rides along in `extra_config`.
     *
     * @var array<string,list<string>>
     */
    private const COMPOSED_CONFIG_KEYS = [
        'narration' => ['image_credit', 'image_source_url', 'background_focus', 'bg_embed'],
        'gallery' => ['title', 'date_label', 'story', 'fit', 'images'],
        'map' => ['qid', 'year', 'projection', 'map_style', 'playback_mode', 'annotations'],
        'voyage' => ['voyage', 'leg', 'view', 'intro', 'stop_images', 'gallery'],
        'game' => ['quiz_scope', 'quiz_shuffle', 'quiz_difficulty', 'hide_identity'],
    ];

    /** Editor scratch state, not content — undo history should never reach a spec file. */
    private const GAME_CONFIG_SCRATCH = ['voyage_undo'];

    /**
     * Scene images whose only known location is our own storage. Those cannot be re-fetched on
     * another machine, so the caller is told about them rather than being handed a broken spec.
     *
     * @var list<string>
     */
    private array $unresolvedImages = [];

    /** @return list<string> */
    public function unresolvedImages(): array
    {
        return $this->unresolvedImages;
    }

    /** @return array<string,mixed> */
    public function toSpec(Lesson $lesson): array
    {
        $this->unresolvedImages = [];

        $spec = [
            // `key` is what makes a rebuild update the same lesson instead of making a second one.
            'key' => (string) ($lesson->topic ?: $lesson->title),
            'title' => (string) $lesson->title,
            'subject' => (string) ($lesson->subject ?: 'history'),
            'grade_level' => (string) ($lesson->grade_level ?: '6'),
            'tone' => (string) ($lesson->tone ?: 'storytelling'),
            'map_style' => (string) ($lesson->map_style ?: 'antique'),
        ];

        if ((float) $lesson->map_relief !== 0.0) {
            $spec['map_relief'] = (float) $lesson->map_relief;
        }

        $gameConfig = $this->withoutKeys((array) ($lesson->game_config ?? []), self::GAME_CONFIG_SCRATCH);
        if ($gameConfig !== []) {
            // The voyage CMS edits a per-lesson copy of the route here. Without it a rebuilt voyage
            // silently reverts to the catalogue's default track.
            $spec['game_config'] = $gameConfig;
        }

        $questions = $lesson->quizQuestions()->orderBy('order')->get()->groupBy('scene_id');

        $spec['scenes'] = $lesson->scenes()
            ->orderBy('order')
            ->get()
            ->map(fn (Scene $scene) => $this->scene($scene, $questions->get($scene->id)?->all() ?? []))
            ->all();

        return $spec;
    }

    /** Render a spec as the contents of a resources/lessons/*.php file. */
    public function toPhp(array $spec, string $summary): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n"
            ."/** {$summary} */\n"
            .'return '.$this->printer->print($spec).";\n";
    }

    /**
     * @param  list<QuizQuestion>  $questions
     * @return array<string,mixed>
     */
    private function scene(Scene $scene, array $questions): array
    {
        $config = (array) ($scene->config ?? []);

        $spec = match (true) {
            $scene->kind === 'game' => $this->quiz($scene, $questions),
            $scene->kind === 'gallery' => $this->gallery($scene, $config),
            $scene->kind === 'map' => $this->map($scene, $config),
            $scene->kind === 'voyage' => $this->voyage($scene, $config),
            isset($config['bg_embed']) => $this->embed($scene, $config),
            default => $this->story($scene, $config),
        };

        $extra = $this->withoutKeys($config, self::COMPOSED_CONFIG_KEYS[$scene->kind] ?? []);
        if ($extra !== []) {
            $spec['extra_config'] = $extra;
        }

        $shots = (array) ($scene->shots ?? []);
        if ($shots !== []) {
            $spec['shots'] = $shots;
        }

        return $spec;
    }

    /** @return array<string,mixed> */
    private function story(Scene $scene, array $config): array
    {
        return array_filter([
            'type' => 'story',
            'chapter' => $scene->chapter_name,
            'location' => $scene->location,
            'year' => $this->year($scene),
            'image' => $this->image($scene, $config),
            'script' => $scene->script_segment,
        ], $this->keep(...));
    }

    /** @return array<string,mixed> */
    private function gallery(Scene $scene, array $config): array
    {
        return array_filter([
            'type' => 'gallery',
            'chapter' => $scene->chapter_name,
            'location' => $scene->location,
            'year' => $this->year($scene),
            'title' => $config['title'] ?? null,
            'date_label' => $config['date_label'] ?? null,
            'story' => $config['story'] ?? null,
            'fit' => $config['fit'] ?? null,
            'images' => $this->imageList((array) ($config['images'] ?? [])),
            'script' => $scene->script_segment,
        ], $this->keep(...));
    }

    /** @return array<string,mixed> */
    private function map(Scene $scene, array $config): array
    {
        $labels = [];
        foreach ((array) ($config['annotations'] ?? []) as $annotation) {
            $label = (string) ($annotation['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $row = [$label, (float) ($annotation['lng'] ?? 0), (float) ($annotation['lat'] ?? 0)];
            if (! empty($annotation['historical'])) {
                $row[] = $annotation['historical'];
            }
            $labels[] = $row;
        }

        return array_filter([
            'type' => 'map',
            'chapter' => $scene->chapter_name,
            'location' => $scene->location,
            'year' => $this->year($scene),
            'qid' => $config['qid'] ?? null,
            'projection' => $config['projection'] ?? null,
            'map_style' => $config['map_style'] ?? null,
            'script' => $scene->script_segment,
            'labels' => $labels,
        ], $this->keep(...));
    }

    /** @return array<string,mixed> */
    private function voyage(Scene $scene, array $config): array
    {
        $gallery = (array) ($config['gallery'] ?? []);

        return array_filter([
            'type' => 'voyage',
            'chapter' => $scene->chapter_name,
            'location' => $scene->location,
            'year' => $this->year($scene),
            'voyage' => $config['voyage'] ?? null,
            'leg' => isset($config['leg']) ? (int) $config['leg'] : null,
            'view' => $config['view'] ?? null,
            'intro' => ! empty($config['intro']) ? true : null,
            'title' => $gallery['title'] ?? null,
            'date_label' => $gallery['date_label'] ?? null,
            'story' => $gallery['story'] ?? null,
            'images' => $this->imageList((array) ($gallery['images'] ?? [])),
            'script' => $scene->script_segment,
        ], $this->keep(...));
    }

    /** @return array<string,mixed> */
    private function embed(Scene $scene, array $config): array
    {
        $embed = (array) $config['bg_embed'];
        $id = (string) ($embed['id'] ?? '');

        $spec = array_filter([
            'chapter' => $scene->chapter_name,
            'location' => $scene->location,
            'year' => $this->year($scene),
            'script' => $scene->script_segment,
        ], $this->keep(...));

        if (($embed['kind'] ?? '') === 'sketchfab') {
            // Both of these re-parse cleanly: EmbedParser pulls the id straight back out.
            return ['type' => '3d', 'sketchfab' => "https://sketchfab.com/models/{$id}/embed"] + $spec;
        }

        $source = match ($embed['provider'] ?? '') {
            'youtube' => "https://www.youtube.com/watch?v={$id}",
            'vimeo' => "https://vimeo.com/{$id}",
            default => (string) ($embed['embed_base'] ?? $embed['src'] ?? ''),
        };

        return ['type' => 'video', 'youtube' => $source] + $spec + array_filter([
            'fit' => $embed['fit'] ?? null,
            'controls' => $embed['controls'] ?? null,
            'autoplay' => array_key_exists('autoplay', $embed) ? (bool) $embed['autoplay'] : null,
            'start' => ! empty($embed['start']) ? (int) $embed['start'] : null,
            'end' => ! empty($embed['end']) ? (int) $embed['end'] : null,
        ], $this->keep(...));
    }

    /**
     * @param  list<QuizQuestion>  $questions
     * @return array<string,mixed>
     */
    private function quiz(Scene $scene, array $questions): array
    {
        return array_filter([
            'type' => 'quiz',
            'when' => $scene->quiz_timing === 'during' ? 'pre' : 'post',
            'chapter' => $scene->chapter_name,
            'location' => $scene->location,
            'questions' => array_map(fn (QuizQuestion $q) => array_filter([
                'q' => (string) $q->question,
                'o' => array_values((array) $q->options),
                'c' => (int) $q->correct_index,
                'e' => $q->explanation,
            ], $this->keep(...)), $questions),
        ], $this->keep(...));
    }

    /**
     * A story scene's background, as something another machine can fetch.
     *
     * @return array<string,string>|null
     */
    private function image(Scene $scene, array $config): ?array
    {
        $url = (string) ($config['image_source_url'] ?? '');

        if ($url === '') {
            if ($scene->image_path) {
                $this->unresolvedImages[] = "scene #{$scene->order}: {$scene->image_path}";
            }

            return null;
        }

        return array_filter([
            'url' => $url,
            'credit' => $config['image_credit'] ?? null,
            'focus' => $config['background_focus'] ?? null,
        ], $this->keep(...));
    }

    /**
     * @param  array<mixed>  $images
     * @return list<array<string,string>>
     */
    private function imageList(array $images): array
    {
        $out = [];
        foreach ($images as $image) {
            $url = (string) (is_array($image) ? ($image['url'] ?? '') : $image);
            if ($url === '') {
                continue;
            }
            $out[] = array_filter([
                'url' => $url,
                'credit' => is_array($image) ? ($image['credit'] ?? null) : null,
            ], $this->keep(...));
        }

        return $out;
    }

    /** Scene years are stored as strings; specs read better with real integers. */
    private function year(Scene $scene): int|string|null
    {
        $year = $scene->year;

        return is_string($year) && ctype_digit($year) ? (int) $year : $year;
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    private function withoutKeys(array $values, array $keys): array
    {
        return array_diff_key($values, array_flip($keys));
    }

    /** Drop nulls and empty strings/arrays, so a spec only states what the lesson actually sets. */
    private function keep(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
