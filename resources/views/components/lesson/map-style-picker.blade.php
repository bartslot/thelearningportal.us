@props(['effectiveStyle' => 'soft-atlas'])

{{-- MAP STYLE — the front-end Time-Map palettes as Europe preview swatches. This sets the
     LESSON-WIDE default (every map block and every voyage inherits it), so teachers pick once.
     Clicking a swatch repaints the live preview instantly (lessonmap:style) and persists via
     setLessonMapStyle. Shared by the map and voyage inspectors — one picker, same behaviour. --}}
@php
    $styleOptions = [
        'soft-atlas' => 'Soft Atlas',
        'antique' => 'Antique',
        'pen-ink' => 'Tolkien',
        'night' => 'Night',
        'satellite' => 'Satellite',
    ];
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
                <img src="{{ asset('img/map-styles/'.$value.'.webp') }}" alt="{{ $label }} map style"
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
</div>
