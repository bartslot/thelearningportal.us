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
        if ($roster === []) {
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

        $prefixFallback = function () use ($roster, $needle): ?string {
            // Bare-first-name fallback: unique prefix match ("Daan" → "Daan K.").
            $prefixMatches = array_values(array_filter($roster, fn (string $entry) => str_starts_with(
                mb_strtolower(self::canonical($entry)), $needle.' ',
            )));

            return count($prefixMatches) === 1 ? $prefixMatches[0] : null;
        };

        if ($bestDistance > 2) {
            return $prefixFallback();
        }

        // Ambiguity guard: another entry within the same distance → give up, let the teacher pick.
        $ties = array_keys(array_filter($scored, fn (int $d) => $d === $bestDistance));
        if (count($ties) !== 1) {
            return null;
        }

        // Mis-attribution guard: a non-exact fuzzy match may only stand when the FIRST-NAME
        // tokens are equal or differ by an insertion/deletion (a genuine typo, e.g. Dan→Daan).
        // A same-length substitution (Kim↔Tim, Ava↔Ana) is a DIFFERENT child — reject it.
        if ($bestDistance > 0) {
            $needleFirst = self::firstNameToken($needle);
            $candidateFirst = self::firstNameToken(mb_strtolower(self::canonical($best)));

            if ($needleFirst !== $candidateFirst) {
                $shorter = mb_strlen($needleFirst) <= mb_strlen($candidateFirst) ? $needleFirst : $candidateFirst;
                $longer = $shorter === $needleFirst ? $candidateFirst : $needleFirst;

                if (! self::isSubsequence($shorter, $longer)) {
                    // Not a typo of the same name — safest to defer to the teacher.
                    return $prefixFallback();
                }
            }
        }

        return $best;
    }

    /** First-name token of a canonical name: everything before the last space, or the whole word. */
    private static function firstNameToken(string $canonical): string
    {
        $pos = mb_strrpos($canonical, ' ');

        return $pos === false ? $canonical : mb_substr($canonical, 0, $pos);
    }

    /** Classic two-pointer subsequence test: is every char of $short present, in order, in $long? */
    private static function isSubsequence(string $short, string $long): bool
    {
        $shortLen = mb_strlen($short);
        $longLen = mb_strlen($long);
        $i = 0;
        for ($j = 0; $i < $shortLen && $j < $longLen; $j++) {
            if (mb_substr($short, $i, 1) === mb_substr($long, $j, 1)) {
                $i++;
            }
        }

        return $i === $shortLen;
    }
}
