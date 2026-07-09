@php
    /** @var \App\Models\LessonModule $module */
    $cfg = $module->config ?? [];
    $takeawaysText = implode("\n", $cfg['takeaways'] ?? []);
@endphp

<div class="space-y-3">
    <label class="form-control w-full">
        <span class="label-text">{{ __('Heading') }}</span>
        <input type="text" class="input input-bordered w-full"
               value="{{ $cfg['heading'] ?? '' }}"
               placeholder="{{ __('What we learned') }}"
               wire:change="updateModuleText({{ $module->id }}, 'heading', $event.target.value)" />
    </label>

    <label class="form-control w-full">
        <span class="label-text">{{ __('Takeaways (one per line, max :max)', ['max' => \App\Lessons\Modules\Types\ConclusionModule::MAX_TAKEAWAYS]) }}</span>
        <textarea class="textarea textarea-bordered w-full" rows="5"
                  wire:change="updateModuleLines({{ $module->id }}, 'takeaways', $event.target.value)">{{ $takeawaysText }}</textarea>
    </label>

    <label class="form-control w-full">
        <span class="label-text">{{ __("What's next (optional)") }}</span>
        <input type="text" class="input input-bordered w-full"
               value="{{ $cfg['whats_next'] ?? '' }}"
               placeholder="{{ __('e.g. Next lesson: the fall of the Republic') }}"
               wire:change="updateModuleText({{ $module->id }}, 'whats_next', $event.target.value)" />
    </label>

    <p class="text-xs text-base-content/60">
        {{ __('Takeaways are prefilled from the lesson objectives when available — edit freely.') }}
    </p>
</div>
