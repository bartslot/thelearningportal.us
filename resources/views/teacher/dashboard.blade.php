<x-layouts.app title="Teacher Dashboard">
<div class="space-y-8">

    {{-- ── Header ─────────────────────────────────────────────────────────── --}}
    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-widest text-amber-400">Teacher workspace</p>
            <h1 class="mt-2 text-3xl font-semibold text-slate-100">Your lessons</h1>
            <p class="mt-1 text-sm text-slate-400">{{ $lessons->total() }} lesson{{ $lessons->total() !== 1 ? 's' : '' }} total</p>
        </div>
        <a href="{{ route('teacher.lessons.create') }}"
           class="flex-shrink-0 rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors">
            + New lesson
        </a>
    </div>

    {{-- ── Meet your narrator (shown on first visit) ─────────────────────── --}}
    <livewire:teacher.meet-your-narrator />

    {{-- ── Lesson list ─────────────────────────────────────────────────────── --}}
    <div class="space-y-3">
        @forelse($lessons as $lesson)
            <a href="{{ route('teacher.lessons.show', $lesson) }}"
               class="group flex items-center gap-5 rounded-2xl border border-slate-800 bg-slate-900/60 p-5
                      transition-all hover:border-amber-600/50 hover:bg-slate-900">

                {{-- Status indicator bar --}}
                <div class="flex-shrink-0 w-1 self-stretch rounded-full
                    @if($lesson->status === \App\Enums\LessonStatus::Published)  bg-indigo-500
                    @elseif($lesson->status === \App\Enums\LessonStatus::Ready)      bg-emerald-500
                    @elseif($lesson->status === \App\Enums\LessonStatus::Generating) bg-amber-400
                    @elseif($lesson->status === \App\Enums\LessonStatus::Failed)     bg-rose-500
                    @else                                                             bg-slate-700
                    @endif
                "></div>

                {{-- Portrait thumb (if available) --}}
                @if($lesson->portraitUrl())
                    <img src="{{ $lesson->portraitUrl() }}"
                         alt="{{ $lesson->historical_figure }}"
                         class="h-12 w-12 flex-shrink-0 rounded-xl object-cover border border-slate-700">
                @else
                    <div class="h-12 w-12 flex-shrink-0 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-lg">
                        🏛️
                    </div>
                @endif

                {{-- Content --}}
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="text-xs text-slate-400 capitalize">{{ $lesson->subject }}</span>
                        <span class="text-slate-700 text-xs">·</span>
                        <span class="text-xs text-slate-400">{{ $lesson->grade_level }}</span>
                    </div>
                    <h2 class="text-base font-semibold text-slate-100 truncate group-hover:text-amber-400 transition-colors">
                        {{ $lesson->title }}
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-400 truncate">{{ $lesson->topic }}</p>
                </div>

                {{-- Status badge --}}
                <div class="flex-shrink-0 flex flex-col items-end gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium border
                        @if($lesson->status === \App\Enums\LessonStatus::Published)
                            bg-indigo-950/60 border-indigo-700 text-indigo-300
                        @elseif($lesson->status === \App\Enums\LessonStatus::Ready)
                            bg-emerald-950/60 border-emerald-700 text-emerald-300
                        @elseif($lesson->status === \App\Enums\LessonStatus::Generating)
                            bg-amber-950/60 border-amber-700 text-amber-300
                        @elseif($lesson->status === \App\Enums\LessonStatus::Failed)
                            bg-rose-950/60 border-rose-700 text-rose-300
                        @else
                            bg-slate-900 border-slate-700 text-slate-400
                        @endif
                    ">
                        @if($lesson->status === \App\Enums\LessonStatus::Generating)
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                        @endif
                        {{ $lesson->status->label() }}
                    </span>
                    <span class="text-xs text-slate-400">{{ $lesson->created_at->diffForHumans() }}</span>
                </div>

            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/30 p-12 text-center">
                <p class="text-2xl mb-3">🏛️</p>
                <p class="text-sm font-medium text-slate-300">No lessons yet</p>
                <p class="mt-1 text-sm text-slate-400">Create your first AI-generated history lesson to get started.</p>
                <a href="{{ route('teacher.lessons.create') }}"
                   class="mt-4 inline-flex rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors">
                    Create your first lesson
                </a>
            </div>
        @endforelse
    </div>

    {{-- ── Pagination ───────────────────────────────────────────────────────── --}}
    @if($lessons->hasPages())
        <div class="border-t border-slate-800 pt-6">
            {{ $lessons->links() }}
        </div>
    @endif

</div>
</x-layouts.app>
