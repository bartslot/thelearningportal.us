@props([
    'state' => 'working',
])

@once
    <style>
        .history-time-machine {
            --machine-accent: #f2b84b;
            --machine-muted: #475569;
            position: relative;
            width: min(74vw, 21rem);
            aspect-ratio: 1;
            contain: layout paint;
        }

        .history-time-machine svg {
            display: block;
            width: 100%;
            height: 100%;
            overflow: visible;
        }

        .history-time-machine__echo,
        .history-time-machine__ring,
        .history-time-machine__sweep,
        .history-time-machine__particle,
        .history-time-machine__logo {
            transform-box: fill-box;
            transform-origin: center;
            will-change: transform, opacity;
        }

        .history-time-machine__echo {
            animation: history-portal-echo 3.2s cubic-bezier(.16, 1, .3, 1) infinite;
        }

        .history-time-machine__ring--outer {
            animation: history-rewind-ring 8s linear infinite;
        }

        .history-time-machine__ring--inner {
            animation: history-rewind-ring-reverse 5.5s linear infinite;
        }

        .history-time-machine__sweep {
            animation: history-era-sweep 2.8s linear infinite;
        }

        .history-time-machine__logo {
            animation: history-logo-breathe 2.4s cubic-bezier(.25, 1, .5, 1) infinite;
        }

        .history-time-machine__particle {
            animation: history-particle-rewind 2.7s cubic-bezier(.25, 1, .5, 1) infinite;
            animation-delay: var(--particle-delay);
        }

        .history-time-machine--stalled {
            --machine-accent: #fb923c;
        }

        .history-time-machine--stalled .history-time-machine__ring--outer,
        .history-time-machine--stalled .history-time-machine__ring--inner {
            animation-duration: 16s;
        }

        .history-time-machine--stalled .history-time-machine__sweep {
            animation: history-stalled-scan 2.4s ease-in-out infinite;
        }

        .history-time-machine--error {
            --machine-accent: #fb7185;
        }

        .history-time-machine--error .history-time-machine__ring,
        .history-time-machine--error .history-time-machine__sweep,
        .history-time-machine--error .history-time-machine__particle,
        .history-time-machine--error .history-time-machine__echo {
            animation: none;
        }

        .history-time-machine--error .history-time-machine__logo {
            animation: history-error-signal 1.8s ease-in-out infinite;
        }

        .history-time-machine--ready {
            --machine-accent: #6ee7b7;
        }

        @keyframes history-rewind-ring {
            to { transform: rotate(-360deg); }
        }

        @keyframes history-rewind-ring-reverse {
            to { transform: rotate(360deg); }
        }

        @keyframes history-era-sweep {
            to { transform: rotate(-360deg); }
        }

        @keyframes history-portal-echo {
            0% { opacity: .5; transform: scale(.78); }
            72%, 100% { opacity: 0; transform: scale(1.16); }
        }

        @keyframes history-logo-breathe {
            0%, 100% { opacity: .82; transform: scale(.97); }
            50% { opacity: 1; transform: scale(1.025); }
        }

        @keyframes history-particle-rewind {
            0% { opacity: 0; transform: rotate(var(--particle-angle)) translateX(8rem) scale(.55); }
            22% { opacity: .8; }
            100% { opacity: 0; transform: rotate(var(--particle-angle)) translateX(3.4rem) scale(1); }
        }

        @keyframes history-stalled-scan {
            0%, 100% { opacity: .2; transform: rotate(-18deg); }
            50% { opacity: .95; transform: rotate(18deg); }
        }

        @keyframes history-error-signal {
            0%, 100% { opacity: .68; transform: scale(.98); }
            50% { opacity: 1; transform: scale(1.02); }
        }

        @media (prefers-reduced-motion: reduce) {
            .history-time-machine *,
            .history-time-machine *::before,
            .history-time-machine *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
@endonce

<div {{ $attributes->class(['history-time-machine', 'history-time-machine--'.$state]) }}>
    <svg viewBox="0 0 320 320" role="img" aria-labelledby="history-time-machine-title history-time-machine-description">
        <title id="history-time-machine-title">{{ __('History Portal time machine') }}</title>
        <desc id="history-time-machine-description">{{ __('Animated clock rings move backwards while the lesson is being created.') }}</desc>

        <circle
            class="history-time-machine__echo"
            cx="160"
            cy="160"
            r="102"
            fill="none"
            stroke="var(--machine-accent)"
            stroke-width="1"
            opacity=".35"
        />

        <g class="history-time-machine__ring history-time-machine__ring--outer">
            <circle cx="160" cy="160" r="116" fill="none" stroke="var(--machine-muted)" stroke-width="1" stroke-dasharray="2 10" />
            <path d="M160 35v12M160 273v12M35 160h12M273 160h12" stroke="var(--machine-accent)" stroke-width="2" stroke-linecap="round" />
            <path d="m73 73 9 9m156 156 9 9M247 73l-9 9M82 238l-9 9" stroke="var(--machine-muted)" stroke-width="1.5" stroke-linecap="round" />
        </g>

        <g class="history-time-machine__ring history-time-machine__ring--inner">
            <circle cx="160" cy="160" r="88" fill="none" stroke="var(--machine-accent)" stroke-width="1.5" stroke-dasharray="34 8 4 8" opacity=".65" />
            <path d="M160 72 152 84h16Z" fill="var(--machine-accent)" />
            <path d="M160 248 152 236h16Z" fill="var(--machine-accent)" opacity=".55" />
        </g>

        <g class="history-time-machine__sweep">
            <path d="M160 160 93 92" stroke="var(--machine-accent)" stroke-width="2" stroke-linecap="round" />
            <circle cx="93" cy="92" r="4" fill="var(--machine-accent)" />
        </g>

        <g opacity=".42">
            <circle cx="160" cy="160" r="67" fill="#0f172a" stroke="var(--machine-muted)" />
            <path d="M118 160a42 42 0 1 0 12-29" fill="none" stroke="var(--machine-accent)" stroke-width="2" stroke-linecap="round" />
            <path d="m119 130 13 1-2 13" fill="none" stroke="var(--machine-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </g>

        <g class="history-time-machine__logo">
            <circle cx="160" cy="160" r="50" fill="#020617" stroke="var(--machine-accent)" stroke-width="1.5" />
            <image href="{{ asset('assets/logo.svg') }}" x="119" y="125" width="82" height="70" preserveAspectRatio="xMidYMid meet" />
        </g>

        @foreach ([12, 64, 118, 172, 226, 280] as $index => $angle)
            <circle
                class="history-time-machine__particle"
                cx="160"
                cy="160"
                r="{{ $index % 2 === 0 ? 2.2 : 1.4 }}"
                fill="var(--machine-accent)"
                style="--particle-angle: {{ $angle }}deg; --particle-delay: -{{ $index * .31 }}s"
            />
        @endforeach
    </svg>
</div>
