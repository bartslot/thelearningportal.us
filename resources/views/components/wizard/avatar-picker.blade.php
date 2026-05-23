@props([
    'avatars'    => collect(),
    'selectedId' => null,
])

<div class="flex gap-3 pb-2">
    @foreach ($avatars as $avatar)
        <button type="button"
                wire:click="$set('avatar_id', {{ $avatar->id }})"
                @class([
                    'shrink-0 w-20 h-20 rounded-xl overflow-hidden transition-all relative',
                    'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900' => $selectedId === $avatar->id,
                    'ring-1 ring-slate-700/50 hover:ring-slate-500' => $selectedId !== $avatar->id,
                ])>
            <img src="{{ $avatar->portraitUrl() ?? asset('assets/avatar-fallback.png') }}"
                 alt="{{ $avatar->name }}"
                 class="w-full h-full object-cover" />
            <span class="absolute bottom-0 left-0 right-0 text-[9px] bg-black/55 text-white py-0.5">{{ $avatar->name }}</span>
        </button>
    @endforeach
</div>
