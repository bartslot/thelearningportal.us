<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The public, indexable side of the lesson library.
 *
 * The player at /lesson/{code} is an application, not a page a search engine can read: it is one
 * JavaScript stage with the content inside a payload. These two pages are the readable counterpart —
 * a browsable index and one page per lesson with real text, so a teacher searching for "les over
 * Anne Frank" can land on something.
 */
class LessonCatalogueController extends Controller
{
    /** How long the public listings are cached. Any lesson save busts it (see Lesson::booted). */
    private const CACHE_MINUTES = 30;

    public function index(Request $request): View
    {
        $lessons = Cache::remember(
            'public.lessons.'.app()->getLocale(),
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => Seo::publicLessons()
                ->with('firstScene')
                ->latest()
                ->take(60)
                ->get(),
        );

        return view('marketing.lessons.index', [
            'lessons' => $lessons,
            'grades' => $lessons->pluck('grade_level')->filter()->unique()->sort()->values(),
        ]);
    }

    public function show(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        // Read the slug by name, not as a method argument: the localised route is
        // {locale}/history-lessons/{slug} and Laravel hands controller arguments over in URI order,
        // so a positional $slug would arrive holding "nl".
        $slug = (string) $request->route('slug');

        $lesson = Seo::publicLessons()
            ->where('lesson_code', Seo::codeFromSlug($slug))
            ->firstOrFail();

        // One canonical URL per lesson: a stale or hand-typed slug redirects rather than competing
        // with itself in the index.
        $canonicalSlug = Seo::lessonSlug($lesson);

        if ($slug !== $canonicalSlug) {
            return redirect()->to(Seo::url('lesson', ['slug' => $canonicalSlug]), 301);
        }

        $lesson->load(['scenes' => fn ($q) => $q->orderBy('order')]);

        return view('marketing.lessons.show', [
            'lesson' => $lesson,
            'slug' => $canonicalSlug,
            'chapters' => $lesson->scenes
                ->pluck('chapter_name')
                ->filter()
                ->unique()
                ->values(),
            'related' => Seo::publicLessons()
                ->whereKeyNot($lesson->getKey())
                ->when($lesson->grade_level, fn ($q, $grade) => $q->where('grade_level', $grade))
                ->latest()
                ->take(3)
                ->get(),
        ]);
    }
}
