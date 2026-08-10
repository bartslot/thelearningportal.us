@props(['mode' => 'lesson'])

@php
    // Two scripts, one stage. 'lesson' is the demo that builds a lesson and hands it over; 'launch'
    // is the announcement that the product itself has shipped. They share the timewarp field, the
    // card wheel, the lighting and the GSAP timeline verbatim — only the words and the thing that
    // arrives out of the tunnel differ, because that is the only part that is actually different.
    //
    // Kept as one component rather than two: the timeline in resources/js/hero/lesson-demo.js is
    // driven entirely by data-attributes, so a second copy would inherit 1,555 lines of JS it would
    // then have to keep in step. Nothing here changes the JS.
    $isLaunch = $mode === 'launch';

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

    // What the demo types. Kept here, not in JS, so it is indexable.
    //
    // The typed goal has to describe the lesson the buttons below actually open. The scripted line
    // is written for the configured demo lesson, so when DemoLesson falls back to a different one,
    // the demo types THAT lesson's title instead of promising the wrong thing.
    //
    // NOT wrapped in __(). The goal is already written in the lesson's own language (each entry in
    // config('demo.by_language') carries its own), and running it through the translator would let
    // a French line get "translated" into the visitor's UI language — which is the exact mixing
    // this whole path exists to prevent.
    $demoGoal = \App\Support\DemoLesson::goal();

    // This lesson's own artwork, packed into one small sheet by `lessons:build-warp-atlas`.
    // With it the tunnel is made of Tasman's fleet and the Golden Bay encounter rather than
    // stock history cards; without it the hero falls back to the generic set.
    $warpAtlas = $demoLesson?->warpAtlas();

    // The launch script's chips: what the product IS, in the same shape the demo uses for grades.
    $launchChips = [__('Five languages'), __('Narrated'), __('Interactive maps'), __('Live now')];

    $demoGrades = [__('Grade 6'), __('Grade 8'), __('Grade 10'), __('Grade 12')];
    $demoPick = __('Grade 12');
@endphp

{{-- Set before the hero paints, so the finished state (which is what non-JS visitors and search
     engines get) never flashes on screen ahead of the animation. --}}
<script>
    if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
        document.documentElement.classList.add('js-hero-demo');
    }
</script>

<section
    id="home"
    data-demo
    class="relative isolate flex min-h-screen flex-col items-center justify-center overflow-hidden"
    data-portal-images='@json(array_map(fn ($image) => asset("assets/{$image}"), $portalCards))'
>
    {{-- The flat card wheel. Sized and centred by `.wheel` in app.css — a square stage floored at
         960px, which the hero clips.

         The width/height attributes are load-bearing, not decoration. This used to carry `h-7xl`,
         which was never a real utility (Tailwind v4 builds `w-7xl` from --container-7xl and has no
         matching height scale), so it had a width and NO reserved height: until the bytes arrived
         it laid out 1281x0 at the flex centre and snapped to 1303x1303 at y=-201 the moment they
         did. A 651px jump of a full-width element in a 900px viewport is a 0.72 layout shift, and
         `fetchpriority=high` guarantees the picture wins the race against the JS that would
         otherwise have hidden it first. Keep both attributes even though CSS now sets the box:
         they are what the browser has to go on before the stylesheet's own value can matter. --}}
    <img src="{{ asset('assets/videocards.webp') }}" alt="" fetchpriority="high"
         width="1280" height="1280"
         class="pointer-events-none absolute wheel z-10" />
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
        @if ($isLaunch)
            {{-- The same beats as the demo, because the timeline is the same: a quiet question, a
                 typed answer, then a row of chips. Here they name what shipped rather than asking
                 what to build, so the tunnel still resolves into something instead of just stopping. --}}
            <p data-demo-step="goal" class="font-history text-lg text-white/55 sm:text-xl">{{ __('Three years of history lessons') }}</p>

            <p class="font-history mt-4 text-2xl leading-snug text-white sm:text-4xl">
                <span data-demo-typed="{{ __('all of it, in one place') }}"></span><span data-demo-caret class="hero-caret" aria-hidden="true"></span>
            </p>

            <p data-demo-step="audience" class="font-history mt-10 text-lg text-white/55 sm:text-xl">{{ __('Built for the classroom') }}</p>

            <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                @foreach ($launchChips as $chip)
                    <span
                        data-demo-chip
                        @if ($loop->last) data-demo-chip-pick @endif
                        class="hero-chip rounded-full border border-white/20 px-4 py-1.5 text-sm text-white/80"
                    >{{ $chip }}</span>
                @endforeach
            </div>
        @else
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
        @endif
    </div>
    </div>

    {{-- Act two: the lesson exists. This is also the whole hero for anyone with JS off or
         reduced motion on, which is why the h1 and the real links live here. --}}
    <div class="hero-stage pointer-events-none absolute inset-0 z-30 flex items-center justify-center px-4">
    <div data-demo-reveal class="hero-cta pointer-events-auto flex max-w-4xl flex-col items-center gap-8 text-center sm:flex-row sm:gap-10 sm:text-left">
        @if ($isLaunch)
            {{-- The logo is what arrives out of the tunnel, in the slot the lesson poster occupies
                 in the other script — same stagger, same overshoot, same landing beat. --}}
            <img
                data-reveal-item
                src="{{ asset('assets/logo.svg') }}"
                alt="{{ __('History Portal') }}"
                width="208" height="208"
                class="hero-launch-mark w-[11.5rem] shrink-0 sm:w-[13rem]"
                fetchpriority="high"
            >

            <div class="flex flex-col items-center sm:items-start">
                <h1 data-reveal-item class="text-balance text-4xl leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
                    {{ __('History Portal is now live') }}
                </h1>

                <p data-reveal-item class="mt-5 max-w-md text-balance text-sm leading-relaxed text-white/60 sm:text-base">
                    {{ __('Story-driven history lessons, narrated and ready for your class.') }}
                </p>

                <div data-reveal-item class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:justify-start">
                    @if ($demoLesson)
                        <a href="{{ route('lesson.play', $demoLesson->lesson_code) }}" class="hero-action hero-action--primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="hero-action__play h-4 w-4" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
                            </svg>
                            {{ __('Watch a lesson') }}
                        </a>
                    @endif
                    <a href="{{ route('public.lessons') }}" class="hero-action hero-action--ghost">
                        {{ __('Browse the lessons') }}
                    </a>
                </div>
            </div>
        @elseif ($demoLesson)
            {{-- The lesson itself, playable. It is what the demo just built, so it arrives out of
                 the tunnel ahead of the words. --}}
            <x-lesson-poster-card
                data-reveal-item
                :lesson="$demoLesson"
                class="w-[11.5rem] shrink-0 sm:w-[13rem]"
            />
        @endif

        @unless ($isLaunch)
        <div class="flex flex-col items-center sm:items-start">
            {{-- The lesson's own name, and nothing else. This is the payoff of the whole animation:
                 the class watched a lesson get built, and here is the thing itself, named.

                 Not run through the translator. It used to be, with a key that was nothing but the
                 placeholder itself: there is nothing in that to translate, and every language
                 reported it as a permanently missing string. (Nor can the key be written out here,
                 even in a comment: lang:audit scans the file as text and would find it again.)

                 The null guard is load-bearing. `$demoLesson->title` was read unguarded, so the
                 moment DemoLesson::resolve() found nothing playable the ENTIRE LANDING PAGE threw
                 "Attempt to read property title on null". The front door of the product, 500ing
                 because a lesson was mid-rebuild. --}}
            <h1 data-reveal-item class="text-balance text-4xl leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
                {{ $demoLesson?->title ?? __('Where storytelling meets learning.') }}
            </h1>

            {{-- What the product IS, under the name of what it just made. This used to read "Check
                 out The Punic Wars for Grade 12", which only repeated the title above it and sold
                 nothing. A visitor who has just watched a lesson assemble itself is at the exact
                 moment they wonder how, so this is where the answer goes: made with AI, finished by
                 a teacher, and delivered as pictures, a story and a game. --}}
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
        @endunless
    </div>
    </div>

    <button
        type="button"
        data-demo-skip
        class="hero-skip absolute bottom-8 right-6 z-30 text-xs uppercase tracking-widest text-white/35 transition hover:text-white/80"
    >{{ __('Skip') }}</button>

</section>
