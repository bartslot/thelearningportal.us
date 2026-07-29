{{-- Meter tracker poster — ONE page, each meter a 10-step track with its start value marked. --}}
<div class="page-break"></div>
<div class="sheet-band">
    <div class="wordmark">History Portal · Spelpakket</div>
    <div class="sheet-title">{{ __('Meterposter') }}</div>
    <div class="sheet-note">{{ __('Hang op het bord. Verschuif de markering na elke keuze. Een meter op nul betekent einde run.') }}</div>
</div>

@forelse ($meters as $meter)
    @php
        // Map the 0-100 start value onto the nearest of $meterSteps steps (1-based).
        $startStep = max(1, (int) round($meter['start'] / 100 * $meterSteps));
    @endphp
    <div class="meter-block">
        <div class="meter-head">{{ $meter['icon'] }} {{ $meter['label'] }}</div>
        <table class="meter-track">
            <tr>
                @for ($step = 1; $step <= $meterSteps; $step++)
                    <td class="{{ $step === $startStep ? 'meter-start' : '' }}">
                        {{ (int) round($step / $meterSteps * 100) }}
                    </td>
                @endfor
            </tr>
        </table>
    </div>
@empty
    <p class="empty-note">{{ __('Nog geen meters gegenereerd voor deze les.') }}</p>
@endforelse
