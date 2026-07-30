{{-- One indexable page per published lesson: real text about what the lesson covers, with the
     player one click away. This is what a teacher searching for a topic can land on. --}}
@php
    use App\Support\Seo;

    $heading = $lesson->title ?: $lesson->topic;
    $description = Seo::lessonDescription($lesson);
    $poster = $lesson->poster_image
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($lesson->poster_image)
        : null;
    $minutes = $lesson->duration_seconds ? (int) ceil($lesson->duration_seconds / 60) : null;
@endphp

<x-layouts.landing :title="$heading">
    <x-slot:head>
        <x-seo
            page="lesson"
            :params="['slug' => $slug]"
            :title="$heading"
            :description="$description"
            :image="$poster"
            type="article"
            :graph="Seo::lessonGraph($lesson)"
        />
    </x-slot:head>

    <x-landing.header />

    <main class="mx-auto max-w-4xl px-4 pb-16 pt-32 sm:px-6">
        <nav class="text-xs text-slate-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ Seo::url('lessons') }}" class="hover:text-amber-300">{{ __('Lesson library') }}</a>
            <span class="mx-2">/</span>
            <span class="text-slate-400">{{ $heading }}</span>
        </nav>

        <header class="mt-6">
            <h1 class="font-history text-4xl font-light tracking-tight text-white sm:text-5xl">{{ $heading }}</h1>
            <p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-400">
                @if ($lesson->grade_level)
                    <span>{{ __('Age group') }}: <span class="text-slate-200">{{ $lesson->grade_level }}</span></span>
                @endif
                @if ($minutes)
                    <span>{{ __('Length') }}: <span class="text-slate-200">{{ __(':minutes min', ['minutes' => $minutes]) }}</span></span>
                @endif
                <span>{{ __('Lesson code') }}: <span class="font-mono uppercase text-slate-200">{{ $lesson->lesson_code }}</span></span>
            </p>
        </header>

        @if ($poster)
            <img src="{{ $poster }}" alt="{{ $heading }}" loading="lazy" decoding="async"
                 class="mt-8 w-full rounded-2xl border border-slate-800 object-cover">
        @endif

        <div class="mt-8 flex flex-wrap items-center gap-3">
            <a href="{{ route('lesson.play', ['lessonCode' => $lesson->lesson_code]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-amber-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-amber-300">
                {{ __('Play this lesson') }}
            </a>
            <span class="text-sm text-slate-500">{{ __('Opens straight away. No account, nothing to install.') }}</span>
        </div>

        <section class="mt-12 space-y-4 text-base leading-relaxed text-slate-300">
            <h2 class="font-history text-2xl font-light text-white">{{ __('What this lesson covers') }}</h2>
            <p>{{ $description }}</p>
            @if ($lesson->topic && $lesson->topic !== $heading)
                <p>{{ __('The subject is :topic, told as a story rather than a list of dates.', ['topic' => $lesson->topic]) }}</p>
            @endif
            <p>{{ __('The narrator reads each scene aloud while historical imagery fills the screen, and a short quiz at the end checks what stuck. A teacher can play it on the big screen or send the code to the class.') }}</p>
        </section>

        @if ($chapters->isNotEmpty())
            <section class="mt-12">
                <h2 class="font-history text-2xl font-light text-white">{{ __('In this lesson') }}</h2>
                <ol class="mt-4 space-y-2">
                    @foreach ($chapters as $index => $chapter)
                        <li class="flex items-start gap-3 text-slate-300">
                            <span class="mt-1 font-mono text-xs text-slate-600">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <span>{{ $chapter }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        <section class="mt-12 rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
            <h2 class="text-base font-semibold text-white">{{ __('Build your own version') }}</h2>
            <p class="mt-2 text-sm leading-relaxed text-slate-300">
                {{ __('Every lesson here was made in the portal from a topic and an age group. You can change any scene, swap the images, rewrite the narration, or start from your own subject entirely.') }}
            </p>
            <a href="{{ route('login') }}" class="mt-4 inline-flex items-center gap-1.5 text-sm text-amber-400 transition hover:text-amber-300">
                {{ __('Sign in to build a lesson') }}
            </a>
        </section>

        @if ($related->isNotEmpty())
            <section class="mt-12">
                <h2 class="font-history text-2xl font-light text-white">{{ __('More lessons') }}</h2>
                <ul class="mt-4 grid gap-4 sm:grid-cols-3">
                    @foreach ($related as $other)
                        <li>
                            <a href="{{ Seo::url('lesson', ['slug' => Seo::lessonSlug($other)]) }}"
                               class="block rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-200 transition hover:border-amber-500/40 hover:text-amber-300">
                                {{ $other->title ?: $other->topic }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
</x-layouts.landing>
