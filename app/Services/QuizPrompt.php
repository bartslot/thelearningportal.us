<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/**
 * Objective-driven quiz generation. Every question is pinned to a learning objective from
 * the lesson outline; scenery/mood trivia ("what was the atmosphere?") is explicitly banned —
 * that failure mode is exactly what pilot teachers meant by "nothing learned".
 */
final class QuizPrompt
{
    public const DEFAULT_QUESTION_COUNT = 5;

    public const DIFFICULTY_EASY = 1;

    public const DIFFICULTY_MEDIUM = 2;

    public const DIFFICULTY_HARD = 3;

    /** Shared length discipline: the question frames, the answers stay snack-sized. */
    public const LENGTH_RULES = <<<'TXT'
- Keep it SHORT. The question does the framing (max ~15 words); answers are fragments of
  at most 5-6 words — never full sentences, never restating the question.
  Good: question "What did the Congress of Vienna restore?" → answer "Monarchy and traditional values."
  Bad: answer "They restored the monarchy and traditional values."
TXT;

    /** Star-level difficulty clause shared by whole-quiz and single-question generation. */
    public static function difficultyClause(int $level): string
    {
        return match (max(self::DIFFICULTY_EASY, min(self::DIFFICULTY_HARD, $level))) {
            self::DIFFICULTY_EASY => 'Difficulty: EASY (1 star). Simple recall — who, what, where. '
                .'One-step questions with the answer stated almost literally in the narration. '
                .'Distractors clearly different from the correct answer. Short, common words.',
            self::DIFFICULTY_MEDIUM => 'Difficulty: MEDIUM (2 stars). Understanding — why and how. '
                .'Cause-and-effect and motivation questions whose answer is in the narration but '
                .'needs one connection. Distractors plausible but distinguishable.',
            self::DIFFICULTY_HARD => 'Difficulty: HARD (3 stars). Analysis — compare, judge, infer. '
                .'Questions that combine two facts from the narration or ask about consequences. '
                .'Distractors are subtle near-misses that test real understanding.',
        };
    }

    /** The lesson's quiz difficulty (1-3 stars), stored on the quiz game scene's config. */
    public static function difficultyFor(Lesson $lesson): int
    {
        $config = $lesson->scenes
            ->first(fn ($scene) => $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz')
            ?->config ?? [];

        return (int) ($config['quiz_difficulty'] ?? self::DIFFICULTY_MEDIUM);
    }

    public static function system(Lesson $lesson): string
    {
        $count = self::questionCount($lesson);
        $language = LessonScriptPrompt::contentLanguage($lesson);
        $difficulty = self::difficultyClause(self::difficultyFor($lesson));
        $lengthRules = self::LENGTH_RULES;

        return <<<SYS
You write quiz questions that check whether a student LEARNED the history — causes, decisions,
consequences, people. You never quiz on scenery.

Return JSON only:
{
  "questions": [
    {
      "order": integer,
      "objective_id": string (the learning objective this question tests, e.g. "LO1"),
      "question": string,
      "options": [string, string, string, string],
      "correct_index": integer (0-3),
      "explanation": string (one sentence: why the correct answer is correct)
    }
  ]
}

{$difficulty}

Rules — all mandatory:
{$lengthRules}
- Write exactly {$count} questions, all answerable from the narration alone.
- EVERY learning objective must be tested by at least one question (objective_id).
- Question stems test cause and effect, decisions, consequences, who did what and WHY.
  Good: "Why did the senators turn against Caesar?" Bad: "What stood in the Forum?"
- BANNED: questions about atmosphere, mood, weather, colors, what things looked like,
  where the student was "standing", or any purely visual detail.
- Wrong options must be plausible misconceptions a real student might hold — same category
  as the correct answer, never obviously absurd, never from a different era.
- No two questions may test the same fact. Vary the stems.
- Write questions and options in {$language}.
SYS;
    }

    public static function user(Lesson $lesson): string
    {
        $combined = $lesson->scenes->pluck('script_segment')->filter()->implode("\n\n");

        $objectives = collect($lesson->outline['learning_objectives'] ?? [])
            ->map(fn (array $objective) => '- '.($objective['id'] ?? '?').': '.($objective['text'] ?? ''))
            ->implode("\n");
        $objectivesBlock = $objectives !== ''
            ? "Learning objectives (every one must be tested):\n{$objectives}\n\n"
            : '';

        return <<<USR
Lesson topic: {$lesson->topic}
Grade: {$lesson->grade_level}

{$objectivesBlock}Combined narration:
{$combined}
USR;
    }

    /** At least one question per objective, floor of DEFAULT_QUESTION_COUNT. */
    public static function questionCount(Lesson $lesson): int
    {
        return max(self::DEFAULT_QUESTION_COUNT, count($lesson->outline['learning_objectives'] ?? []));
    }
}
