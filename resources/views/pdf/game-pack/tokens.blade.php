{{-- Manschappen tokens — a cut grid of ~20. Meter losses = tokens handed in. --}}
<div class="page-break"></div>
<div class="sheet-band">
    <div class="wordmark">History Portal · Spelpakket</div>
    <div class="sheet-title">{{ __('Manschappen-tokens') }}</div>
    <div class="sheet-note">{{ __('Elk team krijgt tokens; bij verlies lever je er in. Op nul? Eén ronde eruit, en de anderen aanmoedigen.') }}</div>
</div>

<div class="cut-note">✂ {{ __('Knip de tokens uit langs de lijnen.') }}</div>

<table class="token-table">
    @foreach (array_chunk($tokens, 4) as $row)
        <tr>
            @foreach ($row as $token)
                <td>
                    <div class="token-icon">🛡️</div>
                    <div class="token-label">{{ __('Manschap') }}</div>
                </td>
            @endforeach
        </tr>
    @endforeach
</table>
