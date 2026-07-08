<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\NameMatcher;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    public function test_canonicalizes_to_first_name_plus_initial(): void
    {
        $this->assertSame('Emma V.', NameMatcher::canonical('emma visser'));
        $this->assertSame('Emma V.', NameMatcher::canonical('  EMMA   V. '));
        $this->assertSame('Emma', NameMatcher::canonical('emma'));
    }

    public function test_matches_roster_entries_with_typos_and_full_surnames(): void
    {
        $roster = ['Emma V.', 'Emma B.', 'Daan K.'];

        $this->assertSame('Emma V.', NameMatcher::match('Emma Visser', $roster));
        $this->assertSame('Emma V.', NameMatcher::match('emma v', $roster));
        $this->assertSame('Daan K.', NameMatcher::match('Dan K.', $roster));      // 1 typo
        $this->assertNull(NameMatcher::match('Sofie J.', $roster));               // not on roster
        $this->assertNull(NameMatcher::match('Emma', $roster));                   // ambiguous: two Emmas
    }
}
