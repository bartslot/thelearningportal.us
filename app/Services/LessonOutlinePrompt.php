<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NarrativeFramework;
use App\Models\Lesson;
use App\Models\StrategyGame;

final class LessonOutlinePrompt
{
    public static function system(bool $hasGame = false): string
    {
        $kindEnum   = $hasGame ? '"narration" | "game"' : '"narration"';
        $gameNote   = $hasGame
            ? ''
            : "\n- Do NOT include any scenes with kind=\"game\". Only narration scenes are allowed."
              ."\n- No scene may depict students, quizzes, tests, or classroom activities — every scene"
              .' stays inside the historical story itself.';

        return <<<SYS
You are a K-12 curriculum writer producing structured lesson outlines.
Return ONLY a JSON object with this exact shape — no markdown, no prose, no extra keys:

{
  "title": string,
  "learning_objectives": [
    {
      "id": string ("LO1", "LO2", ...),
      "text": string (specific and testable — "The student can explain why X led to Y", never "learn about X"),
      "bloom": "remember" | "understand" | "apply" | "analyze"
    }
  ],
  "scene_briefs": [
    {
      "order": integer (1-indexed),
      "kind": {$kindEnum},
      "phase": "intro" | "development" | "climax" | "resolution",
      "scenePurpose": string (one sentence — what should the student understand after this scene),
      "objective_ids": string[] (which learning objectives this scene teaches — at least one),
      "characters": string[] (1-3 NAMED historical people who act in this scene — from source only),
      "conflict": string (one sentence — the tension, threat, or dilemma driving this scene),
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
- Write 3-5 learning_objectives FIRST, derived from the most important ideas in the source text.
  Each must be specific and testable from the lesson itself. These drive everything else: every
  objective must be covered by at least one scene (objective_ids), and every scene must teach at
  least one objective. A scene that teaches nothing is forbidden.
- Every scene needs "characters" (named people from the source who ACT in it) and a "conflict"
  (what is at stake). History without people deciding things is a museum plaque, not a lesson.
- The LAST narration scene is the resolution AND the recap: it lands the story's meaning and
  revisits every learning objective as one memorable line each — never a dry summary list.
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
- Follow the "Narrative arc" in the user message: map the scenes onto its beats in order, connect each
  beat to the previous with "but"/"therefore" (never "and then"), and centre the story on the protagonist when one is named.
SYS;
    }

    public static function user(Lesson $lesson, string $sourceText, ?\App\Models\Story $story = null): string
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

        // Narrative arc (Spec 1): the chosen framework's dramatic spine shapes the beat structure.
        $framework = $lesson->narrative_framework instanceof NarrativeFramework
            ? $lesson->narrative_framework
            : (NarrativeFramework::tryFrom((string) $lesson->narrative_framework) ?? NarrativeFramework::default());
        $spine = StorySpine::for($framework);

        $arcLines = [
            "Narrative arc: \"{$framework->label()}\" — map the scenes onto these beats, in order: {$spine->beatsLine()}.",
            $spine->abtRule,
        ];
        if ($protagonistClause = $spine->protagonistClause($lesson->protagonist_name)) {
            $arcLines[] = $protagonistClause;
        }
        if ($gameClause !== '') {
            $arcLines[] = "Place the game at the \"{$spine->gamePlacementBeat}\" beat of the arc.";
        }
        $arcBlock = implode("\n", $arcLines);

        $storyBlock = $story ? self::buildStoryBlock($story) : '';

        return <<<USR
Topic: {$lesson->topic}
Subject: {$lesson->subject}
Grade level: {$lesson->grade_level}
Tone: {$lesson->tone}
Teacher details: {$lesson->details}
Target duration: {$duration} minutes
target_narration_scenes: {$targetNarrationScenes} (must be ≥3; aim within ±1)

{$arcBlock}
{$gameClause}
{$storyBlock}
Source text:
"""
{$sourceText}
"""

Produce the JSON outline now.
USR;
    }

    /**
     * Curated-catalog pedagogy: the story's human-reviewed objectives are adopted verbatim
     * (same ids), and its documented misconceptions become quiz-distractor raw material.
     */
    private static function buildStoryBlock(\App\Models\Story $story): string
    {
        $lines = [];

        $objectives = collect($story->learning_objectives ?? [])
            ->map(fn (array $objective) => '- '.($objective['id'] ?? '?').': '.($objective['text'] ?? ''))
            ->implode("\n");
        if ($objectives !== '') {
            $lines[] = "Use these curated learning objectives EXACTLY as given — same ids, same text, no additions:\n{$objectives}";
        }

        $facts = collect($story->key_facts ?? [])
            ->map(fn (array $fact) => '- '.($fact['fact'] ?? ''))
            ->filter(fn (string $line) => $line !== '- ')
            ->implode("\n");
        if ($facts !== '') {
            $lines[] = "Key facts that MUST appear across the scenes:\n{$facts}";
        }

        $misconceptions = collect($story->misconceptions ?? [])
            ->map(fn (array $entry) => '- '.($entry['misconception'] ?? '').' (correct: '.($entry['correction'] ?? '').')')
            ->filter(fn (string $line) => $line !== '-  (correct: )')
            ->implode("\n");
        if ($misconceptions !== '') {
            $lines[] = "Common student misconceptions (address in scenes where natural):\n{$misconceptions}";
        }

        return $lines === [] ? '' : "\n".implode("\n\n", $lines)."\n";
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
