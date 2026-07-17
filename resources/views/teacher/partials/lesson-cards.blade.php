{{-- Lesson cards grouped by Canon theme — shared by the Dashboard (/teacher) and the
     Lessons page (/teacher/lessons). Needs: $themeGroups, $lessonCount, $lessonLimit. --}}
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
