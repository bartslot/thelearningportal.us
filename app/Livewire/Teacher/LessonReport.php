<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\Lesson;
use App\Services\LessonResults;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LessonReport extends Component
{
    public Lesson $lesson;

    public string $tab = 'overview';           // overview | questions | players

    public ?int $classroomId = null;           // filter

    public string $range = '30';               // days: 7 | 30 | 90 | all

    public ?int $openScoreId = null;           // players tab drill-down

    private ?LessonResults $resultsCache = null;

    private ?string $resultsCacheKey = null;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
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

    public function requiz(): void
    {
        // Implemented in the re-quiz task; button hidden behind difficult-question presence.
        $this->dispatch('toast', message: 'Re-quiz coming in the next task.', type: 'info');
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

    public function render()
    {
        return view('livewire.teacher.lesson-report')
            ->layout('components.layouts.app', ['title' => 'Results — '.($this->lesson->title ?? $this->lesson->topic)]);
    }
}
