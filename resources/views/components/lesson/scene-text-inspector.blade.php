@props(['text', 'scene'])

@php
    $id = (string) ($text['id'] ?? '');
    $isPanel = ($text['kind'] ?? null) === 'rect';
    $isGlass = ($text['bg'] ?? 'none') === 'glass';
    // Pinning needs a map under the label — offer it on the two scene kinds that have one.
    $isMapScene = in_array($scene->kind ?? null, ['map', 'voyage'], true);
    $isPinned = ($text['anchor'] ?? 'screen') === 'map';
@endphp

<div class="space-y-3 text-sm" wire:key="text-inspector-{{ $id }}">
    <button type="button" wire:click="clearActiveText"
            x-on:click="window.__lessonTextLayer?.select?.(null); window.__clearLayerGuard?.()"
            class="inline-flex items-center gap-1 text-xs text-slate-400 transition hover:text-amber-300">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
        </svg>
        {{ __('Scene') }}
    </button>

    @if ($isPanel)
        <div>
            <h3 class="font-semibold text-amber-300">{{ __('Background panel') }}</h3>
            <p class="mt-1 text-[11px] leading-tight text-slate-500">{{ __('A readable backing panel behind the scene text.') }}</p>
        </div>

        <label class="form-control">
            <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Side') }}</span>
            <select class="select select-sm select-bordered mt-1 bg-slate-900"
                    wire:change="updateSceneText(@js($id), 'side', $event.target.value)">
                <option value="left" @selected(($text['side'] ?? 'left') === 'left')>{{ __('Left') }}</option>
                <option value="right" @selected(($text['side'] ?? 'left') === 'right')>{{ __('Right') }}</option>
            </select>
        </label>

        <div class="flex items-center justify-between gap-3">
            <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Color') }}</span>
            {{-- @input patches the canvas overlay instantly while the picker is open;
                 wire:change persists once the teacher commits. --}}
            <input type="color" value="{{ $text['color'] ?? '#0f172a' }}"
                   @input="window.__lessonTextLayer?.patch?.(@js($id), { color: $event.target.value })"
                   wire:change="updateSceneText(@js($id), 'color', $event.target.value)"
                   class="h-8 w-12 cursor-pointer rounded border border-slate-700 bg-slate-900 p-1" />
        </div>

        <label class="flex items-center gap-2">
            <span class="w-14 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Opacity') }}</span>
            <input type="range" min="0.1" max="0.95" step="0.05" value="{{ $text['opacity'] ?? 0.5 }}"
                   @input="window.__lessonTextLayer?.patch?.(@js($id), { opacity: parseFloat($event.target.value) })"
                   wire:change="updateSceneText(@js($id), 'opacity', $event.target.value)"
                   class="range range-xs flex-1" />
            <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format((float) ($text['opacity'] ?? 0.5), 2), '0'), '.') }}</span>
        </label>
    @else
        <div>
            <h3 class="font-semibold text-amber-300">{{ __('Text') }}</h3>
            <p class="mt-1 text-[11px] leading-tight text-slate-500">{{ __('Edit content directly on the canvas. Adjust its appearance here.') }}</p>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <label class="form-control">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Font') }}</span>
                <select class="select select-sm select-bordered mt-1 bg-slate-900"
                        wire:change="updateSceneText(@js($id), 'font', $event.target.value)">
                    @foreach (['sans' => __('Sans'), 'history' => __('History'), 'cinzel' => __('Display')] as $value => $label)
                        <option value="{{ $value }}" @selected(($text['font'] ?? 'sans') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-control">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Size') }}</span>
                <select class="select select-sm select-bordered mt-1 bg-slate-900"
                        wire:change="updateSceneText(@js($id), 'size', $event.target.value)">
                    @foreach (['sm' => __('Small'), 'md' => __('Medium'), 'lg' => __('Large'), 'xl' => __('Extra large')] as $value => $label)
                        <option value="{{ $value }}" @selected(($text['size'] ?? 'md') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-control">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Align') }}</span>
                <select class="select select-sm select-bordered mt-1 bg-slate-900"
                        wire:change="updateSceneText(@js($id), 'align', $event.target.value)">
                    @foreach (['left' => __('Left'), 'center' => __('Center'), 'right' => __('Right')] as $value => $label)
                        <option value="{{ $value }}" @selected(($text['align'] ?? 'left') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-control">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('List') }}</span>
                <select class="select select-sm select-bordered mt-1 bg-slate-900"
                        wire:change="updateSceneText(@js($id), 'list', $event.target.value)">
                    @foreach (['none' => __('None'), 'bullet' => __('Bullets'), 'number' => __('Numbered')] as $value => $label)
                        <option value="{{ $value }}" @selected(($text['list'] ?? 'none') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <label class="flex cursor-pointer items-center justify-between gap-2 rounded-lg bg-slate-800/40 px-3 py-2">
            <span class="text-[10px] uppercase tracking-widest text-slate-400">{{ __('Glass background') }}</span>
            <input type="checkbox" class="toggle toggle-sm toggle-warning"
                   @checked($isGlass)
                   wire:click="updateSceneText(@js($id), 'bg', {{ $isGlass ? 'false' : 'true' }})" />
        </label>

        @if ($isGlass)
            <div class="flex items-center justify-between gap-3">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Glass color') }}</span>
                <input type="color" value="{{ $text['bgColor'] ?? '#0f172a' }}"
                       @input="window.__lessonTextLayer?.patch?.(@js($id), { bgColor: $event.target.value })"
                       wire:change="updateSceneText(@js($id), 'bgColor', $event.target.value)"
                       class="h-8 w-12 cursor-pointer rounded border border-slate-700 bg-slate-900 p-1" />
            </div>
            <label class="flex items-center gap-2">
                <span class="w-14 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ __('Opacity') }}</span>
                <input type="range" min="0.05" max="0.95" step="0.05" value="{{ $text['bgOpacity'] ?? 0.3 }}"
                       @input="window.__lessonTextLayer?.patch?.(@js($id), { bgOpacity: parseFloat($event.target.value) })"
                       wire:change="updateSceneText(@js($id), 'bgOpacity', $event.target.value)"
                       class="range range-xs flex-1" />
                <span class="w-9 text-right font-mono text-[10px] text-slate-400">{{ rtrim(rtrim(number_format((float) ($text['bgOpacity'] ?? 0.3), 2), '0'), '.') }}</span>
            </label>
        @endif

        {{-- Pin to map — only meaningful where a map sits under the text. A pinned label stores the
             lng/lat it is sitting over and tracks that place through pan and zoom; a screen label
             keeps the percentage placement below. The two are mutually exclusive, so the sliders
             disappear while pinned. --}}
        @if ($isMapScene)
            <div class="space-y-1 border-t border-slate-700/50 pt-3">
                <label class="flex items-center justify-between gap-3">
                    <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Pin to map') }}</span>
                    <input type="checkbox" @checked($isPinned)
                           x-on:change="window.__lessonTextLayer?.togglePin?.(@js($id))"
                           class="toggle toggle-sm toggle-warning shrink-0" />
                </label>
                <p class="text-[10px] leading-tight text-slate-500">
                    {{ $isPinned
                        ? __('Stays on this place as the map pans and zooms.')
                        : __('Sits at a fixed spot on the screen. Pin it to stick to the place underneath.') }}
                </p>
            </div>
        @endif

        @if (! $isPinned)
            <div class="space-y-2 border-t border-slate-700/50 pt-3">
                <span class="block text-[10px] uppercase tracking-widest text-slate-500">{{ __('Placement') }}</span>
                @foreach ([['x', __('Horizontal'), 0, 100, 1, $text['x'] ?? 40], ['y', __('Vertical'), 0, 100, 1, $text['y'] ?? 40], ['w', __('Width'), 5, 95, 1, $text['w'] ?? 46]] as [$field, $label, $min, $max, $step, $value])
                    <label class="flex items-center gap-2">
                        <span class="w-14 shrink-0 text-[10px] uppercase tracking-wide text-slate-500">{{ $label }}</span>
                        <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $value }}"
                               wire:change="updateSceneText(@js($id), '{{ $field }}', $event.target.value)"
                               class="range range-xs flex-1" />
                        <span class="w-7 text-right font-mono text-[10px] text-slate-400">{{ round((float) $value) }}</span>
                    </label>
                @endforeach
            </div>
        @endif
    @endif

    <button type="button" wire:click="deleteSceneText(@js($id))"
            wire:confirm="{{ $isPanel ? __('Remove this background panel?') : __('Remove this text?') }}"
            class="text-xs text-rose-300 underline transition hover:text-rose-200">
        {{ $isPanel ? __('Remove panel') : __('Remove text') }}
    </button>
</div>
