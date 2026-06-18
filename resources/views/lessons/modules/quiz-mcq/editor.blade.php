@php
    /** @var \App\Models\LessonModule $module */
    $cfg = $module->config ?? [];
@endphp

<div class="space-y-3">
    <label class="form-control w-full">
        <span class="label-text">{{ __('Question') }}</span>
        <input type="text" class="input input-bordered w-full"
               value="{{ $cfg['question'] ?? '' }}" readonly />
    </label>

    <div class="space-y-2">
        <span class="label-text">{{ __('Answers') }}</span>
        @foreach (($cfg['answers'] ?? []) as $answer)
            <div class="flex items-center gap-2">
                <span class="badge">{{ $answer['label'] ?? '' }}</span>
                <input type="text" class="input input-bordered input-sm w-full"
                       value="{{ $answer['text'] ?? '' }}" readonly />
                @if (! empty($answer['is_correct']))
                    <span class="badge badge-success">{{ __('Correct') }}</span>
                @endif
            </div>
        @endforeach
    </div>

    <p class="text-xs text-base-content/60">
        {{ __('Editing is wired up in the Composer (K-2).') }}
    </p>
</div>
