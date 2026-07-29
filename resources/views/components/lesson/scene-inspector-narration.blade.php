@props(['scene' => null])

@php
    $isGenerating = $scene->status === 'generating';

    // A scene the teacher added by hand has no script yet, and the Script box lives inside the
    // collapsed "Scene details" disclosure — so the inspector looked like it had nowhere to write
    // the story at all, next to a canvas saying "No narration yet for this scene." Start the
    // disclosure OPEN until there is a script, then let it collapse as designed.
    $needsScript = trim((string) $scene->script_segment) === '';
@endphp

<div class="space-y-3 text-sm">
    {{-- Format panel (Figma): the minimal scene-view + background controls take the top. --}}
    <x-lesson.skybox-controls :scene="$scene" />

    {{-- Scene details — collapsed by default so the Format panel stays minimal (Figma).
         Year, location, caption, the editable script + re-narrate, and delete live here. --}}
    {{-- The wizard polls every three seconds. Ignore attributes on this native disclosure so
         Livewire never removes its browser-managed `open` state mid-edit; its fields still
         receive normal Livewire updates, and switching scenes replaces this keyed inspector. --}}
    {{-- Blade has @checked/@selected/@disabled but NO @open directive — it would emit a literal
         `@open(...)` attribute and the disclosure would stay shut. --}}
    <details wire:ignore.self @if ($needsScript) open @endif class="border-t border-slate-700/50 pt-2">
        <summary class="cursor-pointer list-none text-[10px] font-semibold uppercase tracking-widest text-slate-500 transition hover:text-slate-300">
            {{ __('Scene details') }}
            @if ($needsScript)
                <span class="ml-1 normal-case tracking-normal text-amber-400/80">{{ __('add the story text') }}</span>
            @endif
        </summary>
        <div class="mt-3 space-y-3">
            <label class="form-control">
                <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Chapter name') }}</span>
                <input type="text" wire:model.blur="selectedScene.chapter_name" wire:change="saveSelected"
                       placeholder="{{ $scene->chapterName() }}"
                       class="input input-sm input-bordered bg-slate-900 mt-1" />
                <span class="mt-1 text-[10px] text-slate-500">{{ __('Shown in the player chapter bar. Auto-named from the narration; edit to taste.') }}</span>
            </label>

            <label class="form-control">
                <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Era') }}</span>
                <input type="text" wire:model.blur="selectedScene.year" wire:change="saveSelected"
                       class="input input-sm input-bordered bg-slate-900 mt-1" />
            </label>

            <label class="form-control">
                <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Caption location') }}</span>
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

            <div class="space-y-1">
                <p class="text-xs uppercase tracking-wider text-slate-400">{{ __('Script') }}</p>
                <textarea wire:model.blur="selectedScene.script_segment" wire:change="saveSelected" rows="8"
                          class="textarea textarea-sm textarea-bordered bg-slate-900 w-full"></textarea>
                <div class="flex flex-wrap gap-2">
                    @if ($scene->hasFreshAudio() && ! $isGenerating)
                        <button type="button"
                                wire:click="playSelected"
                                class="btn btn-xs border-0 bg-amber-500 text-slate-950 hover:bg-amber-400 inline-flex items-center gap-1.5">
                            <x-icons.play class="h-3 w-3" />
                            <span>{{ __('Play') }}</span>
                        </button>
                    @else
                        <button type="button"
                                wire:click="regenerate({{ $scene->id }}, 'audio')"
                                wire:loading.attr="disabled" wire:target="regenerate"
                                @disabled($isGenerating)
                                class="btn btn-xs border-0 bg-amber-500 text-slate-950 hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-50 inline-flex items-center gap-1.5">
                            @if ($isGenerating)
                                <x-icons.spinner class="h-3 w-3 animate-spin" />
                                <span>{{ __('Re-narrating…') }}</span>
                            @else
                                <x-icons.regenerate class="h-3 w-3" />
                                <span>{{ __('Re-narrate') }}</span>
                            @endif
                        </button>
                        @if ($scene->script_segment && $scene->audio_path && ! $isGenerating)
                            <span class="self-center text-xs text-slate-400">{{ __('script changed, re-narrate to refresh the audio') }}</span>
                        @endif
                    @endif

                    {{-- Summarize the narration into a bullet list over a half-screen backing panel. --}}
                    <button type="button"
                            wire:click="summarizeScriptToList({{ $scene->id }})"
                            wire:loading.attr="disabled" wire:target="summarizeScriptToList"
                            @disabled(empty($scene->script_segment))
                            class="btn btn-xs btn-outline border-slate-600 text-slate-300 hover:border-amber-400 hover:text-amber-300 disabled:cursor-not-allowed disabled:opacity-50 inline-flex items-center gap-1.5"
                            title="{{ __('Summarize the script into a bullet list over a half-screen backing panel') }}">
                        <span wire:loading wire:target="summarizeScriptToList"><x-icons.spinner class="h-3 w-3 animate-spin" /></span>
                        <svg wire:loading.remove wire:target="summarizeScriptToList" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3">
                            <path stroke-linecap="round" d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="3.5" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="3.5" cy="18" r="1.2" fill="currentColor" stroke="none"/>
                        </svg>
                        <span wire:loading.remove wire:target="summarizeScriptToList">{{ __('Summarize to list') }}</span>
                        <span wire:loading wire:target="summarizeScriptToList">{{ __('Summarizing…') }}</span>
                    </button>
                </div>
            </div>

            <button type="button" wire:click="deleteScene({{ $scene->id }})"
                    wire:confirm="{{ __('Delete this scene?') }}"
                    class="text-xs text-rose-300 underline transition hover:text-rose-200">{{ __('Delete scene') }}</button>
        </div>
    </details>
</div>
