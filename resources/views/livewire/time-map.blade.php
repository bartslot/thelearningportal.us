@push('head-scripts')
    @vite('resources/js/timemap/index.js')
@endpush

<div class="relative h-[calc(100vh-4rem)] w-full"
     x-data="{ year: @js($year), readout: '' }"
     x-init="$nextTick(() => window.initTimeMap($refs.map, $wire, year))">
    {{-- Map canvas --}}
    <div x-ref="map" class="absolute inset-0" wire:ignore></div>

    {{-- Left story column --}}
    <aside class="absolute left-0 top-0 z-10 h-full w-80 overflow-y-auto bg-base-100/95 p-4 shadow-xl">
        <h2 class="text-lg font-bold">
            {{ $selectedPolity ?? __('Click a region') }}
        </h2>
        <div class="mt-3 space-y-3">
            @forelse ($stories as $story)
                <a href="{{ $story['source_url'] }}" target="_blank" rel="noopener"
                   wire:key="story-{{ $story['id'] }}"
                   class="card bg-base-200 p-3 hover:bg-base-300">
                    <span class="font-semibold">{{ $story['title'] }}</span>
                    <span class="text-xs opacity-70">{{ $story['era_start'] }}–{{ $story['era_end'] }}</span>
                </a>
            @empty
                <p class="opacity-70">{{ __('No stories here yet') }}</p>
            @endforelse
        </div>
    </aside>

    {{-- Time slider + readout --}}
    <div class="absolute bottom-0 left-1/2 z-10 mb-6 w-[36rem] max-w-[90vw] -translate-x-1/2 rounded-box bg-base-100/95 p-4 shadow-xl">
        <input type="range" class="range range-primary" min="-2000" max="1880" step="10"
               x-ref="slider" x-model.number="year"
               @input.debounce.150ms="(async () => { readout = await $refs.map._setYear(year) })()" />
        <div class="mt-2 flex justify-between text-sm">
            <span x-text="year < 0 ? Math.abs(year) + ' BCE' : year + ' CE'"></span>
            <span x-text="readout"></span>
        </div>
    </div>
</div>
