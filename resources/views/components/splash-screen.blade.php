{{-- Reusable full-screen splash: a big moment (lesson published, milestone reached).
     Usage:
       <x-splash-screen :title="__('Your lesson is now live!')" :subtitle="$lesson->title">
           <x-slot:actions> ...buttons... </x-slot:actions>
           ...optional extra content (e.g. a rating widget)...
       </x-splash-screen> --}}
{{-- `close` = a Livewire action name (e.g. "dismissPublishSplash"). When given, the splash becomes a
     standard closable modal: an X in the corner, Esc, and a backdrop click all fire it. --}}
@props(['title', 'subtitle' => null, 'icon' => null, 'close' => null])

<div {{ $attributes->merge(['class' => 'fixed inset-0 z-50 flex items-center justify-center']) }}
     @if($close) x-data x-on:keydown.escape.window="$wire.{{ $close }}()" @endif>
    {{-- Dimmed backdrop over the preview canvas (click to close when dismissible) --}}
    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" @if($close) wire:click="{{ $close }}" @endif></div>

    <div class="relative mx-4 w-full max-w-lg rounded-3xl border border-amber-500/30 bg-base-200 p-8 text-center shadow-2xl lp-bg-card space-y-5">
        @if($close)
            <button type="button" wire:click="{{ $close }}" aria-label="{{ __('Close') }}"
                    class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-white/10 hover:text-slate-200">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        @endif
        <div aria-hidden="true">
            @if($icon)
                {{ $icon }}
            @else
                <x-icons.sparkles class="w-8 h-8 text-amber-400 mx-auto" />
            @endif
        </div>

        <h2 class="text-3xl font-bold text-white leading-tight">{{ $title }}</h2>

        @if ($subtitle)
            <p class="text-base text-slate-300">{{ $subtitle }}</p>
        @endif

        @isset($actions)
            <div class="flex flex-wrap items-center justify-center gap-3 pt-1">
                {{ $actions }}
            </div>
        @endisset

        @if (trim((string) $slot) !== '')
            <div class="border-t border-white/10 pt-4">
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
