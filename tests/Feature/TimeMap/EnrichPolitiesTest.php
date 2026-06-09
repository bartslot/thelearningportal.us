<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Services\WikidataPolityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class EnrichPolitiesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_resolver_normalizes_wikidata_and_wikipedia(): void
    {
        Http::fake([
            'www.wikidata.org/w/api.php*' => Http::response([
                'search' => [['id' => 'Q31', 'label' => 'Belgium']],
            ]),
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response([
                'entities' => ['Q31' => [
                    'claims' => [
                        'P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]],
                        'P571' => [['mainsnak' => ['datavalue' => ['value' => ['time' => '+1830-01-01T00:00:00Z']]]]],
                    ],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
        ]);

        $data = app(WikidataPolityResolver::class)->resolve('Kingdom of Belgium');

        $this->assertSame('Q31', $data['wikidata_id']);
        $this->assertSame(1830, $data['inception']);
        $this->assertStringContainsString('Western Europe', $data['summary']);
        $this->assertSame('https://en.wikipedia.org/wiki/Belgium', $data['wikipedia_url']);
        $this->assertSame('Flag of Belgium.svg', $data['flag_commons']);
    }

    public function test_resolver_resolves_predecessor_successor_to_labels(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'wbsearchentities')) {
                return Http::response(['search' => [['id' => 'Q31', 'label' => 'Belgium']]]);
            }
            if (str_contains($url, 'wbgetentities')) {
                return Http::response(['entities' => [
                    'Q29999' => ['labels' => ['en' => ['value' => 'United Kingdom of the Netherlands']]],
                ]]);
            }
            if (str_contains($url, 'EntityData/Q31')) {
                return Http::response(['entities' => ['Q31' => [
                    'claims' => ['P155' => [['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q29999']]]]]],
                    'sitelinks' => [],
                ]]]);
            }

            return Http::response([]);
        });

        $data = app(WikidataPolityResolver::class)->resolve('Kingdom of Belgium');

        // Predecessor comes back as a human label, not the raw QID.
        $this->assertSame('United Kingdom of the Netherlands', $data['predecessor']);
        $this->assertNull($data['successor']);
    }

    public function test_resolver_returns_nulls_when_unresolved(): void
    {
        Http::fake(['www.wikidata.org/w/api.php*' => Http::response(['search' => []])]);

        $data = app(WikidataPolityResolver::class)->resolve('N. European Bronze Age cultures');

        $this->assertNull($data['wikidata_id']);
        $this->assertNull($data['summary']);
    }

    public function test_command_enriches_each_boundary_polity(): void
    {
        $this->seedBoundary(
            ['polity_id' => 'kingdom-of-belgium', 'name' => 'Kingdom of Belgium', 'valid_from' => 1831, 'valid_to' => 3000],
            2.5, 49.5, 6.4, 51.5
        );
        $this->seedBoundary(
            ['polity_id' => 'n-european-bronze-age-cultures', 'name' => 'N. European Bronze Age cultures', 'valid_from' => -1500, 'valid_to' => -500],
            8.0, 54.0, 12.0, 58.0
        );

        Http::preventStrayRequests();
        Http::fake([
            'www.wikidata.org/w/api.php*' => Http::sequence()
                ->push(['search' => [['id' => 'Q31', 'label' => 'Belgium']]])
                ->push(['search' => []]),
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response([
                'entities' => ['Q31' => [
                    'claims' => ['P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]]],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium'], 'frwiki' => ['title' => 'Belgique']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
            'commons.wikimedia.org/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->artisan('timemap:enrich-polities')->assertExitCode(0);

        $belgium = DB::connection('pgsql_corpus')->table('public.polities')->where('polity_id', 'kingdom-of-belgium')->first();
        $this->assertSame('Q31', $belgium->wikidata_id);
        $this->assertStringContainsString('Western Europe', $belgium->summary);
        $this->assertSame('/flags/kingdom-of-belgium.png', $belgium->flag_path);
        $this->assertTrue((bool) $belgium->significant);

        $cultures = DB::connection('pgsql_corpus')->table('public.polities')->where('polity_id', 'n-european-bronze-age-cultures')->first();
        $this->assertNull($cultures->wikidata_id);
        $this->assertFalse((bool) $cultures->significant);

        @unlink(public_path('flags/kingdom-of-belgium.png'));
    }

    public function test_resolve_by_qid_returns_enrichment(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => \Illuminate\Support\Facades\Http::response([
                'entities' => ['Q31' => [
                    'claims' => [
                        'P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]],
                        'P571' => [['mainsnak' => ['datavalue' => ['value' => ['time' => '+1830-01-01T00:00:00Z']]]]],
                    ],
                    'labels' => ['en' => ['value' => 'Belgium']],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => \Illuminate\Support\Facades\Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
            'www.wikidata.org/w/api.php*' => \Illuminate\Support\Facades\Http::response(['entities' => []]),
        ]);

        $data = app(\App\Services\WikidataPolityResolver::class)->resolveByQid('Q31');

        $this->assertSame('Q31', $data['wikidata_id']);
        $this->assertSame('Belgium', $data['label']);
        $this->assertSame(1830, $data['inception']);
        $this->assertStringContainsString('Western Europe', $data['summary']);
        $this->assertSame('Flag of Belgium.svg', $data['flag_commons']);
    }
}
