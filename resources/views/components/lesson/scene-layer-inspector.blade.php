@props(['layer', 'scene'])

{{-- Active-layer inspector (Keynote "Format the selection"): when a clipart layer is selected
     on the canvas / object list, its settings take over the aside. Deselect → back to scene. --}}
@php
    $aid = $layer['asset_id'];
    $slideshowMode = (string) ($scene->config['slideshow_mode'] ?? (($scene->config['parallax'] ?? false) ? 'parallax' : 'standard'));
    $parallax = $slideshowMode === 'parallax';
@endphp

<div class="space-y-3 text-sm" wire:key="layer-inspector-{{ $aid }}">
    {{-- Back to the scene settings (also clears the canvas ring + the JS dedupe guard). --}}
    <button type="button" wire:click="clearActiveLayer"
            x-on:click="window.__lessonArtworkLayer?.select?.(null); window.__clearLayerGuard?.()"
            class="inline-flex items-center gap-1 text-xs text-slate-400 transition hover:text-amber-300">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
        </svg>
        {{ __('Scene') }}
    </button>

    {{-- Name + thumbnail + remove --}}
    <div class="flex items-center gap-2">
        <img src="{{ $layer['url'] }}" alt="{{ $layer['title'] ?? '' }}"
             class="h-9 w-9 shrink-0 rounded bg-base-100 object-contain" />
        <h3 class="min-w-0 flex-1 truncate font-semibold text-amber-300">{{ $layer['title'] ?? __('Clipart') }}</h3>
        <button type="button" wire:click="detachArtwork({{ $aid }})"
                class="btn btn-ghost btn-xs btn-square text-slate-500 hover:text-rose-400"
                aria-label="{{ __('Remove layer') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <p class="text-[11px] leading-tight text-slate-500">{{ __('Drag on the canvas to move. Adjust placement and size here; reorder in the object list.') }}</p>

    {{-- Placement, size, and visual treatment stay in the Format panel. --}}
    @foreach (array_merge(
        $parallax ? [['depth', __('Depth'), 0.4, 2.5, 0.05, 1.3]] : [],
        [
            ['x', __('Horizontal'), 0, 100, 1, 50],
            ['y', __('Vertical'), 0, 100, 1, 58],
            ['scale', __('Size'), 0.2, 3, 0.05, 1.0],
            ['opacity', __('Opacity'), 0.05, 1, 0.05, 1.0],
            ['blur', __('Blur'), 0, 2.5, 0.1, 0],
        ]
    ) as [$field, $label, $min, $max, $step, $default])
        <label class="flex items-center gap-2">
            <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ $label }}</span>
            <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
                   value="{{ $layer[$field] ?? $default }}"
                   wire:change="updateArtworkLayer({{ $aid }}, '{{ $field }}', $event.target.value)"
                   class="range range-xs flex-1" />
            <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format((float) ($layer[$field] ?? $default), 2), '0'), '.') }}</span>
        </label>
    @endforeach

    {{-- Ink draw-on controls — only in Drawing mode. --}}
    @if ($slideshowMode === 'drawing')
        <div class="space-y-2 border-t border-slate-700/50 pt-2">
            <span class="block text-[10px] uppercase tracking-widest text-amber-400/70">{{ __('Ink draw-on') }}</span>
            <label class="flex items-center gap-2">
                <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Speed') }}</span>
                <input type="range" min="2" max="20" step="0.5"
                       value="{{ $layer['draw_time'] ?? 7 }}"
                       wire:change="updateArtworkLayer({{ $aid }}, 'draw_time', $event.target.value)"
                       class="range range-xs flex-1" title="{{ __('Seconds for the full draw-on') }}" />
                <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format((float) ($layer['draw_time'] ?? 7), 1), '0'), '.') }}s</span>
            </label>
            <div class="flex items-center gap-2">
                <select wire:change="updateArtworkLayer({{ $aid }}, 'ink_preset', $event.target.value)"
                        class="select select-xs select-bordered flex-1 border-slate-700 bg-slate-900 text-slate-300" title="{{ __('Pen style') }}">
                    @foreach (['production' => __('Production'), 'brush' => __('Brush'), 'sketch' => __('Sketch'), 'liner' => __('Liner'), 'etch' => __('Etch')] as $pv => $pl)
                        <option value="{{ $pv }}" @selected(($layer['ink_preset'] ?? 'production') === $pv)>{{ $pl }}</option>
                    @endforeach
                </select>
                <select wire:change="updateArtworkLayer({{ $aid }}, 'ink_fill', $event.target.value)"
                        class="select select-xs select-bordered flex-1 border-slate-700 bg-slate-900 text-slate-300" title="{{ __('Fill') }}">
                    @foreach (['auto' => __('Fill: Auto'), 'none' => __('Fill: None'), 'wash' => __('Fill: Wash'), 'hatch' => __('Fill: Hatch'), 'cross' => __('Fill: Crosshatch')] as $fv => $fl)
                        <option value="{{ $fv }}" @selected(($layer['ink_fill'] ?? 'auto') === $fv)>{{ $fl }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    @endif
</div>
