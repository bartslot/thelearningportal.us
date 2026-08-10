<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters.
 *
 * The app had none, and that single omission was doing damage in both directions at once.
 *
 * TOO TIGHT, in the worst possible place. `throttle:10,1` with no name resolves its key with
 * ThrottleRequests::resolveRequestSignature(), which for an anonymous visitor is `domain|ip` with
 * NO per-route prefix. So every unnamed throttle in the app shared ONE counter per address: the
 * guest demo, the leaderboard, the quiz score, forgot-password and reset-password all drew from the
 * same ten. A school is one NAT address, so a school was one bucket. Measured on a class-sized run:
 * children 1 to 10 scored, children 11 upward got 429 and their answers were never written, and the
 * Skip button sits beside Submit, so a child who gave up after two failures vanished from the
 * teacher's report with no trace anywhere.
 *
 * ABSENT, at the front door. Neither /login nor /api/v1/auth/login was throttled at all: thirty
 * wrong passwords and then the right one, in nine seconds, no delay and no lockout.
 *
 * Naming a limiter is what fixes the first problem, because a named limiter's key is whatever `by()`
 * returns and nothing else shares it. Which is also why every limiter below says explicitly who it
 * is counting.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * A class, generously. This counts ONE CHILD's submissions, not a school's, so it only has to
     * be larger than the number of quizzes one child can legitimately answer in a minute.
     */
    private const QUIZ_SUBMISSIONS_PER_MINUTE = 30;

    /**
     * The whole-address backstop. A class of 30 answering a quiz a minute does not come close; a
     * script with cookies switched off, which would otherwise get a new session per request and no
     * limit at all, does.
     */
    private const QUIZ_SUBMISSIONS_PER_CLASS_PER_MINUTE = 300;

    /** Wrong passwords for one account before it stops answering, per minute. */
    private const LOGIN_ATTEMPTS_PER_MINUTE = 5;

    /** …and a wider net for one address hammering many accounts. */
    private const LOGIN_ATTEMPTS_PER_IP_PER_MINUTE = 20;

    public function boot(): void
    {
        $this->quizScoring();
        $this->signingIn();
        $this->anonymousWrites();
    }

    /**
     * Quiz submissions, counted per CHILD rather than per address.
     *
     * The session is the right key: every child's browser has its own, one child cannot spend
     * another child's allowance, and thirty children behind one school router are thirty buckets.
     * It falls back to the IP only when there is no session at all, which in practice means a
     * script rather than a classroom.
     */
    private function quizScoring(): void
    {
        RateLimiter::for('quiz-score', fn (Request $request) => [
            Limit::perMinute(self::QUIZ_SUBMISSIONS_PER_MINUTE)
                ->by($request->hasSession() ? 'quiz:'.$request->session()->getId() : 'quiz-ip:'.$request->ip()),
            // A backstop, wide enough that a whole class never reaches it. Without it a client that
            // refuses cookies gets a fresh session every request and therefore no limit at all.
            Limit::perMinute(self::QUIZ_SUBMISSIONS_PER_CLASS_PER_MINUTE)->by('quiz-room:'.$request->ip()),
        ]);

        // Reading a leaderboard is cheap and a whole class refreshes it at once, so this is per
        // address on purpose — it is here to stop a scraper, not a classroom.
        RateLimiter::for('leaderboard', fn (Request $request) => Limit::perMinute(120)->by('lb:'.$request->ip()));
    }

    /**
     * Signing in. Two limits, because they stop two different attacks.
     *
     * Per email+address stops someone guessing one teacher's password. Per address alone stops
     * someone spraying one common password across many accounts, which the first limit would let
     * through entirely since each account would only see one attempt.
     */
    private function signingIn(): void
    {
        foreach (['login', 'api-login'] as $name) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)
                    ->by($name.':'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_IP_PER_MINUTE)->by($name.'-ip:'.$request->ip()),
            ]);
        }
    }

    /**
     * Anonymous requests that WRITE, each in its own bucket.
     *
     * /try creates a user, a lesson and its scenes on a plain GET. It has to stay tight, but it no
     * longer eats the same ten requests a child needs to submit an answer.
     */
    private function anonymousWrites(): void
    {
        RateLimiter::for('guest-demo', fn (Request $request) => Limit::perMinute(10)->by('try:'.$request->ip()));

        // Password reset keeps its own six. The comment on the route explains why it is low: the
        // form sends mail to an address the sender chooses.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(6)->by('pw:'.$request->ip()));

        // Joining a class writes children into a teacher's roster.
        RateLimiter::for('class-join', fn (Request $request) => Limit::perMinute(20)->by('join:'.$request->ip()));
    }
}
