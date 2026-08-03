@php
    // The collection names are folder names; these are what a teacher reads.
    $collectionLabels = [
        'line-art' => __('Line art'),
        'arrows' => __('Arrows'),
        'shapes' => __('Shapes'),
    ];
@endphp

{{-- Icons tab of the bottom dock: pick an icon, drop it on the scene. Two rows of pills narrow
     the set down; the grid below scrolls. Clicking an icon places it mid-stage, dragging places
     it where it lands — and over a map, on the place it lands on. --}}
<div class="flex min-h-0 flex-1 flex-col gap-2 px-3 pb-2 pt-1">

    {{-- Row 1 — which set of icons. --}}
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($this->collections() as $set)
            <button type="button" wire:click="selectCollection('{{ $set }}')"
                    class="rounded-full px-3 py-1 text-[11px] font-medium transition-colors
                           {{ $collection === $set
                               ? 'bg-amber-500 text-slate-950'
                               : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200' }}">
                {{ $collectionLabels[$set] ?? ucfirst(str_replace('-', ' ', $set)) }}
            </button>
        @endforeach

        {{-- The teacher's own SVG imports still live in their library modal — one way in, kept
             out of the pill row because it is not another set of icons. --}}
        <button type="button" x-on:click="Livewire.dispatch('open-svg-library')"
                class="ml-auto rounded-full px-3 py-1 text-[11px] font-medium text-slate-500 transition-colors hover:text-slate-200">
            {{ __('Import…') }}
        </button>
    </div>

    {{-- Row 2 — a whole period, or one people inside it. Hidden for the flat sets (arrows, shapes),
         which have nothing to narrow down. Scrolls sideways rather than wrapping into the grid. --}}
    @if ($this->groups())
        <div class="flex items-center gap-1.5 overflow-x-auto pb-0.5">
            <button type="button" wire:click="selectGroup('', '')"
                    class="shrink-0 rounded-full px-3 py-1 text-[11px] font-medium transition-colors
                           {{ $category === ''
                               ? 'bg-amber-500 text-slate-950'
                               : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200' }}">
                {{ __('All') }}
            </button>
            @foreach ($this->groups() as $group)
                @php $active = $category === $group['category'] && $subcategory === $group['subcategory']; @endphp
                <button type="button"
                        wire:click="selectGroup(@js($group['category']), @js($group['subcategory']))"
                        class="shrink-0 rounded-full px-3 py-1 text-[11px] transition-colors
                               {{ $group['subcategory'] === '' ? 'font-semibold' : 'font-medium' }}
                               {{ $active
                                   ? 'bg-amber-500 text-slate-950'
                                   : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200' }}">
                    {{ $group['label'] }}
                </button>
            @endforeach
        </div>
    @endif

    {{-- The grid. The icons are black line art, so they are inverted to read on the dark dock —
         the scene keeps the original artwork (and its own tint control). --}}
    <div class="min-h-0 flex-1 overflow-y-auto">
        @if ($this->icons()->isEmpty())
            <p class="px-1 py-6 text-center text-xs text-slate-500">
                {{ __('No icons in this set yet.') }}
            </p>
        @else
            <div class="grid grid-cols-[repeat(auto-fill,minmax(56px,1fr))] gap-1">
                @foreach ($this->icons() as $icon)
                    <button type="button" draggable="true"
                            wire:key="icon-{{ $icon->id }}"
                            x-on:dragstart="$event.dataTransfer.setData('application/x-lp-icon', '{{ $icon->id }}');
                                            $event.dataTransfer.effectAllowed = 'copy'"
                            wire:click="place({{ $icon->id }})"
                            data-tooltip="{{ $icon->title }}"
                            class="flex aspect-square cursor-grab items-center justify-center rounded-lg p-2
                                   transition-colors hover:bg-slate-700/60 active:cursor-grabbing"
                            aria-label="{{ $icon->title }}">
                        <img src="{{ $icon->url() }}" alt="" draggable="false"
                             class="pointer-events-none h-full w-full object-contain invert" />
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</div>
