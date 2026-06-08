<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Livewire\TimeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class StoriesForRegionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_region_returns_only_its_era_bracketed_articles(): void
    {
        // The map identifies the clicked polity client-side; the component just fetches articles.
        $this->seedArticle(['title' => 'The Punic Wars', 'region' => 'Mediterranean', 'era_start' => -264, 'era_end' => -146]);
        $this->seedArticle(['title' => 'Cold War', 'region' => 'Mediterranean', 'era_start' => 1947, 'era_end' => 1991]);
        $this->seedArticle(['title' => 'Han Dynasty', 'region' => 'East Asia', 'era_start' => -206, 'era_end' => 220]);

        Livewire::test(TimeMap::class)
            ->call('storiesForRegion', 'Mediterranean', 'Roman Republic', -200)
            ->assertSet('selectedRegion', 'Mediterranean')
            ->assertSet('selectedPolity', 'Roman Republic')
            ->assertSee('The Punic Wars')
            ->assertDontSee('Cold War')      // right region, wrong era
            ->assertDontSee('Han Dynasty');  // right era, wrong region
    }

    public function test_clicking_outside_any_region_clears_the_panel(): void
    {
        Livewire::test(TimeMap::class)
            ->call('storiesForRegion', null, null, -200)
            ->assertSet('selectedRegion', null)
            ->assertSee('No stories here yet');
    }
}
