{{-- The public lesson library: readable, indexable text for every published lesson.
     The player itself is a JavaScript stage, so this is the page a search engine can actually read
     and a teacher can actually land on. --}}
@php
    use App\Support\Seo;

    $title = __('History lessons ready to play');
    $description = __('Narrated history lessons for the classroom, free to play in the browser with no account. Built by teachers with The Learning Portal.');
@endphp

<x-layouts.landing :title="$title">
    <x-slot:head>
        <x-seo page="lessons" :title="$title" :description="$description" :graph="Seo::organisationGraph()" />
    </x-slot:head>

    <x-landing.header />

    <main class="mx-auto max-w-6xl px-4 pb-16 pt-32 sm:px-6 lg:px-8">
        <header class="max-w-3xl">
            <p class="text-xs font-medium uppercase tracking-[0.18em] text-amber-400">{{ __('Lesson library') }}</p>
            <h1 class="mt-3 font-history text-4xl font-light tracking-tight text-white sm:text-5xl">
                {{ $title }}
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-slate-300">
                {{ __('Every lesson below is narrated, illustrated with museum imagery and ends with a quiz. Open one and it plays straight away: no account, no install, on whatever device the class has.') }}
            </p>
            <p class="mt-3 text-sm text-slate-400">
                {{ trans_choice(':count lesson published so far.|:count lessons published so far.', $lessons->count(), ['count' => $lessons->count()]) }}
                {{ __('We are getting ready for the new school year, and the library grows every week.') }}
            </p>
        </header>

        @if ($grades->isNotEmpty())
            <p class="mt-8 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                <span class="uppercase tracking-wider">{{ __('Age groups') }}:</span>
                @foreach ($grades as $grade)
                    <span class="rounded-full border border-slate-700 px-2.5 py-1">{{ $grade }}</span>
                @endforeach
            </p>
        @endif

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($lessons as $lesson)
                @php
                    $slug = Seo::lessonSlug($lesson);
                    $poster = $lesson->poster_image
                        ? \Illuminate\Support\Facades\Storage::disk('public')->url($lesson->poster_image)
                        : ($lesson->firstScene?->image_path
                            ? \Illuminate\Support\Facades\Storage::disk('public')->url($lesson->firstScene->image_path)
                            : null);
                @endphp
                <article class="group overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/40 transition hover:-translate-y-0.5 hover:border-amber-500/40">
                    <a href="{{ Seo::url('lesson', ['slug' => $slug]) }}" class="block">
                        {{-- Not every lesson has a poster yet. An empty 16:10 box reads as a broken
                             image, so the fallback is a deliberate title card instead. --}}
                        <div class="relative aspect-[16/10] overflow-hidden bg-slate-950">
                            @if ($poster)
                                <img src="{{ $poster }}" alt="{{ $lesson->title }}" loading="lazy" decoding="async"
                                     class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03] motion-reduce:transition-none">
                            @else
                                <div class="flex h-full w-full items-center justify-center bg-[radial-gradient(circle_at_30%_20%,rgba(251,191,36,0.16),transparent_60%),radial-gradient(circle_at_75%_85%,rgba(56,189,248,0.14),transparent_55%)] px-5">
                                    <span class="line-clamp-3 text-center font-history text-lg font-light leading-snug text-white/80">
                                        {{ $lesson->title ?: $lesson->topic }}
                                    </span>
                                </div>
                            @endif
                        </div>
                        <div class="p-5">
                            <h2 class="text-base font-semibold text-slate-100 group-hover:text-amber-300">
                                {{ $lesson->title ?: $lesson->topic }}
                            </h2>
                            <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-400">
                                {{ Seo::lessonDescription($lesson) }}
                            </p>
                            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                @if ($lesson->grade_level)<span>{{ $lesson->grade_level }}</span>@endif
                                @if ($lesson->duration_seconds)
                                    <span>{{ __(':minutes min', ['minutes' => (int) ceil($lesson->duration_seconds / 60)]) }}</span>
                                @endif
                                <span class="font-mono uppercase">{{ $lesson->lesson_code }}</span>
                            </p>
                        </div>
                    </a>
                </article>
            @empty
                <p class="text-slate-400">{{ __('The first lessons are being finished right now. Check back shortly.') }}</p>
            @endforelse
        </div>

        <section class="mt-20 rounded-2xl border border-slate-800 bg-slate-900/40 p-8">
            <h2 class="font-history text-2xl font-light text-white">{{ __('Teaching in a European classroom?') }}</h2>
            <p class="mt-3 max-w-2xl text-slate-300">
                {{ __('The portal speaks English, Dutch, German, French and Italian, and a lesson takes minutes to build from your own topic. We are preparing for wider classroom use and are glad to hear what your curriculum needs.') }}
            </p>
            <a href="mailto:info@thelearningportal.us?subject={{ rawurlencode(__('Classroom use')) }}"
               class="mt-5 inline-flex items-center gap-2 rounded-lg bg-amber-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-amber-300">
                {{ __('Talk to us') }}
            </a>
        </section>
    </main>
</x-layouts.landing>
