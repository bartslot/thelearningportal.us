<x-layouts.app :title="__('Teacher Dashboard')">
<div class="space-y-14">
    <header class="flex flex-col gap-6 border-b border-slate-800 pb-8 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-[0.18em] text-amber-400">{{ __('Teacher workspace') }}</p>
            <h1 class="mt-2 font-history text-4xl font-light tracking-tight text-slate-100 sm:text-5xl">
                {{ __('Dashboard') }}
            </h1>
            <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-400">
                <span>{{ trans_choice(':count active class|:count active classes', $classCount, ['count' => $classCount]) }}</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-700 sm:block"></span>
                <span>{{ trans_choice(':count lesson|:count lessons', $lessonCount, ['count' => $lessonCount]) }}</span>
                <span class="hidden h-1 w-1 rounded-full bg-slate-700 sm:block"></span>
                <span>{{ trans_choice(':count result this month|:count results this month', $results['attempts'], ['count' => $results['attempts']]) }}</span>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            @if($narrator)
                @php $narratorImage = $narrator->thumbnailUrl() ?? $narrator->portraitUrl(); @endphp
                <div
                    x-data="{ open: false }"
                    @keydown.escape.window="open = false"
                    class="relative"
                >
                    <button
                        type="button"
                        @click="open = !open"
                        :aria-expanded="open"
                        aria-haspopup="dialog"
                        class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl border border-slate-700 bg-slate-900 text-slate-400 transition hover:border-amber-500/60 hover:text-amber-300"
                        title="{{ __('Meet your narrator') }}"
                    >
                        @if($narratorImage)
                            <img src="{{ $narratorImage }}" alt="{{ $narrator->name }}" class="h-full w-full object-cover">
                        @else
                            <x-icons.academic-cap class="h-5 w-5" />
                        @endif
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        x-transition.origin.top.right
                        @click.outside="open = false"
                        class="absolute right-0 top-12 z-30 w-72 rounded-xl border border-slate-700 bg-slate-900 p-4 shadow-2xl"
                        role="dialog"
                        aria-label="{{ __('Your narrator') }}"
                    >
                        <div class="flex items-center gap-3">
                            @if($narratorImage)
                                <img src="{{ $narratorImage }}" alt="" class="h-12 w-12 rounded-lg object-cover">
                            @endif
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wider text-amber-400">{{ __('Your narrator') }}</p>
                                <p class="mt-1 truncate text-sm font-semibold text-slate-100">{{ $narrator->name }}</p>
                                @if($narrator->avatar_title || $narrator->era)
                                    <p class="truncate text-xs text-slate-400">{{ $narrator->avatar_title ?: $narrator->era }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <a href="{{ route('teacher.lessons.chat') }}" class="btn btn-primary btn-sm">
                <x-icons.sparkles class="h-4 w-4" />
                {{ __('New lesson') }}
            </a>
        </div>
    </header>

    <section aria-labelledby="classes-heading">
        <div class="mb-5 flex items-end justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg bg-slate-900 text-amber-400">
                    <x-icons.users class="h-5 w-5" />
                </span>
                <div>
                    <h2 id="classes-heading" class="font-history text-2xl font-light text-slate-100">{{ __('Classes') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Roster, assigned lessons and recent performance.') }}</p>
                </div>
            </div>
            <a href="{{ route('teacher.classes.index') }}" class="text-sm text-amber-400 transition hover:text-amber-300">
                {{ __('Manage classes') }}
            </a>
        </div>

        @if($classrooms->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-800 px-6 py-10 text-center">
                <x-icons.users class="h-7 w-7 text-slate-600" />
                <p class="mt-3 text-sm font-medium text-slate-300">{{ __('No active classes yet') }}</p>
                <a href="{{ route('teacher.classes.index') }}" class="mt-3 text-sm text-amber-400 hover:text-amber-300">
                    {{ __('Create a class') }}
                </a>
            </div>
        @else
            <div class="grid gap-3 md:grid-cols-3">
                @foreach($classrooms as $classroom)
                    @php $classResult = $classResults[$classroom->id] ?? null; @endphp
                    <a
                        href="{{ route('teacher.classes.manage', $classroom) }}"
                        class="group rounded-xl border border-slate-800 bg-slate-900/30 p-5 transition hover:-translate-y-0.5 hover:border-amber-500/30 hover:bg-slate-900/60"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-slate-100 group-hover:text-amber-300">
                                    {{ $classroom->name }}
                                </h3>
                                <p class="mt-1 text-xs text-slate-500">
                                    @if($classroom->grade_level)
                                        {{ __('Grade :grade', ['grade' => $classroom->grade_level]) }} ·
                                    @endif
                                    {{ trans_choice(':count student|:count students', $classroom->members_count, ['count' => $classroom->members_count]) }}
                                </p>
                            </div>
                            <span class="rounded-md border border-slate-700 px-2 py-1 font-mono text-[0.65rem] text-slate-400">
                                {{ $classroom->join_code }}
                            </span>
                        </div>

                        <div class="mt-7 flex items-end justify-between border-t border-slate-800 pt-4">
                            <div>
                                @if($classResult)
                                    <p class="font-history text-2xl font-light text-slate-100">{{ $classResult['average'] }}%</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ trans_choice(':count recent attempt|:count recent attempts', $classResult['attempts'], ['count' => $classResult['attempts']]) }}
                                    </p>
                                @else
                                    <p class="text-sm text-slate-400">{{ __('No results yet') }}</p>
                                    <p class="mt-1 text-xs text-slate-600">{{ __('Results appear after a quiz.') }}</p>
                                @endif
                            </div>
                            <span class="text-xs text-slate-500">
                                {{ trans_choice(':count lesson|:count lessons', $classroom->lessons_count, ['count' => $classroom->lessons_count]) }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section aria-labelledby="results-heading">
        <div class="mb-5 flex items-end justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg bg-slate-900 text-amber-400">
                    <x-icons.chart-bar class="h-5 w-5" />
                </span>
                <div>
                    <h2 id="results-heading" class="font-history text-2xl font-light text-slate-100">{{ __('Results') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Quiz performance over the last 30 days.') }}</p>
                </div>
            </div>
            <a href="{{ route('teacher.results.hub') }}" class="inline-flex items-center gap-2 text-sm text-amber-400 transition hover:text-amber-300">
                <x-icons.chart-bar class="h-4 w-4" />
                {{ __('All results') }}
            </a>
        </div>

        <div class="grid gap-8 border-y border-slate-800 py-7 lg:grid-cols-[minmax(0,1.6fr)_minmax(16rem,0.7fr)] lg:gap-12">
            <div class="min-w-0">
                <div class="flex flex-wrap gap-x-10 gap-y-5">
                    <div>
                        <p class="font-history text-3xl font-light text-amber-300">{{ $results['average'] }}%</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Average correct') }}</p>
                    </div>
                    <div>
                        <p class="font-history text-3xl font-light text-slate-100">{{ $results['attempts'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Attempts') }}</p>
                    </div>
                    <div>
                        <p class="font-history text-3xl font-light {{ $results['needs_attention'] > 0 ? 'text-rose-300' : 'text-slate-100' }}">
                            {{ $results['needs_attention'] }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Below 50%') }}</p>
                    </div>
                </div>

                <div class="mt-8">
                    <svg
                        viewBox="0 0 100 42"
                        class="h-44 w-full overflow-visible"
                        role="img"
                        aria-label="{{ __('Average quiz result over the last 14 days') }}"
                        preserveAspectRatio="none"
                    >
                        <defs>
                            <linearGradient id="dashboard-chart-fill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#f59e0b" stop-opacity=".22" />
                                <stop offset="100%" stop-color="#f59e0b" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <path d="M4 6H96 M4 22H96 M4 38H96" fill="none" stroke="rgba(148,163,184,.14)" stroke-width=".35" />
                        @if($results['has_activity'])
                            <polygon points="{{ $results['chart_area'] }}" fill="url(#dashboard-chart-fill)" />
                            <polyline
                                points="{{ $results['chart_points'] }}"
                                fill="none"
                                stroke="#f59e0b"
                                stroke-width="1.15"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                vector-effect="non-scaling-stroke"
                            />
                        @else
                            <path d="M4 38H96" fill="none" stroke="rgba(148,163,184,.45)" stroke-width=".8" stroke-dasharray="2 2" />
                        @endif
                    </svg>
                    <div class="mt-1 flex justify-between text-[0.65rem] text-slate-600">
                        @foreach($results['chart_labels'] as $label)
                            <span>{{ $label }}</span>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="border-slate-800 lg:border-l lg:pl-8">
                <h3 class="text-sm font-semibold text-slate-200">{{ __('Recent lessons') }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ __('Most-played lessons this month.') }}</p>

                <div class="mt-6 space-y-5">
                    @forelse($results['recent_lessons'] as $lessonResult)
                        <a href="{{ route('teacher.lessons.results', $lessonResult['lesson']) }}" class="group block">
                            <div class="flex items-center justify-between gap-4 text-xs">
                                <span class="truncate text-slate-400 group-hover:text-slate-200">
                                    {{ $lessonResult['lesson']->title ?: $lessonResult['lesson']->topic }}
                                </span>
                                <span class="shrink-0 font-medium text-slate-200">{{ $lessonResult['average'] }}%</span>
                            </div>
                            <div class="mt-2 h-1 overflow-hidden rounded-full bg-slate-800">
                                <span class="block h-full rounded-full bg-emerald-400/80" style="width: {{ $lessonResult['average'] }}%"></span>
                            </div>
                            <p class="mt-1.5 text-[0.65rem] text-slate-600">
                                {{ trans_choice(':count attempt|:count attempts', $lessonResult['attempts'], ['count' => $lessonResult['attempts']]) }}
                            </p>
                        </a>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-800 px-4 py-7 text-center">
                            <p class="text-xs text-slate-500">{{ __('No quiz activity yet.') }}</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section aria-labelledby="lessons-heading">
        <div class="mb-5 flex items-end justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg bg-slate-900 text-amber-400">
                    <x-icons.book-open class="h-5 w-5" />
                </span>
                <div>
                    <h2 id="lessons-heading" class="font-history text-2xl font-light text-slate-100">{{ __('Lessons by theme') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Grouped by the Canon van Nederland.') }}</p>
                </div>
            </div>
        </div>

        @if(empty($themeGroups))
            <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-800 px-6 py-12 text-center">
                <x-icons.building-library class="h-8 w-8 text-slate-600" />
                <p class="mt-3 text-sm font-medium text-slate-300">{{ __('No lessons yet') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Create a lesson to start your theme collection.') }}</p>
                <a href="{{ route('teacher.lessons.chat') }}" class="btn btn-primary btn-sm mt-5">
                    <x-icons.sparkles class="h-4 w-4" />
                    {{ __('Create lesson') }}
                </a>
            </div>
        @else
            <div
                x-data="{
                    active: 0,
                    count: {{ count($themeGroups) }},
                    go(index) {
                        this.active = Math.max(0, Math.min(this.count - 1, index));
                        this.$refs.track.scrollTo({
                            left: this.$refs.track.clientWidth * this.active,
                            behavior: 'smooth'
                        });
                    }
                }"
                class="rounded-xl border border-slate-800 bg-slate-900/20 p-4 sm:p-6"
            >
                <div class="mb-6 flex items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-2 overflow-x-auto no-scrollbar" aria-label="{{ __('Lesson themes') }}">
                        @foreach($themeGroups as $index => $group)
                            <button
                                type="button"
                                @click="go({{ $index }})"
                                :class="active === {{ $index }} ? 'border-amber-500/50 bg-amber-500/10 text-amber-300' : 'border-slate-800 text-slate-500 hover:text-slate-300'"
                                class="shrink-0 rounded-full border px-3 py-1.5 text-xs transition"
                            >
                                {{ __($group['label']) }}
                            </button>
                        @endforeach
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            @click="go(active - 1)"
                            :disabled="active === 0"
                            class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 text-slate-400 transition hover:border-amber-500/50 hover:text-amber-300 disabled:cursor-not-allowed disabled:opacity-30"
                            aria-label="{{ __('Previous theme') }}"
                        >
                            <x-icons.chevron-left class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            @click="go(active + 1)"
                            :disabled="active === count - 1"
                            class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 text-slate-400 transition hover:border-amber-500/50 hover:text-amber-300 disabled:cursor-not-allowed disabled:opacity-30"
                            aria-label="{{ __('Next theme') }}"
                        >
                            <x-icons.chevron-right class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div
                    x-ref="track"
                    @scroll.debounce.120ms="active = Math.round($el.scrollLeft / Math.max(1, $el.clientWidth))"
                    class="flex snap-x snap-mandatory overflow-x-auto scroll-smooth no-scrollbar"
                >
                    @foreach($themeGroups as $group)
                        <article class="w-full shrink-0 snap-start" aria-label="{{ __($group['label']) }}">
                            <div class="mb-4 flex items-end justify-between gap-3 pr-1">
                                <div>
                                    @if($group['number'])
                                        <p class="text-[0.65rem] font-medium uppercase tracking-[0.16em] text-sky-400">
                                            {{ __('Canon theme :number', ['number' => $group['number']]) }}
                                        </p>
                                    @else
                                        <p class="text-[0.65rem] font-medium uppercase tracking-[0.16em] text-sky-400">{{ __('Collection') }}</p>
                                    @endif
                                    <h3 class="mt-1 font-history text-2xl font-light text-slate-100">{{ __($group['label']) }}</h3>
                                    <p class="mt-1 text-xs text-slate-500">{{ __($group['subtitle']) }}</p>
                                </div>
                                <span class="text-xs text-slate-600">
                                    {{ trans_choice(':count lesson|:count lessons', $group['lessons']->count(), ['count' => $group['lessons']->count()]) }}
                                </span>
                            </div>

                            <div class="flex snap-x gap-3 overflow-x-auto pb-2 no-scrollbar">
                                @foreach($group['lessons'] as $lesson)
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
                        </article>
                    @endforeach
                </div>

                @if($lessonCount > $lessonLimit)
                    <p class="mt-4 text-right text-xs text-slate-600">
                        {{ __('Showing the :count most recent lessons.', ['count' => $lessonLimit]) }}
                    </p>
                @endif
            </div>
        @endif
    </section>
</div>
</x-layouts.app>
