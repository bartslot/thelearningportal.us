<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dev-only: auto-logs in a seeded account so the login flow can be skipped.
 * Enabled only when APP_AUTO_LOGIN=true in .env.
 * Set APP_USER_ROLE=teacher|admin|student to pick the account (default: admin).
 */
class AutoLoginDev
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! app()->isLocal() || ! config('app.auto_login')) {
            return $next($request);
        }

        if (! Auth::check()) {
            $role = env('APP_USER_ROLE', 'admin');

            $user = match ($role) {
                'teacher' => User::where('email', 'teacher@example.com')->first(),
                'student' => User::where('email', 'student@example.com')->first(),
                default   => User::where('role', 'admin')->first(),
            };

            if ($user) {
                Auth::login($user, remember: true);
            }
        }

        return $next($request);
    }
}
