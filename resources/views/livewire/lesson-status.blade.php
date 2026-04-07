<div
    @if($this->shouldPoll) wire:poll.3s="refresh" @endif
    class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-5"
>
    {{-- Header --}}
    <div class="flex items-center justify-between gap-4">
        <h2 class="text-sm font-semibold text-slate-100 uppercase tracking-widest">Generation pipeline</h2>
        <span class="text-xs text-slate-400">{{ $this->completedCount }}/6 steps</span>
    </div>

    {{-- Progress bar --}}
    <div class="h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
        <div
            class="h-full rounded-full transition-all duration-700 ease-out
                {{ $lesson->status === \App\Enums\LessonStatus::Failed ? 'bg-rose-500' : 'bg-amber-400' }}"
            style="width: {{ round(($this->completedCount / 6) * 100) }}%"
        ></div>
    </div>

    {{-- Steps --}}
    <ol class="space-y-3">
        @foreach($this->steps as $step)
            <li class="flex items-start gap-3">

                {{-- Icon / retry button --}}
                <div class="mt-0.5 flex-shrink-0">
                    @if($step['state'] === 'done')
                        {{-- Green check --}}
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500/20 border border-emerald-600">
                            <svg class="h-3.5 w-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </span>

                    @elseif($step['state'] === 'active')
                        {{-- Spinning amber --}}
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-500/20 border border-amber-500">
                            <svg class="h-3.5 w-3.5 text-amber-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </span>

                    @elseif($step['state'] === 'failed')
                        {{-- Red X --}}
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-rose-500/20 border border-rose-600">
                            <svg class="h-3.5 w-3.5 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </span>

                    @elseif($step['state'] === 'skipped')
                        @if($step['canRetry'] && ! $lesson->isGenerating())
                            {{-- Amber retry button — clickable --}}
                            <button
                                wire:click="runStep('{{ $step['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="runStep('{{ $step['key'] }}')"
                                title="Run this step now"
                                class="group flex h-6 w-6 items-center justify-center rounded-full
                                       border border-amber-700/60 bg-amber-950/40
                                       hover:border-amber-500 hover:bg-amber-500/20
                                       transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1 focus:ring-offset-slate-900
                                       disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                {{-- Retry icon (loading state) --}}
                                <svg
                                    wire:loading wire:target="runStep('{{ $step['key'] }}')"
                                    class="h-3 w-3 text-amber-400 animate-spin"
                                    fill="none" viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                {{-- Retry icon (idle state) --}}
                                <svg
                                    wire:loading.remove wire:target="runStep('{{ $step['key'] }}')"
                                    class="h-3 w-3 text-amber-600 group-hover:text-amber-400 transition-colors"
                                    viewBox="0 0 100 100" fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path d="m49.602 87v7.6016c0 2.1992 2.3984 3.6016 4.3008 2.3984l20.5-12.801c1.8008-1.1016 1.8008-3.6992 0-4.8008l-20.402-12.699c-1.8984-1.1992-4.3008 0.19922-4.3008 2.3984v7.6016h-0.19922c-17.102-0.30078-31.199-14.5-31.301-31.602-0.19922-18.5 15.398-33.398 34.102-32.102 15.398 1 28.102 13.301 29.602 28.699 0.80078 8.1016-1.3984 15.602-5.6992 21.602-1 1.3984-0.60156 3.3008 0.89844 4.1992l3.8984 2.3008c1.3008 0.80078 3 0.39844 3.8984-0.80078 4.8008-6.8008 7.6016-15.102 7.6016-24.102 0-24.398-20.699-44-45.5-42.199-20.898 1.5039-37.602 18.203-39.199 38.805-1.8008 24.699 17.598 45.301 41.801 45.5z"/>
                                </svg>
                            </button>
                        @else
                            {{-- Slate dash — skipped, not retryable --}}
                            <span class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-700 bg-slate-800/60">
                                <svg class="h-3 w-3 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                                </svg>
                            </span>
                        @endif

                    @else
                        {{-- Gray pending --}}
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-600"></span>
                        </span>
                    @endif
                </div>

                {{-- Text --}}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium
                        {{ $step['state'] === 'done'    ? 'text-emerald-300' : '' }}
                        {{ $step['state'] === 'active'  ? 'text-amber-300'   : '' }}
                        {{ $step['state'] === 'failed'  ? 'text-rose-300'    : '' }}
                        {{ $step['state'] === 'skipped' ? 'text-slate-500'   : '' }}
                        {{ $step['state'] === 'pending' ? 'text-slate-400'   : '' }}
                    ">{{ $step['label'] }}</p>
                    @if($step['state'] === 'skipped' && ! empty($step['skipReason']))
                        <p class="text-xs text-slate-500 italic">{{ $step['skipReason'] }}</p>
                    @elseif($step['state'] === 'active')
                        <p class="text-xs text-amber-400/70"
                           x-data="{ elapsed: 0, timer: null }"
                           x-init="timer = setInterval(() => elapsed++, 1000)"
                           x-effect="if (!$el.closest('[wire\\:poll]')) { clearInterval(timer) }"
                        >
                            {{ $step['description'] }} —
                            <span x-text="Math.floor(elapsed / 60) > 0 ? Math.floor(elapsed / 60) + 'm ' + (elapsed % 60) + 's' : elapsed + 's'"></span>
                        </p>
                    @else
                        <p class="text-xs text-slate-400">{{ $step['description'] }}</p>
                    @endif
                </div>

            </li>
        @endforeach
    </ol>

    {{-- Status footer --}}
    @if($lesson->isGenerating())
        <p class="text-xs text-slate-400 border-t border-slate-800 pt-4"
           x-data="{ elapsed: 0 }"
           x-init="setInterval(() => elapsed++, 1000)"
        >
            ⏱ Running for <span x-text="Math.floor(elapsed / 60) > 0 ? Math.floor(elapsed / 60) + 'm ' + (elapsed % 60) + 's' : elapsed + 's'"></span> — average generation time is 2–5 minutes.
        </p>
    @elseif($lesson->status === \App\Enums\LessonStatus::Failed)
        <div class="rounded-xl border border-rose-800 bg-rose-950/40 px-4 py-3 text-xs text-rose-300 border-t border-slate-800 mt-4">
            <p class="font-semibold mb-1">Generation failed</p>
            @if($lesson->error_message)
                <p class="text-rose-400/80 font-mono">{{ $lesson->error_message }}</p>
            @endif
        </div>
    @elseif($this->allStepsComplete)
        <p class="text-xs text-emerald-400 border-t border-slate-800 pt-4">
            ✓ All steps complete — lesson is ready to review and publish.
        </p>
    @elseif($lesson->status === \App\Enums\LessonStatus::Ready)
        <div class="rounded-xl border border-amber-800 bg-amber-950/40 px-4 py-3 text-xs text-amber-300 border-t border-slate-800 mt-4">
            <p class="font-semibold mb-1">Lesson marked ready, but steps are still missing</p>
            <p class="text-amber-200/80">The pipeline should be regenerated so the missing quiz, portrait, audio, or video artifacts are created.</p>
        </div>
    @endif
</div>
