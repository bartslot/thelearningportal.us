@props(['kind' => 'narration', 'gameType' => null])
@php
    // narration | map | quiz | strategy | debate
    $key = $kind === 'game' ? ($gameType ?? 'quiz') : $kind;
@endphp
<div class="flex h-16 w-full items-center justify-center overflow-hidden rounded-md bg-base-100 ring-1 ring-slate-700/60">
    @switch($key)
        @case('narration')
            <div class="flex w-full items-start gap-1.5 p-2">
                <div class="h-4 w-4 shrink-0 rounded-full bg-amber-400"></div>
                <div class="flex flex-1 flex-col gap-1 pt-0.5">
                    <div class="h-1 w-full rounded-sm bg-slate-600"></div>
                    <div class="h-1 w-5/6 rounded-sm bg-slate-600"></div>
                    <div class="h-1 w-2/3 rounded-sm bg-slate-600"></div>
                </div>
            </div>
            @break

        @case('quiz')
            <div class="flex w-full flex-col gap-1 p-2">
                <div class="h-1.5 w-3/4 rounded-sm bg-slate-500"></div>
                <div class="grid grid-cols-2 gap-1">
                    <div class="h-2 rounded-sm bg-amber-400"></div>
                    <div class="h-2 rounded-sm bg-slate-600"></div>
                    <div class="h-2 rounded-sm bg-slate-600"></div>
                    <div class="h-2 rounded-sm bg-slate-600"></div>
                </div>
            </div>
            @break

        @case('strategy')
            <div class="grid grid-cols-3 grid-rows-3 gap-0.5 p-2">
                @foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8] as $cell)
                    <div class="h-2.5 w-2.5 rounded-[2px] {{ $cell === 4 ? 'bg-amber-400' : 'bg-slate-600' }}"></div>
                @endforeach
            </div>
            @break

        @case('debate')
            <div class="flex w-full items-center justify-center gap-2 p-2">
                <div class="h-6 w-9 rounded-md rounded-br-none bg-amber-400/80"></div>
                <div class="h-6 w-9 rounded-md rounded-bl-none bg-slate-600"></div>
            </div>
            @break

        @case('map')
            <div class="flex w-full flex-col justify-center gap-1.5 p-2">
                <div class="relative h-1 w-full rounded-sm bg-slate-600">
                    <div class="absolute -top-1 left-1/3 h-3 w-3 rounded-full border-2 border-amber-400 bg-base-100"></div>
                </div>
                <div class="h-6 w-full rounded-sm bg-indigo-500/30"></div>
            </div>
            @break
    @endswitch
</div>
