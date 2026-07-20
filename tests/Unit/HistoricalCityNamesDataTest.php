<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards database/data/historical_city_names.php — the curated source whose QIDs are
 * resolved to coordinates by cities:backfill-coords (Wikidata P625).
 *
 * A batch of these QIDs were once hallucinated wrong (Persepolis → St. Basil's Cathedral
 * in Moscow, Merv → the National Library of Australia, Fez → a Russian village), so the
 * backfill placed those historical labels thousands of km off — Fez/Persepolis/Babylon
 * rendered in central Russia on the lesson map. These tests pin the corrected QIDs and
 * enforce the structural invariants the seeder relies on.
 */
final class HistoricalCityNamesDataTest extends TestCase
{
    /** @return array<int, array{modern:string, historical:string, period:string, qid:string}> */
    private function rows(): array
    {
        return require dirname(__DIR__, 2).'/database/data/historical_city_names.php';
    }

    public function test_every_qid_is_well_formed_and_unique(): void
    {
        $qids = [];
        foreach ($this->rows() as $row) {
            $this->assertMatchesRegularExpression('/^Q[1-9]\d*$/', $row['qid'], "Malformed QID for {$row['modern']}");
            $qids[] = $row['qid'];
        }

        $this->assertSame(
            count($qids),
            count(array_unique($qids)),
            'Duplicate QIDs — two cities cannot share one Wikidata item (a common copy-paste corruption).'
        );
    }

    public function test_modern_city_names_are_unique(): void
    {
        // The seeder matches by `modern` name (ILIKE) — duplicates would double-fill one city.
        $modern = array_map(fn ($r) => mb_strtolower($r['modern']), $this->rows());
        $this->assertSame(count($modern), count(array_unique($modern)), 'Duplicate modern city names.');
    }

    /**
     * Pins the QIDs that were previously corrupt (hallucinated) to their verified-correct
     * Wikidata city items, so a bad revert or a re-generated data file is caught in CI.
     */
    public function test_previously_corrupt_cities_point_at_the_right_wikidata_items(): void
    {
        $expected = [
            'Hillah' => 'Q243846',   // was Q221413 (Putna monastery, Romania)
            'Shiraz' => 'Q6397066',  // was Q129846 (St. Basil's Cathedral, Moscow)
            'Bukhara' => 'Q5764',    // was Q5712 (Southwest Finland region)
            'Merv' => 'Q193325',     // was Q623578 (National Library of Australia)
            'Fez' => 'Q80985',       // was Q31525 (Seletskoye village, Russia)
            'Patna' => 'Q80484',     // was Q49159 (East Champaran District, not the city)
        ];

        $byModern = [];
        foreach ($this->rows() as $row) {
            $byModern[$row['modern']] = $row['qid'];
        }

        foreach ($expected as $modern => $qid) {
            $this->assertArrayHasKey($modern, $byModern, "{$modern} missing from the data file.");
            $this->assertSame($qid, $byModern[$modern], "{$modern} has a wrong/regressed QID.");
        }
    }
}
