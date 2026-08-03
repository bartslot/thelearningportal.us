<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fence around a landing-page guest (App\Services\GuestDemoSession).
 *
 * A guest holds a real teacher account, so without this every teacher route — the dashboard, the
 * time map, other people's lesson URLs — would open to anyone who pressed Configure. They get one
 * thing: the wizard, on the lesson they were given, on the two canvas steps. Ownership itself is
 * still enforced by LessonWizard::mount() via User::canManage().
 */
final class RestrictGuestDemo
{
    /** Route names a guest may open. Both resolve to LessonWizard. */
    private const ALLOWED_ROUTES = [
        'teacher.lessons.wizard',
        'teacher.lessons.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isGuestDemo()) {
            return $next($request);
        }

        if (! $request->routeIs(...self::ALLOWED_ROUTES)) {
            abort(403, 'This demo can only open the lesson it was given.');
        }

        return $next($request);
    }
}
