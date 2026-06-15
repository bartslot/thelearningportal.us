<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WikidataFiguresService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikidataFiguresServiceTest extends TestCase
{
    public function test_polity_figures_parses_region_and_reign_dated_rulers(): void
    {
        Http::fake([
            'www.wikidata.org/wiki/Special:EntityData/Q12544.json' => Http::response([
                'entities' => [
                    'Q12544' => [
                        'claims' => [
                            // coordinate location (region)
                            'P625' => [[
                                'mainsnak' => ['datavalue' => ['value' => [
                                    'latitude' => 41.9, 'longitude' => 12.5,
                                ]]],
                            ]],
                            // country (region label QID)
                            'P17' => [[
                                'mainsnak' => ['datavalue' => ['value' => ['id' => 'Q38']]],
                            ]],
                            // head of state (ruler) with reign qualifiers
                            'P35' => [[
                                'mainsnak' => ['datavalue' => ['value' => ['id' => 'Q1405']]],
                                'qualifiers' => [
                                    'P580' => [['datavalue' => ['value' => ['time' => '-0027-01-16T00:00:00Z']]]],
                                    'P582' => [['datavalue' => ['value' => ['time' => '+0014-08-19T00:00:00Z']]]],
                                ],
                            ]],
                        ],
                    ],
                ],
            ]),
        ]);

        $svc = new WikidataFiguresService;
        $data = $svc->polityFigures('Q12544');

        $this->assertSame(41.9, $data['region']['lat']);
        $this->assertSame(12.5, $data['region']['lng']);
        $this->assertSame('Q38', $data['region']['label']);

        $this->assertCount(1, $data['rulers']);
        $ruler = $data['rulers'][0];
        $this->assertSame('Q1405', $ruler['qid']);
        $this->assertSame('ruler', $ruler['kind']);
        $this->assertSame(-27, $ruler['era_start']);   // 27 BCE
        $this->assertSame(14, $ruler['era_end']);       // 14 CE
    }

    public function test_people_query_parses_sitelinks_and_births_with_bce(): void
    {
        Http::fake([
            'query.wikidata.org/sparql*' => Http::response([
                'results' => ['bindings' => [
                    [
                        'person' => ['value' => 'http://www.wikidata.org/entity/Q1048'],
                        'personLabel' => ['value' => 'Julius Caesar'],
                        'birth' => ['value' => '-0100-07-12T00:00:00Z'],
                        'death' => ['value' => '-0044-03-15T00:00:00Z'],
                        'article' => ['value' => 'https://en.wikipedia.org/wiki/Julius_Caesar'],
                        'sitelinks' => ['value' => '250'],
                    ],
                ]],
            ]),
        ]);

        $svc = new WikidataFiguresService;
        $people = $svc->peopleFor('Q12544', 8);

        $this->assertCount(1, $people);
        $p = $people[0];
        $this->assertSame('Q1048', $p['qid']);
        $this->assertSame('Julius Caesar', $p['name']);
        $this->assertSame('person', $p['kind']);
        $this->assertSame(-100, $p['era_start']);   // 100 BCE
        $this->assertSame(-44, $p['era_end']);       // 44 BCE
        $this->assertSame(250, $p['sitelinks']);
        $this->assertSame('https://en.wikipedia.org/wiki/Julius_Caesar', $p['wikipedia_url']);
    }
}
