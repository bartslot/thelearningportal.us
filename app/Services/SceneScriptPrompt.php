<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Scene;

final class SceneScriptPrompt
{
    public static function system(): string
    {
        return 'You write tight, vivid history narration in 80-150 words per scene. '
             . 'Only use facts from the provided source. If uncertain, omit — never invent.';
    }

    public static function user(Scene $scene, string $sourceText, string $tone, string $grade): string
    {
        $beat     = $scene->lesson->outline['scene_briefs'][$scene->order - 1]['beat'] ?? 'continue the story';
        $year     = $scene->year     ?? 'unknown year';
        $location = $scene->location ?? 'unknown location';

        return <<<USR
Scene {$scene->order} — {$year}, {$location}
Beat: {$beat}
Grade level: {$grade}
Tone: {$tone}

Source text (authoritative):
"""
{$sourceText}
"""

Write the narration for THIS scene only. 80-150 words. Plain prose, no headings, no scene number.
USR;
    }
}
