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
(() => {
    function mountTimelineSortable() {
        const track = document.getElementById('timeline-track')
        // Skip if deps missing or already mounted on THIS element (guards double-init).
        if (!track || !window.Sortable || track._sortableMounted) return
        track._sortableMounted = true
        new window.Sortable(track, {
            animation: 150,
            draggable: '[data-scene-id]',  // only scene thumbs drag — never the "Add" button
            filter: '[data-no-drag]',
            // Default preventOnFilter:true calls preventDefault() on [data-no-drag], which swallows
            // the click on the "Add scene" button. Keep native clicks alive on filtered elements.
            preventOnFilter: false,
            onEnd: () => {
                const ids = [...track.querySelectorAll('[data-scene-id]')].map(el => Number(el.dataset.sceneId))
                window.Livewire.dispatch('timeline:reordered', { ids })
            },
        })
    }
    function boot() {
        mountTimelineSortable()
        // wire:poll.3s re-renders the timeline; if the morph swaps the track element, re-mount on it.
        window.Livewire?.hook('morph.updated', ({ el }) => { if (el && el.id === 'timeline-track') mountTimelineSortable() })
    }
    // Livewire defers stacked scripts, so `livewire:initialized` has usually ALREADY fired by the
    // time this runs — mount immediately in that case (the old code only listened, so it never mounted).
    if (window.Livewire) boot()
    else document.addEventListener('livewire:initialized', boot)
    document.addEventListener('livewire:navigated', mountTimelineSortable)
})()
</script>
@endpush
@endif
