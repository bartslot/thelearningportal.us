<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\LessonGoalContextResolver;
use PHPUnit\Framework\TestCase;

class LessonGoalContextResolverTest extends TestCase
{
    public function test_numeric_eighty_years_war_alias_resolves_to_dutch_historical_context(): void
    {
        $context = (new LessonGoalContextResolver)->resolve(
            'the 80 years war',
            '80 years war',
        );

        $this->assertSame("The Eighty Years' War", $context['canonical_subject']);
        $this->assertSame('the Dutch revolt against Spanish rule', $context['description']);
        $this->assertSame('netherlands', $context['region']);
        $this->assertSame('the Netherlands', $context['region_label']);
        $this->assertSame('Opstand & Tachtigjarige Oorlog (1568–1648)', $context['era']);
        $this->assertSame(1568, $context['period_start']);
        $this->assertSame(1648, $context['period_end']);
    }

    public function test_dutch_alias_resolves_to_the_same_historical_context(): void
    {
        $context = (new LessonGoalContextResolver)->resolve(
            'Leerlingen begrijpen de Tachtigjarige Oorlog',
            null,
        );

        $this->assertSame("The Eighty Years' War", $context['canonical_subject']);
        $this->assertSame('netherlands', $context['region']);
        $this->assertSame(1568, $context['period_start']);
        $this->assertSame(1648, $context['period_end']);
    }
}
