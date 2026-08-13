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

    /** The PNG signature, so a fixture standing in for Commons looks like what Commons returns. */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n";

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

    /**
     * A flag_path is a promise that a file is there. Commons does not always keep its half.
     *
     * The path used to be written from the presence of a Commons FILENAME, before the download was
     * attempted and regardless of what came back — so an error page, an empty body or a 404 still
     * left the column pointing at a PNG nobody wrote. 581 polities carried one; every information
     * card asked for it, got nothing, and drew a broken image.
     *
     * Commons answers 200 with an HTML error page often enough that the status alone proves
     * nothing, which is why this fakes a 200 rather than a failure.
     */
    public function test_a_flag_that_does_not_arrive_as_a_png_is_not_claimed(): void
    {
        $this->seedBoundary(
            ['polity_id' => 'kingdom-of-belgium', 'name' => 'Kingdom of Belgium', 'valid_from' => 1831, 'valid_to' => 3000],
            2.5, 49.5, 6.4, 51.5
        );

        Http::preventStrayRequests();
        Http::fake([
            'www.wikidata.org/w/api.php*' => Http::response(['search' => [['id' => 'Q31', 'label' => 'Belgium']]]),
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response([
                'entities' => ['Q31' => [
                    'claims' => ['P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]]],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
            // 200, but an error page rather than an image — exactly the shape that produced the 581.
            'commons.wikimedia.org/*' => Http::response('<!DOCTYPE html><html>Not found</html>', 200),
        ]);

        $this->artisan('timemap:enrich-polities')->assertExitCode(0);

        $belgium = DB::connection('pgsql_corpus')->table('public.polities')->where('polity_id', 'kingdom-of-belgium')->first();

        // The rest of the enrichment still lands — a missing flag is not a failed polity.
        $this->assertSame('Q31', $belgium->wikidata_id);
        $this->assertNull($belgium->flag_path, 'flag_path was claimed for a response that was not a PNG');
        $this->assertFileDoesNotExist(public_path('flags/kingdom-of-belgium.png'));
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
            // A real PNG signature, not the string 'PNGBYTES' it used to be. The command now checks
            // the bytes before it claims a flag_path, so a fixture that is not a PNG exercises the
            // rejection path instead of the happy one — see the test below, which does that on purpose.
            'commons.wikimedia.org/*' => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
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
