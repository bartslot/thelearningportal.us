@props(['step' => 1])

@php
    $steps = [
        1 => 'Settings',
        2 => 'Generate',
        3 => 'Configure',
        4 => 'Preview',
    ];
@endphp

<div class="bg-base-300 border-b border-base-100/10">
    <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-3 text-xs">
        @foreach ($steps as $n => $label)
            <div class="flex items-center gap-2">
                <span @class([
                    'w-7 h-7 rounded-full flex items-center justify-center font-semibold',
                    'bg-amber-500 text-slate-950' => $n === $step,
                    'bg-slate-700 text-slate-300' => $n !== $step,
                ])>{{ $n }}</span>
                <span @class([
                    'text-amber-300' => $n === $step,
                    'text-slate-400' => $n !== $step,
                ])>{{ $label }}</span>
            </div>
            @unless ($loop->last)
                <span class="text-slate-600">›</span>
            @endunless
        @endforeach
    </div>
</div>
