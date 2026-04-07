@php
    $isTeacher = auth()->check() && auth()->user()->isTeacher();

    $socials = [
        ['label' => 'LinkedIn', 'href' => 'https://www.linkedin.com/in/bslot/'],
        ['label' => 'Instagram', 'href' => 'https://www.instagram.com/bart.travels/'],
    ];
@endphp

<footer id="contact" class="relative border-t border-white/10">
    <div
        class="relative -mt-px bg-cover bg-center"
        style="background-image: url('{{ asset('footer-bg.svg') }}'), linear-gradient(153.86deg, #02494c 0%, #016877 15.69%, #0d576d 48.9%, #004865 95.52%);"
    >
        <div class="section-container flex flex-col items-center justify-end py-12 text-center">
            <p class="text-xs uppercase tracking-[0.45em] text-sky-50/70">Contact</p>
            <h2 class="mt-3 font-history text-3xl text-white md:text-4xl">
                Feel free to connect on social media.
            </h2>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                @foreach ($socials as $social)
                    <a
                        href="{{ $social['href'] }}"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs uppercase tracking-[0.35em] text-white/90 transition hover:bg-white/20"
                    >
                        {{ $social['label'] }}
                    </a>
                @endforeach
            </div>

            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @if($isTeacher)
                    <a
                        href="{{ route('teacher.dashboard') }}"
                        class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-7 py-3 text-sm font-semibold text-white transition hover:border-white/25 hover:bg-white/10"
                    >
                        Dashboard
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-7 py-3 text-sm font-semibold text-white transition hover:border-white/25 hover:bg-white/10"
                    >
                        Sign in
                    </a>
                @endif
                <a
                    href="mailto:hello@bartslot.com"
                    class="inline-flex items-center rounded-full border border-white/15 bg-white px-7 py-3 text-sm font-semibold text-slate-950 transition hover:bg-slate-100"
                >
                    Let&apos;s talk
                </a>
            </div>

            <p class="mt-8 max-w-2xl text-sm leading-7 text-sky-50/80">
                This landing page was rebuilt from the React homepage into Blade components and Tailwind utilities for the Laravel application.
            </p>
        </div>
    </div>
</footer>
