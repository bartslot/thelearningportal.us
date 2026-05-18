<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="learningportal">
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
<body class="flex min-h-screen h-full items-center justify-center bg-slate-950 text-slate-100 antialiased">
    <div class="w-full max-w-md px-4">
        <div class="mb-8 text-center">
            <a href="{{ route('home') }}" class="inline-flex flex-col items-center gap-2">
                <x-logo class="h-14 w-14" />
                <span class="font-cinzel text-2xl font-bold tracking-wide text-amber-400">
                    The Learning Portal
                </span>
                <span class="text-xs uppercase tracking-widest text-slate-400">
                    Where Storytelling Meets Learning
                </span>
            </a>
        </div>

        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>
