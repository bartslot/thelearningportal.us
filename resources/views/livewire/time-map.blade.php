@push('head-scripts')
    @vite('resources/js/timemap/index.js')
@endpush

<div class="fixed inset-x-0 bottom-0 top-16 z-20"
     x-data="{}"
     x-init="$nextTick(() => window.initTimeMap($refs.map, $wire, {{ $year }}))">
    {{-- Map canvas fills the full viewport (below the navbar). Use h-full/w-full (not absolute
         inset-0): MapLibre's own CSS forces position:relative on the container, which cancels
         inset-0 and collapses it to 0 height. --}}
    <div x-ref="map" class="h-full w-full" wire:ignore></div>

    {{-- Polity info panel — a floating card that overlays the map only after a region is clicked. --}}
    <aside x-data="{ tab: 'summary', polity: null, loading: false }"
           x-show="polity || loading"
           x-transition.opacity.duration.150ms
           x-on:polity-selected.window="
                if (!$event.detail.id) { polity = null; loading = false; return; }
                tab = 'summary';
                if ($event.detail.articleUrl) {
                    // Curated external-article marker (e.g. worldhistory.org) — render directly, no server call.
                    polity = { label: $event.detail.name, summary: $event.detail.summary || null,
                               wikipedia_url: $event.detail.articleUrl,
                               inception: $event.detail.inception ?? null, dissolution: $event.detail.dissolution ?? null,
                               flag_path: null, predecessor: null, successor: null };
                    loading = false; return;
                }
                // Instant from the prefetch cache when available; else fetch (and cache).
                const cached = (window.__polityCache || {})[$event.detail.id];
                if (cached) { polity = { ...cached, label: $event.detail.name || cached.label }; loading = false; return; }
                loading = true; polity = null;
                fetch('/teacher/timemap/polity/' + $event.detail.id + '?name=' + encodeURIComponent($event.detail.name || '') + ($event.detail.qid ? '&qid=' + encodeURIComponent($event.detail.qid) : ''))
                    .then(r => r.json()).then(d => { polity = d; loading = false; (window.__polityCache = window.__polityCache || {})[$event.detail.id] = d; });
           "
           class="absolute left-4 top-4 z-20 max-h-[calc(100%-7rem)] w-80 overflow-y-auto rounded-box bg-base-100/95 p-4 shadow-xl"
           style="display:none">
        <template x-if="loading">
            <p class="flex items-center gap-2"><span class="loading loading-spinner loading-sm"></span> {{ __('Loading…') }}</p>
        </template>
        <template x-if="polity">
            <div>
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <template x-if="polity.flag_path"><img :src="polity.flag_path" class="h-5 rounded-sm shadow" alt=""></template>
                        <h2 class="text-lg font-bold" x-text="polity.label"></h2>
                    </div>
                    <button class="btn btn-ghost btn-xs" x-on:click="polity = null">✕</button>
                </div>
                <p class="text-xs opacity-70"
                   x-text="(polity.inception != null ? (polity.inception < 0 ? Math.abs(polity.inception)+' BCE' : polity.inception+' CE') : '?') + ' – ' + (polity.dissolution != null ? (polity.dissolution < 0 ? Math.abs(polity.dissolution)+' BCE' : polity.dissolution+' CE') : '')"></p>

                {{-- Start a lesson about this territory (prefills the wizard topic). --}}
                <a :href="'{{ route('teacher.lessons.create') }}?topic=' + encodeURIComponent(polity.label)"
                   wire:navigate
                   class="btn btn-warning btn-sm mt-3 w-full gap-2 font-semibold">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 00-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.54 1.118l-3.367-2.447a1 1 0 00-1.175 0l-3.367 2.447c-.784.57-1.838-.196-1.539-1.118l1.286-3.957a1 1 0 00-.363-1.118L2.343 9.384c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z"/></svg>
                    {{ __('Create lesson') }}
                </a>

                <div role="tablist" class="tabs tabs-bordered mt-3">
                    <a role="tab" class="tab" :class="tab==='summary' && 'tab-active'" x-on:click="tab='summary'">{{ __('Summary') }}</a>
                    <a role="tab" class="tab" :class="tab==='wikipedia' && 'tab-active'" x-on:click="tab='wikipedia'">{{ __('Article') }}</a>
                    <a role="tab" class="tab" :class="tab==='overtime' && 'tab-active'" x-on:click="tab='overtime'">{{ __('Over Time') }}</a>
                </div>

                <div class="mt-3 text-sm">
                    <p x-show="tab==='summary'" x-text="polity.summary || '{{ __('No summary yet.') }}'"></p>
                    <div x-show="tab==='wikipedia'">
                        <template x-if="polity.wikipedia_url">
                            <a :href="polity.wikipedia_url" target="_blank" rel="noopener" class="link link-primary"
                               x-text="(polity.wikipedia_url.includes('worldhistory.org') ? '{{ __('Open on World History Encyclopedia') }}' : '{{ __('Open on Wikipedia') }}') + ' ↗'"></a>
                        </template>
                        <p x-show="!polity.wikipedia_url" class="opacity-70">{{ __('No article linked.') }}</p>
                    </div>
                    <div x-show="tab==='overtime'" class="space-y-1">
                        <p><span class="opacity-70">{{ __('Preceded by') }}:</span> <span x-text="polity.predecessor || '—'"></span></p>
                        <p><span class="opacity-70">{{ __('Succeeded by') }}:</span> <span x-text="polity.successor || '—'"></span></p>
                    </div>
                </div>
            </div>
        </template>
    </aside>

    {{-- Time slider (oldmapsonline-style) --}}
    <div class="absolute bottom-0 left-1/2 z-10 mb-6 w-[44rem] max-w-[92vw] -translate-x-1/2 rounded-box bg-base-100/95 px-5 py-3 shadow-xl"
         x-ref="sliderbox"
         x-init="$nextTick(() => window.mountAtlasSlider($refs.sliderbox, $refs.map, {{ $year }}))">
    </div>

    {{-- Map-style switcher: a round amber palette button → dropdown that restyles the map live. --}}
    <div class="absolute right-4 top-4 z-30"
         x-data="{ open: false, style: (window.localStorage.getItem('tm-style') || 'soft-atlas'),
                   items: [['soft-atlas','Soft Atlas'],['antique','Hand-coloured Antique'],['pen-ink','Pen & Ink'],['night','Night']] }"
         x-on:click.outside="open = false">
        <button type="button" x-on:click="open = !open" aria-label="{{ __('Map style') }}"
                class="btn btn-circle border-none bg-warning text-black shadow-lg hover:bg-warning">
            <svg class="h-6 w-6" viewBox="0 0 100 100" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="m71.34 26.148c-7.8477-7.0195-18.648-9.7305-28.879-7.2461-10.234 2.4805-18.59 9.8398-22.348 19.676-3.7578 9.8359-2.4375 20.891 3.5312 29.566 5.9727 8.6719 15.824 13.855 26.355 13.855h2.3398c3.9023-0.003906 7.418-2.3555 8.9141-5.9609 1.4922-3.6055 0.67188-7.7539-2.082-10.52l-4.6914-4.6914c-0.47266-0.47266-0.61328-1.1836-0.35547-1.8008 0.25391-0.62109 0.85547-1.0234 1.5234-1.0273h18.352c2.1211 0 4.1562-0.84375 5.6562-2.3438s2.3438-3.5352 2.3438-5.6562c-0.003906-9.1016-3.8828-17.773-10.66-23.852zm-15.691 23.852c-3.9023 0.003906-7.418 2.3555-8.9102 5.9609-1.4961 3.6055-0.67188 7.7539 2.082 10.52l4.6914 4.6914c0.46875 0.47266 0.60938 1.1836 0.35547 1.8008-0.25781 0.62109-0.85938 1.0234-1.5273 1.0273h-2.3398c-8.2656 0.023438-15.961-4.2031-20.371-11.191-4.4062-6.9922-4.9102-15.758-1.332-23.207 3.582-7.4453 10.742-12.531 18.953-13.453 0.91406-0.097657 1.832-0.14844 2.75-0.14844 5.9102-0.023438 11.613 2.1602 16 6.1211 5.0898 4.5508 7.9961 11.051 8 17.879z"/>
                <path d="m62 38c0 5.332-8 5.332-8 0s8-5.332 8 0"/>
                <path d="m46 38c0 5.332-8 5.332-8 0s8-5.332 8 0"/>
                <path d="m42 54c0 5.332-8 5.332-8 0s8-5.332 8 0"/>
            </svg>
        </button>
        <ul x-show="open" x-transition.opacity style="display:none"
            class="menu absolute right-0 mt-2 w-56 rounded-box bg-base-100/95 p-2 shadow-xl">
            <li class="menu-title text-xs">{{ __('Map style') }}</li>
            <template x-for="it in items" :key="it[0]">
                <li><a x-on:click="style = it[0]; window.__applyMapStyle && window.__applyMapStyle(it[0]); open = false"
                       :class="{ 'active font-semibold': style === it[0] }" x-text="it[1]"></a></li>
            </template>
        </ul>
    </div>
</div>
