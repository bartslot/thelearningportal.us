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
    | Which lesson each country sees
    |--------------------------------------------------------------------------
    |
    | A German teacher should meet a lesson from their own curriculum, not a Dutch
    | one. Keyed by ISO-3166 alpha-2 (App\Support\VisitorCountry); anything not
    | listed gets `lesson_title` above. Matched on title, like the default, so a
    | rebuilt lesson keeps working — and an entry naming a lesson that does not
    | exist yet simply falls through, which is what makes it safe to list a
    | country before its lesson has been written.
    |
    | The hero's animation tiles always come from whichever lesson wins here:
    | run `lessons:build-warp-atlas --all` after changing this map.
    |
    */

    'by_country' => [
        // Dutch Canon.
        // Dutch-speaking: the Dutch edition (lessons:make-tasman-voyage, WMDIF8).
        'NL' => ['title' => 'De reis van Abel Tasman', 'goal' => 'Abel Tasman en de reis van 1642', 'footage' => 'public/lessons/tasman/animation'],
        'BE' => ['title' => 'De reis van Abel Tasman', 'goal' => 'Abel Tasman en de reis van 1642', 'footage' => 'public/lessons/tasman/animation'],

        // English-speaking: the narrated English edition covering BOTH voyages.
        'GB' => ['title' => 'Abel Tasman: two voyages to the unknown south', 'goal' => 'Abel Tasman, and the two voyages that mapped the south', 'footage' => 'public/lessons/tasman/animation'],
        'IE' => ['title' => 'Abel Tasman: two voyages to the unknown south', 'goal' => 'Abel Tasman, and the two voyages that mapped the south', 'footage' => 'public/lessons/tasman/animation'],
        'US' => ['title' => 'Abel Tasman: two voyages to the unknown south', 'goal' => 'Abel Tasman, and the two voyages that mapped the south', 'footage' => 'public/lessons/tasman/animation'],
        'CA' => ['title' => 'Abel Tasman: two voyages to the unknown south', 'goal' => 'Abel Tasman, and the two voyages that mapped the south', 'footage' => 'public/lessons/tasman/animation'],
        'AU' => ['title' => 'Abel Tasman: two voyages to the unknown south', 'goal' => 'Abel Tasman, and the two voyages that mapped the south', 'footage' => 'public/lessons/tasman/animation'],
        'NZ' => ['title' => 'Abel Tasman: two voyages to the unknown south', 'goal' => 'Abel Tasman, and the two voyages that mapped the south', 'footage' => 'public/lessons/tasman/animation'],

        // German-speaking. Anne Frank is a placeholder: it is squarely on the German
        // curriculum, but a lesson written FOR that curriculum should replace it.
        'DE' => ['title' => 'Anne Frank and the Secret Annex', 'goal' => 'Anne Frank and the years in hiding'],
        'AT' => ['title' => 'Anne Frank and the Secret Annex', 'goal' => 'Anne Frank and the years in hiding'],
        'CH' => ['title' => 'Anne Frank and the Secret Annex', 'goal' => 'Anne Frank and the years in hiding'],

        'FR' => ['title' => 'La Révolution française et l\'ascension de Napoléon', 'goal' => 'La Révolution française, et comment Bonaparte a pris le pouvoir'],

        'IT' => ['title' => 'The Colosseum', 'goal' => 'The Colosseum, and the Rome that filled it'],
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
