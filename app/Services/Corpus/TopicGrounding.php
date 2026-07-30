<?php

declare(strict_types=1);

namespace App\Services\Corpus;

use App\Models\Corpus\Topic;
use App\Models\Lesson;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answer "what is this lesson actually about?" from our own catalog, before any model
 * is asked anything.
 *
 * A teacher who picks a topic from the picker hands us the exact Wikipedia article.
 * A teacher who types "de gouden eeuw" hands us a string, and the pipeline used to go
 * straight to a fuzzy Wikipedia search — which is where wrong-article lessons come
 * from. The catalog already holds the name, aliases, QID, era, region and summary for
 * ~2,000 events and every polity and figure, so matching there first is free, offline
 * and repeatable.
 *
 * Grounding is a bonus, never a blocker: the corpus lives on a remote pooler that
 * drops idle connections, so every failure path returns null and the caller carries on.
 */
final class TopicGrounding
{
    /** Below this, a fuzzy name match is too weak to overrule the teacher's own words. */
    private const MIN_CONFIDENCE = 0.82;

    /** How far outside a catalog entry's own dates a lesson era may sit before it's a different subject. */
    private const ERA_SLACK_YEARS = 120;

    public function forLesson(Lesson $lesson): ?GroundedTopic
    {
        $topic = trim((string) $lesson->topic);
        if ($topic === '') {
            return null;
        }

        return $this->resolve($topic, self::eraYear((string) ($lesson->era ?? '')));
    }

    public function resolve(string $topic, ?int $eraYear = null): ?GroundedTopic
    {
        $candidates = $this->candidates($topic);
        if ($candidates === []) {
            return null;
        }

        return self::pick($candidates, $topic, $eraYear);
    }

    /**
     * Query the catalog. Returns [] rather than throwing — an unreachable corpus must not
     * fail a lesson.
     *
     * @return array<int, Topic>
     */
    private function candidates(string $topic): array
    {
        try {
            return Topic::resilient(
                fn () => Topic::search($topic, 8)->get()->all()
            );
        } catch (Throwable $e) {
            Log::info('[TopicGrounding] catalog unavailable, continuing ungrounded: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Choose among catalog hits, or none of them.
     *
     * The catalog's own ranking is built for a human picking from a dropdown, where a
     * near-miss at the top is helpful. Here nothing is shown to anyone — a wrong pick
     * silently grounds the whole lesson in the wrong article — so the bar is a name the
     * teacher would recognise as the thing they typed.
     *
     * @param  array<int, Topic|object>  $candidates
     */
    public static function pick(array $candidates, string $topic, ?int $eraYear = null): ?GroundedTopic
    {
        $needle = self::normalise($topic);
        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $score = self::score($candidate, $needle);
            if ($score < self::MIN_CONFIDENCE || $score <= $bestScore) {
                continue;
            }
            if (! self::eraFits($candidate, $eraYear)) {
                continue;
            }

            $best = $candidate;
            $bestScore = $score;
        }

        if ($best === null) {
            return null;
        }

        return new GroundedTopic(
            id: (string) $best->id,
            type: (string) ($best->type ?? 'topic'),
            name: (string) $best->name,
            qid: filled($best->qid ?? null) ? (string) $best->qid : null,
            wikipediaUrl: filled($best->wikipedia_url ?? null) ? (string) $best->wikipedia_url : null,
            summary: filled($best->summary ?? null) ? (string) $best->summary : null,
            eraStart: isset($best->era_start) ? (int) $best->era_start : null,
            eraEnd: isset($best->era_end) ? (int) $best->era_end : null,
            regionLabel: filled($best->region_label ?? null) ? (string) $best->region_label : null,
            attribution: filled($best->source_attribution ?? null) ? (string) $best->source_attribution : null,
            confidence: round($bestScore, 3),
        );
    }

    /** 1.0 for an exact name or alias hit, otherwise a similarity ratio. */
    private static function score(object $candidate, string $needle): float
    {
        $name = self::normalise((string) ($candidate->name ?? ''));
        if ($name === '') {
            return 0.0;
        }
        if ($name === $needle) {
            return 1.0;
        }

        foreach (explode('|', (string) ($candidate->aliases ?? '')) as $alias) {
            if (self::normalise($alias) === $needle) {
                return 1.0;
            }
        }

        similar_text($name, $needle, $percent);

        return $percent / 100;
    }

    /**
     * A lesson set in 1600 is not about a polity that ended in 800. Only judged when both
     * sides know their dates — most figures and places carry none.
     */
    private static function eraFits(object $candidate, ?int $eraYear): bool
    {
        if ($eraYear === null) {
            return true;
        }

        $start = isset($candidate->era_start) ? (int) $candidate->era_start : null;
        $end = isset($candidate->era_end) ? (int) $candidate->era_end : null;
        if ($start === null && $end === null) {
            return true;
        }

        return $eraYear >= (($start ?? $end) - self::ERA_SLACK_YEARS)
            && $eraYear <= (($end ?? $start) + self::ERA_SLACK_YEARS);
    }

    /**
     * Lowercase, unaccent, drop punctuation and a leading article, collapse whitespace, so
     * "De Gouden Eeuw" and "gouden eeuw" are the same string.
     */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = strtolower($transliterated);
        }

        $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value) ?? $value;
        $value = preg_replace('/^(de|het|een|the|la|le|el|les|los|der|die|das)\s+/', '', trim($value)) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Pull a representative year out of a human era label — "Early modern period
     * (1500–1800)", "1642 – 1659", "17e eeuw". The midpoint of a range, else the year.
     */
    public static function eraYear(string $era): ?int
    {
        if (! preg_match_all('/-?\d{3,4}/', $era, $matches)) {
            return null;
        }

        $years = array_map('intval', $matches[0]);

        return (int) round((min($years) + max($years)) / 2);
    }
}
