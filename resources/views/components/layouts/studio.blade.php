<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="learningportal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }} — The Learning Portal</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cinzel:700|inter:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head-scripts')
    @livewireStyles
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased overflow-hidden">
    <nav class="sticky top-0 z-50 border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <x-logo />
                </a>

                <div class="flex items-center gap-6">
                    @auth
                        @if(auth()->user()->role === 'admin')
                            <a href="{{ route('admin.dashboard') }}" class="text-sm text-rose-400 transition-colors hover:text-rose-300">
                                Admin
                            </a>
                            <a href="{{ route('admin.avatars.index') }}"
                               class="text-sm {{ request()->routeIs('admin.avatars.*') ? 'text-amber-400' : 'text-slate-400 transition-colors hover:text-white' }}">
                                Avatar Studio
                            </a>
                            <a href="{{ route('admin.avatar-lab') }}"
                               class="text-sm {{ request()->routeIs('admin.avatar-lab') ? 'text-indigo-400' : 'text-slate-400 transition-colors hover:text-white' }} flex items-center gap-1.5">
                                🧪 <span>3D Lab</span>
                                <span class="text-[0.55rem] bg-indigo-900 text-indigo-300 px-1.5 py-0.5 rounded font-semibold">BETA</span>
                            </a>
                        @endif

                        @if(auth()->user()->isTeacher() || auth()->user()->role === 'admin')
                            <a href="{{ route('teacher.dashboard') }}" class="text-sm text-slate-300 transition-colors hover:text-amber-400">
                                Dashboard
                            </a>
                            <a href="{{ route('teacher.lessons.create') }}" class="text-sm text-slate-300 transition-colors hover:text-amber-400">
                                New Lesson
                            </a>
                        @endif

                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-400">{{ auth()->user()->name }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-xs text-slate-400 transition-colors hover:text-rose-400">
                                    Sign out
                                </button>
                            </form>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-slate-300 transition-colors hover:text-amber-400">
                            Sign in
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    {{-- Full-bleed content — no max-width, no padding --}}
    {{ $slot }}

    @livewireScripts
</body>
</html>
