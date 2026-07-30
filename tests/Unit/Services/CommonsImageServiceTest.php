<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CommonsImageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommonsImageServiceTest extends TestCase
{
    /**
     * Commons renders its {{Title}} / {{Creator}} templates with Wikidata QuickStatements markers
     * inline, so ObjectName arrives as `My Painting title QS:P1476,en:"My Painting"label QS:Len,…`.
     * That whole string used to land in the credit line printed under a gallery image, so students
     * read raw structured-data syntax. Only the human-readable head may survive.
     */
    public function test_wikidata_quickstatements_markup_is_stripped_from_the_title(): void
    {
        $raw = 'The Departure of Columbus from Palos, Spain, in 1492 title QS:P1476,en:"The Departure '
            .'of Columbus from Palos, Spain, in 1492 "label QS:Len,"The Departure of Columbus from '
            .'Palos, Spain, in 1492 "';

        $result = $this->searchReturning($raw, 'Emanuel Leutze');

        $this->assertSame('The Departure of Columbus from Palos, Spain, in 1492', $result[0]['title']);
    }

    public function test_a_plain_title_is_left_alone(): void
    {
        $result = $this->searchReturning('Christopher Columbus on Santa Maria in 1492', 'Emanuel Leutze');

        $this->assertSame('Christopher Columbus on Santa Maria in 1492', $result[0]['title']);
        $this->assertSame('Emanuel Leutze', $result[0]['artist']);
    }

    public function test_quickstatements_markup_is_stripped_from_the_artist_too(): void
    {
        $result = $this->searchReturning('A View of Palos', 'Emanuel Leutze creator QS:P170,Q313564');

        $this->assertSame('Emanuel Leutze', $result[0]['artist']);
    }

    public function test_html_and_runaway_whitespace_are_collapsed(): void
    {
        $result = $this->searchReturning("<i>Santa&nbsp;Mar&iacute;a</i>\n\n  at   sea", 'Anon');

        $this->assertSame('Santa María at sea', $result[0]['title']);
    }

    /**
     * Fake one Commons search hit carrying the given ObjectName / Artist metadata.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * The picker grid shows cards a few hundred pixels wide. Asking Commons for 800px cost roughly
     * four times the bytes per card (186 KB vs 47 KB on one measured painting), which is most of
     * why the modal felt slow — a full grid pulled megabytes before the teacher chose anything.
     */
    public function test_the_grid_asks_commons_for_card_sized_thumbnails(): void
    {
        $this->searchReturning('A View of Palos', 'Emanuel Leutze');

        Http::assertSent(function ($request) {
            $width = (int) ($request['iiurlwidth'] ?? 0);

            return $width > 0 && $width <= 500;
        });
    }

    /**
     * When Commons declines to render a thumb it still returns `url` — the untouched original,
     * which for a scanned painting can be tens of megabytes. Loading a grid of those is far worse
     * than the oversized-thumb problem it looks like.
     */
    public function test_a_missing_thumbnail_never_falls_back_to_the_full_size_original(): void
    {
        Http::fake([
            '*' => Http::response([
                'query' => ['pages' => [[
                    'title' => 'File:Huge scan.jpg',
                    'imageinfo' => [[
                        'width' => 9000, 'height' => 7000,
                        'url' => 'https://upload.wikimedia.org/Huge_scan.jpg',   // no thumburl
                        'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Huge_scan.jpg',
                        'extmetadata' => ['LicenseShortName' => ['value' => 'Public domain']],
                    ]],
                ]]],
            ]),
        ]);

        $thumb = app(CommonsImageService::class)->searchText('anything')[0]['thumb_url'];

        $this->assertStringNotContainsString('upload.wikimedia.org/Huge_scan.jpg', $thumb);
        $this->assertStringContainsString('width=', $thumb, 'it must still ask for a bounded width');
    }

    private function searchReturning(string $objectName, string $artist): array
    {
        Http::fake([
            '*' => Http::response([
                'query' => [
                    'pages' => [
                        [
                            'title' => 'File:Example.jpg',
                            'imageinfo' => [[
                                // Anything under 640px wide is filtered out as a poor background.
                                'width' => 1600,
                                'height' => 1000,
                                'url' => 'https://upload.wikimedia.org/Example.jpg',
                                'thumburl' => 'https://upload.wikimedia.org/thumb/Example.jpg',
                                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Example.jpg',
                                'extmetadata' => [
                                    'ObjectName' => ['value' => $objectName],
                                    'Artist' => ['value' => $artist],
                                    'LicenseShortName' => ['value' => 'Public domain'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ]),
        ]);

        return app(CommonsImageService::class)->searchText('anything');
    }

    /**
     * A throttled search must not be cached.
     *
     * Wikimedia rate-limits bursts from one address. The old code turned any failure into an
     * empty array and Cache::remember stored it for a full DAY — so one throttled moment pinned
     * "No paintings found" on a search term for 24 hours, in front of an archive that held the
     * painting all along.
     */
    public function test_a_throttled_search_is_not_cached_and_is_reported(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response('', 429),
        ]);

        $service = new CommonsImageService;
        $this->assertSame([], $service->searchText('nachtwacht'));
        $this->assertSame('throttled', $service->lastError, 'the caller must be able to say "try again"');

        // Nothing was written, so a later attempt asks Wikimedia again rather than replaying the failure.
        $this->assertNull(Cache::get('commons.text.v3.'.md5('nachtwacht').'.12'));
    }

    public function test_a_successful_search_is_cached_and_reports_no_error(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
                ['title' => 'File:De Nachtwacht.jpg', 'imageinfo' => [[
                    'url' => 'https://upload.wikimedia.org/x/De_Nachtwacht.jpg',
                    'thumburl' => 'https://upload.wikimedia.org/x/800px-De_Nachtwacht.jpg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:De_Nachtwacht.jpg',
                    'width' => 4000, 'height' => 3000,
                    'extmetadata' => ['LicenseShortName' => ['value' => 'Public domain'], 'Artist' => ['value' => 'Rembrandt']],
                ]]],
            ]]], 200),
        ]);

        $service = new CommonsImageService;
        $rows = $service->searchText('nachtwacht');

        $this->assertCount(1, $rows);
        $this->assertNull($service->lastError);
        $this->assertIsArray(Cache::get('commons.text.v3.'.md5('nachtwacht').'.12'));
    }

    /** A genuine zero-result search IS cached — that answer is stable and worth keeping. */
    public function test_an_empty_but_successful_search_is_cached(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]], 200)]);

        $service = new CommonsImageService;
        $this->assertSame([], $service->searchText('qqqzzz nothing'));
        $this->assertNull($service->lastError);
        $this->assertSame([], Cache::get('commons.text.v3.'.md5('qqqzzz nothing').'.12'));
    }
}
