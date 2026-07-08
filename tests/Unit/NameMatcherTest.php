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

    public function test_same_length_first_name_typos_do_not_cross_match_classmates(): void
    {
        // Kim/Tim, Ana/Ava, Tom/Tim: different real children, one-char SUBSTITUTION — must NOT confidently match.
        $this->assertNull(NameMatcher::match('Tim K.', ['Kim K.']));
        $this->assertNull(NameMatcher::match('Ava B.', ['Ana B.']));
        $this->assertNull(NameMatcher::match('Tom V.', ['Tim V.']));
    }

    public function test_insertion_typos_still_match_the_intended_student(): void
    {
        // Real typos (insertion/deletion in the first name) still resolve.
        $this->assertSame('Daan K.', NameMatcher::match('Dan K.', ['Daan K.', 'Sara J.']));
        $this->assertSame('Emma V.', NameMatcher::match('emma visser', ['Emma V.', 'Noah B.']));
        // Surname-initial typo (first name identical) still matches.
        $this->assertSame('Sofie J.', NameMatcher::match('Sofie J', ['Sofie J.']));
    }

    public function test_empty_roster_returns_null_without_warnings(): void
    {
        $this->assertNull(NameMatcher::match('Emma V.', []));
    }
}
