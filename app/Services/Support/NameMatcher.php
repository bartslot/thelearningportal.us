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
}
