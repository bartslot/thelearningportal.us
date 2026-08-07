<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Use 2D portrait instead of the 3D character
    |--------------------------------------------------------------------------
    |
    | When true, the lesson scene renders the narrator's flat 2D thumbnail image
    | in place of the animated 3D character (backgrounds, audio and captions
    | still play). Set to false to load the 3D GLB model. Default: true.
    |
    | Nothing reads this at the moment: 3D narrators were retired, and a narrator
    | is now a portrait plus a voice. The AVATAR_USE_2D env key is left alone so
    | existing .env files on the servers keep working.
    |
    */

    'use_2d' => env('AVATAR_USE_2D', true),

];
