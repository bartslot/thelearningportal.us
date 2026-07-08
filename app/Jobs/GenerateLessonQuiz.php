<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Services\OpenAiLlmService;
use App\Services\QuizPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Chronology-aware quiz generation. Lessons play as a story, so each quiz segment may
 * only test the narration that comes BEFORE it in the timeline — never facts the
 * student hasn't heard yet. Questions are stored per quiz scene (scene_id); lessons
 * without a quiz scene keep a lesson-level pool (scene_id null).
 */
class GenerateLessonQuiz implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 240;

    /** Two stems whose first N chars match are near-duplicates (padding, not assessment). */
    private const DUPLICATE_STEM_PREFIX = 40;

    /**
     * @param  list<string>|null  $reinforceQuestions  difficult-question texts to re-ask (re-quiz)
     * @param  int|null  $onlySceneId  restrict generation to ONE quiz scene (re-quiz target)
     */
    public function __construct(
        public readonly int $lessonId,
        public readonly ?array $reinforceQuestions = null,
        public readonly ?int $onlySceneId = null,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('scenes')->findOrFail($this->lessonId);

        // Idempotent: a retry, a manual re-run, or a re-queued batch ->then() must regenerate
        // the question set, not append duplicates on top of the previous run.
        QuizQuestion::where('lesson_id', $lesson->id)
            ->when($this->onlySceneId, fn ($q) => $q->where('scene_id', $this->onlySceneId))
            ->delete();

        $quizScenes = $lesson->scenes
            ->filter(fn (Scene $scene) => $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz')
            ->sortBy('order')
            ->values();

        if ($this->onlySceneId) {
            $quizScenes = $quizScenes->where('id', $this->onlySceneId)->values();
        }

        if ($quizScenes->isEmpty()) {
            // No quiz segment in the timeline — lesson-level pool from the full story.
            $narration = $lesson->scenes->where('kind', 'narration')->sortBy('order')->values();
            $this->generateSet($llm, $lesson, $narration, $this->objectivesFor($lesson, $narration), null, null);
        } else {
            $previousQuizOrder = null;
            foreach ($quizScenes as $quizScene) {
                // Everything the student has heard by the time this quiz starts.
                $taught = $lesson->scenes
                    ->filter(fn (Scene $scene) => $scene->kind === 'narration' && $scene->order < $quizScene->order)
                    ->sortBy('order')
                    ->values();

                // Teacher override: scope 'full' turns this segment into a prior-knowledge
                // check — upcoming narration is allowed, flagged asks_ahead per question.
                $upcoming = QuizPrompt::scopeFor($quizScene) === 'full'
                    ? $lesson->scenes
                        ->filter(fn (Scene $scene) => $scene->kind === 'narration' && $scene->order > $quizScene->order)
                        ->sortBy('order')
                        ->values()
                    : null;

                if (($taught->isEmpty() || $taught->pluck('script_segment')->filter()->isEmpty())
                    && ($upcoming === null || $upcoming->isEmpty())) {
                    Log::warning("GenerateLessonQuiz #{$lesson->id}: quiz scene {$quizScene->order} has no narration in scope — skipped.");
                    $previousQuizOrder = $quizScene->order;

                    continue;
                }

                // Later segments prefer fresh material but may reinforce earlier content.
                $focus = $previousQuizOrder !== null
                    ? 'This is a later checkpoint: prefer the narration AFTER the previous quiz, '
                      .'but reinforcing earlier material is allowed.'
                    : '';

                if ($this->reinforceQuestions) {
                    $focus .= "\nREINFORCE: the class struggled with these questions — write fresh variants "
                        ."that test the SAME facts with different wording and different distractors:\n- "
                        .implode("\n- ", $this->reinforceQuestions);
                }

                $objectives = $upcoming !== null
                    ? ($lesson->outline['learning_objectives'] ?? [])   // full-lesson scope
                    : $this->objectivesFor($lesson, $taught);

                $this->generateSet(
                    $llm,
                    $lesson,
                    $taught,
                    $objectives,
                    $quizScene,
                    (int) ($quizScene->quiz_question_count ?: QuizPrompt::DEFAULT_QUESTION_COUNT),
                    $focus,
                    $upcoming,
                );

                $previousQuizOrder = $quizScene->order;
            }
        }

        $lesson->update(['status' => LessonStatus::ScenesReady]);
    }

    /**
     * Generate + validate one question set (one validation retry), then persist it.
     *
     * @param  Collection<int, Scene>  $taught
     * @param  list<array<string, mixed>>  $objectives
     */
    private function generateSet(
        OpenAiLlmService $llm,
        Lesson $lesson,
        Collection $taught,
        array $objectives,
        ?Scene $quizScene,
        ?int $count,
        string $focus = '',
        ?Collection $upcoming = null,
    ): void {
        $count ??= max(QuizPrompt::DEFAULT_QUESTION_COUNT, count($objectives));

        $questions = [];
        foreach ([1, 2] as $attempt) {
            $result = $llm->json(
                system: QuizPrompt::system($lesson, $count),
                user:   QuizPrompt::user($lesson, $taught, $objectives, $focus, $upcoming),
            );

            $questions = $this->validQuestions($result['questions'] ?? []);
            $problems = $this->problems($questions, $objectives);

            if ($problems === []) {
                break;
            }

            Log::warning("GenerateLessonQuiz #{$lesson->id}: attempt {$attempt} rejected", [
                'scene' => $quizScene?->order, 'problems' => $problems,
            ]);
        }

        if ($questions === []) {
            throw new \RuntimeException('Quiz generation produced no valid questions after retry.');
        }

        // Persist atomically: a mid-loop create() failure must leave ZERO rows for this scene,
        // never a partial set. Orphan rows (scene_id later nulled by a scene delete) would
        // silently rejoin the legacy lesson-level pool — this transaction makes that impossible.
        DB::transaction(function () use ($questions, $lesson, $quizScene): void {
            foreach (array_values($questions) as $index => $q) {
                QuizQuestion::create([
                    'lesson_id'     => $lesson->id,
                    'scene_id'      => $quizScene?->id,
                    'order'         => $index + 1,
                    'question'      => (string) $q['question'],
                    'options'       => $q['options'],
                    'correct_index' => (int) $q['correct_index'],
                    'asks_ahead'    => (bool) ($q['asks_ahead'] ?? false),
                    'explanation'   => isset($q['explanation']) ? (string) $q['explanation'] : null,
                ]);
            }
        });
    }

    /**
     * The learning objectives actually TAUGHT by the given scenes, resolved through the
     * outline briefs' objective_ids (briefs align with non-map scenes by position). An
     * objective introduced after the quiz must not be demanded by the coverage validator.
     * Falls back to all objectives when briefs carry no objective_ids (older outlines).
     *
     * @param  Collection<int, Scene>  $scenes
     * @return list<array<string, mixed>>
     */
    private function objectivesFor(Lesson $lesson, Collection $scenes): array
    {
        $allObjectives = collect($lesson->outline['learning_objectives'] ?? []);
        if ($allObjectives->isEmpty()) {
            return [];
        }

        $briefs = array_values($lesson->outline['scene_briefs'] ?? []);
        $nonMap = $lesson->scenes->where('kind', '!=', 'map')->sortBy('order')->values();

        $taughtIds = [];
        foreach ($scenes as $scene) {
            $position = $nonMap->search(fn (Scene $candidate) => $candidate->id === $scene->id);
            foreach ((array) ($briefs[$position]['objective_ids'] ?? []) as $objectiveId) {
                $taughtIds[$objectiveId] = true;
            }
        }

        if ($taughtIds === []) {
            return $allObjectives->all();
        }

        return $allObjectives
            ->filter(fn (array $objective) => isset($taughtIds[$objective['id'] ?? '']))
            ->values()
            ->all();
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
     * Pedagogical validation: every TAUGHT objective covered, no near-duplicate stems.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array<string, mixed>>  $objectives
     * @return list<string>
     */
    private function problems(array $questions, array $objectives): array
    {
        $problems = [];

        if ($questions === []) {
            return ['no structurally valid questions'];
        }

        $objectiveIds = collect($objectives)->pluck('id')->filter()->all();
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
