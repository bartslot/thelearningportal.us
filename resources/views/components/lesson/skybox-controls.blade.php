@props(['scene' => null])

@php
    $blur          = (float)  ($scene->skybox_blur      ?? 0.5);
    $opacity       = (float)  ($scene->skybox_opacity   ?? 1.0);
    $bgColor       = (string) ($scene->background_color ?? '#000000');
    $view          = (string) ($scene->scene_view       ?? 'skybox');
    $worldStatus   = (string) ($scene->world_labs_status ?? '');
    $worldYOffset  = (float)  ($scene->world_y_offset   ?? 0);
    $worldScale    = (float)  ($scene->world_scale      ?? 1);
    $worldCharScale= (float)  ($scene->world_char_scale ?? 0.53);
    $isGenerating  = $scene->status === 'generating';
    $isBusy        = $isGenerating;
    $hasSkyboxImage = ! empty($scene->skybox_image_path);
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

    {{-- ── Scene view tabs ─────────────────────────────────────────────────── --}}
    <div>
        <span class="text-[10px] uppercase tracking-widest text-slate-500 block mb-1.5">Scene View</span>
        <div class="flex rounded-lg overflow-hidden border border-slate-700 text-[11px] font-medium">
            @foreach (['slideshow' => 'Slideshow', 'skybox' => 'Skybox', 'world' => 'World'] as $tabVal => $tabLabel)
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
        <div class="flex items-start gap-3">
            @if ($scene->image_path)
                <img src="{{ asset('storage/' . $scene->image_path) }}"
                     class="w-20 h-12 rounded object-cover shrink-0" />
            @endif
            <button type="button"
                    wire:click="regenerate({{ $scene->id }}, 'image')"
                    wire:loading.attr="disabled" wire:target="regenerate"
                    @disabled($isBusy)
                    class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                @if ($isGenerating)
                    <x-icons.spinner class="w-3 h-3 animate-spin" />
                    <span>Generating…</span>
                @else
                    <x-icons.regenerate class="w-3 h-3" />
                    <span>Regenerate</span>
                @endif
            </button>
        </div>
        <details class="text-xs">
            <summary class="cursor-pointer text-slate-400">Prompt</summary>
            <textarea wire:model.blur="selectedScene.image_prompt" wire:change="saveSelected" rows="3"
                      class="textarea textarea-sm textarea-bordered bg-slate-900 mt-1 w-full"></textarea>
        </details>
    </div>

    {{-- ── Skybox tab ───────────────────────────────────────────────────────── --}}
    <div x-show="view === 'skybox'" x-cloak class="space-y-2">

        {{-- 2:1 preview --}}
        @if ($hasSkyboxImage)
            <img src="{{ asset('storage/' . $scene->skybox_image_path) }}?v={{ $scene->updated_at?->timestamp }}"
                 class="w-full rounded object-cover" style="aspect-ratio:2/1" />
        @else
            <div class="w-full rounded bg-slate-800 border border-dashed border-slate-600 flex items-center justify-center" style="aspect-ratio:2/1">
                @if ($isGenerating)
                    <x-icons.spinner class="w-5 h-5 animate-spin text-slate-500" />
                @else
                    <span class="text-xs text-slate-500">No panorama yet</span>
                @endif
            </div>
        @endif

        <div class="flex flex-wrap gap-1.5">
            @if (! $hasSkyboxImage)
                <button type="button"
                        wire:click="generateSkyboxImage({{ $scene->id }})"
                        wire:loading.attr="disabled" wire:target="generateSkyboxImage"
                        @disabled($isBusy || ! $scene->image_path)
                        class="btn btn-xs bg-sky-600 text-white hover:bg-sky-500 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="generateSkyboxImage"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span wire:loading.remove wire:target="generateSkyboxImage">Generate Skybox</span>
                    <span wire:loading wire:target="generateSkyboxImage">Generating…</span>
                </button>
            @else
                <button type="button"
                        wire:click="generateSkyboxImage({{ $scene->id }})"
                        wire:loading.attr="disabled" wire:target="generateSkyboxImage"
                        @disabled($isBusy)
                        class="btn btn-xs btn-outline btn-sm border-slate-600 text-slate-400 hover:border-sky-500 hover:text-sky-400 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="generateSkyboxImage"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                    <span wire:loading.remove wire:target="generateSkyboxImage">↻ Regenerate</span>
                    <span wire:loading wire:target="generateSkyboxImage">Generating…</span>
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
            @endif
        </div>

        @if (! $scene->image_path)
            <p class="text-[10px] text-slate-500">Generate the flat image first to unlock skybox conversion.</p>
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
