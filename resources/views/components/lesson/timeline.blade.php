@props([
    'scenes'          => collect(),
    'selectedSceneId' => null,
    'editable'        => true,
])

@php
    $total = max(1, $scenes->sum(fn ($s) => $s->duration_seconds ?: 8));
@endphp

<div {{ $attributes->merge(['class' => 'fixed bottom-0 inset-x-0 z-30 bg-base-300/85 backdrop-blur border-t border-slate-700/40']) }}>
    <div class="max-w-screen-2xl mx-auto px-4 py-3">

        {{-- Ruler --}}
        <div class="relative h-3 mb-1">
            @for ($t = 0; $t <= $total; $t += 5)
                <span class="absolute top-0 text-[9px] text-slate-500"
                      style="left: {{ ($t / $total) * 100 }}%; transform: translateX(-50%);">
                    {{ sprintf('%d:%02d', intdiv($t, 60), $t % 60) }}
                </span>
            @endfor
        </div>

        {{-- Video track --}}
        <div id="timeline-track"
             class="flex gap-1 items-stretch min-h-[64px]"
             @if ($editable) data-sortable="timeline" @endif>
            @php $cursor = 0; @endphp
            @foreach ($scenes as $scene)
                @php
                    $dur = $scene->duration_seconds ?: 8;
                    $w   = ($dur / $total) * 100;
                @endphp
                <x-lesson.scene-thumb :scene="$scene"
                                      :selected="$scene->id === $selectedSceneId"
                                      :start-time="$cursor"
                                      :width-pct="$w" />
                @php $cursor += $dur; @endphp
            @endforeach

            @if ($editable)
                <button type="button" wire:click="addScene"
                        class="shrink-0 w-9 h-9 rounded-lg border-2 border-slate-600 hover:border-amber-400 transition-all flex items-center justify-center text-slate-400 hover:text-amber-300"
                        title="Add scene">
                    +
                </button>
            @endif
        </div>

        {{-- Script track --}}
        <div class="flex gap-1 mt-1 text-[10px] text-slate-400">
            @foreach ($scenes as $scene)
                @php $dur = $scene->duration_seconds ?: 8; $w = ($dur / $total) * 100; @endphp
                <div style="width: {{ $w }}%;" class="truncate px-1">
                    {{ \Illuminate\Support\Str::limit($scene->script_segment ?? '', 60) }}
                </div>
            @endforeach
        </div>
    </div>
</div>

@if ($editable)
@push('scripts')
<script>
document.addEventListener('livewire:initialized', () => {
    const track = document.getElementById('timeline-track')
    if (!track || !window.Sortable) return
    new window.Sortable(track, {
        animation: 150,
        filter: '[data-no-drag]',
        onEnd: () => {
            const ids = [...track.querySelectorAll('[data-scene-id]')].map(el => Number(el.dataset.sceneId))
            window.Livewire.dispatch('timeline:reordered', { ids })
        },
    })
})
</script>
@endpush
@endif
