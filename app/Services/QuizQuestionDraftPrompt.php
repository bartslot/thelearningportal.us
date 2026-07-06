<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/**
 * Single-question drafting for the wizard's quiz editor: generate one question with its
 * linked correct answer + three plausible distractors, or regenerate one distractor.
 * The correct answer is produced WITH the question and never edited independently —
 * that invariant is what keeps teacher-edited quizzes internally consistent.
 */
final class QuizQuestionDraftPrompt
{
    public static function questionSystem(Lesson $lesson): string
    {
        $language = LessonScriptPrompt::contentLanguage($lesson);
        $difficulty = QuizPrompt::difficultyClause(QuizPrompt::difficultyFor($lesson));
        $lengthRules = QuizPrompt::LENGTH_RULES;

        return <<<SYS
You write ONE quiz question that checks whether a student LEARNED the history — causes,
decisions, consequences, people. Never quiz on scenery, atmosphere, colors, or appearance.

{$difficulty}

Rules:
{$lengthRules}
- Answerable from the narration alone.
- The correct answer and the question are one unit — write them together.
- The three distractors are plausible misconceptions: same category as the correct answer,
  never absurd, never from a different era.
- Do not repeat any of the existing questions listed.
- Write in {$language}.

Return ONLY JSON:
{
  "question": string,
  "correct_answer": string,
  "distractors": [string, string, string],
  "explanation": string (one sentence: why the correct answer is correct)
}
SYS;
    }

    /**
     * @param  list<string>  $existingQuestions
     */
    public static function questionUser(Lesson $lesson, array $existingQuestions, string $teacherQuestion = '', $scenes = null): string
    {
        // Chronology: only the narration the student has heard before this quiz segment.
        $combined = ($scenes ?? $lesson->scenes)->pluck('script_segment')->filter()->implode("\n\n");

        $objectives = collect($lesson->outline['learning_objectives'] ?? [])
            ->map(fn (array $objective) => '- '.($objective['text'] ?? ''))
            ->implode("\n");
        $objectivesBlock = $objectives !== '' ? "Learning objectives:\n{$objectives}\n\n" : '';

        $existingBlock = $existingQuestions !== []
            ? "Existing questions (do NOT duplicate):\n".implode("\n", array_map(fn ($q) => "- {$q}", $existingQuestions))."\n\n"
            : '';

        $teacherBlock = trim($teacherQuestion) !== ''
            ? "The teacher wrote this question — keep its intent (you may polish the wording):\n\"{$teacherQuestion}\"\n\n"
            : '';

        return <<<USR
Lesson topic: {$lesson->topic}
Grade: {$lesson->grade_level}

{$objectivesBlock}{$existingBlock}{$teacherBlock}Narration the student has heard so far (test ONLY this):
{$combined}

Write the question JSON now.
USR;
    }

    public static function distractorSystem(Lesson $lesson): string
    {
        $language = LessonScriptPrompt::contentLanguage($lesson);

        return <<<SYS
You write ONE plausible wrong answer (distractor) for a history quiz question. It must be
a misconception a real student might hold: same category as the correct answer, never
absurd, never from a different era, clearly wrong to someone who followed the lesson.
Keep it a short fragment of at most 5-6 words — never a full sentence.
Write in {$language}. Return ONLY JSON: { "distractor": string }
SYS;
    }

    /**
     * @param  list<string>  $otherOptions  all current options except the slot being redrawn
     */
    public static function distractorUser(Lesson $lesson, string $question, string $correctAnswer, array $otherOptions): string
    {
        $others = implode("\n", array_map(fn ($option) => "- {$option}", array_filter($otherOptions)));

        return <<<USR
Question: {$question}
Correct answer (do not contradict or duplicate it): {$correctAnswer}
Other answers already shown (do not duplicate):
{$others}

Lesson topic: {$lesson->topic} · Grade: {$lesson->grade_level}

Write the distractor JSON now.
USR;
    }
}
