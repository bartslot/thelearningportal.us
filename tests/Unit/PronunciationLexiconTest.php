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

    /** The voices read "VOC" as the word "vok"; a teacher says vee-oh-see. */
    public function test_the_voc_is_spelled_out_rather_than_read_as_a_word(): void
    {
        $this->assertSame(
            'De vee oh see was de eerste multinational.',
            PronunciationLexicon::apply('De VOC was de eerste multinational.', 'nl'),
        );
        $this->assertSame(
            'The vee oh see was the first multinational.',
            PronunciationLexicon::apply('The VOC was the first multinational.', 'en'),
        );
    }

    /**
     * A respelling that is a pronunciation, not a word, must survive verbatim — upper-casing it
     * to match "VOC" would hand the voice "VEE OH SEE", which engines spell out letter by letter.
     */
    public function test_a_spelled_out_respelling_is_never_upper_cased(): void
    {
        $spoken = PronunciationLexicon::apply('DE VOC EN DE WIC', 'nl');

        $this->assertStringContainsString('vee oh see', $spoken);
        $this->assertStringNotContainsString('VEE OH SEE', $spoken);
    }

    /**
     * The generated scripts do not reliably capitalise it — lesson NY14S4 opens with
     * "The voc: the world's first multinational." That lowercase form is exactly the one the
     * voice reads as a word, so it has to match too.
     */
    public function test_a_lower_case_voc_is_caught_as_well(): void
    {
        $this->assertSame(
            "The vee oh see: the world's first multinational.",
            PronunciationLexicon::apply("The voc: the world's first multinational.", 'en'),
        );
    }

    public function test_the_written_script_is_only_changed_where_the_word_stands_alone(): void
    {
        $this->assertSame('VOCabulaire blijft VOCabulaire', PronunciationLexicon::apply('VOCabulaire blijft VOCabulaire', 'nl'));
    }
}
