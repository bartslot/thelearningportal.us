<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Str;

/**
 * Describes a lesson with the controlled vocabulary in config/lesson-taxonomy.php.
 *
 * Everything here is DERIVED — from the scenes a lesson has, the year it is set in, the words in
 * its title — rather than authored, so a lesson is filed correctly the moment it is created and
 * cannot drift out of date when it is edited. Feed the result to filters, shelves, search facets
 * and export packs; they all then agree on what a category means.
 *
 * @phpstan-type Facets array{
 *     subject:?string, period:?string, regions:list<string>, themes:list<string>,
 *     formats:list<string>, interactivity:string, grade_band:?string, tags:list<string>
 * }
 */
class LessonTaxonomy
{
    public function __construct(private readonly CanonThemeCatalog $canon) {}

    /**
     * Every facet of one lesson, as vocabulary keys.
     *
     * Eager-load `scenes` before calling this over a list — the format facet counts scene kinds.
     */
    public function facets(Lesson $lesson): array
    {
        $formats = $this->formats($lesson);

        return [
            'subject' => $this->subject($lesson),
            'period' => $this->period($lesson),
            'regions' => $this->regions($lesson),
            'themes' => array_values(array_filter([$this->theme($lesson)])),
            'formats' => $formats,
            'interactivity' => $this->interactivity($formats),
            'grade_band' => $this->gradeBand($lesson),
            'tags' => $this->tags($lesson),
        ];
    }

    /** The vertical. Only history ships today, so anything unrecognised falls to it. */
    public function subject(Lesson $lesson): ?string
    {
        $subject = Str::slug((string) $lesson->subject);

        return isset(config('lesson-taxonomy.subjects')[$subject]) ? $subject : 'history';
    }

    /**
     * The tijdvak a lesson sits in, from the first year mentioned in its era, title or topic.
     * Years are read as plain numbers ("1497", "the 1400s"); a BC year is negative.
     */
    public function period(Lesson $lesson): ?string
    {
        $year = $this->year($lesson);
        if ($year === null) {
            return null;
        }

        foreach (config('lesson-taxonomy.periods') as $key => $period) {
            if ($year >= $period['from'] && $year < $period['to']) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Where the lesson is set. A lesson can span regions (a voyage from Lisbon to India), so this
     * returns every match, most specific first; the stored `region` column is searched too.
     *
     * @return list<string>
     */
    public function regions(Lesson $lesson): array
    {
        $haystack = $this->haystack($lesson);
        $matched = [];

        foreach (config('lesson-taxonomy.regions') as $key => $region) {
            foreach ($region['keywords'] as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $matched[] = $key;
                    break;
                }
            }
        }

        return $matched;
    }

    /** The Canon theme, from the stored value or suggested from the lesson's own words. */
    public function theme(Lesson $lesson): ?string
    {
        $stored = (string) ($lesson->canon_theme ?? '');
        if ($stored !== '' && $this->canon->isValidTheme($stored)) {
            return $stored;
        }

        return $this->canon->suggest($lesson->topic, $lesson->details, $lesson->focus);
    }

    /**
     * What kind of lesson this is — the distinction a teacher scans for: is it a story, a voyage,
     * does it have a quiz, is it gamified. Read off the scenes themselves plus the game columns,
     * so it always describes what the lesson really contains.
     *
     * @return list<string>
     */
    public function formats(Lesson $lesson): array
    {
        $kinds = $this->scenes($lesson)->pluck('kind')->filter()->unique()->all();

        $formats = [];
        foreach (config('lesson-taxonomy.formats') as $key => $format) {
            if (array_intersect($format['scene_kinds'], $kinds) !== []) {
                $formats[] = $key;
            }
        }

        // A quiz can also live on the lesson rather than in a scene of its own.
        if (! in_array('quiz', $formats, true) && (int) $lesson->quiz_question_count > 0) {
            $formats[] = 'quiz';
        }

        // Gamified: a class game (story game, strategy) on top of the lesson itself. 'quiz' as a
        // game_type is already covered above and is not what a teacher means by a class game.
        $gameType = (string) ($lesson->game_type ?? '');
        if ($lesson->include_game && $gameType !== '' && $gameType !== 'quiz') {
            $formats[] = 'class-game';
        }

        return array_values(array_unique($formats));
    }

    /**
     * Watch, do, or both — schema.org's interactivityType.
     *
     * @param  list<string>  $formats
     */
    public function interactivity(array $formats): string
    {
        $doing = array_intersect(['quiz', 'class-game', 'voyage'], $formats) !== [];
        $watching = array_intersect(['narrated-story', 'gallery', 'atlas'], $formats) !== [];

        return match (true) {
            $doing && $watching => 'mixed',
            $doing => 'active',
            default => 'expositive',
        };
    }

    /**
     * Which band the free-text grade lands in. "6", "groep 6" and "grade 6" are the same class;
     * "Age 10" and "K-7" are not, so an explicit age is read as an age and everything else as a
     * grade. Ranges ("K-7") take the highest number, which is the class actually being taught.
     */
    public function gradeBand(Lesson $lesson): ?string
    {
        $raw = Str::lower(trim((string) $lesson->grade_level));
        if ($raw === '') {
            return null;
        }

        preg_match_all('/\d+/', $raw, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);
        if ($numbers === []) {
            return null;
        }

        $value = max($numbers);
        $isAge = str_contains($raw, 'age') || str_contains($raw, 'jaar') || str_contains($raw, 'leeftijd');
        $field = $isAge ? 'ages' : 'grades';

        foreach (config('lesson-taxonomy.grade_bands') as $key => $band) {
            if ($value >= $band[$field][0] && $value <= $band[$field][1]) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Free tags: the teacher's own focus tags, kept as slugs. These are deliberately NOT a
     * controlled vocabulary — they are the long tail a filter can offer once the facets above
     * have done the structural work.
     *
     * @return list<string>
     */
    public function tags(Lesson $lesson): array
    {
        $tags = $lesson->focus_tags;
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->filter(fn ($tag) => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag) => Str::slug($tag))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The first year the lesson mentions, negative for BC. Null when it names none.
     *
     * The scenes are consulted too: a voyage's title rarely carries a date ("Marco Polo"), but
     * every stop it sails through is stamped with one.
     */
    private function year(Lesson $lesson): ?int
    {
        $text = implode(' ', array_filter([$lesson->era, $lesson->title, $lesson->topic]));

        if (preg_match('/(\d{3,4})\s*(bc|bce|v\.?\s?chr)/i', $text, $m)) {
            return -((int) $m[1]);
        }

        if (preg_match('/\b(\d{3,4})\b/', $text, $m)) {
            return (int) $m[1];
        }

        // Earliest scene year — where the lesson STARTS, which is the period it belongs to.
        $sceneYears = $this->scenes($lesson)->pluck('year')->filter()->map(fn ($y) => (int) $y);

        return $sceneYears->isNotEmpty() ? $sceneYears->min() : null;
    }

    /**
     * Everything a lesson says about itself, lowercased, for keyword matching — including the
     * places its scenes are set in, which is the only place a voyage names Venice or Batavia.
     */
    private function haystack(Lesson $lesson): string
    {
        $sceneLocations = $this->scenes($lesson)->pluck('location')->filter()->implode(' ');

        return Str::lower(implode(' ', array_filter([
            $lesson->topic, $lesson->title, $lesson->region, $lesson->details, $lesson->focus,
            is_array($lesson->focus_tags) ? implode(' ', $lesson->focus_tags) : null,
            $sceneLocations,
        ])));
    }

    /**
     * The lesson's scenes, from the loaded relation when there is one, and fetched once per call
     * otherwise. Deliberately not a `distinct()->pluck()`: the relation carries a default ordering,
     * and Postgres rejects SELECT DISTINCT with an ORDER BY column that is not selected.
     */
    private function scenes(Lesson $lesson): \Illuminate\Support\Collection
    {
        if ($lesson->relationLoaded('scenes')) {
            return $lesson->scenes;
        }

        return $this->scenes[$lesson->id] ??= $lesson->scenes()->get();
    }

    /** @var array<int, \Illuminate\Support\Collection> */
    private array $scenes = [];
}
