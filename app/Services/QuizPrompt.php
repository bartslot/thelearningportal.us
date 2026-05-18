<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

final class QuizPrompt
{
    public static function system(): string
    {
        return <<<SYS
Return JSON only:
{
  "questions": [
    {
      "order": integer,
      "question": string,
      "options": [string, string, string, string],
      "correct_index": integer
    }
  ]
}
Write 5 multiple-choice questions strictly grounded in the lesson's combined scene scripts.
SYS;
    }

    public static function user(Lesson $lesson): string
    {
        $combined = $lesson->scenes->pluck('script_segment')->filter()->implode("\n\n");
        return "Lesson topic: {$lesson->topic}\nGrade: {$lesson->grade_level}\n\nCombined narration:\n{$combined}";
    }
}
