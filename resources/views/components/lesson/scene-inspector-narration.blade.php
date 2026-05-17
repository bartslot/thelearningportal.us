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

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Style</span>
        <select wire:model.live="selectedScene.image_style" wire:change="saveSelected"
                class="select select-sm select-bordered bg-slate-900 mt-1">
            @foreach (['realistic','sketched','painted','cinematic','comic','animation'] as $s)
                <option value="{{ $s }}">{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Animation</span>
        <select wire:model.live="selectedScene.animation_clip_id" wire:change="saveSelected"
                class="select select-sm select-bordered bg-slate-900 mt-1">
            <option value="">— none —</option>
            @foreach ($clips as $clip)
                <option value="{{ $clip->id }}">{{ $clip->name }} ({{ $clip->category }})</option>
            @endforeach
        </select>
    </label>

    <div class="space-y-1">
        <p class="text-xs uppercase tracking-wider text-slate-400">Skybox</p>
        <div class="flex items-center gap-3">
            @if ($scene->image_path)
                <img src="{{ asset('storage/' . $scene->image_path) }}" class="w-20 h-12 rounded object-cover" />
            @endif
            <button type="button"
                    wire:click="regenerate({{ $scene->id }}, 'image')"
                    wire:loading.attr="disabled" wire:target="regenerate"
                    @disabled($isGenerating)
                    class="btn btn-xs bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
                @if ($isGenerating)
                    <x-icons.spinner class="w-3 h-3 animate-spin" />
                    <span>Generating…</span>
                @else
                    <x-icons.regenerate class="w-3 h-3" />
                    <span>Regenerate</span>
                @endif
            </button>
        </div>
        <details class="text-xs">
            <summary class="cursor-pointer text-slate-400">Prompt</summary>
            <textarea wire:model.blur="selectedScene.image_prompt" wire:change="saveSelected" rows="3"
                      class="textarea textarea-sm textarea-bordered bg-slate-900 mt-1 w-full"></textarea>
        </details>
    </div>

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
        </div>
    </div>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this scene?"
            class="text-rose-300 hover:text-rose-200 text-xs underline mt-2">Delete scene</button>
</div>
