<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Scene;

/**
 * Storyboard prompt: turns a scene's narration into N distinct shots, each anchored
 * to the exact sentence it illustrates. The anchor is a VERBATIM substring of the
 * script — the player resolves it against the TTS character timings so every image
 * appears at the precise moment the narrator speaks about it.
 */
final class ShotListPrompt
{
    /** Composition vocabulary baked into the prompt so shots don't converge on one framing. */
    private const COMPOSITIONS = 'establishing wide, medium view of activity, close-up of an object or '
        .'detail, over-the-shoulder viewpoint, dramatic low angle, high vantage overview, '
        .'intimate interior, movement or action moment, aftermath or quiet detail';

    public static function system(int $shotCount): string
    {
        $compositions = self::COMPOSITIONS;

        return <<<SYS
You are a storyboard artist for a history documentary. Break the scene narration into
exactly {$shotCount} visual shots that illustrate the story as it is told.

Rules — all mandatory:
- Exactly {$shotCount} shots, ordered to follow the narration from start to end.
- "anchor_sentence" must be copied VERBATIM from the narration text — an exact contiguous
  substring (a sentence or distinctive phrase), no paraphrasing, no added words. This is a
  hard technical requirement: the substring is machine-matched against the narration.
- Each shot gets a DIFFERENT composition. Vary among: {$compositions}.
- Each shot anchors a DIFFERENT part of the narration — spread the anchors across the whole
  text from first sentence to last. Never anchor two shots to the same sentence.
- "description" is a concrete visual moment (place, objects, light, weather, period-accurate
  details) — an environment or object study, no readable text, no modern objects.
- Everything must be period-accurate for the year and location given. Respect the avoid-list.

Return ONLY JSON:
{
  "shots": [
    {
      "order": integer (1-based),
      "anchor_sentence": string (verbatim substring of the narration),
      "composition": string (one of the compositions above),
      "description": string (one or two sentences of concrete visual detail)
    }
  ]
}
SYS;
    }

    public static function user(Scene $scene, int $shotCount): string
    {
        $brief = self::briefFor($scene);

        $visualBlock = ! empty($brief['visualEvidence'])
            ? "Period-accurate visual details to draw from:\n".self::bulletList((array) $brief['visualEvidence'])
            : '';
        $avoidBlock = ! empty($brief['avoidList'])
            ? "Do NOT depict:\n".self::bulletList((array) $brief['avoidList'])
            : '';

        $setting = trim(($scene->year ?? '').' '.($scene->location ?? ''));
        $settingLine = $setting !== '' ? "Setting: {$setting}" : '';

        return <<<USR
{$settingLine}

Narration for this scene (anchor_sentence must be a verbatim substring of THIS text):
"""
{$scene->script_segment}
"""

{$visualBlock}

{$avoidBlock}

Produce the {$shotCount}-shot JSON storyboard now.
USR;
    }

    /**
     * The outline brief for this scene, matched by POSITION among non-map scenes —
     * the default map block shifts scene orders by one, so order-based indexing is wrong.
     *
     * @return array<string, mixed>
     */
    public static function briefFor(Scene $scene): array
    {
        $briefs = array_values($scene->lesson->outline['scene_briefs'] ?? []);
        $position = $scene->lesson->scenes
            ->where('kind', '!=', 'map')
            ->sortBy('order')
            ->values()
            ->search(fn (Scene $candidate) => $candidate->id === $scene->id);

        return ($position !== false ? $briefs[$position] : null) ?? [];
    }

    private static function bulletList(array $items): string
    {
        return implode("\n", array_map(fn ($item) => "- {$item}", $items));
    }
}
