<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign In' }} — The Learning Portal</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cinzel:700|inter:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased flex min-h-screen items-center justify-center">

    <div class="w-full max-w-md px-4">
        {{-- Logo --}}
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="inline-flex flex-col items-center gap-2">
                <span class="text-5xl">🏛️</span>
                <span class="font-cinzel text-2xl font-bold text-amber-400 tracking-wide">
                    The Learning Portal
                </span>
                <span class="text-xs text-slate-400 tracking-widest uppercase">
                    Where Storytelling Meets Learning
                </span>
            </a>
        </div>

        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>
