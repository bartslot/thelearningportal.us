<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful middleware for cookie-based auth (web)
        $middleware->statefulApi();

        // Role middleware alias used in api.php routes
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\AutoLoginDev::class,
            \App\Http\Middleware\SetLocale::class,
        ]);

        // Anonymous students submit a quiz score from a player tab that may have been open
        // for hours — longer than the session lives (2h), and cookie-less altogether when the
        // player is embedded in a cross-site iframe. Both cases mean a stale CSRF token and a
        // 419 that silently loses the kid's result. CSRF buys nothing here: the endpoint is
        // public, needs no login, is throttled per IP, and its payload is already treated as
        // untrusted (see the score clamp in QuizLeaderboardController).
        $middleware->validateCsrfTokens(except: [
            'lesson/*/quiz-score',

            // Stripe posts from its own servers, so there is no session and no token to send. What
            // replaces CSRF here is the signature check in StripeWebhookController, which verifies
            // every request against STRIPE_WEBHOOK_SECRET and refuses anything it cannot verify.
            // Without this exemption every webhook 419s and no teacher ever receives their credits.
            'stripe/webhook',
        ]);

        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\AutoLoginDev::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
