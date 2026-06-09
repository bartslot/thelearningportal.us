<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Services\WikidataPolityResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichPolitiesTest extends TestCase
{
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

    public function test_resolver_returns_nulls_when_unresolved(): void
    {
        Http::fake(['www.wikidata.org/w/api.php*' => Http::response(['search' => []])]);

        $data = app(WikidataPolityResolver::class)->resolve('N. European Bronze Age cultures');

        $this->assertNull($data['wikidata_id']);
        $this->assertNull($data['summary']);
    }
}
