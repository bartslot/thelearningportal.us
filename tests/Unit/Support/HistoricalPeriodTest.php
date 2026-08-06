<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HistoricalPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The atlas drew every historical name at every year — Stalingrad next to Persepolis — because the
 * curated period was prose the map could not read. These are the readings it has to get right.
 */
class HistoricalPeriodTest extends TestCase
{
    public function test_it_reads_a_ce_range(): void
    {
        $this->assertSame([330, 1453], HistoricalPeriod::parse('330–1453 CE'));
    }

    public function test_it_reads_a_bce_range_as_negative_years_running_forwards(): void
    {
        // 550 BCE is EARLIER than 330 BCE, so the pair negates and swaps rather than staying in place.
        $this->assertSame([-550, -330], HistoricalPeriod::parse('550–330 BCE'));
    }

    public function test_a_bce_range_ends_after_it_begins(): void
    {
        [$from, $to] = HistoricalPeriod::parse('550–330 BCE');

        $this->assertLessThan($to, $from);
    }

    public function test_it_accepts_a_plain_hyphen(): void
    {
        $this->assertSame([1925, 1961], HistoricalPeriod::parse('1925-1961 CE'));
    }

    public function test_it_knows_the_era_names_the_gazetteer_uses(): void
    {
        $this->assertSame([-27, 476], HistoricalPeriod::parse('Roman era'));
        $this->assertSame([-27, 476], HistoricalPeriod::parse('roman ERA'));   // case is the data's, not ours
        $this->assertNotNull(HistoricalPeriod::parse('Silk Road era'));
        $this->assertNotNull(HistoricalPeriod::parse('Six Dynasties'));
    }

    public function test_a_single_year_runs_from_then_on(): void
    {
        [$from, $to] = HistoricalPeriod::parse('founded 1189 CE');

        $this->assertSame(1189, $from);
        $this->assertGreaterThan(2000, $to);
    }

    public function test_an_unreadable_period_stays_unknown_rather_than_guessed(): void
    {
        // Unknown means "show at every year" upstream: a gap in the era table shows up as an
        // anachronism a teacher can report, where a wrongly-guessed range hides a real city.
        $this->assertNull(HistoricalPeriod::parse('sometime long ago'));
        $this->assertNull(HistoricalPeriod::parse(null));
        $this->assertNull(HistoricalPeriod::parse('  '));
    }
}
