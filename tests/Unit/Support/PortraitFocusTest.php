<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PortraitFocus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortraitFocusTest extends TestCase
{
    #[DataProvider('portraitTags')]
    public function test_a_portrait_tag_anchors_the_crop_to_the_top(array $tags, string $expected): void
    {
        $this->assertSame($expected, PortraitFocus::forTags($tags));
    }

    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function portraitTags(): array
    {
        return [
            'portrait' => [['portrait', 'oil-painting'], 'top'],
            'self-portrait' => [['self-portrait'], 'top'],
            'group portrait' => [['group-portrait'], 'top'],
            'equestrian portrait' => [['equestrian-portrait'], 'top'],
            'landscape' => [['landscape', 'seascape'], 'center'],
            'no tags' => [[], 'center'],
        ];
    }

    #[DataProvider('titles')]
    public function test_portrait_words_in_the_title_anchor_the_crop_to_the_top(string $text, string $expected): void
    {
        $this->assertSame($expected, PortraitFocus::forText($text));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function titles(): array
    {
        return [
            // The exact Commons file that shipped with a decapitated sitter.
            'said to be columbus' => ['Portrait of a Man, Said to be Christopher Columbus', 'top'],
            'dutch portret' => ['Portret van Michiel de Ruyter', 'top'],
            'dutch zelfportret' => ['Zelfportret met strohoed', 'top'],
            'bust' => ['Marble bust of Julius Caesar', 'top'],
            'case insensitive' => ['PORTRAIT OF A LADY', 'top'],
            'a seascape' => ['The Battle of Trafalgar', 'center'],
            'empty' => ['', 'center'],
        ];
    }

    public function test_a_tall_image_is_treated_as_a_portrait_even_with_a_neutral_title(): void
    {
        // Anything meaningfully taller than it is wide gets cropped from the top: a cover-fit into a
        // 16:9 stage would otherwise slice the head off whatever is standing in the frame.
        $this->assertSame('top', PortraitFocus::forImage('Christopher Columbus', 900, 1400));
        $this->assertSame('center', PortraitFocus::forImage('Christopher Columbus', 1600, 900));
    }

    public function test_a_square_image_is_not_treated_as_a_portrait_on_shape_alone(): void
    {
        $this->assertSame('center', PortraitFocus::forImage('A view of Genoa', 1000, 1000));
    }

    public function test_the_title_still_wins_when_the_shape_is_inconclusive(): void
    {
        $this->assertSame('top', PortraitFocus::forImage('Portrait of a Man', 1600, 900));
    }

    /**
     * Shape is the only signal that catches a portrait the corpus mis-tags. The real case: Antonis
     * Mor's full-length "Philip II at the Battle of St. Quentin" is 736×1475 but tagged
     * {battle, empire, military-camp, painting, soldiers, war} — no 'portrait' anywhere — so a
     * tag-only reading centred the crop and put Philip's waist on screen instead of his face.
     */
    public function test_shape_catches_a_standing_portrait_the_tags_call_a_battle_scene(): void
    {
        $this->assertSame('center', PortraitFocus::forTags(['battle', 'empire', 'military-camp', 'painting', 'soldiers', 'war']));
        $this->assertTrue(PortraitFocus::isPortraitShape(736, 1475));
    }

    public function test_portrait_shape_needs_more_than_a_hair_of_extra_height(): void
    {
        $this->assertTrue(PortraitFocus::isPortraitShape(1000, 1200));
        $this->assertFalse(PortraitFocus::isPortraitShape(1000, 1040));   // within the square-ish band
        $this->assertFalse(PortraitFocus::isPortraitShape(1600, 900));
        $this->assertFalse(PortraitFocus::isPortraitShape(null, 1400));
        $this->assertFalse(PortraitFocus::isPortraitShape(0, 1400));
    }
}
