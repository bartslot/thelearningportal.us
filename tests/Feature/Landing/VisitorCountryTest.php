<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Support\VisitorCountry;
use Tests\TestCase;

/**
 * Where a visitor is, without a GeoIP database or a per-request lookup.
 *
 * These cases used to live inside DemoLessonByCountryTest, which was retired when the demo picker
 * became language-first (see DemoLessonLanguageTest). They are about VisitorCountry itself, they
 * were its only coverage, and the picker still leans on it to decide that a teacher in Amsterdam
 * with an English laptop should get the Dutch lesson — so they move here rather than disappear.
 */
class VisitorCountryTest extends TestCase
{
    public function test_the_country_header_wins_over_the_browser_region(): void
    {
        request()->headers->set('CF-IPCountry', 'CH');
        $this->assertSame('CH', VisitorCountry::code(request()));
    }

    public function test_the_browser_region_is_used_when_no_header_is_set(): void
    {
        request()->headers->remove('CF-IPCountry');
        request()->headers->set('Accept-Language', 'de-AT,de;q=0.9,en;q=0.8');

        $this->assertSame('AT', VisitorCountry::code(request()));
    }

    public function test_a_placeholder_country_header_is_ignored(): void
    {
        // Cloudflare sends XX when it cannot tell; treating that as a country would be a lie.
        request()->headers->set('CF-IPCountry', 'XX');
        request()->headers->set('Accept-Language', 'fr-BE,fr;q=0.9');

        $this->assertSame('BE', VisitorCountry::code(request()));
    }

    public function test_a_language_without_a_region_falls_back_to_the_locale(): void
    {
        request()->headers->remove('CF-IPCountry');
        request()->headers->set('Accept-Language', 'de');
        app()->setLocale('de');

        $this->assertSame('DE', VisitorCountry::code(request()));
    }
}
