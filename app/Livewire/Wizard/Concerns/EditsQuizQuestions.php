<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\QuizQuestion;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;

/**
 * Quiz-question editing for the scene configurator's Quiz inspector.
 *
 * Questions belong to their quiz scene (quiz_questions.scene_id) because lessons are
 * chronological: each segment may only test the narration BEFORE it. The draft mirrors the
 * selected segment's set (legacy scene-less pools still load). Each question carries up to
 * four options (A/B/C/D) and a 0-based correct_index — the shape QuizPrompt writes and
 * StudentLessonController reads.
 */
trait EditsQuizQuestions
{
    /** @var array<int,array{id:?int,question:string,options:array<int,string>,correct_index:int,explanation:string}> */
    public array $quizDraft = [];

    /** Per-question validation messages, keyed by draft index. */
    public array $quizErrors = [];

    public bool $quizSaved = false;

    /** The quiz scene the draft was last loaded for — guards against status-poll re-selects wiping edits. */
    public ?int $quizDraftSceneId = null;

    private const QUIZ_OPTION_COUNT = 4;     // A/B/C/D — matches QuizPrompt + the player
    private const QUIZ_MAX_QUESTIONS = 12;

    /**
     * Load (or keep) the editable draft for the scene being opened. Only reloads from the DB when the
     * teacher switches to a different scene — a status-change re-select of the same quiz scene keeps
     * any unsaved edits intact. Clears the draft for non-quiz scenes.
     */
    public function syncQuizDraftFor(Scene $scene): void
    {
        $isQuiz = $scene->kind === 'game' && ($scene->game_type ?? null) === 'quiz';

        if (! $isQuiz) {
            $this->quizDraftSceneId = null;
            $this->quizDraft = [];
            $this->quizErrors = [];

            return;
        }

        if ($this->quizDraftSceneId === $scene->id && $this->quizDraft !== []) {
            return;
        }

        $this->quizDraftSceneId = $scene->id;
        $this->loadQuizDraft();
    }

    /**
     * Pull THIS quiz scene's questions into the draft (chronology-scoped per segment),
     * padded to four option slots each. Legacy lessons keep a scene-less pool.
     */
    public function loadQuizDraft(): void
    {
        $this->quizErrors = [];
        $this->quizSaved = false;
        $query = $this->lesson->quizQuestions();
        if ($this->quizDraftSceneId) {
            $scoped = (clone $query)->where('scene_id', $this->quizDraftSceneId);
            $query = $scoped->exists() ? $scoped : $query->whereNull('scene_id');
        }
        $this->quizDraft = $query->orderBy('order')->get()
            ->map(fn (QuizQuestion $q) => [
                'id' => $q->id,
                'question' => (string) $q->question,
                'options' => $this->padOptions(is_array($q->options) ? $q->options : []),
                'correct_index' => (int) $q->correct_index,
                'explanation' => (string) ($q->explanation ?? ''),
            ])->values()->all();
    }

    public function addQuizQuestion(): void
    {
        if (count($this->quizDraft) >= self::QUIZ_MAX_QUESTIONS) {
            return;
        }
        $this->quizSaved = false;
        $this->quizDraft[] = [
            'id' => null,
            'question' => '',
            'options' => array_fill(0, self::QUIZ_OPTION_COUNT, ''),
            'correct_index' => 0,
            'explanation' => '',
        ];
    }

    public function removeQuizQuestion(int $index): void
    {
        if (! array_key_exists($index, $this->quizDraft)) {
            return;
        }
        unset($this->quizDraft[$index]);
        $this->quizDraft = array_values($this->quizDraft);
        $this->autosaveQuiz();
    }

    /** Current quiz difficulty (1-3 stars) for the inspector UI. */
    public function quizDifficulty(): int
    {
        $scene = $this->quizDraftSceneId ? Scene::find($this->quizDraftSceneId) : null;

        return (int) (($scene?->config['quiz_difficulty'] ?? null) ?: \App\Services\QuizPrompt::DIFFICULTY_MEDIUM);
    }

    /**
     * Set the quiz difficulty (1-3 stars) on the quiz scene. Takes effect on the next
     * generation — per-question sparkles or "Regenerate all".
     */
    public function setQuizDifficulty(int $level): void
    {
        if (! $this->quizDraftSceneId || $level < 1 || $level > 3) {
            return;
        }

        $scene = Scene::find($this->quizDraftSceneId);
        if ($scene) {
            $scene->update(['config' => array_merge($scene->config ?? [], ['quiz_difficulty' => $level])]);
        }
    }

    /**
     * Regenerate the WHOLE question set at the current difficulty via the same job the
     * pipeline uses (objective coverage + duplicate-stem validation included), then reload
     * the draft. Runs inline — the teacher watches a spinner, not a queue.
     */
    public function regenerateAllQuizQuestions(): void
    {
        try {
            (new \App\Jobs\GenerateLessonQuiz($this->lesson->id))
                ->handle(app(\App\Services\OpenAiLlmService::class));
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Quiz regeneration failed — try again.', type: 'error');

            return;
        }

        $this->loadQuizDraft();
        $this->dispatch('toast', message: 'Quiz regenerated.', type: 'success');
    }

    /**
     * AI-draft one question: question + linked correct answer + three plausible distractors,
     * generated as ONE unit. If the teacher already typed a question, its intent is kept.
     * The correct answer lands in a random slot; the teacher can reorder but never repoint
     * correctness — regenerating the question is the only way to change the correct answer.
     */
    public function generateQuizQuestion(int $index): void
    {
        if (! array_key_exists($index, $this->quizDraft)) {
            return;
        }

        $existing = collect($this->quizDraft)
            ->reject(fn ($q, $i) => $i === $index)
            ->pluck('question')
            ->filter()
            ->values()
            ->all();

        try {
            $result = app(\App\Services\OpenAiLlmService::class)->json(
                system: \App\Services\QuizQuestionDraftPrompt::questionSystem($this->lesson),
                user: \App\Services\QuizQuestionDraftPrompt::questionUser(
                    $this->lesson->load('scenes'),
                    $existing,
                    (string) ($this->quizDraft[$index]['question'] ?? ''),
                    $this->taughtScenesForQuiz(),
                ),
            );
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Question generation failed — try again.', type: 'error');

            return;
        }

        $question = trim((string) ($result['question'] ?? ''));
        $correct = trim((string) ($result['correct_answer'] ?? ''));
        $distractors = array_values(array_filter(array_map(
            fn ($d) => trim((string) $d),
            (array) ($result['distractors'] ?? []),
        )));

        if ($question === '' || $correct === '' || count($distractors) < self::QUIZ_OPTION_COUNT - 1) {
            $this->dispatch('toast', message: 'Question generation came back incomplete — try again.', type: 'error');

            return;
        }

        $correctSlot = random_int(0, self::QUIZ_OPTION_COUNT - 1);
        $options = array_slice($distractors, 0, self::QUIZ_OPTION_COUNT - 1);
        array_splice($options, $correctSlot, 0, [$correct]);

        $this->quizDraft[$index] = [
            'id' => $this->quizDraft[$index]['id'] ?? null,
            'question' => $question,
            'options' => $this->padOptions($options),
            'correct_index' => $correctSlot,
            'explanation' => trim((string) ($result['explanation'] ?? '')),
        ];
        $this->autosaveQuiz();
    }

    /**
     * AI-redraw ONE plausible wrong answer. The correct answer is never redrawable on its
     * own — it only changes together with the question (generateQuizQuestion).
     */
    public function regenerateQuizOption(int $index, int $optionIndex): void
    {
        if (! array_key_exists($index, $this->quizDraft)
            || $optionIndex < 0 || $optionIndex >= self::QUIZ_OPTION_COUNT
            || $optionIndex === (int) $this->quizDraft[$index]['correct_index']) {
            return;
        }

        $draft = $this->quizDraft[$index];
        $question = trim((string) $draft['question']);
        $correct = trim((string) ($draft['options'][$draft['correct_index']] ?? ''));
        if ($question === '' || $correct === '') {
            $this->dispatch('toast', message: 'Generate the question first — distractors need it.', type: 'warning');

            return;
        }

        $others = collect($draft['options'])->forget($optionIndex)->filter()->values()->all();

        try {
            $result = app(\App\Services\OpenAiLlmService::class)->json(
                system: \App\Services\QuizQuestionDraftPrompt::distractorSystem($this->lesson),
                user: \App\Services\QuizQuestionDraftPrompt::distractorUser($this->lesson, $question, $correct, $others),
            );
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Answer generation failed — try again.', type: 'error');

            return;
        }

        $distractor = trim((string) ($result['distractor'] ?? ''));
        if ($distractor !== '') {
            $this->quizDraft[$index]['options'][$optionIndex] = $distractor;
            $this->autosaveQuiz();
        }
    }

    /**
     * Drag-reorder answers (A/B/C/D). The correct answer MOVES but its correctness is
     * pinned — correct_index follows the option to its new slot.
     */
    public function moveQuizOption(int $index, int $from, int $to): void
    {
        $max = self::QUIZ_OPTION_COUNT - 1;
        if (! array_key_exists($index, $this->quizDraft)
            || $from < 0 || $from > $max || $to < 0 || $to > $max || $from === $to) {
            return;
        }

        $options = $this->quizDraft[$index]['options'];
        $moved = $options[$from];
        array_splice($options, $from, 1);
        array_splice($options, $to, 0, [$moved]);

        $correct = (int) $this->quizDraft[$index]['correct_index'];
        if ($correct === $from) {
            $correct = $to;
        } elseif ($from < $correct && $to >= $correct) {
            $correct--;
        } elseif ($from > $correct && $to <= $correct) {
            $correct++;
        }

        $this->quizDraft[$index]['options'] = $options;
        $this->quizDraft[$index]['correct_index'] = $correct;
        $this->autosaveQuiz();
    }

    /** Livewire hook: any wire:model edit inside the draft autosaves — no Save button. */
    public function updatedQuizDraft(): void
    {
        $this->autosaveQuiz();
    }

    /**
     * Autosave: persist every COMPLETE question (prompt + ≥2 filled options + a filled correct
     * answer), silently keep incomplete ones in the draft until they're finished. Empty option
     * slots are dropped and the correct index remapped, so saved data is always clean. Replaces
     * the lesson's pool in one transaction — the same delete-then-recreate the generator uses.
     */
    public function autosaveQuiz(): void
    {
        $this->quizErrors = [];
        $clean = [];

        foreach ($this->quizDraft as $i => $q) {
            $question = trim((string) ($q['question'] ?? ''));
            $rawOptions = array_map(fn ($o) => trim((string) $o), $q['options'] ?? []);
            $correct = (int) ($q['correct_index'] ?? 0);
            $correctValue = $rawOptions[$correct] ?? '';
            $filled = array_values(array_filter($rawOptions, fn ($o) => $o !== ''));

            $isUntouched = $question === '' && $filled === [];
            $isComplete = $question !== '' && count($filled) >= 2 && $correctValue !== '';

            if (! $isComplete) {
                // Only nag about questions the teacher has actually started.
                if (! $isUntouched) {
                    $errors = [];
                    if ($question === '') {
                        $errors[] = 'Question text is required.';
                    }
                    if (count($filled) < 2) {
                        $errors[] = 'Add at least two answer options.';
                    }
                    if ($correctValue === '') {
                        $errors[] = 'The correct answer field is empty.';
                    }
                    $this->quizErrors[$i] = $errors;
                }

                continue;
            }

            $newCorrect = array_search($correctValue, $filled, true);
            $clean[] = [
                'question' => $question,
                'options' => $filled,
                'correct_index' => $newCorrect === false ? 0 : (int) $newCorrect,
                'explanation' => trim((string) ($q['explanation'] ?? '')) ?: null,
            ];
        }

        DB::transaction(function () use ($clean) {
            // Replace only THIS segment's questions — other quiz scenes keep theirs.
            $this->lesson->quizQuestions()
                ->when($this->quizDraftSceneId,
                    fn ($q) => $q->where(fn ($w) => $w->where('scene_id', $this->quizDraftSceneId)->orWhereNull('scene_id')))
                ->delete();
            foreach ($clean as $order => $row) {
                $this->lesson->quizQuestions()->create([
                    'scene_id' => $this->quizDraftSceneId,
                    'order' => $order,
                    'question' => $row['question'],
                    'options' => $row['options'],
                    'correct_index' => $row['correct_index'],
                    'explanation' => $row['explanation'],
                    'points' => 10,
                ]);
            }
        });

        // No draft reload: reloading would clobber half-typed questions mid-edit.
        $this->quizSaved = true;
    }

    /** @deprecated Autosave replaced the manual save — kept for any stray callers. */
    public function saveQuiz(): void
    {
        $this->autosaveQuiz();
    }

    /**
     * The narration the student has heard BEFORE this quiz segment — the only material a
     * question may test. Null when no quiz scene is selected (fall back to the whole lesson).
     */
    private function taughtScenesForQuiz(): ?\Illuminate\Support\Collection
    {
        $quizScene = $this->quizDraftSceneId ? Scene::find($this->quizDraftSceneId) : null;
        if (! $quizScene) {
            return null;
        }

        return $this->lesson->scenes
            ->filter(fn (Scene $scene) => $scene->kind === 'narration' && $scene->order < $quizScene->order)
            ->sortBy('order')
            ->values();
    }

    /** Pad/truncate an options list to exactly four slots so the editor always shows A/B/C/D. */
    private function padOptions(array $options): array
    {
        $options = array_values(array_map(fn ($o) => (string) $o, $options));

        return array_slice(array_pad($options, self::QUIZ_OPTION_COUNT, ''), 0, self::QUIZ_OPTION_COUNT);
    }
}
