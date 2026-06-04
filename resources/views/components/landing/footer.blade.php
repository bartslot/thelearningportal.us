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
        style="bg-blue-800"
    >
        <div class="section-container flex flex-col items-center justify-end py-12 text-center">
            <p class="text-sm uppercase tracking-[0.8em] text-sky-50/70">Contact</p>
            <h2 class="mt-3 font-history text-3xl text-white md:text-4xl">
                Feel free to connect on social media.
            </h2>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                @foreach ($socials as $social)
                    <a
                        href="{{ $social['href'] }}"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm uppercase text-white/90 transition hover:bg-white/20"
                    >
                        {{ $social['label'] }}
                    </a>
                @endforeach
            </div>

            <div class="mt-8 flex flex-wrap justify-center gap-4">
                
                <a
                    href="mailto:info@thelearningportal.us"
                    class="inline-flex items-center rounded-full border border-white/15 bg-white px-7 py-3 text-sm font-semibold text-slate-950 transition hover:bg-slate-100"
                >
                    Let&apos;s talk
                </a>
            </div>

            <p class="mt-8 max-w-2xl text-sm leading-7 text-sky-50/80">
                © 2026 History Portal, part of The Learning Portal. All rights reserved.
All lesson content, scripts, prompts, illustrations, images, animations, interface designs, games, downloadable materials, and platform content are owned by The Learning Portal or used under licence, unless stated otherwise. No part of this website or platform may be copied, reproduced, scraped, redistributed, sold, modified, or used to train AI systems without prior written permission.
            </p>
        </div>
    </div>
</footer>
