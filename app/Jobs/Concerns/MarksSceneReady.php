<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Scene;

trait MarksSceneReady
{
    protected function maybeMarkReady(Scene $scene): void
    {
        $hasScript = (string) $scene->script_segment !== '';
        $hasImage  = $scene->image_path !== null;
        $hasAudio  = $scene->audio_path !== null;

        if ($hasScript && $hasImage && $hasAudio) {
            $scene->update(['status' => 'ready']);
        }
    }
}
