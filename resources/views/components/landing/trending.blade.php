@props(['lessons' => collect()])

@php
    // Cards come from App\Support\TrendingTopics so the headline and the lesson title are paired
    // in one place. A card whose lesson is not built or published simply renders without a link.
    $popular = \App\Support\TrendingTopics::cards();

    $features = [
        [
            'title' => 'Interactive Lessons',
            'description' => 'Interaction energizes your class. Techniques that prompt students to respond in real time keep attention high and make the material easier to absorb.',
            'image' => asset('history/6history.webp'),
            'icon' => 'book-open',
        ],
        [
            'title' => 'Engaged Students',
            'description' => 'Learning can be fun. With gamification, lessons feel active and memorable, improving engagement and retention in class.',
            'image' => asset('history/12history.webp'),
            'icon' => 'puzzle-piece',
        ],
        [
            'title' => 'Track Progress',
            'description' => "Track each student's progress and see how lessons perform through a clear dashboard built for teachers.",
            'image' => asset('history/18history.webp'),
            'icon' => 'chart-bar',
        ],
        [
            'title' => 'Made for Teachers',
            'description' => 'The editor helps teachers refine, adjust, and enhance content. Building a new lesson is faster and easier with the right tools.',
            'image' => asset('history/24history.webp'),
            'icon' => 'academic-cap',
        ],
    ];
@endphp

<section id="popular" class="relative overflow-hidden border-t border-white/10 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 py-16 sm:py-20">
    <div class="section-container">
        <div class="mb-12 flex items-end justify-between gap-4">
            <div>
                <h2 class="mt-3 font-history font-semibold text-3xl tracking-tight text-white md:text-4xl">Trending history topics</h2>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-[2rem]">
            <!-- <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-16 bg-gradient-to-r from-slate-950 via-slate-950/75 to-transparent"></div> -->
            <!-- <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-gradient-to-l from-slate-950 via-slate-950/75 to-transparent"></div> -->

            <x-carousel aria-label="{{ __('Trending history topics') }}">
                @foreach ($popular as $item)
                @php $lesson = $lessons[$item['lesson']] ?? null; @endphp
                <article class="carousel-cell group relative mx-2 w-[16rem] shrink-0 sm:w-[17rem] lg:w-[18rem]">
                    <div class="relative aspect-[5/8] overflow-hidden rounded-[1.35rem] border border-white/10 bg-black/40 shadow-[0_16px_34px_rgba(0,0,0,0.35)] transition duration-300 ease-out hover:-translate-y-1 hover:border-sky-400/25 hover:shadow-[0_24px_52px_rgba(0,0,0,0.5)]">
                        @if ($lesson)
                            {{-- Overlay link so the whole card is clickable without rewrapping the
                                 markup (which would nest the hover-reveal inside an anchor). --}}
                            <a href="{{ route('lesson.play', $lesson->lesson_code) }}"
                               class="absolute inset-0 z-10 rounded-[1.35rem] focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400/70"
                               aria-label="{{ __('Play the lesson :title', ['title' => $item['title']]) }}"></a>
                        @endif
                        <img
                            src="{{ asset($item['image']) }}"
                            alt="{{ $item['title'] }}"
                            class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.04]"
                        >
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/20 to-transparent"></div>

                        <!-- <div class="absolute left-0 right-0 top-0 border border-white/10 bg-black/10 p-2 backdrop-blur">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full text-4xl text-white shadow-sm font-history">
                                    {{ $loop->iteration }}
                                </span>
                                
                            </div>
                        </div> -->

                        <div class="absolute inset-x-0 bottom-0 p-8 text-white">
                            <div>
                                <h3 class="text-2xl font-semibold leading-[1.05] tracking-tight text-white">
                                    {{ $item['title'] }}
                                    <p class="truncate text-[14px] pt-2 font-medium text-white/85">{{ $item['category'] }}</p>
                                </h3>
                                <div class="max-h-0 overflow-hidden opacity-0 transition-all duration-300 ease-out-ease-[cubic-bezier(0.95,0.05,0.795,0.035)] group-hover:max-h-32 group-hover:opacity-100">
                                <p class="text-[11px] leading-5 text-slate-200/90">
                                        {{ $item['description'] }}
                                    </p>
                                    <div class="min-w-0">
                                        @if ($lesson)
                                            <span class="mt-2 inline-flex items-center gap-1.5 text-[11px] font-semibold text-sky-200">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
                                                </svg>
                                                {{ __('Play this lesson') }}
                                            </span>
                                        @endif
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
                @endforeach
            </x-carousel>
        </div>

        <div id="features" class="mt-20">
            <h2 class="mt-3 font-history font-semibold text-3xl tracking-tight text-white md:text-4xl">Built for better history teaching</h2>

            <div class="relative overflow-hidden rounded-[2rem] mt-8">
                <!-- <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-16 bg-gradient-to-r from-slate-950 via-slate-950/75 to-transparent"></div> -->
                <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-gradient-to-l from-slate-950 via-slate-950/75 to-transparent"></div>

                <x-carousel aria-label="{{ __('Built for better history teaching') }}">
                    @foreach ($features as $feature)
                    <article class="carousel-cell group relative mx-2 w-[15rem] shrink-0 sm:w-[16rem] lg:w-[17rem]">
                        <div class="relative aspect-[5/8] overflow-hidden rounded-[1.35rem] border border-white/10 bg-white/5 shadow-[0_16px_34px_rgba(0,0,0,0.25)] transition duration-300 ease-out hover:-translate-y-1 hover:border-sky-400/20 hover:bg-white/7">
                            <img
                                src="{{ $feature['image'] }}"
                                alt="{{ $feature['title'] }}"
                                class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.04]"
                            >
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/25 to-transparent"></div>

                            <div class="absolute left-3 right-3 top-3 rounded-[1rem] border border-white/10 bg-slate-950/70 px-3 py-2 backdrop-blur">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-white text-base font-semibold text-slate-950 shadow-sm">
                                        {{ $loop->iteration }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-[9px] font-semibold uppercase tracking-[0.3em] text-sky-100/70">Tool</p>
                                        <p class="truncate text-[10px] font-medium text-white/85 flex items-center gap-1">
                                            <x-dynamic-component :component="'icons.'.$feature['icon']" class="w-4 h-4 flex-shrink-0" />
                                            <span>{{ $feature['title'] }}</span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="absolute inset-x-0 bottom-0 p-4 text-white">
                                <div class="space-y-3 rounded-[1rem] bg-slate-950/70 px-4 py-4 backdrop-blur">
                                    <h3 class="text-xl font-semibold leading-tight tracking-tight text-white">
                                        {{ $feature['title'] }}
                                    </h3>
                                    <div class="max-h-0 overflow-hidden opacity-0 transition-all duration-300 ease-out group-hover:max-h-28 group-hover:opacity-100">
                                        <p class="text-[11px] leading-5 text-slate-300">
                                            {{ $feature['description'] }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                    @endforeach
                </x-carousel>
            </div>
        </div>

        <div class="mt-20 text-center">
            <p class="text-xs uppercase tracking-[0.45em] text-sky-100/60">Learning through play</p>
            <h2 class="mx-auto mt-4 max-w-4xl font-history text-4xl leading-tight text-white md:text-6xl">
                Places a fully engaged class within reach.
            </h2>

            <a
                href="#contact"
                class="mt-8 inline-flex items-center gap-3 rounded-full border border-sky-400/40 bg-sky-600 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_20px_40px_rgba(14,165,233,0.25)] transition hover:bg-sky-500"
            >
                <span>Start Teaching History</span>
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>
