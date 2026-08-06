<x-layouts.guest :title="__('Reset your password')">

    <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-8 shadow-2xl backdrop-blur-sm">
        <h1 class="text-xl font-semibold text-slate-100 mb-2 text-center">{{ __('Reset your password') }}</h1>
        <p class="mb-6 text-center text-sm text-slate-400">
            {{ __('Enter your email address and we will send you a link to choose a new one.') }}
        </p>

        {{-- Deliberately the same message whether or not the address has an account. --}}
        @if(session('status'))
            <div class="mb-4 rounded-lg border border-emerald-700 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-4 rounded-lg border border-rose-700 bg-rose-900/30 px-4 py-3 text-sm text-rose-300">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf

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
                    autofocus
                    value="{{ old('email') }}"
                    class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-slate-100 placeholder-slate-500
                           focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 transition-colors"
                    placeholder="you@school.edu"
                >
            </div>

            <button
                type="submit"
                class="w-full rounded-lg bg-amber-500 px-4 py-3 text-sm font-semibold text-slate-950
                       hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2
                       focus:ring-offset-slate-900 transition-colors"
            >
                {{ __('Send reset link') }}
            </button>
        </form>

        <p class="mt-5 text-center text-xs text-slate-400">
            <a href="{{ route('login') }}" class="text-amber-400 hover:underline">{{ __('Back to sign in') }}</a>
        </p>
    </div>

</x-layouts.guest>
