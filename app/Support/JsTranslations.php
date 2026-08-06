<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The strings the player's JavaScript renders, handed to the browser already translated.
 *
 * `php artisan lang:audit` finds `__()` in PHP and Blade only, so anything a JS module built by
 * hand was invisible to it: five languages reported 100% while a French class answered a quiz that
 * praised them in English. Listing the strings HERE — as real `__()` calls — puts them back inside
 * the audit, and inside the test that fails the build when a language falls behind.
 *
 * Keyed by the English source, matching Laravel's JSON translations, so `t()` in the browser reads
 * the same way `__()` does on the server and an untranslated language degrades to English.
 *
 * @see resources/js/i18n.js
 */
final class JsTranslations
{
    /** @return array<string, string> */
    public static function forBrowser(): array
    {
        return array_merge(self::quiz(), self::player());
    }

    /**
     * The quiz card: the classroom sign-in, and the handful of words left on it.
     *
     * The praise ("Nice!", "Almost!") and the read-gate sentence were here until the card was
     * reworked to say all of that with motion instead — see resources/js/scene/QuizOverlay.js.
     * A string that no longer exists in the browser must not stay in the audit, or every language
     * is asked to translate something no class will ever read.
     *
     * @return array<string, string>
     */
    private static function quiz(): array
    {
        return self::translate([
            'Continue',
            // Joining a classroom leaderboard.
            'Class code…',
            'Your name…',
            'Submit',
            'Pick a name (at least 2 letters).',
            'Check the class code, ask your teacher.',
            'Could not submit, try again.',
        ]);
    }

    /**
     * Player chrome that JavaScript writes rather than Blade.
     *
     * @return array<string, string>
     */
    private static function player(): array
    {
        return self::translate([
            'Quiz paused',
            'Tap to continue',
            // The chapter list's fallback for a scene with no name of its own. On a Dutch lesson
            // this was the one English phrase on screen.
            'Chapter :number',
        ]);
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<string, string>
     */
    private static function translate(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $out[$source] = __($source);
        }

        return $out;
    }
}
