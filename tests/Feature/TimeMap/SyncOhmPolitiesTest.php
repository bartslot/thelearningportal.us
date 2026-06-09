<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class SyncOhmPolitiesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_sync_creates_polities_keyed_by_osm_id_from_overpass(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'overpass-api.openhistoricalmap.org/*' => Http::response(['elements' => [
                ['type' => 'relation', 'id' => 12345, 'tags' => ['name' => 'Kingdom of Belgium', 'admin_level' => '2', 'wikidata' => 'Q31']],
                ['type' => 'relation', 'id' => 999, 'tags' => ['name' => 'No Wikidata Land', 'admin_level' => '4']],
            ]]),
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response(['entities' => ['Q31' => [
                'claims' => ['P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]]],
                'labels' => ['en' => ['value' => 'Belgium']], 'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
            ]]]),
            'en.wikipedia.org/*' => Http::response(['extract' => 'Belgium is in Western Europe.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']]]),
            'commons.wikimedia.org/*' => Http::response('PNG', 200, ['Content-Type' => 'image/png']),
            'www.wikidata.org/w/api.php*' => Http::response(['entities' => []]),
        ]);

        $this->artisan('timemap:sync-ohm-polities')->assertExitCode(0);

        $belgium = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', '-12345')->first();
        $this->assertNotNull($belgium);
        $this->assertSame('Q31', $belgium->wikidata_id);
        $this->assertStringContainsString('Western Europe', $belgium->summary);

        // No-wikidata feature is recorded (clickable) but unenriched.
        $other = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', '-999')->first();
        $this->assertNotNull($other);
        $this->assertNull($other->wikidata_id);

        @unlink(public_path('flags/-12345.png'));
    }
}
