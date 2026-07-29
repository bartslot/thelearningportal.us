{{-- Help centre. Same explanations as the welcome tour, at full length, illustrated with
     screenshots captured in the reader's own language (see App\Support\HelpMedia) and searchable.
     Both surfaces read App\Support\HelpGuide, so they cannot drift apart. --}}
@php
    $topics = $this->topics();
    $shortcuts = $this->shortcuts();
    $searching = trim($this->search) !== '';
@endphp

<div
    class="space-y-10"
    x-data="{
        lightbox: { open: false, src: null, caption: '' },
        show(src, caption) { this.lightbox = { open: true, src, caption }; document.body.style.overflow = 'hidden' },
        hide() { this.lightbox.open = false; document.body.style.overflow = '' },
    }"
    x-on:keydown.escape.window="hide()"
>
    <header class="flex flex-col gap-6 border-b border-slate-800 pb-8 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-[0.18em] text-amber-400">{{ __('Help') }}</p>
            <h1 class="mt-2 font-history text-4xl font-light tracking-tight text-slate-100 sm:text-5xl">
                {{ __('How the portal works') }}
            </h1>
            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-slate-400">
                {{ __('Everything from your first lesson to reading the results. Search, pick a subject on the left, or read it straight through.') }}
            </p>
        </div>

        @if ($this->canRunTour())
            <button type="button" wire:click="startTutorial" class="btn btn-outline btn-sm shrink-0 border-slate-700 text-slate-300 hover:border-amber-500/60 hover:text-amber-300">
                <x-icons.play class="h-4 w-4" />
                {{ $this->tourResumable() ? __('Resume the welcome tour') : __('Replay the welcome tour') }}
            </button>
        @endif
    </header>

    {{-- Search over every explanation on this page, and over the places in the app they describe. --}}
    <div class="relative max-w-xl">
        <label for="help-search" class="sr-only">{{ __('Search help') }}</label>
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <x-icons.magnifying-glass class="h-5 w-5" />
        </span>
        <input
            id="help-search"
            type="search"
            wire:model.live.debounce.250ms="search"
            placeholder="{{ __('Search help, for example: lesson code, results, class') }}"
            autocomplete="off"
            class="w-full rounded-xl border border-slate-700 bg-slate-900 py-3 pl-11 pr-11 text-sm text-slate-100 placeholder:text-slate-500 focus:border-amber-500/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/40"
        >
        @if ($searching)
            <button type="button" wire:click="clearSearch" aria-label="{{ __('Clear search') }}"
                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-500 transition hover:text-slate-200">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
        @endif
    </div>

    @if ($searching && $shortcuts !== [])
        <section aria-labelledby="help-shortcuts-heading">
            <h2 id="help-shortcuts-heading" class="text-xs uppercase tracking-wider text-slate-500">{{ __('Go straight there') }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($shortcuts as $shortcut)
                    <a href="{{ route($shortcut['route']) }}"
                       class="group flex items-start gap-3 rounded-xl border border-slate-800 bg-slate-900/40 p-4 transition hover:-translate-y-0.5 hover:border-amber-500/30 hover:bg-slate-900/70">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-amber-400">
                            <x-dynamic-component :component="'icons.'.$shortcut['icon']" class="h-4 w-4" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-100 group-hover:text-amber-300">{{ $shortcut['label'] }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-slate-500">{{ $shortcut['description'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($searching && ! $this->hasResults())
        <div class="rounded-xl border border-dashed border-slate-800 px-6 py-12 text-center">
            <x-icons.magnifying-glass class="mx-auto h-7 w-7 text-slate-600" />
            <p class="mt-3 text-sm font-medium text-slate-300">{{ __('Nothing here matches ":search"', ['search' => $this->search]) }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ __('Try a shorter word, or read the subjects below.') }}</p>
            <button type="button" wire:click="clearSearch" class="mt-4 text-sm text-amber-400 transition hover:text-amber-300">
                {{ __('Show everything again') }}
            </button>
        </div>
    @endif

    <div class="grid gap-10 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] lg:gap-14">
        <nav class="lg:sticky lg:top-24 lg:self-start" aria-label="{{ __('Help topics') }}">
            <p class="text-xs uppercase tracking-wider text-slate-500">
                {{ $searching ? __('Matching subjects') : __('Subjects') }}
            </p>
            <ul class="mt-4 space-y-1">
                @forelse ($topics as $topic)
                    <li>
                        <a href="#{{ $topic['id'] }}"
                           class="flex items-start gap-2.5 rounded-lg px-2 py-2 text-sm text-slate-400 transition hover:bg-slate-900/60 hover:text-amber-300">
                            <x-dynamic-component :component="'icons.'.$topic['icon']" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $topic['title'] }}</span>
                            @if (($topic['audience'] ?? 'all') === 'admin')
                                <span class="ml-auto shrink-0 rounded bg-rose-400/10 px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-rose-300">{{ __('Admin') }}</span>
                            @endif
                        </a>
                    </li>
                @empty
                    <li class="px-2 py-2 text-sm text-slate-600">{{ __('No matching subjects.') }}</li>
                @endforelse
            </ul>
        </nav>

        <div class="min-w-0 space-y-14">
            @foreach ($topics as $topic)
                @php $sections = $topic['matched_sections'] ?? $topic['sections']; @endphp
                <section id="{{ $topic['id'] }}" class="scroll-mt-24" aria-labelledby="{{ $topic['id'] }}-heading">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-amber-400">
                            <x-dynamic-component :component="'icons.'.$topic['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 id="{{ $topic['id'] }}-heading" class="flex flex-wrap items-center gap-2 font-history text-2xl font-light text-slate-100">
                                {{ $topic['title'] }}
                                @if (($topic['audience'] ?? 'all') === 'admin')
                                    <span class="rounded bg-rose-400/10 px-1.5 py-0.5 font-sans text-[0.6rem] font-semibold uppercase tracking-wide text-rose-300">{{ __('Admin only') }}</span>
                                @endif
                            </h2>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-400">{{ $topic['summary'] }}</p>
                        </div>
                    </div>

                    <div class="mt-6 space-y-7 border-l border-slate-800 pl-5 sm:ml-12 sm:pl-6">
                        @foreach ($sections as $section)
                            <div>
                                <h3 class="text-sm font-semibold text-slate-200">{{ $section['heading'] }}</h3>

                                @if ($section['body'])
                                    <p class="mt-2 text-sm leading-relaxed text-slate-400">{{ $section['body'] }}</p>
                                @endif

                                @if (! empty($section['steps']))
                                    <ul class="mt-3 space-y-2">
                                        @foreach ($section['steps'] as $stepText)
                                            <li class="flex items-start gap-2.5 text-sm leading-relaxed text-slate-400">
                                                <span class="mt-[0.45rem] h-1 w-1 shrink-0 rounded-full bg-amber-400/70"></span>
                                                <span>{{ $stepText }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($section['image_url'])
                                    {{-- Click opens the same picture full size; the button is what
                                         carries the keyboard and screen-reader affordance. --}}
                                    <figure class="mt-4" data-help-figure>
                                        <button type="button"
                                                @click="show(@js($section['image_url']), @js($section['heading']))"
                                                class="group relative block w-full overflow-hidden rounded-xl border border-slate-800 bg-slate-950 transition hover:border-amber-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                                aria-label="{{ __('Enlarge the picture of :subject', ['subject' => $section['heading']]) }}">
                                            <img src="{{ $section['image_url'] }}" alt="{{ $section['heading'] }}"
                                                 loading="lazy" decoding="async"
                                                 class="block w-full transition duration-300 ease-[cubic-bezier(0.215,0.61,0.355,1)] group-hover:scale-[1.01] motion-reduce:transition-none">
                                            <span class="absolute bottom-2 right-2 flex items-center gap-1.5 rounded-lg bg-slate-950/80 px-2 py-1 text-[0.65rem] text-slate-300 opacity-0 backdrop-blur-sm transition group-hover:opacity-100 group-focus-visible:opacity-100">
                                                <x-icons.magnifying-glass class="h-3.5 w-3.5" />
                                                {{ __('Enlarge') }}
                                            </span>
                                        </button>
                                    </figure>
                                @endif
                            </div>
                        @endforeach

                        @if ($topic['link'])
                            <a href="{{ route($topic['link']['route']) }}"
                               class="inline-flex items-center gap-1.5 text-sm text-amber-400 transition hover:text-amber-300">
                                {{ $topic['link']['label'] }}
                                <x-icons.chevron-right class="h-4 w-4" />
                            </a>
                        @endif
                    </div>
                </section>
            @endforeach

            @unless ($searching)
                <section class="rounded-xl border border-dashed border-slate-800 px-6 py-7">
                    <h2 class="text-sm font-semibold text-slate-200">{{ __('Still stuck?') }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-400">
                        {{ __('If something does not work the way this page describes, mail us and tell us what you were doing. That is the fastest way to get it fixed.') }}
                    </p>
                    {{-- Same address as the public site footer. --}}
                    <a href="mailto:info@thelearningportal.us" class="mt-3 inline-flex items-center gap-1.5 text-sm text-amber-400 transition hover:text-amber-300">
                        info@thelearningportal.us
                        <x-icons.chevron-right class="h-4 w-4" />
                    </a>
                </section>
            @endunless
        </div>
    </div>

    {{-- Lightbox. Closes on the corner X, Esc and a backdrop click, like every modal here. --}}
    <div x-show="lightbox.open" x-cloak
         class="fixed inset-0 z-[70] flex items-center justify-center p-4 sm:p-8"
         role="dialog" aria-modal="true" :aria-label="lightbox.caption"
         x-transition:enter="transition duration-200 ease-[cubic-bezier(0.215,0.61,0.355,1)]"
         x-transition:enter-start="opacity-0"
         x-transition:leave="transition duration-150 ease-[cubic-bezier(0.55,0.055,0.675,0.19)]"
         x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-slate-950/90 backdrop-blur-sm" @click="hide()"></div>

        {{-- The picture is bigger than most screens, so it is bounded by the viewport rather than
             by its own size: it scales down to fit and never pushes the caption off-screen. --}}
        <div class="relative flex max-h-full w-full max-w-6xl flex-col overflow-hidden">
            <div class="flex shrink-0 items-start justify-between gap-4 pb-3">
                <p class="text-sm text-slate-300" x-text="lightbox.caption"></p>
                <button type="button" @click="hide()" aria-label="{{ __('Close') }}"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-white/10 hover:text-slate-200">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>
            <img :src="lightbox.src" :alt="lightbox.caption"
                 class="mx-auto max-h-[calc(100vh-9rem)] w-auto max-w-full rounded-xl border border-slate-700 object-contain shadow-2xl">
        </div>
    </div>
</div>
