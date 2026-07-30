@props(['scene' => null, 'voyageDef' => null, 'routeLine' => [], 'voyageMap' => ['cities' => true, 'borders' => true, 'labels' => true], 'voyageFog' => [], 'voyageView' => 'flat', 'transports' => [], 'lessonMapStyle' => 'soft-atlas', 'lessonMapRelief' => 0])

@php
    $config = $scene->config ?? [];
    $legIndex = (int) ($config['leg'] ?? 0);
    $legs = $voyageDef['legs'] ?? [];
    $legDef = $legs[$legIndex] ?? null;
    $fleet = $voyageDef['fleet'] ?? [];
    // Projection is GLOBAL to the voyage (all waypoints share it).
    $view = $voyageView ?? 'flat';
    // The waypoint's stop title (landfall name) lives in the voyage format; fall back to the scene's
    // location, which is what it was seeded from and stays mirrored to.
    $stopTitle = $legDef['title'] ?? ($scene->location ?? '');
    $intro = (bool) ($config['intro'] ?? false);
    $legCount = count($legs);
    $gallery = $config['gallery'] ?? [];
    $galleryImages = array_values($gallery['images'] ?? []);
    $rl = array_merge(['enabled' => true, 'color' => '#7c2d12', 'opacity' => 0.9, 'thickness' => 3, 'wobble' => 0.3, 'curve' => 'bezier'], $routeLine ?? []);

    // How this leg was travelled. Transport belongs to the CROSSING, so it is edited per leg: the
    // marker swaps at each landfall as the traveller comes ashore or puts to sea.
    $legTransport = $legDef['transport'] ?? 'sea';
    $legMarkerImage = $legDef['marker_image'] ?? '';
    $transportById = collect($transports)->keyBy('id');
    // Which collection to show: the leg's own choice decides, so a sea leg opens on ships and a
    // desert crossing opens on mounts, with the other a click away.
    $legTerrain = $transportById[$legTransport]['terrain'] ?? 'water';
@endphp

{{-- The voyage inspector is split into three tabs — Leg (this stop's content), Map (how the whole
     map looks) and Route (the sailed line + fleet). Alpine owns the active tab; Livewire preserves
     that state across the component's 3s wire:poll, and every panel stays in the DOM (x-show) so the
     wire:model bindings never detach. --}}
<div class="text-sm" x-data="{ tab: 'leg' }"
     x-on:voyage-obj-focus.window="tab = 'leg'; if ($event.detail?.which === 'gallery') $nextTick(() => { if ($refs.gallerySection) $refs.gallerySection.open = true; $refs.gallerySection?.scrollIntoView({ block: 'start', behavior: 'smooth' }) })">
    {{-- Header --}}
    <div class="flex items-center justify-between gap-2">
        <h3 class="flex items-center gap-2 font-semibold text-indigo-300">
            <x-lesson.icon-voyage class="h-5 w-5" />
            Route
        </h3>
        <div class="flex items-center gap-1.5">
            {{-- Zoom out to the whole trip from any leg, and back again. Purely a camera move on the
                 live map — nothing is saved, so a teacher can check the shape of the voyage
                 mid-edit without disturbing the scene they are working on. --}}
            <button type="button"
                    x-data="{ wide: false }"
                    x-on:click="wide = !wide; wide ? window.__voyageTour?.showOverview?.() : window.__voyageTour?.hideOverview?.()"
                    :class="wide ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-300 hover:text-amber-300'"
                    class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium transition"
                    :title="wide ? @js(__('Back to this leg')) : @js(__('See the whole voyage'))">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15m11.25 5.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                </svg>
                <span x-text="wide ? @js(__('This leg')) : @js(__('Whole trip'))"></span>
            </button>
            @if ($legCount)
                <span class="rounded-full bg-slate-800 px-2 py-0.5 text-[10px] font-medium text-slate-400">Waypoint {{ $legIndex + 1 }} / {{ $legCount }}</span>
            @endif
        </div>
    </div>

    {{-- TABS --}}
    <div role="tablist" class="mt-3 grid grid-cols-3 gap-1 rounded-xl bg-slate-800/60 p-1">
        <button type="button" role="tab" x-on:click="tab = 'leg'"
                :class="tab === 'leg' ? 'bg-amber-500 text-slate-950 shadow-sm' : 'text-slate-300 hover:text-slate-100'"
                class="rounded-lg py-1.5 text-xs font-medium transition-colors">
            Waypoint
        </button>
        <button type="button" role="tab" x-on:click="tab = 'map'"
                :class="tab === 'map' ? 'bg-amber-500 text-slate-950 shadow-sm' : 'text-slate-300 hover:text-slate-100'"
                class="rounded-lg py-1.5 text-xs font-medium transition-colors">
            Map
        </button>
        <button type="button" role="tab" x-on:click="tab = 'route'"
                :class="tab === 'route' ? 'bg-amber-500 text-slate-950 shadow-sm' : 'text-slate-300 hover:text-slate-100'"
                class="rounded-lg py-1.5 text-xs font-medium transition-colors">
            Route
        </button>
    </div>

    {{-- ══════════════════ TAB: WAYPOINT — this stop's title, dates, thumbnails and story ══════════ --}}
    <div x-show="tab === 'leg'" class="mt-3 space-y-4">
        {{-- STOP TITLE — the landfall's name, stored in the voyage format (voyage_def leg), NOT the
             gallery title. It's the single source for the landfall name: the map label, the date chip
             and the scene-list label all follow it. --}}
        <section>
            <label class="form-control">
                <span class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                    Stop title
                </span>
                <input type="text" value="{{ $stopTitle }}"
                       wire:change="setLegTitle($event.target.value)"
                       placeholder="e.g. Hispaniola"
                       class="input input-sm input-bordered bg-slate-900 mt-1.5" />
                <span class="mt-1 text-[10px] text-slate-500">The landfall's name — shown on the map, the date chip and the scene list.</span>
            </label>
        </section>

        {{-- FROM → TO — the waypoint's departure and arrival dates. These live in the lesson's editable
             voyage copy (game_config.voyage_def), so editing one clones the shared catalog on first save. --}}
        <section class="border-t border-slate-700/50 pt-3">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                Dates
            </div>
            @if ($legDef)
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <label class="form-control">
                        <span class="text-[10px] uppercase tracking-wider text-slate-400">Departs</span>
                        <input type="date" value="{{ $legDef['depart'] ?? '' }}"
                               wire:change="setLegDepart($event.target.value)"
                               class="input input-xs input-bordered bg-slate-900 mt-1" />
                    </label>
                    <label class="form-control">
                        <span class="text-[10px] uppercase tracking-wider text-slate-400">Arrives</span>
                        <input type="date" value="{{ $legDef['arrive'] ?? '' }}"
                               wire:change="setLegArrive($event.target.value)"
                               class="input input-xs input-bordered bg-slate-900 mt-1" />
                    </label>
                </div>
                <p class="mt-2 flex items-center gap-1.5 text-[10px] text-slate-500">
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24"><path d="M5 5 19 19M19 5 5 19" fill="none" stroke="#f59e0b" stroke-width="3" stroke-linecap="round"/></svg>
                    Drag the <span class="text-amber-400">Destination</span> X to set the landfall — the waypoint sails from the previous one.
                </p>
                {{-- The teacher steers the route around land (a ship can't sail across it — e.g. through
                     the Strait of Gibraltar) by grabbing the route line itself and dragging a bend. --}}
                <p class="mt-1.5 flex items-center gap-1.5 text-[10px] text-slate-500">
                    <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full border-2 border-white" style="background:rgba(59,130,246,.95)"></span>
                    Hover the <span class="text-blue-300">blue sailing line</span> and drag to bend it around the coast — or drag an existing bend to move it.
                </p>
            @else
                <p class="mt-2 rounded-lg border border-amber-700/40 bg-amber-950/20 px-2.5 py-1.5 text-[11px] text-amber-300/80">
                    This voyage has no waypoint data yet — dates can't be edited.
                </p>
            @endif
        </section>


        {{-- TRAVELLED BY — the model (or picture) that crosses THIS leg. Per leg, not per stop: the
             journey is what has a means of travel, so the marker swaps at the landfall where the
             traveller comes ashore. Alpine owns which collection is on show; the choice itself is
             saved server-side. Keyed per scene so the open collection doesn't leak between legs. --}}
        @if ($legDef && count($transports))
            <section class="border-t border-slate-700/50 pt-3"
                     wire:key="transport-{{ $scene->id }}"
                     x-data="{ terrain: '{{ $legTerrain }}' }">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                    Travelled by
                </div>
                <span class="mt-0.5 block text-[10px] text-slate-500">Just this leg — the marker swaps as the traveller reaches the coast.</span>

                {{-- Water or land splits the collection: a sea crossing is offered ships, an overland
                     stretch is offered mounts. --}}
                <div role="tablist" class="mt-2 grid grid-cols-2 gap-1 rounded-lg bg-slate-800/60 p-0.5">
                    @foreach ([['water', 'By sea'], ['land', 'Overland']] as [$terrainKey, $terrainLabel])
                        <button type="button" role="tab" x-on:click="terrain = '{{ $terrainKey }}'"
                                :class="terrain === '{{ $terrainKey }}' ? 'bg-slate-700 text-slate-100' : 'text-slate-400 hover:text-slate-200'"
                                class="rounded-md py-1 text-[11px] font-medium transition-colors">{{ $terrainLabel }}</button>
                    @endforeach
                </div>

                <div class="mt-2 grid grid-cols-3 gap-1.5">
                    @foreach ($transports as $t)
                        <button type="button"
                                x-show="terrain === '{{ $t['terrain'] }}'" x-cloak
                                wire:click="setLegTransport('{{ $t['id'] }}')"
                                title="{{ $t['hint'] }}"
                                @class([
                                    'group flex flex-col items-center gap-1 rounded-lg border p-1.5 transition',
                                    'border-amber-400 bg-amber-400/10 ring-2 ring-amber-400/60' => $legMarkerImage === '' && $legTransport === $t['id'],
                                    'border-slate-700 bg-slate-900/60 hover:border-slate-500' => $legMarkerImage !== '' || $legTransport !== $t['id'],
                                ])>
                            <span class="flex aspect-square w-full items-center justify-center overflow-hidden rounded-md bg-slate-950/60">
                                <img src="{{ $t['thumb'] }}" alt="" class="h-full w-full object-contain" loading="lazy" />
                            </span>
                            <span @class([
                                'text-[10px] font-medium',
                                'text-amber-300' => $legMarkerImage === '' && $legTransport === $t['id'],
                                'text-slate-400' => $legMarkerImage !== '' || $legTransport !== $t['id'],
                            ])>{{ $t['label'] }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- A picture instead of a model, for the journeys no model covers (on foot, by sledge,
                     by canoe). Drawn on the map as this same round portrait with its ring. --}}
                <div class="mt-2.5 flex items-center gap-2 rounded-lg border border-slate-700/50 bg-slate-900/40 p-2">
                    @if ($legMarkerImage !== '')
                        <img src="{{ $legMarkerImage }}" alt=""
                             class="h-16 w-16 shrink-0 rounded-full object-cover ring-2 ring-amber-400" />
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-300">A picture travels this leg.</p>
                            <div class="mt-1 flex gap-2">
                                <button type="button" wire:click="openMarkerImagePicker"
                                        class="text-[11px] text-amber-300 underline hover:text-amber-200">Change</button>
                                <button type="button" wire:click="clearLegMarkerImage"
                                        class="text-[11px] text-slate-400 underline hover:text-slate-200">Use a model</button>
                            </div>
                        </div>
                    @else
                        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full border border-dashed border-slate-600 text-slate-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 6.75h.008v.008H18V6.75Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6a2.25 2.25 0 0 1 2.25-2.25h15A2.25 2.25 0 0 1 21.75 6v12a2.25 2.25 0 0 1-2.25 2.25h-15A2.25 2.25 0 0 1 2.25 18V6Z"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400">No model for this journey?</p>
                            <button type="button" wire:click="openMarkerImagePicker"
                                    class="mt-1 text-[11px] text-amber-300 underline hover:text-amber-200">Use a picture instead</button>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- GALLERY / DESCRIPTION — the "just a description" layer. Opens as a modal over the map when
             the landfall hotspot (or a thumbnail) is clicked, so it lives in THIS voyage scene. --}}
        {{-- Folded away by default: a landfall's title, date line, story and images are a lot of
             panel for something most stops never set, and it pushed the controls above it out of
             reach. wire:ignore.self keeps it open through the component's 3s poll once expanded,
             and voyage-obj-focus (clicking the hotspot on the map) opens it. --}}
        <details x-ref="gallerySection" wire:ignore.self class="group border-t border-slate-700/50 pt-3"
                 x-on:voyage-obj-focus.window="if ($event.detail?.which === 'gallery') $el.open = true">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2">
                <span class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                    <svg class="h-3 w-3 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                    Gallery / description
                </span>
                {{-- What is in there, without opening it. --}}
                <span class="text-[10px] text-slate-500">
                    {{ trim((string) ($gallery['title'] ?? '')) !== '' ? $gallery['title'] : __('unnamed') }}@if (count($galleryImages)) · {{ count($galleryImages) }} @endif
                </span>
            </summary>
            <span class="mt-0.5 block text-[10px] text-slate-500">Opens as a modal from the landfall hotspot.</span>

            <label class="form-control mt-2">
                <span class="text-[10px] uppercase tracking-wider text-slate-500">Title</span>
                <input type="text" wire:model.blur="selectedScene.config.gallery.title" wire:change="saveSelected"
                       placeholder="e.g. Verversingspost" class="input input-sm input-bordered bg-slate-900 mt-1" />
            </label>
            <label class="form-control mt-2">
                <span class="text-[10px] uppercase tracking-wider text-slate-500">Date label</span>
                <input type="text" wire:model.blur="selectedScene.config.gallery.date_label" wire:change="saveSelected"
                       placeholder="e.g. 5 september 1642" class="input input-sm input-bordered bg-slate-900 mt-1" />
            </label>
            <label class="form-control mt-2">
                <span class="text-[10px] uppercase tracking-wider text-slate-500">Story</span>
                <textarea wire:model.blur="selectedScene.config.gallery.story" wire:change="saveSelected" rows="4"
                          placeholder="What happened at this stop…"
                          class="textarea textarea-bordered bg-slate-900 mt-1 leading-relaxed"></textarea>
            </label>

            {{-- Image fit — cover (fill, may crop) vs fit (letterbox on a blurred backdrop). --}}
            @php $gfit = ($gallery['fit'] ?? 'fit') === 'cover' ? 'cover' : 'fit'; @endphp
            <div class="mt-3 flex items-center justify-between gap-2">
                <span class="text-[10px] uppercase tracking-wider text-slate-500">Image fit</span>
                <div class="inline-flex overflow-hidden rounded-lg border border-slate-700/60">
                    <button type="button" wire:click="setGalleryFit('cover')"
                            class="px-2.5 py-1 text-xs font-medium transition-colors {{ $gfit === 'cover' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400' }}">Cover</button>
                    <button type="button" wire:click="setGalleryFit('fit')"
                            class="px-2.5 py-1 text-xs font-medium transition-colors {{ $gfit === 'fit' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400' }}">Fit</button>
                </div>
            </div>

            <span class="mt-3 block text-[10px] uppercase tracking-wider text-slate-500">Images</span>
            <span class="text-[10px] text-slate-500">The first three pin as thumbnails on the map; all show in the gallery.</span>
            @if (count($galleryImages))
                <ul class="mt-1 space-y-2" x-data="{ drag: null }">
                    @foreach ($galleryImages as $i => $img)
                        @php $gsrc = is_array($img) ? ($img['url'] ?? '') : $img; @endphp
                        <li wire:key="galimg-{{ $i }}" draggable="true"
                            x-on:dragstart="drag = {{ $i }}; $el.classList.add('opacity-50')"
                            x-on:dragend="$el.classList.remove('opacity-50')"
                            x-on:dragover.prevent
                            x-on:drop.prevent="if (drag !== null && drag !== {{ $i }}) $wire.moveGalleryImage(drag, {{ $i }}); drag = null"
                            class="rounded-lg border border-slate-700/50 bg-slate-900/60 p-1.5 cursor-grab active:cursor-grabbing">
                            <div class="flex items-center gap-2">
                                <span class="shrink-0 select-none text-slate-600" aria-hidden="true" title="Drag to reorder">⠿</span>
                                <img src="{{ $gsrc }}" alt="" class="h-9 w-9 shrink-0 rounded object-cover" onerror="this.style.visibility='hidden'" />
                                <span class="min-w-0 flex-1 truncate text-[11px] text-slate-400">{{ $gsrc }}</span>
                                <button type="button" wire:click="removeGalleryImage({{ $i }})"
                                        class="shrink-0 px-1 text-rose-300 hover:text-rose-200" title="Remove" aria-label="Remove">✕</button>
                            </div>
                            <input type="text" value="{{ is_array($img) ? ($img['credit'] ?? '') : '' }}"
                                   wire:change="setGalleryImageCredit({{ $i }}, $event.target.value)"
                                   placeholder="Credit / source"
                                   class="input input-xs input-bordered bg-slate-900 mt-1.5 w-full" />
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-1 text-[10px] text-slate-500">No gallery images yet.</p>
            @endif

            <div x-data="{ url: '' }" class="mt-2">
                <button type="button" wire:click="openGalleryImagePicker"
                        class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-slate-600 py-1.5 text-xs font-medium text-slate-300 hover:border-slate-400 hover:text-slate-100">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg> Add image
                </button>
                <div class="mt-1.5 flex items-center gap-1.5">
                    <input type="url" x-model="url" placeholder="or paste an image URL"
                           class="input input-xs input-bordered bg-slate-900 flex-1" />
                    <button type="button" class="btn btn-xs btn-primary"
                            @click="if (url.trim()) { $wire.addGalleryImage(url); url='' }">Add</button>
                </div>
            </div>
        </details>
    </div>

    {{-- ══════════════════ TAB: MAP — projection, visible detail, fog of war ══════════════════ --}}
    <div x-show="tab === 'map'" x-cloak class="mt-3 space-y-4">
        {{-- VIEW toggle + space intro. --}}
        <section>
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                Projection
            </div>
            <span class="mt-0.5 block text-[10px] text-slate-500">Applies to the whole voyage.</span>
            <div class="mt-2 flex items-end justify-between gap-3">
                <div>
                    <span class="text-[10px] uppercase tracking-wider text-slate-400">View</span>
                    <div class="mt-1 inline-flex overflow-hidden rounded-lg border border-slate-700/60">
                        <button type="button" wire:click="setVoyageView('flat')"
                                class="px-2.5 py-1 text-xs font-medium transition-colors {{ $view === 'globe' ? 'bg-slate-800 text-slate-400' : 'bg-amber-500 text-slate-950' }}">
                            Flat
                        </button>
                        <button type="button" wire:click="setVoyageView('globe')"
                                class="px-2.5 py-1 text-xs font-medium transition-colors {{ $view === 'globe' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400' }}">
                            Globe
                        </button>
                    </div>
                </div>
                {{-- INTRO — the "from space" fly-in. Only the first leg plays it. --}}
                <label class="flex items-center gap-2" title="Fly in from orbit before departure (first leg only)">
                    <span class="text-[10px] uppercase tracking-wider text-slate-400">Space intro</span>
                    <input type="checkbox" @checked($intro)
                           wire:change="setVoyageIntro($event.target.checked)"
                           class="toggle toggle-sm toggle-warning" />
                </label>
            </div>
        </section>

        {{-- MAP STYLE — the same lesson-wide palette a map block uses, voyages included (a voyage is
             not a special case: pick the look once and every map in the lesson follows). --}}
        <section class="border-t border-slate-700/50 pt-3">
            <x-lesson.map-style-picker :effective-style="$lessonMapStyle" :relief="$lessonMapRelief" />
        </section>

        {{-- MAP DETAIL — hide anachronistic modern cities/borders (they didn't exist yet) and pin the
             landfall names as period place labels. Lesson-wide, applies to every leg. --}}
        <section class="border-t border-slate-700/50 pt-3">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                Visible detail
            </div>
            <div class="mt-2 space-y-2">
                {{-- Each element: a visibility toggle + (when shown) its style controls, applied live. --}}
                @php
                    $vm = fn ($k, $d) => $voyageMap[$k] ?? $d;
                    $swatch = 'h-5 w-7 shrink-0 cursor-pointer rounded border border-slate-600 bg-slate-900';
                    $mini = 'range range-xs range-warning';
                @endphp

                {{-- WAYPOINT TITLES — the landfall names pinned along the route --}}
                <div class="rounded-lg bg-slate-800/30 p-2">
                    <label class="flex items-center justify-between gap-2">
                        <span class="text-[11px] text-slate-300">Waypoint titles</span>
                        <input type="checkbox" @checked($vm('labels', true)) wire:change="setVoyageMap('labels', $event.target.checked)" class="toggle toggle-sm toggle-warning" />
                    </label>
                    @if ($vm('labels', true))
                        <div class="mt-2 flex items-center gap-2">
                            <input type="color" value="{{ $vm('label_color', '#3a2c1a') }}" wire:change="setVoyageMap('label_color', $event.target.value)" class="{{ $swatch }}" title="Label colour" />
                            <label class="flex flex-1 items-center gap-1.5">
                                <span class="text-[10px] text-slate-500">Size</span>
                                <input type="range" min="0.5" max="2.2" step="0.1" value="{{ $vm('label_size', 1.0) }}" wire:change="setVoyageMap('label_size', $event.target.value)" class="{{ $mini }}" />
                            </label>
                        </div>
                    @endif
                </div>

                {{-- CITY NAMES --}}
                <div class="rounded-lg bg-slate-800/30 p-2">
                    <label class="flex items-center justify-between gap-2">
                        <span class="text-[11px] text-slate-300">City names</span>
                        <input type="checkbox" @checked($vm('cities', true)) wire:change="setVoyageMap('cities', $event.target.checked)" class="toggle toggle-sm toggle-warning" />
                    </label>
                    @if ($vm('cities', true))
                        <div class="mt-2 flex items-center gap-2">
                            <input type="color" value="{{ $vm('city_color', '#3a2c1a') }}" wire:change="setVoyageMap('city_color', $event.target.value)" class="{{ $swatch }}" title="City name colour" />
                            <label class="flex flex-1 items-center gap-1.5">
                                <span class="text-[10px] text-slate-500">Size</span>
                                <input type="range" min="0.5" max="2.2" step="0.1" value="{{ $vm('city_size', 1.0) }}" wire:change="setVoyageMap('city_size', $event.target.value)" class="{{ $mini }}" />
                            </label>
                        </div>
                    @endif
                </div>

                {{-- COUNTRY BORDERS --}}
                <div class="rounded-lg bg-slate-800/30 p-2">
                    <label class="flex items-center justify-between gap-2">
                        <span class="text-[11px] text-slate-300">Country borders</span>
                        <input type="checkbox" @checked($vm('borders', true)) wire:change="setVoyageMap('borders', $event.target.checked)" class="toggle toggle-sm toggle-warning" />
                    </label>
                    @if ($vm('borders', true))
                        <div class="mt-2 space-y-1.5">
                            <div class="flex items-center gap-2">
                                <input type="color" value="{{ $vm('border_color', '#5b4a36') }}" wire:change="setVoyageMap('border_color', $event.target.value)" class="{{ $swatch }}" title="Border colour" />
                                <label class="flex flex-1 items-center gap-1.5">
                                    <span class="text-[10px] text-slate-500">Width</span>
                                    <input type="range" min="0.2" max="4" step="0.2" value="{{ $vm('border_width', 0.6) }}" wire:change="setVoyageMap('border_width', $event.target.value)" class="{{ $mini }}" />
                                </label>
                            </div>
                            <label class="flex items-center gap-1.5">
                                <span class="w-9 text-[10px] text-slate-500">Opacity</span>
                                <input type="range" min="0" max="1" step="0.05" value="{{ $vm('border_opacity', 0.3) }}" wire:change="setVoyageMap('border_opacity', $event.target.value)" class="{{ $mini }} flex-1" />
                            </label>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        {{-- PAINT FOG — brush over the map to hide land the voyagers hadn't discovered yet.
             The header count + undo/reset are server-state (reactive); the brush toggle/size live in a
             wire:ignore island so Alpine's paint state survives Livewire re-renders. --}}
        <section class="border-t border-slate-700/50 pt-3">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15a4.5 4.5 0 0 0 4.5 4.5H18a3.75 3.75 0 0 0 1.332-7.257 3 3 0 0 0-3.758-3.848 5.25 5.25 0 0 0-10.233 2.33A4.502 4.502 0 0 0 2.25 15Z"/></svg>
                    Undiscovered land
                </div>
                <span class="text-[10px] text-slate-500">{{ ($voyageMap['fog_auto'] ?? false) ? 'auto' : count($voyageFog).' painted' }}</span>
            </div>

            {{-- AUTO fog-of-war: mask all new land along the route; the ship's range unmasks it on
                 approach and the coastline draws on at landfall. When on, the manual brush is hidden. --}}
            <label class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-slate-800/40 px-2.5 py-2">
                <span class="text-[11px] text-slate-300">Fog undiscovered land automatically</span>
                <input type="checkbox" @checked($voyageMap['fog_auto'] ?? false)
                       wire:change="setVoyageMap('fog_auto', $event.target.checked)"
                       class="toggle toggle-sm toggle-warning" />
            </label>

            <div @class(['mt-2 opacity-40 pointer-events-none' => ($voyageMap['fog_auto'] ?? false)])
                 wire:ignore x-data="{ paint: false, erase: false, brush: 250 }"
                 x-init="window.__voyagePaint = window.__voyagePaint || {}; window.__voyagePaint.brushKm = brush; window.__voyagePaint.erase = false">
                <button type="button"
                        x-on:click="paint = !paint; window.__voyagePaint.active = paint; if(!paint){erase=false; window.__voyagePaint.erase=false;} window.dispatchEvent(new Event('voyage-paint-changed'))"
                        :class="paint ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-300 hover:text-slate-100'"
                        class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg py-1.5 text-xs font-medium transition-colors">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42"/></svg>
                    <span x-show="!paint">Paint undiscovered land</span>
                    <span x-show="paint" x-cloak>Painting — click to stop</span>
                </button>
                {{-- Paint vs Erase segmented toggle + brush size, only while the tool is active. --}}
                <div x-show="paint" x-cloak>
                    <div class="mt-2 grid grid-cols-2 gap-1 rounded-lg bg-slate-800/60 p-0.5">
                        <button type="button"
                                x-on:click="erase = false; window.__voyagePaint.erase = false; window.dispatchEvent(new Event('voyage-paint-changed'))"
                                :class="!erase ? 'bg-amber-500 text-slate-950' : 'text-slate-300 hover:text-slate-100'"
                                class="rounded-md py-1 text-[11px] font-medium transition-colors">Paint</button>
                        <button type="button"
                                x-on:click="erase = true; window.__voyagePaint.erase = true; window.dispatchEvent(new Event('voyage-paint-changed'))"
                                :class="erase ? 'bg-rose-500 text-slate-950' : 'text-slate-300 hover:text-slate-100'"
                                class="rounded-md py-1 text-[11px] font-medium transition-colors">Erase</button>
                    </div>
                    <p class="mt-1 text-[10px] text-slate-500" x-text="erase ? 'Drag over masked land to reveal it.' : 'Drag over land to hide it.'"></p>
                    {{-- Brush size — shared between Paint and Erase (the value stays put when you switch). --}}
                    <label class="mt-2 block">
                        <span class="flex justify-between text-[11px] text-slate-300">Brush size <span class="text-slate-500" x-text="brush + ' km'"></span></span>
                        <input type="range" min="60" max="600" step="20" x-model.number="brush"
                               x-on:input="window.__voyagePaint.brushKm = brush"
                               class="range range-xs mt-1" :class="erase ? 'range-error' : 'range-warning'" />
                    </label>
                </div>
            </div>

            @if (count($voyageFog))
                <div class="mt-2 flex items-center gap-3">
                    <button type="button" wire:click="undoFog"
                            class="flex items-center gap-1 text-[11px] text-slate-300 hover:text-slate-100">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
                        Undo <span class="text-slate-500">(⌘Z)</span>
                    </button>
                    <button type="button" wire:click="clearFog"
                            class="text-[11px] text-rose-300 underline hover:text-rose-200">Reset all</button>
                </div>
            @endif
        </section>
    </div>

    {{-- ══════════════════ TAB: ROUTE — the sailed line + fleet ══════════════════ --}}
    <div x-show="tab === 'route'" x-cloak class="mt-3 space-y-4">
        {{-- FLEET — compact chips (editing ships is coming). Reads the lesson's voyage copy. --}}
        <section>
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                <x-lesson.icon-voyage class="h-4 w-4" />
                Fleet
            </div>
            @if (count($fleet))
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    @foreach ($fleet as $ship)
                        <span class="inline-flex items-center gap-1 rounded-full border border-slate-700/50 bg-slate-900/60 px-2 py-0.5 text-[11px] text-slate-300">
                            {{ $ship['name'] ?? 'Ship' }}
                            @if ($ship['flagship'] ?? false)<span class="text-[9px] font-semibold text-amber-300">★</span>@endif
                        </span>
                    @endforeach
                </div>
            @else
                <p class="mt-1 text-[10px] text-slate-500">This voyage has no fleet data.</p>
            @endif
        </section>

        {{-- SHIP & CAMERA — whole-voyage. Ship on-screen size + whether it scales with zoom; plus the
             cinematic camera moves that play during the sail (Preview / student player, not the editor
             jump). --}}
        @php $vmr = fn ($k, $d) => $voyageMap[$k] ?? $d; @endphp
        <section class="border-t border-slate-700/50 pt-3">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                <x-lesson.icon-voyage class="h-4 w-4" />
                Ship &amp; camera
            </div>

            {{-- WHERE THE DATE AND PLACE ARE WRITTEN. One or the other: the map used to carry both,
                 so the landfall name appeared twice within a few centimetres of itself. --}}
            <div class="mt-2">
                <span class="text-[11px] text-slate-300">{{ __('Date and place') }}</span>
                <div class="mt-1 grid grid-cols-2 gap-1">
                    @foreach ([['bottom', __('Bottom left')], ['top', __('Top centre')]] as [$pos, $label])
                        <button type="button" wire:click="setVoyageMap('info_position', '{{ $pos }}')"
                                @class([
                                    'rounded-md border px-2 py-1 text-[11px] transition',
                                    'border-amber-400 bg-amber-400/10 text-amber-300' => $vmr('info_position', 'bottom') === $pos,
                                    'border-slate-700 text-slate-400 hover:border-slate-500' => $vmr('info_position', 'bottom') !== $pos,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
                <p class="mt-1 text-[10px] text-slate-500">{{ __('One line in the corner, or the date chip above the map.') }}</p>
            </div>

            <label class="mt-3 block">
                <span class="flex justify-between text-[11px] text-slate-300">Ship size <span class="text-slate-500">{{ number_format((float) $vmr('ship_scale', 1.0), 1) }}×</span></span>
                <input type="range" min="0.4" max="2.5" step="0.1" value="{{ $vmr('ship_scale', 1.0) }}"
                       wire:change="setVoyageMap('ship_scale', $event.target.value)"
                       class="range range-xs range-warning mt-1" />
            </label>

            <label class="mt-2 flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">Scale with zoom
                    <span class="block text-[10px] text-slate-500">On: pinned to the map (shrinks as you zoom out). Off: constant size.</span>
                </span>
                <input type="checkbox" @checked($vmr('ship_anchored', false)) wire:change="setVoyageMap('ship_anchored', $event.target.checked)" class="toggle toggle-sm toggle-warning shrink-0" />
            </label>

            {{-- How much the traveller moves on the spot. The rocking was tuned for an ocean and can
                 read as too much on a big classroom screen, so the swing is adjustable — 0 parks the
                 ship dead still on the water. --}}
            <label class="mt-3 block">
                <span class="flex justify-between text-[11px] text-slate-300">Animation <span class="text-slate-500">{{ (int) $vmr('motion', 100) }}%</span></span>
                <input type="range" min="0" max="100" step="5" value="{{ (int) $vmr('motion', 100) }}"
                       wire:change="setVoyageMap('motion', $event.target.value)"
                       class="range range-xs range-warning mt-1" />
                <span class="mt-0.5 flex justify-between text-[9px] text-slate-500"><span>Still</span><span>Full rock</span></span>
            </label>

            {{-- Sailing zoom: how closely the camera follows the ship. 0 = tight on the ship + a small
                 island; 100 = a continental view (never the whole world). --}}
            <label class="mt-3 block">
                <span class="flex justify-between text-[11px] text-slate-300">Sailing zoom <span class="text-slate-500">{{ (int) $vmr('ocean_zoom', 30) }}%</span></span>
                <input type="range" min="0" max="100" step="5" value="{{ (int) $vmr('ocean_zoom', 30) }}"
                       wire:change="setVoyageMap('ocean_zoom', $event.target.value)"
                       class="range range-xs range-warning mt-1" />
                <span class="mt-0.5 flex justify-between text-[9px] text-slate-500"><span>Ship + island</span><span>Continental</span></span>
            </label>

            <label class="mt-3 flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">Dolly in on arrival
                    <span class="block text-[10px] text-slate-500">Zoom in a little more as the ship makes landfall.</span>
                </span>
                <input type="checkbox" @checked($vmr('cam_dolly_arrival', false)) wire:change="setVoyageMap('cam_dolly_arrival', $event.target.checked)" class="toggle toggle-sm toggle-warning shrink-0" />
            </label>
            <p class="mt-2 text-[10px] text-slate-500">The sail (with these camera moves) plays in Preview and for students — the editor jumps straight to each landfall.</p>
        </section>

        {{-- ROUTE LINE — the sailed (visible) part of the journey. Lesson-wide (one setting for every
             waypoint). Animated: the line grows as the ship sails. wire:ignore.self keeps the open/closed
             state through the component's 3s wire:poll. --}}
        <details wire:ignore.self class="group form-control border-t border-slate-700/50 pt-3" open>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2">
                <span class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                    <svg class="h-3 w-3 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                    Route line
                </span>
                <input type="checkbox" @checked($rl['enabled'])
                       x-on:click.stop
                       wire:change="setRouteLine('enabled', $event.target.checked)"
                       class="toggle toggle-sm toggle-warning" />
            </summary>

            <div class="mt-2 space-y-2.5" @disabled(! $rl['enabled']) @class(['opacity-40 pointer-events-none' => ! $rl['enabled']])>
                {{-- Colour (fill) --}}
                <label class="flex items-center justify-between gap-2">
                    <span class="text-[11px] text-slate-300">Colour</span>
                    <input type="color" value="{{ $rl['color'] }}"
                           wire:change="setRouteLine('color', $event.target.value)"
                           class="h-6 w-10 cursor-pointer rounded border border-slate-600 bg-slate-900" />
                </label>

                {{-- Opacity --}}
                <label class="block">
                    <span class="flex justify-between text-[11px] text-slate-300">Opacity <span class="text-slate-500">{{ number_format((float) $rl['opacity'], 2) }}</span></span>
                    <input type="range" min="0" max="1" step="0.05" value="{{ $rl['opacity'] }}"
                           wire:change="setRouteLine('opacity', $event.target.value)"
                           class="range range-xs range-warning mt-1" />
                </label>

                {{-- Thickness --}}
                <label class="block">
                    <span class="flex justify-between text-[11px] text-slate-300">Thickness <span class="text-slate-500">{{ (float) $rl['thickness'] }}px</span></span>
                    <input type="range" min="0.5" max="12" step="0.5" value="{{ $rl['thickness'] }}"
                           wire:change="setRouteLine('thickness', $event.target.value)"
                           class="range range-xs range-warning mt-1" />
                </label>

                {{-- Wobble --}}
                <label class="block">
                    <span class="flex justify-between text-[11px] text-slate-300">Wobble <span class="text-slate-500">{{ number_format((float) $rl['wobble'], 2) }}</span></span>
                    <input type="range" min="0" max="1" step="0.05" value="{{ $rl['wobble'] }}"
                           wire:change="setRouteLine('wobble', $event.target.value)"
                           class="range range-xs range-warning mt-1" />
                </label>

                {{-- Curve: straight vs bezier --}}
                <div>
                    <span class="text-[11px] text-slate-300">Line style</span>
                    <div class="mt-1 inline-flex overflow-hidden rounded-lg border border-slate-700/60">
                        <button type="button" wire:click="setRouteLine('curve', 'bezier')"
                                class="px-3 py-1 text-xs font-medium transition-colors {{ ($rl['curve'] ?? 'bezier') === 'straight' ? 'bg-slate-800 text-slate-400' : 'bg-amber-500 text-slate-950' }}">
                            Curved
                        </button>
                        <button type="button" wire:click="setRouteLine('curve', 'straight')"
                                class="px-3 py-1 text-xs font-medium transition-colors {{ ($rl['curve'] ?? 'bezier') === 'straight' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400' }}">
                            Straight
                        </button>
                    </div>
                </div>
            </div>
        </details>

        {{-- Recover a route tangled by dragging — revert the geometry to the original catalog route. --}}
        {{-- Undo the last route edit. Cmd-Z does the same thing; this is here so it is findable,
             and because an accidental drag is exactly when nobody thinks to try a shortcut. --}}
        <button type="button" wire:click="undoVoyage"
                @disabled(! $this->canUndoVoyage)
                class="mt-3 flex items-center gap-1.5 border-t border-slate-700/50 pt-3 text-[11px] transition
                       {{ $this->canUndoVoyage ? 'text-slate-300 hover:text-amber-300' : 'cursor-not-allowed text-slate-600' }}">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
            {{ __('Undo last route change') }}
            <span class="ml-auto font-mono text-[10px] text-slate-500">⌘Z</span>
        </button>

        <button type="button" wire:click="resetVoyageRoute"
                wire:confirm="Reset the whole route to its original shape? Your dragged waypoints and bends will be discarded (dates, gallery and map settings stay)."
                class="mt-2 flex items-center gap-1.5 border-t border-slate-700/50 pt-3 text-[11px] text-slate-400 hover:text-amber-300">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
            Reset route to original
        </button>
    </div>

    {{-- Footer — always visible under the tabs. --}}
    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this waypoint?"
            class="mt-4 flex items-center gap-1 border-t border-slate-700/50 pt-3 text-xs text-rose-300 hover:text-rose-200">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
        Delete waypoint
    </button>
</div>
