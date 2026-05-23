<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Scene;

final class SceneScriptPrompt
{
    public static function system(): string
    {
        return 'You write tight, vivid history narration in 80-150 words per scene. '
             . 'Only use facts from the provided source. If uncertain, omit — never invent. '
             . 'Write in second person ("you are standing…") to place the student in the scene. '
             . 'No headings, no scene numbers, no meta-commentary — pure narration prose only.';
    }

    public static function user(Scene $scene, string $sourceText, string $tone, string $grade): string
    {
        $brief    = $scene->lesson->outline['scene_briefs'][$scene->order - 1] ?? [];
        $year     = $scene->year     ?? ($brief['year']     ?? 'unknown year');
        $location = $scene->location ?? ($brief['location'] ?? 'unknown location');

        $beat         = $brief['beat']         ?? 'continue the story';
        $purpose      = $brief['scenePurpose'] ?? '';
        $viewerPos    = $brief['viewerPosition'] ?? '';
        $facts        = $brief['historicalFacts'] ?? [];
        $visual       = $brief['visualEvidence'] ?? [];
        $avoid        = $brief['avoidList'] ?? [];

        $factsBlock  = $facts  ? "Historical facts to include:\n" . self::bulletList($facts)  : '';
        $visualBlock = $visual ? "Visual details in this scene:\n" . self::bulletList($visual) : '';
        $avoidBlock  = $avoid  ? "Do NOT reference:\n"            . self::bulletList($avoid)  : '';
        $posBlock    = $viewerPos ? "Student viewpoint: {$viewerPos}" : '';
        $purposeBlock = $purpose ? "Learning goal: {$purpose}" : '';

        return <<<USR
Scene {$scene->order} — {$year}, {$location}
Beat: {$beat}
{$purposeBlock}
{$posBlock}
Grade level: {$grade}
Tone: {$tone}

{$factsBlock}

{$visualBlock}

{$avoidBlock}

Source text (authoritative — only use facts from here):
"""
{$sourceText}
"""

Write the narration for THIS scene only. 80-150 words. Plain prose, second person, no headings, no scene number.
USR;
    }

    private static function bulletList(array $items): string
    {
        return implode("\n", array_map(fn ($i) => "- {$i}", $items));
    }
}
