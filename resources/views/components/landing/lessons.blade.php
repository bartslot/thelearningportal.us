@props(['lessons' => collect()])

@if ($lessons->isNotEmpty())
<section id="play" class="relative overflow-hidden border-t border-white/10 bg-gradient-to-b from-slate-950 via-slate-900 to-slate-900 py-16 sm:py-20">
    <div class="section-container">
        <div class="mb-10 max-w-2xl">
            <p class="text-[10px] uppercase tracking-[0.5em] text-sky-100/60">Play now</p>
            <h2 class="mt-3 font-history text-3xl tracking-tight text-white md:text-4xl">Lessons ready to play</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-400">
                Tap any lesson to watch the story unfold — narrated by an AI historian, no account needed.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @foreach ($lessons as $lesson)
                @php $cover = $lesson->cardImageUrl(); @endphp
                <a href="{{ route('lesson.play', $lesson->lesson_code) }}"
                   class="group relative block focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400/60 rounded-[1.35rem]">
                    <div class="lp-grain-poster relative aspect-[5/8] overflow-hidden rounded-[1.35rem] border border-white/10
                                bg-black/40 shadow-[0_16px_34px_rgba(0,0,0,0.35)] transition duration-300 ease-out
                                group-hover:-translate-y-1 group-hover:border-sky-400/25 group-hover:shadow-[0_24px_52px_rgba(0,0,0,0.5)]">

                        @if ($cover)
                            <img src="{{ $cover }}" alt="{{ $lesson->title }}"
                                 loading="lazy" decoding="async"
                                 width="480" height="768"
                                 class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.04]">
                        @else
                            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 text-4xl opacity-40">🏛️</div>
                        @endif

                        {{-- Legibility scrim --}}
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/20 to-transparent"></div>

                        {{-- Play affordance on hover --}}
                        <div class="absolute inset-0 flex items-center justify-center opacity-0 transition duration-300 ease-out group-hover:opacity-100">
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-white/90 text-slate-950 shadow-lg backdrop-blur-sm">
                                <svg class="ml-1 h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
                            </span>
                        </div>

                        {{-- Title block --}}
                        <div class="absolute inset-x-0 bottom-0 p-4 text-white">
                            <p class="truncate text-[11px] font-medium uppercase tracking-wide text-amber-400/90">
                                {{ $lesson->subject }}@if ($lesson->era) · {{ $lesson->era }}@endif
                            </p>
                            <h3 class="mt-1 text-base font-semibold leading-tight tracking-tight text-white line-clamp-2">
                                {{ $lesson->title ?: $lesson->topic }}
                            </h3>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
