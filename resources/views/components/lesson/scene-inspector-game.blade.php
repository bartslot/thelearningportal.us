@props(['scene' => null])

@php
    $isGenerating = $scene->status === 'generating';
@endphp

<div class="space-y-3 text-sm">
    <header>
        <h3 class="text-amber-300 font-semibold">{{ $scene->lesson->strategyGame?->title ?? 'Strategy Game' }}</h3>
        <p class="text-xs text-slate-400">Segment {{ $scene->game_segment_index }} of {{ $scene->lesson->game_split_count }}</p>
    </header>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Segment intro</p>
        <textarea wire:model.blur="selectedScene.script_segment" wire:change="saveSelected" rows="6"
                  class="textarea textarea-sm textarea-bordered bg-slate-900 w-full"></textarea>
        <div class="flex gap-2 flex-wrap">
            @if ($scene->hasFreshAudio() && ! $isGenerating)
                <button type="button" wire:click="playSelected"
                        class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 inline-flex items-center gap-1.5">
                    <x-icons.play class="w-3 h-3" />
                    <span>Play</span>
                </button>
            @else
                <button type="button"
                        wire:click="regenerate({{ $scene->id }}, 'audio')"
                        wire:loading.attr="disabled" wire:target="regenerate"
                        @disabled($isGenerating)
                        class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    @if ($isGenerating)
                        <x-icons.spinner class="w-3 h-3 animate-spin" />
                        <span>Re-narrating…</span>
                    @else
                        <x-icons.regenerate class="w-3 h-3" />
                        <span>Re-narrate</span>
                    @endif
                </button>
            @endif
        </div>
    </div>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Background image</p>
        <div class="flex items-center gap-3">
            @if ($scene->image_path)
                <img src="{{ asset('storage/' . $scene->image_path) }}" class="w-20 h-12 rounded object-cover" />
            @endif
            <button type="button"
                    wire:click="regenerate({{ $scene->id }}, 'image')"
                    wire:loading.attr="disabled" wire:target="regenerate"
                    @disabled($isGenerating)
                    class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                @if ($isGenerating)
                    <x-icons.spinner class="w-3 h-3 animate-spin" />
                    <span>Generating…</span>
                @else
                    <x-icons.regenerate class="w-3 h-3" />
                    <span>Regenerate</span>
                @endif
            </button>
        </div>
    </div>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Challenge duration (mm:ss)</span>
        <input type="text"
               x-data="{ v: '{{ sprintf('%d:%02d', intdiv((int) ($scene->duration_seconds ?? 0), 60), ((int) ($scene->duration_seconds ?? 0)) % 60) }}' }"
               x-model="v"
               @blur="
                   const [m, s] = v.split(':').map(Number)
                   $wire.set('selectedScene.duration_seconds', (m*60) + (s||0))
                   $wire.call('saveSelected')"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this game segment?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">Delete segment</button>
</div>
