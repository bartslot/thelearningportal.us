<div class="contents" x-data="step3SceneConfigurator" x-init="init()">

    {{-- Fullscreen canvas wrapper — wire:ignore so Livewire never re-renders the
         canvas (which would force a new Avatar3DPlayer + reset camera/orbit). --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url="{{ $lesson->avatar?->glbUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
    </div>

    {{-- Inspector toggle chip --}}
    <button type="button" @click="inspectorOpen = !inspectorOpen"
            class="fixed top-4 right-4 z-40 px-3 py-2 rounded-xl bg-slate-900/80 backdrop-blur border border-slate-700 text-xs text-slate-200 hover:border-amber-400">
        ≡ Inspector
    </button>

    {{-- Inspector drawer --}}
    <aside x-show="inspectorOpen" x-transition.opacity
           class="fixed top-16 right-0 bottom-24 z-30 w-[360px] bg-base-300/90 backdrop-blur border-l border-slate-700/40 p-4 overflow-y-auto">
        @php $sceneModel = $this->selectedSceneModel; @endphp
        @if ($sceneModel)
            @if ($sceneModel->kind === 'game')
                <x-lesson.scene-inspector-game :scene="$sceneModel" />
            @else
                <x-lesson.scene-inspector-narration :scene="$sceneModel" :clips="$this->animationClips" />
            @endif
        @else
            <p class="text-sm text-slate-400">No scene selected.</p>
        @endif
    </aside>

    {{-- Step nav floating buttons --}}
    <div class="fixed bottom-28 right-4 z-30 flex gap-2">
        <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2]) }}"
           wire:navigate class="btn btn-sm btn-outline">← Back</a>
        <button wire:click="continueToPreview"
                class="btn btn-sm bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Continue to Preview →</button>
    </div>

    {{-- Bottom timeline --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" editable />

    {{-- Scenes payload as inert JSON so we don't string-interpolate it into JS --}}
    <script type="application/json" id="step3-scenes-data">
        {!! $this->scenes->map->only(['id','kind','year','location','image_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id'])->toJson() !!}
    </script>
</div>

@push('head-scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('step3SceneConfigurator', () => ({
            inspectorOpen: true,

            async init() {
                this.inspectorOpen = (localStorage.getItem('wizard.inspector') ?? '1') === '1';
                this.$watch('inspectorOpen', v => localStorage.setItem('wizard.inspector', v ? '1' : '0'));

                if (!window.LessonScene?.mountWizardScene) return;

                const dataEl       = document.getElementById('step3-scenes-data');
                const scenes       = dataEl ? JSON.parse(dataEl.textContent) : [];
                const overlayEl    = document.getElementById('lesson-overlay');
                const timerEl      = document.getElementById('lesson-game-overlay');
                const canvasEl     = document.getElementById('lesson-canvas');
                const rootEl       = document.getElementById('lesson-canvas-root');
                const characterUrl = rootEl?.dataset.characterUrl || null;

                window.__lessonStage = await window.LessonScene.mountWizardScene({
                    canvasEl, overlayEl, timerEl, scenes, characterUrl,
                });
            },
        }));
    });

    window.addEventListener('timeline:reordered', e => window.Livewire?.dispatch('reorder', { orderedIds: e.detail.ids }));
</script>
@endpush
