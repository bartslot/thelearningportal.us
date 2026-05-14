<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Local dev: debug123 bypasses password check for any user
        if (app()->isLocal() && $credentials['password'] === 'debug123') {
            $user = \App\Models\User::where('email', $credentials['email'])->first();
            if ($user) {
                Auth::login($user, $request->boolean('remember'));
                $request->session()->regenerate();

                return match (true) {
                    $user->isTeacher(), $user->role === 'admin' => redirect()->route('teacher.dashboard'),
                    default                                      => redirect()->intended('/'),
                };
            }
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();

            return match (true) {
                $user->isTeacher(), $user->role === 'admin' => redirect()->route('teacher.dashboard'),
                default                                      => redirect()->intended('/'),
            };
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'These credentials do not match our records.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
