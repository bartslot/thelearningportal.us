<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Livewire\TimeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class StoriesAtTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_click_inside_boundary_returns_region_era_articles(): void
    {
        // Boundary box around Rome (12..13 lng, 41..42 lat), valid 509 BCE..27 BCE.
        $this->seedBoundary(
            ['polity_id' => 'roman-republic', 'name' => 'Roman Republic',
             'valid_from' => -509, 'valid_to' => -27, 'extra' => ['region' => 'Mediterranean']],
            12.0, 41.0, 13.0, 42.0
        );
        $this->seedArticle(['title' => 'The Punic Wars', 'region' => 'Mediterranean', 'era_start' => -264, 'era_end' => -146]);
        $this->seedArticle(['title' => 'Cold War', 'region' => 'Mediterranean', 'era_start' => 1947, 'era_end' => 1991]);

        Livewire::test(TimeMap::class)
            ->call('storiesAt', 12.5, 41.5, -200)
            ->assertSet('selectedRegion', 'Mediterranean')
            ->assertSee('The Punic Wars')
            ->assertDontSee('Cold War');
    }

    public function test_click_outside_any_boundary_is_empty(): void
    {
        $this->seedBoundary(
            ['polity_id' => 'roman-republic', 'valid_from' => -509, 'valid_to' => -27],
            12.0, 41.0, 13.0, 42.0
        );

        Livewire::test(TimeMap::class)
            ->call('storiesAt', 100.0, 0.0, -200)
            ->assertSet('selectedRegion', null)
            ->assertSee('No stories here yet');
    }
}
