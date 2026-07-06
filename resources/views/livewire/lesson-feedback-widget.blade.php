<div class="card bg-base-200/80 backdrop-blur border border-base-300 shadow-lg w-64">
    <div class="card-body p-4 gap-2">
        <span class="text-xs font-semibold opacity-70">{{ __('Rate this lesson') }}</span>

        <div class="rating rating-md">
            @for ($star = 1; $star <= 5; $star++)
                <input type="radio" name="lesson-rating-{{ $lesson->id }}"
                       class="mask mask-star-2 bg-amber-400"
                       aria-label="{{ $star }} {{ __('stars') }}"
                       wire:click="rate({{ $star }})"
                       @checked($rating === $star) />
            @endfor
        </div>

        @if ($rating > 0)
            <textarea wire:model.blur="comment" wire:change="save"
                      class="textarea textarea-sm textarea-bordered w-full"
                      rows="2"
                      placeholder="{{ __('What was boring? What worked?') }}"></textarea>
        @endif

        @if ($saved)
            <span class="text-xs text-success">{{ __('Thanks — feedback saved.') }}</span>
        @endif

        @error('rating')
            <span class="text-xs text-error">{{ $message }}</span>
        @enderror
    </div>
</div>
