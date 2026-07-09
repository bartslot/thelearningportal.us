@push('head-scripts')
    @vite('resources/js/lesson-map.js')
@endpush

<div class="contents" x-data="step3SceneConfigurator" wire:poll.3s>

    {{-- Fullscreen canvas wrapper — wire:ignore so Livewire never re-renders the
         canvas (which would force a new Avatar3DPlayer + reset camera/orbit). --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url=""
         data-territory="{{ $lesson->topic }}"
         data-flag="{{ $lesson->territoryFlagUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        {{-- Cinematic film-grain overlay (reuses the .lp-grain brand utility). --}}
        <div class="lp-grain pointer-events-none absolute inset-0 z-[3]"></div>
        {{-- Scene overlay (flag + territory title). z-[6] so it sits ABOVE the map preview (z-[5]),
             not hidden behind the globe. pointer-events-none keeps the map interactive. --}}
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none py-32 z-[6]"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
        {{-- Teacher text annotations (Freeform-style, draggable). z-[7]: above scene art + map. --}}
        <div id="lesson-text-overlay" class="absolute inset-0 z-[7]"></div>
        {{-- Map block preview — overlays the canvas when a map scene is selected. Uses fixed
             positioning (full viewport) because MapLibre relatively-positions its container, which
             would otherwise collapse an `absolute inset-0` host to height 0. --}}
        <div id="lesson-map-preview" class="fixed inset-0 z-[5]" style="display:none" wire:ignore></div>
    </div>

    {{-- Mount/destroy the MapLibre map block when a map scene is (de)selected. --}}
    @push('scripts')
    <script>
    (() => {
    let booted = false
    function boot() {
        if (booted || !window.Livewire) return
        booted = true
        const host = document.getElementById('lesson-map-preview')
        if (!host) return
        let inst = null
        let lastKey = null
        const destroy = () => {
            if (inst) { inst.destroy(); inst = null }
            host.innerHTML = ''
            host.style.display = 'none'
            lastKey = null
        }

        // Text labels can be pinned to the live map — wire the projector whenever both the
        // map instance and the text layer exist (either can be created first).
        const wireTextProjector = () => {
            const layer = window.__lessonTextLayer
            const overlayHost = document.getElementById('lesson-text-overlay')
            if (!layer) return
            if (!inst || !overlayHost) { layer.setProjector(null); return }
            layer.setProjector(inst.textProjector(overlayHost))
            inst.map.on('move', () => layer.refreshPositions())
        }

        window.Livewire.on('scene:load', (e) => {
            const p = Array.isArray(e) ? e[0]?.payload : e?.payload
            if (!p || p.kind !== 'map') { destroy(); wireTextProjector(); return }
            const cfg = p.config || {}
            const year = cfg.year ?? p.year ?? 1600
            // Only the scene-defining bits decide a re-mount. scene:load re-fires constantly (status
            // polling, saves, etc.); re-mounting each time would yank the globe back to its fit and
            // discard the camera the teacher panned. If nothing map-defining changed, keep the live
            // map and just sync the focus markers.
            // (projection is excluded — the Flat/Globe toggle flips it in place via lessonmap:projection,
            //  which keeps the camera; re-mounting for it would reset the view)
            const key = [p.sceneId, cfg.qid || '', year].join('|')
            if (inst && key === lastKey) {
                try { inst.setAnnotations(cfg.annotations || []) } catch (_) {}
                return
            }
            destroy()                 // clears lastKey…
            lastKey = key             // …so set it after
            host.style.display = 'block'
            // MapLibre stamps `position:relative` on its container, which would override the host's
            // fixed positioning and collapse it — so mount into a full-size inner child instead.
            const inner = document.createElement('div')
            inner.style.width = '100%'
            inner.style.height = '100%'
            host.appendChild(inner)
            if (window.renderLessonMap) {
                inst = window.renderLessonMap(inner, {
                    qid: cfg.qid || null,
                    year,
                    projection: cfg.projection || 'mercator',
                    interactive: true,
                    annotations: cfg.annotations || [],
                    editable: true,
                    onAnnotationsChange: (a) => window.Livewire.dispatch('annotationsChanged', { sceneId: p.sceneId, annotations: a }),
                    // Click a polity on the map → link it as this block's territory (hover shows its name).
                    onPolityClick: (t) => window.Livewire.dispatch('mapTerritoryClicked', { sceneId: p.sceneId, qid: t.qid, name: t.name }),
                })
                wireTextProjector()
            } else {
                // lesson-map.js failed to load — surface it instead of silently leaving an empty host,
                // which reveals the 3D canvas (narration art) behind and reads as "the map didn't open".
                console.error('[lesson-map] renderLessonMap unavailable — lesson-map.js did not load')
            }
        })

        // Inspector "+ Add focus city" button → put the map into drop-a-pin mode.
        window.addEventListener('lessonmap:add-focus', () => inst && inst.beginAddFocus())

        // Inspector VIEW toggle (Flat 2D / Globe 3D) → flip the live preview projection immediately.
        window.addEventListener('lessonmap:projection', (e) => inst && inst.setProjection(e.detail.type))

        // Focus-city rename/remove saved server-side → push fresh annotations so marker labels update live.
        window.Livewire.on('focusAnnotationsRefresh', (e) => {
            const p = Array.isArray(e) ? e[0] : e
            if (inst) inst.setAnnotations(p.annotations || [])
        })

        // ── Teacher text annotations ([T] tool) ────────────────────────────
        let textLayer = null
        let textSceneId = null
        let lastAppliedTexts = null
        const ensureTextLayer = () => {
            if (textLayer) return textLayer
            const layerHost = document.getElementById('lesson-text-overlay')
            if (!layerHost || !window.LessonScene?.TextOverlayLayer) return null
            textLayer = new window.LessonScene.TextOverlayLayer(layerHost, {
                editable: true,
                onChange: (texts) => {
                    lastAppliedTexts = JSON.stringify(texts)
                    // sceneId may still be null before the first scene:load — the server
                    // falls back to the currently selected scene in that case.
                    window.Livewire.dispatch('sceneTextsChanged', { sceneId: textSceneId ?? null, texts })
                },
            })
            window.__lessonTextLayer = textLayer
            wireTextProjector()   // a map block may already be live — pin labels to it now
            return textLayer
        }
        window.Livewire.on('scene:load', (e) => {
            const p = Array.isArray(e) ? e[0]?.payload : e?.payload
            if (!p) return
            const layer = ensureTextLayer()
            if (!layer) return
            const incoming = JSON.stringify(p.texts || [])
            const layerHost = document.getElementById('lesson-text-overlay')
            // scene:load re-fires every status poll — never nuke a box mid-typing, and skip
            // no-op re-renders of the same content.
            if (p.sceneId === textSceneId && (incoming === lastAppliedTexts || layerHost?.contains(document.activeElement))) return
            textSceneId = p.sceneId
            lastAppliedTexts = incoming
            layer.setTexts(p.texts || [])
        })
        window.addEventListener('lesson:add-text', () => ensureTextLayer()?.addText())
    }
    // Livewire defers stacked scripts, so `livewire:initialized` has often ALREADY fired by the time
    // this runs — boot immediately in that case; otherwise wait for the event. (Same footgun the
    // timeline Sortable hit: a bare addEventListener never fired, so the scene:load handler never
    // registered and selecting/adding a map block did nothing.)
    if (window.Livewire) boot()
    else document.addEventListener('livewire:initialized', boot)
    })()
    </script>
    @endpush
    {{-- Draggable inspector. Game/quiz scenes get a WIDE workspace (managing 5+ questions in a
         24rem sidebar was unusable) — narration/map scenes keep the compact panel. --}}
    @php $inspectorSceneModel = $this->selectedSceneModel; @endphp
    <aside x-cloak
           x-ref="inspectorPanel"
           :style="inspectorPanelStyle()"
           :class="inspectorOpen ? '{{ $inspectorSceneModel?->kind === 'game' ? 'w-[min(48rem,calc(100vw-1rem))]' : 'w-[min(24rem,calc(100vw-1rem))]' }}' : 'w-56'"
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
                @if ($sceneModel->kind === 'map')
                    <x-lesson.scene-inspector-map :scene="$sceneModel"
                                                 :territory-results="$this->territoryResults"
                                                 :territory-query="$territoryQuery"
                                                 :city-results="$this->cityResults"
                                                 :city-query="$cityQuery" />
                @elseif ($sceneModel->kind === 'game')
                    <x-lesson.scene-inspector-game :scene="$sceneModel" :games="$this->games"
                                                  :quiz-draft="$quizDraft" :quiz-errors="$quizErrors" :quiz-saved="$quizSaved"
                                                  :quiz-difficulty="$this->quizDifficulty()" :quiz-scope="$this->quizScope()"
                                                  :quiz-shuffle="$this->quizShuffle()" />
                @else
                    <x-lesson.scene-inspector-narration :scene="$sceneModel" :clips="$this->animationClips" />
                @endif

                {{-- Story-game: branch OPTION scenes get the "Game effects" editor. --}}
                @if ($this->showsGameEffectsPanel($sceneModel))
                    <x-lesson.scene-inspector-story-effects :scene="$sceneModel" :meters="$this->storyMeters()" />
                @endif
            @else
                <p class="text-sm text-slate-400">No scene selected.</p>
            @endif

            {{-- ── Class meters (story_game, lesson-level) ────── --}}
            @if ($this->showsMetersPanel())
                <x-lesson.story-meters-panel :meters-draft="$metersDraft" />
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
        
    {{-- Step nav — the primary CTA of this screen. Full-width strip above the timeline so
         teachers can't miss it (the old small button hid behind the narrator portrait). --}}
    <div class="fixed bottom-28 inset-x-0 z-30 flex items-center justify-between px-4 pointer-events-none">
        <div class="flex items-center gap-2 pointer-events-auto">
            <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2]) }}"
               wire:navigate class="btn btn-sm btn-ghost text-slate-300">← {{ __('Back') }}</a>
            {{-- [T] text tool: adds a text box on the scene, focused and ready to type. --}}
            <button type="button"
                    onclick="window.dispatchEvent(new CustomEvent('lesson:add-text'))"
                    class="btn btn-sm btn-square border border-slate-600 bg-base-300/80 text-slate-200 hover:border-amber-400 hover:text-amber-300 backdrop-blur"
                    title="{{ __('Add text to this scene (drag to move; paste a link to embed a page)') }}"
                    aria-label="{{ __('Add text to this scene') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                    <rect x="2.5" y="5.5" width="19" height="13" rx="3" stroke-dasharray="3.5 2.5"/>
                    <path stroke-linecap="round" d="M9 9.5h6M12 9.5v6"/>
                </svg>
            </button>
        </div>
        <button wire:click="continueToPreview"
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 shadow-xl px-6 pointer-events-auto">
            {{ __('Continue to Preview') }} →
        </button>
    </div>

    {{-- Bottom timeline --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" editable />

    {{-- Add-scene picker (Keynote-style). Replaces the old DaisyUI dropdown (broke in v5: the
         menu stayed opacity:0 on focus). Open state is Livewire-driven; tiles call addScene(). --}}
    <div class="modal modal-bottom sm:modal-middle {{ $addSceneOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-lg border border-slate-700/70 bg-base-300/95 backdrop-blur-xl">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-100">Add a scene</h2>
                <button type="button" class="btn btn-ghost btn-sm btn-circle text-slate-400"
                        aria-label="Close" wire:click="$set('addSceneOpen', false)">✕</button>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <button type="button" wire:click="addScene('narration')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="narration" />
                    <span class="text-sm font-medium text-slate-200">Story</span>
                </button>
                <button type="button" wire:click="addScene('game', 'quiz')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="game" game-type="quiz" />
                    <span class="text-sm font-medium text-slate-200">Quiz</span>
                </button>
                <button type="button" wire:click="addScene('game', 'strategy')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="game" game-type="strategy" />
                    <span class="text-sm font-medium text-slate-200">Strategy game</span>
                </button>
                <button type="button" wire:click="addScene('game', 'debate')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="game" game-type="debate" />
                    <span class="text-sm font-medium text-slate-200">Debate</span>
                </button>
                <button type="button" wire:click="addScene('map')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="map" />
                    <span class="text-sm font-medium text-slate-200">Map</span>
                </button>
            </div>
        </div>
        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('addSceneOpen', false)"></button>
    </div>

    {{-- Scenes payload as inert JSON so we don't string-interpolate it into JS --}}
    <script type="application/json" id="step3-scenes-data">
        {!! $this->scenes->map->only(['id','kind','game_type','quiz_question_count','quiz_timing','strategy_game_id','team_count','year','location','image_path','scene_view','skybox_blur','world_pano_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id','background_color','kb_animated','kb_direction'])->toJson() !!}
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

                    await window.loadLessonScene?.();
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
