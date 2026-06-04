<?php

declare(strict_types=1);

namespace App\Services;

class EraService
{
    public function __construct(private ?int $currentYear = null)
    {
        $this->currentYear ??= (int) date('Y');
    }

    public function yearsAgo(int $year): int
    {
        return $this->currentYear - $year;
    }

    public function generations(int $year, int $yearsPerGeneration = 25): int
    {
        return intdiv($this->yearsAgo($year), $yearsPerGeneration);
    }

    public function nearestStop(int $year, array $stops): int
    {
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($stops as $stop) {
            $dist = abs($stop - $year);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $stop;
            }
        }

        return (int) $best;
    }
}
