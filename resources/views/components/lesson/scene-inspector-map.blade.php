@props(['scene' => null, 'territoryResults' => null, 'territoryQuery' => ''])

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
        <input type="number" wire:model.blur.number="selectedScene.config.year" wire:change="saveSelected"
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

    {{-- Focus cities — teacher drops a red dot + label on the map (annotations[].type === 'focus'). --}}
    <div class="form-control border-t border-slate-700/50 pt-3">
        <span class="text-xs uppercase tracking-wider text-slate-400">Focus cities</span>
        <button type="button"
                onclick="window.dispatchEvent(new CustomEvent('lessonmap:add-focus'))"
                class="btn btn-sm btn-outline btn-block mt-1">+ Add focus city (then click the map)</button>

        @php $annotations = $scene->config['annotations'] ?? []; @endphp
        @if (count($annotations))
            <ul class="mt-2 space-y-1.5">
                @foreach ($annotations as $i => $a)
                    @if (($a['type'] ?? null) === 'focus')
                        <li class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:#c0392b;border:2px solid #fff;"></span>
                            <input type="text"
                                   value="{{ $a['label'] ?? '' }}"
                                   wire:change="renameFocus({{ $i }}, $event.target.value)"
                                   placeholder="Place name"
                                   class="input input-xs input-bordered bg-slate-900 flex-1" />
                            <button type="button" wire:click="removeFocus({{ $i }})"
                                    class="shrink-0 text-rose-300 hover:text-rose-200 text-xs px-1"
                                    title="Remove focus city" aria-label="Remove focus city">✕</button>
                        </li>
                    @endif
                @endforeach
            </ul>
        @else
            <p class="mt-1 text-[10px] text-slate-500">No focus cities yet — add one to mark a key place on the map.</p>
        @endif
    </div>

    {{-- Territory picker — link a polity's Wikidata QID for an accurate red boundary. --}}
    @php $qid = $scene->config['qid'] ?? null; @endphp
    <div class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Territory</span>

        @if ($qid)
            <div class="mt-1 flex items-center justify-between gap-2 rounded-lg border border-emerald-700/40 bg-emerald-950/30 px-2.5 py-1.5">
                <div class="min-w-0">
                    <p class="truncate text-sm text-emerald-200">{{ $scene->location ?? $qid }}</p>
                    <p class="text-[10px] text-slate-500">{{ $qid }} · red boundary, fit at the chosen year</p>
                </div>
                <button type="button" wire:click="unlinkTerritory"
                        class="shrink-0 text-[11px] text-rose-300 underline hover:text-rose-200">Change</button>
            </div>
        @else
            <input type="search" wire:model.live.debounce.400ms="territoryQuery"
                   placeholder="Search an empire/kingdom — e.g. Byzantine Empire"
                   class="input input-sm input-bordered bg-slate-900 mt-1" />
            <p class="mt-1 text-[10px] text-amber-400/70">No territory linked — search a polity (cities: link the empire that ruled it).</p>

            @if (filled($territoryQuery) && $territoryResults && $territoryResults->isNotEmpty())
                <ul class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-700/60 divide-y divide-slate-800 bg-slate-900/95">
                    @foreach ($territoryResults as $t)
                        <li>
                            <button type="button" wire:click="linkTerritory('{{ $t->qid }}')"
                                    class="flex w-full items-center justify-between gap-2 px-2.5 py-1.5 text-left hover:bg-slate-800/70">
                                <span class="truncate text-sm text-slate-200">{{ $t->name }}</span>
                                <span class="shrink-0 text-[10px] text-slate-500">{{ $t->region_label ?: ($t->era_start !== null ? ($t->era_start < 0 ? abs($t->era_start).' BCE' : $t->era_start) : '') }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (filled($territoryQuery))
                <p class="mt-1 text-[10px] text-slate-500">No polity matches “{{ $territoryQuery }}”.</p>
            @endif
        @endif
    </div>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this map block?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">Delete block</button>
</div>
