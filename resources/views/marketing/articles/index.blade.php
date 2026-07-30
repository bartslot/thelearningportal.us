{{-- Articles written in WordPress on the marketing site, read here. --}}
@php
    use App\Support\Seo;

    $title = __('Notes on teaching history');
    $description = __('Writing from The Learning Portal about teaching history: what works in a classroom, where the sources come from, and what we are building next.');
@endphp

<x-layouts.landing :title="$title">
    <x-slot:head>
        <x-seo page="articles" :title="$title" :description="$description" :noindex="$noindex" />
    </x-slot:head>

    <main class="mx-auto max-w-4xl px-4 py-16 sm:px-6">
        <header class="max-w-2xl">
            <p class="text-xs font-medium uppercase tracking-[0.18em] text-amber-400">{{ __('Articles') }}</p>
            <h1 class="mt-3 font-history text-4xl font-light tracking-tight text-white sm:text-5xl">{{ $title }}</h1>
            <p class="mt-5 text-lg leading-relaxed text-slate-300">{{ $description }}</p>
        </header>

        @forelse ($articles as $article)
            <article class="mt-12 border-t border-slate-800 pt-8">
                <h2 class="text-xl font-semibold text-slate-100">
                    <a href="{{ Seo::url('article', ['slug' => $article['slug']]) }}" class="hover:text-amber-300">
                        {{ $article['title'] }}
                    </a>
                </h2>
                @if ($article['published_at'])
                    <p class="mt-1 text-xs text-slate-500">
                        <time datetime="{{ $article['published_at']->toDateString() }}">
                            {{ $article['published_at']->isoFormat('LL') }}
                        </time>
                    </p>
                @endif
                <p class="mt-3 leading-relaxed text-slate-300">{{ $article['excerpt'] }}</p>
                <a href="{{ Seo::url('article', ['slug' => $article['slug']]) }}"
                   class="mt-3 inline-flex text-sm text-amber-400 hover:text-amber-300">{{ __('Read more') }}</a>
            </article>
        @empty
            <div class="mt-12 rounded-2xl border border-dashed border-slate-800 px-6 py-12 text-center">
                <p class="text-slate-300">{{ __('Nothing published here yet.') }}</p>
                <p class="mt-2 text-sm text-slate-500">
                    {{ __('Articles written on the main site appear here automatically.') }}
                </p>
                <a href="{{ config('services.wordpress.url') }}" rel="noopener"
                   class="mt-5 inline-flex text-sm text-amber-400 hover:text-amber-300">
                    {{ __('Visit the main site') }}
                </a>
            </div>
        @endforelse
    </main>
</x-layouts.landing>
