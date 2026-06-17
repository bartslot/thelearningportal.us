@props([
    'scene'    => null,
    'selected' => false,
])

<button type="button"
        wire:key="scene-thumb-{{ $scene->id }}"
        wire:click="selectScene({{ $scene->id }})"
        data-scene-id="{{ $scene->id }}"
        @class([
            'group relative shrink-0 w-32 aspect-video rounded-xl overflow-hidden transition-all',
            'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'        => $selected,
            'ring-1 ring-slate-700/50 hover:ring-slate-500'                    => ! $selected && $scene->status !== 'failed',
            'ring-1 ring-rose-500/50'                                          => $scene->status === 'failed',
        ])>
    @if ($scene->kind === 'map')
        <div class="w-full h-full bg-sky-800/30 border border-sky-600/30 flex flex-col items-center justify-center text-white gap-1">
            <svg class="h-6 w-6 opacity-90" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
            <span class="text-[9px] font-bold uppercase tracking-widest">Map</span>
            <span class="text-[9px] opacity-70">{{ $scene->year ?? '—' }}</span>
        </div>
    @elseif ($scene->kind === 'game')
        <div class="w-full h-full bg-teal-700/30 border border-teal-600/30 flex flex-col items-center justify-center text-white">
            <span class="text-[10px] font-bold tracking-widest mt-1">{{ strtoupper($scene->game_type ?? 'game') }}</span>
            <span class="text-[9px] opacity-70">Seg {{ $scene->game_segment_index }}</span>
        </div>
    @else
        @if ($scene->image_path)
            <img src="{{ asset('storage/' . $scene->image_path) }}"
                 class="w-full h-full object-cover" alt="" />
        @else
            <div class="w-full h-full bg-slate-800/60 flex items-center justify-center text-[10px] text-slate-500 tracking-widest">SCENE {{ $scene->order }}</div>
        @endif
        <span class="absolute bottom-0 inset-x-0 bg-black/55 text-[9px] text-white px-1 py-0.5">
            {{ $scene->year ?? '—' }} · {{ $scene->location ?? '—' }}
        </span>
    @endif

    @if ($scene->status === 'failed')
        <span class="absolute top-1 right-1 w-4 h-4 rounded-full bg-rose-500 text-white text-[8px] flex items-center justify-center">!</span>
    @endif
</button>
