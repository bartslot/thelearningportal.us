@props(['mode', 'layer' => null, 'scene' => null])

@php
    /**
     * The Animate tab. Two modes, one panel:
     *   'layer' — how THIS layer arrives: movement, delay, easing.
     *   'scene' — how this scene REPLACES the one before it, and how long that takes.
     *
     * The option lists mirror resources/js/scene/animations.js, which is what actually plays them
     * in the editor preview and the student player.
     */
    $isLayer = $mode === 'layer';

    $entrances = [
        'none' => __('None'),
        'fade' => __('Fade in'),
        'slide-left' => __('In from left'),
        'slide-right' => __('In from right'),
        'slide-up' => __('In from below'),
        'slide-down' => __('In from above'),
        'zoom' => __('Zoom in'),
        'pop' => __('Pop'),
    ];

    $transitions = [
        'crossfade' => __('Crossfade'),
        'slide-left' => __('Slide left'),
        'slide-right' => __('Slide right'),
        'slide-up' => __('Slide up'),
        'slide-down' => __('Slide down'),
        'cut' => __('Cut'),
    ];

    $transition = ($scene?->config['transition'] ?? []) + ['type' => 'crossfade', 'duration' => 0.8, 'ease' => 'move'];

    // The itinerary scene has something to animate that no other scene has: the route itself. It
    // sails nothing, so "how it arrives" is the only motion it would otherwise own — and a whole
    // voyage appearing at once tells the class nothing about the order it was sailed in.
    $isOverview = $scene?->kind === 'voyage' && (bool) ($scene->config['overview'] ?? false);
    $routeAnim = ($scene?->config['overview_anim'] ?? []) + ['route' => 'draw', 'duration' => 3.0, 'stops' => true];
    $routeReveals = [
        'draw' => __('Draw the route'),
        'none' => __('Show it all at once'),
    ];

    $aid = $isLayer ? (int) ($layer['asset_id'] ?? 0) : 0;
    $current = $isLayer
        ? ['anim' => $layer['anim'] ?? 'none', 'delay' => (float) ($layer['anim_delay'] ?? 0), 'ease' => $layer['anim_ease'] ?? 'enter']
        : ['anim' => $transition['type'], 'delay' => (float) $transition['duration'], 'ease' => $transition['ease']];
@endphp

<div class="space-y-3">
    <p class="text-[11px] leading-tight text-slate-500">
        {{ $isLayer
            ? __('How this layer arrives when the scene starts.')
            : __('How this scene replaces the one before it.') }}
    </p>

    @if ($isOverview)
        {{-- ROUTE — the itinerary's own motion: the line drawing itself from the home port outwards,
             with each numbered stop appearing as the route reaches it. Same controls as everything
             else in this tab (a choice, then a time), so it reads as one panel. --}}
        <section class="space-y-2 rounded-lg border border-slate-700/60 bg-slate-900/40 p-2">
            <label class="block space-y-1">
                <span class="block text-[10px] uppercase tracking-wide text-slate-500">{{ __('Route') }}</span>
                <select class="select select-xs select-bordered w-full border-slate-700 bg-slate-900 text-slate-300"
                        wire:change="setOverviewAnim('route', $event.target.value)">
                    @foreach ($routeReveals as $value => $label)
                        <option value="{{ $value }}" @selected($routeAnim['route'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <div @class(['space-y-2', 'pointer-events-none opacity-40' => $routeAnim['route'] === 'none'])>
                <label class="flex items-center gap-2">
                    <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Time') }}</span>
                    <input type="range" min="0.5" max="10" step="0.5" value="{{ $routeAnim['duration'] }}"
                           wire:change="setOverviewAnim('duration', $event.target.value)"
                           class="range range-xs flex-1" />
                    <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format((float) $routeAnim['duration'], 1), '0'), '.') }}s</span>
                </label>

                <label class="flex items-center justify-between gap-2">
                    <span class="text-[11px] text-slate-300">{{ __('Number the stops as it goes') }}</span>
                    <input type="checkbox" @checked($routeAnim['stops'])
                           wire:change="setOverviewAnim('stops', $event.target.checked)"
                           class="toggle toggle-xs toggle-warning" />
                </label>
            </div>
        </section>
    @endif

    {{-- Movement. The label sits ABOVE its control rather than in the 3rem gutter the sliders use:
         "Transition" does not fit there and was rendering clipped. --}}
    <label class="block space-y-1">
        <span class="block text-[10px] uppercase tracking-wide text-slate-500">
            {{ $isLayer ? __('Appear') : __('Transition') }}
        </span>
        <select @class(['select select-xs select-bordered w-full border-slate-700 bg-slate-900 text-slate-300'])
                @if ($isLayer)
                    wire:change="updateArtworkLayer({{ $aid }}, 'anim', $event.target.value)"
                @else
                    wire:change="setSceneTransition('type', $event.target.value)"
                @endif>
            @foreach (($isLayer ? $entrances : $transitions) as $value => $label)
                <option value="{{ $value }}" @selected($current['anim'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    {{-- Delay (layer) or duration (scene) --}}
    <label class="flex items-center gap-2">
        <span class="w-12 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">
            {{ $isLayer ? __('Delay') : __('Time') }}
        </span>
        <input type="range"
               min="0" max="{{ $isLayer ? 10 : 3 }}" step="{{ $isLayer ? 0.1 : 0.1 }}"
               value="{{ $current['delay'] }}"
               @if ($isLayer)
                   wire:change="updateArtworkLayer({{ $aid }}, 'anim_delay', $event.target.value)"
               @else
                   wire:change="setSceneTransition('duration', $event.target.value)"
               @endif
               class="range range-xs flex-1" />
        <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format($current['delay'], 1), '0'), '.') ?: '0' }}s</span>
    </label>

    {{-- Easing, chosen from live curves rather than a list of names --}}
    <div class="space-y-1.5" wire:ignore>
        <span class="block text-[10px] uppercase tracking-wide text-slate-500">{{ __('Easing') }}</span>
        {{-- auto-fit rather than a fixed 3 columns: the panel is resizable, and three fixed columns
             overflowed it at narrow widths, which is what pushed the whole tab sideways. --}}
        <div x-data="easingPreview(@js($current['ease']))" x-init="init()" x-on:destroy="destroy()"
             class="grid gap-1.5 [grid-template-columns:repeat(auto-fit,minmax(4rem,1fr))]">
            <template x-for="opt in options" :key="opt.key">
                <button type="button"
                        @click="choose(opt.key, (k) => $wire.{{ $isLayer ? "updateArtworkLayer($aid, 'anim_ease', k)" : "setSceneTransition('ease', k)" }})"
                        :class="selected === opt.key
                            ? 'border-amber-400 bg-slate-900 text-amber-300'
                            : 'border-slate-700 bg-slate-900/60 text-slate-400 hover:border-slate-500'"
                        class="flex flex-col items-center gap-0.5 rounded-lg border p-1 transition">
                    <svg viewBox="0 0 72 44" class="h-8 w-full" aria-hidden="true">
                        {{-- Rest line: where the value starts and ends, so the curve has a frame of reference. --}}
                        <line x1="6" y1="38" x2="66" y2="38" stroke="currentColor" stroke-width="0.5" opacity="0.25" />
                        <line x1="6" y1="6" x2="66" y2="6" stroke="currentColor" stroke-width="0.5" opacity="0.25" />
                        <path :d="opt.path" fill="none" stroke="currentColor" stroke-width="1.5"
                              stroke-linecap="round" stroke-linejoin="round" opacity="0.9" />
                        {{-- The entity: a dot running the curve on the shared clock. --}}
                        <circle :cx="dot(opt.key).x" :cy="dot(opt.key).y" r="3.2" fill="currentColor" />
                    </svg>
                    <span class="text-[9px] leading-none" x-text="opt.label"></span>
                </button>
            </template>
        </div>
    </div>
</div>
