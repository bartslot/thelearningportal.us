{{-- TEMPORARY. A preview of the new hero demo for review on production, deliberately not
     linked from anywhere and excluded from search. Delete this file and its route once a warp
     variant has been chosen; the real hero lives in components/landing/hero.blade.php. --}}
<x-layouts.landing title="Hero preview">
    <x-slot:head>
        <title>Hero preview · The Learning Portal</title>
        <meta name="robots" content="noindex, nofollow">
    </x-slot:head>

@php
    $portalCards = [
        'historycards/history.jpg',
        'historycards/history12.jpg',
        'historycards/history16.jpg',
        'historycards/history17.jpg',
        'historycards/history18.jpg',
        'historycards/history19.jpg',
        'historycards/history26.jpg',
        'historycards/history115.jpg',
    ];

    $isTeacher = auth()->check() && auth()->user()->isTeacher();

    // The lesson the animation pretends to build, and the one both buttons open. Resolved by
    // title so a rebuilt Canon lesson keeps working — see App\Support\DemoLesson.
    $demoLesson = \App\Support\DemoLesson::resolve();

    // What the demo types. Kept here, not in JS, so it is translatable and indexable.
    //
    // The typed goal has to describe the lesson the buttons below actually open. The scripted
    // line is written for the configured demo lesson, so when DemoLesson falls back to a
    // different one, the demo types THAT lesson's title instead of promising the wrong thing.
    $demoGoal = \App\Support\DemoLesson::goal();

    // This lesson's own artwork, packed into one small sheet by `lessons:build-warp-atlas`.
    // With it the tunnel is made of Tasman's fleet and the Golden Bay encounter rather than
    // stock history cards; without it the hero falls back to the generic set.
    $warpAtlas = $demoLesson?->warpAtlas();

    $demoGrades = [__('Grade 6'), __('Grade 8'), __('Grade 10'), __('Grade 12')];
    $demoPick = __('Grade 12');
@endphp

    <div class="fixed bottom-4 left-4 z-50 rounded-full border border-white/15 bg-slate-950/70 px-4 py-2 text-xs text-white/60 backdrop-blur">
        Preview · variant <span class="font-mono text-white/90">{{ request('warp', 'cardwheel') }}</span>
        · country <span class="font-mono text-white/90">{{ \App\Support\VisitorCountry::code() ?? '—' }}</span>
        · lesson <span class="font-mono text-white/90">{{ $demoLesson?->title ?? '—' }}</span>
    </div>

<section
    id="home"
    data-demo
    class="relative isolate flex min-h-screen flex-col items-center justify-center overflow-hidden"
    data-portal-images='@json(array_map(fn ($image) => asset("assets/{$image}"), $portalCards))'
>
    {{-- Deep navy radial gradient background --}}
    <img src="{{ asset('assets/videocards.webp') }}" alt="" fetchpriority="high" class="h-7xl w-7xl pointer-events-none absolute wheel z-10" />
    <div class="hero-glow pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(ellipse_80%_60%_at_50%_100%,#0d2a4a_0%,#020b24_55%,#010510_100%)] opacity-60"></div>
    {{-- Subtle center glow --}}
    <div class="hero-spotlight pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(ellipse_50%_40%_at_50%_60%,rgba(30,80,140,0.45)_0%,transparent_70%)] bg-blend-overlay"></div>
    <div class="hero-orb pointer-events-none absolute left-1/2 top-[16%] z-0 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-[radial-gradient(circle,rgba(56,189,248,0.14)_0%,rgba(56,189,248,0.05)_35%,transparent_72%)] blur-3xl"></div>

    {{-- The timewarp field. One sprite sheet, 24 cells, drawn as depth-sorted cards by
         resources/js/hero/timewarp.js. --}}
    <canvas data-timewarp-canvas class="hero-timewarp pointer-events-none absolute inset-0 z-20 h-full w-full" aria-hidden="true"></canvas>
    @if ($warpAtlas)
        <img
            data-timewarp-lesson-atlas
            data-cells="{{ $warpAtlas['cells'] }}"
            src="{{ $warpAtlas['url'] }}"
            alt=""
            class="hero-timewarp-atlas"
            fetchpriority="high"
            decoding="async"
            aria-hidden="true"
        >
    @endif

    <picture class="hero-timewarp-atlas" aria-hidden="true">
        <source srcset="{{ asset('assets/timewarp-cards.avif') }}" type="image/avif">
        <img data-timewarp-atlas src="{{ asset('assets/timewarp-cards.webp') }}" alt="" width="640" height="960" fetchpriority="high" decoding="async">
    </picture>

    {{-- Act one: the lesson asks for itself.
         Both acts are absolutely centred on top of each other, so the hero never reflows as one
         hands over to the other. --}}
    <div class="hero-stage pointer-events-none absolute inset-0 z-30 flex items-center justify-center px-4">
    <div data-demo-conversation class="hero-copy pointer-events-auto w-full max-w-2xl text-center">
        <p data-demo-step="goal" class="font-history text-lg text-white/55 sm:text-xl">{{ __('What is your learning goal?') }}</p>

        <p class="font-history mt-4 text-2xl leading-snug text-white sm:text-4xl">
            <span data-demo-typed="{{ $demoGoal }}"></span><span data-demo-caret class="hero-caret" aria-hidden="true"></span>
        </p>

        <p data-demo-step="audience" class="font-history mt-10 text-lg text-white/55 sm:text-xl">{{ __('Great. Who is it for?') }}</p>

        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            @foreach ($demoGrades as $grade)
                <span
                    data-demo-chip
                    @if ($grade === $demoPick) data-demo-chip-pick @endif
                    class="hero-chip rounded-full border border-white/20 px-4 py-1.5 text-sm text-white/80"
                >{{ $grade }}</span>
            @endforeach
        </div>
    </div>
    </div>

    {{-- Act two: the lesson exists. This is also the whole hero for anyone with JS off or
         reduced motion on, which is why the h1 and the real links live here. --}}
    <div class="hero-stage pointer-events-none absolute inset-0 z-30 flex items-center justify-center px-4">
    <div data-demo-reveal class="hero-cta pointer-events-auto flex max-w-4xl flex-col items-center gap-8 text-center sm:flex-row sm:gap-10 sm:text-left">
        @if ($demoLesson)
            {{-- The lesson itself, playable. It is what the demo just built, so it arrives out of
                 the tunnel ahead of the words. --}}
            <x-lesson-poster-card
                data-reveal-item
                :lesson="$demoLesson"
                class="w-[11.5rem] shrink-0 sm:w-[13rem]"
            />
        @endif

        <div class="flex flex-col items-center sm:items-start">
            {{-- Kept word for word in step with components/landing/hero.blade.php. This page exists to
                 preview that hero, so copy that drifts here previews something nobody ships. --}}
            <h1 data-reveal-item class="text-balance text-4xl leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
                {{ $demoLesson?->title ?? __('Where storytelling meets learning.') }}
            </h1>

            <p data-reveal-item class="mt-5 max-w-md text-balance text-sm leading-relaxed text-white/60 sm:text-base">
                {{ __('Real paintings, a story your class wants the end of, and a game they finish by themselves. Made with AI, shaped by teachers.') }}
            </p>

            <div data-reveal-item class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:justify-start">
                @if ($demoLesson)
                    {{-- The sliders answer the label: hover, and the two knobs slide along their
                         tracks. Configuring is what the button does, so that is what it shows. --}}
                    <a href="{{ route('demo.configure') }}" class="hero-action hero-action--ghost">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                            <path stroke-linecap="round" d="M3 8.5h18M3 15.5h18" />
                            <circle class="hero-action__knob hero-action__knob--a" cx="8.5" cy="8.5" r="2.6" />
                            <circle class="hero-action__knob hero-action__knob--b" cx="15.5" cy="15.5" r="2.6" />
                        </svg>
                        {{ __('Configure') }}
                    </a>
                    <a href="{{ route('lesson.play', $demoLesson->lesson_code) }}" class="hero-action hero-action--primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="hero-action__play h-4 w-4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
                        </svg>
                        {{ __('Play lesson') }}
                    </a>
                @elseif ($isTeacher)
                    <a href="{{ route('teacher.lessons.create') }}" class="hero-action hero-action--primary">
                        {{ __('Create a lesson') }}
                    </a>
                @endif
            </div>
        </div>
    </div>
    </div>

    <button
        type="button"
        data-demo-skip
        class="hero-skip absolute bottom-8 right-6 z-30 text-xs uppercase tracking-widest text-white/35 transition hover:text-white/80"
    >{{ __('Skip') }}</button>

</section>

</x-layouts.landing>
