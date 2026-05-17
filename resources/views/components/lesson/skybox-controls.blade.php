@props(['scene' => null])

@php
    $blur    = (float)  ($scene->skybox_blur     ?? 0.5);
    $opacity = (float)  ($scene->skybox_opacity  ?? 1.0);
    $bgColor = (string) ($scene->background_color ?? '#000000');
@endphp

<div class="mt-2 space-y-3"
     x-data="{
        blur:    {{ $blur }},
        opacity: {{ $opacity }},
        bgColor: '{{ $bgColor }}',
        emitBlur()    { window.dispatchEvent(new CustomEvent('lesson:skybox:blur',    { detail: { blur:    Number(this.blur)    } })); },
        emitOpacity() { window.dispatchEvent(new CustomEvent('lesson:skybox:opacity', { detail: { opacity: Number(this.opacity) } })); },
        emitBg()      { window.dispatchEvent(new CustomEvent('lesson:skybox:bgcolor', { detail: { color:   String(this.bgColor) } })); },
     }">
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

    {{-- Background color (visible through transparent skybox) --}}
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
            <span class="text-[10px] text-slate-400">Visible behind transparent skybox</span>
        </label>
    </div>
</div>
