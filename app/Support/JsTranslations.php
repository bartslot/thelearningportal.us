<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The strings the player's JavaScript renders, handed to the browser already translated.
 *
 * `php artisan lang:audit` finds `__()` in PHP and Blade only, so anything a JS module built by
 * hand was invisible to it: five languages reported 100% while a French class answered a quiz that
 * praised them in English. Listing the strings HERE puts them back inside the audit, and inside
 * the test that fails the build when a language falls behind.
 *
 * Keyed by the English source, matching Laravel's JSON translations, so `t()` in the browser reads
 * the same way `__()` does on the server and an untranslated language degrades to English.
 *
 * WRITE EVERY ENTRY OUT IN FULL: the English as the array key, and the same English again inside
 * the translation call. Never build either side from a variable, a loop or a constant.
 *
 * The repetition is the point. The audit extracts strings by matching the translation helper
 * against a literal, the way every gettext-style extractor has to — it cannot run the code, so it
 * cannot see a string that arrives in a variable. An earlier version of this file looped over an
 * array and translated each entry in turn, which read better and quietly undid the whole exercise:
 * not one of these strings reached the catalogue, all of them were reported as UNUSED keys, and
 * `lang:audit --prune` would have deleted the lot. A loop here is not a refactor, it is the bug.
 *
 * (Nor may an example of the right shape be written out in this comment. The extractor reads the
 * whole file, comments included, and would add the example itself to the catalogue — leaving every
 * language permanently one string short of complete. It did.)
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
     * The quiz card: the classroom sign-in, the score card and the leaderboard behind it.
     *
     * The praise ("Nice!", "Almost!") and the read-gate sentence were here until the card was
     * reworked to say all of that with motion instead — see resources/js/scene/QuizOverlay.js.
     *
     * @return array<string, string>
     */
    private static function quiz(): array
    {
        return [
            'Continue' => __('Continue'),

            // Joining a classroom leaderboard.
            'Join the leaderboard' => __('Join the leaderboard'),
            'Class code…' => __('Class code…'),
            'Your name…' => __('Your name…'),
            'Submit' => __('Submit'),
            'Pick a name (at least 2 letters).' => __('Pick a name (at least 2 letters).'),
            'Check the class code, ask your teacher.' => __('Check the class code, ask your teacher.'),
            'Could not submit, try again.' => __('Could not submit, try again.'),

            // The score card.
            ':correct of :total correct' => __(':correct of :total correct'),
            'Skip' => __('Skip'),

            // …and the leaderboard behind it. `t()` is a lookup, not `trans_choice`, so a count of
            // one and a count of many are two separate keys.
            'Leaderboard' => __('Leaderboard'),
            ':count player' => __(':count player'),
            ':count players' => __(':count players'),
            'No scores yet. You could be first!' => __('No scores yet. You could be first!'),
            'You are #:rank of :players. Keep climbing!' => __('You are #:rank of :players. Keep climbing!'),
            // Marks the reader's own row in the list.
            'you' => __('you'),

            // Both end screens hand the lesson back on their own, and say so while they do.
            'Lesson starts in :count' => __('Lesson starts in :count'),
        ];
    }

    /**
     * Player chrome that JavaScript writes rather than Blade.
     *
     * @return array<string, string>
     */
    private static function player(): array
    {
        return [
            // Leaving the tab mid-quiz pauses it behind a veil.
            'Quiz paused' => __('Quiz paused'),
            'Stay with the story. Tap to continue.' => __('Stay with the story. Tap to continue.'),

            // The chapter list's fallback for a scene with no name of its own. On a Dutch lesson
            // this was the one English phrase on screen.
            'Chapter :number' => __('Chapter :number'),
        ];
    }
}
