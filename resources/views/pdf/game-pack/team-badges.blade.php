{{-- Team badges — 6 generic colored badges for class mode. --}}
<div class="page-break"></div>
<div class="sheet-band">
    <div class="wordmark">History Portal · Spelpakket</div>
    <div class="sheet-title">{{ __('Teambadges') }}</div>
    <div class="sheet-note">{{ __('Eén badge per team. Knip uit en deel uit.') }}</div>
</div>

<div class="cut-note">✂ {{ __('Knip de badges uit.') }}</div>

<table class="badge-table">
    @foreach (array_chunk($teamBadges, 3) as $row)
        <tr>
            @foreach ($row as $badge)
                <td class="badge-cell" style="background-color: {{ $badge['color'] }};">
                    <div class="badge-team-label">{{ __('Team') }}</div>
                    <div class="badge-team-name">{{ $badge['name'] }}</div>
                </td>
            @endforeach
        </tr>
    @endforeach
</table>
