@props(['scene', 'meters' => []])

{{-- Story-game "Game effects" panel — shown only for branch OPTION scenes of a
     story_game lesson. All fields autosave via EditsStoryGame::saveStoryEffectsDraft(). --}}
<div class="mt-4 space-y-3 rounded-box border border-amber-500/30 bg-base-200/60 p-3">
    <div>
        <h4 class="text-xs font-semibold uppercase tracking-widest text-amber-300">{{ __('Game effects') }}</h4>
        <p class="mt-1 text-[11px] text-slate-500">{{ __('What this choice does to the class meters, and what the game master says next.') }}</p>
    </div>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Choice label') }}</span>
        <input type="text" maxlength="60"
               wire:model.blur="storyEffectsDraft.branch_choice_label"
               wire:change="saveStoryEffectsDraft"
               placeholder="{{ __('Button text students see…') }}"
               class="input input-sm input-bordered mt-1 bg-slate-900" />
    </label>

    <div class="space-y-2">
        <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Meter effects (−25 to +25)') }}</span>
        @foreach ($meters as $meter)
            <label class="flex items-center justify-between gap-2">
                <span class="truncate text-sm text-slate-300">{{ $meter['icon'] }} {{ $meter['label'] }}</span>
                <input type="number" min="-25" max="25" step="1"
                       wire:model.blur="storyEffectsDraft.deltas.{{ $meter['key'] }}"
                       wire:change="saveStoryEffectsDraft"
                       aria-label="{{ $meter['label'] }}"
                       class="input input-sm input-bordered w-20 shrink-0 bg-slate-900 text-right" />
            </label>
        @endforeach
    </div>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Consequence line') }}</span>
        <textarea rows="2" maxlength="240"
                  wire:model.blur="storyEffectsDraft.consequence_line"
                  wire:change="saveStoryEffectsDraft"
                  placeholder="{{ __('Game-master line shown right after this choice…') }}"
                  class="textarea textarea-sm textarea-bordered mt-1 w-full bg-slate-900"></textarea>
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Historical note') }}</span>
        <textarea rows="2" maxlength="240"
                  wire:model.blur="storyEffectsDraft.historical_note"
                  wire:change="saveStoryEffectsDraft"
                  placeholder="{{ __('What really happened — shown on game over…') }}"
                  class="textarea textarea-sm textarea-bordered mt-1 w-full bg-slate-900"></textarea>
    </label>
</div>
