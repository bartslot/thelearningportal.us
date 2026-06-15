<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Services\WikipediaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogSourceTest extends TestCase
{
    public function test_fetch_by_url_extracts_title_and_fetches_exact_article(): void
    {
        Http::fake([
            'en.wikipedia.org/w/api.php*' => Http::response([
                'query' => ['pages' => [
                    '123' => ['extract' => 'The Roman Empire was the post-Republican state of ancient Rome.'],
                ]],
            ]),
        ]);

        $text = (new WikipediaService)->fetchByUrl('https://en.wikipedia.org/wiki/Roman_Empire');

        $this->assertStringContainsString('Roman Empire', (string) $text);
    }

    public function test_fetch_by_url_returns_null_for_non_wiki_url(): void
    {
        $this->assertNull((new WikipediaService)->fetchByUrl('https://example.com/not-wiki'));
    }

    public function test_source_attribution_reflects_topic_type(): void
    {
        $polity = new Lesson(['topic_id' => 'polity:Q2277']);
        $figure = new Lesson(['topic_id' => 'figure:Q1048:Q2277']);
        $legacy = new Lesson(['topic_id' => null]);

        $this->assertStringContainsString('Cliopatria', $polity->sourceAttribution());
        $this->assertStringContainsString('Wikidata', $figure->sourceAttribution());
        $this->assertNull($legacy->sourceAttribution());
    }
}
