{{-- Reusable full-screen splash: a big moment (lesson published, milestone reached).
     Usage:
       <x-splash-screen :title="__('Your lesson is now live!')" :subtitle="$lesson->title">
           <x-slot:actions> ...buttons... </x-slot:actions>
           ...optional extra content (e.g. a rating widget)...
       </x-splash-screen> --}}
@props(['title', 'subtitle' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'fixed inset-0 z-50 flex items-center justify-center']) }}>
    {{-- Dimmed backdrop over the preview canvas --}}
    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm"></div>

    <div class="relative mx-4 w-full max-w-lg rounded-3xl border border-amber-500/30 bg-base-200 p-8 text-center shadow-2xl lp-bg-card space-y-5">
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
