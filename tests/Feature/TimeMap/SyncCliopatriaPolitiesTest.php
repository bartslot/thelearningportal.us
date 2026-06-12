<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class SyncCliopatriaPolitiesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_sync_enriches_a_polity_by_its_wikidata_qid(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'Special:EntityData/') && preg_match('/(Q\d+)\.json/', $url, $m)) {
                return Http::response(['entities' => [$m[1] => [
                    'claims' => ['P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag.svg']]]]],
                    'labels' => ['en' => ['value' => 'Wikidata Label']],
                    'sitelinks' => ['enwiki' => ['title' => 'Test Polity']],
                ]]]);
            }
            if (str_contains($url, 'en.wikipedia.org')) {
                return Http::response(['extract' => 'A test polity in Europe.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Test']]]);
            }
            if (str_contains($url, 'commons.wikimedia.org')) {
                return Http::response('PNG', 200, ['Content-Type' => 'image/png']);
            }

            return Http::response(['entities' => []]); // wbgetentities labels

        });

        // The committed list drives which QID is enriched; --limit=1 takes the first.
        $first = json_decode(File::get(database_path('data/cliopatria-polities.json')), true)[0];
        $qid = $first['qid'];

        $this->artisan('timemap:sync-cliopatria-polities --limit=1')->assertExitCode(0);

        $row = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', $qid)->first();
        $this->assertNotNull($row);
        $this->assertSame($qid, $row->wikidata_id);
        $this->assertSame($first['name'], $row->label); // clean Cliopatria name, not the Wikidata label
        $this->assertStringContainsString('test polity', strtolower($row->summary));

        @unlink(public_path("flags/{$qid}.png"));
    }
}
