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

    {{-- ── Scene view tabs ─────────────────────────────────────────────────── --}}
    <div>
        <span class="text-[10px] uppercase tracking-widest text-slate-500 block mb-1.5">Scene View</span>
        <div class="flex rounded-lg overflow-hidden border border-slate-700 text-[11px] font-medium">
            @foreach (['slideshow' => 'Slideshow', 'skybox' => 'Panorama', 'world' => '3D World'] as $tabVal => $tabLabel)
                <button type="button"
                        @click="
                            view = '{{ $tabVal }}';
                            window.dispatchEvent(new CustomEvent('lesson:scene:view', { detail: {
                                view:     '{{ $tabVal }}',
                                imageUrl: {{ $scene->image_path ? json_encode(asset('storage/' . $scene->image_path)) : 'null' }},
                                sceneId:  {{ $scene->id }},
                                duration: {{ $scene->duration_seconds ?? 10 }},
                            }}));
                            @if ($tabVal === 'world')
                            $wire.call('generateWorld', $wire.get('selectedSceneId'));
                            @else
                            $wire.call('setSceneView', '{{ $tabVal }}');
                            @endif
                        "
                        :class="view === '{{ $tabVal }}'
                            ? 'bg-amber-500 text-slate-950'
                            : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200'"
                        class="flex-1 py-1.5 transition-colors">
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>
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

        {{-- Current background preview + remove --}}
        <div class="flex items-start gap-3">
            @if ($scene->image_path)
                <img src="{{ asset('storage/' . $scene->image_path) }}"
                     class="w-20 h-12 rounded object-cover shrink-0" />
                <button type="button"
                        wire:click="deleteSceneImage({{ $scene->id }})"
                        wire:confirm="{{ __('Remove this image? The scene will use a solid dark background instead.') }}"
                        @disabled($isBusy)
                        class="btn btn-xs btn-ghost text-rose-300 hover:text-rose-200 self-center"
                        title="{{ __('Remove image — use solid background') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-8 0 1 13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1l1-13"/></svg>
                </button>
            @else
                {{-- No image: solid backdrop. Click the swatch → hue picker + dark presets. --}}
                <div class="relative shrink-0"
                     x-data="{ pickerOpen: false, solidColor: @js($scene->background_color ?? '#0f172a') }">
                    <button type="button" @click="pickerOpen = !pickerOpen"
                            class="w-20 h-12 rounded ring-1 ring-slate-700 hover:ring-amber-400 flex items-center justify-center transition"
                            :style="'background-color:' + solidColor"
                            title="{{ __('Solid background — click to change color') }}">
                        <span class="text-[9px] uppercase tracking-wider text-slate-400">{{ __('solid') }}</span>
                    </button>

                    <div x-show="pickerOpen" x-cloak @click.outside="pickerOpen = false"
                         x-transition.opacity.duration.150ms
                         class="absolute left-0 top-14 z-30 rounded-xl border border-slate-700 bg-base-300 p-3 shadow-2xl space-y-2 w-52">
                        <span class="text-[10px] uppercase tracking-widest text-slate-500 block">{{ __('Background color') }}</span>
                        <div class="flex items-center gap-1.5">
                            @foreach (['#0f172a' => 'Dark blue', '#3f0d12' => 'Dark red', '#052e16' => 'Dark green', '#2e1065' => 'Dark purple', '#1c1917' => 'Charcoal'] as $preset => $presetLabel)
                                <button type="button"
                                        @click="solidColor = '{{ $preset }}';
                                                $wire.setSceneBackgroundColor('{{ $preset }}')"
                                        class="h-7 w-7 rounded-lg ring-1 transition"
                                        :class="solidColor === '{{ $preset }}' ? 'ring-2 ring-amber-400' : 'ring-slate-600 hover:ring-slate-400'"
                                        style="background-color: {{ $preset }}"
                                        title="{{ __($presetLabel) }}"></button>
                            @endforeach
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer pt-1">
                            <span class="w-7 h-7 rounded-lg border border-slate-600 overflow-hidden relative shrink-0"
                                  :style="'background:' + solidColor">
                                <input type="color" x-model="solidColor"
                                       @change="$wire.setSceneBackgroundColor(String(solidColor))"
                                       class="absolute inset-0 opacity-0 w-full h-full cursor-pointer" />
                            </span>
                            <span class="text-xs text-slate-400">{{ __('Custom…') }}</span>
                            <span class="text-[10px] font-mono text-amber-300 ml-auto" x-text="solidColor"></span>
                        </label>
                    </div>
                </div>
            @endif
        </div>

        {{-- Background — how this scene is filled. Keynote model: a scene has ONE
             background (image / video / 3D). Clipart and other objects go ON TOP via
             the Insert tools, not here. --}}
        <div x-data="{ bgType: @js(in_array($srcDefault, ['video', '3d'], true) ? $srcDefault : 'image'), imgSrc: @js(in_array($srcDefault, ['ai', 'paintings', 'url'], true) ? $srcDefault : 'ai') }" class="space-y-2">
            <span class="block text-[10px] uppercase tracking-widest text-slate-500">{{ __('Background') }}</span>
            <div class="flex overflow-hidden rounded-lg border border-slate-700 text-[10px] font-medium">
                @foreach (['image' => __('Image'), 'video' => __('Video'), '3d' => __('3D')] as $bv => $bl)
                    <button type="button" @click="bgType = '{{ $bv }}'"
                            :class="bgType === '{{ $bv }}' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200'"
                            class="flex-1 whitespace-nowrap px-1 py-1.5 transition-colors">{{ $bl }}</button>
                @endforeach
            </div>

          {{-- Image — where the background image comes from: generate, a painting, or a URL --}}
          <div x-show="bgType === 'image'" x-cloak class="space-y-2">
            <div class="flex overflow-hidden rounded-lg border border-slate-700/70 text-[10px] font-medium">
                @foreach (['ai' => __('AI generated'), 'paintings' => __('Paintings'), 'url' => __('URL')] as $sv => $sl)
                    <button type="button" @click="imgSrc = '{{ $sv }}'"
                            :class="imgSrc === '{{ $sv }}' ? 'bg-slate-700 text-amber-300' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-slate-200'"
                            class="flex-1 whitespace-nowrap px-1 py-1 transition-colors">{{ $sl }}</button>
                @endforeach
            </div>

            {{-- AI generated --}}
            <div x-show="imgSrc === 'ai'" x-cloak class="space-y-2">
                <button type="button"
                        wire:click="regenerate({{ $scene->id }}, 'image')"
                        wire:loading.attr="disabled" wire:target="regenerate"
                        @disabled($isBusy)
                        class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    @if ($isGenerating)
                        <x-icons.spinner class="w-3 h-3 animate-spin" />
                        <span>{{ __('Generating…') }}</span>
                    @else
                        <x-icons.regenerate class="w-3 h-3" />
                        <span>{{ $scene->image_path ? __('Regenerate') : __('Generate image') }}</span>
                    @endif
                </button>
                <details class="text-xs">
                    <summary class="cursor-pointer text-slate-400">{{ __('Prompt') }}</summary>
                    <textarea wire:model.blur="selectedScene.image_prompt" wire:change="saveSelected" rows="3"
                              class="textarea textarea-sm textarea-bordered bg-slate-900 mt-1 w-full"></textarea>
                </details>
            </div>

            {{-- Paintings (curated public-domain / museum works) as the background image --}}
            <div x-show="imgSrc === 'paintings'" x-cloak class="space-y-1.5">
                <button type="button" wire:click="openPaintingPicker" @disabled($isBusy)
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

            {{-- URL (paste a direct image link) --}}
            <div x-show="imgSrc === 'url'" x-cloak x-data="{ imageUrl: '' }" class="space-y-1.5">
                <input type="url" x-model="imageUrl" placeholder="https://…/image.jpg"
                       class="input input-xs input-bordered bg-slate-900 w-full" />
                <button type="button"
                        @click="imageUrl && $wire.applyImageUrl({{ $scene->id }}, imageUrl)"
                        wire:loading.attr="disabled" wire:target="applyImageUrl"
                        class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="applyImageUrl"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span>{{ __('Use image URL') }}</span>
                </button>
                <p class="text-[10px] text-slate-500">{{ __('Paste a direct image link — it’s downloaded and saved to this lesson.') }}</p>
            </div>
          </div>{{-- /image sub-sources --}}

            {{-- Video — YouTube/Vimeo embed (next todo) --}}
            <div x-show="bgType === 'video'" x-cloak
                 class="rounded-lg border border-dashed border-slate-700 p-3 text-[11px] text-slate-500">
                {{ __('Embed a YouTube or Vimeo video as the scene — coming soon.') }}
            </div>

            {{-- 3D — Sketchfab embed (next todo) --}}
            <div x-show="bgType === '3d'" x-cloak
                 class="rounded-lg border border-dashed border-slate-700 p-3 text-[11px] text-slate-500">
                {{ __('Embed a Sketchfab 3D model — coming soon.') }}
            </div>
        </div>

        {{-- Layers — objects placed ON TOP of the background. Clipart is added from the
             Insert tools on the canvas; each layer keeps its own depth, kind and controls.
             (Keynote model: objects live above the slide background, not inside it.) --}}
        @if (count($this->sceneArtworkLayers()) > 0)
            <div class="space-y-1.5">
                {{-- Layers are just names here; click one to edit it in the inspector (its settings
                     take over the aside — Keynote's "Format the selection"). --}}
                <span class="block text-[10px] uppercase tracking-widest text-slate-500">{{ __('Layers') }}</span>
                @foreach ($this->sceneArtworkLayers() as $layer)
                    @php($aid = $layer['asset_id'])
                    <div wire:key="layer-{{ $aid }}"
                         x-on:click="window.__lessonArtworkLayer?.select?.('art_{{ $aid }}'); $wire.call('setActiveLayer', {{ $aid }})"
                         @class([
                             'group flex cursor-pointer items-center gap-2 rounded p-1.5 text-[11px] transition',
                             'bg-amber-500/15 ring-1 ring-amber-500/40' => $this->activeLayerId === $aid,
                             'bg-slate-800/50 hover:bg-slate-800' => $this->activeLayerId !== $aid,
                         ])>
                        <img src="{{ $layer['url'] }}" alt="{{ $layer['title'] }}"
                             class="h-7 w-7 shrink-0 rounded bg-base-100 object-contain" />
                        <span class="min-w-0 flex-1 truncate font-medium text-slate-300">{{ $layer['title'] }}</span>
                        <button type="button" wire:click.stop="detachArtwork({{ $aid }})"
                                class="btn btn-ghost btn-xs btn-square text-slate-500 opacity-0 transition hover:text-rose-400 group-hover:opacity-100"
                                aria-label="{{ __('Remove layer') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Background motion: Animated toggle + Ken Burns direction — applies to any image. --}}
        @if ($scene->image_path)
            <div class="flex flex-wrap items-center gap-3 pt-1"
                 x-data="{ animated: @js((bool) ($scene->kb_animated ?? true)) }">
                <label class="flex cursor-pointer items-center gap-2">
                    <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Animated') }}</span>
                    <input type="checkbox" class="toggle toggle-sm toggle-warning"
                           x-on:change="animated = $el.checked"
                           wire:model.live="selectedScene.kb_animated"
                           wire:change="saveSelected" />
                </label>
                <select x-show="animated" x-transition.opacity.duration.150ms
                        wire:model.live="selectedScene.kb_direction" wire:change="saveSelected"
                        class="select select-xs select-bordered bg-slate-900">
                    <option value="">{{ __('Auto (varied pans)') }}</option>
                    <option value="left_right">{{ __('Moving left → right') }}</option>
                    <option value="right_left">{{ __('Moving right → left') }}</option>
                    <option value="zoom_in">{{ __('Slow zoom in') }}</option>
                    <option value="zoom_out">{{ __('Slow zoom out') }}</option>
                </select>
            </div>
        @endif
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
                        class="btn btn-xs btn-outline btn-sm border-slate-600 text-slate-400 hover:border-sky-500 hover:text-sky-400 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
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
