<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Resetting a forgotten password.
 *
 * The app shipped with a login form and nothing else: no forgot-password route existed, so the only
 * way to recover an account was somebody with SSH running Hash::make in tinker. Fine for two admins,
 * useless the first time a pilot teacher forgets theirs.
 *
 * Everything Laravel needs was already in place — the password_reset_tokens table, the broker
 * config, and CanResetPassword/Notifiable on the User model. Only the routes were missing.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forgot_password_screen_is_reachable(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_the_login_screen_links_to_it(): void
    {
        // A reset route nobody can find is the same as no reset route.
        $this->get(route('login'))->assertSee(route('password.request'));
    }

    public function test_a_reset_link_is_emailed(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'teacher@school.nl']);

        $this->post(route('password.email'), ['email' => 'teacher@school.nl'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        // Telling a stranger "no such account" turns the form into a way to discover who has one.
        Notification::fake();
        User::factory()->create(['email' => 'real@school.nl']);

        $known = $this->post(route('password.email'), ['email' => 'real@school.nl']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@school.nl']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'the form reveals whether an address has an account',
        );
        Notification::assertCount(1);
    }

    public function test_a_guest_demo_account_cannot_request_a_reset(): void
    {
        // Throwaway accounts from /try, pruned after 24h. They have no real address to send to, and
        // an unauthenticated form that emails anything is worth keeping narrow.
        Notification::fake();
        $guest = User::factory()->create([
            'email' => 'guest-abc@demo.invalid',
            'is_guest_demo' => true,
        ]);

        $this->post(route('password.email'), ['email' => $guest->email]);

        Notification::assertNothingSent();
    }

    public function test_the_password_is_actually_changed(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'teacher@school.nl']);
        $this->post(route('password.email'), ['email' => $user->email]);

        $token = '';
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_a_tampered_token_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'teacher@school.nl']);
        $before = $user->password;

        $this->post(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'attacker-chosen',
            'password_confirmation' => 'attacker-chosen',
        ])->assertSessionHasErrors();

        $this->assertSame($before, $user->fresh()->password, 'a forged token changed the password');
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'teacher@school.nl']);
        $this->post(route('password.email'), ['email' => $user->email]);

        $token = '';
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'first-choice-password',
            'password_confirmation' => 'first-choice-password',
        ];
        $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();

        // A reset link forwarded, logged or left in a browser history must not still work.
        $this->post(route('password.update'), $payload + [
            'password' => 'second-choice-password',
            'password_confirmation' => 'second-choice-password',
        ])->assertSessionHasErrors();

        $this->assertTrue(Hash::check('first-choice-password', $user->fresh()->password));
    }

    public function test_the_reset_form_rejects_a_short_password(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_the_reset_screen_renders_with_a_token(): void
    {
        $this->get(route('password.reset', ['token' => 'some-token']))
            ->assertOk()
            ->assertSee('some-token', false);
    }
}
