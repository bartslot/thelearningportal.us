<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\LessonSharing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Share a lesson with every teacher, or take a copy of one somebody shared.
 *
 * Both actions look the lesson up UNSCOPED and let LessonSharing decide, because the two have
 * different rules: you may only share your own, but you may copy anything shared with you. Scoping
 * the query would answer one of those questions with the other one's rule.
 */
final class LessonSharingController extends Controller
{
    public function __construct(private readonly LessonSharing $sharing) {}

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        $request->validate(['is_public' => ['required', 'boolean']]);

        try {
            $this->sharing->setPublic($lesson, $request->user(), $request->boolean('is_public'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $request->boolean('is_public')
            ? __('This lesson is now shared with every teacher.')
            : __('This lesson is private again.'));
    }

    public function copy(Request $request, Lesson $lesson): RedirectResponse
    {
        try {
            $copy = $this->sharing->copyToLibrary($lesson, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Straight into the editor: somebody copies a lesson in order to change it, and landing
        // back on the library means finding it again first.
        return redirect()
            ->route('teacher.lessons.wizard', ['lesson' => $copy->id, 'step' => 3])
            ->with('status', __('Copied to your lessons. Changes here will not affect the original.'));
    }
}
