{{-- Lesson shelves — Netflix-style: one SECTION per place group (Netherlands / France / …),
     stacked vertically, each a title over a horizontal snap-scroll row of poster cards.
     Shared by the Dashboard (/teacher) and the Lessons page (/teacher/lessons).
     Needs: $shelves (label + lessons), $lessonCount, $lessonLimit. --}}
<div class="space-y-10">
    @if(empty($shelves))
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
        @foreach($shelves as $shelf)
            <section aria-label="{{ __($shelf['label']) }}">
                <h2 class="mb-4 font-history text-xl font-light text-slate-100">{{ __($shelf['label']) }}</h2>

                <div class="group/row relative" x-data>
                    {{-- Edge arrows — appear on row hover, scroll ~a page. --}}
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
                        @foreach($shelf['lessons'] as $lesson)
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

                            {{-- Poster card: full-height image, title-only over a bottom shadow scrim.
                                 The whole card links to the lesson dashboard (settings + configurator). --}}
                            <a href="{{ route('teacher.lessons.show', $lesson) }}"
                               class="group relative aspect-[2/3] w-[13rem] shrink-0 snap-start overflow-hidden rounded-xl border border-slate-800 bg-slate-900 transition duration-300 hover:-translate-y-1 hover:border-amber-500/40 hover:shadow-[0_18px_40px_rgba(0,0,0,0.5)] sm:w-[15rem]">
                                @if($cardImage)
                                    <img
                                        src="{{ $cardImage }}"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        class="absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.04]"
                                    >
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-slate-800 to-slate-950">
                                        <x-icons.building-library class="h-10 w-10 text-slate-600" />
                                    </div>
                                @endif

                                {{-- Shadow fading in over the image — the only backdrop the title gets. --}}
                                <div class="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-slate-950 via-slate-950/55 to-transparent"></div>

                                <div class="absolute left-3 top-3 flex items-center gap-1.5 rounded-full bg-slate-950/80 px-2.5 py-1 backdrop-blur-sm">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $statusClass }} {{ $isGenerating ? 'animate-pulse' : '' }}"></span>
                                    <span class="text-[0.65rem] text-slate-300">{{ $lesson->status->label() }}</span>
                                </div>

                                <h4 class="absolute inset-x-0 bottom-0 p-4 text-base font-semibold leading-snug text-slate-100 drop-shadow group-hover:text-amber-300">
                                    {{ $lesson->title ?: $lesson->topic }}
                                </h4>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endforeach

        @if($lessonCount > $lessonLimit)
            <p class="text-right text-xs text-slate-600">
                {{ __('Showing the :count most recent lessons.', ['count' => $lessonLimit]) }}
            </p>
        @endif
    @endif
</div>
