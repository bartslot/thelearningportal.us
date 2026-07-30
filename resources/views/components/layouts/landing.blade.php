@props(['title' => config('app.name')])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', \App\Support\Locales::region(app()->getLocale())) }}" class="h-full scroll-smooth" data-theme="learningportal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Pages that care about being found pass <x-slot:head><x-seo .../></x-slot:head>, which owns
         the title, description, canonical, hreflang, social cards and structured data. Anything
         without it falls back to the plain title below. --}}
    @isset($head)
        {{ $head }}
    @else
        <title>{{ $title }} · The Learning Portal</title>
        <meta name="description" content="{{ __('Narrated, story-driven history lessons that a teacher can build in minutes and a class can play on any device.') }}">
    @endisset

    {{-- The narration audio and museum imagery come from the same origin, but fonts and tiles do
         not: warming those connections early shaves a round trip off first paint. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="landing-shell relative min-h-full bg-slate-950 text-white antialiased">
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_top,_rgba(56,189,248,0.18),_transparent_34%),radial-gradient(circle_at_bottom,_rgba(14,165,233,0.14),_transparent_28%)]"></div>
    <div class="pointer-events-none fixed inset-0 bg-[linear-gradient(180deg,rgba(15,23,42,0.15)_0%,rgba(2,6,23,0.7)_100%)]"></div>
    <div
        class="landing-cursor pointer-events-none fixed left-0 top-0 z-9999 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border border-white/80 bg-white opacity-90 shadow-[0_0_24px_rgba(255,255,255,0.5)]"
        aria-hidden="true"
    ></div>

    {{ $slot }}

    {{-- Dev role switching stays reachable here: a student has no workspace of its own and lands on the landing page. --}}
    @if (app()->environment('local') && auth()->check())
        @livewire('dev.test-panel')
    @endif

    @stack('scripts')
    @livewireScripts
</body>
</html>
