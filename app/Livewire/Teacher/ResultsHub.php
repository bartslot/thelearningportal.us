<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\QuizScore;
use App\Services\LessonResults;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ResultsHub extends Component
{
    public string $range = '30';    // days: 7 | 30 | 90 | all

    public ?int $lessonId = null;   // filter to one lesson

    /**
     * Recent activity: one row per lesson per calendar day (spec). needs_help is
     * computed with the same LessonResults rule so numbers match the report page.
     */
    #[Computed]
    public function activity(): array
    {
        $rows = QuizScore::query()
            ->select([
                'lesson_id',
                DB::raw('DATE(quiz_scores.created_at) as day'),
                DB::raw('COUNT(*) as players'),
            ])
            ->whereHas('lesson', fn ($q) => $q->where('teacher_id', auth()->id()))
            ->when($this->lessonId, fn ($q) => $q->where('lesson_id', $this->lessonId))
            ->when($this->range !== 'all', fn ($q) => $q->where('quiz_scores.created_at', '>=', now()->subDays((int) $this->range)))
            ->groupBy('lesson_id', 'day')
            ->orderByDesc('day')
            ->with('lesson')   // note: with() on aggregate needs lesson_id in select — it is
            ->get();

        return $rows->map(function ($row) {
            $results = new LessonResults(
                $row->lesson,
                null,
                \Carbon\Carbon::parse($row->day)->startOfDay(),
                \Carbon\Carbon::parse($row->day)->endOfDay(),
            );
            $overview = $results->overview();

            return [
                'lesson' => $row->lesson,
                'day' => $row->day,
                'players' => (int) $row->players,
                'avg_correct_pct' => $overview['avg_correct_pct'],
                'needs_help' => count($overview['needs_help']),
            ];
        })->all();
    }

    public function render()
    {
        return view('livewire.teacher.results-hub')
            ->layout('components.layouts.app', ['title' => 'Results']);
    }
}
