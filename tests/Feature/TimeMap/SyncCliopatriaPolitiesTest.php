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

    public function test_overridden_qid_enriches_from_the_corrected_entity(): void
    {
        $this->fakeWikidataByQid();

        $this->artisan('timemap:sync-cliopatria-polities --qid=Q207162')->assertExitCode(0);

        // The row stays keyed by the tile QID but is enriched from the Kingdom of France item —
        // never from Q207162, the Bourbon Restoration period item Cliopatria mistagged it with
        // (which put an 1815 restoration article on medieval France).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Special:EntityData/Q70972.json'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Special:EntityData/Q207162.json'));

        $row = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', 'Q207162')->first();
        $this->assertSame('Q70972', $row->wikidata_id);
        $this->assertSame('Kingdom of France', $row->label);   // Cliopatria era-name, not the target label
        $this->assertSame('Summary of Article Q70972', $row->summary);
        $this->assertSame(990, $row->inception);               // era = the Cliopatria span the tiles render by
        $this->assertSame(1847, $row->dissolution);
    }

    public function test_named_override_writes_a_standalone_row_for_the_carved_out_variant(): void
    {
        $this->fakeWikidataByQid();

        $this->artisan('timemap:sync-cliopatria-polities --qid=Q1068371')->assertExitCode(0);

        $corpus = DB::connection('pgsql_corpus');

        // Keeper row: Q1068371 genuinely is the Chauhan dynasty, so the list row keeps it.
        $keeper = $corpus->table('public.polities')->where('osm_id', 'Q1068371')->first();
        $this->assertSame('Q1068371', $keeper->wikidata_id);
        $this->assertSame('Chauhan Dynasty', $keeper->label);

        // Carved-out variant: the map rewrites Han Dynasty clicks to Q7209, so it gets its own
        // row with the polygon's own era from the override entry.
        $han = $corpus->table('public.polities')->where('osm_id', 'Q7209')->first();
        $this->assertNotNull($han);
        $this->assertSame('Han Dynasty', $han->label);
        $this->assertSame(-202, $han->inception);
        $this->assertSame(237, $han->dissolution);
    }

    /** Fake Wikidata/Wikipedia so every entity resolves to per-QID marker content (no flags). */
    private function fakeWikidataByQid(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (preg_match('~Special:EntityData/(Q\d+)\.json~', $url, $m)) {
                return Http::response(['entities' => [$m[1] => [
                    'claims' => [],
                    'labels' => ['en' => ['value' => "Label {$m[1]}"]],
                    'sitelinks' => ['enwiki' => ['title' => "Article {$m[1]}"]],
                ]]]);
            }
            if (str_contains($url, 'en.wikipedia.org')) {
                preg_match('~page/summary/(.+)$~', $url, $m);

                return Http::response(['extract' => 'Summary of '.rawurldecode($m[1] ?? ''), 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Test']]]);
            }

            return Http::response(['entities' => []]); // wbgetentities labels
        });
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
