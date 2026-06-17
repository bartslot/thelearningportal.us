@props(['scene' => null])

<div class="space-y-3 text-sm">
    <h3 class="flex items-center gap-2 font-semibold text-sky-300">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
        Map block
    </h3>

    <p class="text-xs text-slate-400">
        Shows the territory on the historical map, fit to its borders at the chosen year.
    </p>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Year</span>
        <input type="number" wire:model.blur="selectedScene.config.year" wire:change="saveSelected"
               placeholder="e.g. 1600"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
        <span class="mt-1 text-[10px] text-slate-500">Negative = BCE. Sets the map's time slider.</span>
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Playback</span>
        <select wire:model.live="selectedScene.config.playback_mode" wire:change="saveSelected"
                class="select select-sm select-bordered bg-slate-900 mt-1">
            <option value="interactive">Interactive — explore, then Continue</option>
            <option value="timed">Timed — fly-to, then auto-advance</option>
        </select>
    </label>

    <div x-show="$wire.selectedScene?.config?.playback_mode === 'timed'">
        <label class="form-control">
            <span class="text-xs uppercase tracking-wider text-slate-400">Hold (seconds)</span>
            <input type="number" min="2" max="30" wire:model.blur="selectedScene.config.hold_seconds" wire:change="saveSelected"
                   placeholder="7"
                   class="input input-sm input-bordered bg-slate-900 mt-1" />
        </label>
    </div>

    @php $qid = $scene->config['qid'] ?? null; @endphp
    @if ($qid)
        <p class="text-[10px] text-slate-500">Territory: {{ $scene->location ?? $qid }} ({{ $qid }})</p>
    @else
        <p class="text-[10px] text-amber-400/70">No territory linked — pick a catalog topic in Step 1 for an accurate boundary.</p>
    @endif

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this map block?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">Delete block</button>
</div>
