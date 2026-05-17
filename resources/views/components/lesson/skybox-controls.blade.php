@props(['scene' => null])

@php
    $blur    = (float) ($scene->skybox_blur    ?? 0.5);
    $opacity = (float) ($scene->skybox_opacity ?? 1.0);
@endphp

<div class="mt-2 space-y-3"
     x-data="{
        blur:    {{ $blur }},
        opacity: {{ $opacity }},
        emitBlur()    { window.dispatchEvent(new CustomEvent('lesson:skybox:blur',    { detail: { blur:    Number(this.blur)    } })); },
        emitOpacity() { window.dispatchEvent(new CustomEvent('lesson:skybox:opacity', { detail: { opacity: Number(this.opacity) } })); },
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
</div>
