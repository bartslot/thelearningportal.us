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
        <div x-data="easingPreview(@js($current['ease']))" x-init="init()" x-on:destroy="destroy()"
             class="grid grid-cols-3 gap-1.5">
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
