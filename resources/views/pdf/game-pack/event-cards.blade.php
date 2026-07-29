{{-- Event cards — one per branch group: the choice question + both option labels.
     For offline play without a screen. --}}
<div class="page-break"></div>
<div class="sheet-band">
    <div class="wordmark">History Portal · Spelpakket</div>
    <div class="sheet-title">{{ __('Gebeurteniskaarten') }}</div>
    <div class="sheet-note">{{ __('Eén kaart per keuzemoment. Lees hardop voor en laat het team kiezen.') }}</div>
</div>

@forelse ($events as $event)
    <div class="event-card">
        <div class="event-eyebrow">{{ __('Keuzemoment') }} {{ $event['group'] }}</div>
        @if ($event['question'] !== '')
            <div class="event-question">{{ $event['question'] }}</div>
        @endif
        @foreach ($event['options'] as $i => $option)
            <div class="event-option">
                <span class="event-option-letter">{{ chr(65 + $i) }}.</span> {{ $option }}
            </div>
        @endforeach
    </div>
@empty
    <p class="empty-note">{{ __('Nog geen keuzemomenten gegenereerd voor deze les.') }}</p>
@endforelse
