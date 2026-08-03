<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuestDemoSession;
use App\Support\DemoLesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Configure" on the landing page. Drops a visitor with no account straight into the real wizard,
 * editing their own private copy of the demo lesson.
 */
final class GuestDemoController extends Controller
{
    public function __construct(private readonly GuestDemoSession $guests) {}

    public function __invoke(Request $request): RedirectResponse
    {
        // A teacher who is already signed in has no business being handed a throwaway account —
        // that would sign them out of their own. Send them to build a real lesson instead.
        if ($request->user() && ! $request->user()->isGuestDemo()) {
            return redirect()->route('teacher.lessons.create');
        }

        $demo = DemoLesson::resolve();

        abort_if($demo === null, 404, 'No demo lesson is published yet.');

        $sandbox = $this->guests->start($demo);

        return redirect()->route('teacher.lessons.wizard', [
            'lesson' => $sandbox->id,
            'step' => 4,
        ]);
    }
}
