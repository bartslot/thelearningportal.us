<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CanonThemeCatalog;
use PHPUnit\Framework\TestCase;

class CanonThemeCatalogTest extends TestCase
{
    public function test_it_maps_learning_goals_to_official_canon_themes(): void
    {
        $catalog = new CanonThemeCatalog;

        $this->assertSame('governance', $catalog->suggest(
            'The Eighty Years War and the Dutch revolt against Spain',
        ));
        $this->assertSame('vulnerable-delta', $catalog->suggest(
            'How the Watersnood changed Dutch water management',
        ));
        $this->assertSame('word-and-image', $catalog->suggest(
            'How Rembrandt used light in Dutch painting',
        ));
    }

    public function test_it_uses_other_when_no_theme_is_a_reliable_match(): void
    {
        $catalog = new CanonThemeCatalog;

        $this->assertSame(CanonThemeCatalog::OTHER, $catalog->suggest(
            'A local family memory with no historical context',
        ));
    }
}
