<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Who each throttle is counting.
 *
 * Every throttle in the app was unnamed, and an unnamed `throttle:N,1` resolves its key with
 * ThrottleRequests::resolveRequestSignature(): `domain|ip` for an anonymous visitor, with no
 * per-route prefix. So they all shared ONE counter per address. A school is one NAT address, so a
 * class of 25 answering two quizzes drew 50 submissions from a bucket of ten, shared with the guest
 * demo and both password-reset routes. Meanwhile neither login endpoint was throttled at all.
 *
 * These assert the LIMITER RULES rather than firing requests at the routes, on purpose. Laravel's
 * test client mints a fresh session on every request, so an HTTP test of a per-session limiter can
 * never accumulate: the first draft of this file "passed" a 25-child class only because each of the
 * 25 got its own bucket for free, which is exactly the thing the test was supposed to prove. The
 * login test below stays end-to-end because it keys on the submitted email, which does survive.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{key:string,max:int}> */
    private function limitsFor(string $name, array $input = [], string $ip = '203.0.113.9', ?string $session = null): array
    {
        $request = Request::create('/', 'POST', $input, server: ['REMOTE_ADDR' => $ip]);

        if ($session !== null) {
            $store = app('session.store');
            $store->setId($session);
            $request->setLaravelSession($store);
        }

        $limits = (RateLimiter::limiter($name))($request);

        return collect(is_array($limits) ? $limits : [$limits])
            ->map(fn ($limit) => ['key' => (string) $limit->key, 'max' => $limit->maxAttempts])
            ->all();
    }

    public function test_two_children_on_one_school_address_are_two_buckets(): void
    {
        // The defect in one assertion. Same address, different browsers, and the counters must not
        // touch — otherwise the eleventh child in a class is refused because ten classmates
        // answered first, and their answers are never written.
        $sanne = $this->limitsFor('quiz-score', session: 'session-for-sanne');
        $joris = $this->limitsFor('quiz-score', session: 'session-for-joris');

        $this->assertNotSame($sanne[0]['key'], $joris[0]['key']);
        $this->assertGreaterThanOrEqual(25, $sanne[0]['max'], 'a class must fit inside one minute');
    }

    public function test_a_cookieless_client_still_meets_a_ceiling(): void
    {
        // A per-session limit alone is no limit at all for anything that refuses cookies: it would
        // get a fresh session, and therefore a fresh allowance, on every single request.
        $limits = $this->limitsFor('quiz-score', session: 'a-session');

        $this->assertCount(2, $limits, 'the per-address backstop must be there as well');
        $this->assertStringContainsString('quiz-room:', $limits[1]['key']);
    }

    public function test_the_quiz_and_the_guest_demo_no_longer_share_a_bucket(): void
    {
        // These two drew from the same ten requests per address, so a visitor pressing Configure on
        // the landing page could use up a child's ability to submit an answer.
        $quiz = $this->limitsFor('quiz-score', session: 'a-session');
        $demo = $this->limitsFor('guest-demo');
        $reset = $this->limitsFor('password-reset');
        $board = $this->limitsFor('leaderboard');

        $keys = [$quiz[0]['key'], $demo[0]['key'], $reset[0]['key'], $board[0]['key']];
        $this->assertCount(4, array_unique($keys), 'every route counts its own traffic');
    }

    public function test_signing_in_is_limited_per_account_and_per_address(): void
    {
        $one = $this->limitsFor('login', ['email' => 'teacher@example.com']);
        $two = $this->limitsFor('login', ['email' => 'other@example.com']);

        // Guessing one teacher's password is counted against that teacher…
        $this->assertNotSame($one[0]['key'], $two[0]['key']);
        $this->assertLessThanOrEqual(10, $one[0]['max']);

        // …and spraying one password across many accounts is caught by the second limit, which the
        // first cannot see because each account only ever gets one attempt.
        $this->assertSame($one[1]['key'], $two[1]['key']);
    }

    public function test_the_email_key_is_case_insensitive(): void
    {
        // Otherwise "Teacher@example.com" is a fresh allowance for the same account.
        $lower = $this->limitsFor('login', ['email' => 'teacher@example.com']);
        $mixed = $this->limitsFor('login', ['email' => 'Teacher@Example.com']);

        $this->assertSame($lower[0]['key'], $mixed[0]['key']);
    }

    public function test_the_login_form_stops_a_password_guesser(): void
    {
        // End to end, because this limiter keys on the submitted email rather than the session, so
        // it accumulates across test requests the way it would across a real attacker's.
        User::create([
            'name' => 'Teacher', 'email' => 'teacher@example.com',
            'password' => 'the-real-password', 'role' => 'teacher',
        ]);

        $statuses = [];
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $statuses[] = $this->post('/login', [
                'email' => 'teacher@example.com',
                'password' => 'wrong-'.$attempt,
            ])->status();
        }

        $this->assertContains(429, $statuses, 'thirty wrong passwords then the right one used to sign in');
    }
}
