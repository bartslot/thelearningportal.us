<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Avatar;
use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Services\CanonThemeCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    private const LESSON_LIMIT = 60;

    private const RESULT_DAYS = 30;

    private const CHART_DAYS = 14;

    public function __invoke(Request $request, CanonThemeCatalog $catalog): View
    {
        $teacher = $request->user();

        $lessonQuery = Lesson::query()->where('teacher_id', $teacher->id);
        $lessonCount = (clone $lessonQuery)->count();
        $lessons = (clone $lessonQuery)
            ->with(['source', 'firstScene'])
            ->latest()
            ->limit(self::LESSON_LIMIT)
            ->get();

        $classCount = Classroom::query()
            ->where('teacher_id', $teacher->id)
            ->where('is_active', true)
            ->count();

        $classrooms = Classroom::query()
            ->where('teacher_id', $teacher->id)
            ->where('is_active', true)
            ->withCount(['members', 'lessons'])
            ->latest('updated_at')
            ->limit(3)
            ->get();

        $scores = QuizScore::query()
            ->whereHas('lesson', fn ($query) => $query->where('teacher_id', $teacher->id))
            ->where('created_at', '>=', now()->startOfDay()->subDays(self::RESULT_DAYS - 1))
            ->with('lesson:id,title,topic')
            ->get([
                'id',
                'lesson_id',
                'classroom_member_id',
                'nickname',
                'correct',
                'total',
                'created_at',
            ]);

        return view('teacher.dashboard', [
            'classCount' => $classCount,
            'classResults' => $this->classResults($classrooms, $scores),
            'classrooms' => $classrooms,
            'lessonCount' => $lessonCount,
            'lessonLimit' => self::LESSON_LIMIT,
            'narrator' => Avatar::active()->first(),
            'results' => $this->resultSummary($scores),
            'themeGroups' => $catalog->groupLessons($lessons),
        ]);
    }

    /**
     * @param  Collection<int, Classroom>  $classrooms
     * @param  Collection<int, QuizScore>  $scores
     * @return array<int, array{attempts:int, average:int}>
     */
    private function classResults(Collection $classrooms, Collection $scores): array
    {
        if ($classrooms->isEmpty() || $scores->isEmpty()) {
            return [];
        }

        $memberClassIds = ClassroomMember::query()
            ->whereIn('classroom_id', $classrooms->pluck('id'))
            ->pluck('classroom_id', 'id');

        return $scores
            ->filter(fn (QuizScore $score): bool => $memberClassIds->has($score->classroom_member_id))
            ->groupBy(fn (QuizScore $score): int => (int) $memberClassIds->get($score->classroom_member_id))
            ->map(fn (Collection $classScores): array => [
                'attempts' => $classScores->count(),
                'average' => $this->correctRate($classScores),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, QuizScore>  $scores
     * @return array{
     *     attempts:int,
     *     average:int,
     *     needs_attention:int,
     *     has_activity:bool,
     *     chart_points:string,
     *     chart_area:string,
     *     chart_labels:list<string>,
     *     recent_lessons:list<array{lesson:Lesson,attempts:int,average:int}>
     * }
     */
    private function resultSummary(Collection $scores): array
    {
        $chartStart = CarbonImmutable::today()->subDays(self::CHART_DAYS - 1);
        $scoresByDay = $scores->groupBy(
            fn (QuizScore $score): string => $score->created_at->toDateString(),
        );

        $days = collect(range(0, self::CHART_DAYS - 1))->map(function (int $offset) use ($chartStart, $scoresByDay): array {
            $day = $chartStart->addDays($offset);
            $dayScores = $scoresByDay->get($day->toDateString(), collect());

            return [
                'date' => $day,
                'average' => $this->correctRate($dayScores),
            ];
        });

        $points = $days->values()->map(function (array $day, int $index): string {
            $x = 4 + ($index * (92 / max(1, self::CHART_DAYS - 1)));
            $y = 38 - (($day['average'] / 100) * 32);

            return sprintf('%.2f,%.2f', $x, $y);
        })->implode(' ');

        $recentLessons = $scores
            ->groupBy('lesson_id')
            ->map(function (Collection $lessonScores): ?array {
                $lesson = $lessonScores->first()?->lesson;
                if (! $lesson) {
                    return null;
                }

                return [
                    'lesson' => $lesson,
                    'attempts' => $lessonScores->count(),
                    'average' => $this->correctRate($lessonScores),
                ];
            })
            ->filter()
            ->sortByDesc('attempts')
            ->take(3)
            ->values()
            ->all();

        return [
            'attempts' => $scores->count(),
            'average' => $this->correctRate($scores),
            'needs_attention' => $scores
                ->filter(fn (QuizScore $score): bool => $score->total > 0 && ($score->correct / $score->total) < 0.5)
                ->count(),
            'has_activity' => $scores->isNotEmpty(),
            'chart_points' => $points,
            'chart_area' => "4,38 {$points} 96,38",
            'chart_labels' => [
                $days->first()['date']->translatedFormat('M j'),
                $days->get((int) floor(self::CHART_DAYS / 2))['date']->translatedFormat('M j'),
                $days->last()['date']->translatedFormat('M j'),
            ],
            'recent_lessons' => $recentLessons,
        ];
    }


    /** @param  Collection<int, QuizScore>  $scores */
    private function correctRate(Collection $scores): int
    {
        $total = (int) $scores->sum('total');

        return $total > 0
            ? (int) round(((int) $scores->sum('correct') / $total) * 100)
            : 0;
    }
}
