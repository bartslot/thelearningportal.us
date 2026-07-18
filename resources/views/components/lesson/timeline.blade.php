@props([
    'scenes'          => collect(),
    'selectedSceneId' => null,
    'editable'        => true,
])

{{-- Vertical scene rail (LessonUp/Keynote-style): numbered thumbnails down the left edge.
     Replaces the old horizontal bottom timeline — vertical scanning matches how teachers
     read slide decks, and it frees the bottom strip for the step CTA. --}}
{{-- Full-height left column: the panel reaches the top edge (aligned with the header, whose
     logo sits over it), while pt-20 keeps the 'Scenes' block at its original height. --}}
{{-- Compact rail: when the rail is dragged narrow (≤ 92px), the container query collapses
     each thumb to just its centered number on a transparent background (no image / year /
     place) so scenes can still be scanned and reordered in a sliver of width. --}}
<style>
    [data-rail-col] { container: railcol / inline-size; }
    @container railcol (max-width: 92px) {
        [data-rail-col] [data-thumb] {
            aspect-ratio: auto !important;
            height: 2.1rem;
            background: transparent !important;
            box-shadow: none !important;                 /* kills the Tailwind ring (box-shadow) */
            --tw-ring-offset-width: 0px;
        }
        [data-rail-col] [data-thumb-full] { display: none !important; }
        [data-rail-col] [data-thumb-compact] { display: flex !important; }
        [data-rail-col] [data-thumb-selected] { background: rgba(245, 158, 11, 0.14) !important; }
        [data-rail-col] [data-thumb-selected] [data-thumb-compact] { color: #fbbf24 !important; }
        [data-rail-col] [data-rail-label] { display: none !important; }
        [data-rail-col] [data-rail-add] { aspect-ratio: auto !important; height: 2.1rem !important; }
    }
</style>
<aside {{ $attributes->merge(['class' => 'fixed left-0 top-0 bottom-0 z-30 overflow-hidden border-r border-slate-700 bg-base-300']) }}
       style="width: var(--rail-w, 11rem)">
    <div class="flex h-full flex-col pt-20" data-rail-col>
        <div class="px-3 pb-1 pt-3">
            <span data-rail-label class="text-[10px] font-semibold uppercase tracking-widest text-slate-500">
                {{ __('Scenes') }} · {{ $scenes->count() }}
            </span>
        </div>

        {{-- p-3 (not just pb-3): the selected thumb's ring-offset-2 overspill would be clipped
             by overflow-y-auto without top/side breathing room. See overflow-clips-rings memory. --}}
        <div id="timeline-track"
             class="flex-1 space-y-2 overflow-y-auto p-3"
             @if ($editable) data-sortable="timeline" @endif>
            @foreach ($scenes as $scene)
                <x-lesson.scene-thumb :scene="$scene"
                                      :selected="$scene->id === $selectedSceneId"
                                      :number="$loop->iteration"
                                      wide />
            @endforeach

            @if ($editable)
                <button type="button"
                        data-no-drag data-rail-add
                        wire:click="$set('addSceneOpen', true)"
                        class="aspect-video w-full rounded-xl border-2 border-dashed border-white/20 text-white/40 transition-all hover:border-amber-400 hover:text-amber-300"
                        title="{{ __('Add scene') }}" aria-label="{{ __('Add scene') }}">
                    <span class="block text-2xl leading-none">+</span>
                    <span data-rail-label class="mt-1 block text-[9px] font-semibold uppercase tracking-widest">{{ __('Add Scene') }}</span>
                </button>
            @endif
        </div>
    </div>
</aside>

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
        // wire:poll.3s re-renders the rail; if the morph swaps the track element, re-mount on it.
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
