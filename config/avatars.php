<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Use 2D portrait instead of the 3D avatar
    |--------------------------------------------------------------------------
    |
    | When true, the lesson scene renders the avatar's flat 2D thumbnail image
    | in place of the animated 3D character (backgrounds, audio and captions
    | still play). Set to false to load the 3D GLB avatar. Default: true.
    |
    */

    'use_2d' => env('AVATAR_USE_2D', true),

];
