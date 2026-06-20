<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\NarrativeFramework;
use App\Services\StorySpine;
use PHPUnit\Framework\TestCase;

class StorySpineTest extends TestCase
{
    public function test_heros_journey_spine(): void
    {
        $spine = StorySpine::for(NarrativeFramework::HerosJourney);

        $this->assertSame(['Before', 'Call', 'Trials', 'Crisis', 'Return'], $spine->beatTemplate);
        $this->assertSame('Crisis', $spine->gamePlacementBeat);
    }

    public function test_branching_places_the_game_at_the_choice(): void
    {
        $this->assertSame('Choice', StorySpine::for(NarrativeFramework::Branching)->gamePlacementBeat);
    }

    public function test_every_framework_has_five_beats_and_a_valid_game_placement(): void
    {
        foreach (NarrativeFramework::cases() as $framework) {
            $spine = StorySpine::for($framework);

            $this->assertCount(5, $spine->beatTemplate, $framework->value);
            $this->assertContains(
                $spine->gamePlacementBeat,
                $spine->beatTemplate,
                $framework->value.' game beat must be one of its beats',
            );
        }
    }

    public function test_abt_rule_states_the_but_therefore_causality(): void
    {
        $rule = StorySpine::for(NarrativeFramework::Mystery)->abtRule;

        $this->assertStringContainsStringIgnoringCase('but', $rule);
        $this->assertStringContainsStringIgnoringCase('therefore', $rule);
        $this->assertStringContainsStringIgnoringCase('never', $rule);
    }

    public function test_protagonist_clause_uses_the_name_or_is_empty(): void
    {
        $spine = StorySpine::for(NarrativeFramework::HerosJourney);

        $this->assertStringContainsString('Suleiman', $spine->protagonistClause('Suleiman the Magnificent'));
        $this->assertSame('', $spine->protagonistClause(null));
        $this->assertSame('', $spine->protagonistClause('   '));
    }
}
