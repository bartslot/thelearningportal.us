@props(['layer', 'scene'])

{{-- Active-layer inspector (Keynote "Format the selection"): when a clipart layer is selected
     on the canvas / object list, its settings take over the aside. Deselect → back to scene. --}}
@php
    $aid = $layer['asset_id'];
    $slideshowMode = (string) ($scene->config['slideshow_mode'] ?? (($scene->config['parallax'] ?? false) ? 'parallax' : 'standard'));
    $parallax = $slideshowMode === 'parallax';
    // A map sits under voyage and map scenes, so a layer there can be pinned to a place.
    $isMapScene = in_array($scene->kind, ['map', 'voyage'], true);
    $isPinned = ($layer['anchor'] ?? 'screen') === 'map';
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

    {{-- 3D MODEL — how the object behaves once it is on the slide. Only a Sketchfab layer has
         these; a clipart image drops straight through to the controls below. --}}
    @php
        $embed = $layer['embed'] ?? null;
        $is3d = ($embed['type'] ?? null) === 'sketchfab';
        $eo = array_merge(['interact' => true, 'autospin' => true, 'bg' => 'none'], $embed['opts'] ?? []);
    @endphp
    @if ($is3d)
        <section class="rounded-lg border border-slate-700/60 bg-slate-900/40 p-2.5">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-slate-400">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
                {{ __('3D model') }}
            </div>

            <label class="mt-2 flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">{{ __('Interact') }}
                    <span class="block text-[10px] text-slate-500">{{ __('The class can grab the model and turn it.') }}</span>
                </span>
                <input type="checkbox" @checked($eo['interact'])
                       wire:change="setEmbedOption({{ $aid }}, 'interact', $event.target.checked)"
                       class="toggle toggle-sm toggle-warning shrink-0" />
            </label>

            <label class="mt-2 flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">{{ __('Turn by itself') }}
                    <span class="block text-[10px] text-slate-500">{{ __('Slowly rotates while the scene plays.') }}</span>
                </span>
                <input type="checkbox" @checked($eo['autospin'])
                       wire:change="setEmbedOption({{ $aid }}, 'autospin', $event.target.checked)"
                       class="toggle toggle-sm toggle-warning shrink-0" />
            </label>

            <div class="mt-2.5">
                <span class="text-[11px] text-slate-300">{{ __('Behind the model') }}</span>
                <div class="mt-1 grid grid-cols-3 gap-1">
                    @foreach ([['none', __('None')], ['glass', __('Glass')], ['#0f172a', __('Solid')]] as [$val, $label])
                        <button type="button" wire:click="setEmbedOption({{ $aid }}, 'bg', '{{ $val }}')"
                                @class([
                                    'rounded-md border px-2 py-1 text-[11px] transition',
                                    'border-amber-400 bg-amber-400/10 text-amber-300' => $eo['bg'] === $val,
                                    'border-slate-700 text-slate-400 hover:border-slate-500' => $eo['bg'] !== $val,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
                <p class="mt-1 text-[10px] text-slate-500">{{ __('None cuts the model out of its studio backdrop.') }}</p>
            </div>
        </section>
    @endif

    {{-- Name + thumbnail + remove --}}
    <div class="flex items-center gap-2">
        <img src="{{ $layer['url'] }}" alt="{{ $layer['title'] ?? '' }}"
             class="h-9 w-9 shrink-0 rounded bg-base-100 object-contain" />
        <h3 class="min-w-0 flex-1 truncate font-semibold text-amber-300">{{ $layer['title'] ?? __('Icon') }}</h3>
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
            ['scale', __('Size'), 0.2, 6, 0.05, 1.0],
            ['opacity', __('Opacity'), 0.05, 1, 0.05, 1.0],
            ['blur', __('Blur'), 0, 2.5, 0.1, 0],
        ]
    ) as [$field, $label, $min, $max, $step, $default])
        <label class="flex items-center gap-2">
            <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ $label }}</span>
            <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
                   value="{{ $layer[$field] ?? $default }}"
                   x-on:input="window.__lessonArtworkLayer?.setLayerProp?.({{ $aid }}, '{{ $field }}', $event.target.value)"
                   wire:change="updateArtworkLayer({{ $aid }}, '{{ $field }}', $event.target.value)"
                   class="range range-xs flex-1" />
            <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format((float) ($layer[$field] ?? $default), 2), '0'), '.') }}</span>
        </label>
    @endforeach

    {{-- How the layer mixes with what is behind it. Multiply is the one that earns its keep:
         it drops the white paper out of an engraving or a scanned map so the artwork sits ON the
         scene instead of in a box on top of it. --}}
    <label class="flex items-center gap-2">
        <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Blend') }}</span>
        <select x-on:input="window.__lessonArtworkLayer?.setLayerProp?.({{ $aid }}, 'blend', $event.target.value)"
                wire:change="updateArtworkLayer({{ $aid }}, 'blend', $event.target.value)"
                class="select select-xs select-bordered flex-1 border-slate-700 bg-slate-900 text-slate-300">
            @foreach ([
                'normal' => __('None'),
                'multiply' => __('Multiply (drops white paper)'),
                'screen' => __('Screen (drops black)'),
                'overlay' => __('Overlay'),
                'darken' => __('Darken'),
                'lighten' => __('Lighten'),
            ] as $bv => $bl)
                <option value="{{ $bv }}" @selected(($layer['blend'] ?? 'normal') === $bv)>{{ $bl }}</option>
            @endforeach
        </select>
    </label>

    {{-- Colour treatment. Drop White is the one that changes what a scan can be used for: an
         engraving arrives as ink on paper, and keying the paper out leaves just the ink, which can
         then be drained and recoloured to sit with the lesson's palette. --}}
    <div class="space-y-2 border-t border-slate-700/50 pt-2">
        <label class="flex items-center gap-2">
            <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Drop white') }}</span>
            <input type="range" min="0" max="0.5" step="0.01"
                   value="{{ $layer['white_key'] ?? 0 }}"
                   x-on:input="window.__lessonArtworkLayer?.setLayerProp?.({{ $aid }}, 'white_key', $event.target.value)"
                   wire:change="updateArtworkLayer({{ $aid }}, 'white_key', $event.target.value)"
                   class="range range-xs flex-1" />
            <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ round(((float) ($layer['white_key'] ?? 0)) * 100) }}%</span>
        </label>

        <label class="flex items-center justify-between gap-2">
            <span class="text-[11px] text-slate-300">{{ __('Grayscale') }}</span>
            <input type="checkbox" @checked($layer['grayscale'] ?? false)
                   x-on:input="window.__lessonArtworkLayer?.setLayerProp?.({{ $aid }}, 'grayscale', $event.target.checked)"
                   wire:change="updateArtworkLayer({{ $aid }}, 'grayscale', $event.target.checked)"
                   class="toggle toggle-sm toggle-warning shrink-0" />
        </label>

        <label class="flex items-center gap-2">
            <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Tint') }}</span>
            {{-- `input` fires on every move of the hue; `change` only when the picker closes. The
                 canvas follows the drag locally, and only the value the teacher settles on is
                 saved — one round-trip instead of hundreds. --}}
            <input type="color" value="{{ $layer['tint'] ?? '#38bdf8' }}"
                   x-on:input="window.__lessonArtworkLayer?.setLayerProp?.({{ $aid }}, 'tint', $event.target.value)"
                   wire:change="updateArtworkLayer({{ $aid }}, 'tint', $event.target.value)"
                   class="h-6 w-10 shrink-0 cursor-pointer rounded border border-slate-700 bg-slate-900" />
            <button type="button" wire:click="updateArtworkLayer({{ $aid }}, 'tint', '')"
                    class="flex-1 rounded-md border border-slate-700 px-2 py-1 text-[11px] text-slate-400 transition hover:border-slate-500 hover:text-slate-200">
                {{ __('No tint') }}
            </button>
        </label>
        <p class="text-[10px] leading-tight text-slate-500">{{ __('Tint recolours what survives the white key, so drop the white first.') }}</p>
        {{-- A line-art icon is black ink on nothing: there is no paper to key out, so Tint alone
             is what rescues it from a night scene. It reaches the ink either way — the flood is
             composited INTO the source's alpha, which for an icon is the strokes themselves. --}}
    </div>

    {{-- Pin to map — the same anchor a text label uses, so "pinned" means one thing on a scene.
         A pinned layer stores the lng/lat it sits over and tracks that place through pan and
         zoom; the placement sliders belong to a screen-anchored one, so they step aside. --}}
    @if ($isMapScene)
        <div class="space-y-1 border-t border-slate-700/50 pt-3">
            <label class="flex items-center justify-between gap-3">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Pin to map') }}</span>
                {{-- The map scene's overlay by its OWN handle first: the shared one can be
                     repointed by a slideshow render, and pinning the wrong overlay's layer
                     would silently do nothing. --}}
                <input type="checkbox" @checked($isPinned)
                       x-on:change="(window.__voyageArtworkLayer || window.__lessonArtworkLayer)?.togglePin?.({{ $aid }})"
                       class="toggle toggle-sm toggle-warning shrink-0" />
            </label>
            <p class="text-[10px] leading-tight text-slate-500">
                {{ $isPinned
                    ? __('Stays on this place as the map pans and zooms.')
                    : __('Sits at a fixed spot on the screen. Pin it to stick to the place underneath.') }}
            </p>
        </div>
    @endif

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
