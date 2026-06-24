@php
    $popular = [
        [
            'title' => 'The Punic Wars',
            'category' => 'Ancient History',
            'image' => asset('history/popular1.jpg'),
            'description' => 'Three devastating wars between Rome and Carthage that shaped the ancient Mediterranean world.',
        ],
        [
            'title' => 'Abraham Lincoln',
            'category' => 'American History',
            'image' => asset('history/popular2.jpg'),
            'description' => 'The 16th President who led America through the Civil War and abolished slavery.',
        ],
        [
            'title' => 'Colosseum',
            'category' => 'Roman Empire',
            'image' => asset('history/popular3.jpg'),
            'description' => "Rome's iconic amphitheater where gladiators fought before 50,000 spectators.",
        ],
        [
            'title' => 'Gladiators',
            'category' => 'Roman Culture',
            'image' => asset('history/popular4.jpg'),
            'description' => 'Professional fighters who battled in arenas for entertainment and honor in ancient Rome.',
        ],
        [
            'title' => 'Silk Road',
            'category' => 'Trade Routes',
            'image' => asset('history/popular5.jpg'),
            'description' => 'Ancient network of trade routes connecting East and West for over 1,400 years.',
        ],
    ];

    $features = [
        [
            'title' => 'Interactive Lessons',
            'description' => 'Interaction energizes your class. Techniques that prompt students to respond in real time keep attention high and make the material easier to absorb.',
            'image' => asset('history/6history.jpg'),
            'icon' => '📚',
        ],
        [
            'title' => 'Engaged Students',
            'description' => 'Learning can be fun. With gamification, lessons feel active and memorable, improving engagement and retention in class.',
            'image' => asset('history/12history.jpg'),
            'icon' => '🎮',
        ],
        [
            'title' => 'Track Progress',
            'description' => 'Track each student’s progress and see how lessons perform through a clear dashboard built for teachers.',
            'image' => asset('history/18history.jpg'),
            'icon' => '📊',
        ],
        [
            'title' => 'Made for Teachers',
            'description' => 'The editor helps teachers refine, adjust, and enhance content. Building a new lesson is faster and easier with the right tools.',
            'image' => asset('history/24history.jpg'),
            'icon' => '👩‍🏫',
        ],
    ];
@endphp

<section id="popular" class="relative overflow-hidden border-t border-white/10 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 py-16 sm:py-20">
    <div class="section-container">
        <div class="mb-12 flex items-end justify-between gap-4">
            <div>
                <p class="text-[10px] uppercase tracking-[0.5em] text-sky-100/60">Most popular</p>
                <h2 class="mt-3 font-history text-3xl tracking-tight text-white md:text-4xl">Trending history topics</h2>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-[2rem]">
            <!-- <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-16 bg-gradient-to-r from-slate-950 via-slate-950/75 to-transparent"></div> -->
            <!-- <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-gradient-to-l from-slate-950 via-slate-950/75 to-transparent"></div> -->

            <div class="js-disney-carousel">
                @foreach ($popular as $item)
                <article class="carousel-cell group relative mx-2 w-[16rem] shrink-0 sm:w-[17rem] lg:w-[18rem]">
                    <div class="relative aspect-[5/8] overflow-hidden rounded-[1.35rem] border border-white/10 bg-black/40 shadow-[0_16px_34px_rgba(0,0,0,0.35)] transition duration-300 ease-out hover:-translate-y-1 hover:border-sky-400/25 hover:shadow-[0_24px_52px_rgba(0,0,0,0.5)]">
                        <img
                            src="{{ $item['image'] }}"
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
                                <div class="max-h-0 overflow-hidden opacity-0 transition-all duration-300 ease-out-ease-[cubic-bezier(0.95,0.05,0.795,0.035)] group-hover:max-h-28 group-hover:opacity-100">
                                <p class="text-[11px] leading-5 text-slate-200/90">
                                        {{ $item['description'] }}
                                    </p>
                                    <div class="min-w-0">
                                    
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
                @endforeach
            </div>
        </div>

        <div id="features" class="mt-20">
            <p class="text-xs uppercase tracking-[0.45em] text-sky-100/60">Teaching tools</p>
            <h2 class="mt-3 font-history text-3xl tracking-tight text-sky-100 md:text-4xl">Built for better history teaching</h2>

            <div class="relative overflow-hidden rounded-[2rem] mt-8">
                <!-- <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-16 bg-gradient-to-r from-slate-950 via-slate-950/75 to-transparent"></div> -->
                <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-gradient-to-l from-slate-950 via-slate-950/75 to-transparent"></div>

                <div class="js-disney-carousel">
                    @foreach ($features as $feature)
                    <article class="carousel-cell group relative mx-2 w-[15rem] shrink-0 sm:w-[16rem] lg:w-[17rem]">
                        <div class="relative aspect-[5/8] overflow-hidden rounded-[1.35rem] border border-white/10 bg-white/5 shadow-[0_16px_34px_rgba(0,0,0,0.25)] transition duration-300 ease-out hover:-translate-y-1 hover:border-sky-400/20 hover:bg-white/[0.07]">
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
                                        <p class="truncate text-[10px] font-medium text-white/85">{{ $feature['icon'] }} {{ $feature['title'] }}</p>
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
                </div>
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
