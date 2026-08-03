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

    public function test_a_tall_image_is_left_centred_when_nothing_says_there_is_a_face_in_it(): void
    {
        // Shape used to be enough on its own, and a tall engraving of Abel Tasman's fleet came out
        // cropped to mast and flag with the ships gone. Tall is not evidence of a face.
        $this->assertSame('center', PortraitFocus::forImage('Christopher Columbus', 900, 1400));
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
     * The cost of ignoring shape, recorded deliberately.
     *
     * Antonis Mor's full-length "Philip II at the Battle of St. Quentin" is 736×1475 but tagged
     * {battle, empire, military-camp, painting, soldiers, war} — no 'portrait' anywhere — so it is
     * now centred, and Philip loses some of his head. Shape used to catch exactly this case.
     *
     * It is still the right trade. Shape caught every mislabelled portrait AND every tall
     * engraving, map and landscape, and those are the common case: a face wrongly centred loses
     * some forehead, a ship wrongly anchored to the top loses the ship. Scenes that really do need
     * a top crop can say so with an explicit `focus`.
     */
    public function test_a_mistagged_standing_portrait_is_now_centred_and_that_is_accepted(): void
    {
        $this->assertSame('center', PortraitFocus::forTags(['battle', 'empire', 'military-camp', 'painting', 'soldiers', 'war']));
        $this->assertSame('center', PortraitFocus::forImage('Philip II at the Battle of St. Quentin', 736, 1475));

        // The shape helper itself still works; it simply no longer decides the crop on its own.
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
