@php use App\Enums\NarrativeFramework; @endphp
<div class="space-y-4 pt-6">

    {{-- ═══════════════════════════════════ STORY ARC ═══════════════════════════════════ --}}
    <div class="bg-base-300 rounded-2xl p-6 space-y-4">
        <span class="label-text text-xs uppercase tracking-wider text-slate-400">
            Choose your story arc
        </span>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            @foreach ($this->frameworks as $fw)
                <button type="button"
                        wire:click="selectFramework('{{ $fw->value }}')"
                        @class([
                            'flex flex-col items-start gap-1.5 rounded-xl border-2 p-3.5 text-left transition-all',
                            'border-amber-400 bg-amber-500/10' => $narrative_framework === $fw->value,
                            'border-slate-600 hover:border-slate-400' => $narrative_framework !== $fw->value,
                        ])>
                    <i class="ti {{ $fw->icon() }} text-xl @if($narrative_framework === $fw->value) text-amber-300 @else text-slate-300 @endif"></i>
                    <span class="text-sm font-medium text-white">{{ $fw->label() }}</span>
                    <span class="text-xs text-slate-400 leading-snug">{{ $fw->description() }}</span>
                    @if ($fw === NarrativeFramework::default())
                        <span class="mt-0.5 text-[10px] uppercase tracking-wide text-amber-300/70">default</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    {{-- ═══════════════════════════════════ HERO PICKER ═════════════════════════════════ --}}
    @if ($this->framework()->needsHero())
        <div class="bg-base-300 rounded-2xl p-6 space-y-3">
            <div class="flex items-baseline justify-between">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Choose your hero</span>
                <span class="text-xs text-slate-500">from the corpus · rulers first</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @forelse ($this->heroes as $hero)
                    <button type="button"
                            wire:click="selectHero('{{ $hero->qid }}')"
                            wire:key="hero-{{ $hero->qid }}"
                            @class([
                                'flex items-center gap-3 rounded-lg border-2 p-2.5 text-left transition-all',
                                'border-amber-400 bg-amber-500/10' => $protagonist_qid === $hero->qid,
                                'border-slate-600 hover:border-slate-400' => $protagonist_qid !== $hero->qid,
                            ])>
                        @if ($hero->image_url)
                            <img src="{{ $hero->image_url }}?width=96" alt=""
                                 class="h-11 w-11 shrink-0 rounded-full object-cover bg-slate-700"
                                 loading="lazy"
                                 onerror="this.style.display='none'" />
                        @else
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-700">
                                <i class="ti ti-user text-slate-400"></i>
                            </span>
                        @endif
                        <span class="flex flex-col min-w-0">
                            <span class="text-sm font-medium text-white truncate">{{ $hero->name }}</span>
                            <span class="text-xs text-slate-400">
                                @if ($hero->figure_kind === 'ruler')<span class="text-amber-300/80">Ruler</span> · @endif
                                {{ $hero->era_start ? ($hero->era_start < 0 ? abs($hero->era_start).' BCE' : $hero->era_start.' CE') : '' }}
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="text-sm text-slate-400 col-span-full py-1">
                        No catalog figures for this topic yet — type your hero's name below.
                    </p>
                @endforelse
            </div>

            <div class="pt-1">
                <input type="text" wire:model.blur="protagonist_name"
                       placeholder="…or type a hero's name"
                       class="input input-bordered input-sm bg-slate-900 w-full" />
                @if ($protagonist_name && ! $protagonist_qid)
                    <span class="text-xs text-emerald-400/80 mt-1 inline-block">Using "{{ $protagonist_name }}".</span>
                @endif
            </div>
        </div>
    @elseif ($narrative_framework === 'branching')
        <div class="bg-base-300/60 rounded-2xl px-6 py-4">
            <p class="text-sm text-slate-300 flex items-center gap-2">
                <i class="ti ti-git-branch text-amber-300"></i>
                The AI adds one choice point mid-lesson — you can edit both paths in Configure.
            </p>
        </div>
    @endif

    {{-- ═══════════════════════════════════ GAME ════════════════════════════════════════ --}}
    <div class="bg-base-300 rounded-2xl overflow-hidden"
         x-data="{ open: @js((bool) $include_game) }"
         x-on:livewire-update.window="open = $wire.include_game">
        <div class="flex items-center justify-between px-4 py-3 cursor-pointer select-none"
             x-on:click="if($wire.include_game){ open = !open }">
            <span class="font-medium text-white text-sm">
                Game
                @if ($include_game && $this->framework()->defaultGameType())
                    <span class="ml-2 text-xs font-normal text-slate-500">paired with {{ $this->framework()->label() }}</span>
                @endif
            </span>
            <div class="flex items-center gap-3">
                <span class="text-slate-400 text-xs">
                    @if ($include_game && $game_type)
                        {{ ['quiz' => 'Quiz', 'strategy' => 'Strategy game', 'debate' => 'Debate'][$game_type] ?? $game_type }}
                    @elseif (! $include_game)
                        Off
                    @endif
                </span>
                <input type="checkbox" wire:model.live="include_game"
                       x-on:click.stop x-on:change="open = $event.target.checked"
                       class="toggle toggle-primary toggle-sm" />
            </div>
        </div>

        <div x-show="open" x-collapse class="px-4 pb-4 space-y-4 border-t border-white/10 pt-3">
            @if ($include_game)
                <div class="space-y-2">
                    <p class="text-xs uppercase tracking-wider text-slate-400">Game type</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (['quiz' => 'Quiz', 'strategy' => 'Strategy game', 'debate' => 'Debate'] as $val => $label)
                            <button type="button" wire:click="$set('game_type', '{{ $val }}')"
                                    @class([
                                        'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                                        'border-amber-400 bg-amber-500/10 text-white' => $game_type === $val,
                                        'border-slate-600 text-slate-300 hover:border-slate-400' => $game_type !== $val,
                                    ])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($game_type === 'quiz')
                    <div class="border-t border-white/10 pt-4 flex flex-col sm:flex-row gap-6">
                        <div class="w-full sm:w-1/3 space-y-2 shrink-0">
                            <p class="text-xs uppercase tracking-wider text-slate-400">Number of questions</p>
                            <input type="number" wire:model.live="quiz_question_count" min="1" max="10"
                                   class="input input-bordered bg-slate-900 w-24 text-center" />
                        </div>
                        <div class="flex-1 space-y-2">
                            <p class="text-xs uppercase tracking-wider text-slate-400">When to ask</p>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach (['during' => 'During', 'after' => 'After', 'both' => 'Both'] as $val => $label)
                                    <button type="button" wire:click="$set('quiz_timing', '{{ $val }}')"
                                            @class([
                                                'px-3 py-2 rounded-lg text-sm border-2 transition-all text-center',
                                                'border-amber-400 bg-amber-500/10 text-white' => $quiz_timing === $val,
                                                'border-slate-600 text-slate-300 hover:border-slate-400' => $quiz_timing !== $val,
                                            ])>{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                @if ($game_type === 'strategy')
                    <div class="border-t border-white/10 pt-4">
                        <x-wizard.game-picker :games="$this->games" :selected-id="$strategy_game_id"
                                              :team-count="$team_count" :split-count="$game_split_count" />
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════ FOOTER ══════════════════════════════════════ --}}
    <div class="flex items-center justify-between pt-2">
        <button type="button" wire:click="back" class="btn btn-sm btn-ghost text-slate-300">← Back</button>
        <button type="button" wire:click="generate" class="btn btn-primary">
            Generate lesson <i class="ti ti-arrow-right"></i>
        </button>
    </div>
</div>
