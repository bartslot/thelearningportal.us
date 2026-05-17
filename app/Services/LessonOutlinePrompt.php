<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\StrategyGame;

final class LessonOutlinePrompt
{
    public static function system(): string
    {
        return <<<SYS
You are a K-12 curriculum writer producing structured lesson outlines.
Return ONLY a JSON object with this exact shape:
{
  "title": string,
  "scene_briefs": [
    {
      "order": integer (1-indexed),
      "kind": "narration" | "game",
      "year": string | null,
      "location": string | null,
      "beat": string (one-sentence summary of what happens in this scene),
      "image_prompt_seed": string | null,
      "game_segment_index": integer | null
    }
  ]
}

Rules:
- Only use facts from the provided source text. If uncertain, omit — never invent.
- Match the requested grade-level vocabulary and tone.
- Place game scenes at pedagogically sensible points.
SYS;
    }

    public static function user(Lesson $lesson, string $sourceText): string
    {
        $game        = $lesson->strategyGame;
        $teamCount   = $lesson->team_count ?? 0;
        $splitCount  = (int) ($lesson->game_split_count ?? 1);
        $duration    = $lesson->duration_seconds
            ? (int) round($lesson->duration_seconds / 60)
            : 10;

        $gameClause = '';
        if ($game instanceof StrategyGame && $splitCount > 0) {
            $gameClause = self::buildGameClause($game->title, $teamCount, $splitCount);
        }

        return <<<USR
Topic: {$lesson->topic}
Subject: {$lesson->subject}
Grade level: {$lesson->grade_level}
Tone: {$lesson->tone}
Teacher details: {$lesson->details}
Target duration: {$duration} minutes
{$gameClause}

Source text:
"""
{$sourceText}
"""

Produce the JSON outline now.
USR;
    }

    private static function buildGameClause(string $gameTitle, int $teamCount, int $splitCount): string
    {
        $base = "Strategy game: \"{$gameTitle}\" with {$teamCount} teams.";
        if ($splitCount <= 1) {
            return "{$base} Insert one game scene at a pedagogically sensible point.";
        }
        return "{$base} Split the game into {$splitCount} segments (kind=game, "
             . "game_segment_index 1..{$splitCount}). Between every two consecutive game segments, "
             . "insert one short narration scene (kind=narration) briefing students on what changed.";
    }
}
