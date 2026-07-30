@props([
    'scene'    => null,
    'selected' => false,
    'wide'     => false,   // vertical rail: fill the rail width instead of fixed w-32
    'number'   => null,    // slide number badge (vertical rail)
    'editable' => false,   // Configure step: show the hover X that deletes the scene
    // Play step only: switch the stage in the browser from a pre-built payload instead of asking
    // the server what it already told us. Configure keeps the Livewire round trip, because
    // selecting a scene there really does change server-side editing state.
    'clientSwitch' => false,
])

{{-- data-thumb / data-thumb-selected let the rail's @container "compact" rules (timeline.blade)
     collapse this to just the centered number on a transparent background when the rail is narrow. --}}
@php
    // Stable accessible name — in compact mode the visible content is just an aria-hidden
    // number, so without this the button would announce as unlabeled.
    $sceneAriaLabel = trim(__('Scene').' '.($number ?? $scene->order)
        .($scene->year ? ', '.$scene->year : '')
        .($scene->location ? ' · '.$scene->location : ''));
    // The delete X is a SIBLING of the thumb, never a child: a button inside a button is invalid
    // markup and the inner one stops firing. data-scene-block is what the rail drags and reads the
    // running order from, so the wrapper takes over that job from the button.
    $canDelete = $editable && ! $clientSwitch;
@endphp
@if ($canDelete)
<div class="group/thumb relative" data-scene-block="{{ $scene->id }}" wire:key="scene-block-{{ $scene->id }}">
@endif
<button type="button"
        wire:key="scene-thumb-{{ $scene->id }}"
        @if ($clientSwitch)
            x-on:click="switchScene({{ $scene->id }})"
        @else
            wire:click="selectScene({{ $scene->id }})"
        @endif
        data-scene-id="{{ $scene->id }}"
        data-thumb
        aria-label="{{ $sceneAriaLabel }}"
        @if ($selected && ! $clientSwitch) data-thumb-selected aria-current="true" @endif
        @if ($clientSwitch)
            {{-- Alpine owns the highlight here, so the static ring classes are left off to avoid
                 fighting the binding. data-thumb-selected drives the compact-rail CSS above. --}}
            x-bind:data-thumb-selected="selected === {{ $scene->id }} ? '' : null"
            x-bind:aria-current="selected === {{ $scene->id }} ? 'true' : null"
            x-bind:class="selected === {{ $scene->id }}
                ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'
                : '{{ $scene->status === 'failed' ? 'ring-1 ring-rose-500/50' : 'ring-1 ring-slate-700/50 hover:ring-slate-500' }}'"
        @endif
        @class([
            'group relative shrink-0 aspect-video rounded-xl overflow-hidden transition-all',
            'w-full' => $wide,
            'w-32' => ! $wide,
            'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'        => $selected && ! $clientSwitch,
            'ring-1 ring-slate-700/50 hover:ring-slate-500'                    => ! $clientSwitch && ! $selected && $scene->status !== 'failed',
            'ring-1 ring-rose-500/50'                                          => ! $clientSwitch && $scene->status === 'failed',
        ])>
    {{-- Full thumbnail (image / year / place / badges). Hidden by the compact @container rule. --}}
    <span data-thumb-full class="contents">
    @if ($scene->kind === 'map')
        <div class="w-full h-full bg-sky-800/30 border border-sky-600/30 flex flex-col items-center justify-center text-white gap-1">
            <x-lesson.icon-map class="h-7 w-7 opacity-90" />
            <span class="text-[9px] font-bold uppercase tracking-widest">Map</span>
            <span class="text-[9px] opacity-70">{{ $scene->year ?? '—' }}</span>
        </div>
    @elseif ($scene->kind === 'game')
        <div class="w-full h-full bg-teal-700/30 border border-teal-600/30 flex flex-col items-center justify-center text-white gap-1">
            @switch($scene->game_type)
                @case('strategy') <x-lesson.icon-strategy class="h-7 w-7 opacity-90" /> @break
                @case('debate') <x-lesson.icon-debate class="h-6 w-6 opacity-90" /> @break
                @case('story_game') <x-lesson.icon-branching-story class="h-7 w-7 opacity-90" /> @break
                @default
                    {{-- Quiz (no bespoke icon): a checklist glyph. --}}
                    <svg class="h-6 w-6 opacity-90" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m-9 8h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
            @endswitch
            <span class="text-[9px] font-bold tracking-widest">{{ strtoupper($scene->game_type ?? 'game') }}</span>
            <span class="text-[9px] opacity-70">Seg {{ $scene->game_segment_index }}</span>
        </div>
    @elseif ($scene->kind === 'voyage')
        <div class="w-full h-full bg-indigo-800/30 border border-indigo-500/30 flex flex-col items-center justify-center text-white gap-1">
            <x-lesson.icon-voyage class="h-7 w-7 opacity-90" />
            <span class="text-[9px] font-bold uppercase tracking-widest">Voyage</span>
            <span class="text-[9px] opacity-70 truncate max-w-[90%]">{{ $scene->location ?? '—' }}</span>
        </div>
    @elseif ($scene->kind === 'gallery')
        <div class="w-full h-full bg-violet-800/30 border border-violet-500/30 flex flex-col items-center justify-center text-white gap-1">
            <x-lesson.icon-slideshow class="h-7 w-7 opacity-90" />
            <span class="text-[9px] font-bold uppercase tracking-widest">Slideshow</span>
            <span class="text-[9px] opacity-70 truncate max-w-[90%]">{{ $scene->location ?? '—' }}</span>
        </div>
    @else
        {{-- Placeholder sits underneath; a present image covers it. If the image 404s
             (generation failed / file removed) onerror hides it, revealing the placeholder
             instead of the browser's broken-image glyph. --}}
        <div class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-slate-800/60 text-slate-500">
            <x-lesson.icon-story class="h-6 w-6 opacity-80" />
            <span class="text-[9px] tracking-widest">{{ __('SCENE') }} {{ $scene->order }}</span>
        </div>
        @if ($scene->image_path)
            <img src="{{ asset('storage/' . $scene->image_path) }}"
                 onerror="this.style.display='none'"
                 class="relative w-full h-full object-cover" alt="" />
        @endif
        <span class="absolute bottom-0 inset-x-0 bg-black/55 text-[9px] text-white px-1 py-0.5 text-left truncate">
            {{ $scene->year ?? '—' }} · {{ $scene->location ?? '—' }}
        </span>
    @endif

    @if ($number !== null)
        <span class="absolute top-1 left-1 min-w-4 rounded bg-black/60 px-1 text-center text-[9px] font-semibold leading-4 text-white/80">
            {{ $number }}
        </span>
    @endif

    @if ($scene->status === 'failed')
        <span class="absolute top-1 right-1 w-4 h-4 rounded-full bg-rose-500 text-white text-[8px] flex items-center justify-center">!</span>
    @endif
    </span>

    {{-- Compact: just the scene number, centered on a transparent background. Revealed by the
         rail's @container rule when the rail is dragged narrow; amber when this scene is selected. --}}
    <span data-thumb-compact aria-hidden="true"
          class="pointer-events-none absolute inset-0 hidden items-center justify-center font-mono text-sm font-semibold tabular-nums text-slate-400">
        {{ $number ?? $scene->order }}
    </span>
</button>

@if ($canDelete)
    {{-- Hover (or keyboard focus) reveals it. data-no-drag so grabbing the X doesn't start a reorder.
         No wire:confirm: the toast it fires offers Undo, which is the gentler version of asking. --}}
    <button type="button"
            data-no-drag
            data-scene-delete="{{ $scene->id }}"
            wire:click.stop="deleteScene({{ $scene->id }})"
            wire:loading.attr="disabled"
            title="{{ __('Delete scene') }}"
            aria-label="{{ __('Delete scene') }}"
            class="absolute -right-1.5 -top-1.5 z-10 flex h-5 w-5 items-center justify-center rounded-full
                   border border-slate-600 bg-slate-900 text-slate-300 opacity-0 shadow-lg transition
                   hover:border-rose-500 hover:bg-rose-600 hover:text-white
                   focus-visible:opacity-100 group-hover/thumb:opacity-100">
        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>
</div>
@endif
