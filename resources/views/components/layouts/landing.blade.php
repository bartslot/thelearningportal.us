@props(['title' => config('app.name')])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="The Learning Portal is a cinematic history landing page for teachers and students.">
    <title>{{ $title }} — The Learning Portal</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="landing-shell relative min-h-full bg-slate-950 text-white antialiased">
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_top,_rgba(56,189,248,0.18),_transparent_34%),radial-gradient(circle_at_bottom,_rgba(14,165,233,0.14),_transparent_28%)]"></div>
    <div class="pointer-events-none fixed inset-0 bg-[linear-gradient(180deg,rgba(15,23,42,0.15)_0%,rgba(2,6,23,0.7)_100%)]"></div>
    <div
        class="landing-cursor pointer-events-none fixed left-0 top-0 z-[9999] h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border border-white/80 bg-white opacity-90 shadow-[0_0_24px_rgba(255,255,255,0.5)]"
        aria-hidden="true"
    ></div>

    {{ $slot }}

    @livewireScripts
</body>
</html>
