<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\CanonThemeCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /teacher/lessons — the teacher's full lesson library as theme-grouped cards.
 * The dashboard (/teacher) shows the same cards as one section among stats;
 * this page is the dedicated "Lessons" destination in the main nav.
 */
final class LessonIndexController extends Controller
{
    private const LESSON_LIMIT = 200;

    public function __invoke(Request $request, CanonThemeCatalog $catalog): View
    {
        $lessonQuery = Lesson::query()->ownedByCurrentUser();
        $lessonCount = (clone $lessonQuery)->count();
        $lessons = (clone $lessonQuery)
            ->with(['source', 'firstScene'])
            ->latest()
            ->limit(self::LESSON_LIMIT)
            ->get();

        return view('teacher.lessons', [
            'lessonCount' => $lessonCount,
            'lessonLimit' => self::LESSON_LIMIT,
            'shelves' => $catalog->placeShelves($lessons),
        ]);
    }
}
