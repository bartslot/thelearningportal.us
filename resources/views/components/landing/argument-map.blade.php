@php
    $map      = json_decode(file_get_contents(resource_path('data/argument-map.json')), true);
    $root     = $map['root'];
    $branches = $root['children'];

    $borderColor = fn(string $type) => match($type) {
        'meta'     => 'border-l-sky-400/60',
        'solution' => 'border-l-emerald-400/60',
        default    => 'border-l-amber-500/60',
    };

    $badgeClass = fn(string $type) => match($type) {
        'meta'     => 'badge-info',
        'solution' => 'badge-success',
        default    => 'badge-warning',
    };

    $badgeLabel = fn(string $type) => match($type) {
        'meta'     => 'Tension',
        'solution' => 'Strategy',
        default    => 'Risk',
    };
@endphp

{{-- ── Argument Map section ──────────────────────────────────────────────── --}}
<section
    id="js-argument-map"
    class="relative isolate py-28 md:py-36 overflow-hidden"
    style="background: linear-gradient(180deg, #020617 0%, #0a1628 40%, #020617 100%);"
>

    {{-- Ambient glow blobs --}}
    <div class="pointer-events-none absolute left-1/4 top-0 h-96 w-96 -translate-x-1/2 rounded-full bg-amber-500/5 blur-3xl"></div>
    <div class="pointer-events-none absolute right-1/4 bottom-0 h-96 w-96 translate-x-1/2 rounded-full bg-sky-400/4 blur-3xl"></div>

    {{-- SVG connector overlay (desktop only — hidden on mobile via CSS) --}}
    <svg
        id="map-svg"
        class="pointer-events-none absolute inset-0 hidden md:block"
        style="width:100%; height:100%;"
        aria-hidden="true"
    ></svg>

    <div class="section-container relative z-10">

        {{-- ── Section header ──────────────────────────────────────────────── --}}
        <div class="mb-14 text-center">
            <span class="lp-label">The Great Debate</span>
            <h2 class="font-history mt-4 text-4xl text-white md:text-5xl">
                Will AI Take the Teaching Job?
            </h2>
            <p class="mx-auto mt-4 max-w-lg text-sm leading-relaxed text-slate-400/80">
                Six risk clusters. Two core tensions. One path forward.<br>
                Click any card to explore its arguments.
            </p>
        </div>

        {{-- ── Root node ────────────────────────────────────────────────────── --}}
        <div
            id="map-root-card"
            class="card mx-auto mb-12 max-w-2xl border border-amber-500/25 lp-grain"
            style="background: linear-gradient(135deg, rgba(245,158,11,0.10) 0%, rgba(245,158,11,0.04) 100%);"
        >
            <div class="card-body py-7 text-center">
                <span class="lp-label mx-auto mb-3 w-fit text-amber-400">Core Question</span>
                <h3 class="font-history text-2xl leading-snug text-white md:text-3xl">
                    {{ $root['label'] }}
                </h3>
                <p class="mt-2 text-xs text-slate-500">History / Social Studies · K-12</p>
            </div>
        </div>

        {{-- ── Branch cards grid ────────────────────────────────────────────── --}}
        <div
            id="map-branches-grid"
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
        >
            @foreach($branches as $branch)
                @php
                    $type        = $branch['type'] ?? 'risk_cluster';
                    $hasChildren = isset($branch['children']);
                    $childCount  = $hasChildren
                        ? count($branch['children'])
                        : count($branch['points'] ?? []);
                @endphp

                <div
                    class="map-branch-card card cursor-pointer border-l-4 transition-colors duration-200 hover:border-opacity-80 {{ $borderColor($type) }}"
                    style="background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%); border-top-color: transparent; border-right-color: transparent; border-bottom-color: transparent;"
                    data-type="{{ $type }}"
                    data-open="false"
                >

                    {{-- Toggle header --}}
                    <button
                        class="map-branch-toggle w-full cursor-pointer px-4 pb-2 pt-4 text-left"
                        type="button"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1">
                                <span class="badge badge-xs {{ $badgeClass($type) }} mb-2 opacity-80">
                                    {{ $badgeLabel($type) }}
                                </span>
                                <h3 class="text-xs font-semibold leading-snug text-white/90">
                                    {{ $branch['label'] }}
                                </h3>
                            </div>
                            {{-- Chevron icon --}}
                            <svg
                                class="map-toggle-icon mt-0.5 h-4 w-4 shrink-0 text-amber-400/60"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                aria-hidden="true"
                            >
                                <path d="M6 9l6 6 6-6"/>
                            </svg>
                        </div>
                        <p class="mt-2 text-[10px] text-slate-600">
                            {{ $childCount }} {{ $hasChildren ? 'nodes' : 'points' }} — tap to expand
                        </p>
                    </button>

                    {{-- Expandable body (GSAP controls height) --}}
                    <div class="map-branch-body">
                        <div class="space-y-2 px-3 pb-4 pt-1">

                            @if($hasChildren)
                                {{-- Cluster / meta: show child nodes --}}
                                @foreach($branch['children'] as $child)
                                    @php $isCounter = ($child['type'] ?? '') === 'counter'; @endphp
                                    <div class="rounded-lg p-3 {{ $isCounter
                                        ? 'border border-sky-400/20 bg-sky-400/5'
                                        : 'border border-white/6 bg-white/3' }}"
                                    >
                                        <h4 class="mb-1.5 text-[11px] font-semibold leading-snug
                                            {{ $isCounter ? 'text-sky-300' : 'text-amber-300/80' }}">
                                            {{ $child['label'] }}
                                        </h4>
                                        @if(!empty($child['points']))
                                            <ul class="space-y-1">
                                                @foreach($child['points'] as $point)
                                                    <li class="flex items-start gap-1.5 text-[10px] leading-relaxed text-slate-400/80">
                                                        <span class="mt-0.5 shrink-0 text-[8px]
                                                            {{ $isCounter ? 'text-sky-400/50' : 'text-amber-500/40' }}">
                                                            &#9656;
                                                        </span>
                                                        {{ $point }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @endforeach

                            @else
                                {{-- Solution leaf: show points directly --}}
                                @foreach(($branch['points'] ?? []) as $point)
                                    <div class="flex items-start gap-2 py-1 text-[11px] text-slate-300/80">
                                        <svg class="mt-0.5 h-3 w-3 shrink-0 text-emerald-400"
                                            viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2.5"
                                            aria-hidden="true"
                                        >
                                            <path d="M20 6L9 17l-5-5"/>
                                        </svg>
                                        {{ $point }}
                                    </div>
                                @endforeach
                            @endif

                        </div>
                    </div>

                </div>
            @endforeach
        </div>

        {{-- ── Footer note ──────────────────────────────────────────────────── --}}
        <p class="mt-10 text-center text-[11px] text-slate-700">
            Argument map · Will AI Take the Teaching Job? · History / Social Studies · The Learning Portal Thesis
        </p>

    </div>
</section>
