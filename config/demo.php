<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Landing-page demo lesson
    |--------------------------------------------------------------------------
    |
    | The lesson the hero animation pretends to build, and the one a visitor gets
    | a private copy of when they press Configure. Matched on title so the demo
    | survives a rebuild of the lesson (which changes its id and lesson_code).
    |
    */

    'lesson_title' => env('DEMO_LESSON_TITLE', 'Abel Tasman: two voyages to the unknown south'),

    /*
    |--------------------------------------------------------------------------
    | What the hero types
    |--------------------------------------------------------------------------
    |
    | The learning goal the demo types out. It has to describe the lesson above,
    | because the buttons underneath open exactly that lesson. When the configured
    | lesson is not available and DemoLesson falls back to another one, the hero
    | types THAT lesson's title instead, so the words never promise the wrong thing.
    |
    */

    'lesson_goal' => env('DEMO_LESSON_GOAL', 'Abel Tasman, and the two voyages that mapped the south'),

    // Hand-picked square footage for the default lesson's ring, if there is any. Beats scraping
    // the lesson's own artwork: chosen to sit next to each other, already cropped.
    'lesson_footage' => 'public/lessons/tasman/animation',

    /*
    |--------------------------------------------------------------------------
    | Which lesson each LANGUAGE sees
    |--------------------------------------------------------------------------
    |
    | Keyed by language, not by country, because language is the thing that must
    | never be wrong. A Dutch teacher opening the landing page and being handed an
    | English lesson is not a slightly worse demo — it is the wrong product. So the
    | rule is strict: a visitor is only ever offered a lesson in their own
    | language, or the English one. Never a third language.
    |
    | Matched on title so a rebuild (which changes id and lesson_code) keeps
    | working, and DemoLesson checks the winning lesson's `language` column really
    | does match before it hands it over — a title can be edited, a column cannot
    | drift by accident.
    |
    | An entry naming a lesson nobody has written yet costs nothing: it falls
    | through to English, which is the documented behaviour rather than an
    | accident. That is what 'de' and 'it' are doing here.
    |
    | English is deliberately NOT listed here. It comes from `lesson_title` and
    | `lesson_goal` above, so DEMO_LESSON_TITLE stays the one env var that pins
    | the default demo, and English has a single source of truth.
    |
    | The hero's animation tiles come from whichever lesson wins:
    | run `lessons:build-warp-atlas --all` after changing this map.
    |
    */

    'by_language' => [
        'nl' => ['title' => 'De reis van Abel Tasman', 'goal' => 'Abel Tasman en de reis van 1642', 'footage' => 'public/lessons/tasman/animation'],
        'fr' => ['title' => 'La Révolution française et l\'ascension de Napoléon', 'goal' => 'La Révolution française, et comment Bonaparte a pris le pouvoir'],

        // No German or Italian lesson exists yet. Listed so the intent is visible and so writing
        // one is a content job, not a code change — until then both fall through to English.
        'de' => ['title' => null, 'goal' => null],
        'it' => ['title' => null, 'goal' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | What language a country implies
    |--------------------------------------------------------------------------
    |
    | Only consulted when the browser did not ask for one of our languages. A
    | teacher in Amsterdam whose laptop is set to English still teaches in Dutch,
    | so the country gets them the Dutch lesson; a French visitor is matched on
    | EITHER signal, which is what "in France, or with a French browser" means.
    |
    | Anything not listed is English.
    |
    */

    'country_language' => [
        'NL' => 'nl', 'BE' => 'nl',
        'FR' => 'fr',
        'DE' => 'de', 'AT' => 'de', 'CH' => 'de',
        'IT' => 'it',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest sandbox
    |--------------------------------------------------------------------------
    |
    | Guest accounts and their lesson copies are deleted this many hours after
    | they were created (demo:prune-guests, scheduled hourly). Long enough for
    | somebody to wander off and come back to the tab, short enough that the
    | table never becomes a graveyard.
    |
    */

    'guest_ttl_hours' => (int) env('DEMO_GUEST_TTL_HOURS', 24),

];
