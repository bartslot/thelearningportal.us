@props(['scene' => null, 'clips' => collect()])

@php
    $isGenerating = $scene->status === 'generating';
@endphp

<div class="space-y-3 text-sm">
    <h3 class="text-amber-300 font-semibold">Scene {{ $scene->order }}</h3>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Year</span>
        <input type="text" wire:model.blur="selectedScene.year" wire:change="saveSelected"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Location</span>
        <input type="text" wire:model.blur="selectedScene.location" wire:change="saveSelected"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    {{-- Show/hide the on-canvas caption (flag · title · year · location) for this scene. --}}
    <label class="my-1 flex cursor-pointer items-center justify-between gap-2 rounded-lg bg-slate-800/40 px-3 py-2">
        <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Caption') }}</span>
        <input type="checkbox" class="toggle toggle-sm toggle-warning shrink-0"
               @checked(! ($scene->config['hide_identity'] ?? false))
               wire:click="toggleCaption" />
    </label>

    {{-- Style is a GLOBAL lesson setting (Step 1 / chat preset), set once — no per-scene
         override (founder decision 2026-07-11; the old dropdown also showed stale options). --}}

    <x-lesson.skybox-controls :scene="$scene" />

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Script</p>
        <textarea wire:model.blur="selectedScene.script_segment" wire:change="saveSelected" rows="8"
                  class="textarea textarea-sm textarea-bordered bg-slate-900 w-full"></textarea>
        <div class="flex gap-2 flex-wrap">
            @if ($scene->hasFreshAudio() && ! $isGenerating)
                <button type="button"
                        wire:click="playSelected"
                        class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 inline-flex items-center gap-1.5">
                    <x-icons.play class="w-3 h-3" />
                    <span>Play</span>
                </button>
            @else
                <button type="button"
                        wire:click="regenerate({{ $scene->id }}, 'audio')"
                        wire:loading.attr="disabled" wire:target="regenerate"
                        @disabled($isGenerating)
                        class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                    @if ($isGenerating)
                        <x-icons.spinner class="w-3 h-3 animate-spin" />
                        <span>Re-narrating…</span>
                    @else
                        <x-icons.regenerate class="w-3 h-3" />
                        <span>Re-narrate</span>
                    @endif
                </button>
                @if ($scene->script_segment && $scene->audio_path && ! $isGenerating)
                    <span class="text-xs text-slate-400 self-center">script changed — re-narrate to refresh audio</span>
                @endif
            @endif

            {{-- Summarize the narration into a bullet list over a half-screen backing panel. --}}
            <button type="button"
                    wire:click="summarizeScriptToList({{ $scene->id }})"
                    wire:loading.attr="disabled" wire:target="summarizeScriptToList"
                    @disabled(empty($scene->script_segment))
                    class="btn btn-xs btn-outline border-slate-600 text-slate-300 hover:border-amber-400 hover:text-amber-300 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5"
                    title="{{ __('Summarize the script into a bullet list over a half-screen backing panel') }}">
                <span wire:loading wire:target="summarizeScriptToList"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                <svg wire:loading.remove wire:target="summarizeScriptToList" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                    <path stroke-linecap="round" d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="3.5" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="3.5" cy="18" r="1.2" fill="currentColor" stroke="none"/>
                </svg>
                <span wire:loading.remove wire:target="summarizeScriptToList">{{ __('Summarize to list') }}</span>
                <span wire:loading wire:target="summarizeScriptToList">{{ __('Summarizing…') }}</span>
            </button>
        </div>
    </div>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this scene?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">Delete scene</button>
</div>
