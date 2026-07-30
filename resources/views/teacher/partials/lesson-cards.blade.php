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

                {{-- The shared carousel — same drag/swipe/arrows as the landing shelves. It brings
                     its own arrow buttons, so the hand-rolled scroll-by-a-page pair that used to
                     live here is gone. --}}
                <div class="relative overflow-hidden rounded-[2rem]">
                    <x-carousel aria-label="{{ __($shelf['label']) }}">
                        @foreach($shelf['lessons'] as $lesson)
                            @php
                                $cardImage = $lesson->cardImageUrl();
                                $isGenerating = $lesson->status->isGenerating();
                                // Open an existing lesson on Preview; see Lesson::cardEntryStep().
                                $entryStep = $lesson->cardEntryStep();
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
                                 The whole card opens the lesson on Preview once it has scenes, and
                                 otherwise resumes the wizard where the teacher left off. --}}
                            <a href="{{ $entryStep
                                    ? route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => $entryStep])
                                    : route('teacher.lessons.show', $lesson) }}"
                               class="carousel-cell group relative mx-2 block aspect-2/3 w-52 overflow-hidden rounded-xl border border-slate-800 bg-slate-900 transition duration-300 hover:-translate-y-1 hover:border-amber-500/40 hover:shadow-[0_18px_40px_rgba(0,0,0,0.5)] sm:w-[15rem]">
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
                                <p class="absolute inset-x-0 top-6 right-0 p-4 text-sm leading-snug text-slate-100 drop-shadow group-hover:text-amber-300">
                                    Grade {{ $lesson->grade ?: $lesson->grade_level ?: __(' ') }}
                                </p>
                            </a>
                        @endforeach
                    </x-carousel>
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
