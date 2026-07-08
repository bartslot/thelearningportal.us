@props(['scene' => null, 'games' => collect(), 'quizDraft' => [], 'quizErrors' => [], 'quizSaved' => false, 'quizDifficulty' => 2, 'quizScope' => 'taught', 'quizShuffle' => 'per_player'])

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

        {{-- Multiple-choice question editor. Questions are lesson-level (shared across quiz segments);
             each has up to four colour-coded options (A/B/C/D) with exactly one marked correct. This
             is the actual quiz content — the field below is only the spoken intro, not the questions. --}}
        @php $letters = ['A', 'B', 'C', 'D']; $palette = ['#e11d48', '#0284c7', '#d97706', '#059669']; @endphp
        <div class="space-y-3 border-t border-white/10 pt-3">
            <div class="flex items-center justify-between">
                <p class="text-xs uppercase tracking-wider text-slate-400">Quiz questions</p>
                <span class="text-[11px] text-slate-500">{{ count($quizDraft) }} total</span>
            </div>

            {{-- Difficulty (1-3 stars) + regenerate-all at that level. Difficulty applies to
                 every AI generation: per-question sparkles and the full regenerate. --}}
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] text-slate-400">{{ __('Difficulty') }}</span>
                    @foreach ([1 => __('Easy'), 2 => __('Medium'), 3 => __('Hard')] as $level => $label)
                        <button type="button" wire:click="setQuizDifficulty({{ $level }})"
                                class="transition {{ $quizDifficulty >= $level ? 'text-amber-400 hover:text-amber-300' : 'text-slate-600 hover:text-slate-400' }}"
                                title="{{ $label }}" aria-label="{{ __('Set difficulty to :label', ['label' => $label]) }}"
                                aria-pressed="{{ $quizDifficulty === $level ? 'true' : 'false' }}">
                            <x-icons.star />
                        </button>
                    @endforeach
                    <span class="text-[11px] text-slate-500 ml-1">{{ [1 => __('Easy'), 2 => __('Medium'), 3 => __('Hard')][$quizDifficulty] ?? __('Medium') }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] text-slate-400">{{ __('Scope') }}</span>
                    <div class="join">
                        <button type="button" wire:click="setQuizScope('taught')"
                                class="btn btn-xs join-item {{ $quizScope === 'taught' ? 'btn-warning text-slate-950' : 'btn-ghost border border-slate-600 text-slate-300' }}"
                                title="{{ __('Only test what the story has covered so far') }}">
                            {{ __('Taught so far') }}
                        </button>
                        <button type="button" wire:click="setQuizScope('full')"
                                class="btn btn-xs join-item {{ $quizScope === 'full' ? 'btn-warning text-slate-950' : 'btn-ghost border border-slate-600 text-slate-300' }}"
                                title="{{ __('Prior-knowledge check: questions may reach ahead of the story') }}">
                            {{ __('Whole story') }}
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] text-slate-400">{{ __('Shuffle') }}</span>
                    <select wire:change="setQuizShuffle($event.target.value)" class="select select-xs select-bordered bg-slate-900">
                        <option value="per_player" @selected($quizShuffle === 'per_player')>{{ __('Per player') }}</option>
                        <option value="once" @selected($quizShuffle === 'once')>{{ __('Same for class (digibord/paper)') }}</option>
                        <option value="off" @selected($quizShuffle === 'off')>{{ __('Off') }}</option>
                    </select>
                </div>
                <button type="button" wire:click="regenerateAllQuizQuestions"
                        wire:loading.attr="disabled" wire:target="regenerateAllQuizQuestions"
                        class="btn btn-xs btn-outline gap-1"
                        title="{{ __('Regenerate every question at the current difficulty') }}">
                    <span wire:loading.remove wire:target="regenerateAllQuizQuestions"><x-icons.ai-generate class="w-3.5 h-3.5" /></span>
                    <span wire:loading wire:target="regenerateAllQuizQuestions" class="loading loading-spinner loading-xs"></span>
                    {{ __('Regenerate all') }}
                </button>
            </div>

            @if ($quizScope === 'full')
                <div class="alert py-2 px-3 bg-amber-500/10 border border-amber-500/40 text-xs text-amber-200">
                    {{ __('Prior-knowledge mode: this quiz may ask about parts of the story the students haven\'t reached yet — great as a "what do you already know?" opener. Questions that reach ahead are marked, and students get gentle feedback instead of being graded on guesswork.') }}
                </div>
            @endif

            {{-- Two-up on the wide game workspace; stacks on small screens. --}}
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @forelse ($quizDraft as $i => $q)
                <div class="rounded-box border border-slate-700/70 bg-base-200/50 p-3 space-y-2"
                     wire:key="quiz-q-{{ $i }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="text-[11px] font-semibold text-amber-300">{{ __('Question') }} {{ $i + 1 }}</span>
                            @if (! empty($q['asks_ahead']))
                                <span class="badge badge-xs bg-amber-500/20 border-amber-500/50 text-amber-300 gap-1"
                                      title="{{ __('Tests material that comes later in the story — students will be guessing (that\'s the point of a prior-knowledge check).') }}">
                                    ⤳ {{ __('asks ahead') }}
                                </span>
                            @endif
                        </span>
                        <button type="button" wire:click="removeQuizQuestion({{ $i }})"
                                class="text-rose-300 hover:text-rose-200 text-[11px] underline">{{ __('Remove') }}</button>
                    </div>

                    {{-- Question + AI-generate: drafts the question WITH its linked correct answer
                         and three plausible distractors as one unit. --}}
                    <div class="relative">
                        <textarea wire:model.blur="quizDraft.{{ $i }}.question" rows="2" maxlength="140"
                                  placeholder="{{ __('Type a question, or let AI draft one…') }}"
                                  class="textarea textarea-sm textarea-bordered bg-slate-900 w-full pr-9"></textarea>
                        <button type="button" wire:click="generateQuizQuestion({{ $i }})"
                                wire:loading.attr="disabled" wire:target="generateQuizQuestion({{ $i }})"
                                class="absolute right-1.5 top-1.5 btn btn-xs btn-square btn-ghost text-amber-300 hover:text-amber-200"
                                title="{{ __('Generate question + answers with AI (replaces answers too)') }}"
                                aria-label="{{ __('Generate question with AI') }}">
                            <span wire:loading.remove wire:target="generateQuizQuestion({{ $i }})"><x-icons.ai-generate /></span>
                            <span wire:loading wire:target="generateQuizQuestion({{ $i }})" class="loading loading-spinner loading-xs"></span>
                        </button>
                    </div>

                    {{-- Answers: drag handle in front to reorder A/B/C/D (Sortable). The correct
                         answer is locked (generated with the question, green) but still reorderable;
                         distractors get their own AI-redraw button. --}}
                    <div class="space-y-1.5"
                         x-data
                         x-init="window.Sortable && Sortable.create($el, {
                             handle: '.quiz-drag-handle',
                             animation: 150,
                             onEnd: (evt) => {
                                 const { oldIndex, newIndex } = evt
                                 if (oldIndex === newIndex) return
                                 // Revert the DOM move — Livewire owns the order and re-renders it,
                                 // otherwise morph and Sortable fight over the same nodes.
                                 evt.from.insertBefore(evt.item, evt.from.children[oldIndex + (newIndex < oldIndex ? 1 : 0)] ?? null)
                                 $wire.moveQuizOption({{ $i }}, oldIndex, newIndex)
                             },
                         })">
                        @foreach ($letters as $oi => $letter)
                            @php $isCorrect = (int) ($q['correct_index'] ?? 0) === $oi; @endphp
                            <div class="flex items-center gap-1.5" wire:key="quiz-q-{{ $i }}-opt-{{ $oi }}">
                                <span class="quiz-drag-handle cursor-grab active:cursor-grabbing text-slate-500 hover:text-slate-300 shrink-0"
                                      title="{{ __('Drag to reorder') }}"><x-icons.reorder /></span>
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[11px] font-bold text-white"
                                      style="background-color: {{ $palette[$oi] }}">{{ $letter }}</span>

                                @if ($isCorrect)
                                    <input type="text" value="{{ $q['options'][$oi] ?? '' }}" readonly
                                           class="input input-xs input-bordered flex-1 bg-emerald-950/60 border-emerald-500/50 text-emerald-200 cursor-not-allowed"
                                           title="{{ __('Correct answer — generated with the question; regenerate the question to change it. Reordering is allowed.') }}" />
                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-emerald-500/20 text-emerald-400"
                                          title="{{ __('Correct answer (locked — linked to the question)') }}" aria-label="{{ __('Correct answer, locked') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                @else
                                    <input type="text" wire:model.blur="quizDraft.{{ $i }}.options.{{ $oi }}" maxlength="60"
                                           placeholder="{{ __('Answer') }} {{ $letter }}"
                                           class="input input-xs input-bordered bg-slate-900 flex-1" />
                                    <button type="button" wire:click="regenerateQuizOption({{ $i }}, {{ $oi }})"
                                            wire:loading.attr="disabled" wire:target="regenerateQuizOption({{ $i }}, {{ $oi }})"
                                            class="btn btn-xs btn-square btn-ghost text-slate-400 hover:text-amber-300"
                                            title="{{ __('Redraw this wrong answer with AI') }}"
                                            aria-label="{{ __('Redraw answer :letter with AI', ['letter' => $letter]) }}">
                                        <span wire:loading.remove wire:target="regenerateQuizOption({{ $i }}, {{ $oi }})"><x-icons.ai-generate /></span>
                                        <span wire:loading wire:target="regenerateQuizOption({{ $i }}, {{ $oi }})" class="loading loading-spinner loading-xs"></span>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if (! empty($quizErrors[$i]))
                        <ul class="text-[11px] text-rose-300 list-disc list-inside space-y-0.5">
                            @foreach ($quizErrors[$i] as $err)<li>{{ $err }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="text-xs text-slate-500 md:col-span-2">{{ __('No questions yet — add one below and hit the sparkle to draft it.') }}</p>
            @endforelse
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="addQuizQuestion" class="btn btn-xs btn-outline">+ Add question</button>
                {{-- Edits autosave — no manual save button. --}}
                <span wire:loading wire:target="autosaveQuiz, updatedQuizDraft, removeQuizQuestion, moveQuizOption"
                      class="text-[11px] text-slate-400 inline-flex items-center gap-1">
                    <span class="loading loading-spinner loading-xs"></span> {{ __('Saving…') }}
                </span>
                @if ($quizSaved)
                    <span wire:loading.remove wire:target="autosaveQuiz, updatedQuizDraft"
                          class="text-[11px] text-emerald-400">✓ {{ __('Saved automatically') }}</span>
                @endif
            </div>
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
                Spoken intro <span class="normal-case text-slate-500">(optional — narrated before the quiz)</span>
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
        {{-- Image thumbnail, regenerate/delete, and motion controls all live in the shared
             skybox-controls slideshow tab — the old duplicate block here showed a second
             Regenerate button and confused teachers. --}}
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
