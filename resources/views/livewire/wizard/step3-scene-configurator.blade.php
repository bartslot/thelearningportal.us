<div class="contents" x-data="step3SceneConfigurator" wire:poll.3s>

    {{-- Fullscreen canvas wrapper — wire:ignore so Livewire never re-renders the
         canvas (which would force a new Avatar3DPlayer + reset camera/orbit). --}}
    @php $use2dAvatar = config('avatars.use_2d'); @endphp
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url="{{ $use2dAvatar ? '' : $lesson->avatar?->glbUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        {{-- 2D avatar: static portrait standing where the 3D avatar would render. --}}
        @if ($use2dAvatar && $lesson->avatar && ($avatarImg = $lesson->avatar->thumbnailUrl() ?? $lesson->avatar->portraitUrl()))
            <img src="{{ $avatarImg }}" alt="{{ $lesson->avatar->name }}"
                 class="pointer-events-none absolute bottom-0 left-1/2 z-[5] h-[80%] w-auto max-w-[42%] -translate-x-1/2 object-contain drop-shadow-2xl">
        @endif
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none py-32"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
    </div>
    {{-- Draggable inspector --}}
    <aside x-cloak
           x-ref="inspectorPanel"
           :style="inspectorPanelStyle()"
           :class="inspectorOpen ? 'w-[min(24rem,calc(100vw-1rem))]' : 'w-56'"
           class="card card-compact fixed z-50 overflow-hidden border border-slate-700/70 bg-base-300/95 shadow-2xl backdrop-blur-xl">
        <header
            @pointerdown="startInspectorDrag($event)"
            class="card-title flex min-h-11 cursor-grab select-none items-center justify-between gap-3 border-b border-slate-700/50 bg-base-200/80 px-3 py-2 text-sm active:cursor-grabbing">
            <div class="flex min-w-0 items-center gap-2">
                <span class="h-2 w-2 shrink-0 rounded-full bg-amber-400"></span>
                <span class="truncate font-semibold text-slate-100">Inspector</span>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button"
                        @pointerdown.stop
                        @click.stop="resetInspectorPosition()"
                        class="btn btn-ghost btn-xs btn-square text-slate-400 hover:text-slate-100"
                        aria-label="Reset inspector position"
                        title="Reset position">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 1 0 9-9" />
                        <path d="M3 3v6h6" />
                    </svg>
                </button>
                <button type="button"
                        @pointerdown.stop
                        @click.stop="toggleInspector()"
                        class="btn btn-ghost btn-xs btn-square text-slate-300 hover:text-amber-300"
                        :aria-label="inspectorOpen ? 'Collapse inspector' : 'Expand inspector'"
                        :title="inspectorOpen ? 'Collapse inspector' : 'Expand inspector'">
                    <svg class="h-4 w-4 transition-transform duration-200"
                         :class="inspectorOpen ? 'rotate-0' : 'rotate-180'"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </button>
            </div>
        </header>

        <div x-show="inspectorOpen"
             x-transition.opacity.duration.150ms
             class="card-body overflow-y-auto p-4"
             :style="inspectorBodyStyle()">
            @php $sceneModel = $this->selectedSceneModel; @endphp
            @if ($sceneModel)
                @if ($sceneModel->kind === 'game')
                    <x-lesson.scene-inspector-game :scene="$sceneModel" :games="$this->games" />
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
        {!! $this->scenes->map->only(['id','kind','game_type','quiz_question_count','quiz_timing','strategy_game_id','team_count','year','location','image_path','world_pano_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id'])->toJson() !!}
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
                inspectorX: 0,
                inspectorY: 0,
                inspectorDragging: false,
                inspectorDragStartX: 0,
                inspectorDragStartY: 0,
                inspectorDragPanelX: 0,
                inspectorDragPanelY: 0,
                _inspectorMoveHandler: null,
                _inspectorUpHandler: null,
                _inspectorResizeHandler: null,

                async init() {
                    this.inspectorOpen = (localStorage.getItem('wizard.inspector') ?? '1') === '1';
                    this.restoreInspectorPosition();
                    this.$watch('inspectorOpen', v => {
                        localStorage.setItem('wizard.inspector', v ? '1' : '0');
                        this.$nextTick(() => this.constrainInspectorPosition());
                    });

                    this._inspectorMoveHandler = event => this.moveInspector(event);
                    this._inspectorUpHandler = () => this.stopInspectorDrag();
                    this._inspectorResizeHandler = () => this.constrainInspectorPosition();
                    window.addEventListener('pointermove', this._inspectorMoveHandler);
                    window.addEventListener('pointerup', this._inspectorUpHandler);
                    window.addEventListener('resize', this._inspectorResizeHandler);

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

                destroy() {
                    if (this._inspectorMoveHandler) window.removeEventListener('pointermove', this._inspectorMoveHandler);
                    if (this._inspectorUpHandler) window.removeEventListener('pointerup', this._inspectorUpHandler);
                    if (this._inspectorResizeHandler) window.removeEventListener('resize', this._inspectorResizeHandler);
                },

                inspectorPanelStyle() {
                    return `left:${this.inspectorX}px; top:${this.inspectorY}px;`;
                },

                inspectorBodyStyle() {
                    const maxHeight = Math.max(180, Math.min(680, window.innerHeight - this.inspectorY - 132));
                    return `max-height:${maxHeight}px;`;
                },

                toggleInspector() {
                    this.inspectorOpen = !this.inspectorOpen;
                },

                resetInspectorPosition() {
                    const panelWidth = this.inspectorPanelWidth();
                    this.inspectorX = Math.max(8, window.innerWidth - panelWidth - 16);
                    this.inspectorY = 16;
                    this.$nextTick(() => this.constrainInspectorPosition());
                },

                restoreInspectorPosition() {
                    let saved = null;
                    try {
                        saved = JSON.parse(localStorage.getItem('wizard.inspector.position') ?? 'null');
                    } catch {
                        saved = null;
                    }

                    if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) {
                        this.inspectorX = saved.x;
                        this.inspectorY = saved.y;
                    } else {
                        this.resetInspectorPosition();
                    }

                    this.$nextTick(() => this.constrainInspectorPosition());
                },

                startInspectorDrag(event) {
                    if (event.button !== undefined && event.button !== 0) return;
                    this.inspectorDragging = true;
                    this.inspectorDragStartX = event.clientX;
                    this.inspectorDragStartY = event.clientY;
                    this.inspectorDragPanelX = this.inspectorX;
                    this.inspectorDragPanelY = this.inspectorY;
                },

                moveInspector(event) {
                    if (!this.inspectorDragging) return;

                    this.inspectorX = this.inspectorDragPanelX + (event.clientX - this.inspectorDragStartX);
                    this.inspectorY = this.inspectorDragPanelY + (event.clientY - this.inspectorDragStartY);
                    this.constrainInspectorPosition(false);
                },

                stopInspectorDrag() {
                    if (!this.inspectorDragging) return;
                    this.inspectorDragging = false;
                    this.constrainInspectorPosition();
                },

                constrainInspectorPosition(save = true) {
                    const panel = this.$refs.inspectorPanel;
                    const rect = panel?.getBoundingClientRect();
                    const margin = 8;
                    const bottomReserve = 112;
                    const width = rect?.width || this.inspectorPanelWidth();
                    const height = rect?.height || 44;
                    const maxX = Math.max(margin, window.innerWidth - width - margin);
                    const maxY = Math.max(margin, window.innerHeight - height - bottomReserve);

                    this.inspectorX = this.clamp(this.inspectorX, margin, maxX);
                    this.inspectorY = this.clamp(this.inspectorY, margin, maxY);

                    if (save) {
                        localStorage.setItem('wizard.inspector.position', JSON.stringify({
                            x: Math.round(this.inspectorX),
                            y: Math.round(this.inspectorY),
                        }));
                    }
                },

                inspectorPanelWidth() {
                    if (!this.inspectorOpen) return 224;
                    return Math.min(384, Math.max(224, window.innerWidth - 16));
                },

                clamp(value, min, max) {
                    return Math.min(Math.max(value, min), max);
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
