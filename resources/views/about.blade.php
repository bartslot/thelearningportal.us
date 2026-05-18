<x-layouts.landing title="About">

    <x-landing.header />

    {{-- ═══════════════════════════════════════════════════════════════════════
         ABOUT HERO
    ════════════════════════════════════════════════════════════════════════════ --}}
    <section
        id="about"
        class="relative isolate flex min-h-[100dvh] items-center overflow-hidden"
    >
        {{-- Background layers --}}
        <div class="pointer-events-none absolute inset-0 z-0"
             style="background: radial-gradient(ellipse 80% 60% at 50% 100%, #0d2a4a 0%, #020b24 55%, #010510 100%); opacity: 0.65;"></div>
        <div class="pointer-events-none absolute inset-0 z-0"
             style="background: radial-gradient(ellipse 50% 40% at 50% 60%, rgba(30,80,140,0.4) 0%, transparent 70%);"></div>
        <div class="pointer-events-none absolute left-1/2 top-[18%] z-0 h-[28rem] w-[28rem] -translate-x-1/2 rounded-full blur-3xl"
             style="background: radial-gradient(circle, rgba(56,189,248,0.12) 0%, transparent 70%);"></div>

        <div class="section-container relative z-10 pb-24 pt-36 lg:pb-0 lg:pt-0">
            <div class="grid items-center gap-12 lg:grid-cols-[3fr_2fr] xl:gap-20">

                {{-- ── Left: copy ──────────────────────────────────────────── --}}
                <div class="max-w-2xl">
                    <span class="lp-label mb-6 block">About The Learning Portal</span>

                    <h1 class="font-history text-5xl leading-[1.04] tracking-tight text-white md:text-6xl xl:text-7xl">
                        Where Storytelling<br>Meets Learning
                    </h1>

                    <p class="mt-7 max-w-lg text-base leading-relaxed text-slate-300/80">
                        We use storytelling to engage learners and make history come alive.
                        Julius Caesar narrates the fall of Rome. Cleopatra walks you through her
                        own dynasty. Abraham Lincoln explains the Civil War from the front lines.
                    </p>

                    <p class="mt-4 max-w-lg text-sm leading-relaxed text-slate-400/70">
                        Our AI-powered platform transforms K-12 history education — turning dry
                        facts into cinematic experiences that students actually remember. Currently
                        in beta and invite-only. Built for teachers who believe learning should feel
                        like an adventure.
                    </p>

                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <a
                            href="{{ route('login') }}"
                            class="btn btn-primary inline-flex items-center gap-2 rounded-full px-7"
                        >
                            Request invite
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </a>
                        <a
                            href="#js-argument-map"
                            class="flex items-center gap-1.5 text-sm text-slate-400 transition hover:text-white"
                        >
                            Explore our thesis
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M6 9l6 6 6-6"/>
                            </svg>
                        </a>
                    </div>
                </div>

                {{-- ── Right: feature cards (desktop only) ─────────────────── --}}
                <div class="hidden grid-cols-1 gap-3 lg:grid">

                    <div class="card lp-grain border border-amber-500/15"
                         style="background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);">
                        <div class="card-body p-5">
                            <span class="lp-label mb-2 text-amber-400">AI Narration</span>
                            <p class="text-sm leading-relaxed text-slate-300/80">
                                Historical figures narrate their own lessons. Every voice is generated from
                                primary sources — not invented.
                            </p>
                        </div>
                    </div>

                    <div class="card lp-grain border border-white/6"
                         style="background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);">
                        <div class="card-body p-5">
                            <span class="lp-label mb-2 text-sky-400">Teacher-First</span>
                            <p class="text-sm leading-relaxed text-slate-400/80">
                                Create a complete lesson in under two minutes. Set the topic, grade level,
                                and tone — the AI handles the rest.
                            </p>
                        </div>
                    </div>

                    <div class="card lp-grain border border-white/6"
                         style="background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);">
                        <div class="card-body p-5">
                            <span class="lp-label mb-2 text-slate-400">K-12 Aligned</span>
                            <p class="text-sm leading-relaxed text-slate-400/80">
                                Quiz questions auto-generated. Student progress tracked.
                                Content mapped to curriculum standards.
                            </p>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        {{-- Scroll hint --}}
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 opacity-40">
            <span class="text-[10px] uppercase tracking-widest text-slate-400">Scroll</span>
            <svg class="h-4 w-4 animate-bounce text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M6 9l6 6 6-6"/>
            </svg>
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════════════
         ARGUMENT MAP
    ════════════════════════════════════════════════════════════════════════════ --}}
    <x-landing.argument-map />

    <x-landing.footer />

    @push('scripts')
        @vite('resources/js/argument-map.js')
    @endpush

</x-layouts.landing>
