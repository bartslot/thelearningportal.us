@props(['type' => 'standing'])

@php
$svgs = [
    'standing' => '
        <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
        <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="28" x2="12" y2="38" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="28" x2="38" y2="38" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    ',
    'walking' => '
        <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
        <line x1="25" y1="17" x2="23" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="23" y1="28" x2="10" y2="22" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="23" y1="28" x2="37" y2="35" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="23" y1="46" x2="13" y2="65" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="23" y1="46" x2="35" y2="62" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    ',
    'pointing' => '
        <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
        <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="28" x2="12" y2="36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="24" x2="44" y2="17" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    ',
    'explaining' => '
        <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
        <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="27" x2="10" y2="32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="27" x2="40" y2="32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    ',
    'waving' => '
        <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
        <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="28" x2="12" y2="36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="24" x2="40" y2="12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    ',
    'waving-expressive' => '
        <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
        <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="28" x2="12" y2="36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="24" x2="40" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="40" y1="13" x2="47" y2="5"  stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    ',
];
$inner = $svgs[$type] ?? $svgs['standing'];
@endphp

<svg {{ $attributes->merge(['viewBox' => '0 0 50 80', 'fill' => 'none', 'xmlns' => 'http://www.w3.org/2000/svg']) }}>
    {!! $inner !!}
</svg>
