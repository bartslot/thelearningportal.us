<x-layouts.guest :title="__('Choose a new password')">

    <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-8 shadow-2xl backdrop-blur-sm">
        <h1 class="text-xl font-semibold text-slate-100 mb-6 text-center">{{ __('Choose a new password') }}</h1>

        @if($errors->any())
            <div class="mb-4 rounded-lg border border-rose-700 bg-rose-900/30 px-4 py-3 text-sm text-rose-300">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="block text-xs font-medium text-slate-400 mb-1.5 uppercase tracking-wider">
                    {{ __('Email address') }}
                </label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    required
                    value="{{ old('email', $email) }}"
                    class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-slate-100 placeholder-slate-500
                           focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 transition-colors"
                    placeholder="you@school.edu"
                >
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-slate-400 mb-1.5 uppercase tracking-wider">
                    {{ __('New password') }}
                </label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    autofocus
                    class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-slate-100 placeholder-slate-500
                           focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 transition-colors"
                    placeholder="••••••••"
                >
                <p class="mt-1.5 text-xs text-slate-500">{{ __('At least 8 characters.') }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-medium text-slate-400 mb-1.5 uppercase tracking-wider">
                    {{ __('Confirm new password') }}
                </label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-slate-100 placeholder-slate-500
                           focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 transition-colors"
                    placeholder="••••••••"
                >
            </div>

            <button
                type="submit"
                class="w-full rounded-lg bg-amber-500 px-4 py-3 text-sm font-semibold text-slate-950
                       hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2
                       focus:ring-offset-slate-900 transition-colors"
            >
                {{ __('Change password') }}
            </button>
        </form>
    </div>

</x-layouts.guest>
