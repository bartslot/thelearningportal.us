<div class="mx-auto max-w-5xl px-4 py-8 space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold">{{ $lesson->title ?? $lesson->topic }} — {{ __('Results') }}</h1>
            <p class="text-sm opacity-60">{{ __('Lesson code') }} {{ $lesson->lesson_code }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($this->classrooms->isNotEmpty())
                <select wire:model.live="classroomId" class="select select-sm select-bordered">
                    <option value="">{{ __('All classes') }}</option>
                    @foreach ($this->classrooms as $classroom)
                        <option value="{{ $classroom->id }}">{{ $classroom->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="range" class="select select-sm select-bordered">
                <option value="7">{{ __('Last 7 days') }}</option>
                <option value="30">{{ __('Last 30 days') }}</option>
                <option value="90">{{ __('Last 90 days') }}</option>
                <option value="all">{{ __('All time') }}</option>
            </select>
            <button wire:click="exportCsv" class="btn btn-sm btn-outline">⬇ CSV</button>
            <a href="{{ route('teacher.lessons.answer-sheet', $lesson) }}" target="_blank" class="btn btn-sm btn-outline">🖨 {{ __('Answer sheets') }}</a>
            <a href="{{ route('teacher.lessons.print.handout', $lesson) }}" target="_blank" class="btn btn-sm btn-outline">📄 {{ __('Print handout') }}</a>
            <label for="paper-import" class="btn btn-sm btn-warning">📷 {{ __('Import paper answers') }}</label>
        </div>
    </div>

    <div role="tablist" class="tabs tabs-bordered">
        @foreach (['overview' => __('Overview'), 'questions' => __('Questions'), 'players' => __('Players')] as $key => $label)
            <button role="tab" wire:click="$set('tab', '{{ $key }}')"
                    class="tab {{ $tab === $key ? 'tab-active font-semibold' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        @php $o = $this->overview; @endphp
        <div class="grid grid-cols-3 gap-3">
            <div class="card bg-base-200 p-4 text-center"><span class="text-3xl font-extrabold">{{ $o['players'] }}</span><span class="text-xs opacity-60">{{ __('players') }}</span></div>
            <div class="card bg-base-200 p-4 text-center"><span class="text-3xl font-extrabold">{{ $o['avg_correct_pct'] }}%</span><span class="text-xs opacity-60">{{ __('avg correct') }}</span></div>
            <div class="card bg-base-200 p-4 text-center {{ count($o['needs_help']) ? 'border border-error/40' : '' }}">
                <span class="text-3xl font-extrabold {{ count($o['needs_help']) ? 'text-error' : '' }}">{{ count($o['needs_help']) }}</span>
                <span class="text-xs opacity-60">{{ __('need help') }}</span>
            </div>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider opacity-60">{{ __('Leaderboard') }}</h2>
                <div class="space-y-1.5">
                    @forelse ($o['leaderboard'] as $i => $row)
                        <div class="flex items-center gap-2 rounded-xl border px-3 py-2
                                    {{ $row['needs_help'] ? 'border-error/40' : 'border-base-300' }}">
                            <span class="w-6 text-sm font-bold opacity-60">{{ $i + 1 }}</span>
                            <span class="flex-1 truncate font-medium">{{ $row['name'] }}</span>
                            <x-results.integrity-chips :integrity="$row['integrity']" :source="$row['source']" />
                            <span class="font-bold text-warning">{{ $row['score'] }}</span>
                            <span class="text-xs opacity-60">{{ $row['correct'] }}/{{ $row['total'] }}</span>
                        </div>
                    @empty
                        <p class="text-sm opacity-60">{{ __('No plays yet — share the lesson link or import paper sheets.') }}</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider opacity-60">{{ __('Difficult questions') }}</h2>
                @php $difficult = collect($this->difficult); @endphp
                <div class="space-y-2">
                    @forelse ($difficult as $q)
                        <div class="rounded-xl border border-base-300 p-3">
                            <p class="text-sm">{{ $q['asks_ahead'] ? '⤳ ' : '' }}{{ $q['question_text'] }}</p>
                            <progress class="progress {{ $q['correct_pct'] < 35 ? 'progress-error' : 'progress-warning' }} w-full"
                                      value="{{ $q['correct_pct'] }}" max="100"></progress>
                            <span class="text-xs {{ $q['correct_pct'] < 35 ? 'text-error' : 'text-warning' }}">{{ $q['correct_pct'] }}% {{ __('correct') }}</span>
                        </div>
                    @empty
                        <p class="text-sm opacity-60">{{ __('No difficult questions — nice!') }}</p>
                    @endforelse
                </div>
                @if ($difficult->isNotEmpty())
                    <button wire:click="requiz" class="btn btn-sm btn-outline mt-3">↻ {{ __('Re-quiz these questions') }}</button>
                @endif
            </div>
        </div>
    @elseif ($tab === 'questions')
        <div class="space-y-2">
            @forelse ($this->questionBreakdown as $q)
                <details class="rounded-xl border border-base-300 p-3">
                    <summary class="cursor-pointer text-sm">
                        {{ $q['asks_ahead'] ? '⤳ ' : '' }}{{ $q['question_text'] }}
                        <span class="{{ $q['correct_pct'] < 35 ? 'text-error' : ($q['correct_pct'] < 50 ? 'text-warning' : 'text-success') }} font-semibold">
                            {{ $q['correct_pct'] }}%
                        </span>
                    </summary>
                    <div class="mt-2 space-y-1 text-sm">
                        @foreach ($q['distribution'] as $option => $count)
                            <div class="flex items-center gap-2">
                                <span class="{{ $option === $q['correct_text'] ? 'text-success font-semibold' : '' }} flex-1 truncate">{{ $option }}</span>
                                <span class="opacity-60">{{ $count }}×</span>
                            </div>
                        @endforeach
                        @if ($q['missed_by'])
                            <p class="text-xs opacity-60">{{ __('Missed by') }}: {{ implode(', ', $q['missed_by']) }}</p>
                        @endif
                    </div>
                </details>
            @empty
                <p class="text-sm opacity-60">{{ __('No answers recorded yet.') }}</p>
            @endforelse
        </div>
    @else
        <div class="space-y-1.5">
            @forelse ($this->players as $row)
                <div class="rounded-xl border {{ $row['needs_help'] ? 'border-error/40' : 'border-base-300' }} px-3 py-2">
                    <button wire:click="openPlayer({{ $row['score_id'] }})" class="flex w-full items-center gap-2 text-left">
                        <span class="flex-1 font-medium">{{ $row['name'] }}</span>
                        <x-results.integrity-chips :integrity="$row['integrity']" :source="$row['source']" />
                        <span class="text-sm">{{ $row['pct'] }}%</span>
                        <span class="text-xs opacity-60">{{ $row['played_at']->format('d M H:i') }}</span>
                    </button>
                    @if ($openScoreId === $row['score_id'])
                        <div class="mt-2 space-y-1 border-t border-base-300 pt-2 text-sm">
                            @foreach ($this->drilldown as $a)
                                <div class="flex items-start gap-2">
                                    <span>{{ $a['was_correct'] ? '✅' : '❌' }}</span>
                                    <span class="flex-1">{{ $a['asks_ahead'] ? '⤳ ' : '' }}{{ $a['question_text'] }}
                                        <span class="opacity-60">— {{ $a['chosen_text'] }}@if(!$a['was_correct']) ({{ __('correct') }}: {{ $a['correct_text'] }})@endif</span>
                                    </span>
                                    @if ($a['response_ms'] !== null)<span class="text-xs opacity-50">{{ round($a['response_ms'] / 1000, 1) }}s</span>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm opacity-60">{{ __('No players yet.') }}</p>
            @endforelse
        </div>
    @endif

    {{-- Paper import: upload photos of filled answer sheets → review grid → confirm. --}}
    <div class="modal {{ $paperModalOpen || $paperPhotos ? 'modal-open' : '' }}">
        <div class="modal-box max-w-3xl">
            <h3 class="mb-3 text-lg font-semibold">📷 {{ __('Import paper answers') }}</h3>
            <input id="paper-import" type="file" wire:model="paperPhotos" multiple accept="image/*" class="file-input file-input-bordered w-full" />
            <button wire:click="extractPaper" wire:loading.attr="disabled" class="btn btn-sm btn-warning mt-2">
                <span wire:loading wire:target="extractPaper" class="loading loading-spinner loading-xs"></span>
                {{ __('Read sheets') }}
            </button>

            @if ($paperRows)
                <div class="mt-4 space-y-1.5">
                    @foreach ($paperRows as $i => $row)
                        <div class="flex items-center gap-2 rounded-xl border px-3 py-2
                                    {{ $row['matched_name'] ? 'border-success/40' : 'border-warning/50' }}">
                            <span>{{ $row['matched_name'] ? '✓' : '?' }}</span>
                            <input type="text" wire:model.blur="paperRows.{{ $i }}.matched_name"
                                   placeholder="{{ $row['raw_name'] ?: __('Name…') }}"
                                   class="input input-xs input-bordered w-36" />
                            <div class="flex flex-1 gap-1 font-mono">
                                @foreach ($row['answers'] as $qi => $answer)
                                    <select wire:model.blur="paperRows.{{ $i }}.answers.{{ $qi }}"
                                            class="select select-xs {{ $answer === null ? 'select-warning' : 'select-bordered' }}">
                                        <option value="">—</option>
                                        @foreach (['A','B','C','D'] as $letter)
                                            <option value="{{ $letter }}" @selected($answer === $letter)>{{ $letter }}</option>
                                        @endforeach
                                    </select>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <button wire:click="confirmPaperImport" wire:loading.attr="disabled" wire:target="confirmPaperImport" class="btn btn-warning mt-4">
                    <span wire:loading wire:target="confirmPaperImport" class="loading loading-spinner loading-xs"></span>
                    {{ __('Confirm & import :n sheets', ['n' => count($paperRows)]) }}
                </button>
            @endif
            <div class="modal-action">
                <button wire:click="$set('paperModalOpen', false)" class="btn btn-ghost btn-sm">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>
