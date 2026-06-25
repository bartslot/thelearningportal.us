@php
    // Pricing copy & numbers live in config/pricing.php — edit there, not here.
    $terms       = config('pricing.terms');
    $defaultTerm = config('pricing.default_term');
    $tiers       = config('pricing.tiers');
    $finePrint   = config('pricing.fine_print');
@endphp

<section id="pricing" class="relative overflow-hidden border-t border-white/10 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 py-16 sm:py-24">
    <style>[x-cloak]{display:none!important}</style>

    {{-- soft amber portal glow, top-center --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-[radial-gradient(ellipse_at_top,_rgba(245,158,11,0.12),_transparent_60%)]"></div>

    <div class="section-container relative" x-data="{ term: '{{ $defaultTerm }}' }">
        {{-- ── Heading ──────────────────────────────────────────────────── --}}
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-xs uppercase tracking-[0.45em] text-sky-100/60">Pricing</p>
            <h2 class="mt-3 font-history text-3xl tracking-tight text-white md:text-4xl">
                Plans that grow with your school
            </h2>
            <p class="mt-4 text-base leading-relaxed text-slate-400">
                Cinematic, AI-narrated history lessons your teachers build in minutes.
                Commit longer and lock a lower rate — most schools choose the 2-year plan.
            </p>
        </div>

        {{-- ── Term selector ────────────────────────────────────────────── --}}
        <div class="mt-10 flex justify-center">
            <div class="inline-flex items-center gap-1 rounded-full border border-white/10 bg-white/5 p-1">
                @foreach ($terms as $key => $term)
                    <button
                        type="button"
                        @click="term = '{{ $key }}'"
                        :class="term === '{{ $key }}'
                            ? 'lp-bg-amber-cta text-slate-950 shadow-[0_2px_12px_rgba(245,158,11,0.4)]'
                            : 'text-slate-300 hover:text-white'"
                        class="inline-flex items-center gap-2 rounded-full px-5 py-2 text-sm font-semibold transition"
                    >
                        {{ $term['label'] }}
                        @if ($term['save'])
                            <span
                                :class="term === '{{ $key }}' ? 'bg-slate-950/15 text-slate-900' : 'bg-amber-500/15 text-amber-300'"
                                class="rounded-full px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-[0.08em]"
                            >{{ $term['save'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        {{-- ── Tier grid ────────────────────────────────────────────────── --}}
        <div class="mx-auto mt-12 grid max-w-6xl gap-6 lg:grid-cols-3 lg:items-stretch">
            @foreach ($tiers as $tier)
                <div @class([
                    'group relative flex flex-col rounded-[1.5rem] p-8 transition duration-300 ease-out',
                    'border border-amber-400/40 bg-gradient-to-b from-slate-800/90 to-slate-900/90 shadow-[0_24px_60px_rgba(245,158,11,0.18)] ring-1 ring-amber-400/30 lg:-my-3 lg:py-11' => $tier['featured'],
                    'border border-white/10 bg-white/[0.04] shadow-[0_16px_40px_rgba(0,0,0,0.35)] hover:-translate-y-1 hover:border-amber-400/30 hover:bg-white/[0.06]' => ! $tier['featured'],
                ])>
                    @if (! empty($tier['badge']))
                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full border border-amber-400/40 bg-amber-500/15 px-3 py-1 text-[0.7rem] font-medium uppercase tracking-[0.1em] text-amber-300">
                            {{ $tier['badge'] }}
                        </span>
                    @endif

                    {{-- name + tagline --}}
                    <h3 class="font-history text-2xl text-white">{{ $tier['name'] }}</h3>
                    <p class="mt-2 min-h-[2.5rem] text-sm leading-relaxed text-slate-400">{{ $tier['tagline'] }}</p>

                    {{-- price --}}
                    <div class="mt-6 min-h-[5.25rem]">
                        @if (! empty($tier['prices']))
                            @foreach ($terms as $key => $term)
                                <div x-show="term === '{{ $key }}'" x-cloak>
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-medium text-slate-400">$</span>
                                        <span @class([
                                            'font-history text-5xl tracking-tight',
                                            'lp-text-shimmer' => $tier['featured'],
                                            'text-white' => ! $tier['featured'],
                                        ])>{{ $tier['prices'][$key]['per_year'] }}</span>
                                        <span class="text-sm text-slate-400">/ year</span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        @if ((string) $key === '1')
                                            Billed annually
                                        @else
                                            ${{ $tier['prices'][$key]['total'] }} total over {{ $key }} years · {{ $term['save'] }}
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        @else
                            <div class="flex items-baseline gap-2">
                                <span class="font-history text-4xl tracking-tight text-white">{{ $tier['price_label'] }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Custom pricing · any term</p>
                        @endif
                    </div>

                    {{-- divider glow --}}
                    <div class="mt-5 h-px w-full bg-gradient-to-r from-transparent via-amber-500/40 to-transparent"></div>

                    {{-- features --}}
                    <ul class="mt-6 flex-1 space-y-3 text-sm">
                        @foreach ($tier['features'] as $feature)
                            <li class="flex items-start gap-3 text-slate-300">
                                <svg class="mt-0.5 h-5 w-5 flex-none text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>

                    {{-- CTA --}}
                    <a
                        href="{{ $tier['href'] }}"
                        @class([
                            'mt-8 inline-flex w-full items-center justify-center rounded-full px-6 py-3 text-sm font-semibold transition',
                            'lp-bg-amber-cta text-slate-950 shadow-[0_2px_12px_rgba(245,158,11,0.4)] hover:shadow-[0_0_40px_rgba(245,158,11,0.45)]' => $tier['featured'],
                            'border border-amber-400/45 text-amber-300 hover:border-amber-400 hover:bg-amber-500/10' => ! $tier['featured'],
                        ])
                    >
                        {{ $tier['cta'] }}
                    </a>
                </div>
            @endforeach
        </div>

        {{-- reassurance + honest renewal disclosure --}}
        <p class="mx-auto mt-10 max-w-2xl text-center text-xs leading-relaxed text-slate-500">
            {{ $finePrint }}
        </p>
    </div>
</section>
