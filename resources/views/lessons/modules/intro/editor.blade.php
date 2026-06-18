@php
    /** @var \App\Models\LessonModule $module */
    $cfg = $module->config ?? [];
@endphp

<div class="space-y-3">
    <label class="form-control w-full">
        <span class="label-text">{{ __('Heading') }}</span>
        <input type="text" class="input input-bordered w-full"
               value="{{ $cfg['heading'] ?? '' }}" readonly />
    </label>

    <label class="form-control w-full">
        <span class="label-text">{{ __('Subheading') }}</span>
        <input type="text" class="input input-bordered w-full"
               value="{{ $cfg['subheading'] ?? '' }}" readonly />
    </label>

    <p class="text-xs text-base-content/60">
        {{ __('Editing is wired up in the Composer (K-2).') }}
    </p>
</div>
