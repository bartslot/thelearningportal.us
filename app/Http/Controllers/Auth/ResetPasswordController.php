<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The other half of the reset: the screen the emailed link opens, and the password it sets.
 *
 * The broker owns token validity, single use and expiry (60 minutes, config/auth.php) — this only
 * hands it the request and turns its answer into a redirect or a validation error.
 */
class ResetPasswordController extends Controller
{
    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')),
                    // A new remember-token signs out every other browser holding a "keep me signed
                    // in" cookie. Someone resetting a password may be doing it because a device was
                    // lost, and leaving those sessions alive would defeat the point.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Covers an expired link, a link already used, and a forged token alike — the broker
            // does not distinguish, and neither should the screen.
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return redirect()->route('login')->with('status', __('Your password has been changed. You can sign in now.'));
    }
}
