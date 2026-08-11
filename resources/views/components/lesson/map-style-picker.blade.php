@props(['effectiveStyle' => 'soft-atlas', 'relief' => 0])

{{-- MAP STYLE — the front-end Time-Map palettes as Europe preview swatches. This sets the
     LESSON-WIDE default (every map block and every voyage inherits it), so teachers pick once.
     Clicking a swatch repaints the live preview instantly (lessonmap:style) and persists via
     setLessonMapStyle. Shared by the map and voyage inspectors — one picker, same behaviour. --}}
@php
    // What a teacher may CHOOSE. Earth and Night, and nothing else.
    //
    // Soft Atlas, Antique and Tolkien read as the same drawn map at lesson size — three swatches
    // for one look. Earth is Satellite v2 and replaces it as the photographic ground.
    //
    // The others are hidden, NOT removed: lessons already carry them, and deleting a style would
    // silently repaint someone's finished lesson. lesson-map.js still renders all five, and the
    // block below keeps whichever one a lesson is already on visible so its own choice never
    // disappears from under it. Converting existing lessons to Earth is a separate, deliberate job.
    $styleOptions = [
        'earth' => 'Earth',
        'night' => 'Night',
    ];
    $retired = [
        'soft-atlas' => 'Soft Atlas',
        'antique' => 'Antique',
        'pen-ink' => 'Tolkien',
        'satellite' => 'Satellite',
    ];
    if (isset($retired[$effectiveStyle])) {
        $styleOptions[$effectiveStyle] = $retired[$effectiveStyle];
    }
@endphp
<div class="form-control">
    <span class="text-xs uppercase tracking-wider text-slate-400">Map style</span>
    <span class="mt-0.5 text-[10px] text-slate-500">Applies to every map in this lesson.</span>
    <div class="mt-2 grid grid-cols-2 gap-2">
        @foreach ($styleOptions as $value => $label)
            <button type="button"
                    wire:click="setLessonMapStyle('{{ $value }}')"
                    onclick="window.dispatchEvent(new CustomEvent('lessonmap:style',{detail:{name:'{{ $value }}'}}))"
                    class="group overflow-hidden rounded-lg border-2 text-left transition {{ $effectiveStyle === $value ? 'border-amber-500' : 'border-slate-700/60 hover:border-slate-500' }}">
                {{-- Earth has no swatch of its own yet; it is the satellite ground, so that photo is
                     the honest stand-in until one is captured from the real render. --}}
                <img src="{{ asset('img/map-styles/'.($value === 'earth' ? 'satellite' : $value).'.webp') }}"
                     alt="{{ $label }} map style"
                     class="h-14 w-full object-cover" loading="lazy" />
                <span class="flex items-center justify-between px-2 py-1 text-[11px] font-medium {{ $effectiveStyle === $value ? 'bg-amber-500/15 text-amber-300' : 'bg-slate-900 text-slate-300' }}">
                    {{ $label }}
                    @if ($effectiveStyle === $value)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    @endif
                </span>
            </button>
        @endforeach
    </div>

    {{-- 3D TERRAIN — drape the map over the height map so mountains stand up and the traveller
         climbs them. The slider moves the preview live (lessonmap:relief, no re-mount) and only
         persists on release, so dragging doesn't fire a Livewire round-trip per pixel.
         Real height is invisible at continent scale, so the usable range starts well above 1×. --}}
    <div class="mt-3" x-data="{ relief: {{ (float) $relief }} }">
        <label class="flex items-center justify-between gap-2">
            <span class="text-xs uppercase tracking-wider text-slate-400">3D terrain</span>
            <input type="checkbox" :checked="relief > 0"
                   x-on:change="relief = $event.target.checked ? 3 : 0;
                                window.dispatchEvent(new CustomEvent('lessonmap:relief',{detail:{relief}}));
                                $wire.setLessonMapRelief(relief)"
                   class="toggle toggle-sm toggle-warning" />
        </label>
        <div class="mt-1.5" x-show="relief > 0" x-cloak>
            <label class="flex items-center gap-2">
                <span class="text-[10px] uppercase tracking-wider text-slate-500">Height</span>
                <input type="range" min="0.5" max="6" step="0.5" x-model.number="relief"
                       x-on:input="window.dispatchEvent(new CustomEvent('lessonmap:relief',{detail:{relief}}))"
                       x-on:change="$wire.setLessonMapRelief(relief)"
                       class="range range-xs range-warning flex-1" />
                <span class="w-8 text-right text-[10px] text-slate-400" x-text="relief + '×'"></span>
            </label>
            <span class="mt-1 block text-[10px] text-slate-500">Tilt the map to see it. Mountains are exaggerated on purpose.</span>
        </div>
    </div>
</div>
