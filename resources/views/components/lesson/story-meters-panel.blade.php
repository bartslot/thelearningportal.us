@props(['metersDraft' => []])

{{-- Lesson-level class meters editor (story_game only). Keys are immutable in v1 —
     teachers rename labels, swap the emoji icon, and tune start values (0–100). --}}
<div class="collapse collapse-arrow mt-6 rounded-box border border-slate-700/50 bg-base-200/60">
    <input type="checkbox" aria-label="{{ __('Toggle class meters panel') }}" />
    <div class="collapse-title min-h-0 py-3 text-[10px] font-semibold uppercase tracking-widest text-slate-400">
        {{ __('Class meters') }}
    </div>
    <div class="collapse-content space-y-2">
        @foreach ($metersDraft as $i => $meter)
            <div class="flex items-center gap-2">
                <input type="text" maxlength="8"
                       wire:model.blur="metersDraft.{{ $i }}.icon"
                       wire:change="saveMetersFromDraft"
                       aria-label="{{ __('Meter icon') }}"
                       class="input input-sm input-bordered w-12 shrink-0 bg-slate-900 text-center" />
                <input type="text" maxlength="24"
                       wire:model.blur="metersDraft.{{ $i }}.label"
                       wire:change="saveMetersFromDraft"
                       aria-label="{{ __('Meter label') }}"
                       class="input input-sm input-bordered min-w-0 flex-1 bg-slate-900" />
                <input type="number" min="0" max="100" step="1"
                       wire:model.blur="metersDraft.{{ $i }}.start"
                       wire:change="saveMetersFromDraft"
                       aria-label="{{ __('Meter start value') }}"
                       class="input input-sm input-bordered w-20 shrink-0 bg-slate-900 text-right" />
            </div>
        @endforeach
        <p class="text-[10px] text-slate-600">{{ __('Icon (emoji), label and start value (0–100). Meters can’t be added or removed.') }}</p>
    </div>
</div>
