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

    {{-- Scene view --}}
    <div>
        <div class="flex items-center justify-between mb-1">
            <label class="text-[10px] uppercase tracking-widest text-slate-500">Scene view</label>
        </div>
        <select x-model="view"
                @change="
                    $wire.set('selectedScene.scene_view', String(view));
                    if (view === 'world') { $wire.call('generateWorld', $wire.get('selectedSceneId')); }
                    else { $wire.call('saveSelected'); }
                "
                class="select select-sm select-bordered bg-slate-900 w-full">
            <option value="skybox">Skybox (3D panorama)</option>
            <option value="slideshow">Slideshow (flat image)</option>
            <option value="world">World (WorldLabs 3D)</option>
        </select>
    </div>

    {{-- WorldLabs status indicator --}}
    @if($view === 'world')
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
                    class="text-amber-400 hover:text-amber-300 underline underline-offset-2 mt-1">
                Retry
            </button>
        @else
            <div class="text-slate-400">Select to start WorldLabs generation</div>
        @endif
    </div>
    @endif

    {{-- World settings collapsible (world view only) --}}
    <div x-show="view === 'world'" x-cloak
         x-data="{
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

            {{-- World Y --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500">World Y</label>
                    <span class="text-[10px] font-mono text-amber-300" x-text="(charYOffset >= 0 ? '+' : '') + Number(charYOffset).toFixed(2)"></span>
                </div>
                <input type="range" min="-3" max="3" step="0.01"
                       x-model.number="charYOffset"
                       @input="emitCharY()"
                       class="range range-xs accent-amber-400 w-full" />
                <button @click="charYOffset = 0; emitCharY()"
                        class="text-[9px] text-slate-500 hover:text-slate-300 mt-1">reset</button>
            </div>

            {{-- World Scale --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500">World Scale</label>
                    <span class="text-[10px] font-mono text-amber-300" x-text="Number(worldScale).toFixed(2) + '×'"></span>
                </div>
                <input type="range" min="0.1" max="5" step="0.01"
                       x-model.number="worldScale"
                       @input="emitWorldScale()"
                       class="range range-xs accent-amber-400 w-full" />
                <button @click="worldScale = 1; emitWorldScale()"
                        class="text-[9px] text-slate-500 hover:text-slate-300 mt-1">reset</button>
            </div>

            {{-- Char Scale --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500">Char Scale</label>
                    <span class="text-[10px] font-mono text-amber-300" x-text="Number(charScale).toFixed(2) + '×'"></span>
                </div>
                <input type="range" min="0.1" max="3" step="0.01"
                       x-model.number="charScale"
                       @input="emitCharScale()"
                       class="range range-xs accent-amber-400 w-full" />
                <button @click="charScale = 1; emitCharScale()"
                        class="text-[9px] text-slate-500 hover:text-slate-300 mt-1">reset</button>
            </div>

            {{-- Save --}}
            <button @click="dirty && save()"
                    :disabled="!dirty"
                    :class="dirty
                        ? 'bg-slate-600 hover:bg-slate-500 text-white cursor-pointer'
                        : 'bg-slate-800 text-slate-600 cursor-not-allowed'"
                    class="w-full rounded px-2 py-1 text-[10px] uppercase tracking-widest transition-colors">
                Save world settings
            </button>

        </div>
    </div>

    {{-- Blur (skybox only) --}}
    <div x-show="view === 'skybox'">
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

    {{-- Opacity (skybox only) --}}
    <div x-show="view === 'skybox'">
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

    {{-- Background color (visible through transparent skybox; skybox view only) --}}
    <div x-show="view === 'skybox'">
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
