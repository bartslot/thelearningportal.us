@props(['class' => 'w-4 h-4 animate-spin'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" aria-hidden="true">
    <circle cx="50" cy="50" r="40" opacity="0.25"/>
    <path d="M90 50 A40 40 0 0 0 50 10" />
</svg>
