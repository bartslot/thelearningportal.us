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
    @if ($scene->kind === 'game')
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
