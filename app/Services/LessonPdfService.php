<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Renders printable teacher PDFs for a lesson via dompdf (pure PHP — works on
 * SiteGround shared hosting where headless Chrome is unavailable).
 *
 * Accepts both persisted lessons (relations queried) and in-memory lessons with
 * relations attached through setRelation() (useful for previews and tooling).
 */
class LessonPdfService
{
    /**
     * Full lesson handout pack: a branded cover/summary page, one block per
     * narration scene, and a teacher-only quiz answer key.
     *
     * @return string raw PDF bytes
     */
    public function handout(Lesson $lesson): string
    {
        return Pdf::loadView('pdf.lesson.handout', [
            'lesson' => $lesson,
            'objectives' => $this->objectives($lesson),
            'scenes' => $this->narrationScenes($lesson),
            'questions' => $this->orderedQuestions($lesson),
            'generatedAt' => now(),
        ])->output();
    }

    /**
     * Learning objectives from the lesson outline, normalised to plain strings.
     * Outline entries are usually ['id' => ..., 'text' => ...] but plain strings
     * are tolerated too.
     *
     * @return list<string>
     */
    private function objectives(Lesson $lesson): array
    {
        return collect($lesson->outline['learning_objectives'] ?? [])
            ->map(fn ($objective): string => is_array($objective)
                ? trim((string) ($objective['text'] ?? ''))
                : trim((string) $objective))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Narration scenes that carry script text, ordered ascending.
     *
     * @return Collection<int, Scene>
     */
    private function narrationScenes(Lesson $lesson): Collection
    {
        $scenes = $lesson->relationLoaded('scenes')
            ? $lesson->scenes
            : $lesson->scenes()->get();

        return $scenes
            ->filter(fn (Scene $scene): bool => $scene->kind === 'narration' && filled($scene->script_segment))
            ->sortBy(fn (Scene $scene): int => (int) $scene->order)
            ->values();
    }

    /**
     * Quiz questions ordered by scene, then question order (mirrors the
     * answer-sheet ordering so both printouts number questions identically).
     *
     * @return Collection<int, QuizQuestion>
     */
    private function orderedQuestions(Lesson $lesson): Collection
    {
        $questions = $lesson->relationLoaded('quizQuestions')
            ? $lesson->quizQuestions
            : $lesson->quizQuestions()->reorder()->orderBy('scene_id')->orderBy('order')->get();

        return $questions
            ->sortBy(fn (QuizQuestion $question): array => [
                (int) ($question->scene_id ?? 0),
                (int) $question->order,
            ])
            ->values();
    }
}
