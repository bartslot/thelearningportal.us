{{-- Welcome tour: a spotlight card that walks a new teacher through the workspace.

     Copy is rendered here (server side) so it is translated like everything else; only the anchor
     name and preferred side are handed to Alpine, which owns placement and focus. The engine is
     resources/js/onboarding/tour.js, its placement maths resources/js/onboarding/tour-position.js.

     Closes the way every modal in this app closes: corner X, Esc, backdrop click. --}}
<div>
@if ($visible)
    @php
        $steps = $this->steps();
        $config = [
            'startStep' => $startStep,
            'steps' => array_map(fn ($step) => [
                'key' => $step['key'],
                'anchor' => $step['anchor'],
                'placement' => $step['placement'],
            ], $steps),
        ];
    @endphp

    <div
        data-welcome-tour
        x-data="onboardingTour(@js($config))"
        x-on:keydown.escape.window="dismiss()"
        x-on:keydown.arrow-right.window="next()"
        x-on:keydown.arrow-left.window="previous()"
    >
        {{-- Click catcher. It also carries the dimming for steps that have nothing to spotlight;
             anchored steps are dimmed by the ring's box-shadow instead, which leaves a clean hole. --}}
        <div class="fixed inset-0 z-[60] transition-colors duration-300 motion-reduce:transition-none"
             :class="spotlight ? '' : 'bg-slate-950/85 backdrop-blur-sm'"
             wire:click="dismiss({{ $startStep }})"
             aria-hidden="true"></div>

        {{-- The spotlight ring. Its huge spread shadow dims everything except the element inside.
             That shadow is a class, not an inline style: Alpine's :style binding replaces the whole
             style attribute on every reposition, which would wipe an inline shadow after one move. --}}
        <div class="pointer-events-none fixed z-[61] rounded-xl ring-2 ring-amber-400/90 shadow-[0_0_0_9999px_rgba(2,6,23,0.85)] transition-all duration-300 ease-[cubic-bezier(0.645,0.045,0.355,1)] motion-reduce:transition-none"
             :style="spotlightStyle"
             aria-hidden="true"></div>

        <div
            x-ref="card"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="`welcome-tour-title-${index}`"
            x-on:keydown="trapFocus($event)"
            class="lp-bg-card fixed z-[62] border border-amber-500/30 bg-base-200 shadow-2xl outline-none transition-[top,left,opacity] duration-200 ease-[cubic-bezier(0.215,0.61,0.355,1)] motion-reduce:transition-none"
            :class="[
                compact
                    ? 'inset-x-0 bottom-0 max-h-[85vh] w-full overflow-y-auto rounded-t-3xl border-b-0 p-6 pb-8'
                    : 'max-h-[calc(100vh-2rem)] w-[min(26rem,calc(100vw-2rem))] overflow-y-auto rounded-3xl p-7',
                ready ? 'opacity-100' : 'opacity-0',
            ]"
            :style="compact ? '' : cardStyle"
        >
            {{-- Pointer towards the spotlighted element. Hidden when the card is centred, pinned as
                 a sheet, or clamped so far that the arrow would miss its target. --}}
            <div x-show="!compact && arrow !== null" x-cloak aria-hidden="true"
                 class="absolute h-3 w-3 rotate-45 border border-amber-500/30 bg-base-200"
                 :class="{
                     'bottom-[-0.4rem] border-l-0 border-t-0': placement === 'top',
                     'top-[-0.4rem] border-b-0 border-r-0': placement === 'bottom',
                     'right-[-0.4rem] border-b-0 border-l-0': placement === 'left',
                     'left-[-0.4rem] border-r-0 border-t-0': placement === 'right',
                 }"
                 :style="arrowStyle"></div>

            <button type="button" wire:click="dismiss({{ $startStep }})" aria-label="{{ __('Close') }}"
                    class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-white/10 hover:text-slate-200">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>

            <p class="pr-8 text-xs font-medium uppercase tracking-[0.18em] text-amber-400">
                {{ __('Getting started') }}
                <span class="ml-2 text-slate-500" aria-live="polite"
                      x-text="`${index + 1} / ${total}`"></span>
            </p>

            @foreach ($steps as $index => $step)
                <div x-show="index === {{ $index }}" x-cloak class="mt-5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-amber-400">
                        <x-dynamic-component :component="'icons.'.$step['icon']" class="h-5 w-5" />
                    </span>

                    <h2 id="welcome-tour-title-{{ $index }}" class="mt-4 font-history text-2xl font-light leading-tight text-slate-100">
                        {{ $step['title'] }}
                    </h2>

                    <p class="mt-2.5 text-sm leading-relaxed text-slate-300">{{ $step['body'] }}</p>

                    @if (! empty($step['bullets']))
                        <ul class="mt-3 space-y-1.5">
                            @foreach ($step['bullets'] as $bullet)
                                <li class="flex items-start gap-2.5 text-sm text-slate-400">
                                    <span class="mt-[0.45rem] h-1 w-1 shrink-0 rounded-full bg-amber-400"></span>
                                    <span>{{ $bullet }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        @if ($step['cta'])
                            <button type="button" wire:click="finishAnd('{{ $step['key'] }}')" class="btn btn-primary btn-sm">
                                <x-icons.sparkles class="h-4 w-4" />
                                {{ $step['cta']['label'] }}
                            </button>
                        @endif

                        @if ($step['topic'])
                            <button type="button" wire:click="openHelp('{{ $step['topic'] }}')"
                                    class="text-sm text-amber-400 transition hover:text-amber-300">
                                {{ __('Read more in the help centre') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="mt-7 flex items-center justify-between gap-3 border-t border-slate-800 pt-4">
                <button type="button" @click="dismiss()" class="text-xs text-slate-500 transition hover:text-slate-300">
                    {{ __('Skip the tour') }}
                </button>

                <div class="flex items-center gap-1.5" role="tablist" aria-label="{{ __('Tour steps') }}">
                    @foreach ($steps as $dotIndex => $dotStep)
                        <button type="button" @click="goTo({{ $dotIndex }})" role="tab"
                                :aria-selected="index === {{ $dotIndex }}"
                                aria-label="{{ $dotStep['title'] }}"
                                class="h-1.5 rounded-full transition-all duration-200 ease-[cubic-bezier(0.215,0.61,0.355,1)] motion-reduce:transition-none"
                                :class="index === {{ $dotIndex }} ? 'w-5 bg-amber-400' : 'w-1.5 bg-slate-700 hover:bg-slate-600'"></button>
                    @endforeach
                </div>

                <div class="flex items-center gap-1.5">
                    <button type="button" @click="previous()" x-show="!isFirst" x-cloak
                            class="btn btn-ghost btn-sm text-slate-300">
                        {{ __('Back') }}
                    </button>
                    <button type="button" @click="next()" class="btn btn-primary btn-sm">
                        <span x-show="!isLast">{{ __('Next') }}</span>
                        <span x-show="isLast" x-cloak>{{ __('Finish') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
</div>
