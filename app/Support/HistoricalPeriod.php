<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turn a curated period ("330–1453 CE", "550–330 BCE", "Roman era") into the two years the map can
 * filter on.
 *
 * The gazetteer writes these for humans, and the atlas drew all 53 of them at every year: Stalingrad
 * (1925–1961) beside Persepolis (550–330 BCE) and Bombay beside Vindobona, on one map. A class
 * reading that map is being told those names coexisted.
 *
 * Named eras are spelled out rather than guessed at, and anything unrecognised stays visible at
 * every year. Curated data that cannot be parsed is a gap in this table, not a reason to silently
 * drop a city off the map — the gap shows up as an anachronism a teacher can report, which is
 * recoverable; a missing Constantinople is not.
 */
final class HistoricalPeriod
{
    /** Rough spans for the era names the gazetteer actually uses. Negative years are BCE. */
    private const ERAS = [
        'antiquity' => [-3000, 500],
        'roman era' => [-27, 476],
        'roman gaul' => [-50, 486],
        'roman–moorish' => [-200, 1492],
        'silk road era' => [-130, 1450],
        'antiquity–timurid' => [-3000, 1507],
        'six dynasties' => [220, 589],
        'eastern zhou–han' => [-770, 220],
    ];

    /**
     * @return array{0: int, 1: int}|null  [from, to] in astronomical years, or null when unknown
     */
    public static function parse(?string $period): ?array
    {
        $text = trim(mb_strtolower((string) $period));
        if ($text === '') {
            return null;
        }

        if (isset(self::ERAS[$text])) {
            return self::ERAS[$text];
        }

        // "330–1453 CE", "550–330 BCE", "1925-1961 CE" — en dash or hyphen, era at the end. In a BCE
        // range both numbers are BCE and count DOWN (550 BCE is earlier than 330 BCE), so the pair is
        // negated and swapped rather than negated in place.
        if (preg_match('/(\d+)\s*[–-]\s*(\d+)\s*(bce|bc|ce|ad)?/u', $text, $m)) {
            $from = (int) $m[1];
            $to = (int) $m[2];
            $isBce = in_array($m[3] ?? '', ['bce', 'bc'], true);

            return $isBce ? [-$from, -$to] : [$from, $to];
        }

        // A single year: "founded 1189 CE" — treat it as "from then on".
        if (preg_match('/(\d+)\s*(bce|bc)?/u', $text, $m)) {
            $year = (int) $m[1];
            $isBce = in_array($m[2] ?? '', ['bce', 'bc'], true);

            return $isBce ? [-$year, -$year] : [$year, 2100];
        }

        return null;
    }
}
