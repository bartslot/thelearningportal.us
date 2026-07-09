@php
    /** @var \App\Models\LessonModule $module */
    $cfg = $module->config ?? [];
    $seedTermsText = implode("\n", $cfg['seed_terms'] ?? []);
@endphp

<div class="space-y-3">
    <label class="form-control w-full">
        <span class="label-text">{{ __('Prompt question') }}</span>
        <input type="text" class="input input-bordered w-full"
               value="{{ $cfg['question'] ?? '' }}"
               placeholder="{{ __('What do you already know about this topic?') }}"
               wire:change="updateModuleText({{ $module->id }}, 'question', $event.target.value)" />
    </label>

    <label class="form-control w-full">
        <span class="label-text">{{ __('Seed terms (optional, one per line, max :max)', ['max' => \App\Lessons\Modules\Types\PriorKnowledgeModule::MAX_SEED_TERMS]) }}</span>
        <textarea class="textarea textarea-bordered w-full" rows="4"
                  placeholder="{{ __('e.g. Rome&#10;Senate&#10;Legion') }}"
                  wire:change="updateModuleLines({{ $module->id }}, 'seed_terms', $event.target.value)">{{ $seedTermsText }}</textarea>
    </label>

    <p class="text-xs text-base-content/60">
        {{ __('The question shows big on the slide; seed terms stay hidden until you reveal them in class.') }}
    </p>
</div>
