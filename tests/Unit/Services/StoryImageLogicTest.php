<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\StoryImages\AbstractDomStorySource;
use App\Services\StoryImages\ResolvedStoryImage;
use App\Services\StoryImages\ScrapedArticle;
use App\Services\StoryImages\ScrapedImage;
use App\Services\StoryImages\ScrapedSection;
use PHPUnit\Framework\TestCase;

class StoryImageLogicTest extends TestCase
{
    private function image(string $title, string $license, bool $free): ScrapedImage
    {
        return new ScrapedImage($title, null, $license, $free, null, null, 'sec', 'Section', 0);
    }

    private function article(int $wordsA, int $wordsB): ScrapedArticle
    {
        return new ScrapedArticle('http://x', 'worldhistory', 'en', 'T', [
            new ScrapedSection('a', 'A', $wordsA, [$this->image('Free', 'Public Domain', true)]),
            new ScrapedSection('b', 'B', $wordsB, [$this->image('Paid', 'CC BY-NC-SA', false)]),
        ]);
    }

    public function test_total_words_and_shrink_ratio(): void
    {
        $article = $this->article(1000, 1860);   // 2860 total

        $this->assertSame(2860, $article->totalWords());
        $this->assertSame(2.0, $article->shrinkRatio());   // 2860 / 1430 default
    }

    public function test_free_images_filters_by_license(): void
    {
        $article = $this->article(500, 500);
        $all = $article->images();
        $free = $article->freeImages();

        $this->assertCount(2, $all);
        $this->assertCount(1, $free);
        $this->assertSame('Free', $free[0]->title);
    }

    public function test_scene_plan_never_exceeds_the_narration_cap(): void
    {
        $article = $this->article(2000, 2000);   // 4000 words, well over the cap
        $plan = $article->scenePlan(1000, 130);   // 1000-word budget

        $budgetTotal = array_sum(array_column($plan, 'budget'));
        $this->assertLessThanOrEqual(1000, $budgetTotal);        // compressed to the cap
        $this->assertSame($plan[0]['budget'], $plan[1]['budget']); // equal sections → equal budget
        $this->assertGreaterThanOrEqual(1, $plan[0]['imageBeats']);
    }

    public function test_scene_plan_never_pads_a_short_article(): void
    {
        $article = $this->article(300, 300);   // 600 words, under the cap
        $plan = $article->scenePlan(1430, 130);

        // ratio capped at 1.0 — budgets equal the words, never inflated.
        $this->assertSame(300, $plan[0]['budget']);
        $this->assertSame(300, $plan[1]['budget']);
    }

    /**
     * @dataProvider licenses
     */
    public function test_is_free_license(string $license, bool $expected): void
    {
        $this->assertSame($expected, AbstractDomStorySource::isFreeLicense($license));
    }

    public static function licenses(): array
    {
        return [
            ['Public Domain', true],
            ['CC0', true],
            ['CC BY', true],
            ['CC BY-SA 4.0', true],
            ['CC BY-NC-SA 3.0', false],   // NC
            ['CC BY-ND', false],           // ND
            ['© 2020 Someone', false],
            ['All rights reserved', false],
            ['Unknown', false],
        ];
    }

    public function test_clean_commons_title_strips_wikidata_markup(): void
    {
        $raw = 'Portrait of Philip II of Spain title QS:P1476,en:"Portrait of Philip II"label QS:Len';
        $this->assertSame('Portrait of Philip II of Spain', ResolvedStoryImage::cleanCommonsTitle($raw));
        $this->assertSame('Plain Title', ResolvedStoryImage::cleanCommonsTitle('Plain Title'));
    }

    public function test_content_tokens_are_diacritic_insensitive_and_drop_short_words(): void
    {
        $tokens = ResolvedStoryImage::contentTokens('The Zwarte Dood in Münster');

        $this->assertContains('zwarte', $tokens);
        $this->assertContains('munster', $tokens);   // ü → u
        $this->assertNotContains('the', $tokens);     // length <= 3 dropped
    }

    public function test_credit_line_names_license_and_source(): void
    {
        $img = new ResolvedStoryImage(
            'sec', 'Section', 0,
            'File.jpg', 'http://img', null, 'http://page',
            'Public domain', 'Rembrandt', 'The Night Watch',
            'worldhistory', 'caption', null,
        );

        $credit = $img->credit();
        $this->assertStringContainsString('The Night Watch', $credit);
        $this->assertStringContainsString('Rembrandt', $credit);
        $this->assertStringContainsString('Public domain', $credit);
        $this->assertStringContainsString('Wikimedia Commons', $credit);
    }
}
