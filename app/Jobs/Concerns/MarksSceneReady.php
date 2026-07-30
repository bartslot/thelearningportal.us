<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Scene;

trait MarksSceneReady
{
    /**
     * Flip a scene to 'ready' once it has everything it needs to play: words, something to look at,
     * and a recording of those words.
     *
     * "Something to look at" is deliberately not just an image file. A scene whose background is a
     * solid colour renders perfectly well — that is what removing an image leaves behind — and so
     * does one built from shot layers. Insisting on image_path left those scenes sitting at
     * 'generating' forever after their narration arrived: the Script panel kept showing a spinner
     * and publishing refused them with "still generating" while nothing was generating at all.
     */
    protected function maybeMarkReady(Scene $scene): void
    {
        $hasScript = (string) $scene->script_segment !== '';
        $hasVisual = $scene->image_path !== null
            || ! empty($scene->shots)
            || (string) $scene->background_color !== '';
        $hasAudio = $scene->audio_path !== null;

        if ($hasScript && $hasVisual && $hasAudio) {
            $scene->update(['status' => 'ready']);
        }
    }
}
