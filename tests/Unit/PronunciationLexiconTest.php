<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\PronunciationLexicon;
use PHPUnit\Framework\TestCase;

class PronunciationLexiconTest extends TestCase
{
    public function test_dutch_limes_is_respelled_with_case_preserved(): void
    {
        $this->assertSame(
            'De Romeinse liemes was de grens. De Liemes. DE LIEMES!',
            PronunciationLexicon::apply('De Romeinse limes was de grens. De Limes. DE LIMES!', 'nl'),
        );
    }

    public function test_word_boundaries_protect_other_words(): void
    {
        $this->assertSame(
            'sublimes klimt over de liemes',
            PronunciationLexicon::apply('sublimes klimt over de limes', 'nl'),
        );
    }

    public function test_other_locales_and_null_are_untouched(): void
    {
        $this->assertSame('the limes frontier', PronunciationLexicon::apply('the limes frontier', 'en'));
        $this->assertSame('the limes frontier', PronunciationLexicon::apply('the limes frontier', null));
    }
}
