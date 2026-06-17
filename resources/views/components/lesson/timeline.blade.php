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
                <div class="dropdown dropdown-top shrink-0" data-no-drag>
                    <button type="button"
                            tabindex="0"
                            class="h-[72px] w-32 rounded-lg border-2 border-white/20 text-white/40 transition-all hover:border-amber-400 hover:text-amber-300"
                            title="Add scene">
                        <span class="block text-2xl leading-none">+</span>
                        <span class="mt-1 block text-[9px] font-semibold uppercase tracking-widest">Add</span>
                    </button>
                    <ul tabindex="0"
                        class="menu dropdown-content z-50 mb-2 w-56 rounded-box border border-slate-700 bg-base-200 p-2 shadow-2xl">
                        <li class="menu-title px-2 text-[10px] uppercase tracking-widest text-slate-500">Scene element</li>
                        <li>
                            <button type="button" wire:click="addScene('narration')">
                                Extend Story
                            </button>
                        </li>
                        <li>
                            <button type="button" wire:click="addScene('map')">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                                Map
                            </button>
                        </li>
                        <li class="menu-title px-2 pt-2 text-[10px] uppercase tracking-widest text-slate-500">Game</li>
                        <li>
                            <button type="button" wire:click="addScene('game', 'quiz')">
                                Quiz
                            </button>
                        </li>
                        <li>
                            <button type="button" wire:click="addScene('game', 'strategy')">
                                Strategy Game
                            </button>
                        </li>
                        <li>
                            <button type="button" wire:click="addScene('game', 'debate')">
                                Debate
                            </button>
                        </li>
                    </ul>
                </div>
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
        onEnd: () => {
            const ids = [...track.querySelectorAll('[data-scene-id]')].map(el => Number(el.dataset.sceneId))
            window.Livewire.dispatch('timeline:reordered', { ids })
        },
    })
})
</script>
@endpush
@endif
