<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->seedPolity(['polity_id' => '-12345', 'osm_id' => '-12345', 'label' => 'Kingdom of Belgium',
            'summary' => 'Belgium is a country in Western Europe.', 'flag_path' => '/flags/-12345.png',
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/Belgium', 'inception' => 1831, 'dissolution' => null,
            'predecessor' => 'United Kingdom of the Netherlands', 'successor' => null]);

        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/-12345')
            ->assertOk()
            ->assertJsonPath('label', 'Kingdom of Belgium')
            ->assertJsonPath('summary', 'Belgium is a country in Western Europe.')
            ->assertJsonPath('flag_path', '/flags/-12345.png')
            ->assertJsonPath('inception', 1831);
    }

    public function test_unknown_polity_without_name_returns_minimal_payload(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/-999')
            ->assertOk()
            ->assertJsonPath('summary', null);
    }

    public function test_unknown_osm_id_lazy_enriches_and_caches(): void
    {
        Http::fake([
            'www.wikidata.org/w/api.php*' => Http::response(['search' => [['id' => 'Q142', 'label' => 'France']]]),
            'www.wikidata.org/wiki/Special:EntityData/Q142.json' => Http::response(['entities' => ['Q142' => ['claims' => [], 'labels' => ['en' => ['value' => 'France']], 'sitelinks' => ['enwiki' => ['title' => 'France']]]]]),
            'en.wikipedia.org/*' => Http::response(['extract' => 'France is in Western Europe.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/France']]]),
        ]);

        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/-71?name='.urlencode('France'))
            ->assertOk()
            ->assertJsonPath('summary', 'France is in Western Europe.');

        $this->assertNotNull(DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', '-71')->first());
    }

    public function test_supplemental_marker_lazy_enriches_by_qid(): void
    {
        // A marker passes an explicit QID; enrichment must go through resolveByQid (no name search).
        Http::fake([
            'www.wikidata.org/wiki/Special:EntityData/Q38060.json' => Http::response(['entities' => ['Q38060' => ['claims' => [], 'labels' => ['en' => ['value' => 'Gaul']], 'sitelinks' => ['enwiki' => ['title' => 'Gaul']]]]]),
            'en.wikipedia.org/*' => Http::response(['extract' => 'Gaul was a region of Western Europe.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Gaul']]]),
            'www.wikidata.org/w/api.php*' => Http::response(['entities' => []]),
        ]);

        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/gaul?name=Gaul&qid=Q38060')
            ->assertOk()
            ->assertJsonPath('label', 'Gaul')
            ->assertJsonPath('summary', 'Gaul was a region of Western Europe.');

        $this->assertNotNull(DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', 'gaul')->first());
    }
}
