{{-- One WordPress article, rendered in this app's design.
     The body is author-written HTML from our own WordPress, so it is printed unescaped; it never
     contains visitor input. --}}
@php
    use App\Support\Seo;
@endphp

<x-layouts.landing :title="$article['title']">
    <x-slot:head>
        <x-seo
            page="article"
            :params="['slug' => $article['slug']]"
            :title="$article['title']"
            :description="$article['excerpt']"
            type="article"
        />
    </x-slot:head>

    <main class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <nav class="text-xs text-slate-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ Seo::url('articles') }}" class="hover:text-amber-300">{{ __('Articles') }}</a>
            <span class="mx-2">/</span>
            <span class="text-slate-400">{{ $article['title'] }}</span>
        </nav>

        <article class="mt-6">
            <h1 class="font-history text-4xl font-light tracking-tight text-white sm:text-5xl">{{ $article['title'] }}</h1>
            @if ($article['published_at'])
                <p class="mt-3 text-sm text-slate-500">
                    <time datetime="{{ $article['published_at']->toDateString() }}">
                        {{ $article['published_at']->isoFormat('LL') }}
                    </time>
                </p>
            @endif

            <div class="wp-content mt-8 space-y-5 text-base leading-relaxed text-slate-300">
                {!! $article['html'] !!}
            </div>
        </article>

        <aside class="mt-14 rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
            <h2 class="text-base font-semibold text-white">{{ __('Try a lesson') }}</h2>
            <p class="mt-2 text-sm leading-relaxed text-slate-300">
                {{ __('Every lesson in the library is narrated and free to play in the browser.') }}
            </p>
            <a href="{{ Seo::url('lessons') }}"
               class="mt-4 inline-flex items-center gap-2 rounded-lg bg-amber-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-amber-300">
                {{ __('Browse the lesson library') }}
            </a>
        </aside>
    </main>
</x-layouts.landing>
