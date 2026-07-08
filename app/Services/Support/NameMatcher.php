<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Roster name handling. Convention: first name + last-name initial ("Emma V.") —
 * disambiguates duplicate first names while staying AVG/GDPR-friendly.
 */
final class NameMatcher
{
    /** "emma visser" / "Emma  V" / "EMMA V." → "Emma V." ; single names pass through. */
    public static function canonical(string $raw): string
    {
        $parts = preg_split('/\s+/', trim(strip_tags($raw))) ?: [];
        $parts = array_values(array_filter($parts));
        if ($parts === []) {
            return '';
        }
        $first = mb_convert_case(mb_strtolower($parts[0]), MB_CASE_TITLE);
        if (count($parts) === 1) {
            return $first;
        }
        $initial = mb_strtoupper(mb_substr(end($parts), 0, 1));

        return "{$first} {$initial}.";
    }

    /**
     * Find the roster entry for a handwritten name. Normalizes both sides to
     * "first + initial", accepts Levenshtein ≤ 2 on the normalized form, and
     * refuses ambiguous matches (two roster entries equally close).
     *
     * @param  list<string>  $roster
     */
    public static function match(string $raw, array $roster): ?string
    {
        $needle = mb_strtolower(self::canonical($raw));
        if ($needle === '') {
            return null;
        }

        $scored = [];
        foreach ($roster as $entry) {
            $candidate = mb_strtolower(self::canonical($entry));
            $distance = levenshtein($needle, $candidate);
            // A bare first name may not silently claim "First X." — require the initial
            // unless exactly one roster entry starts with that first name.
            $scored[$entry] = $distance;
        }
        asort($scored);
        $best = array_key_first($scored);
        $bestDistance = $scored[$best];

        if ($bestDistance > 2) {
            // Bare-first-name fallback: unique prefix match ("Daan" → "Daan K.").
            $prefixMatches = array_values(array_filter($roster, fn (string $entry) => str_starts_with(
                mb_strtolower(self::canonical($entry)), $needle.' ',
            )));

            return count($prefixMatches) === 1 ? $prefixMatches[0] : null;
        }

        // Ambiguity guard: another entry within the same distance → give up, let the teacher pick.
        $ties = array_keys(array_filter($scored, fn (int $d) => $d === $bestDistance));

        return count($ties) === 1 ? $best : null;
    }
}
