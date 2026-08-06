@props(['lessons' => collect()])

@if ($lessons->isNotEmpty())
<section id="play" class="relative overflow-hidden border-t border-white/10 bg-gradient-to-b from-slate-950 via-slate-900 to-slate-900 py-16 sm:py-20">
    <div class="section-container">
        <div class="mb-10 max-w-2xl">
            <h2 class="mt-3 font-history font-semibold text-3xl tracking-tight text-white md:text-4xl">{{ __('Lessons ready to play') }}</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-400">
                {{ __('Tap any lesson to watch the story unfold, narrated by an AI historian. No account needed.') }}
            </p>
        </div>

        {{-- The app's shared card row — see resources/views/components/carousel.blade.php. --}}
        <div class="relative overflow-hidden rounded-[2rem]">
            <x-carousel aria-label="{{ __('Lessons ready to play') }}">
                @foreach ($lessons as $lesson)
                    <x-lesson-poster-card
                        :lesson="$lesson"
                        class="carousel-cell mx-2 w-[15rem] shrink-0 sm:w-[16rem] lg:w-[17rem]"
                    />
                @endforeach
            </x-carousel>
        </div>
    </div>
</section>
@endif
