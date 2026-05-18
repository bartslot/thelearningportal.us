@props([
    'step'   => 1,
    'lesson' => null,
])

@php
    $statusValue = $lesson?->status?->value;

    // Highest step the teacher is allowed to jump to.
    $maxStep = 1;
    if ($lesson) {
        // Lesson exists → generation phase is at least visible.
        $maxStep = 2;
        if (in_array($statusValue, ['scenes_ready', 'configuring', 'previewable', 'published'], true)) {
            $maxStep = 3;
        }
        if (in_array($statusValue, ['previewable', 'published'], true)) {
            $maxStep = 4;
        }
    }

    $steps = [
        1 => 'Settings',
        2 => 'Generate',
        3 => 'Configure',
        4 => 'Preview',
    ];

    $stepUrl = fn (int $n) => $lesson
        ? route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => $n])
        : route('teacher.lessons.create', ['step' => $n]);
@endphp

<div class="fixed top-0 inset-x-0 z-50 bg-base-300/95 backdrop-blur border-b border-slate-700/40">
    <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-3 text-xs">
        @foreach ($steps as $n => $label)
            @php
                $isActive    = $n === (int) $step;
                $isAvailable = $n <= $maxStep;
            @endphp

            @if ($isAvailable && ! $isActive)
                <a href="{{ $stepUrl($n) }}" wire:navigate
                   class="flex items-center gap-2 group">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-semibold bg-slate-700 text-slate-200 group-hover:bg-slate-600 transition-colors">{{ $n }}</span>
                    <span class="text-slate-300 group-hover:text-amber-300 transition-colors">{{ $label }}</span>
                </a>
            @elseif ($isActive)
                <div class="flex items-center gap-2">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-semibold bg-amber-500 text-slate-950">{{ $n }}</span>
                    <span class="text-amber-300 font-semibold">{{ $label }}</span>
                </div>
            @else
                <div class="flex items-center gap-2 opacity-40 cursor-not-allowed" title="Not yet available">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-semibold bg-slate-800 text-slate-500">{{ $n }}</span>
                    <span class="text-slate-500">{{ $label }}</span>
                </div>
            @endif

            @unless ($loop->last)
                <span class="text-slate-600">›</span>
            @endunless
        @endforeach
    </div>
</div>
