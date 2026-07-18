@props(['integrity' => null, 'source' => 'web'])
<span class="flex shrink-0 gap-1 text-sm" aria-label="{{ __('Quiz integrity signals') }}">
    @if ($source === 'paper')
        <span class="tooltip tooltip-left shrink-0 cursor-help" data-tip="{{ __('Submitted on a paper answer sheet') }}" tabindex="0" role="img" aria-label="{{ __('Submitted on a paper answer sheet') }}">
            <x-icons.document class="inline-block h-3.5 w-3.5" />
        </span>
    @endif
    @if (($integrity['rapid_guesses'] ?? 0) >= 2)
        <span class="tooltip tooltip-left shrink-0 cursor-help" data-tip="{{ __(':n answers took under 2 seconds', ['n' => $integrity['rapid_guesses']]) }}" tabindex="0" role="img" aria-label="{{ __(':n answers took under 2 seconds', ['n' => $integrity['rapid_guesses']]) }}">
            <x-icons.bolt class="inline-block h-3.5 w-3.5" />
        </span>
    @endif
    @if (($integrity['focus_drops'] ?? 0) >= 2)
        <span class="tooltip tooltip-left shrink-0 cursor-help" data-tip="{{ __('Left the quiz tab :n times', ['n' => $integrity['focus_drops']]) }}" tabindex="0" role="img" aria-label="{{ __('Left the quiz tab :n times', ['n' => $integrity['focus_drops']]) }}">
            <x-icons.eye-slash class="inline-block h-3.5 w-3.5" />
        </span>
    @endif
    @if (($integrity['same_letter_streak'] ?? 0) >= 4)
        <span class="tooltip tooltip-left shrink-0 cursor-help" data-tip="{{ __('Picked the same answer position :n times in a row', ['n' => $integrity['same_letter_streak']]) }}" tabindex="0" role="img" aria-label="{{ __('Picked the same answer position :n times in a row', ['n' => $integrity['same_letter_streak']]) }}">
            <x-icons.arrow-path class="inline-block h-3.5 w-3.5" />
        </span>
    @endif
</span>
