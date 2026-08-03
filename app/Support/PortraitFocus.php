<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a scene background should be cropped from.
 *
 * Scene backgrounds are cover-fitted into a 16:9 stage, so a portrait centred in the frame gets its
 * head sliced off. Anchoring the crop to the top keeps the sitter's face on screen. The rule was
 * implemented three separate times (corpus tags in the wizard, corpus tags in SceneImageSourcer, a
 * title heuristic in SceneImageSourcer) and the Wikimedia Commons picker was missed entirely — which
 * is how a teacher-picked Columbus portrait ended up showing everything but his face. One place now.
 */
final class PortraitFocus
{
    public const TOP = 'top';

    public const CENTER = 'center';

    /** Corpus artwork tags that mean "a person is the subject". */
    private const PORTRAIT_TAGS = ['portrait', 'group-portrait', 'equestrian-portrait', 'self-portrait'];

    /** Title/query words that mean the same, in the two languages the product ships. */
    private const PORTRAIT_WORDS = ['portrait', 'portret', 'zelfportret', 'self-portrait', 'selfportrait', 'bust', 'buste'];

    /**
     * How much taller than wide an image must be before its SHAPE alone implies a portrait.
     * Deliberately above 1.0 so a square-ish work is not mistaken for one.
     */
    private const PORTRAIT_ASPECT = 1.05;

    /** @param  iterable<string>  $tags */
    public static function forTags(iterable $tags): string
    {
        foreach ($tags as $tag) {
            if (in_array(mb_strtolower(trim((string) $tag)), self::PORTRAIT_TAGS, true)) {
                return self::TOP;
            }
        }

        return self::CENTER;
    }

    /** Title, filename or search query — whatever text describes the image. */
    public static function forText(string ...$parts): string
    {
        $haystack = mb_strtolower(implode(' ', $parts));

        foreach (self::PORTRAIT_WORDS as $word) {
            if (str_contains($haystack, $word)) {
                return self::TOP;
            }
        }

        return self::CENTER;
    }

    /**
     * Is this image taller than wide enough to be a portrait?
     *
     * Mirrors isPortraitShape() in resources/js/scene/background-fit.js — the renderers apply the
     * same test to the loaded pixels, so keep the two thresholds identical.
     */
    public static function isPortraitShape(?int $width, ?int $height): bool
    {
        return $width !== null && $height !== null
            && $width > 0
            && $height > $width * self::PORTRAIT_ASPECT;
    }

    /**
     * Best guess from everything known about a file.
     *
     * The DESCRIPTION decides. Shape used to be a second, sufficient signal — any image taller
     * than wide cropped from the top — and that is what put a tall engraving of a ship on screen
     * as mast and flag with the ship cropped away. Tall is not the same as "there is a face here".
     *
     * The dimensions stay in the signature because callers pass what they measured and a future
     * face detector would use them; they no longer decide on their own.
     */
    public static function forImage(string $text, ?int $width = null, ?int $height = null): string
    {
        return self::forText($text);
    }
}
