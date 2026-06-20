<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\NarrativeFramework;
use PHPUnit\Framework\TestCase;

class NarrativeFrameworkTest extends TestCase
{
    public function test_default_is_heros_journey(): void
    {
        $this->assertSame(NarrativeFramework::HerosJourney, NarrativeFramework::default());
        $this->assertSame('heros_journey', NarrativeFramework::HerosJourney->value);
    }

    public function test_only_heros_journey_needs_a_hero(): void
    {
        $this->assertTrue(NarrativeFramework::HerosJourney->needsHero());

        foreach ([
            NarrativeFramework::InMediasRes,
            NarrativeFramework::RiseAndFall,
            NarrativeFramework::Mystery,
            NarrativeFramework::Branching,
        ] as $framework) {
            $this->assertFalse($framework->needsHero(), $framework->value.' should not need a hero');
        }
    }

    public function test_default_game_pairing_matches_the_spec(): void
    {
        $this->assertSame('quiz', NarrativeFramework::HerosJourney->defaultGameType());
        $this->assertSame('quiz', NarrativeFramework::InMediasRes->defaultGameType());
        $this->assertSame('strategy', NarrativeFramework::RiseAndFall->defaultGameType());
        $this->assertSame('quiz', NarrativeFramework::Mystery->defaultGameType());
        $this->assertNull(NarrativeFramework::Branching->defaultGameType());
    }

    public function test_every_case_has_a_label_description_and_outline_icon(): void
    {
        foreach (NarrativeFramework::cases() as $framework) {
            $this->assertNotEmpty($framework->label());
            $this->assertNotEmpty($framework->description());
            $this->assertStringStartsWith('ti-', $framework->icon());
        }
    }

    public function test_is_backed_by_stable_string_values(): void
    {
        $this->assertSame(NarrativeFramework::Branching, NarrativeFramework::from('branching'));
        $this->assertCount(5, NarrativeFramework::cases());
    }
}
