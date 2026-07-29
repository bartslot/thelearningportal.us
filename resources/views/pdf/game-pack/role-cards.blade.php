{{-- Team role cards — 2 per A4, cut along the dashed border. One per TEAM, never per child. --}}
<div class="sheet-band">
    <div class="wordmark">History Portal · Spelpakket</div>
    <div class="sheet-title">{{ __('Rolkaarten') }}</div>
    <div class="sheet-note">{{ $lesson->title ?? $lesson->topic }}</div>
</div>

<div class="cut-note">
    ✂ {{ __('Knip langs de stippellijn. Print één set per team. Deel rollen aan groepjes uit, nooit aan losse leerlingen.') }}
</div>

@forelse ($roles as $role)
    <div class="role-card">
        <div class="role-eyebrow">{{ __('Teamrol') }}</div>
        <div class="role-title">{{ $role['title'] }}</div>
        @if ($role['flavor'] !== '')
            <div class="role-flavor">{{ $role['flavor'] }}</div>
        @endif
        @if ($role['power'] !== '')
            <div class="role-power-label">{{ __('Kracht van dit team') }}</div>
            <div class="role-power">{{ $role['power'] }}</div>
        @endif
    </div>
@empty
    <p class="empty-note">{{ __('Nog geen rollen gegenereerd voor deze les.') }}</p>
@endforelse
