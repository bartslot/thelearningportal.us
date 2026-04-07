<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }} — The Learning Portal</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cinzel:700|inter:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased">

    {{-- ── Navigation ──────────────────────────────────────────────────────── --}}
    <nav class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm sticky top-0 z-50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">

                {{-- Logo --}}
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="text-amber-400 text-2xl">🏛️</span>
                    <span class="font-cinzel text-lg font-bold text-amber-400 tracking-wide">
                        The Learning Portal
                    </span>
                </a>

                {{-- Nav links --}}
                <div class="flex items-center gap-6">
                    @auth
                        @if(auth()->user()->isTeacher())
                            <a href="{{ route('teacher.dashboard') }}"
                               class="text-sm text-slate-300 hover:text-amber-400 transition-colors">
                                Dashboard
                            </a>
                            <a href="{{ route('teacher.lessons.create') }}"
                               class="text-sm text-slate-300 hover:text-amber-400 transition-colors">
                                New Lesson
                            </a>
                        @endif

                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-400">{{ auth()->user()->name }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                        class="text-xs text-slate-400 hover:text-rose-400 transition-colors">
                                    Sign out
                                </button>
                            </form>
                        </div>
                    @else
                        <a href="{{ route('login') }}"
                           class="text-sm text-slate-300 hover:text-amber-400 transition-colors">
                            Sign in
                        </a>
                    @endauth
                </div>

            </div>
        </div>
    </nav>

    {{-- ── Flash messages ───────────────────────────────────────────────────── --}}
    @if(session('success'))
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-4">
            <div class="rounded-lg border border-emerald-700 bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-4">
            <div class="rounded-lg border border-rose-700 bg-rose-900/40 px-4 py-3 text-sm text-rose-300">
                {{ session('error') }}
            </div>
        </div>
    @endif

    {{-- ── Main content ─────────────────────────────────────────────────────── --}}
    <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
