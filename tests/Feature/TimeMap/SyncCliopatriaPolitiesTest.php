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

    /** The command rewrites the committed snapshot; preserve it so tests never pollute the repo. */
    private ?string $snapshotBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
        $path = public_path('timemap/articles.json');
        $this->snapshotBackup = File::exists($path) ? File::get($path) : null;
    }

    protected function tearDown(): void
    {
        $path = public_path('timemap/articles.json');
        if ($this->snapshotBackup !== null) {
            File::put($path, $this->snapshotBackup);
        } elseif (File::exists($path)) {
            File::delete($path);
        }
        parent::tearDown();
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

        // Era = the Cliopatria span (what the tiles render by), NOT Wikidata P571/P576. The faked
        // entity carries no inception/dissolution, so these can only come from the committed span.
        $this->assertSame($first['from'], $row->inception);
        $this->assertSame($first['to'], $row->dissolution);

        @unlink(public_path("flags/{$qid}.png"));
    }

    public function test_dates_only_realigns_stale_eras_to_the_cliopatria_span(): void
    {
        Http::preventStrayRequests(); // --dates-only must never touch the network

        $first = json_decode(File::get(database_path('data/cliopatria-polities.json')), true)[0];
        $qid = $first['qid'];

        // A previously-enriched row carrying a stale (modern Wikidata) era — the bug shape.
        DB::connection('pgsql_corpus')->table('public.polities')->insert([
            'polity_id' => $qid, 'osm_id' => $qid, 'label' => $first['name'],
            'wikidata_id' => $qid, 'summary' => 'A test polity.', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Test',
            'inception' => 1861, 'dissolution' => 1946, 'sitelinks' => 1, 'significant' => true, 'updated_at' => now(),
        ]);

        $this->artisan('timemap:sync-cliopatria-polities --dates-only')->assertExitCode(0);

        $row = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', $qid)->first();
        $this->assertSame($first['from'], $row->inception);
        $this->assertSame($first['to'], $row->dissolution);

        // The rewritten snapshot reflects the corrected span too.
        $snapshot = json_decode(File::get(public_path('timemap/articles.json')), true);
        $this->assertSame($first['from'], $snapshot[$qid]['inception']);
        $this->assertSame($first['to'], $snapshot[$qid]['dissolution']);
    }
}
