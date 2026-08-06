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
     * The quiz card: praise, encouragement, the answer gate and the classroom sign-in.
     *
     * @return array<string, string>
     */
    private static function quiz(): array
    {
        return self::translate([
            // Praise, one per correct answer, cycled.
            'Nice!',
            'Great!',
            'Perfect!',
            'Brilliant!',
            'On fire!',
            // …and for a wrong one. Never scolding: the quiz runs in front of a class.
            'Almost!',
            'Good try!',
            'Keep going!',
            // A question that reaches ahead of the story is not a failure either way.
            'You already knew this!',
            'No worries, you will hear this later in the story!',
            // The gate that holds the answers back until the question has been read.
            'Read the question… answers unlock in :count',
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
