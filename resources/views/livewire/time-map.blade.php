@push('head-scripts')
    @vite('resources/js/timemap/index.js')
@endpush

<div class="relative h-[calc(100vh-4rem)] w-full"
     x-data="{}"
     x-init="$nextTick(() => window.initTimeMap($refs.map, $wire, {{ $year }}))">
    {{-- Map canvas. Use h-full/w-full (not absolute inset-0): MapLibre's own CSS forces
         position:relative on the container, which cancels inset-0 and collapses it to 0 height. --}}
    <div x-ref="map" class="h-full w-full" wire:ignore></div>

    {{-- Polity info panel (TimeMap.org-style) --}}
    <aside x-data="{ tab: 'summary', polity: null, loading: false }"
           x-on:polity-selected.window="
                if (!$event.detail.id) { polity = null; loading = false; return; }
                loading = true; polity = null; tab = 'summary';
                fetch('/teacher/timemap/polity/' + $event.detail.id + '?name=' + encodeURIComponent($event.detail.name || '') + ($event.detail.qid ? '&qid=' + encodeURIComponent($event.detail.qid) : ''))
                    .then(r => r.json()).then(d => { polity = d; loading = false; });
           "
           class="absolute left-0 top-0 z-10 h-full w-80 overflow-y-auto bg-base-100/95 p-4 shadow-xl">
        <template x-if="!polity && !loading">
            <p class="opacity-70">{{ __('Click a region') }}</p>
        </template>
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

                <div role="tablist" class="tabs tabs-bordered mt-3">
                    <a role="tab" class="tab" :class="tab==='summary' && 'tab-active'" x-on:click="tab='summary'">{{ __('Summary') }}</a>
                    <a role="tab" class="tab" :class="tab==='wikipedia' && 'tab-active'" x-on:click="tab='wikipedia'">{{ __('Wikipedia') }}</a>
                    <a role="tab" class="tab" :class="tab==='overtime' && 'tab-active'" x-on:click="tab='overtime'">{{ __('Over Time') }}</a>
                </div>

                <div class="mt-3 text-sm">
                    <p x-show="tab==='summary'" x-text="polity.summary || '{{ __('No summary yet.') }}'"></p>
                    <div x-show="tab==='wikipedia'">
                        <template x-if="polity.wikipedia_url">
                            <a :href="polity.wikipedia_url" target="_blank" rel="noopener" class="link link-primary">{{ __('Open on Wikipedia ↗') }}</a>
                        </template>
                        <p x-show="!polity.wikipedia_url" class="opacity-70">{{ __('No Wikipedia page linked.') }}</p>
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
</div>
