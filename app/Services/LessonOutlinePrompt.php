<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\StrategyGame;

final class LessonOutlinePrompt
{
    public static function system(bool $hasGame = false): string
    {
        $kindEnum   = $hasGame ? '"narration" | "game"' : '"narration"';
        $gameNote   = $hasGame
            ? ''
            : "\n- Do NOT include any scenes with kind=\"game\". Only narration scenes are allowed.";

        return <<<SYS
You are a K-12 curriculum writer producing structured lesson outlines.
Return ONLY a JSON object with this exact shape — no markdown, no prose, no extra keys:

{
  "title": string,
  "scene_briefs": [
    {
      "order": integer (1-indexed),
      "kind": {$kindEnum},
      "phase": "intro" | "development" | "climax" | "resolution",
      "scenePurpose": string (one sentence — what should the student understand after this scene),
      "year": string | null,
      "location": string | null,
      "viewerPosition": string | null (e.g. "standing in the market square", "on a ship deck"),
      "historicalFacts": string[] (2-4 specific facts to ground the narration — from source only),
      "visualEvidence": string[] (4-8 period-accurate visual details for the scene image),
      "avoidList": string[] (2-5 anachronisms or objects to explicitly exclude from the image),
      "beat": string (one sentence — what happens in this scene narratively),
      "image_prompt_seed": string (short descriptive phrase for image generation),
      "game_segment_index": integer | null
    }
  ]
}

Rules:
- Produce AT LEAST 3 narration scenes — single-scene outlines are forbidden. Break the story into
  distinct beats (e.g. setup → rising action → climax → resolution); use the "phase" field to mark them.
- Aim for roughly one narration scene per 60–90 seconds of target duration; the user message gives an
  explicit target_narration_scenes — match it within ±1.
- Each scene's "beat" must advance the story; do not summarize the whole lesson in one scene.
- If the source text is short, expand by using each historical fact / actor / location as a separate
  scene rather than collapsing everything together.
- Only use facts from the provided source text. If uncertain, omit — never invent.
- Match the requested grade-level vocabulary and tone.
- historicalFacts must be verifiable from the source text — no fabrication.
- visualEvidence must be period-accurate: no anachronisms.
- avoidList must include obvious anachronisms for the period (modern vehicles, electricity, etc.).{$gameNote}
- Place game scenes at pedagogically sensible points (after a narration has introduced new content).
SYS;
    }

    public static function user(Lesson $lesson, string $sourceText): string
    {
        $game       = $lesson->strategyGame;
        $gameType   = $lesson->game_type ?? 'quiz';
        $teamCount  = $lesson->team_count ?? 0;
        $splitCount = (int) ($lesson->game_split_count ?? 1);
        $duration    = $lesson->duration_seconds
            ? (int) round($lesson->duration_seconds / 60)
            : 10;
        // Target one narration scene per ~75 seconds, with a hard floor of 3.
        $targetNarrationScenes = max(3, (int) round(($duration * 60) / 75));

        $gameClause = '';
        if ($gameType === 'strategy' && $game instanceof StrategyGame && $splitCount > 0) {
            $gameClause = self::buildGameClause($game->title, $teamCount, $splitCount);
        } elseif ($gameType === 'quiz') {
            $count = (int) ($lesson->quiz_question_count ?? 4);
            $timing = $lesson->quiz_timing ?? 'after';
            $gameClause = "Quiz game: insert one game scene as a {$count}-question checkpoint ({$timing}).";
        } elseif ($gameType === 'debate') {
            $gameClause = 'Debate game: insert one game scene where students defend opposing interpretations using evidence from the lesson.';
        }

        return <<<USR
Topic: {$lesson->topic}
Subject: {$lesson->subject}
Grade level: {$lesson->grade_level}
Tone: {$lesson->tone}
Teacher details: {$lesson->details}
Target duration: {$duration} minutes
target_narration_scenes: {$targetNarrationScenes} (must be ≥3; aim within ±1)
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
