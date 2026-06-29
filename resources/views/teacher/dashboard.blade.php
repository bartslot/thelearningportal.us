<x-layouts.app :title="__('Teacher Dashboard')">
<div class="space-y-8">

    {{-- ── Header ─────────────────────────────────────────────────────────── --}}
    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-widest text-amber-400">{{ __('Teacher workspace') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-slate-100">{{ __('Your lessons') }}</h1>
            <p class="mt-1 text-sm text-slate-400">{{ $lessons->total() }} {{ $lessons->total() === 1 ? __('lesson total') : __('lessons total') }}</p>
        </div>
        <a href="{{ route('teacher.lessons.create') }}"
           class="flex-shrink-0 rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors">
            {{ __('+ New lesson') }}
        </a>
    </div>

    {{-- ── Meet your narrator (shown on first visit) ─────────────────────── --}}
    <livewire:teacher.meet-your-narrator />

    {{-- ── Lesson card grid ────────────────────────────────────────────────── --}}
    @if($lessons->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/30 p-12 text-center">
            <p class="text-2xl mb-3">🏛️</p>
            <p class="text-sm font-medium text-slate-300">{{ __('No lessons yet') }}</p>
            <p class="mt-1 text-sm text-slate-400">{{ __('Create your first AI-generated history lesson to get started.') }}</p>
            <a href="{{ route('teacher.lessons.create') }}"
               class="mt-4 inline-flex rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors">
                {{ __('Create your first lesson') }}
            </a>
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @foreach($lessons as $lesson)
                @php
                    $cardImage = $lesson->cardImageUrl();
                    $statusColor = match($lesson->status) {
                        \App\Enums\LessonStatus::Published  => 'bg-indigo-500',
                        \App\Enums\LessonStatus::Ready,
                        \App\Enums\LessonStatus::Previewable,
                        \App\Enums\LessonStatus::Configuring => 'bg-emerald-500',
                        \App\Enums\LessonStatus::Generating,
                        \App\Enums\LessonStatus::ScenesGenerating,
                        \App\Enums\LessonStatus::Outlining,
                        \App\Enums\LessonStatus::FetchingSources => 'bg-amber-400',
                        \App\Enums\LessonStatus::Failed          => 'bg-rose-500',
                        default                                  => 'bg-slate-600',
                    };
                    $isGenerating = in_array($lesson->status, [
                        \App\Enums\LessonStatus::Generating,
                        \App\Enums\LessonStatus::ScenesGenerating,
                        \App\Enums\LessonStatus::Outlining,
                        \App\Enums\LessonStatus::FetchingSources,
                    ]);
                @endphp

                <a href="{{ route('teacher.lessons.show', $lesson) }}"
                   class="group relative block">
                    {{-- Card shell — 5:8 portrait ratio --}}
                    <div class="lp-grain-poster relative aspect-[5/8] overflow-hidden rounded-[1.35rem] border border-white/10
                                bg-black/40 shadow-[0_16px_34px_rgba(0,0,0,0.35)]
                                transition duration-300 ease-out
                                hover:-translate-y-1 hover:border-sky-400/25
                                hover:shadow-[0_24px_52px_rgba(0,0,0,0.5)]">

                        {{-- Background image --}}
                        @if($cardImage)
                            <img src="{{ $cardImage }}"
                                 alt="{{ $lesson->title }}"
                                 loading="lazy" decoding="async"
                                 class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.04]">
                        @else
                            {{-- Fallback gradient when no image yet --}}
                            <div class="h-full w-full bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 flex items-center justify-center text-4xl opacity-40">
                                🏛️
                            </div>
                        @endif

                        {{-- Gradient overlay --}}
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/20 to-transparent"></div>

                        {{-- Status dot — top right --}}
                        <div class="absolute right-3 top-3 flex items-center gap-1.5 rounded-full bg-black/50 px-2.5 py-1 backdrop-blur-sm">
                            <span class="h-1.5 w-1.5 rounded-full {{ $statusColor }} {{ $isGenerating ? 'animate-pulse' : '' }}"></span>
                            <span class="text-[10px] font-medium text-white/80">{{ $lesson->status->label() }}</span>
                        </div>

                        {{-- Card body text --}}
                        <div class="absolute inset-x-0 bottom-0 p-4 text-white">
                            <p class="truncate text-[11px] font-medium text-amber-400/90 uppercase tracking-wide mb-1">
                                {{ $lesson->subject }}
                                @if($lesson->grade_level)
                                    · {{ $lesson->grade_level }}
                                @endif
                            </p>
                            <h3 class="text-base font-semibold leading-tight tracking-tight text-white line-clamp-2">
                                {{ $lesson->title ?: $lesson->topic }}
                            </h3>

                            {{-- Hover reveal: topic + time --}}
                            <div class="max-h-0 overflow-hidden opacity-0 transition-all duration-300 ease-out group-hover:max-h-16 group-hover:opacity-100">
                                <p class="mt-1.5 text-[11px] leading-4 text-slate-300/80 line-clamp-2">
                                    {{ $lesson->topic }}
                                </p>
                                <p class="mt-1 text-[10px] text-slate-400">
                                    {{ $lesson->created_at->diffForHumans() }}
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- ── Pagination ───────────────────────────────────────────────────────── --}}
    @if($lessons->hasPages())
        <div class="border-t border-slate-800 pt-6">
            {{ $lessons->links() }}
        </div>
    @endif

</div>
</x-layouts.app>
