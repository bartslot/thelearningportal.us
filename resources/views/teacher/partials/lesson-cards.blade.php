{{-- Lesson cards — one flat, Netflix-style horizontal row shared by the Dashboard (/teacher)
     and the Lessons page (/teacher/lessons). The old per-theme slider put ONE card on a slide
     and buried it under Canon labels; with a handful of lessons that read as empty. All lessons
     scroll in a single row, newest first. Needs: $themeGroups, $lessonCount, $lessonLimit. --}}
@php
    $allLessons = collect($themeGroups)
        ->flatMap(fn (array $group) => $group['lessons'])
        ->unique('id')
        ->sortByDesc('created_at')
        ->values();
@endphp

<section aria-label="{{ __('Lessons') }}">
    @if($allLessons->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-800 px-6 py-12 text-center">
            <x-icons.building-library class="h-8 w-8 text-slate-600" />
            <p class="mt-3 text-sm font-medium text-slate-300">{{ __('No lessons yet') }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ __('Create a lesson to get started.') }}</p>
            <a href="{{ route('teacher.lessons.chat') }}" class="btn btn-primary btn-sm mt-5">
                <x-icons.sparkles class="h-4 w-4" />
                {{ __('Create lesson') }}
            </a>
        </div>
    @else
        <div class="group/row relative" x-data>
            {{-- Edge arrows — Netflix style: appear on row hover, scroll by ~a page. --}}
            <button type="button"
                    @click="$refs.row.scrollBy({ left: -$refs.row.clientWidth * 0.8, behavior: 'smooth' })"
                    class="absolute -left-3 top-1/2 z-10 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-slate-700 bg-slate-950/90 text-slate-300 opacity-0 shadow-xl transition hover:border-amber-500/50 hover:text-amber-300 group-hover/row:opacity-100 sm:flex"
                    aria-label="{{ __('Scroll left') }}">
                <x-icons.chevron-left class="h-5 w-5" />
            </button>
            <button type="button"
                    @click="$refs.row.scrollBy({ left: $refs.row.clientWidth * 0.8, behavior: 'smooth' })"
                    class="absolute -right-3 top-1/2 z-10 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-slate-700 bg-slate-950/90 text-slate-300 opacity-0 shadow-xl transition hover:border-amber-500/50 hover:text-amber-300 group-hover/row:opacity-100 sm:flex"
                    aria-label="{{ __('Scroll right') }}">
                <x-icons.chevron-right class="h-5 w-5" />
            </button>

            <div x-ref="row" class="flex snap-x gap-4 overflow-x-auto pb-2 no-scrollbar">
                @foreach($allLessons as $lesson)
                    @php
                        $cardImage = $lesson->cardImageUrl();
                        $isGenerating = in_array($lesson->status, [
                            \App\Enums\LessonStatus::Generating,
                            \App\Enums\LessonStatus::ScenesGenerating,
                            \App\Enums\LessonStatus::Outlining,
                            \App\Enums\LessonStatus::FetchingSources,
                        ]);
                        $statusClass = match($lesson->status) {
                            \App\Enums\LessonStatus::Failed => 'bg-rose-400',
                            \App\Enums\LessonStatus::Ready,
                            \App\Enums\LessonStatus::Published,
                            \App\Enums\LessonStatus::Previewable,
                            \App\Enums\LessonStatus::Configuring => 'bg-emerald-400',
                            default => 'bg-amber-400',
                        };
                    @endphp

                    <div class="w-[17rem] shrink-0 snap-start overflow-hidden rounded-xl border border-slate-800 bg-slate-950/70 transition hover:-translate-y-0.5 hover:border-amber-500/30 sm:w-[19rem]">
                        <a href="{{ route('teacher.lessons.show', $lesson) }}" class="group block">
                            <div class="relative aspect-[16/9] overflow-hidden bg-slate-900">
                                @if($cardImage)
                                    <img
                                        src="{{ $cardImage }}"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        class="h-full w-full object-cover opacity-80 transition duration-500 group-hover:scale-[1.03] group-hover:opacity-100"
                                    >
                                @else
                                    <div class="flex h-full items-center justify-center bg-gradient-to-br from-slate-800 to-slate-950">
                                        <x-icons.building-library class="h-8 w-8 text-slate-600" />
                                    </div>
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent"></div>
                                <div class="absolute left-3 top-3 flex items-center gap-1.5 rounded-full bg-slate-950/80 px-2.5 py-1 backdrop-blur-sm">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $statusClass }} {{ $isGenerating ? 'animate-pulse' : '' }}"></span>
                                    <span class="text-[0.65rem] text-slate-300">{{ $lesson->status->label() }}</span>
                                </div>
                            </div>

                            <div class="p-4">
                                <p class="truncate text-[0.65rem] font-medium uppercase tracking-wider text-amber-400/80">
                                    {{ $lesson->subject }}
                                    @if($lesson->grade_level)
                                        · {{ $lesson->grade_level }}
                                    @endif
                                </p>
                                <h4 class="mt-2 line-clamp-2 min-h-10 text-sm font-semibold leading-5 text-slate-100 group-hover:text-amber-300">
                                    {{ $lesson->title ?: $lesson->topic }}
                                </h4>
                                <p class="mt-2 text-xs text-slate-600">{{ $lesson->created_at->diffForHumans() }}</p>
                            </div>
                        </a>

                        <div class="flex items-center justify-between border-t border-slate-800 px-4 py-3">
                            <a href="{{ route('teacher.lessons.show', $lesson) }}" class="text-xs text-slate-400 hover:text-slate-200">
                                {{ __('Open lesson') }}
                            </a>
                            <a href="{{ route('teacher.lessons.results', $lesson) }}" class="inline-flex items-center gap-1.5 text-xs text-amber-400 hover:text-amber-300">
                                <x-icons.chart-bar class="h-3.5 w-3.5" />
                                {{ __('Results') }}
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @if($lessonCount > $lessonLimit)
            <p class="mt-4 text-right text-xs text-slate-600">
                {{ __('Showing the :count most recent lessons.', ['count' => $lessonLimit]) }}
            </p>
        @endif
    @endif
</section>
