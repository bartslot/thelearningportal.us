@props(['scene' => null])

@php
    $blur           = (float)  ($scene->skybox_blur      ?? 0.5);
    $opacity        = (float)  ($scene->skybox_opacity   ?? 1.0);
    $bgColor        = (string) ($scene->background_color ?? '#000000');
    $view           = (string) ($scene->scene_view       ?? 'skybox');
    $worldStatus    = (string) ($scene->world_labs_status ?? '');
    $worldYOffset   = (float)  ($scene->world_y_offset   ?? 0);
    $worldScale     = (float)  ($scene->world_scale      ?? 1);
    $worldCharScale = (float)  ($scene->world_char_scale ?? 0.53);
    $isGenerating   = $scene->status === 'generating';
    $isUpscaling    = ($scene->upscale_status ?? null) === 'upscaling';
    $isBusy         = $isGenerating;
    $hasSkyboxImage = ! empty($scene->skybox_image_path);
    $slideshowMode  = (string) ($scene->config['slideshow_mode'] ?? (($scene->config['parallax'] ?? false) ? 'parallax' : 'standard'));
    $parallax       = $slideshowMode === 'parallax';
    $candidates     = $scene->skybox_candidates ?? [];
    // Default image-source tab reflects how the current background was set.
    $bgKind         = $scene->config['background_credit']['kind'] ?? null;
    $srcDefault     = $bgKind === 'painting' ? 'paintings' : ($bgKind === 'url' ? 'url' : 'ai');
    $backgroundFit  = ($scene->config['background_fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover';
    $backgroundImageUrl = $scene->image_path
        ? asset('storage/' . $scene->image_path) . '?v=' . ($scene->updated_at?->timestamp ?? '')
        : null;
@endphp

<div class="mt-2 space-y-3"
     x-data="{
        view:         '{{ $view }}',
        blur:         {{ $blur }},
        opacity:      {{ $opacity }},
        bgColor:      '{{ $bgColor }}',
        charYOffset:  {{ $worldYOffset }},
        worldScale:   {{ $worldScale }},
        charScale:    {{ $worldCharScale }},
        emitWorldScale() { window.dispatchEvent(new CustomEvent('lesson:world:scale',  { detail: { scale: Number(this.worldScale) } })); },
        emitCharScale()  { window.dispatchEvent(new CustomEvent('lesson:world:char-scale', { detail: { scale: Number(this.charScale) } })); },
        emitAllWorld()   { this.$nextTick(() => { this.emitCharY(); this.emitWorldScale(); this.emitCharScale(); }); },
        _onMounted: null,
        init() {
            this.$watch('view', v => { if (v === 'world') this.emitAllWorld(); });
            this._onMounted = (e) => {
                if (this.view !== 'world') return
                const d = e.detail
                if (d) {
                    if (d.worldYOffset   !== undefined) this.charYOffset = d.worldYOffset
                    if (d.worldScale     !== undefined) this.worldScale  = d.worldScale
                    if (d.worldCharScale !== undefined) this.charScale   = d.worldCharScale
                }
            };
            window.addEventListener('world:mounted', this._onMounted);
        },
        destroy() {
            if (this._onMounted) window.removeEventListener('world:mounted', this._onMounted);
        },
        emitBlur()    { window.dispatchEvent(new CustomEvent('lesson:skybox:blur',    { detail: { blur:    Number(this.blur)    } })); },
        emitOpacity() { window.dispatchEvent(new CustomEvent('lesson:skybox:opacity', { detail: { opacity: Number(this.opacity) } })); },
        emitBg()      { window.dispatchEvent(new CustomEvent('lesson:skybox:bgcolor', { detail: { color:   String(this.bgColor) } })); },
        emitCharY()   { window.dispatchEvent(new CustomEvent('lesson:world:character-y', { detail: { offset: Number(this.charYOffset) } })); },
     }">

    {{-- ── Upscaling progress banner (local env only) ────────────────────── --}}
    @if ($isUpscaling)
        <div class="flex items-center gap-2 rounded-lg bg-violet-950/60 border border-violet-700/50 px-3 py-2 text-xs text-violet-300">
            <x-icons.spinner class="w-3.5 h-3.5 animate-spin shrink-0 text-violet-400" />
            <span>Upscaling with Upscayl… shader will update when done.</span>
        </div>
    @endif

    {{-- ── Scene view — a dropdown (Figma): Slideshow · Panorama · 3D World. Keeps the
         lesson:scene:view dispatch + setSceneView/generateWorld wiring. ─────────────── --}}
    <div class="relative">
        <span class="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-slate-400">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                <rect x="3" y="6" width="14" height="10" rx="1.5" /><path stroke-linecap="round" d="M20 8v8M17 6.5v11" />
            </svg>
        </span>
        <select x-model="view"
                @change="
                    window.dispatchEvent(new CustomEvent('lesson:scene:view', { detail: {
                        view:     view,
                        imageUrl: {{ $scene->image_path ? json_encode(asset('storage/' . $scene->image_path)) : 'null' }},
                        sceneId:  {{ $scene->id }},
                        duration: {{ $scene->duration_seconds ?? 10 }},
                    }}));
                    view === 'world'
                        ? $wire.call('generateWorld', $wire.get('selectedSceneId'))
                        : $wire.call('setSceneView', view);
                "
                class="select select-sm select-bordered w-full bg-base-100 pl-9 font-medium text-slate-200">
            <option value="slideshow">{{ __('Slideshow') }}</option>
            <option value="skybox">{{ __('Panorama') }}</option>
            <option value="world">{{ __('3D World') }}</option>
        </select>
    </div>

    {{-- ── Slideshow tab ────────────────────────────────────────────────────── --}}
    <div x-show="view === 'slideshow'" x-cloak class="space-y-2">
        {{-- Slideshow render mode — Standard (flat) | Parallax (depth) | Drawing (line art). --}}
        <div x-data="{ mode: @js($slideshowMode) }">
            <div class="flex rounded-lg overflow-hidden border border-slate-700 text-[11px] font-medium">
                @foreach (['standard' => __('Standard'), 'parallax' => __('Parallax'), 'drawing' => __('Drawing')] as $modeVal => $modeLabel)
                    <button type="button"
                            @click="mode = '{{ $modeVal }}'; $wire.call('setSlideshowMode', '{{ $modeVal }}')"
                            :class="mode === '{{ $modeVal }}'
                                ? 'bg-amber-500 text-slate-950'
                                : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200'"
                            class="flex-1 py-1.5 transition-colors">
                        {{ $modeLabel }}
                    </button>
                @endforeach
            </div>
            <p class="mt-1 text-[10px] leading-tight text-slate-500" x-show="mode === 'parallax'" x-cloak>
                {{ __('Layers and text gain depth and follow the camera.') }}
            </p>
            <p class="mt-1 text-[10px] leading-tight text-slate-500" x-show="mode === 'drawing'" x-cloak>
                {{ __('The scene draws itself as an ink line-art animation.') }}
            </p>
        </div>

        {{-- Background — how this scene is filled. Keynote model: a scene has ONE
             background (image / video / 3D). Clipart and other objects go ON TOP via
             the Insert tools, not here. --}}
        <div x-data="{ bgType: @js(in_array($srcDefault, ['video', '3d'], true) ? $srcDefault : 'image'), imgSrc: @js(in_array($srcDefault, ['ai', 'paintings', 'url'], true) ? $srcDefault : 'ai'), promptOpen: false }" class="space-y-2">
            {{-- Background type — a dropdown (Figma "Background image"). --}}
            <select x-model="bgType"
                    class="select select-sm select-bordered w-full bg-base-100 font-medium text-slate-200">
                <option value="image">{{ __('Background image') }}</option>
                <option value="video">{{ __('Background video') }}</option>
                <option value="3d">{{ __('3D background') }}</option>
            </select>

          {{-- Image — where the background image comes from: AI, a painting, or a drawing --}}
          <div x-show="bgType === 'image'" x-cloak class="space-y-2">
            <div class="flex items-center gap-2.5 rounded-lg border border-slate-700/70 bg-slate-900/40 p-1.5">
                @if ($backgroundImageUrl)
                    <img src="{{ $backgroundImageUrl }}"
                         class="h-14 w-24 shrink-0 rounded object-cover ring-1 ring-slate-700"
                         alt="{{ __('Current scene background') }}" />
                @else
                    <div class="flex h-14 w-24 shrink-0 items-center justify-center rounded bg-slate-800 text-[9px] font-medium uppercase tracking-wider text-slate-500 ring-1 ring-dashed ring-slate-600">
                        {{ __('none') }}
                    </div>
                @endif
                <div class="min-w-0 leading-tight">
                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Current image') }}</span>
                    <span class="block truncate text-[11px] text-slate-500">{{ $backgroundImageUrl ? __('Scene background') : __('No background selected') }}</span>
                </div>
            </div>

            {{-- Fit — how the image fills the 16:9 stage. Cover crops the overflow (and anchors a
                 portrait to the top so the face survives); Contain shows the whole work. --}}
            @if ($backgroundImageUrl)
                <div x-data="{ fit: @js($backgroundFit) }">
                    <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Fit') }}</span>
                    <div class="flex rounded-lg overflow-hidden border border-slate-700 text-[11px] font-medium">
                        @foreach (['cover' => __('Fill frame'), 'contain' => __('Whole image')] as $fitVal => $fitLabel)
                            <button type="button"
                                    @click="fit = '{{ $fitVal }}'; $wire.call('setBackgroundFit', '{{ $fitVal }}')"
                                    :class="fit === '{{ $fitVal }}'
                                        ? 'bg-amber-500 text-slate-950'
                                        : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200'"
                                    class="flex-1 py-1.5 transition-colors">
                                {{ $fitLabel }}
                            </button>
                        @endforeach
                    </div>
                    <p class="mt-1 text-[10px] leading-tight text-slate-500" x-show="fit === 'cover'" x-cloak>
                        {{ __('Fills the frame. Portraits are cropped from the top so faces stay in shot.') }}
                    </p>
                    <p class="mt-1 text-[10px] leading-tight text-slate-500" x-show="fit === 'contain'" x-cloak>
                        {{ __('Shows the whole picture, with bars where it does not reach the edges.') }}
                    </p>
                </div>
            @endif

            {{-- Reuse an image already in THIS lesson — a single-row carousel (latest first), arrows to
                 scroll. Clicking sets it as this scene's background (local paths reused; external
                 URLs downloaded once). Mirrors the Poster picker's "pick from the lesson" idea. --}}
            @php $reuseImages = array_reverse($scene->lesson?->posterCandidates() ?? []); @endphp
            @if (count($reuseImages))
                <div x-data="{ scroll(d) { const el = $refs.strip; el.scrollBy({ left: d * el.clientWidth * 0.8, behavior: 'smooth' }); } }">
                    <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Reuse from this lesson') }}</span>
                    <div class="flex items-center gap-1">
                        <button type="button" @click="scroll(-1)" aria-label="{{ __('Previous') }}"
                                class="flex h-8 w-5 shrink-0 items-center justify-center rounded text-slate-400 transition hover:bg-slate-800 hover:text-slate-100">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6"/></svg>
                        </button>
                        <div x-ref="strip" class="flex flex-1 gap-1.5 overflow-x-auto scroll-smooth [&::-webkit-scrollbar]:hidden" style="scrollbar-width:none">
                            @foreach ($reuseImages as $img)
                                <button type="button" wire:click="useLessonImageBackground(@js($img['url']))" title="{{ $img['label'] ?? '' }}"
                                        @class([
                                            'h-12 w-16 shrink-0 overflow-hidden rounded ring-1 transition',
                                            'ring-amber-400' => $backgroundImageUrl === $img['url'],
                                            'ring-slate-700 hover:ring-amber-400' => $backgroundImageUrl !== $img['url'],
                                        ])>
                                    <img src="{{ $img['url'] }}" alt="{{ $img['label'] ?? '' }}" class="h-full w-full object-cover" loading="lazy"
                                         onerror="this.closest('button').style.display='none'" />
                                </button>
                            @endforeach
                        </div>
                        <button type="button" @click="scroll(1)" aria-label="{{ __('Next') }}"
                                class="flex h-8 w-5 shrink-0 items-center justify-center rounded text-slate-400 transition hover:bg-slate-800 hover:text-slate-100">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            <div class="flex overflow-hidden rounded-lg border border-slate-700/70 text-[11px] font-medium">
                @foreach (['ai' => __('AI Gen'), 'paintings' => __('Paintings'), 'url' => __('Drawing')] as $sv => $sl)
                    <button type="button" @click="imgSrc = '{{ $sv }}'"
                            :class="imgSrc === '{{ $sv }}' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-slate-200'"
                            class="flex-1 whitespace-nowrap px-1 py-1.5 transition-colors">{{ $sl }}</button>
                @endforeach
            </div>

            {{-- AI generated — Regenerate / Edit prompt (Figma item 5) --}}
            <div x-show="imgSrc === 'ai'" x-cloak class="space-y-2">
                <span class="block text-[11px] font-medium text-slate-300">{{ __('AI Generated image') }}</span>
                <div class="flex flex-col gap-1.5">
                    <button type="button"
                            wire:click="regenerate({{ $scene->id }}, 'image')"
                            wire:loading.attr="disabled" wire:target="regenerate"
                            @disabled($isBusy)
                            class="inline-flex items-center gap-1.5 text-[12px] text-slate-300 transition hover:text-amber-300 disabled:opacity-50">
                        @if ($isGenerating)
                            <x-icons.spinner class="h-3.5 w-3.5 animate-spin" /><span>{{ __('Generating…') }}</span>
                        @else
                            <x-icons.regenerate class="h-3.5 w-3.5" /><span>{{ $scene->image_path ? __('Regenerate') : __('Generate') }}</span>
                        @endif
                    </button>
                    <button type="button" @click="promptOpen = !promptOpen"
                            class="inline-flex items-center gap-1.5 text-[12px] text-slate-300 transition hover:text-amber-300">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                        <span>{{ __('Edit prompt') }}</span>
                    </button>
                </div>
                <textarea x-show="promptOpen" x-cloak
                          wire:model.blur="selectedScene.image_prompt" wire:change="saveSelected" rows="3"
                          class="textarea textarea-sm textarea-bordered mt-1 w-full bg-slate-900"></textarea>
            </div>

            {{-- Paintings (curated public-domain / museum works) as the background image --}}
            <div x-show="imgSrc === 'paintings'" x-cloak class="space-y-1.5">
                {{-- NOT gated on $isBusy. A narration scene only reaches 'ready' with script + image
                     + audio, so a hand-built scene that has a script and narration but no image sits
                     at status 'generating' — and gating this button on that status deadlocked the
                     scene: the one control that could supply the missing image was disabled because
                     the image was missing. Picking a painting is safe at any time regardless, since
                     GenerateSceneImage bails on hasManualBackground() before AND after generating.
                     The AI generate/regenerate buttons below stay gated, to avoid double-dispatch. --}}
                <button type="button" wire:click="openPaintingPicker"
                        class="btn btn-xs btn-outline border-slate-600 text-slate-300 hover:border-amber-400 hover:text-amber-300 disabled:opacity-50 inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                        <rect x="3" y="4" width="18" height="14" rx="2"/>
                        <path stroke-linecap="round" d="m3 15 4.5-4.5a1.4 1.4 0 0 1 2 0L14 15m-2-2 2.5-2.5a1.4 1.4 0 0 1 2 0L21 15"/>
                        <circle cx="9" cy="8.5" r="1.2" fill="currentColor" stroke="none"/>
                    </svg>
                    <span>{{ __('Browse paintings') }}</span>
                </button>
                <p class="text-[10px] text-slate-500">{{ __('Public-domain paintings & museum works, matched to the scene’s era and place.') }}</p>
            </div>

            {{-- Drawing — render the background as a hand-drawn ink line-art animation.
                 (The 'url' imgSrc key labels the Drawing tab; the paste-a-URL flow via
                 applyImageUrl still exists on the component, just not surfaced in Format.) --}}
            <div x-show="imgSrc === 'url'" x-cloak class="space-y-1.5">
                <button type="button"
                        @click="$wire.call('setSlideshowMode', 'drawing')"
                        class="inline-flex items-center gap-1.5 text-[12px] text-slate-300 transition hover:text-amber-300">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                    <span>{{ __('Draw this background') }}</span>
                </button>
                <p class="text-[10px] text-slate-500">{{ __('The scene draws itself as an ink line-art animation (sets the render mode to Drawing).') }}</p>
            </div>
          </div>{{-- /image sub-sources --}}

            @php $bgEmbed = ($scene->config ?? [])['bg_embed'] ?? null; @endphp

            {{-- Video — YouTube / Vimeo / any embed (Klokhuis …) as the scene background --}}
            <div x-show="bgType === 'video'" x-cloak class="space-y-2">
                <div x-data="{ link: '' }">
                    <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Video link or embed code') }}</span>
                    <div class="flex gap-1.5">
                        <input type="text" x-model="link" placeholder="https://youtu.be/… · vimeo.com/… · &lt;iframe …&gt;"
                               class="input input-xs input-bordered flex-1 bg-slate-900" />
                        <button type="button" @click="if (link.trim()) { $wire.setVideoEmbed(link); link = '' }"
                                class="btn btn-xs border-0 bg-amber-500 font-semibold text-slate-950 hover:bg-amber-400">{{ __('Set') }}</button>
                    </div>
                    <p class="mt-1 text-[10px] text-slate-500">{{ __('YouTube, Vimeo, or paste any provider\'s <iframe> embed code.') }}</p>
                </div>

                @if ($bgEmbed && ($bgEmbed['kind'] ?? '') === 'video')
                    <div class="aspect-video overflow-hidden rounded-lg ring-1 ring-slate-700">
                        <iframe src="{{ $bgEmbed['src'] }}" class="h-full w-full" style="border:0" allow="autoplay; fullscreen; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                    </div>
                    {{-- Playback settings — autoplay, start/end, cover/fit, controls (live-saved). --}}
                    <div x-data="{
                            autoplay: @js((bool) ($bgEmbed['autoplay'] ?? true)),
                            controls: @js((bool) ($bgEmbed['controls'] ?? true)),
                            fit: @js($bgEmbed['fit'] ?? 'cover'),
                            start: @js((int) ($bgEmbed['start'] ?? 0)),
                            end: @js((int) ($bgEmbed['end'] ?? 0)),
                            save() { $wire.setEmbedOptions({ autoplay: this.autoplay, controls: this.controls, fit: this.fit, start: Number(this.start) || 0, end: Number(this.end) || 0 }) }
                         }" class="space-y-2 rounded-lg bg-slate-800/40 p-2">
                        <label class="flex items-center justify-between gap-2">
                            <span class="text-[11px] text-slate-300">{{ __('Autoplay (muted)') }}</span>
                            <input type="checkbox" x-model="autoplay" @change="save()" class="toggle toggle-sm toggle-warning" />
                        </label>
                        <label class="flex items-center justify-between gap-2">
                            <span class="text-[11px] text-slate-300">{{ __('Show controls') }}</span>
                            <input type="checkbox" x-model="controls" @change="save()" class="toggle toggle-sm toggle-warning" />
                        </label>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[11px] text-slate-300">{{ __('Fit') }}</span>
                            <div class="inline-flex overflow-hidden rounded-lg border border-slate-700/60">
                                <button type="button" @click="fit = 'cover'; save()" :class="fit === 'cover' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400'" class="px-2.5 py-1 text-xs font-medium">{{ __('Cover') }}</button>
                                <button type="button" @click="fit = 'fit'; save()" :class="fit === 'fit' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400'" class="px-2.5 py-1 text-xs font-medium">{{ __('Fit') }}</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="form-control">
                                <span class="text-[10px] uppercase tracking-wider text-slate-500">{{ __('Start (s)') }}</span>
                                <input type="number" min="0" x-model="start" @change="save()" class="input input-xs input-bordered bg-slate-900 mt-1" />
                            </label>
                            <label class="form-control">
                                <span class="text-[10px] uppercase tracking-wider text-slate-500">{{ __('End (s)') }}</span>
                                <input type="number" min="0" x-model="end" @change="save()" class="input input-xs input-bordered bg-slate-900 mt-1" />
                            </label>
                        </div>
                    </div>
                    <button type="button" wire:click="clearBgEmbed" class="text-[11px] text-rose-300 underline hover:text-rose-200">{{ __('Remove video background') }}</button>
                @endif
            </div>

            {{-- 3D — Sketchfab model as the scene background --}}
            <div x-show="bgType === '3d'" x-cloak class="space-y-2">
                <div x-data="{ link: '' }">
                    <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Sketchfab model link or embed code') }}</span>
                    <div class="flex gap-1.5">
                        <input type="text" x-model="link" placeholder="https://sketchfab.com/3d-models/…"
                               class="input input-xs input-bordered flex-1 bg-slate-900" />
                        <button type="button" @click="if (link.trim()) { $wire.setSketchfabEmbed(link); link = '' }"
                                class="btn btn-xs border-0 bg-amber-500 font-semibold text-slate-950 hover:bg-amber-400">{{ __('Set') }}</button>
                    </div>
                    <p class="mt-1 text-[10px] text-slate-500">{{ __('Paste a Sketchfab model URL or its <iframe> embed code.') }}</p>
                </div>

                @if ($bgEmbed && ($bgEmbed['kind'] ?? '') === 'sketchfab')
                    <div class="aspect-video overflow-hidden rounded-lg ring-1 ring-slate-700">
                        <iframe src="{{ $bgEmbed['src'] }}" class="h-full w-full" style="border:0" allow="autoplay; fullscreen; xr-spatial-tracking" allowfullscreen></iframe>
                    </div>
                    <button type="button" wire:click="clearBgEmbed" class="text-[11px] text-rose-300 underline hover:text-rose-200">{{ __('Remove 3D background') }}</button>
                @endif
            </div>
        </div>

    </div>

    {{-- ── Skybox tab ───────────────────────────────────────────────────────── --}}
    <div x-show="view === 'skybox'" x-cloak class="space-y-2">

        @if (count($candidates) && ! $hasSkyboxImage)
            {{-- ── State A: candidates ready, awaiting the teacher's pick ───────── --}}
            <div class="space-y-2">
                <span class="text-[10px] uppercase tracking-widest text-slate-500 block">Pick your panorama</span>
                <div class="grid grid-cols-2 gap-1.5">
                    @foreach ($candidates as $i => $path)
                        <button type="button"
                                wire:click="selectSkyboxCandidate({{ $scene->id }}, {{ $i }})"
                                wire:loading.attr="disabled" wire:target="selectSkyboxCandidate,generateSkyboxCandidates"
                                class="group relative block w-full overflow-hidden rounded ring-1 ring-slate-700 hover:ring-2 hover:ring-amber-400 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                style="aspect-ratio:2/1">
                            <img src="{{ asset('storage/' . $path) }}"
                                 class="w-full h-full object-cover" />
                        </button>
                    @endforeach
                </div>
                <button type="button"
                        wire:click="generateSkyboxCandidates({{ $scene->id }})"
                        wire:loading.attr="disabled" wire:target="generateSkyboxCandidates"
                        @disabled($isBusy)
                        class="text-[10px] text-slate-400 hover:text-sky-400 underline underline-offset-2 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1">
                    <span wire:loading wire:target="generateSkyboxCandidates"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span wire:loading.remove wire:target="generateSkyboxCandidates">↻ Generate 4 new options</span>
                    <span wire:loading wire:target="generateSkyboxCandidates">Generating…</span>
                </button>
            </div>
        @elseif ($hasSkyboxImage)
            {{-- ── State B: a skybox has been chosen ───────────────────────────── --}}
            <div class="relative">
                <img src="{{ asset('storage/' . $scene->skybox_image_path) }}?v={{ $scene->updated_at?->timestamp }}"
                     class="w-full rounded object-cover @if($isUpscaling) opacity-60 @endif" style="aspect-ratio:2/1" />
                @if ($isUpscaling)
                    <div class="absolute inset-0 flex items-center justify-center gap-1.5 rounded">
                        <x-icons.spinner class="w-4 h-4 animate-spin text-violet-300" />
                        <span class="text-[10px] text-violet-300 font-medium">Upscaling…</span>
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap gap-1.5">
                <button type="button"
                        wire:click="generateSkyboxCandidates({{ $scene->id }})"
                        wire:loading.attr="disabled" wire:target="generateSkyboxCandidates"
                        @disabled($isBusy)
                        class="btn btn-outline btn-sm border-slate-600 text-slate-400 hover:border-sky-500 hover:text-sky-400 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="generateSkyboxCandidates"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span wire:loading.remove wire:target="generateSkyboxCandidates">↻ Regenerate</span>
                    <span wire:loading wire:target="generateSkyboxCandidates">Generating…</span>
                </button>
                <button type="button"
                        wire:click="enhanceSkybox({{ $scene->id }})"
                        wire:loading.attr="disabled" wire:target="enhanceSkybox"
                        @disabled($isBusy)
                        class="btn btn-xs bg-violet-600 text-white hover:bg-violet-500 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="enhanceSkybox"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span wire:loading.remove wire:target="enhanceSkybox">Enhance 4×</span>
                    <span wire:loading wire:target="enhanceSkybox">Enhancing…</span>
                </button>
            </div>
        @else
            {{-- ── State C: nothing generated yet ──────────────────────────────── --}}
            <div class="w-full rounded bg-slate-800 border border-dashed border-slate-600 flex items-center justify-center" style="aspect-ratio:2/1">
                @if ($isGenerating)
                    <x-icons.spinner class="w-5 h-5 animate-spin text-slate-500" />
                @else
                    <span class="text-xs text-slate-500">No panorama yet</span>
                @endif
            </div>

            <div class="flex flex-wrap gap-1.5">
                <button type="button"
                        wire:click="generateSkyboxCandidates({{ $scene->id }})"
                        wire:loading.attr="disabled" wire:target="generateSkyboxCandidates"
                        @disabled($isBusy || ! $scene->image_path)
                        class="btn btn-xs bg-sky-600 text-white hover:bg-sky-500 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="generateSkyboxCandidates"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span wire:loading.remove wire:target="generateSkyboxCandidates">Generate 4 panorama options</span>
                    <span wire:loading wire:target="generateSkyboxCandidates">Generating…</span>
                </button>
            </div>
        @endif

        @if (! $scene->image_path)
            <p class="text-[10px] text-slate-500">Generate the flat image first to unlock panorama options.</p>
        @endif

        {{-- Blur --}}
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="text-[10px] uppercase tracking-widest text-slate-500">Blur</label>
                <span class="text-[10px] font-mono text-amber-300" x-text="Number(blur).toFixed(2)"></span>
            </div>
            <input type="range" min="0.01" max="0.9" step="0.01"
                   x-model.number="blur"
                   @input="emitBlur()"
                   @change="$wire.set('selectedScene.skybox_blur', Number(blur)); $wire.call('saveSelected')"
                   class="range range-xs accent-amber-400 w-full" />
        </div>

        {{-- Opacity --}}
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="text-[10px] uppercase tracking-widest text-slate-500">Opacity</label>
                <span class="text-[10px] font-mono text-amber-300" x-text="Math.round(opacity * 100) + '%'"></span>
            </div>
            <input type="range" min="0" max="1" step="0.01"
                   x-model.number="opacity"
                   @input="emitOpacity()"
                   @change="$wire.set('selectedScene.skybox_opacity', Number(opacity)); $wire.call('saveSelected')"
                   class="range range-xs accent-amber-400 w-full" />
        </div>

        {{-- Background color --}}
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="text-[10px] uppercase tracking-widest text-slate-500">Background</label>
                <span class="text-[10px] font-mono text-amber-300" x-text="bgColor"></span>
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <span class="w-6 h-6 rounded border border-slate-600 overflow-hidden relative shrink-0"
                      :style="'background:' + bgColor">
                    <input type="color"
                           x-model="bgColor"
                           @input="emitBg()"
                           @change="$wire.set('selectedScene.background_color', String(bgColor)); $wire.call('saveSelected')"
                           class="absolute inset-0 opacity-0 w-full h-full cursor-pointer" />
                </span>
            </label>
        </div>
    </div>

    {{-- ── World tab ────────────────────────────────────────────────────────── --}}
    <div x-show="view === 'world'" x-cloak class="space-y-3">

        <div class="rounded-lg border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs space-y-1">
            @if($worldStatus === 'ready')
                <div class="flex items-center gap-2 text-emerald-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                    World ready
                </div>
            @elseif(in_array($worldStatus, ['pending', 'generating']))
                <div class="flex items-center gap-2 text-amber-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                    Generating world… this takes ~5–10 min
                </div>
            @elseif($worldStatus === 'failed')
                <div class="flex items-center gap-2 text-rose-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span>
                    Generation failed
                </div>
                <button wire:click="generateWorld({{ $scene->id }})"
                        class="text-amber-400 hover:text-amber-300 underline underline-offset-2 mt-1">Retry</button>
            @else
                <div class="text-slate-400">Select to start WorldLabs generation</div>
            @endif
        </div>

        <div x-data="{
                 open:        false,
                 savedY:      {{ $worldYOffset }},
                 savedScale:  {{ $worldScale }},
                 savedChar:   {{ $worldCharScale }},
                 get dirty() {
                     return Math.abs(charYOffset - this.savedY)    > 0.001
                         || Math.abs(worldScale  - this.savedScale) > 0.001
                         || Math.abs(charScale   - this.savedChar)  > 0.001
                 },
                 save() {
                     $wire.call('saveWorldSettings', Number(charYOffset), Number(worldScale), Number(charScale))
                     this.savedY     = charYOffset
                     this.savedScale = worldScale
                     this.savedChar  = charScale
                 }
             }">
            <button @click="open = !open"
                    class="flex items-center justify-between w-full text-[10px] uppercase tracking-widest text-slate-400 hover:text-slate-200 transition-colors">
                <span>World Settings</span>
                <svg class="w-3 h-3 transition-transform duration-200" :class="open ? 'rotate-180' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="open" class="mt-2 space-y-3">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-[10px] uppercase tracking-widest text-slate-500">World Y</label>
                        <span class="text-[10px] font-mono text-amber-300" x-text="(charYOffset >= 0 ? '+' : '') + Number(charYOffset).toFixed(2)"></span>
                    </div>
                    <input type="range" min="-3" max="3" step="0.01" x-model.number="charYOffset"
                           @input="emitCharY()" class="range range-xs accent-amber-400 w-full" />
                    <button @click="charYOffset = 0; emitCharY()" class="text-[9px] text-slate-500 hover:text-slate-300 mt-1">reset</button>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-[10px] uppercase tracking-widest text-slate-500">World Scale</label>
                        <span class="text-[10px] font-mono text-amber-300" x-text="Number(worldScale).toFixed(2) + '×'"></span>
                    </div>
                    <input type="range" min="0.1" max="5" step="0.01" x-model.number="worldScale"
                           @input="emitWorldScale()" class="range range-xs accent-amber-400 w-full" />
                    <button @click="worldScale = 1; emitWorldScale()" class="text-[9px] text-slate-500 hover:text-slate-300 mt-1">reset</button>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-[10px] uppercase tracking-widest text-slate-500">Char Scale</label>
                        <span class="text-[10px] font-mono text-amber-300" x-text="Number(charScale).toFixed(2) + '×'"></span>
                    </div>
                    <input type="range" min="0.1" max="3" step="0.01" x-model.number="charScale"
                           @input="emitCharScale()" class="range range-xs accent-amber-400 w-full" />
                    <button @click="charScale = 1; emitCharScale()" class="text-[9px] text-slate-500 hover:text-slate-300 mt-1">reset</button>
                </div>
                <button @click="dirty && save()"
                        :disabled="!dirty"
                        :class="dirty ? 'bg-slate-600 hover:bg-slate-500 text-white cursor-pointer' : 'bg-slate-800 text-slate-600 cursor-not-allowed'"
                        class="w-full rounded px-2 py-1 text-[10px] uppercase tracking-widest transition-colors">
                    Save world settings
                </button>
            </div>
        </div>
    </div>

</div>
