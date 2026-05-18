@props([
    'src',                        // audio URL (required)
    'mime'  => null,              // MIME type (auto-detected from src when null)
    'label' => null,              // optional label shown above the waveform
    'transcript' => null,         // optional transcript text, split into words and synced to progress
    'wordTimings' => [],          // optional per-word timing entries: [{text,start,end}]
])

@php
    $detectedMime = 'audio/mpeg';
    if (is_string($src) && str_ends_with(strtolower(parse_url($src, PHP_URL_PATH) ?? ''), '.m4a')) {
        $detectedMime = 'audio/mp4';
    }
    $resolvedMime = $mime ?: $detectedMime;
@endphp

@if($src)
<div
    x-data="wavePlayer('{{ $src }}', @js($transcript), @js($wordTimings))"
    x-init="init(); return () => destroy()"
    class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 space-y-2"
>
    @if($label)
        <p class="text-xs text-slate-500 font-medium truncate">{{ $label }}</p>
    @endif

    <div class="flex items-center gap-3">

        {{-- Play / Pause button --}}
        <button
            @click="toggle()"
            :disabled="!ready"
            :title="playing ? 'Pause' : 'Play'"
            class="flex-shrink-0 flex h-9 w-9 items-center justify-center rounded-full border transition-all
                   focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 focus:ring-offset-slate-900"
            :class="ready
                ? 'border-amber-500 bg-amber-500/10 hover:bg-amber-500/25 text-amber-400'
                : 'border-slate-700 text-slate-600 cursor-not-allowed'"
        >
            {{-- Loading spinner --}}
            <svg x-show="loading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            {{-- Play icon --}}
            <svg x-show="!loading && !playing" width="8" height="8" viewBox="0 0 8 8" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M0 0L8 4.19615L0 8V0Z" fill="currentColor"/>
            </svg>
            {{-- Pause icon --}}
            <svg x-show="!loading && playing" width="9" height="8" viewBox="0 0 9 8" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect width="3" height="8" fill="currentColor"/>
                <rect x="5.11328" width="3" height="8" fill="currentColor"/>
            </svg>
        </button>

        {{-- Waveform --}}
        <div class="relative flex-1 overflow-hidden rounded" style="min-height: 44px;">
            <div
                x-ref="waveform"
                class="h-full w-full"
                role="img"
                aria-label="Audio waveform"
            ></div>
            <div
                x-show="ready"
                class="pointer-events-none absolute inset-y-1 w-[2px] rounded-full bg-amber-300/90 shadow-[0_0_8px_rgba(251,191,36,0.65)] transition-[left] duration-75 ease-linear"
                :style="`left: calc(${progressPct}% - 1px)`"
            ></div>
        </div>

        {{-- Time remaining --}}
        <span
            x-text="time"
            class="flex-shrink-0 w-10 text-right text-xs tabular-nums text-slate-400"
        ></span>

    </div>

    <template x-if="words.length > 0">
        <p class="leading-relaxed text-sm">
            <template x-for="(word, idx) in words" :key="`${word}-${idx}`">
                <span
                    class="transition-opacity duration-100"
                    :class="idx === activeWordIndex ? 'opacity-100 text-amber-300' : 'opacity-80 text-slate-300'"
                    x-text="`${word} `"
                ></span>
            </template>
        </p>
    </template>

    <p x-show="error" x-text="errorMessage" class="text-xs text-rose-400"></p>
    <audio
        x-ref="nativeAudio"
        preload="metadata"
        class="hidden"
        aria-hidden="true"
        tabindex="-1"
    >
        <source src="{{ $src }}" type="{{ $resolvedMime }}">
    </audio>
</div>
@else
    {{-- No audio yet — muted placeholder --}}
    <div class="rounded-xl border border-dashed border-slate-700 bg-slate-900/40 px-4 py-3 flex items-center gap-3">
        <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full border border-slate-700 text-slate-600">
            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
        </span>
        <span class="text-xs text-slate-600 italic">No audio generated yet</span>
    </div>
@endif
