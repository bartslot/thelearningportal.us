<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\BuildCliopatriaTiles;
use PHPUnit\Framework\TestCase;

/**
 * Cliopatria's polygons carry raster-to-vector noise — 5-point specks scattered across the map,
 * belonging to a polity that is nowhere near them. They are invisible at world scale and glaring on
 * the map, because the tiles stop at z4 and a speck over-zoomed becomes a hard block of colour in
 * the middle of another country.
 *
 * The rule that matters here is that it is an AND. Anyone tempted to simplify it to "drop small
 * parts" should read the microstate test: that is the case an absolute-only rule silently deletes.
 */
final class CliopatriaSpeckFilterTest extends TestCase
{
    /** A square of the given size in degrees, centred where asked. */
    private function square(float $cx, float $cy, float $size): array
    {
        $h = $size / 2;

        return [[
            [$cx - $h, $cy - $h], [$cx + $h, $cy - $h], [$cx + $h, $cy + $h], [$cx - $h, $cy + $h], [$cx - $h, $cy - $h],
        ]];
    }

    public function test_it_drops_specks_and_keeps_the_body_and_a_real_exclave(): void
    {
        // Measured from the real thing: Germany proper 81.4 deg², East Prussia 5.86, specks 0.02–0.07.
        $germany = $this->square(13.0, 51.1, 9.02);   // ≈ 81.4 deg²
        $eastPrussia = $this->square(21.2, 54.5, 2.42); // ≈ 5.86 deg²
        $specks = [
            $this->square(35.0, 62.5, 0.23),  // Karelia
            $this->square(25.5, 37.1, 0.19),  // the Aegean
            $this->square(35.9, 33.0, 0.16),  // Lebanon
            $this->square(0.0, 53.7, 0.15),   // the open Atlantic
        ];

        $kept = BuildCliopatriaTiles::withoutSpecks([$germany, $eastPrussia, ...$specks]);

        $this->assertCount(2, $kept, 'the specks should be gone and both real parts should remain');
        $this->assertSame($germany, $kept[0]);
        $this->assertSame($eastPrussia, $kept[1], 'East Prussia is a real exclave, not noise');
    }

    public function test_a_microstate_survives_because_it_is_its_own_largest_part(): void
    {
        // THE case an absolute-size rule deletes. The Vatican is ~0.00004 deg² — far smaller than
        // any speck — but it is the whole polity, so the relative half of the test never fires.
        $vatican = $this->square(12.45, 41.9, 0.0064);
        $stMartha = $this->square(12.44, 41.89, 0.002); // a second tiny parcel of the same state

        $kept = BuildCliopatriaTiles::withoutSpecks([$vatican, $stMartha]);

        $this->assertCount(2, $kept, 'a microstate must never be filtered away');
    }

    public function test_a_small_countrys_islands_are_not_specks(): void
    {
        // THE test that separates the two rules, and the reason the microstate test above is not
        // enough on its own: there, every part is tiny, so the "never empty a polity" guard hands
        // them all back and an absolute-only rule looks correct by accident.
        //
        // Here the body is 0.5 deg² and its islands 0.05 and 0.02 — all three under the 0.1
        // absolute threshold except the body. An absolute-only rule keeps 1 and throws the islands
        // away. The share test saves them: 10% and 4% of the body is nothing like 0.5%.
        $body = $this->square(23.7, 37.9, 0.707);   // ≈ 0.5 deg²
        $island = $this->square(25.0, 37.0, 0.224); // ≈ 0.05 deg²
        $islet = $this->square(26.0, 36.5, 0.141);  // ≈ 0.02 deg²

        $kept = BuildCliopatriaTiles::withoutSpecks([$body, $island, $islet]);

        $this->assertCount(3, $kept, 'an island that is a tenth of its country is territory, not noise');
    }

    public function test_it_leaves_single_part_geometry_alone(): void
    {
        $only = $this->square(0.0, 0.0, 0.001);

        $this->assertSame([$only], BuildCliopatriaTiles::withoutSpecks([$only]));
    }

    public function test_it_refuses_to_empty_a_polity(): void
    {
        // Degenerate input (every part identical and tiny) must not produce a feature with no
        // geometry at all — tippecanoe would take it, and the polity would vanish from the map.
        $a = $this->square(0.0, 0.0, 0.0001);

        $kept = BuildCliopatriaTiles::withoutSpecks([$a, $a, $a]);

        $this->assertNotEmpty($kept);
    }

    public function test_a_large_second_part_is_never_a_speck_however_many_there_are(): void
    {
        // Colonial holdings are a long way from the body and legitimately large. Distance is not
        // part of the rule precisely so that these survive.
        $italy = $this->square(12.5, 42.5, 6.0);
        $libya = $this->square(17.0, 27.0, 5.0);
        $eritrea = $this->square(38.0, 15.0, 2.0);

        $kept = BuildCliopatriaTiles::withoutSpecks([$italy, $libya, $eritrea]);

        $this->assertCount(3, $kept);
    }
}
