<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Jobs\GenerateLessonQuiz;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\Scene;
use App\Services\LessonResults;
use App\Services\OpenAiLlmService;
use App\Services\PaperSheetExtractor;
use App\Services\Support\NameMatcher;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class LessonReport extends Component
{
    use WithFileUploads;

    public Lesson $lesson;

    public string $tab = 'overview';           // overview | questions | players

    public ?int $classroomId = null;           // filter

    public string $range = '30';               // days: 7 | 30 | 90 | all

    public ?int $openScoreId = null;           // players tab drill-down

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $paperPhotos = [];

    /** @var list<array{raw_name: string, matched_name: ?string, answers: list<?string>}> */
    public array $paperRows = [];

    public bool $paperModalOpen = false;

    private ?LessonResults $resultsCache = null;

    private ?string $resultsCacheKey = null;

    public function mount(Lesson $lesson): void
    {
        abort_unless(auth()->user()?->canManage($lesson), 403);
        $this->lesson = $lesson;
    }

    private function results(): LessonResults
    {
        $key = $this->classroomId.'|'.$this->range;
        if ($this->resultsCache === null || $this->resultsCacheKey !== $key) {
            $this->resultsCache = new LessonResults(
                $this->lesson,
                $this->classroomId,
                $this->range === 'all' ? null : now()->subDays((int) $this->range),
                null,
            );
            $this->resultsCacheKey = $key;
        }

        return $this->resultsCache;
    }

    #[Computed]
    public function overview(): array
    {
        return $this->results()->overview();
    }

    #[Computed]
    public function questionBreakdown(): array
    {
        return $this->results()->questionBreakdown();
    }

    #[Computed]
    public function difficult(): array
    {
        return $this->results()->difficultQuestions();
    }

    #[Computed]
    public function players(): array
    {
        return $this->results()->players();
    }

    #[Computed]
    public function drilldown(): array
    {
        return $this->openScoreId ? $this->results()->drilldown($this->openScoreId) : [];
    }

    #[Computed]
    public function classrooms()
    {
        return $this->lesson->classrooms()->get();
    }

    public function openPlayer(int $scoreId): void
    {
        $this->openScoreId = $this->openScoreId === $scoreId ? null : $scoreId;
    }

    public function viewPlayer(int $scoreId): void
    {
        $this->tab = 'players';
        $this->openScoreId = $scoreId;
    }

    public function requiz(): void
    {
        $difficult = collect($this->results()->difficultQuestions())
            ->reject(fn (array $q) => $q['asks_ahead'])
            ->pluck('question_text')->take(8)->all();

        if ($difficult === []) {
            $this->dispatch('toast', message: __('No difficult questions to re-quiz.'), type: 'info');

            return;
        }

        // Append a NEW quiz scene at the end — the original segment and results stay untouched.
        $lastOrder = (int) $this->lesson->scenes()->max('order');
        $scene = Scene::create([
            'lesson_id' => $this->lesson->id,
            'order' => $lastOrder + 1,
            'kind' => 'game',
            'game_type' => 'quiz',
            'quiz_question_count' => count($difficult),
            'quiz_timing' => 'after',
            'status' => 'ready',
            'config' => ['quiz_scope' => 'taught', 'requiz' => true],
        ]);

        try {
            (new GenerateLessonQuiz($this->lesson->id, $difficult, $scene->id))
                ->handle(app(OpenAiLlmService::class));
        } catch (\Throwable $e) {
            $scene->delete();
            $this->dispatch('toast', message: __('We could not rebuild the quiz. Try again.'), type: 'error');

            return;
        }

        $this->dispatch('toast', message: __('Re-quiz added to the end of the lesson.'), type: 'success');
    }

    /** Neutralize spreadsheet formula-injection: a leading =,+,-,@ makes Excel/Sheets execute the cell. */
    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $players = $this->results()->players();
        $filename = 'results-'.$this->lesson->lesson_code.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($players): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'score', 'correct', 'total', 'correct_pct', 'needs_help', 'source', 'played_at']);
            foreach ($players as $row) {
                fputcsv($out, [
                    self::csvSafe((string) $row['name']), $row['score'], $row['correct'], $row['total'],
                    $row['pct'], $row['needs_help'] ? 'yes' : 'no', $row['source'], $row['played_at'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function extractPaper(): void
    {
        // Cap photos: each is a synchronous vision call; a class scan is a handful of photos, not dozens.
        $this->validate([
            'paperPhotos' => ['array', 'max:20'],
            'paperPhotos.*' => ['image', 'max:10240'],
        ]);

        $questions = $this->paperQuestions();
        if ($questions->isEmpty()) {
            $this->dispatch('toast', message: __('This lesson has no quiz questions.'), type: 'warning');

            return;
        }

        $dataUrls = array_map(
            fn ($photo) => 'data:'.$photo->getMimeType().';base64,'.base64_encode($photo->get()),
            $this->paperPhotos,
        );

        $this->paperRows = app(PaperSheetExtractor::class)
            ->extract($dataUrls, $questions->count(), $this->rosterNames());
        $this->paperModalOpen = true;
    }

    private function paperQuestions(): \Illuminate\Support\Collection
    {
        return $this->lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();
    }

    /** @return list<string> */
    private function rosterNames(): array
    {
        return ClassroomMember::whereIn(
            'classroom_id', $this->lesson->classrooms()->pluck('classrooms.id'),
        )->pluck('display_name')->all();
    }

    public function confirmPaperImport(): void
    {
        $rows = $this->paperRows;
        $this->paperRows = [];          // clear first: a double-click's second call imports nothing
        if ($rows === []) {
            return;
        }

        $questions = $this->paperQuestions()->values();
        $letters = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
        $memberByName = ClassroomMember::whereIn(
            'classroom_id', $this->lesson->classrooms()->pluck('classrooms.id'),
        )->get()->keyBy(fn ($m) => mb_strtolower($m->display_name));

        foreach ($rows as $row) {
            $name = NameMatcher::canonical(
                (string) ($row['matched_name'] ?: $row['raw_name']),
            );
            if ($name === '') {
                continue;
            }

            $answers = [];
            $correct = 0;
            foreach ($questions as $index => $question) {
                $letter = $row['answers'][$index] ?? null;
                $chosenIndex = $letter !== null ? ($letters[$letter] ?? null) : null;
                $chosenText = $chosenIndex !== null ? (string) ($question->options[$chosenIndex] ?? '') : '';
                $wasCorrect = $chosenIndex !== null && $chosenIndex === (int) $question->correct_index;
                if ($wasCorrect) {
                    $correct++;
                }
                $answers[] = [
                    'quiz_question_id' => $question->id,
                    'question_order' => $index + 1,
                    'question_text' => $question->question,
                    'chosen_text' => $chosenText,
                    'correct_text' => (string) ($question->options[$question->correct_index] ?? ''),
                    'was_correct' => $wasCorrect,
                    'response_ms' => null,
                    'asks_ahead' => (bool) $question->asks_ahead,
                ];
            }

            $score = QuizScore::create([
                'lesson_id' => $this->lesson->id,
                'nickname' => $name,
                'classroom_member_id' => $memberByName[mb_strtolower($name)]->id ?? null,
                'score' => $correct * 10,
                'correct' => $correct,
                'total' => $questions->count(),
                'source' => 'paper',
            ]);
            $score->answers()->createMany($answers);
        }

        $this->paperPhotos = [];
        $this->paperModalOpen = false;
        $this->dispatch('toast', message: __('Paper answers imported.'), type: 'success');
    }

    public function render()
    {
        return view('livewire.teacher.lesson-report')
            ->layout('components.layouts.app', ['title' => 'Results — '.($this->lesson->title ?? $this->lesson->topic)]);
    }
}
