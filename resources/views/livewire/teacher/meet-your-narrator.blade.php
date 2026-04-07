@php
    $avatar   = $this->activeAvatar;
    $feedback = $this->feedback;
@endphp

{{-- Only render if there's an active avatar and the teacher hasn't responded yet --}}
@if($avatar && $feedback && !$hasResponded && !$dismissed)
<div
    wire:init="generateGreeting"
    x-data="{ audioPlayed: false }"
    class="relative rounded-3xl border border-amber-700/30 bg-gradient-to-br from-slate-900 to-slate-950
           shadow-2xl overflow-hidden"
>
    {{-- Ambient glow --}}
    <div class="absolute inset-0 bg-amber-500/3 pointer-events-none"></div>

    {{-- Dismiss button --}}
    <button
        wire:click="dismiss"
        class="absolute top-4 right-4 text-slate-600 hover:text-slate-400 transition-colors z-10"
        title="Dismiss"
    >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>

    <div class="p-6 sm:p-8">
        <div class="flex flex-col sm:flex-row items-center gap-6 sm:gap-8">

            {{-- ── Portrait ───────────────────────────────────────────────── --}}
            <div class="flex-shrink-0 relative">
                @if($avatar->portraitUrl())
                    <img
                        src="{{ $avatar->portraitUrl() }}"
                        alt="{{ $avatar->name }}"
                        class="h-32 w-32 rounded-2xl object-cover border-2 border-amber-500/40 shadow-xl
                               sm:h-40 sm:w-40"
                    >
                @else
                    <div class="h-32 w-32 sm:h-40 sm:w-40 rounded-2xl bg-slate-800 border-2 border-slate-700
                                flex items-center justify-center text-5xl">
                        🎓
                    </div>
                @endif

                {{-- Animated speaking indicator --}}
                @if($isGenerating)
                    <div class="absolute -bottom-2 left-1/2 -translate-x-1/2 flex gap-1">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-bounce" style="animation-delay:0ms"></span>
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-bounce" style="animation-delay:100ms"></span>
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-bounce" style="animation-delay:200ms"></span>
                    </div>
                @endif
            </div>

            {{-- ── Content ─────────────────────────────────────────────────── --}}
            <div class="flex-1 min-w-0 text-center sm:text-left">

                <p class="text-xs uppercase tracking-widest text-amber-400 mb-2">Meet your narrator</p>

                {{-- Speech bubble --}}
                <div class="relative rounded-2xl border border-slate-700 bg-slate-800/60 p-4 mb-4">
                    <div class="hidden sm:block absolute -left-3 top-5 w-3 h-3 border-l border-b border-slate-700
                                bg-slate-800 rotate-45 -translate-y-1/2"></div>

                    @if($isGenerating)
                        <p class="text-sm text-slate-400 italic">Preparing your personalised greeting…</p>
                    @else
                        <p class="text-sm text-slate-200 leading-6 italic mb-3">"{{ $this->greetingText }}"</p>
                        <x-audio-player :src="$feedback->greetingAudioUrl()" />
                    @endif
                </div>

                {{-- Feedback buttons --}}
                @if(!$isGenerating)
                    <div class="space-y-2">
                        <p class="text-xs text-slate-400 font-medium">Do you like this voice?</p>
                        <div class="flex items-center gap-3">
                            <button
                                wire:click="like"
                                class="inline-flex items-center gap-2 rounded-xl border border-emerald-700 bg-emerald-950/40
                                       px-5 py-2.5 text-sm font-semibold text-emerald-300
                                       hover:bg-emerald-900/60 hover:border-emerald-500 transition-all"
                            >
                                <span class="text-lg">👍</span>
                                Yes, I love it!
                            </button>
                            <button
                                wire:click="dislike"
                                class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/40
                                       px-5 py-2.5 text-sm font-medium text-slate-400
                                       hover:border-rose-700 hover:text-rose-400 transition-all"
                            >
                                <span class="text-lg">👎</span>
                                Not really
                            </button>
                        </div>
                        <p class="text-xs text-slate-600">Your feedback helps us improve the narrator experience.</p>
                    </div>
                @endif

            </div>
        </div>
    </div>
</div>

{{-- Thank you state after responding --}}
@elseif($feedback && $hasResponded && !$dismissed)
<div class="rounded-2xl border border-slate-800 bg-slate-900/40 px-5 py-4 flex items-center gap-3">
    @if($avatar)
        <img src="{{ $avatar->portraitUrl() }}" alt="{{ $avatar->name }}"
             class="h-8 w-8 rounded-lg object-cover border border-slate-700 flex-shrink-0">
    @endif
    <p class="text-sm text-slate-400">
        {{ $feedback->liked ? '🎉 Great! The Professor will narrate your lessons.' : '📝 Noted — your feedback has been sent to the admin.' }}
    </p>
    <button wire:click="dismiss" class="ml-auto text-slate-600 hover:text-slate-400 transition-colors flex-shrink-0">✕</button>
</div>
@endif
