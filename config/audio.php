<?php

declare(strict_types=1);

/**
 * The lesson soundtrack: the background music bed and the UI sound effects.
 *
 * Everything here lives in public/ and is served straight from the web root, so the paths are
 * relative to it. This file is the one place that knows what the soundtrack is made of — the
 * wizard toggle, the player and the wizard preview all read it, so adding or swapping a track is
 * a one-line change here and nothing else.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Background music
    |--------------------------------------------------------------------------
    |
    | A bed that plays under the narration when the teacher turns it on. The player shuffles
    | these and crossfades from one into the next, so the order differs per lesson and no class
    | hears the same opening twice.
    |
    */

    'background_music' => [
        'tracks' => [
            'sound/bg-music/track-1.mp3',
            'sound/bg-music/track-2.mp3',
            'sound/bg-music/track-3.mp3',
        ],

        // How loud the bed sits under the narration, before the student's own volume is applied.
        // Music is scenery, never the thing being listened to — a voice has to win over it in a
        // classroom with 25 children in it.
        'volume' => 0.14,

        // Crossfade length. Long enough that one track dissolves into the next rather than
        // cutting, short enough that it isn't a third of the track playing at half volume.
        'fade_ms' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sound effects
    |--------------------------------------------------------------------------
    |
    | Short one-shots fired by the interface. They follow the player's mute and volume, because
    | a teacher who muted the lesson muted the whole lesson.
    |
    */

    'sfx' => [
        'correct' => 'sound/sfx/correct.mp3',
    ],

];
