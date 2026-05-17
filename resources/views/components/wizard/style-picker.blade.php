@props([
    'styles'      => [],
    'selected'    => 'realistic',
    'recommended' => [],
    'wireModel'   => 'image_style',
])

<div class="grid grid-cols-3 md:grid-cols-6 gap-3">
    @foreach ($styles as $s)
        <button type="button"
                wire:click="$set('{{ $wireModel }}', '{{ $s['key'] }}')"
                @class([
                    'relative aspect-square rounded-xl overflow-hidden transition-all',
                    'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900' => $selected === $s['key'],
                    'ring-1 ring-slate-700/50 hover:ring-slate-500' => $selected !== $s['key'],
                ])>
            <img src="{{ $s['thumb'] }}" alt="{{ $s['label'] }}" class="w-full h-full object-cover" />
            <span class="absolute bottom-1 left-1 right-1 text-[10px] uppercase tracking-wider text-white drop-shadow">{{ $s['label'] }}</span>
            @if (in_array($s['key'], $recommended, true))
                <span class="absolute top-1 right-1 text-[8px] bg-amber-500 text-slate-950 font-bold px-1.5 py-0.5 rounded">RECOMMENDED</span>
            @endif
        </button>
    @endforeach
</div>
