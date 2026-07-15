@props(['integrity' => null, 'source' => 'web'])
<span class="flex shrink-0 gap-1 text-sm">
    @if ($source === 'paper')<span title="{{ __('Paper sheet') }}"><x-icons.document class="w-3.5 h-3.5 inline-block" /></span>@endif
    @if (($integrity['rapid_guesses'] ?? 0) >= 2)<span title="{{ __(':n answers under 2 seconds', ['n' => $integrity['rapid_guesses']]) }}"><x-icons.bolt class="w-3.5 h-3.5 inline-block" /></span>@endif
    @if (($integrity['focus_drops'] ?? 0) >= 2)<span title="{{ __('Left the tab :n times', ['n' => $integrity['focus_drops']]) }}"><x-icons.eye-slash class="w-3.5 h-3.5 inline-block" /></span>@endif
    @if (($integrity['same_letter_streak'] ?? 0) >= 4)<span title="{{ __('Same answer position :n times in a row', ['n' => $integrity['same_letter_streak']]) }}"><x-icons.arrow-path class="w-3.5 h-3.5 inline-block" /></span>@endif
</span>
