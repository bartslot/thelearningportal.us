<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Services\OpenAiLlmService;
use App\Services\QuizPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateLessonQuiz implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /** Two stems whose first N chars match are near-duplicates (padding, not assessment). */
    private const DUPLICATE_STEM_PREFIX = 40;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('scenes')->findOrFail($this->lessonId);

        // Idempotent: a retry, a manual re-run, or a re-queued batch ->then() must regenerate
        // the question set, not append duplicates on top of the previous run.
        QuizQuestion::where('lesson_id', $lesson->id)->delete();

        // One validation retry: reject question sets with uncovered objectives, duplicate
        // stems, or malformed entries before they ever reach a student.
        $questions = [];
        foreach ([1, 2] as $attempt) {
            $result = $llm->json(
                system: QuizPrompt::system($lesson),
                user:   QuizPrompt::user($lesson),
            );

            $questions = $this->validQuestions($result['questions'] ?? []);
            $problems = $this->problems($lesson, $questions);

            if ($problems === []) {
                break;
            }

            Log::warning("GenerateLessonQuiz #{$lesson->id}: attempt {$attempt} rejected", ['problems' => $problems]);
        }

        if ($questions === []) {
            throw new \RuntimeException('Quiz generation produced no valid questions after retry.');
        }

        foreach (array_values($questions) as $index => $q) {
            QuizQuestion::create([
                'lesson_id'     => $lesson->id,
                'order'         => $index + 1,
                'question'      => (string) $q['question'],
                'options'       => $q['options'],
                'correct_index' => (int) $q['correct_index'],
                'explanation'   => isset($q['explanation']) ? (string) $q['explanation'] : null,
            ]);
        }

        $lesson->update(['status' => LessonStatus::ScenesReady]);
    }

    /**
     * Drop structurally broken entries (wrong option count, out-of-range answer, empty stem).
     *
     * @return list<array<string, mixed>>
     */
    private function validQuestions(array $raw): array
    {
        return array_values(array_filter($raw, function ($q): bool {
            return is_array($q)
                && trim((string) ($q['question'] ?? '')) !== ''
                && is_array($q['options'] ?? null)
                && count($q['options']) === 4
                && is_numeric($q['correct_index'] ?? null)
                && (int) $q['correct_index'] >= 0
                && (int) $q['correct_index'] <= 3;
        }));
    }

    /**
     * Pedagogical validation: every outline objective covered, no near-duplicate stems.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return list<string>
     */
    private function problems(Lesson $lesson, array $questions): array
    {
        $problems = [];

        if ($questions === []) {
            return ['no structurally valid questions'];
        }

        $objectiveIds = collect($lesson->outline['learning_objectives'] ?? [])
            ->pluck('id')->filter()->all();
        if ($objectiveIds !== []) {
            $covered = collect($questions)->pluck('objective_id')->filter()->unique()->all();
            $uncovered = array_values(array_diff($objectiveIds, $covered));
            if ($uncovered !== []) {
                $problems[] = 'objectives not tested: '.implode(', ', $uncovered);
            }
        }

        $stems = collect($questions)
            ->map(fn (array $q) => Str::lower(Str::substr(trim((string) $q['question']), 0, self::DUPLICATE_STEM_PREFIX)));
        if ($stems->count() !== $stems->unique()->count()) {
            $problems[] = 'near-duplicate question stems';
        }

        return $problems;
    }
}
