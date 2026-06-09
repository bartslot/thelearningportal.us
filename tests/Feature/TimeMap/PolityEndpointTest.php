<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class PolityEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_returns_enriched_polity_payload(): void
    {
        $this->seedPolity(['polity_id' => 'kingdom-of-belgium', 'label' => 'Kingdom of Belgium',
            'summary' => 'Belgium is a country in Western Europe.', 'flag_path' => '/flags/kingdom-of-belgium.png',
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/Belgium', 'inception' => 1831, 'dissolution' => null,
            'predecessor' => 'United Kingdom of the Netherlands', 'successor' => null]);

        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/kingdom-of-belgium')
            ->assertOk()
            ->assertJsonPath('label', 'Kingdom of Belgium')
            ->assertJsonPath('summary', 'Belgium is a country in Western Europe.')
            ->assertJsonPath('flag_path', '/flags/kingdom-of-belgium.png')
            ->assertJsonPath('inception', 1831);
    }

    public function test_unknown_polity_returns_minimal_payload(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/does-not-exist')
            ->assertOk()
            ->assertJsonPath('summary', null);
    }
}
