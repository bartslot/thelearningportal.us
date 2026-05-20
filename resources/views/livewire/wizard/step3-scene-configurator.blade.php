<div class="contents" x-data="step3SceneConfigurator" wire:poll.3s>

    {{-- Fullscreen canvas wrapper — wire:ignore so Livewire never re-renders the
         canvas (which would force a new Avatar3DPlayer + reset camera/orbit). --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url="{{ $lesson->avatar?->glbUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none py-32"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
    </div>
    
        {{-- Inspector toggle chip --}}
        <button type="button" @click="inspectorOpen = !inspectorOpen"
                :aria-label="inspectorOpen ? 'Close inspector' : 'Open inspector'"
                :title="inspectorOpen ? 'Close inspector' : 'Open inspector'"
                :class="{ 'swap-active': inspectorOpen }"
                class="fixed w-90 flex gap-4 justify-between top-4 right-4 p-4 z-60 btn btn-circle btn-sm swap swap-rotate bg-slate-900/80 backdrop-blur border-slate-700 text-slate-200 hover:border-amber-400">
            <div class="w-4">
            <span class="swap-on text-base leading-none">❯</span>
            <span class="swap-off text-base leading-none">❮</span>
            </div>
            <div>Inspector</div>
        </button>

        {{-- Inspector drawer --}}
        <aside x-show="inspectorOpen" x-transition.opacity
            class="fixed top-16 right-0 bottom-24 z-50 w-90 bg-base-300/90 backdrop-blur border-l border-slate-700/40 p-4 overflow-y-auto">
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

            {{-- ── Background Music ──────────────────────────── --}}
            <div class="mt-6 pt-4 border-t border-slate-700/50" x-data="musicStrip">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[10px] uppercase tracking-widest text-slate-500">Background Music</span>
                    @if($lesson->background_music)
                        <button wire:click="selectMusic('')"
                                class="text-[10px] text-slate-500 hover:text-rose-400 transition-colors">✕ off</button>
                    @endif
                </div>
                <div class="flex gap-2 pb-2 overflow-x-auto scroll-smooth" style="scroll-snap-type:x mandatory; scrollbar-width:none;">
                    @foreach($this->musicTracks() as $track)
                    @php $url = asset('sound/bg-music/' . $track['file']); @endphp
                    <button
                        x-on:click="toggle('{{ $track['id'] }}', '{{ $url }}')"
                        wire:click="selectMusic('{{ $track['id'] }}')"
                        :class="selectedId === '{{ $track['id'] }}'
                            ? 'border-amber-400'
                            : 'border-slate-700/60 hover:border-indigo-500/50'"
                        class="{{ $track['gradient_class'] }} shrink-0 w-16 rounded-xl p-2 border relative cursor-pointer transition-all"
                        style="scroll-snap-align:start; min-height:72px;"
                        title="{{ $track['label'] }}"
                        x-init="@if($lesson->background_music === $track['id']) selectedId = '{{ $track['id'] }}' @endif"
                    >
                        <div class="absolute top-1 right-1 z-10">
                            <span x-show="playingId === '{{ $track['id'] }}'"
                                  class="flex gap-0.5 items-end h-3 text-indigo-400">
                                <span class="wave-bar h-3"></span>
                                <span class="wave-bar h-2"></span>
                                <span class="wave-bar h-3"></span>
                            </span>
                            <span x-show="playingId !== '{{ $track['id'] }}' && selectedId === '{{ $track['id'] }}'"
                                  class="text-amber-400 text-xs leading-none">✓</span>
                        </div>
                        <div class="flex items-center justify-center h-7 mt-1">
                            <svg class="w-5 h-5 text-white/50" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 3v10.55A4 4 0 1 0 11 17V7h4V3H9z"/>
                            </svg>
                        </div>
                        <p class="text-[10px] text-white/80 text-center truncate mt-1 leading-tight">{{ $track['label'] }}</p>
                    </button>
                    @endforeach
                </div>
                <p class="text-[10px] text-slate-600 mt-1">Click to preview (20s). Selected track plays during lesson.</p>
            </div>
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
        {!! $this->scenes->map->only(['id','kind','year','location','image_path','world_pano_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id'])->toJson() !!}
    </script>
</div>

@script
<script>
    (function () {
        function registerStep3() {
            if (window.__step3AlpineRegistered) return;
            window.__step3AlpineRegistered = true;

            Alpine.data('musicStrip', () => ({
                playingId: null,
                selectedId: null,
                _audio: null,
                _timer: null,

                toggle(trackId, url) {
                    // Stop current preview
                    if (this._audio) { this._audio.pause(); this._audio = null; }
                    clearTimeout(this._timer);

                    if (this.playingId === trackId) {
                        this.playingId = null;
                        return;
                    }

                    this.selectedId = trackId;
                    this.playingId  = trackId;
                    const audio = new Audio(url);
                    audio.volume = 0.6;
                    audio.play().catch(() => {});
                    this._audio = audio;

                    // Auto-stop after 20s
                    this._timer = setTimeout(() => {
                        audio.pause();
                        this._audio = null;
                        this.playingId = null;
                    }, 20000);

                    audio.addEventListener('ended', () => {
                        this.playingId = null;
                        this._audio = null;
                        clearTimeout(this._timer);
                    });
                },
            }));

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

            window.addEventListener('timeline:reordered', e => window.Livewire?.dispatch('reorder', { orderedIds: e.detail.ids }));
        }

        // Alpine may already be booted (Livewire defers scripts); register immediately if so.
        if (window.Alpine) {
            registerStep3();
        } else {
            document.addEventListener('alpine:init', registerStep3);
        }
    })();
</script>
@endscript
