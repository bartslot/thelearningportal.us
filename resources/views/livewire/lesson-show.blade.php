<div
    class="space-y-8"
>
    {{-- ── Breadcrumb ─────────────────────────────────────────────────────── --}}
    <nav class="flex items-center gap-2 text-xs text-slate-400">
        <a href="{{ route('teacher.dashboard') }}" class="hover:text-amber-400 transition-colors">Dashboard</a>
        <span>/</span>
        <span class="text-slate-400">{{ $lesson->title }}</span>
    </nav>

    {{-- ── Page header ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="text-xs uppercase tracking-widest
                    @if($lesson->status === \App\Enums\LessonStatus::Published) text-indigo-400
                    @elseif($lesson->status === \App\Enums\LessonStatus::Ready) text-emerald-400
                    @elseif($lesson->status === \App\Enums\LessonStatus::Generating) text-amber-400
                    @elseif($lesson->status === \App\Enums\LessonStatus::Failed) text-rose-400
                    @else text-slate-400
                    @endif
                ">{{ $lesson->status->label() }}</span>
                <span class="text-slate-700">·</span>
                <span class="text-xs text-slate-400 capitalize">{{ $lesson->subject }}</span>
                <span class="text-slate-700">·</span>
                <span class="text-xs text-slate-400">{{ $lesson->grade_level }}</span>
            </div>
            <h1 class="text-3xl font-semibold text-slate-100">{{ $lesson->title }}</h1>
            @if($lesson->historical_figure)
                <p class="mt-1 text-sm text-slate-400">Narrated by <span class="text-amber-400">{{ $lesson->historical_figure }}</span></p>
            @endif
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3 flex-shrink-0">
            @if($lesson->isReady() && $lesson->status !== \App\Enums\LessonStatus::Published)
                <form method="POST" action="{{ route('teacher.lessons.publish', $lesson) }}">
                    @csrf @method('PATCH')
                    <button type="submit"
                            class="rounded-xl bg-emerald-500 px-5 py-2.5 text-sm font-semibold text-slate-950 hover:bg-emerald-400 transition-colors">
                        Publish lesson
                    </button>
                </form>
            @endif

            @if($lesson->status === \App\Enums\LessonStatus::Published)
                <span class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-500/10 border border-indigo-600/50 px-4 py-2.5 text-sm font-medium text-indigo-300">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Published
                </span>
            @endif

            @if(app()->environment(['local', 'testing']) && $lesson->status === \App\Enums\LessonStatus::Failed)
                <form method="POST" action="{{ route('teacher.lessons.retry', $lesson) }}">
                    @csrf
                    <button type="submit"
                            class="rounded-xl border border-amber-600 px-5 py-2.5 text-sm font-semibold text-amber-400 hover:bg-amber-500/10 transition-colors">
                        Retry generation
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- ── Tabs ────────────────────────────────────────────────────────────── --}}
    <div x-data="{ tab: 'overview' }">

        <div class="flex items-center gap-0 border-b border-slate-800 mb-8">
            <button
                @click="tab = 'overview'"
                :class="tab === 'overview'
                    ? 'text-slate-100 border-b-2 border-amber-400'
                    : 'text-slate-400 hover:text-slate-300 border-b-2 border-transparent'"
                class="px-4 pb-3 text-sm font-medium transition-colors"
            >
                Overview
            </button>
            <button
                @click="tab = 'settings'"
                :class="tab === 'settings'
                    ? 'text-slate-100 border-b-2 border-amber-400'
                    : 'text-slate-400 hover:text-slate-300 border-b-2 border-transparent'"
                class="px-4 pb-3 text-sm font-medium transition-colors"
            >
                Settings
            </button>
        </div>

        {{-- ── Overview tab ─────────────────────────────────────────────────── --}}
        <div x-show="tab === 'overview'" x-cloak>
            <div class="grid gap-8 lg:grid-cols-[1.4fr_1fr]">

                {{-- Left column --}}
                <div class="space-y-6">

                    {{-- ── Lesson player (slideshow background + avatar overlay) ────── --}}
                    <x-lesson-player :lesson="$lesson" />

                    {{-- Script preview (collapsible) --}}
                    @if($lesson->script)
                        <details class="rounded-2xl border border-slate-800 bg-slate-900/60 group">
                            <summary class="cursor-pointer px-6 py-4 text-sm font-medium text-slate-300
                                            hover:text-amber-400 transition-colors list-none flex items-center justify-between">
                                <span>Lesson script</span>
                                <svg class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </summary>
                            <div class="px-6 pb-6 pt-0">
                                <div class="rounded-xl bg-slate-950/60 p-4 text-sm text-slate-300 leading-7 whitespace-pre-wrap font-mono max-h-64 overflow-y-auto">
                                    {{ $lesson->script }}
                                </div>
                            </div>
                        </details>
                    @endif

                    {{-- Quiz questions preview --}}
                    @if($lesson->quizQuestions->isNotEmpty())
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-4">
                            <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-widest">
                                Quiz — {{ $lesson->quizQuestions->count() }} questions
                            </h2>

                            @foreach($lesson->quizQuestions as $question)
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                                    <p class="text-sm font-medium text-slate-200">
                                        {{ $loop->iteration }}. {{ $question->question }}
                                    </p>
                                    <ul class="space-y-1.5">
                                        @foreach($question->options as $index => $option)
                                            <li class="flex items-center gap-2.5 text-sm rounded-lg px-3 py-2
                                                @if($index === $question->correct_index)
                                                    bg-emerald-950/40 border border-emerald-800/50 text-emerald-300
                                                @else
                                                    text-slate-400
                                                @endif
                                            ">
                                                @if($index === $question->correct_index)
                                                    <svg class="h-3.5 w-3.5 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                    </svg>
                                                @else
                                                    <span class="h-3.5 w-3.5 flex-shrink-0 text-center text-xs text-slate-400">{{ chr(65 + $index) }}</span>
                                                @endif
                                                {{ $option }}
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if($question->explanation)
                                        <p class="text-xs text-slate-400 italic border-t border-slate-800 pt-2">
                                            {{ $question->explanation }}
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                </div>

                {{-- Right column: pipeline status + lesson metadata --}}
                <div class="space-y-4">
                    <livewire:lesson-status :lesson="$lesson" :key="$lesson->id" />

                    <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Lesson details</h3>
                        <dl class="space-y-2 text-sm">
                            @if($lesson->lesson_code)
                                <div class="flex justify-between gap-4">
                                    <dt class="text-slate-400">Lesson code</dt>
                                    <dd class="font-mono font-semibold text-amber-300 tracking-widest">{{ $lesson->lesson_code }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-400">Topic</dt>
                                <dd class="text-slate-300 text-right">{{ $lesson->topic }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-400">Subject</dt>
                                <dd class="text-slate-300 capitalize">{{ $lesson->subject }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-400">Grade level</dt>
                                <dd class="text-slate-300">{{ $lesson->grade_level }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-400">Tone</dt>
                                <dd class="text-slate-300 capitalize">{{ $lesson->tone }}</dd>
                            </div>
                            @if($lesson->duration_seconds)
                                <div class="flex justify-between gap-4">
                                    <dt class="text-slate-400">Duration</dt>
                                    <dd class="text-slate-300">{{ gmdate('i:s', $lesson->duration_seconds) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-400">Created</dt>
                                <dd class="text-slate-300">{{ $lesson->created_at->diffForHumans() }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

            </div>
        </div>

        {{-- ── Settings tab ─────────────────────────────────────────────────── --}}
        <div x-show="tab === 'settings'" x-cloak>
            <livewire:lesson-settings :lesson="$lesson" :key="'settings-' . $lesson->id" />
        </div>

    </div>
</div>
