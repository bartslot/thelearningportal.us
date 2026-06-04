<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EraService;
use PHPUnit\Framework\TestCase;

class EraServiceTest extends TestCase
{
    private EraService $era;

    protected function setUp(): void
    {
        parent::setUp();
        $this->era = new EraService(currentYear: 2026);
    }

    public function test_years_ago_for_bce_adds_to_current_year(): void
    {
        $this->assertSame(3526, $this->era->yearsAgo(-1500));
    }

    public function test_years_ago_for_ce(): void
    {
        $this->assertSame(1026, $this->era->yearsAgo(1000));
    }

    public function test_generations_uses_25_year_default(): void
    {
        $this->assertSame(141, $this->era->generations(-1500));
    }

    public function test_nearest_stop_picks_closest_seeded_year(): void
    {
        $stops = [-2000, -1500, -500, 1, 500, 1000, 2010];
        $this->assertSame(-500, $this->era->nearestStop(-650, $stops));
        $this->assertSame(1, $this->era->nearestStop(-100, $stops));
        $this->assertSame(2010, $this->era->nearestStop(1990, $stops));
    }

    public function test_nearest_stop_rejects_empty_stops(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->era->nearestStop(-100, []);
    }

    public function test_bce_sorts_before_ce(): void
    {
        $years = [500, -44, 1, -1500];
        sort($years);
        $this->assertSame([-1500, -44, 1, 500], $years);
    }
}
