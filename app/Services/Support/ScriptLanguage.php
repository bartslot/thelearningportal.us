<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Guess the language a narration script is actually written in.
 *
 * Narration used to pick its voice from the TEACHER'S UI locale, on the assumption that a Dutch
 * teacher writes Dutch lessons. That is often wrong: a Dutch teacher writing an English lesson about
 * the Ottoman Empire got the whole thing read aloud by a Dutch voice. The script itself is the only
 * honest signal, so we read it.
 *
 * Deliberately a stopword count, not a library: function words are the highest-signal, lowest-cost
 * discriminator between the five languages we ship, and this runs once per scene.
 */
final class ScriptLanguage
{
    /** Common function words per language, chosen to overlap as little as possible. */
    private const MARKERS = [
        'en' => ['the', 'and', 'of', 'to', 'was', 'that', 'with', 'for', 'they', 'he', 'she', 'his', 'her', 'it', 'in', 'on', 'but', 'were', 'had', 'this'],
        'nl' => ['de', 'het', 'een', 'en', 'van', 'die', 'dat', 'is', 'op', 'voor', 'met', 'zij', 'hij', 'werd', 'niet', 'maar', 'aan', 'werden', 'ook', 'naar'],
        'de' => ['der', 'die', 'das', 'und', 'von', 'ist', 'nicht', 'auf', 'mit', 'sie', 'er', 'wurde', 'für', 'aber', 'wurden', 'auch', 'einen', 'dem', 'des', 'im'],
        'fr' => ['le', 'la', 'les', 'et', 'de', 'des', 'du', 'que', 'qui', 'dans', 'pour', 'avec', 'était', 'elle', 'ils', 'mais', 'sur', 'une', 'ses', 'aux'],
        'it' => ['il', 'lo', 'la', 'gli', 'e', 'di', 'che', 'per', 'con', 'era', 'non', 'sono', 'nel', 'alla', 'ma', 'suo', 'una', 'dei', 'delle', 'più'],
    ];

    /** Below this many recognised words the sample is too short to call. */
    private const MIN_HITS = 4;

    /**
     * @param  string  $fallback  returned when the script is too short or ambiguous
     * @return string a locale code from MARKERS, or $fallback
     */
    public static function detect(?string $script, string $fallback = 'en'): string
    {
        $text = mb_strtolower(strip_tags((string) $script));
        // Keep letters (incl. accented) and spaces only, so punctuation cannot glue words together.
        $text = preg_replace('/[^\p{L}\s]+/u', ' ', $text) ?? '';
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 8) {
            return $fallback;
        }

        $counts = [];
        foreach (self::MARKERS as $locale => $markers) {
            $counts[$locale] = count(array_intersect($words, $markers));
        }

        arsort($counts);
        $best = array_key_first($counts);
        $bestScore = $counts[$best];

        if ($bestScore < self::MIN_HITS) {
            return $fallback;
        }

        // A near-tie means the markers did not really separate the languages (short or mixed text);
        // trust the caller's fallback rather than coin-flip a voice for a whole lesson.
        $second = array_slice($counts, 1, 1, true);
        $secondScore = $second ? reset($second) : 0;
        if ($bestScore - $secondScore < 2) {
            return $fallback;
        }

        return (string) $best;
    }
}
