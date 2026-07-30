<x-layouts.app :title="__('Teacher Dashboard')">
<div class="space-y-14">
    <header class="flex flex-col gap-6 border-b border-slate-800 pb-8 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-[0.18em] text-amber-400">{{ __('Teacher workspace') }}</p>
            <h1>
                {{ __('Overview') }}
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

            <a href="{{ route('teacher.lessons.chat') }}" class="btn btn-primary btn-sm" data-tour="new-lesson">
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


    @include('teacher.partials.lesson-cards')

    {{-- The welcome tour spotlights parts of this page and the top bar, so it lives here rather
         than in the layout: it opens where its anchors are, and only for accounts still owed it. --}}
    @if (auth()->user()?->isTeacher() || auth()->user()?->isAdmin())
        @livewire('onboarding.welcome-tour')
    @endif
</div>
</x-layouts.app>
