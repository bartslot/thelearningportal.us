<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * LLM-as-judge rubric for a generated lesson script. One cheap call that catches the
 * failure modes pilot teachers reported: scenery-only scenes, no causality, objectives
 * never taught. Consumed by GenerateLessonScript for a single revise loop.
 */
final class ScriptCritiquePrompt
{
    public const SCORE_KEYS = ['causality', 'character_agency', 'objective_coverage', 'grade_fit', 'variety'];

    /** Scores below this trigger one regeneration with the issues fed back. */
    public const PASS_THRESHOLD = 3;

    public static function system(): string
    {
        return <<<'SYS'
You are a strict K-12 story editor. Grade the lesson narration below. Be harsh: the bar is
"a 12-year-old begs to hear what happens next", not "factually fine".

Score each dimension 1-5 (integers):
- causality: do scenes connect with but/therefore, or is it a list of facts? A sequence of
  disconnected postcards scores 1.
- character_agency: do NAMED people decide, act, speak? Scenery descriptions with nobody
  acting score 1.
- objective_coverage: could a student answer every listed learning objective after hearing
  this? Missing objectives cap this at 2.
- grade_fit: vocabulary and sentence length for the stated grade.
- variety: do scenes open differently, or repeat a formula ("you are standing…", "the air
  is thick…")?

Return ONLY JSON:
{
  "causality": int, "character_agency": int, "objective_coverage": int,
  "grade_fit": int, "variety": int,
  "issues": [string]   // concrete, fixable problems, each naming the scene order it occurs in
}
SYS;
    }

    /** @param Collection<int, string> $scriptsByOrder keyed by scene order */
    public static function user(Lesson $lesson, Collection $scriptsByOrder): string
    {
        $objectives = collect($lesson->outline['learning_objectives'] ?? [])
            ->map(fn (array $objective) => '- '.($objective['id'] ?? '?').': '.($objective['text'] ?? ''))
            ->implode("\n");

        $scenes = $scriptsByOrder
            ->map(fn (string $script, int $order) => "Scene {$order}:\n{$script}")
            ->implode("\n\n");

        return <<<USR
Grade level: {$lesson->grade_level}

Learning objectives:
{$objectives}

Narration:

{$scenes}

Grade it now. JSON only.
USR;
    }

    /** @param array<string, mixed> $critique */
    public static function needsRevision(array $critique): bool
    {
        foreach (self::SCORE_KEYS as $key) {
            $score = (int) ($critique[$key] ?? 0);
            if ($score < self::PASS_THRESHOLD) {
                return true;
            }
        }

        return false;
    }
}
