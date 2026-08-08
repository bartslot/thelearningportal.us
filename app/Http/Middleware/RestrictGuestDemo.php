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
    /**
     * Route names a guest may open.
     *
     * The first two resolve to LessonWizard, which is the whole point of the demo. `help.index` is
     * here because the Help icon sits in the editor's own chrome: a visitor trying the product
     * clicked the one control offering to explain it and was told "this demo can only open the
     * lesson it was given". Reading the help costs nothing and spends nothing, and refusing it is
     * the least useful moment in the product to refuse anything.
     */
    private const ALLOWED_ROUTES = [
        'teacher.lessons.wizard',
        'teacher.lessons.show',
        'help.index',
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
