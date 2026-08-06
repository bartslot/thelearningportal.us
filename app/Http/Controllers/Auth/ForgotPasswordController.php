<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "I forgot my password" — ask for an address, send a reset link.
 *
 * Everything underneath already existed: the password_reset_tokens table, the broker in
 * config/auth.php, and CanResetPassword/Notifiable on the User model. Only the routes were missing,
 * so the sole way to recover an account was somebody with SSH running Hash::make in tinker.
 */
class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Guest demo accounts (/try) are throwaway, pruned after 24 hours, and their addresses are
        // @demo.invalid — there is nothing to send to. Skipping them keeps an unauthenticated form
        // that sends email as narrow as it can be. Answered identically to everything else below.
        $isGuest = User::where('email', $request->string('email'))
            ->where('is_guest_demo', true)
            ->exists();

        if (! $isGuest) {
            Password::sendResetLink($request->only('email'));
        }

        // ALWAYS the same answer, whatever happened. Reporting "no such account" would turn this
        // form into a way for anyone to discover which addresses have one — and the reply is
        // deliberately not a promise that a mail was sent.
        return back()->with('status', __('If that address has an account, a reset link is on its way.'));
    }
}
