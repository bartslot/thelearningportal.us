@push('head-scripts')
    @vite('resources/js/lesson-map.js')
@endpush

<div class="contents" x-data="step3SceneConfigurator" wire:poll.3s
     x-effect="document.documentElement.style.setProperty('--objlist-w', $store.view.objects ? '13rem' : '0px');
               document.documentElement.style.setProperty('--ruler-w', $store.view.rulers ? '16px' : '0px');
               window.__placeRulers && requestAnimationFrame(window.__placeRulers)">

    {{-- Letterboxed canvas stage. The lesson lives in the WORK AREA between the fixed chrome —
         the scene rail (left, 11rem) and the docked inspector (right, 24rem) — below the top
         toolbar (4rem). It never slides under those panels. Inside that area it fits a 16:9 band,
         centred vertically, so the darkest-blue page forms bars top & bottom (Keynote-style).
         The canvas fits its host via coverScale (clientWidth-based), so constraining it is safe.
         wire:ignore so Livewire never re-renders the canvas (which would reset camera/orbit). --}}
    {{-- --work-left / --work-right track the live widths of the rail and inspector (set by the
         work-area sync script below) so the stage always fills exactly the gap between them and
         never slides under either panel. Defaults match the rail (11rem) + docked inspector (24rem). --}}
    <div class="fixed z-0 bg-slate-950" id="lesson-canvas-root"
         style="--work-left: calc(var(--rail-w, 11rem) + var(--objlist-w, 0px) + var(--ruler-w, 0px)); --work-right: 24rem;
                --top-inset: calc(4rem + var(--ruler-w, 0px));
                --lbw: calc(100vw - var(--work-left) - var(--work-right));
                --lbh: min(calc(var(--lbw) * 0.5625), calc(100vh - var(--top-inset)));
                left: var(--work-left); right: var(--work-right);
                height: var(--lbh); top: var(--top-inset);"
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

    {{-- Work-area sync — keep the stage's right edge glued to the live inspector width (docked
         narration 24rem, game 48rem, collapsed 14rem, or 0 when floating). The stage tracks it
         via --work-right; a resize nudge lets the WebGL scene refit. --}}
    @push('scripts')
    <script>
    (() => {
        const boot = () => {
            const root = document.getElementById('lesson-canvas-root')
            if (!root) return
            const aside = document.querySelector('aside[x-ref="inspectorPanel"]')
            if (!aside) { requestAnimationFrame(boot); return }
            let raf = 0
            const sync = () => {
                raf = 0
                const r = aside.getBoundingClientRect()
                // Docked = pinned to the right edge near the top; floating panels reserve no space.
                const docked = r.right >= window.innerWidth - 2 && r.top <= 140 && r.width > 0
                root.style.setProperty('--work-right', docked ? `${Math.round(r.width)}px` : '0px')
                window.dispatchEvent(new Event('resize'))   // nudge the scene renderer to refit
            }
            const schedule = () => { if (!raf) raf = requestAnimationFrame(sync) }
            new ResizeObserver(schedule).observe(aside)
            new MutationObserver(schedule).observe(aside, { attributes: true, attributeFilter: ['style', 'class'] })
            sync()
        }
        if (document.readyState !== 'loading') boot()
        else document.addEventListener('DOMContentLoaded', boot)
        document.addEventListener('livewire:navigated', boot)
    })()
    </script>
    @endpush

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
        // A just-added box/panel lives client-side for a beat before the save round-trips.
        // The 3s status poll can re-fire scene:load with STALE server texts in that window —
        // ignore it briefly so a fresh add (which has no focus to guard it) isn't wiped.
        let localDirtyUntil = 0
        const ensureTextLayer = () => {
            if (textLayer) return textLayer
            const layerHost = document.getElementById('lesson-text-overlay')
            if (!layerHost || !window.LessonScene?.TextOverlayLayer) return null
            textLayer = new window.LessonScene.TextOverlayLayer(layerHost, {
                editable: true,
                onChange: (texts) => {
                    lastAppliedTexts = JSON.stringify(texts)
                    localDirtyUntil = Date.now() + 2500
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
            // scene:load re-fires every status poll — never nuke a box mid-typing, skip
            // no-op re-renders of the same content, and hold off on stale polls right after
            // a local add (until the save round-trips back).
            const sameScene = p.sceneId === textSceneId
            if (sameScene && (incoming === lastAppliedTexts || layerHost?.contains(document.activeElement) || Date.now() < localDirtyUntil)) return
            textSceneId = p.sceneId
            lastAppliedTexts = incoming
            layer.setTexts(p.texts || [])
        })
        window.addEventListener('lesson:add-text', () => ensureTextLayer()?.addText())
        window.addEventListener('lesson:add-rect', (e) => ensureTextLayer()?.addRect(e.detail?.side || 'left'))
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
           :class="[
               inspectorOpen ? '{{ $inspectorSceneModel?->kind === 'game' ? 'w-[min(48rem,calc(100vw-1rem))]' : 'w-[min(24rem,calc(100vw-1rem))]' }}' : 'w-56',
               docked ? 'rounded-none border-r-0 border-t-0' : 'rounded-2xl'
           ]"
           class="card card-compact fixed z-50 overflow-hidden border border-slate-700 bg-base-300 shadow-2xl">
        <header
            @pointerdown="startInspectorDrag($event)"
            class="card-title flex min-h-11 cursor-grab select-none items-center justify-between gap-3 border-b border-slate-700/50 bg-base-200 px-3 py-2 text-sm active:cursor-grabbing">
            {{-- Drag grip (left) --}}
            <span class="flex items-center text-slate-600" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                    <circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/>
                    <circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/>
                    <circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>
                </svg>
            </span>
            {{-- No panel title: the right panel is purely the per-scene editor now. Global
                 settings live on the top toolbar (Settings button), not here. --}}
            <div class="flex shrink-0 items-center gap-1">
                <button type="button"
                        @pointerdown.stop
                        @click.stop="dockInspector()"
                        class="btn btn-ghost btn-xs btn-square text-slate-400 hover:text-slate-100"
                        :class="docked && 'text-amber-300'"
                        aria-label="Dock inspector to the right"
                        title="Dock to right">
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
        </div>
    </aside>

    {{-- Global class & lesson settings — opened from the Settings button on the top toolbar.
         Holds the Story link and the class Background Music (both lesson-wide, not per-scene). --}}
    <div class="modal modal-bottom sm:modal-middle {{ $settingsOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-lg border border-slate-700 bg-base-300">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-100">{{ __('Class & lesson settings') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm btn-circle text-slate-400"
                        aria-label="Close" wire:click="$set('settingsOpen', false)">✕</button>
            </div>

            {{-- ══ Story ══════════════════════════════════════════ --}}
            <div class="space-y-1.5">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Story') }}</span>
                <p class="text-xs text-slate-400">{{ __('The narrative arc and framework this lesson is built on.') }}</p>
                <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2]) }}" wire:navigate
                   class="btn btn-sm btn-outline mt-1 border-slate-600 text-slate-200 hover:border-amber-400 hover:text-amber-300">
                    {{ __('Edit story') }}
                </a>
            </div>

            {{-- ── Background Music (global) ──────────────────── --}}
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
        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('settingsOpen', false)"></button>
    </div>
        
    {{-- Bottom CTA removed: the Play button (top toolbar) opens the player, and the back
         arrow (top-left) exits to the dashboard. --}}

    {{-- Publish feedback banner (no global toast system yet) — auto-clears after 5s. Bottom-centre
         so it clears the top toolbar, step indicator and inspector. --}}
    @if ($publishNotice)
        <div class="fixed bottom-6 left-1/2 z-[70] -translate-x-1/2"
             wire:key="publish-notice"
             x-data x-init="setTimeout(() => $wire.set('publishNotice', null), 5000)">
            <div @class([
                'flex items-center gap-2 rounded-lg border px-4 py-2.5 text-sm shadow-2xl',
                'border-emerald-600 bg-emerald-950 text-emerald-200' => $publishOk,
                'border-amber-600 bg-amber-950 text-amber-200' => ! $publishOk,
            ])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0" aria-hidden="true">
                    @if ($publishOk)
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    @endif
                </svg>
                {{ $publishNotice }}
            </div>
        </div>
    @endif

    {{-- Scene canvas tools — horizontal strip pinned to the top-left of the canvas, below the
         app header and right of the scene rail. Replaces the orphaned [T] button that used to
         sit in the bottom bar. Kept clear of the bottom-left scene caption (flag/year/location). --}}
    {{-- wire:ignore: the tools are static, so the 3s status poll must not morph this strip —
         that would re-init Alpine and snap the rectangle popover shut mid-interaction. --}}
    {{-- View state store — drives the View menu toggles: Scenes (rail), Object list, Rulers,
         Internal notes. Persisted to localStorage; the rail also syncs its Scenes checkbox. --}}
    @push('scripts')
    <script>
    // Object list — reads the live text layer (title/text boxes + backing panels) so the teacher
    // can find and flash-locate objects that overlap on the stage.
    window.objectList = function objectList() {
        return {
            items: [],
            init() {
                this.refresh();
                setInterval(() => { if (window.Alpine?.store('view')?.objects) this.refresh(); }, 2000);
            },
            refresh() {
                const texts = (window.__lessonTextLayer && window.__lessonTextLayer._texts) || [];
                const items = texts.map((t) => t.kind === 'rect'
                    ? { id: t.id, tag: 'Panel', label: (t.side || 'left') + ' half' }
                    : { id: t.id, tag: 'Text', label: (t.text || 'Text').slice(0, 40) });
                // The background is the bottom-most object on every scene — always listed last.
                items.push({ id: '__bg__', tag: 'BG', label: 'Background', bg: true });
                this.items = items;
            },
            locate(obj) {
                if (obj.bg) {
                    // Route to the Background settings in the inspector (scroll + flash them).
                    const insp = document.querySelector('aside[x-ref="inspectorPanel"]');
                    const label = insp && [...insp.querySelectorAll('*')].find(
                        (el) => el.children.length === 0 && el.textContent.trim().toUpperCase() === 'BACKGROUND');
                    const target = label?.parentElement || label;
                    if (target) {
                        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        target.style.transition = 'box-shadow .2s';
                        target.style.boxShadow = '0 0 0 2px #38bdf8';
                        setTimeout(() => { target.style.boxShadow = ''; }, 900);
                    }
                    return;
                }
                const node = document.querySelector(`[data-text-id="${obj.id}"]`);
                if (!node) return;
                node.scrollIntoView({ block: 'center', behavior: 'smooth' });
                node.style.outline = '2px solid #38bdf8';
                node.style.outlineOffset = '2px';
                setTimeout(() => { node.style.outline = ''; node.style.outlineOffset = ''; }, 900);
            },
        };
    };
    document.addEventListener('alpine:init', () => {
        Alpine.store('view', {
            scenes: true, objects: false, rulers: false, notes: false, railLast: 176,
            init() {
                try { Object.assign(this, JSON.parse(localStorage.getItem('wizard.view') || '{}')); } catch (_) {}
                const w = parseFloat(localStorage.getItem('wizard.rail.w'));
                if (Number.isFinite(w)) this.scenes = w > 0;
            },
            _save() {
                localStorage.setItem('wizard.view', JSON.stringify({
                    scenes: this.scenes, objects: this.objects, rulers: this.rulers,
                    notes: this.notes, railLast: this.railLast,
                }));
            },
            toggleScenes() {
                const el = document.documentElement;
                if (this.scenes) {
                    const cur = parseFloat(getComputedStyle(el).getPropertyValue('--rail-w')) || this.railLast;
                    if (cur > 0) this.railLast = cur;
                    el.style.setProperty('--rail-w', '0px');
                    localStorage.setItem('wizard.rail.w', '0');
                    this.scenes = false;
                } else {
                    const w = this.railLast || 176;
                    el.style.setProperty('--rail-w', w + 'px');
                    localStorage.setItem('wizard.rail.w', String(w));
                    this.scenes = true;
                }
                this._save();
            },
            toggle(k) { this[k] = !this[k]; this._save(); },
        });
    });
    </script>
    @endpush

    {{-- Top toolbar — Keynote-style: Play + insert tools as stacked icon-over-label buttons.
         Solid background (no opacity/blur) so the composition never shows through. wire:ignore
         keeps the 3s status poll from morphing the strip and snapping the Panel popover shut. --}}
    <div class="fixed right-0 top-0 z-40 flex h-16 items-center justify-between border-b border-slate-800 bg-slate-900 px-3 shadow-lg"
         style="left: max(var(--rail-w, 11rem), 3.25rem)"
         wire:ignore
         x-data="{ rectOpen: false, viewOpen: false }">
        {{-- Left group: View menu + Play + insert tools --}}
        <div class="flex items-stretch gap-0.5">
        {{-- View menu — show/hide workspace surfaces (Keynote's View) --}}
        <div class="relative">
            <button type="button" @click="viewOpen = !viewOpen"
                    class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-amber-300"
                    :class="viewOpen && 'text-amber-300'"
                    title="{{ __('Show or hide workspace panels') }}" aria-label="{{ __('View') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
                </svg>
                <span class="text-[10px] font-medium">{{ __('View') }}</span>
            </button>
            <div x-show="viewOpen" x-cloak @click.outside="viewOpen = false" x-transition.opacity.duration.150ms
                 class="absolute left-0 top-full z-50 mt-1.5 w-56 rounded-xl border border-slate-700 bg-slate-900 p-1.5 shadow-2xl">
                <p class="px-2 py-1 text-[10px] uppercase tracking-widest text-slate-500">{{ __('Show') }}</p>
                <template x-for="item in [
                    { k: 'scenes',  label: @js(__('Scenes')) },
                    { k: 'objects', label: @js(__('Object list')) },
                    { k: 'rulers',  label: @js(__('Rulers')) },
                    { k: 'notes',   label: @js(__('Internal notes')) },
                ]" :key="item.k">
                    <button type="button"
                            @click="(item.k === 'scenes' ? $store.view.toggleScenes() : $store.view.toggle(item.k)); viewOpen = false"
                            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-200 hover:bg-slate-800">
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center text-amber-400">
                            <svg x-show="$store.view[item.k]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3.5 w-3.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </span>
                        <span x-text="item.label"></span>
                    </button>
                </template>
            </div>
        </div>

        <div class="mx-1 my-1.5 w-px bg-slate-700"></div>

        {{-- Play → open the player (step 5) --}}
        <button type="button"
                onclick="Livewire.dispatch('lesson:play')"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-emerald-300 transition hover:bg-base-200"
                title="{{ __('Play the lesson') }}" aria-label="{{ __('Play the lesson') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Play') }}</span>
        </button>

        <div class="mx-1 my-1.5 w-px bg-slate-700"></div>

        {{-- Text --}}
        <button type="button"
                onclick="window.dispatchEvent(new CustomEvent('lesson:add-text'))"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-base-200 hover:text-amber-300"
                title="{{ __('Add text (drag to move; paste a link to embed a page)') }}" aria-label="{{ __('Add text') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5m16.5-1.5V6A2.25 2.25 0 0 0 18 3.75h-1.5m0 16.5H18A2.25 2.25 0 0 0 20.25 18v-1.5M15 12H9m1.5 8.25H6A2.25 2.25 0 0 1 3.75 18v-1.5M12 8.25v7.5" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Text') }}</span>
        </button>

        {{-- Panel (backing rectangle, left/right half) --}}
        <div class="relative">
            <button type="button" @click="rectOpen = !rectOpen"
                    class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-base-200 hover:text-amber-300"
                    :class="rectOpen && 'text-amber-300'"
                    title="{{ __('Add a panel (left or right half) behind your text') }}" aria-label="{{ __('Add panel') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m-4.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z" />
                </svg>
                <span class="text-[10px] font-medium">{{ __('Panel') }}</span>
            </button>
            <div x-show="rectOpen" x-cloak @click.outside="rectOpen = false"
                 x-transition.opacity.duration.150ms
                 class="absolute left-0 top-full z-50 mt-1.5 w-40 rounded-xl border border-slate-700 bg-base-300 p-1.5 shadow-2xl">
                <p class="px-2 py-1 text-[10px] uppercase tracking-widest text-slate-500">{{ __('Backing panel') }}</p>
                <button type="button"
                        @click="window.dispatchEvent(new CustomEvent('lesson:add-rect', { detail: { side: 'left' } })); rectOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-200 hover:bg-base-200">
                    <span class="flex h-4 w-6 overflow-hidden rounded border border-slate-600"><span class="w-1/2 bg-amber-400/70"></span></span>
                    {{ __('Left half') }}
                </button>
                <button type="button"
                        @click="window.dispatchEvent(new CustomEvent('lesson:add-rect', { detail: { side: 'right' } })); rectOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-200 hover:bg-base-200">
                    <span class="flex h-4 w-6 justify-end overflow-hidden rounded border border-slate-600"><span class="w-1/2 bg-amber-400/70"></span></span>
                    {{ __('Right half') }}
                </button>
            </div>
        </div>

        {{-- Clipart — insert a public-domain SVG as an object/layer on top of the background --}}
        <button type="button"
                onclick="Livewire.dispatch('open-svg-library')"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-base-200 hover:text-amber-300"
                title="{{ __('Insert clipart (public-domain line-art)') }}" aria-label="{{ __('Insert clipart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42"/>
            </svg>
            <span class="text-[10px] font-medium">{{ __('Clipart') }}</span>
        </button>
        </div>

        {{-- Right group: global actions, pushed to the far right --}}
        <div class="flex items-stretch gap-0.5">
        {{-- Settings — global class/lesson settings (Story + Music). Lives on the toolbar, not
             inside the per-scene inspector. --}}
        <button type="button"
                onclick="Livewire.dispatch('open-lesson-settings')"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-amber-300"
                title="{{ __('Class & lesson settings') }}" aria-label="{{ __('Settings') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Settings') }}</span>
        </button>

        {{-- Publish — make the lesson available (every scene must be ready) --}}
        <button type="button"
                onclick="Livewire.dispatch('lesson:publish')"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-emerald-300 transition hover:bg-slate-800"
                title="{{ __('Publish this lesson') }}" aria-label="{{ __('Publish') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Publish') }}</span>
        </button>
        </div>
    </div>

    {{-- ══ View surfaces (toggled by the View menu) ══════════════════════════════════ --}}

    {{-- Rulers — hug the actual canvas stage (top edge + left edge), positioned by JS so they
         always sit flush against the canvas and shift right when the rail/object list push it.
         The canvas reserves --ruler-w on its top & left so the rulers never overlap it. --}}
    <div id="ruler-top" x-show="$store.view.rulers" x-cloak class="pointer-events-none fixed z-30"
         style="background: #0f172a repeating-linear-gradient(to right, rgba(148,163,184,.5) 0 1px, transparent 1px 50px);
                border-bottom: 1px solid rgba(148,163,184,.3);"></div>
    <div id="ruler-left" x-show="$store.view.rulers" x-cloak class="pointer-events-none fixed z-30"
         style="background: #0f172a repeating-linear-gradient(to bottom, rgba(148,163,184,.5) 0 1px, transparent 1px 50px);
                border-right: 1px solid rgba(148,163,184,.3);"></div>
    @push('scripts')
    <script>
    (() => {
        const RW = 16;
        const boot = () => {
            const canvas = document.getElementById('lesson-canvas-root');
            const top = document.getElementById('ruler-top');
            const left = document.getElementById('ruler-left');
            if (!canvas || !top || !left) return;
            const place = () => {
                const r = canvas.getBoundingClientRect();
                Object.assign(top.style, { left: `${r.left}px`, top: `${r.top - RW}px`, width: `${r.width}px`, height: `${RW}px` });
                Object.assign(left.style, { left: `${r.left - RW}px`, top: `${r.top}px`, width: `${RW}px`, height: `${r.height}px` });
            };
            place();
            window.__placeRulers = place;   // let the View x-effect nudge it on toggle
            new ResizeObserver(place).observe(canvas);
            window.addEventListener('resize', place);
            // Position-only shifts (rail collapse, object-list toggle) may not resize the canvas
            // enough to fire the observer — a light poll keeps the rulers glued to the stage.
            setInterval(() => { if (window.Alpine?.store('view')?.rulers) place(); }, 120);
        };
        if (document.readyState !== 'loading') boot();
        else document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('livewire:navigated', boot);
    })();
    </script>
    @endpush

    {{-- Object list — a full-height panel docked to the right of the Scenes rail. Lists the
         scene's objects (title/text boxes, backing panels); click to flash-locate on the stage.
         No heading — teachers recognise their own objects. --}}
    <div x-show="$store.view.objects" x-cloak x-data="objectList()" x-init="init()"
         @scene-objects-changed.window="refresh()"
         class="fixed z-30 overflow-hidden border-r border-slate-700 bg-slate-900"
         style="left: var(--rail-w, 11rem); width: 13rem; top: 4rem; bottom: 0;">
        <div class="h-full space-y-0.5 overflow-y-auto p-1.5">
            <template x-for="obj in items" :key="obj.id">
                <button type="button" @click="locate(obj)"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-slate-200 hover:bg-slate-800">
                    <span class="text-[10px] uppercase tracking-wide text-amber-400/80" x-text="obj.tag"></span>
                    <span class="truncate" x-text="obj.label"></span>
                </button>
            </template>
        </div>
    </div>

    {{-- Internal notes — the teacher's private per-lesson scratchpad (this browser). --}}
    <div x-show="$store.view.notes" x-cloak
         x-data="{ note: '', key: 'wizard.notes.{{ $lesson->id }}',
                   init() { this.note = localStorage.getItem(this.key) || ''; },
                   save() { localStorage.setItem(this.key, this.note); } }"
         class="fixed bottom-4 right-4 z-40 w-72 overflow-hidden rounded-xl border border-slate-700 bg-slate-900 shadow-2xl"
         style="right: calc(var(--work-right, 24rem) + 0.75rem);">
        <div class="flex items-center justify-between border-b border-slate-700/60 bg-slate-800/60 px-3 py-2">
            <span class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">{{ __('Internal notes') }}</span>
            <button type="button" @click="$store.view.toggle('notes')" class="text-slate-500 hover:text-slate-200" aria-label="Close">✕</button>
        </div>
        <textarea x-model="note" @input.debounce.400ms="save()" rows="6"
                  placeholder="{{ __('Private notes for this lesson — only you see these.') }}"
                  class="w-full resize-none border-0 bg-transparent p-3 text-sm text-slate-200 focus:outline-none"></textarea>
    </div>

    {{-- Scene rail (vertical, left edge) --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" editable />

    {{-- Rail resize handle — drag to resize the scene rail; drag to the edge to hide it entirely.
         Sits at the rail's right edge; when the rail is collapsed (0) it rests at the far left so
         it can be dragged back out. --}}
    {{-- Straddles the rail's own border (no extra line of its own → no double line). On hover the
         panel edge lights up bright blue; the cursor already signals resize. --}}
    <div id="rail-resize" wire:ignore
         class="group fixed bottom-0 top-16 z-40 -ml-1.5 w-3 cursor-col-resize"
         style="left: var(--rail-w, 11rem)"
         role="separator" aria-orientation="vertical" aria-label="{{ __('Resize scene rail') }}"
         title="{{ __('Drag to resize · drag to the edge to hide') }}">
        <div class="mx-auto h-full w-0.5 bg-transparent transition-colors group-hover:bg-sky-400"></div>
    </div>
    @push('scripts')
    <script>
    (() => {
        const KEY = 'wizard.rail.w';
        const rootEl = document.documentElement;
        const clamp = (v) => Math.max(0, Math.min(280, v));
        const saved = parseFloat(localStorage.getItem(KEY));
        if (Number.isFinite(saved)) rootEl.style.setProperty('--rail-w', saved + 'px');
        const boot = () => {
            const handle = document.getElementById('rail-resize');
            if (!handle || handle.__wired) return;
            handle.__wired = true;
            let dragging = false;
            handle.addEventListener('pointerdown', (e) => {
                dragging = true; handle.setPointerCapture(e.pointerId);
                document.body.style.userSelect = 'none';
                e.preventDefault();
            });
            handle.addEventListener('pointermove', (e) => {
                if (!dragging) return;
                rootEl.style.setProperty('--rail-w', clamp(e.clientX) + 'px');
            });
            const end = (e) => {
                if (!dragging) return;
                dragging = false;
                try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
                document.body.style.userSelect = '';
                let w = clamp(parseFloat(rootEl.style.getPropertyValue('--rail-w')) || 176);
                if (w < 56) w = 0;                 // dragged to the edge → hide
                else if (w < 120) w = 120;         // otherwise keep a usable minimum
                rootEl.style.setProperty('--rail-w', w + 'px');
                localStorage.setItem(KEY, String(w));
                // Keep the View ▸ Scenes checkbox in sync with a drag-to-hide / drag-out.
                const store = window.Alpine?.store('view');
                if (store) { store.scenes = w > 0; if (w > 0) store.railLast = w; store._save?.(); }
            };
            handle.addEventListener('pointerup', end);
            handle.addEventListener('pointercancel', end);
        };
        if (document.readyState !== 'loading') boot();
        else document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('livewire:navigated', boot);
    })();
    </script>
    @endpush

    {{-- Add-scene picker (Keynote-style). Replaces the old DaisyUI dropdown (broke in v5: the
         menu stayed opacity:0 on focus). Open state is Livewire-driven; tiles call addScene(). --}}
    <div class="modal modal-bottom sm:modal-middle {{ $addSceneOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-lg border border-slate-700/70 bg-base-300">
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

    {{-- Painting picker — public-domain artworks from the corpus. Click a thumbnail to set it
         as this scene's background (downloaded to lesson storage; attribution stored on the scene). --}}
    <div class="modal modal-bottom sm:modal-middle {{ $paintingPickerOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-3xl border border-slate-700/70 bg-base-300">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-slate-100">{{ __('Painting backgrounds') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm btn-circle text-slate-400"
                        aria-label="Close" wire:click="$set('paintingPickerOpen', false)">✕</button>
            </div>

            <label class="input input-sm input-bordered flex items-center gap-2 bg-slate-900 mb-3">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 text-slate-500">
                    <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                </svg>
                <input type="search"
                       wire:model.live.debounce.400ms="paintingQuery"
                       placeholder="{{ __('Search paintings or painters (e.g. senaat, Caesar, Rembrandt)…') }}"
                       class="grow bg-transparent" />
            </label>

            {{-- Kind filter: everything / paintings / historical city plans (Braun & Hogenberg e.a.) --}}
            <div class="mb-3 flex items-center gap-1.5">
                @foreach (['' => __('Everything'), 'painting' => __('Paintings'), 'city_map' => __('City plans')] as $kindVal => $kindLabel)
                    <button type="button"
                            wire:click="$set('paintingKind', '{{ $kindVal }}')"
                            class="btn btn-xs {{ $paintingKind === $kindVal ? 'bg-amber-500 text-slate-950 border-0 hover:bg-amber-400' : 'btn-outline border-slate-600 text-slate-400 hover:border-amber-400 hover:text-amber-300' }}">
                        {{ $kindLabel }}
                    </button>
                @endforeach
            </div>

            <div wire:loading.delay
                 wire:target="paintingQuery, applyPaintingBackground, paintingPickerOpen, paintingKind"
                 class="flex items-center gap-2 py-2 text-xs text-slate-400">
                <x-icons.spinner class="w-3.5 h-3.5 animate-spin" />
                <span>{{ __('Loading…') }}</span>
            </div>

            @if ($paintingPickerOpen)
                <div class="grid max-h-[55vh] grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3"
                     wire:loading.class="opacity-40" wire:target="applyPaintingBackground">
                    @forelse ($this->paintingResults as $art)
                        <button type="button"
                                wire:key="art-{{ md5($art['source'].$art['key']) }}"
                                wire:click="applyPaintingBackground('{{ $art['source'] }}', '{{ addslashes($art['key']) }}')"
                                wire:loading.attr="disabled" wire:target="applyPaintingBackground"
                                class="group relative block overflow-hidden rounded-lg ring-1 ring-slate-700 transition hover:ring-2 hover:ring-amber-400 disabled:cursor-wait"
                                style="aspect-ratio:16/10"
                                title="{{ trim($art['title'].' — '.$art['caption'], ' —') }}">
                            <img src="{{ $art['thumb'] }}" loading="lazy" alt=""
                                 class="h-full w-full object-cover transition group-hover:scale-105" />
                            @if (($art['kind'] ?? 'painting') === 'city_map')
                                <span class="absolute right-1 top-1 rounded bg-sky-600/90 px-1 text-[8px] font-semibold uppercase tracking-wider text-white">
                                    {{ __('plan') }}
                                </span>
                            @endif
                            @if (! empty($art['correctness']))
                                <span class="absolute left-1 top-1 rounded bg-emerald-600/90 px-1 text-[8px] font-semibold uppercase tracking-wider text-white"
                                      title="{{ __('Match correctness — soft criteria met') }}">
                                    ✓ {{ $art['correctness'] }}
                                </span>
                            @endif
                            <span class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1.5 py-0.5 text-left text-[9px] text-white">
                                {{ $art['title'] }}@if($art['caption']) · {{ $art['caption'] }}@endif
                            </span>
                        </button>
                    @empty
                        <p class="col-span-full py-6 text-center text-sm text-slate-400">
                            {{ __('No paintings found — try a name, place or event (Dutch or English).') }}
                        </p>
                    @endforelse
                </div>
                @unless ($paintingCommonsLoaded)
                    <button type="button"
                            wire:click="$set('paintingCommonsLoaded', true)"
                            wire:loading.attr="disabled" wire:target="paintingCommonsLoaded"
                            class="btn btn-xs btn-outline mt-2 border-slate-600 text-slate-300 hover:border-sky-400 hover:text-sky-300 inline-flex items-center gap-1.5">
                        <span wire:loading wire:target="paintingCommonsLoaded"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                        <span wire:loading.remove wire:target="paintingCommonsLoaded">{{ __('More from Wikimedia Commons') }}</span>
                        <span wire:loading wire:target="paintingCommonsLoaded">{{ __('Searching Wikimedia…') }}</span>
                    </button>
                @endunless
                <p class="mt-2 text-[10px] text-slate-500">
                    {{ __('Public-domain works from Wikimedia Commons. The painting is saved to this lesson and credited automatically.') }}
                </p>
            @endif
        </div>
        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('paintingPickerOpen', false)"></button>
    </div>

    {{-- Ink artwork library — teacher-imported public-domain SVGs, drawn line-by-line.
         The nested Livewire component only mounts while the modal is open. --}}
    <div class="modal modal-bottom sm:modal-middle {{ $svgLibraryOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-4xl border border-slate-700/70 bg-base-300">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-slate-100">{{ __('Ink artwork library') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm btn-circle text-slate-400"
                        aria-label="Close" wire:click="$set('svgLibraryOpen', false)">✕</button>
            </div>
            @if ($svgLibraryOpen)
                <livewire:svg-asset-library :key="'svg-library-'.$lesson->id" />
            @endif
        </div>
        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('svgLibraryOpen', false)"></button>
    </div>

    {{-- Scenes payload as inert JSON so we don't string-interpolate it into JS --}}
    <script type="application/json" id="step3-scenes-data">
        {!! $this->scenes->map(fn ($s) => array_merge(
            $s->only(['id','kind','game_type','quiz_question_count','quiz_timing','strategy_game_id','team_count','year','location','image_path','scene_view','skybox_blur','world_pano_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id','background_color','kb_animated','kb_direction']),
            ['shots' => $this->serializeShots($s)],
        ))->toJson() !!}
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
                // Docked (default): pinned flush to the right edge, full height below the header.
                // Dragging the header pops it out into a free-floating panel.
                docked: true,
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
                    // Docked is the standard state — default to it unless the teacher undocked before.
                    this.docked = (localStorage.getItem('wizard.inspector.docked') ?? '1') === '1';
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

                // App nav height (h-16 = 64px) — the docked panel sits flush under it, no gap.
                _headerOffset: 64,

                inspectorPanelStyle() {
                    if (this.docked) {
                        // Flush right, below the header. Full height when open; just the bar when collapsed.
                        return this.inspectorOpen
                            ? `right:0; left:auto; top:${this._headerOffset}px; bottom:0;`
                            : `right:0; left:auto; top:${this._headerOffset}px;`;
                    }
                    return `left:${this.inspectorX}px; top:${this.inspectorY}px;`;
                },

                inspectorBodyStyle() {
                    const maxHeight = this.docked
                        ? window.innerHeight - this._headerOffset - 44   // viewport − header − card title bar
                        : Math.max(180, Math.min(680, window.innerHeight - this.inspectorY - 132));
                    return `max-height:${maxHeight}px;`;
                },

                toggleInspector() {
                    this.inspectorOpen = !this.inspectorOpen;
                },

                setDocked(v) {
                    this.docked = v;
                    localStorage.setItem('wizard.inspector.docked', v ? '1' : '0');
                },

                // Re-dock to the right edge (the ⟲ button / standard state).
                dockInspector() {
                    this.setDocked(true);
                },

                // Pop the panel out of the dock into a free-floating panel at its current spot.
                undockInspector() {
                    const rect = this.$refs.inspectorPanel?.getBoundingClientRect();
                    this.inspectorX = rect ? rect.left : Math.max(8, window.innerWidth - this.inspectorPanelWidth() - 16);
                    this.inspectorY = rect ? rect.top : this._headerOffset;
                    this.setDocked(false);
                },

                resetInspectorPosition() {
                    const panelWidth = this.inspectorPanelWidth();
                    this.inspectorX = Math.max(8, window.innerWidth - panelWidth - 16);
                    this.inspectorY = 16;
                    this.$nextTick(() => this.constrainInspectorPosition());
                },

                restoreInspectorPosition() {
                    if (this.docked) return;   // docked ignores stored free position
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
                    // Grabbing the header of a docked panel pulls it out into free-floating mode.
                    if (this.docked) this.undockInspector();
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
                    if (this.docked) return;   // docked panel is CSS-pinned, nothing to clamp
                    const panel = this.$refs.inspectorPanel;
                    const rect = panel?.getBoundingClientRect();
                    const margin = 8;
                    // Keep the panel clear of the vertical scene rail (w-44 = 176px) — also
                    // migrates positions saved before the rail existed out of the overlap zone.
                    const railReserve = 176 + margin;
                    const bottomReserve = 112;
                    const width = rect?.width || this.inspectorPanelWidth();
                    const height = rect?.height || 44;
                    const maxX = Math.max(railReserve, window.innerWidth - width - margin);
                    const maxY = Math.max(margin, window.innerHeight - height - bottomReserve);

                    this.inspectorX = this.clamp(this.inspectorX, railReserve, maxX);
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
