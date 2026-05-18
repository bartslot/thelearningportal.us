<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dev-only: auto-logs in the admin@admin.com account so the login flow can be skipped.
 * Enabled only when APP_AUTO_LOGIN=true in .env.
 */
class AutoLoginDev
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! app()->isLocal() || ! config('app.auto_login')) {
            return $next($request);
        }

        if (! Auth::check()) {
            $user = User::where('email', 'admin@admin.com')->first();
            if ($user) {
                Auth::login($user, remember: true);
            }
        }

        return $next($request);
    }
}
