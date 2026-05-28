@props(['scene' => null, 'games' => collect()])

@php
    $isGenerating = $scene->status === 'generating';
    $gameType = $scene->game_type ?? $scene->lesson->game_type ?? 'strategy';
    $strategyGameId = $scene->strategy_game_id ?? $scene->lesson->strategy_game_id;
    $teamCount = $scene->team_count ?? $scene->lesson->team_count ?? 2;
@endphp

<div class="space-y-3 text-sm">
    <header>
        <h3 class="text-amber-300 font-semibold">{{ ucfirst($gameType) }} Scene</h3>
        <p class="text-xs text-slate-400">Game element {{ $scene->game_segment_index ?? 1 }} of {{ $scene->lesson->game_split_count }}</p>
    </header>

    <div class="space-y-2">
        <p class="text-xs uppercase tracking-wider text-slate-400">Element type</p>
        <div class="join w-full">
            @foreach (['quiz' => 'Quiz', 'strategy' => 'Strategy', 'debate' => 'Debate'] as $val => $label)
                <button type="button"
                        wire:click="setSceneGameType({{ $scene->id }}, '{{ $val }}')"
                        @class([
                            'btn btn-sm join-item flex-1',
                            'btn-primary' => $gameType === $val,
                            'btn-outline' => $gameType !== $val,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($gameType === 'quiz')
        <div class="grid grid-cols-1 gap-3 border-t border-white/10 pt-3 sm:grid-cols-2">
            <label class="form-control">
                <span class="text-xs uppercase tracking-wider text-slate-400">Questions</span>
                <input type="number"
                       wire:model.live="selectedScene.quiz_question_count"
                       wire:change="saveSelected"
                       min="1" max="10"
                       class="input input-sm input-bordered bg-slate-900 mt-1" />
            </label>
            <label class="form-control">
                <span class="text-xs uppercase tracking-wider text-slate-400">Timing</span>
                <select wire:model.live="selectedScene.quiz_timing"
                        wire:change="saveSelected"
                        class="select select-sm select-bordered bg-slate-900 mt-1">
                    <option value="during">During lesson</option>
                    <option value="after">After lesson</option>
                    <option value="both">Both</option>
                </select>
            </label>
        </div>
    @endif

    @if ($gameType === 'strategy')
        <div class="grid grid-cols-1 gap-3 border-t border-white/10 pt-3 sm:grid-cols-2">
            <label class="form-control sm:col-span-2">
                <span class="text-xs uppercase tracking-wider text-slate-400">Strategy game</span>
                <select wire:model.live="selectedScene.strategy_game_id"
                        wire:change="saveSelected"
                        class="select select-sm select-bordered bg-slate-900 mt-1">
                    <option value="">— select a game —</option>
                    @foreach ($games as $game)
                        <option value="{{ $game->id }}" @selected((int) $strategyGameId === (int) $game->id)>{{ $game->title }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-control">
                <span class="text-xs uppercase tracking-wider text-slate-400">Teams</span>
                <input type="number"
                       wire:model.live="selectedScene.team_count"
                       wire:change="saveSelected"
                       min="1" max="8"
                       placeholder="{{ $teamCount }}"
                       class="input input-sm input-bordered bg-slate-900 mt-1" />
            </label>
        </div>
    @endif

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">
            @if ($gameType === 'quiz')
                Quiz setup
            @elseif ($gameType === 'debate')
                Debate prompt
            @else
                Segment intro
            @endif
        </p>
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

        <x-lesson.skybox-controls :scene="$scene" />
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
