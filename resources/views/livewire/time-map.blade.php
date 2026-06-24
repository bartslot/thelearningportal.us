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

    {{-- Papery overlay: a tileable parchment grain blended over the whole map for an old-paper feel.
         pointer-events-none so it never blocks map interaction. Swap public/timemap/parchment.png
         for your own scan to change the paper. --}}
    <div class="pointer-events-none absolute inset-0 z-[5]"
         style="mix-blend-mode:overlay;opacity:0.2;background-image:url('{{ asset('timemap/parchment.png') }}');background-repeat:repeat;background-size:360px 360px"></div>

    {{-- Polity info panel — a floating card that overlays the map only after a region is clicked. --}}
    <aside x-data="{ tab: 'summary', polity: null, loading: false, thumb: null, lead: null, leadLoading: false, leadFailed: false, selected: [] }"
           x-show="polity || loading"
           x-transition.opacity.duration.150ms
           x-on:polity-selected.window="
                window.__timemapStopSpeak && window.__timemapStopSpeak();
                if (!$event.detail.id) { polity = null; loading = false; return; }
                tab = 'summary'; selected = [];
                if ($event.detail.articleUrl) {
                    // Curated external-article marker (e.g. worldhistory.org) — render directly, no server call.
                    polity = { label: $event.detail.name, summary: $event.detail.summary || null,
                               wikipedia_url: $event.detail.articleUrl,
                               inception: $event.detail.inception ?? null, dissolution: $event.detail.dissolution ?? null,
                               flag_path: null, predecessor: null, successor: null };
                    loading = false;
                    window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity);
                    window.__timemapSpeak && window.__timemapSpeak($event.detail.id, polity.summary);
                    return;
                }
                // Instant from the prefetch cache when available; else fetch (and cache).
                const cached = (window.__polityCache || {})[$event.detail.id];
                if (cached) {
                    polity = { ...cached, label: $event.detail.name || cached.label }; loading = false;
                    window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity);
                    window.__timemapSpeak && window.__timemapSpeak($event.detail.id, polity.summary);
                    return;
                }
                loading = true; polity = null;
                fetch('/teacher/timemap/polity/' + $event.detail.id + '?name=' + encodeURIComponent($event.detail.name || '') + ($event.detail.qid ? '&qid=' + encodeURIComponent($event.detail.qid) : ''))
                    .then(r => r.json()).then(d => { polity = d; loading = false; (window.__polityCache = window.__polityCache || {})[$event.detail.id] = d; window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity); window.__timemapSpeak && window.__timemapSpeak($event.detail.id, d.summary); });
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
                    <button class="btn btn-ghost btn-xs" x-on:click="polity = null; window.__timemapStopSpeak && window.__timemapStopSpeak()">✕</button>
                </div>
                {{-- Both years scrub the timeline to that era. --}}
                <p class="text-xs opacity-70">
                    <template x-if="polity.inception != null">
                        <a class="link link-hover cursor-pointer font-medium"
                           x-on:click="window.__setTimemapYear && window.__setTimemapYear(polity.inception)"
                           x-text="polity.inception < 0 ? Math.abs(polity.inception)+' BCE' : polity.inception+' CE'"></a>
                    </template>
                    <template x-if="polity.inception == null"><span>?</span></template>
                    <span> – </span>
                    <template x-if="polity.dissolution != null">
                        <a class="link link-hover cursor-pointer font-medium"
                           x-on:click="window.__setTimemapYear && window.__setTimemapYear(polity.dissolution)"
                           x-text="polity.dissolution < 0 ? Math.abs(polity.dissolution)+' BCE' : polity.dissolution+' CE'"></a>
                    </template>
                </p>

                {{-- Wikipedia thumbnail (fetched client-side from the CORS REST API). --}}
                <template x-if="thumb">
                    <figure class="mt-3 overflow-hidden rounded-lg">
                        <img :src="thumb" :alt="polity.label" class="h-36 w-full object-cover" loading="lazy">
                        <figcaption class="bg-base-200 px-2 py-0.5 text-[10px] opacity-60">{{ __('Image: Wikimedia Commons') }}</figcaption>
                    </figure>
                </template>

                {{-- Start a lesson about this territory (prefills the wizard topic). --}}
                <a :href="'{{ route('teacher.lessons.create') }}?topic=' + encodeURIComponent(polity.label) + (selected.length ? '&protagonist_qid=' + encodeURIComponent(selected[0].qid) + '&protagonist_name=' + encodeURIComponent(selected[0].name) : '')"
                   wire:navigate
                   class="btn btn-warning btn-sm mt-3 w-full gap-2 font-semibold">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 00-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.54 1.118l-3.367-2.447a1 1 0 00-1.175 0l-3.367 2.447c-.784.57-1.838-.196-1.539-1.118l1.286-3.957a1 1 0 00-.363-1.118L2.343 9.384c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z"/></svg>
                    <span x-text="selected.length ? '{{ __('Create lesson with') }} ' + selected[0].name + (selected.length > 1 ? ' +' + (selected.length - 1) : '') : '{{ __('Create lesson') }}'"></span>
                </a>

                <div role="tablist" class="tabs tabs-bordered mt-3">
                    <a role="tab" class="tab" :class="tab==='summary' && 'tab-active'" x-on:click="tab='summary'">{{ __('Summary') }}</a>
                    <a role="tab" class="tab" :class="tab==='people' && 'tab-active'" x-on:click="tab='people'">{{ __('People') }}</a>
                    <a role="tab" class="tab" :class="tab==='overtime' && 'tab-active'" x-on:click="tab='overtime'">{{ __('Over Time') }}</a>
                </div>

                <div class="mt-3 text-sm">
                    {{-- Summary, with the Wikipedia article link underneath it. --}}
                    <div x-show="tab==='summary'" class="space-y-2">
                        <p x-text="polity.summary || '{{ __('No summary yet.') }}'"></p>
                        <template x-if="polity.wikipedia_url && polity.wikipedia_url.includes('wikipedia.org')">
                            <div>
                                <p x-show="lead" x-text="lead" class="whitespace-pre-line leading-relaxed"></p>
                                <button x-show="!lead && !leadLoading && !leadFailed"
                                        x-on:click="window.__timemapPanelReadMore && window.__timemapPanelReadMore($data, polity)"
                                        class="btn btn-outline btn-xs mt-1">{{ __('Read more') }}</button>
                                <p x-show="leadLoading" class="flex items-center gap-2 opacity-70"><span class="loading loading-spinner loading-xs"></span> {{ __('Loading article…') }}</p>
                                <a :href="polity.wikipedia_url" target="_blank" rel="noopener"
                                   class="link link-primary mt-1 inline-block text-xs">{{ __('Open on Wikipedia') }} ↗</a>
                            </div>
                        </template>
                        <template x-if="polity.wikipedia_url && !polity.wikipedia_url.includes('wikipedia.org')">
                            <a :href="polity.wikipedia_url" target="_blank" rel="noopener" class="link link-primary text-xs"
                               x-text="'{{ __('Open on World History Encyclopedia') }}' + ' ↗'"></a>
                        </template>
                    </div>

                    {{-- People: rulers + notable figures of this polity (corpus). The toggle selects a figure
                         for the "Create lesson" button, feeding it into the lesson wizard as the protagonist. --}}
                    <div x-show="tab==='people'" class="space-y-2">
                        <template x-if="!polity.figures || polity.figures.length === 0">
                            <p class="opacity-70">{{ __('No people linked yet for this territory.') }}</p>
                        </template>
                        <template x-for="f in (polity.figures || [])" :key="f.qid">
                            <div class="flex items-center gap-3 rounded-lg border border-base-300 p-2">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-base-300">
                                    <template x-if="f.image_url">
                                        <img :src="f.image_url + '?width=80'" :alt="f.name" class="h-full w-full object-cover" loading="lazy">
                                    </template>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold leading-tight" x-text="f.name"></p>
                                    <p class="truncate text-xs capitalize opacity-60" x-text="[f.kind, f.era].filter(Boolean).join(' · ')"></p>
                                </div>
                                <button type="button"
                                        x-on:click="selected.some(s => s.qid === f.qid) ? (selected = selected.filter(s => s.qid !== f.qid)) : (selected = [...selected, f])"
                                        class="btn btn-xs shrink-0"
                                        :class="selected.some(s => s.qid === f.qid) ? 'btn-success' : 'btn-outline'"
                                        x-text="selected.some(s => s.qid === f.qid) ? '✓ {{ __('Selected') }}' : '{{ __('Use in lesson') }}'">
                                </button>
                            </div>
                        </template>
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

    {{-- Settings: a cog that fans out to a palette (map style) and a sound (read-aloud) toggle. --}}
    <div class="absolute right-4 top-4 z-30"
         x-data="{ settingsOpen: false, paletteOpen: false,
                   style: (window.localStorage.getItem('tm-style') || 'soft-atlas'),
                   sound: (window.localStorage.getItem('tm-sound') === '1'),
                   items: [['soft-atlas','Soft Atlas'],['antique','Hand-coloured Antique'],['pen-ink','Tolkien'],['night','Night']] }"
         x-on:click.outside="settingsOpen = false; paletteOpen = false">

        {{-- Cog --}}
        <button type="button" x-on:click="settingsOpen = !settingsOpen; if (!settingsOpen) paletteOpen = false"
                aria-label="{{ __('Settings') }}"
                class="btn btn-circle border-none bg-warning text-black shadow-lg hover:bg-warning">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.85 1.567L9.05 4.889c-.02.12-.115.26-.297.348a7.493 7.493 0 0 0-.986.57c-.166.115-.334.126-.45.083L6.3 5.508a1.875 1.875 0 0 0-2.282.819l-.922 1.597a1.875 1.875 0 0 0 .432 2.385l.84.692c.095.078.17.229.154.43a7.6 7.6 0 0 0 0 1.139c.016.2-.059.352-.153.43l-.841.692a1.875 1.875 0 0 0-.432 2.385l.922 1.597a1.875 1.875 0 0 0 2.282.818l1.019-.382c.115-.043.283-.031.45.082.312.214.641.405.985.57.182.088.277.228.297.35l.178 1.071c.151.904.933 1.567 1.85 1.567h1.844c.916 0 1.699-.663 1.85-1.567l.178-1.072c.02-.12.114-.26.297-.349.344-.165.673-.356.985-.57.167-.114.335-.125.45-.082l1.02.382a1.875 1.875 0 0 0 2.28-.819l.923-1.597a1.875 1.875 0 0 0-.432-2.385l-.84-.692c-.095-.078-.17-.229-.154-.43a7.6 7.6 0 0 0 0-1.139c-.016-.2.059-.352.153-.43l.84-.692c.708-.582.891-1.59.433-2.385l-.922-1.597a1.875 1.875 0 0 0-2.282-.818l-1.02.382c-.114.043-.282.031-.449-.083a7.49 7.49 0 0 0-.985-.57c-.183-.087-.277-.227-.297-.348l-.179-1.072a1.875 1.875 0 0 0-1.85-1.567h-1.843ZM12 15.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z"/>
            </svg>
        </button>

        {{-- Palette sub-button (to the left) --}}
        <div class="absolute top-0" style="right: 3.75rem; display: none;" x-show="settingsOpen" x-transition>
            <button type="button" x-on:click="paletteOpen = !paletteOpen" aria-label="{{ __('Map style') }}"
                    class="btn btn-circle border-none bg-base-100 text-base-content shadow-lg">
                <svg class="h-6 w-6" viewBox="0 0 100 100" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path d="m71.34 26.148c-7.8477-7.0195-18.648-9.7305-28.879-7.2461-10.234 2.4805-18.59 9.8398-22.348 19.676-3.7578 9.8359-2.4375 20.891 3.5312 29.566 5.9727 8.6719 15.824 13.855 26.355 13.855h2.3398c3.9023-0.003906 7.418-2.3555 8.9141-5.9609 1.4922-3.6055 0.67188-7.7539-2.082-10.52l-4.6914-4.6914c-0.47266-0.47266-0.61328-1.1836-0.35547-1.8008 0.25391-0.62109 0.85547-1.0234 1.5234-1.0273h18.352c2.1211 0 4.1562-0.84375 5.6562-2.3438s2.3438-3.5352 2.3438-5.6562c-0.003906-9.1016-3.8828-17.773-10.66-23.852zm-15.691 23.852c-3.9023 0.003906-7.418 2.3555-8.9102 5.9609-1.4961 3.6055-0.67188 7.7539 2.082 10.52l4.6914 4.6914c0.46875 0.47266 0.60938 1.1836 0.35547 1.8008-0.25781 0.62109-0.85938 1.0234-1.5273 1.0273h-2.3398c-8.2656 0.023438-15.961-4.2031-20.371-11.191-4.4062-6.9922-4.9102-15.758-1.332-23.207 3.582-7.4453 10.742-12.531 18.953-13.453 0.91406-0.097657 1.832-0.14844 2.75-0.14844 5.9102-0.023438 11.613 2.1602 16 6.1211 5.0898 4.5508 7.9961 11.051 8 17.879z"/>
                    <path d="m62 38c0 5.332-8 5.332-8 0s8-5.332 8 0"/>
                    <path d="m46 38c0 5.332-8 5.332-8 0s8-5.332 8 0"/>
                    <path d="m42 54c0 5.332-8 5.332-8 0s8-5.332 8 0"/>
                </svg>
            </button>
            <ul x-show="paletteOpen" x-transition.opacity style="display:none"
                class="menu absolute right-0 mt-2 w-56 rounded-box bg-base-100/95 p-2 shadow-xl">
                <li class="menu-title text-xs">{{ __('Map style') }}</li>
                <template x-for="it in items" :key="it[0]">
                    <li><a x-on:click="style = it[0]; window.__applyMapStyle && window.__applyMapStyle(it[0]); paletteOpen = false"
                           :class="{ 'active font-semibold': style === it[0] }" x-text="it[1]"></a></li>
                </template>
            </ul>
        </div>

        {{-- Sound sub-button (bottom-left): read summaries aloud via ElevenLabs --}}
        <div class="absolute" style="right: 2.5rem; top: 3.75rem; display: none;" x-show="settingsOpen" x-transition>
            <button type="button"
                    x-on:click="sound = !sound; window.localStorage.setItem('tm-sound', sound ? '1' : '0'); window.__timemapSoundOn = sound; if (!sound && window.__timemapStopSpeak) window.__timemapStopSpeak();"
                    :aria-label="sound ? '{{ __('Sound on') }}' : '{{ __('Sound off') }}'"
                    class="btn btn-circle border-none shadow-lg" :class="sound ? 'bg-success text-white' : 'bg-base-100 text-base-content'">
                {{-- Sound on: wave/equalizer bars --}}
                <svg x-show="sound" class="h-6 w-6" viewBox="0 0 100 100" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path d="m39 35h6v30h-6z"/><path d="m55 19h6v62h-6z"/><path d="m23 27h6v46h-6z"/><path d="m71 43h6v14h-6z"/>
                </svg>
                {{-- Sound off: muted speaker --}}
                <svg x-show="!sound" style="display:none" class="h-6 w-6" viewBox="0 0 100 100" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path d="m97.828 53.828c0.58594 0.57031 0.91797 1.3477 0.92188 2.1641 0.007812 0.8125-0.3125 1.5977-0.89062 2.1719-0.57422 0.57813-1.3594 0.89844-2.1758 0.89453-0.8125-0.007813-1.5938-0.33984-2.1602-0.92188l-3.8281-3.8281-3.8281 3.8281h-0.003907c-1.1875 1.1875-3.1172 1.1875-4.3047 0-1.1914-1.1875-1.1914-3.1172-0.003906-4.3086l3.832-3.8281-3.832-3.8281c-1.1602-1.1953-1.1484-3.1016 0.03125-4.2773 1.1758-1.1797 3.082-1.1914 4.2773-0.03125l3.8281 3.8281 3.8281-3.8281c0.57031-0.58203 1.3477-0.91406 2.1641-0.92188 0.8125-0.003906 1.5977 0.31641 2.1719 0.89453 0.57812 0.57422 0.89844 1.3594 0.89453 2.1719-0.007812 0.81641-0.33984 1.5938-0.92188 2.1641l-3.8281 3.8281zm-21.801-3.8281c-0.003906 3.7578-1.3594 7.3867-3.8203 10.227-2.457 2.8398-5.8555 4.6992-9.5742 5.2383v11.922c0.011719 3.0273-1.5977 5.832-4.2227 7.3477-2.6211 1.5156-5.8555 1.5117-8.4727-0.015625l-23.012-13.289h-17.363c-4.5742-0.003907-8.2773-3.7109-8.2852-8.2812v-26.297c0.007812-4.5703 3.7109-8.2773 8.2852-8.2812h17.363l23.012-13.285c2.6211-1.5156 5.8477-1.5117 8.4648 0 2.6172 1.5117 4.2305 4.3086 4.2305 7.332v11.918c3.7188 0.53906 7.1172 2.3984 9.5742 5.2383 2.4609 2.8398 3.8164 6.4688 3.8203 10.227zm-66.465 15.34h15.133v-30.68h-15.133c-1.2109 0-2.1914 0.98047-2.1953 2.1914v26.297c0.003906 1.2109 0.98437 2.1914 2.1953 2.1914zm46.98-42.727c-0.003907-0.84766-0.45312-1.6289-1.1875-2.0508-0.73047-0.42578-1.6367-0.42578-2.3672-0.003906l-22.199 12.812v33.254l22.191 12.82c0.73438 0.42188 1.6406 0.41797 2.3711-0.003906 0.73438-0.42578 1.1836-1.207 1.1875-2.0508zm13.395 27.387c-0.003906-4.4062-3.0234-8.2344-7.3047-9.2734v18.543c4.2812-1.0352 7.2969-4.8633 7.3047-9.2695z"/>
                </svg>
            </button>
        </div>
    </div>
</div>
