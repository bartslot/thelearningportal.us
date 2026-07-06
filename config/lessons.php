<?php

declare(strict_types=1);

return [

    'max_generation_attempts' => (int) env('MAX_LESSON_GENERATION_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Scene shots (multi-image scenes)
    |--------------------------------------------------------------------------
    | Each scene gets a storyboard of shots generated as ONE image (a grid) that
    | is cropped into cells and upscaled — one image-gen call per scene, many
    | images on screen. '3x3' = 9 shots; '2x2' = 4 larger, higher-fidelity cells.
    | Set shot_grid to null/'' to fall back to the single-image pipeline.
    */
    'shot_grid' => env('LESSON_SHOT_GRID', '3x3'),

];
