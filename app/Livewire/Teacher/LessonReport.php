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

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
    }

    private function results(): LessonResults
    {
        return new LessonResults(
            $this->lesson,
            $this->classroomId,
            $this->range === 'all' ? null : now()->subDays((int) $this->range),
            null,
        );
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

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $players = $this->results()->players();
        $filename = 'results-'.$this->lesson->lesson_code.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($players): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'score', 'correct', 'total', 'correct_pct', 'needs_help', 'source', 'played_at']);
            foreach ($players as $row) {
                fputcsv($out, [
                    $row['name'], $row['score'], $row['correct'], $row['total'],
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
