{{-- Every error page in the app.

     The default was a bare status line and nothing else: a teacher who hit one had no link, no
     button and no way back except the browser's own back arrow. Bart, after hitting a 403 on the
     Help page mid-demo: "We need better feedback on error pages. Has to have at least a link or
     button. And will auto redirect after 10 seconds to where the teacher came from."

     So: say what happened in one plain sentence, offer the way back as a real control, and take
     them there after ten seconds if they do nothing. The countdown is visible and cancellable —
     an unannounced redirect steals the page from someone still reading it, and a teacher reading
     an error message is usually about to tell a colleague what it said. --}}
@props(['title', 'message', 'code'])

<x-layouts.guest :title="$title">
    @php
        // Where "back" goes, best answer first: the page they came from (same host only, so a
        // crafted Referer cannot bounce a signed-in teacher off-site), then their own dashboard,
        // then the front door. Never the page that just failed.
        $referer = request()->headers->get('referer');
        $sameHost = $referer && parse_url($referer, PHP_URL_HOST) === request()->getHost();
        $notThisPage = $referer && rtrim($referer, '/') !== rtrim(request()->fullUrl(), '/');

        $back = $sameHost && $notThisPage
            ? $referer
            : (auth()->check() ? route('teacher.dashboard') : route('home'));

        $backLabel = $sameHost && $notThisPage
            ? __('Go back')
            : (auth()->check() ? __('Back to my lessons') : __('Back to the home page'));
    @endphp

    <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-8 text-center shadow-2xl backdrop-blur-sm">
        <p class="font-history text-5xl text-slate-700">{{ $code }}</p>

        <h1 class="mt-4 text-xl font-semibold text-slate-100">{{ $title }}</h1>

        <p class="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-slate-400">{{ $message }}</p>

        <div class="mt-7 flex flex-col items-center gap-3">
            <a href="{{ $back }}"
               class="w-full rounded-lg bg-amber-500 px-4 py-3 text-sm font-semibold text-slate-950
                      transition-colors hover:bg-amber-400 focus:outline-none focus:ring-2
                      focus:ring-amber-500 focus:ring-offset-2 focus:ring-offset-slate-900">
                {{ $backLabel }}
            </a>

            @auth
                <a href="{{ route('help.index') }}" class="text-sm text-amber-400 hover:underline">{{ __('Open the help centre') }}</a>
            @endauth
        </div>

        {{-- The countdown. Announced, and stoppable by anyone who wants to keep reading. --}}
        <p data-error-countdown class="mt-6 text-xs text-slate-500" hidden>
            {{-- Unescaped on purpose: the count sits INSIDE the sentence so it can be updated in
                 place, and a language that puts the number elsewhere still reads correctly. The
                 only interpolated value is our own span, never anything from the request. --}}
            {!! __('Taking you back in :seconds seconds.', ['seconds' => '<span data-error-seconds>10</span>']) !!}
            <button type="button" data-error-stay class="ml-1 underline hover:text-slate-300">{{ __('Stay here') }}</button>
        </p>
    </div>

    <script>
        (function () {
            var note = document.querySelector('[data-error-countdown]');
            var out  = document.querySelector('[data-error-seconds]');
            var stay = document.querySelector('[data-error-stay]');
            if (!note || !out) return;

            // Someone who cannot see the countdown cannot cancel it, so with reduced motion or
            // assistive-tech pacing in play we simply do not redirect. The button above is the
            // way out for them, and it is always there.
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            var left = 10;
            note.hidden = false;

            var tick = setInterval(function () {
                left -= 1;
                out.textContent = left;
                if (left <= 0) {
                    clearInterval(tick);
                    window.location.assign(@js($back));
                }
            }, 1000);

            stay && stay.addEventListener('click', function () {
                clearInterval(tick);
                note.remove();
            });
        })();
    </script>
</x-layouts.guest>
