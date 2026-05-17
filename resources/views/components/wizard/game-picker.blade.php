@props([
    'games'      => collect(),
    'selectedId' => null,
    'teamCount'  => null,
    'splitCount' => 1,
    'maxSplit'   => 4,
])

<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div>
        <label class="text-xs uppercase tracking-wider text-slate-400">Strategy game</label>
        <select wire:model.live="strategy_game_id"
                class="select select-bordered w-full bg-slate-900 mt-1">
            <option value="">— no game —</option>
            @foreach ($games as $g)
                <option value="{{ $g->id }}">{{ $g->title }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="text-xs uppercase tracking-wider text-slate-400">Teams</label>
        <input type="number" wire:model="team_count" min="1" max="8"
               class="input input-bordered w-full bg-slate-900 mt-1" />
    </div>

    <div class="md:col-span-2">
        <label class="text-xs uppercase tracking-wider text-slate-400">Split game into segments</label>
        <div class="flex gap-2 mt-1">
            @for ($i = 1; $i <= $maxSplit; $i++)
                <button type="button"
                        wire:click="$set('game_split_count', {{ $i }})"
                        @class([
                            'flex-1 py-2 rounded-lg border-2 text-sm font-semibold transition-all',
                            'border-amber-400 bg-amber-500/10 text-amber-300' => $splitCount === $i,
                            'border-slate-600 text-slate-300 hover:border-slate-400' => $splitCount !== $i,
                        ])>{{ $i }}</button>
            @endfor
        </div>
        <p class="text-xs text-slate-500 mt-1">Game pauses for narration between segments — useful for drip-feeding new information.</p>
    </div>
</div>
