<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\QuizQuestion;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;

/**
 * Quiz-question editing for the scene configurator's Quiz inspector.
 *
 * Questions are lesson-level (the quiz_questions table has no per-scene split), so the draft mirrors
 * the lesson's whole pool. Each question carries up to four options (A/B/C/D) and a 0-based
 * correct_index — the exact shape QuizPrompt writes and StudentLessonController reads. The teacher
 * previously only saw the intro narration script here, never the questions; this makes them editable.
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

    /** Pull the lesson's quiz questions into the draft, padded to four option slots each. */
    public function loadQuizDraft(): void
    {
        $this->quizErrors = [];
        $this->quizSaved = false;
        $this->quizDraft = $this->lesson->quizQuestions()->orderBy('order')->get()
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
        $this->quizSaved = false;
    }

    public function setQuizCorrect(int $index, int $optionIndex): void
    {
        if (array_key_exists($index, $this->quizDraft) && $optionIndex >= 0 && $optionIndex < self::QUIZ_OPTION_COUNT) {
            $this->quizDraft[$index]['correct_index'] = $optionIndex;
            $this->quizSaved = false;
        }
    }

    /**
     * Persist the draft to quiz_questions. Each question needs a prompt, at least two filled options,
     * and a correct answer that points at a filled option. Empty option slots are dropped and the
     * correct index is remapped to its position in the trimmed list, so saved data is always clean
     * (no blank answer tiles, no dangling correct_index). Replaces the lesson's pool in one
     * transaction — the same delete-then-recreate the generator uses, keeping `order` canonical.
     */
    public function saveQuiz(): void
    {
        $this->quizErrors = [];
        $clean = [];

        foreach ($this->quizDraft as $i => $q) {
            $question = trim((string) ($q['question'] ?? ''));
            $rawOptions = array_map(fn ($o) => trim((string) $o), $q['options'] ?? []);
            $correct = (int) ($q['correct_index'] ?? 0);
            $correctValue = $rawOptions[$correct] ?? '';
            $filled = array_values(array_filter($rawOptions, fn ($o) => $o !== ''));

            $errors = [];
            if ($question === '') {
                $errors[] = 'Question text is required.';
            }
            if (count($filled) < 2) {
                $errors[] = 'Add at least two answer options.';
            }
            if ($correctValue === '') {
                $errors[] = 'Pick which option is the correct answer.';
            }
            if ($errors !== []) {
                $this->quizErrors[$i] = $errors;

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

        if ($this->quizErrors !== []) {
            $this->quizSaved = false;

            return;
        }

        DB::transaction(function () use ($clean) {
            $this->lesson->quizQuestions()->delete();
            foreach ($clean as $order => $row) {
                $this->lesson->quizQuestions()->create([
                    'order' => $order,
                    'question' => $row['question'],
                    'options' => $row['options'],
                    'correct_index' => $row['correct_index'],
                    'explanation' => $row['explanation'],
                    'points' => 10,
                ]);
            }
        });

        $this->loadQuizDraft();
        $this->quizSaved = true;
        $this->dispatch('toast', message: 'Quiz questions saved.', type: 'success');
    }

    /** Pad/truncate an options list to exactly four slots so the editor always shows A/B/C/D. */
    private function padOptions(array $options): array
    {
        $options = array_values(array_map(fn ($o) => (string) $o, $options));

        return array_slice(array_pad($options, self::QUIZ_OPTION_COUNT, ''), 0, self::QUIZ_OPTION_COUNT);
    }
}
