@props([
    'scenes'          => collect(),
    'selectedSceneId' => null,
    'editable'        => true,
])

<div {{ $attributes->merge(['class' => 'fixed bottom-0 inset-x-0 z-30 hover:bg-base-300/85 hover:backdrop-blur hover:border-t hover:border-slate-700/40']) }}>
    <div class="max-w-screen-2xl mx-auto px-4 py-3">

        {{-- Video track — fixed-size thumbs, scrolls horizontally if it overflows --}}
        <div id="timeline-track"
             class="flex gap-2 items-stretch pb-1"
             @if ($editable) data-sortable="timeline" @endif>
            @foreach ($scenes as $scene)
                <x-lesson.scene-thumb :scene="$scene"
                                      :selected="$scene->id === $selectedSceneId" />
            @endforeach

            @if ($editable)
                <button type="button"
                        data-no-drag
                        wire:click="$set('addSceneOpen', true)"
                        class="h-[72px] w-32 shrink-0 rounded-lg border-2 border-white/20 text-white/40 transition-all hover:border-amber-400 hover:text-amber-300"
                        title="Add scene">
                    <span class="block text-2xl leading-none">+</span>
                    <span class="mt-1 block text-[9px] font-semibold uppercase tracking-widest">Add</span>
                </button>
            @endif
        </div>

        {{-- Script track (mirrors each thumb's fixed width) --}}
        <div class="flex gap-2 mt-1 text-[10px] text-slate-400 overflow-x-auto">
            @foreach ($scenes as $scene)
                <div class="w-32 shrink-0 truncate px-1">
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
        // Default preventOnFilter:true calls preventDefault() on [data-no-drag], which swallows
        // the click on the "Add scene" button. Keep native clicks alive on filtered elements.
        preventOnFilter: false,
        onEnd: () => {
            const ids = [...track.querySelectorAll('[data-scene-id]')].map(el => Number(el.dataset.sceneId))
            window.Livewire.dispatch('timeline:reordered', { ids })
        },
    })
})
</script>
@endpush
@endif
