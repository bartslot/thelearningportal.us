@push('head-scripts')
    @vite('resources/js/lesson-map.js')
    {{-- The gallery renderer is dependency-free and ANY lesson can hold a gallery scene — including
         one the teacher adds without reloading the page — so it always loads. Gating it behind
         game_type === 'voyage' left window.renderGallery undefined in a normal lesson, and selecting
         a gallery scene died with "renderer unavailable". --}}
    @vite('resources/js/gallery-scene.js')
    {{-- voyage-tour stays gated: it drags in ships/fog/etch, which only a voyage lesson needs. --}}
    @if (($lesson->game_type ?? null) === 'voyage')
        @vite('resources/js/voyage-tour.js')
    @endif
@endpush

<div class="contents" x-data="step3SceneConfigurator" wire:poll.3s
     {{-- Optional chaining throughout: this effect runs on first paint, which can beat the view
          store's registration (a pushed script racing alpine:init). Without the `?.` the effect
          throws and Alpine tears down the component's reactive effects. --}}
     x-effect="document.documentElement.style.setProperty('--objlist-w', $store.view?.objects ? ($store.view?.objectsW ?? 208) + 'px' : '0px');
               document.documentElement.style.setProperty('--ruler-w', $store.view?.rulers ? '20px' : '0px');
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
    {{-- --work-right lives on <html> (set by the sync below) so the sibling script/notes panels
         inherit it too — the canvas-root is not their ancestor. --work-left/--work-bottom stay
         local to the canvas-root (only it needs them). --}}
    <div class="fixed z-0 bg-slate-950" id="lesson-canvas-root"
         style="--work-left: calc(var(--rail-w, 11rem) + var(--objlist-w, 0px) + var(--ruler-w, 0px)); --work-bottom: 0px;
                --top-inset: calc(4rem + var(--ruler-w, 0px));
                --lbw: calc(100vw - var(--work-left) - var(--work-right, 16rem));
                --lbh: min(calc(var(--lbw) * 0.5625), calc(100vh - var(--top-inset) - var(--work-bottom)));
                left: var(--work-left); right: var(--work-right, 16rem);
                height: var(--lbh); top: calc(var(--top-inset) + (100vh - var(--top-inset) - var(--work-bottom) - var(--lbh)) / 2);"
         data-character-url=""
         data-territory="{{ $lesson->topic }}"
         data-flag="{{ $lesson->territoryFlagUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        {{-- Cinematic film-grain overlay (reuses the .lp-grain brand utility). --}}
        <div class="lp-grain pointer-events-none absolute inset-0 z-3"></div>
        {{-- Scene overlay (flag + territory title). z-[6] so it sits ABOVE the map preview (z-[5]),
             not hidden behind the globe. pointer-events-none keeps the map interactive.
             NO vertical padding: SceneOverlay sets container-type:size on this host, and padding
             would force a 256px min-height that shoves the bottom-anchored caption off a short
             stage. overflow-hidden clips the caption to the canvas as a final guard on tiny stages. --}}
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none overflow-hidden z-6"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
        {{-- Teacher text annotations (Freeform-style, draggable). z-[7]: above scene art + map. --}}
        <div id="lesson-text-overlay" class="absolute inset-0 z-7"></div>
        {{-- Map block preview — overlays the canvas when a map/voyage/gallery scene is selected.
             Bounded to the WORK AREA (between the rail and the docked inspector) so the scene is
             fully visible and never slides under either panel — the gallery's side text stays on
             screen. Fixed positioning with all four edges set keeps an explicit box, so MapLibre's
             `position:relative` stamp on its inner child can't collapse the host to height 0. --}}
        <div id="lesson-map-preview" class="fixed z-5" wire:ignore
             style="display:none;
                    left: var(--work-left); right: var(--work-right, 16rem);
                    top: var(--top-inset); bottom: var(--work-bottom, 0px);"></div>
        {{-- Editable clipart layers a teacher drops ON TOP of a Route waypoint (voyage) map. Same
             letterboxed coordinate box as the text overlay (and the student player), so a layer's
             %-position matches between editor and playback. z-6: above the map (z-5), below text
             (z-7) by default; raised to z-8 when the teacher stacks clipart above text. The host is
             transparent + pointer-events:none (only the layer nodes catch), so map panning still
             works between layers. --}}
        <div id="lesson-voyage-art" class="absolute inset-0 z-6" style="display:none; pointer-events:none;"></div>
    </div>

    {{-- Work-area sync — keep the stage's right edge glued to the live inspector width (16rem
         normal, 48rem for game scenes, 0 when hidden). The inspector is always docked (no
         floating), so we reserve exactly its width. The stage tracks it via --work-right; a
         resize nudge lets the WebGL scene refit. --}}
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
                // Docked panel → reserve its width; hidden (x-show off → 0 width) → reserve nothing.
                // Set it on <html> so the sibling script/notes panels inherit it (the canvas-root
                // is not their ancestor); the canvas-root inherits it too.
                document.documentElement.style.setProperty('--work-right', r.width > 0 ? `${Math.round(r.width)}px` : '0px')
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

    {{-- Fog paint brush — when active, dragging over the voyage map paints an "undiscovered" region.
         Each stroke → a geo ring (inst.strokeToRing) → live fog (inst.setPaintedFog) + persisted
         ($wire.addFogRegion). Paint state lives on window.__voyagePaint, toggled from the inspector. --}}
    @push('scripts')
    <script>
    window.__voyagePaint = window.__voyagePaint || { active: false, erase: false, brushKm: 250 };
    // Set once from a preview "Edit scene?modal=1" deep-link; the voyage tour consumes it on arrival.
    if (window.__openGalleryOnLoad === undefined) window.__openGalleryOnLoad = @js((bool) ($openModalOnLoad ?? false));
    // Resolve THIS wizard's Step-3 component (NOT the outer lesson-wizard) so $wire calls land right.
    window.__step3Wire = () => {
        try { const c = window.Livewire.all().find((x) => x.name === 'wizard.step3-scene-configurator'); return c ? (c.$wire || window.Livewire.find(c.id).$wire) : null; } catch (_) { return null; }
    };
    window.__mountVoyagePaint = (inst, stageInner, payload) => {
        if (!inst || typeof inst.strokeToRing !== 'function') return;
        // The painted regions live on the tour instance (inst.getPaintedFog) — the single source of
        // truth. Reading it fresh every time keeps paint/erase/undo in sync with server resets, which
        // arrive via scene:load → inst.setPaintedFog(); a private copy here would drift and resurrect
        // erased regions on the next stroke.
        const brushKm = () => Number(window.__voyagePaint.brushKm) || 250;

        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:absolute;inset:0;z-index:30;touch-action:none;';
        stageInner.appendChild(overlay);
        const ink = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        ink.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;';
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('fill', 'none'); path.setAttribute('stroke', 'rgba(148,163,184,0.55)');
        path.setAttribute('stroke-linecap', 'round'); path.setAttribute('stroke-linejoin', 'round');
        // Live brush cursor — a ring sized to the ACTUAL painted footprint (brushKm → screen pixels).
        const cursor = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        cursor.setAttribute('fill', 'rgba(148,163,184,0.18)'); cursor.setAttribute('stroke', 'rgba(226,232,240,0.9)');
        cursor.setAttribute('stroke-width', '1.5'); cursor.setAttribute('r', '0'); cursor.style.display = 'none';
        ink.appendChild(path); ink.appendChild(cursor); overlay.appendChild(ink);

        let drawing = false, pts = [], lastR = 20;
        const sync = () => {
            const on = !!window.__voyagePaint.active;
            overlay.style.pointerEvents = on ? 'auto' : 'none';
            overlay.style.cursor = on ? 'none' : '';         // hide the OS cursor; the ring IS the cursor
            if (!on) { cursor.style.display = 'none'; path.setAttribute('d', ''); }
        };
        sync();
        window.addEventListener('voyage-paint-changed', sync);

        const isErase = () => !!window.__voyagePaint.erase;
        const rel = (e) => { const r = overlay.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
        const showCursor = (p) => {
            lastR = inst.brushRadiusPx(p, brushKm());
            // Both paint and erase show the TRUE brush footprint (same size slider) — amber to add fog,
            // red to reveal. The drawn band matches the painted/erased band.
            const r = Math.max(4, lastR);
            cursor.setAttribute('cx', p.x); cursor.setAttribute('cy', p.y); cursor.setAttribute('r', r);
            cursor.setAttribute('stroke', isErase() ? 'rgba(248,113,113,0.95)' : 'rgba(226,232,240,0.9)');
            cursor.setAttribute('fill', isErase() ? 'rgba(248,113,113,0.15)' : 'rgba(148,163,184,0.18)');
            cursor.style.display = 'block';
            path.setAttribute('stroke', isErase() ? 'rgba(248,113,113,0.55)' : 'rgba(245,158,11,0.5)');
            path.setAttribute('stroke-width', Math.max(4, lastR * 2));   // the drawn band == the painted band
        };
        overlay.addEventListener('pointerdown', (e) => {
            if (!window.__voyagePaint.active) return;
            // Paint AND erase are the same gesture — a brush stroke sized by the shared Brush size slider.
            drawing = true; pts = [rel(e)]; overlay.setPointerCapture(e.pointerId); e.preventDefault();
        });
        overlay.addEventListener('pointermove', (e) => {
            if (!window.__voyagePaint.active) return;
            const p = rel(e);
            showCursor(p);
            if (!drawing) return;
            pts.push(p);
            path.setAttribute('d', pts.map((q, i) => `${i ? 'L' : 'M'}${q.x},${q.y}`).join(' '));
        });
        overlay.addEventListener('pointerleave', () => { if (!drawing) cursor.style.display = 'none'; });
        const finish = () => {
            if (!drawing) return; drawing = false;
            path.setAttribute('d', '');
            const ring = inst.strokeToRing(pts, brushKm());
            pts = [];
            if (!ring) return;
            const w = window.__step3Wire();
            if (isErase()) {
                // Subtract the brush footprint from the painted fog (turf.difference) → reveal.
                let next = null;
                try { next = inst.eraseFog(ring); } catch (_) { next = null; }
                if (next) {
                    try { inst.setPaintedFog(next); } catch (_) {}                                 // instant feedback
                    if (w && typeof w.setVoyageFog === 'function') { try { w.setVoyageFog(next); } catch (_) {} }  // persist
                }
                return;
            }
            try { inst.setPaintedFog([...inst.getPaintedFog(), ring]); } catch (_) {}   // instant feedback
            if (w && typeof w.addFogRegion === 'function') { try { w.addFogRegion(ring); } catch (_) {} }   // persist
        };
        overlay.addEventListener('pointerup', finish);
        overlay.addEventListener('pointercancel', finish);

        // CMD-Z / Ctrl-Z → undo the last painted region. Pop locally for instant feedback, then let
        // the server (undoFog) re-fire scene:load as the source of truth. One listener at a time:
        // drop the previous overlay's handler so re-mounts don't stack them.
        if (window.__voyageUndoHandler) document.removeEventListener('keydown', window.__voyageUndoHandler);
        window.__voyageUndoHandler = (e) => {
            if ((e.key !== 'z' && e.key !== 'Z') || !(e.metaKey || e.ctrlKey) || e.shiftKey) return;
            // Never steal the shortcut from a field the teacher is typing in.
            const t = e.target;
            if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;

            const w = window.__step3Wire();

            // While the fog brush is up, Cmd-Z takes back the last painted region.
            if (window.__voyagePaint.active) {
                const cur = inst.getPaintedFog();
                if (!cur.length) return;
                e.preventDefault();
                cur.pop();
                try { inst.setPaintedFog(cur); } catch (_) {}   // instant feedback; undoFog re-syncs from server
                if (w && typeof w.undoFog === 'function') { try { w.undoFog(); } catch (_) {} }
                return;
            }

            // Otherwise it takes back the last ROUTE edit. Dragging a waypoint or bending the
            // sailing line used to be unrecoverable: one slip rewrote the route for good.
            e.preventDefault();
            if (w && typeof w.undoVoyage === 'function') { try { w.undoVoyage(); } catch (_) {} }
        };
        document.addEventListener('keydown', window.__voyageUndoHandler);
    };
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

        // ── Editable clipart layers on a voyage map ───────────────────────────────
        // A single reusable ArtworkOverlay lives in #lesson-voyage-art (a canvas-root child, so it
        // survives map re-mounts). It's shown only on voyage scenes; its %-coordinates match the
        // student player because both use the same letterboxed box. Same object-list / selection /
        // delete plumbing as slideshow clipart, via window.__lessonArtworkLayer.
        let artOverlay = null
        let artSig = null
        const voyageArtHost = () => document.getElementById('lesson-voyage-art')
        const ensureArtOverlay = () => {
            if (artOverlay) return artOverlay
            const h = voyageArtHost()
            const Cls = window.LessonScene?.ArtworkOverlay
            if (!h || !Cls) return null   // scene bundle still loading — a later scene:load retries
            artOverlay = new Cls(h, {
                onChange: (assetId, t) =>
                    window.Livewire.dispatch('artwork:move', { assetId, x: t.x, y: t.y, scale: t.scale }),
            })
            return artOverlay
        }
        const showVoyageArt = (p) => {
            const overlay = ensureArtOverlay()
            const h = voyageArtHost()
            if (!overlay || !h) return
            const layers = ((p.shots || [])[0]?.layers || []).filter((l) => l && (l.url || l.embed) && l.asset_id != null)
            const onTop = !!(p.config || {}).clipart_on_top
            overlay.setOnTop(onTop)
            h.style.zIndex = onTop ? '8' : '6'      // above text (7) when stacked on top, else below it
            h.style.display = ''
            // Skip re-seeding on identical poll re-renders — it would reset an in-progress drag.
            const sig = JSON.stringify(layers.map((l) => [l.asset_id, l.x, l.y, l.scale, l.height, String(l.url || (l.embed && l.embed.src) || '').split('?')[0]]))
            if (sig !== artSig) { artSig = sig; overlay.setLayers(layers) }
            // A dedicated handle the object list reads on voyage scenes — the SHARED handle can be
            // repointed by a slideshow render (wizard-bridge), so it isn't reliable here.
            window.__voyageArtworkLayer = overlay
            window.__lessonArtworkLayer = overlay
        }
        const hideVoyageArt = () => {
            const h = voyageArtHost()
            if (h) h.style.display = 'none'
            artSig = null
            if (artOverlay) { try { artOverlay.clear() } catch (_) {} }
            window.__voyageArtworkLayer = null
            // Release the shared handle only if it still points at MY overlay (a slideshow scene
            // reclaims it on its own render).
            if (window.__lessonArtworkLayer === artOverlay) window.__lessonArtworkLayer = null
        }
        // The current voyage map centre — a new leg drops its destination here (the teacher pans to
        // roughly where the leg should end). null when no voyage map is mounted.
        window.__voyageCenter = () => { try { return (inst && inst.map) ? inst.map.getCenter() : null } catch (_) { return null } }
        let lastKey = null
        let lastVoyageId = null       // the mounted voyage's id — the ONLY thing that forces a REBUILD
        let lastVoyageDefStr = null   // last applied route geometry (waypoints/legs) — reshaped in place
        let lastLeg = null            // last-played leg index (for the no-rebuild leg switch)
        let lastGalleryId = null      // mounted standalone-gallery scene id — content edits refresh in place
        let currentSceneId = null     // the selected scene (callbacks read this, not the mount-time id)
        // Identity of the MAP is the VOYAGE ID alone. Switching legs, editing the route geometry
        // (dragging / inserting a waypoint) and toggling map detail all keep the same map instance —
        // we update it in place (playLeg / setVoyageDef / setMapOptions) instead of destroying +
        // rebuilding it, which reset the zoom / centre / globe-flat projection and reloaded every tile.
        const destroy = () => {
            if (inst) { inst.destroy(); inst = null }
            host.innerHTML = ''
            host.style.display = 'none'
            hideVoyageArt()
            lastKey = null; lastVoyageId = null; lastVoyageDefStr = null; lastLeg = null; lastGalleryId = null
        }

        // A re-mount key for voyage/gallery scenes: only scene-DEFINING bits belong in it, so the
        // 3s status poll (which re-fires scene:load) doesn't restart the sail / slideshow mid-view.
        const stageKey = (p) => {
            const cfg = p.config || {}
            if (p.kind === 'voyage') {
                const legDef = (p.voyage_def && p.voyage_def.legs) ? p.voyage_def.legs[Number(cfg.leg) || 0] : null
                // DELIBERATELY excluded (they apply LIVE, never re-mount): voyage_map + leg_labels
                // (setMapOptions), voyage_fog (setPaintedFog), route_line (setRouteLine), view
                // (setView) and intro (a playback-only flag, ignored in the editor's preview jump).
                // NOTE: route GEOMETRY (voyage_def.waypoints) is DELIBERATELY excluded — a waypoint edit
                // is handled by setVoyageDef() (defChanged), not by a content refresh. Including it here
                // too would double-build the HUD (setVoyageDef + refreshArrivalContent) → pins pop twice.
                return ['voyage', p.sceneId, cfg.voyage, cfg.leg,
                    legDef ? `${legDef.depart}_${legDef.arrive}` : '', (cfg.stop_images || []).join(','),
                    JSON.stringify(cfg.gallery || {})].join('|')
            }
            return ['gallery', p.sceneId, cfg.title, cfg.date_label, cfg.story, cfg.fit || 'fit', JSON.stringify(cfg.images || [])].join('|')
        }

        // Text labels can be pinned to the live map — wire the projector whenever both the
        // map instance and the text layer exist (either can be created first).
        const wireTextProjector = () => {
            const layer = window.__lessonTextLayer
            const overlayHost = document.getElementById('lesson-text-overlay')
            if (!layer) return
            // Only the MAP block projects pinned text labels. Voyage/gallery instances share `inst`
            // but expose no textProjector — guard so their scenes don't throw here (which aborted
            // the seed flow and left the preview blank / with no arrival card).
            if (!inst || !overlayHost || typeof inst.textProjector !== 'function') { layer.setProjector(null); return }
            layer.setProjector(inst.textProjector(overlayHost))
            inst.map.on('move', () => layer.refreshPositions())
        }

        window.Livewire.on('scene:load', (e) => {
            const p = Array.isArray(e) ? e[0]?.payload : e?.payload
            if (!p) { destroy(); wireTextProjector(); return }

            // Tell the object/layers list what KIND of scene is on stage, so a Route waypoint scene can
            // list its own layers (the Waypoint map + the Gallery overlay) instead of a bare "Background".
            const _vcfg = p.config || {}
            const _gal = _vcfg.gallery || {}
            window.__objScene = {
                kind: p.kind,
                hasGallery: !!(_gal.title || _gal.story || (_gal.images && _gal.images.length) || (_vcfg.stop_images && _vcfg.stop_images.length)),
            }
            try { window.dispatchEvent(new CustomEvent('objscene-changed')) } catch (_) { /* noop */ }

            // Voyage tour + image gallery are map-family stage scenes — preview them in the same
            // #lesson-map-preview host, reusing the student player's renderers so the wizard shows
            // exactly what students see. A voyage re-mounts ONLY when the voyage id itself changes;
            // every other edit (leg switch, route-geometry drag/insert, map detail, gallery content)
            // updates the SAME map in place so the world never flashes or jumps back to its fit.
            if (p.kind === 'voyage' || p.kind === 'gallery') {
                currentSceneId = p.sceneId
                const key = stageKey(p)
                const cfg = p.config || {}
                const applyLiveMap = () => {
                    if (p.kind !== 'voyage' || typeof inst.setMapOptions !== 'function') return
                    try { inst.setMapOptions(p.voyage_map, p.leg_labels) } catch (_) {}
                    try { inst.setPaintedFog(p.voyage_fog || []) } catch (_) {}
                    try { inst.setRouteLine(p.route_line) } catch (_) {}
                    try { inst.setView(cfg.view || 'flat') } catch (_) {}
                }
                // SAME voyage still mounted → NEVER re-mount. Apply every change live:
                //  • map detail / fog / route-line / view  → applyLiveMap()
                //  • route GEOMETRY edited (drag / insert / move a waypoint) → setVoyageDef() reshapes
                //    the ship + trail + fog + handles in place at the current camera (no jump, no flash)
                //  • leg changed → playLeg()   • same leg, content changed → refreshArrivalContent()
                if (inst && p.kind === 'voyage' && cfg.voyage === lastVoyageId) {
                    applyLiveMap()
                    const legNow = Number(cfg.leg) || 0
                    const defStr = JSON.stringify(p.voyage_def || null)
                    const defChanged = defStr !== lastVoyageDefStr
                    const legChanged = legNow !== lastLeg
                    const contentChanged = key !== lastKey
                    // Route GEOMETRY edited (drag / insert / move a waypoint) → reshape in place.
                    if (defChanged && typeof inst.setVoyageDef === 'function') {
                        try { inst.setVoyageDef(p.voyage_def, legNow) } catch (_) {}
                    }
                    if (legChanged) {
                        try {
                            inst.playLeg(legNow, {
                                intro: !!cfg.intro,
                                stopImages: cfg.stop_images || [],
                                gallery: cfg.gallery || null,
                                hotspot: cfg.hotspot || null,
                            })
                        } catch (_) {}
                    } else if (contentChanged && typeof inst.refreshArrivalContent === 'function') {
                        // Same leg, CONTENT edited (gallery images/text, image fit, dates) → refresh the
                        // pins in place; an open gallery modal is left alone. NOTE: gated on a real change
                        // so the 3s status poll (which re-fires scene:load unchanged) never rebuilds the
                        // HUD — that was yanking handles out from under an in-progress drag.
                        try {
                            inst.refreshArrivalContent({
                                gallery: cfg.gallery || null,
                                stopImages: cfg.stop_images || [],
                                hotspot: cfg.hotspot || null,
                            })
                        } catch (_) {}
                    }
                    lastKey = key; lastLeg = legNow; lastVoyageDefStr = defStr
                    showVoyageArt(p)   // keep the clipart layers in sync (a poll/leg switch may add/move one)
                    return
                }
                // Gallery scene: SAME scene → refresh content in place (title/date/story/fit/images)
                // instead of destroy+rebuild, so an inspector edit never restarts the slideshow / flashes.
                // Nothing changed → just return. A DIFFERENT gallery scene falls through to a fresh mount.
                if (inst && p.kind === 'gallery' && lastGalleryId === p.sceneId) {
                    if (key !== lastKey && typeof inst.setContent === 'function') {
                        try { inst.setContent(p.config || {}) } catch (_) {}
                    }
                    lastKey = key
                    hideVoyageArt()   // gallery scenes don't carry map clipart
                    return
                }
                destroy(); wireTextProjector()
                lastKey = key
                lastVoyageId = cfg.voyage
                lastVoyageDefStr = JSON.stringify(p.voyage_def || null)
                lastLeg = Number(cfg.leg) || 0
                host.style.display = 'block'
                const stageInner = document.createElement('div')
                stageInner.style.width = '100%'
                stageInner.style.height = '100%'
                host.appendChild(stageInner)
                const vcfg = p.config || {}
                if (p.kind === 'voyage' && window.renderVoyageTour) {
                    inst = window.renderVoyageTour(stageInner, {
                        voyage: vcfg.voyage,
                        def: p.voyage_def || null,
                        view: vcfg.view || 'flat',
                        routeLine: p.route_line || null,
                        preview: true,   // editor: jump straight to the landfall, skip the sail
                        editable: true,  // teacher can edit the gallery title/date/story on the scene
                        mapOptions: p.voyage_map || null,   // hide cities/borders, show place labels
                        style: vcfg.map_style || p.lesson_map_style || 'soft-atlas',
                        legLabels: p.leg_labels || [],
                        paintedFog: p.voyage_fog || [],     // teacher-painted undiscovered regions
                        {{-- Read currentSceneId (not the mount-time id) so a no-rebuild leg switch still
                             saves edits against the scene now on screen. --}}
                        onGalleryEdit: (field, value) =>
                            window.Livewire.dispatch('galleryTextChanged', { sceneId: currentSceneId, field, value }),
                        onHotspotMove: (lng, lat) =>
                            window.Livewire.dispatch('voyageHotspotMoved', { sceneId: currentSceneId, lng, lat }),
                        // Drag a leg route point (destination or a bend) → persist that waypoint by index.
                        onEndpointMove: (wpIndex, lng, lat) =>
                            window.Livewire.dispatch('voyageWaypointMoved', { sceneId: currentSceneId, wpIndex, lng, lat }),
                        // Drop a NEW bend by dragging the route line → insert a waypoint after afterWpIndex.
                        onWaypointInsert: (afterWpIndex, lng, lat) =>
                            window.Livewire.dispatch('voyageWaypointInserted', { sceneId: currentSceneId, afterWpIndex, lng, lat }),
                        // Double-click a bend → drop that waypoint (undoable, like every other route edit).
                        onWaypointRemove: (wpIndex) =>
                            window.Livewire.dispatch('voyageWaypointRemoved', { sceneId: currentSceneId, wpIndex }),
                        // Restore the open gallery ONCE when arriving via a preview "Edit scene" deep-link.
                        openGalleryOnArrive: window.__openGalleryOnLoad === true,
                    })
                    window.__openGalleryOnLoad = false   // consume — later re-mounts start on the map
                    window.__mountVoyagePaint && window.__mountVoyagePaint(inst, stageInner, p)
                    if (vcfg.overview) {
                        // The itinerary scene: whole route, every stop, fitted. No leg is sailed.
                        inst.showOverview({ animate: false })
                    } else {
                        inst.playLeg(Number(vcfg.leg) || 0, {
                            intro: !!vcfg.intro,
                            stopImages: vcfg.stop_images || [],
                            gallery: vcfg.gallery || null,
                            hotspot: vcfg.hotspot || null,
                        })
                    }
                    // Expose the live tour so the zoom-out toggle can reach it from any voyage scene.
                    window.__voyageTour = inst
                    lastGalleryId = null
                } else if (p.kind === 'gallery' && window.renderGallery) {
                    inst = window.renderGallery(stageInner, vcfg)
                    lastGalleryId = p.sceneId
                } else {
                    console.error('[wizard] voyage/gallery renderer unavailable — is the module loaded?')
                }
                // Clipart layers ride ABOVE the voyage map (never on a standalone gallery scene).
                if (p.kind === 'voyage') showVoyageArt(p); else hideVoyageArt()
                return
            }

            if (p.kind !== 'map') { destroy(); wireTextProjector(); return }
            const cfg = p.config || {}
            const year = cfg.year ?? p.year ?? 1600
            // Only the scene-defining bits decide a re-mount. scene:load re-fires constantly (status
            // polling, saves, etc.); re-mounting each time would yank the globe back to its fit and
            // discard the camera the teacher panned. If nothing map-defining changed, keep the live
            // map and just sync the focus markers.
            // (projection is excluded — the Flat/Globe toggle flips it in place via lessonmap:projection,
            //  which keeps the camera; re-mounting for it would reset the view)
            // Effective palette: a per-block override wins, else the lesson-wide default, else app default.
            const style = cfg.map_style || p.lesson_map_style || 'soft-atlas'
            const key = [p.sceneId, cfg.qid || '', year].join('|')
            if (inst && key === lastKey) {
                // Style isn't in the key (it repaints in place), so apply it here too — this is
                // the path a saved style change lands on after its Livewire round-trip.
                try { inst.setStyle(style) } catch (_) {}
                try { inst.setAnnotations(cfg.annotations || []) } catch (_) {}
                return
            }
            // SAME scene, only the YEAR and/or the linked territory (qid) changed → update the live
            // map in place instead of remounting. Remounting on every year/territory tweak was what
            // made the map blink and jump (a fresh mount re-fits + reloads tiles). setYear() reshapes
            // the period borders in place; setPolity() moves the red highlight — both keep the camera.
            const prev = lastKey ? lastKey.split('|') : null
            if (inst && prev && prev[0] === String(p.sceneId)) {
                lastKey = key
                try { inst.setStyle(style) } catch (_) {}
                if (prev[2] !== String(year)) { try { inst.setYear(year) } catch (_) {} }
                if (prev[1] !== String(cfg.qid || '')) { try { inst.setPolity(cfg.qid || null) } catch (_) {} }
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
                    style,
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

        // Inspector MAP STYLE select → repaint the live preview to the chosen palette immediately.
        window.addEventListener('lessonmap:style', (e) => inst && inst.setStyle(e.detail.name))

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
                onChange: (texts, selectedTextId) => {
                    lastAppliedTexts = JSON.stringify(texts)
                    localDirtyUntil = Date.now() + 2500
                    // sceneId may still be null before the first scene:load — the server
                    // falls back to the currently selected scene in that case.
                    window.Livewire.dispatch('sceneTextsChanged', { sceneId: textSceneId ?? null, texts, selectedTextId })
                },
            })
            window.__lessonTextLayer = textLayer
            wireTextProjector()   // a map block may already be live — pin labels to it now
            return textLayer
        }
        window.Livewire.on('scene:text-updated', (e) => {
            const payload = Array.isArray(e) ? e[0] : e
            if (!payload || payload.sceneId !== textSceneId) return
            const layer = ensureTextLayer()
            layer?.patch?.(payload.textId, payload.text)
            if (layer) lastAppliedTexts = JSON.stringify(layer._texts)
        })
        window.Livewire.on('scene:text-removed', (e) => {
            const payload = Array.isArray(e) ? e[0] : e
            if (!payload || payload.sceneId !== textSceneId) return
            const layer = ensureTextLayer()
            layer?.remove?.(payload.textId)
            if (layer) lastAppliedTexts = JSON.stringify(layer._texts)
        })
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

        // Eager init: the initial scene:load (dispatched during Livewire hydration) can fire
        // BEFORE this boot registers its listener on slow devices/headless browsers — then the
        // text layer never exists and scene 1's saved text boxes don't render until the teacher
        // clicks away and back. Seed scene 1 from the bootstrap JSON. Retries because the Vite
        // bundle (window.LessonScene) also races this script on slow loads — ensureTextLayer()
        // returns null until it lands. The guard on textSceneId keeps a scene:load that DID
        // arrive first authoritative.
        let eagerTries = 0
        const eagerSeed = () => {
            const layer = ensureTextLayer()
            if (!layer) { if (++eagerTries < 60) setTimeout(eagerSeed, 500); return }
            if (textSceneId !== null) return   // a real scene:load already seeded — done
            try {
                const scenes = JSON.parse(document.getElementById('step3-scenes-data')?.textContent || '[]')
                const first = scenes[0]
                if (first) {
                    textSceneId = first.id
                    lastAppliedTexts = JSON.stringify(first.config?.texts || [])
                    layer.setTexts(first.config?.texts || [])
                }
            } catch (e) { /* malformed bootstrap JSON — scene:load will still seed on next poll */ }
        }
        eagerSeed()
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
    {{-- FIXED Format panel (was a draggable/dockable floating inspector — teachers lost it or
         dragged it over the canvas). Always pinned to the right edge below the app header;
         visibility is toggled by the toolbar "Format" button. Game/quiz scenes get a WIDE
         workspace (managing 5+ questions in a 24rem sidebar was unusable). --}}
    @php $inspectorSceneModel = $this->selectedSceneModel; @endphp
    <aside x-cloak
           x-ref="inspectorPanel"
           x-show="inspectorOpen"
           x-on:inspector-toggle.window="toggleInspector()"
           x-on:inspector-open.window="inspectorOpen = true"
           x-on:inspector-open-settings.window="inspectorOpen = true"
           {{-- Broadcast open-state + active tab to the toolbar (sibling scope, wire:ignore) so
                its Format/Settings buttons can render an open state. Re-runs when inspectorOpen
                flips AND when a Livewire morph rewrites the literal panelView below. --}}
           x-effect="window.dispatchEvent(new CustomEvent('inspector-state', { detail: { open: inspectorOpen, view: '{{ $panelView }}' } }))"
           {{-- The x-effect's FIRST broadcast fires before the toolbar's listener exists (and
                inspectorOpen never changes afterward, so it never re-fires) — the toolbar then never
                learned the panel was open. Re-broadcast on $nextTick (listener now ready) and answer
                an explicit request, so fmtOpen syncs no matter which scope inits first. --}}
           x-init="$nextTick(() => window.dispatchEvent(new CustomEvent('inspector-state', { detail: { open: inspectorOpen, view: '{{ $panelView }}' } })))"
           x-on:inspector-state-request.window="window.dispatchEvent(new CustomEvent('inspector-state', { detail: { open: inspectorOpen, view: '{{ $panelView }}' } }))"
           style="right:0; left:auto; top:64px; bottom:0;"
           class="card card-compact fixed z-50 overflow-hidden rounded-none border border-r-0 border-t-0 border-slate-700 bg-base-300 shadow-2xl
                  {{ $inspectorSceneModel?->kind === 'game' ? 'w-[min(48rem,calc(100vw-1rem))]' : 'w-[min(16rem,calc(100vw-1rem))]' }}">
        <div x-show="inspectorOpen"
             x-transition.opacity.duration.150ms
             class="card-body overflow-y-auto p-4"
             :style="inspectorBodyStyle()">
            @if ($activeTextId && ($activeText = $this->activeText))
            {{-- Text and backing-panel controls live in Format, never over the canvas object. --}}
            <x-lesson.scene-text-inspector :text="$activeText" :scene="$this->selectedSceneModel" />
            @elseif ($activeLayerId && ($al = $this->activeLayer))
            {{-- A clipart layer is selected → its settings take over the inspector. --}}
            <x-lesson.scene-layer-inspector :layer="$al" :scene="$this->selectedSceneModel" />
            @elseif ($panelView === 'scene')
            @php $sceneModel = $this->selectedSceneModel; @endphp
            @if ($sceneModel)
                {{-- Key the inspector by scene id. The inspector partials seed their Alpine x-data
                     (view tabs, blur/opacity sliders, slideshow mode, colour) ONCE from $scene;
                     without a per-scene key, Livewire's morph preserves the previous scene's Alpine
                     state on switch, so sliders/tabs show — and can persist — the wrong scene's
                     values. A changing key forces Alpine to reinitialise from the new scene. --}}
                <div wire:key="scene-inspector-{{ $sceneModel->id }}">
                @if ($sceneModel->kind === 'map')
                    <x-lesson.scene-inspector-map :scene="$sceneModel"
                                                 :lesson-map-style="$this->lesson->map_style"
                                                 :territory-results="$this->territoryResults"
                                                 :territory-query="$territoryQuery"
                                                 :city-results="$this->cityResults"
                                                 :city-query="$cityQuery" />
                @elseif ($sceneModel->kind === 'game')
                    <x-lesson.scene-inspector-game :scene="$sceneModel" :games="$this->games"
                                                  :quiz-draft="$quizDraft" :quiz-errors="$quizErrors" :quiz-saved="$quizSaved"
                                                  :quiz-difficulty="$this->quizDifficulty()" :quiz-scope="$this->quizScope()"
                                                  :quiz-shuffle="$this->quizShuffle()" />
                @elseif ($sceneModel->kind === 'voyage')
                    <x-lesson.scene-inspector-voyage :scene="$sceneModel" :voyage-def="$this->voyageDef()" :route-line="$this->routeLine()" :voyage-map="$this->voyageMap()" :voyage-fog="$this->voyageFog()" :voyage-view="$this->voyageView()" :transports="$this->transports()" :lesson-map-style="$this->lesson->map_style ?? 'soft-atlas'" />
                @elseif ($sceneModel->kind === 'gallery')
                    <x-lesson.scene-inspector-gallery :scene="$sceneModel" />
                @else
                    <x-lesson.scene-inspector-narration :scene="$sceneModel" />
                @endif

                {{-- Story-game: branch OPTION scenes get the "Game effects" editor. --}}
                @if ($this->showsGameEffectsPanel($sceneModel))
                    <x-lesson.scene-inspector-story-effects :scene="$sceneModel" :meters="$this->storyMeters()" />
                @endif
                </div>{{-- /scene-inspector-{{ $sceneModel->id }} --}}
            @else
                <p class="text-sm text-slate-400">No scene selected.</p>
            @endif

            @else
            {{-- ══ SETTINGS view — lesson-global Story + class meters + Background Music ══════ --}}
            {{-- Class meters (story_game, lesson-level) — a lesson-wide setting, so it lives in
                 Settings, not the per-scene Format panel. --}}
            @if ($this->showsMetersPanel())
                <div class="mb-6">
                    <x-lesson.story-meters-panel :meters-draft="$metersDraft" />
                </div>
            @endif

            <div class="space-y-1.5">
                <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Story') }}</span>
                <p class="text-xs text-slate-400">{{ __('The narrative arc and framework this lesson is built on.') }}</p>
                <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2]) }}" wire:navigate
                   class="btn btn-sm btn-outline mt-1 border-slate-600 text-slate-200 hover:border-amber-400 hover:text-amber-300">
                    {{ __('Edit story') }}
                </a>
            </div>

            {{-- SUBTITLES — whether the lesson starts with the narration written along the bottom.
                 A class that needs captions should have them from the first scene, not after
                 someone finds the button; students can still toggle them while it plays. --}}
            <div class="mt-6 border-t border-slate-700/50 pt-4">
                <label class="flex items-center justify-between gap-3">
                    <span>
                        <span class="text-[10px] uppercase tracking-widest text-slate-500">{{ __('Subtitles') }}</span>
                        <span class="mt-0.5 block text-xs text-slate-400">{{ __('Show the narration as text while it plays.') }}</span>
                    </span>
                    <input type="checkbox" @checked($lesson->subtitles)
                           wire:change="setSubtitles($event.target.checked)"
                           class="toggle toggle-sm toggle-warning shrink-0" />
                </label>
            </div>

            {{-- POSTER — the lesson's cover image. Never empty: auto-picks an image already loaded in
                 the lesson, overridable by clicking any candidate below. --}}
            @php $posterCandidates = $this->lesson->posterCandidates(); $posterOverride = trim((string) ($lesson->poster_image ?? '')) !== ''; @endphp
            <div class="mt-6 pt-4 border-t border-slate-700/50">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-[10px] uppercase tracking-widest text-slate-500">Poster</span>
                    @if ($posterOverride)
                        <button wire:click="resetPoster" class="text-[10px] text-slate-500 transition-colors hover:text-amber-300">↺ auto</button>
                    @else
                        <span class="text-[10px] text-slate-600">auto-picked</span>
                    @endif
                </div>
                <div class="flex gap-3">
                    <img src="{{ $this->lesson->posterUrl() }}" alt="Lesson poster"
                         class="h-24 w-16 shrink-0 rounded-lg object-cover ring-1 ring-slate-600" />
                    @if (count($posterCandidates))
                        <div class="grid grid-cols-4 gap-1.5 content-start">
                            @foreach ($posterCandidates as $cand)
                                <button type="button" wire:click="selectPoster(@js($cand['url']))" title="{{ $cand['label'] }}"
                                        @class([
                                            'aspect-square overflow-hidden rounded ring-1 transition',
                                            'ring-amber-400' => $posterOverride && ($lesson->poster_image === $cand['url']),
                                            'ring-slate-700 hover:ring-slate-400' => ! ($posterOverride && ($lesson->poster_image === $cand['url'])),
                                        ])>
                                    <img src="{{ $cand['url'] }}" alt="{{ $cand['label'] }}" class="h-full w-full object-cover" onerror="this.closest('button').style.display='none'" />
                                </button>
                            @endforeach
                        </div>
                    @else
                        <p class="self-center text-[11px] text-slate-500">Add images to the lesson to choose a poster.</p>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-4 border-t border-slate-700/50" x-data="musicStrip">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-[10px] uppercase tracking-widest text-slate-500">Background Music</span>
                    @if($lesson->background_music)
                        <button wire:click="selectMusic('')" class="text-[10px] text-slate-500 transition-colors hover:text-rose-400">✕ off</button>
                    @endif
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach($this->musicTracks() as $track)
                    @php $url = asset('sound/bg-music/' . $track['file']); @endphp
                    <button
                        x-on:click="toggle('{{ $track['id'] }}', '{{ $url }}')"
                        wire:click="selectMusic('{{ $track['id'] }}')"
                        :class="selectedId === '{{ $track['id'] }}' ? 'border-amber-400' : 'border-slate-700/60 hover:border-indigo-500/50'"
                        class="{{ $track['gradient_class'] }} relative cursor-pointer rounded-xl border p-2 transition-all"
                        style="min-height:64px;"
                        title="{{ $track['label'] }}"
                        x-init="@if($lesson->background_music === $track['id']) selectedId = '{{ $track['id'] }}' @endif"
                    >
                        <div class="absolute right-1 top-1 z-10">
                            <span x-show="playingId === '{{ $track['id'] }}'" class="flex h-3 items-end gap-0.5 text-indigo-400">
                                <span class="wave-bar h-3"></span><span class="wave-bar h-2"></span><span class="wave-bar h-3"></span>
                            </span>
                            <span x-show="playingId !== '{{ $track['id'] }}' && selectedId === '{{ $track['id'] }}'" class="text-xs leading-none text-amber-400">✓</span>
                        </div>
                        <div class="mt-1 flex h-6 items-center justify-center">
                            <svg class="h-5 w-5 text-white/50" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3v10.55A4 4 0 1 0 11 17V7h4V3H9z"/></svg>
                        </div>
                        <p class="mt-1 truncate text-center text-[10px] leading-tight text-white/80">{{ $track['label'] }}</p>
                    </button>
                    @endforeach
                </div>
                <p class="mt-1 text-[10px] text-slate-600">Click to preview (20s). Selected track plays during lesson.</p>
            </div>
            @endif
        </div>
    </aside>

    {{-- Bottom CTA removed: the Play button (top toolbar) opens the player, and the back
         arrow (top-left) exits to the dashboard. --}}

    {{-- Publish feedback banner (no global toast system yet) — auto-clears after 5s. Bottom-centre
         so it clears the top toolbar, step indicator and inspector. --}}
    @if ($publishNotice)
        <div class="fixed bottom-6 left-1/2 z-70 -translate-x-1/2"
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
    // Object-list row icons (from the shared ui_icons pack). Type glyphs replace the old
    // TEXT/PANEL/BG text tags. `currentColor` lets each inherit the row's text colour.
    window.__objIcons = {
        text:   '<svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"><path d="M20.25 19.5H3.75C3.35218 19.5 2.97064 19.342 2.68934 19.0607C2.40804 18.7794 2.25 18.3978 2.25 18V6C2.25 5.60218 2.40804 5.22064 2.68934 4.93934C2.97064 4.65804 3.35218 4.5 3.75 4.5H20.25C20.6478 4.5 21.0294 4.65804 21.3107 4.93934C21.592 5.22064 21.75 5.60218 21.75 6V18C21.75 18.3978 21.592 18.7794 21.3107 19.0607C21.0294 19.342 20.6478 19.5 20.25 19.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.18335 9.3667C8.80595 9.3667 8.5 9.06075 8.5 8.68335C8.5 8.30595 8.80595 8 9.18335 8H14.8167C15.1941 8 15.5 8.30595 15.5 8.68335C15.5 9.06075 15.1941 9.3667 14.8167 9.3667H12.7882V16.2118C12.7882 16.6471 12.4353 17 12 17C11.5647 17 11.2118 16.6471 11.2118 16.2118V9.3667H9.18335Z" fill="currentColor"/></svg>',
        panelL: '<svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"><path d="M20.0122 3.86951C21.1166 3.86977 22.0122 4.7651 22.0122 5.86951V18.475L22.0015 18.6791C21.899 19.6874 21.0475 20.4738 20.0122 20.474L20.0112 20.475H3.98779L3.78369 20.4642C2.84228 20.3688 2.0942 19.6204 1.99854 18.6791L1.98779 18.475V5.86951C1.98779 4.76494 2.88322 3.86951 3.98779 3.86951H20.0122ZM10.5522 18.975H20.0122C20.288 18.9747 20.5119 18.7507 20.5122 18.475V5.86951C20.5122 5.59353 20.2881 5.36977 20.0122 5.36951H10.5522V18.975Z" fill="currentColor"/></svg>',
        panelR: '<svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"><path d="M20.0122 3.86951C21.1168 3.86951 22.0122 4.76494 22.0122 5.86951V18.475C22.0122 19.5796 21.1168 20.475 20.0122 20.475H3.98779L3.78369 20.4642C2.84228 20.3688 2.0942 19.6204 1.99854 18.6791L1.98779 18.475V5.86951C1.98779 4.76494 2.88322 3.86951 3.98779 3.86951H20.0122ZM3.98779 5.36951C3.71165 5.36951 3.48779 5.59336 3.48779 5.86951V18.475C3.48806 18.7509 3.71181 18.975 3.98779 18.975H13.4478V5.36951H3.98779Z" fill="currentColor"/></svg>',
        photo:  '<svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"><path d="M2.25 15.75L7.409 10.591C7.61793 10.3821 7.86597 10.2163 8.13896 10.1033C8.41194 9.99018 8.70452 9.93198 9 9.93198C9.29548 9.93198 9.58806 9.99018 9.86104 10.1033C10.134 10.2163 10.3821 10.3821 10.591 10.591L15.75 15.75M14.25 14.25L15.659 12.841C15.8679 12.6321 16.116 12.4663 16.389 12.3533C16.6619 12.2402 16.9545 12.182 17.25 12.182C17.5455 12.182 17.8381 12.2402 18.111 12.3533C18.384 12.4663 18.6321 12.6321 18.841 12.841L21.75 15.75M3.75 19.5H20.25C20.6478 19.5 21.0294 19.342 21.3107 19.0607C21.592 18.7794 21.75 18.3978 21.75 18V6C21.75 5.60218 21.592 5.22064 21.3107 4.93934C21.0294 4.65804 20.6478 4.5 20.25 4.5H3.75C3.35218 4.5 2.97064 4.65804 2.68934 4.93934C2.40804 5.22064 2.25 5.60218 2.25 6V18C2.25 18.3978 2.40804 18.7794 2.68934 19.0607C2.97064 19.342 3.35218 19.5 3.75 19.5ZM14.625 8.25C14.625 8.66421 14.2892 9 13.875 9C13.4608 9 13.125 8.66421 13.125 8.25C13.125 7.83579 13.4608 7.5 13.875 7.5C14.2892 7.5 14.625 7.83579 14.625 8.25Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        solid:  '<svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"><path d="M20.0049 19.6379H9.00488C8.73967 19.6379 8.48531 19.5325 8.29778 19.345C8.11024 19.1574 8.00488 18.9031 8.00488 18.6379V10.6379C8.00488 10.3727 8.11024 10.1183 8.29778 9.93077C8.48531 9.74324 8.73967 9.63788 9.00488 9.63788H20.0049C20.2701 9.63788 20.5245 9.74324 20.712 9.93077C20.8995 10.1183 21.0049 10.3727 21.0049 10.6379V18.6379C21.0049 18.9031 20.8995 19.1574 20.712 19.345C20.5245 19.5325 20.2701 19.6379 20.0049 19.6379Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.1345 9.6989C14.1345 6.75148 11.7451 4.36212 8.79772 4.36212C5.8503 4.36212 3.46095 6.75148 3.46095 9.6989C3.44224 11.3303 4.45651 14.5767 8.41688 14.5767" stroke="currentColor" stroke-width="1.5"/></svg>',
        map:    '<svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"><path d="M9 6.75 3.75 4.5v12.75L9 19.5m0-12.75 6 2.25m-6-2.25v12.75m6-10.5 5.25-2.25V15L15 17.25m0-10.5v10.5m0 0-6-2.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    };
    // Object list — reads the live text layer (title/text boxes + backing panels) so the teacher
    // can find, flash-locate, and restack objects that overlap on the stage.
    window.objectList = function objectList() {
        return {
            items: [],
            dragging: false,
            selectedId: null,
            _sig: null,
            init() {
                this.refresh();
                setInterval(() => { if (!this.dragging && window.Alpine?.store('view')?.objects) this.refresh(); }, 2000);
                window.addEventListener('objscene-changed', () => this.refresh());   // scene switched → relist layers
                this.$nextTick(() => this.initSortable());
                // Compact (icons-only) toggle via a data attribute, NOT a CSS container query:
                // container-type would make this panel a containing block for SortableJS's
                // position:fixed drag ghost, offsetting/clipping it and breaking layer reordering.
                const setCompact = () => { this.$el.dataset.compact = this.$el.clientWidth <= 108 ? '1' : ''; };
                setCompact();
                new ResizeObserver(setCompact).observe(this.$el);
            },
            effZ(t) {
                const layer = window.__lessonTextLayer;
                if (layer && typeof layer._effZ === 'function') return layer._effZ(t);
                return Number.isFinite(t.z) ? t.z : (t.kind === 'rect' ? 0 : 1);
            },
            iconSvg(obj) { return window.__objIcons[obj.icon] || ''; },
            refresh() {
                const texts = (window.__lessonTextLayer && window.__lessonTextLayer._texts) || [];
                // Front-most first: the top row is the highest z (drawn last / on top). Ties keep
                // insertion order. Mirrors the canvas stacking so the list reads like a layers panel.
                const withZ = texts.map((t, i) => ({ t, i, z: this.effZ(t) }));
                withZ.sort((a, b) => (b.z - a.z) || (b.i - a.i));
                const textItems = withZ.map(({ t }) => t.kind === 'rect'
                    ? { id: t.id, icon: t.side === 'right' ? 'panelR' : 'panelL', label: (t.side || 'left') + ' half', bg: false }
                    : { id: t.id, icon: 'text', label: (t.text || 'Text').slice(0, 40), bg: false });
                // Clipart / artwork / embed layers (own overlay, drag-reorderable). _layers is bottom-first
                // (last painted = on top), so reverse it to list front-most first like the text rows.
                // On a voyage scene read the DEDICATED voyage overlay handle — the shared one can be
                // repointed to a slideshow overlay by wizard-bridge, dropping the voyage layers.
                const objKind = (window.__objScene || {}).kind;
                const artLayer = (objKind === 'voyage' && window.__voyageArtworkLayer) || window.__lessonArtworkLayer;
                const arts = (artLayer && artLayer._layers) || [];
                const artItems = [...arts].reverse().map((a) =>
                    ({ id: 'art_' + a.asset_id, icon: a.embed ? (a.embed.type === 'video' ? 'photo' : 'map') : 'photo',
                       label: a.title || (a.embed ? (a.embed.type === 'video' ? 'Video' : '3D model') : 'Clipart'), bg: false, art: true }));
                // The clipart group sits above the text objects only when the teacher dragged it there
                // (config.clipart_on_top → the overlay host's z-index is raised above the text layer).
                const onTop = !!(artLayer && artLayer.onTop);
                const items = onTop ? [...artItems, ...textItems] : [...textItems, ...artItems];
                // Bottom layer(s), pinned (not drag-reorderable). A Route waypoint scene lists its own
                // stack — the Gallery overlay ON TOP of the Waypoint map — instead of a bare Background;
                // every other scene lists a single Background (or Slideshow for a standalone gallery).
                if (objKind === 'voyage') {
                    items.push({ id: '__gallery__', icon: 'photo', label: 'Gallery', bg: true, voyage: 'gallery' });
                    items.push({ id: '__waypoint__', icon: 'map', label: 'Waypoint', bg: true, voyage: 'waypoint' });
                } else {
                    items.push({ id: '__bg__', icon: 'photo', label: objKind === 'gallery' ? 'Slideshow' : 'Background', bg: true });
                }
                // Skip the re-render (and its churn under SortableJS) when nothing actually changed —
                // the 2s poll would otherwise rebuild identical rows and fight the drag layer.
                const sig = items.map((i) => i.id + ':' + i.icon + ':' + i.label).join('|');
                if (sig === this._sig) return;
                this._sig = sig;
                this.items = items;
            },
            // Drag-to-reorder (SortableJS). Top of the list = frontmost; dropping a row restacks
            // the objects on the canvas (their z-index) and persists it via the layer's onChange.
            initSortable() {
                const list = this.$refs.list;
                if (!list || !window.Sortable || list._objSortable) return;
                list._objSortable = true;
                window.Sortable.create(list, {
                    animation: 150,
                    // No handle — the whole row drags. Background ([data-bg]) is pinned, and the
                    // adjust button ([data-nodrag]) opts out so tapping it doesn't start a drag.
                    draggable: '[data-obj-id]',
                    filter: '[data-bg], [data-nodrag]',
                    preventOnFilter: false,         // keep the adjust button's own click working
                    // Pointer-based dragging (not native HTML5 DnD): more reliable inside this
                    // fixed-position panel and works consistently across browsers.
                    forceFallback: true,
                    fallbackTolerance: 4,
                    onStart: (evt) => { this.dragging = true; evt.item._objNext = evt.item.nextSibling; },
                    onEnd: (evt) => {
                        this.dragging = false;
                        // Read the new top→bottom order Sortable applied (BG has no data-obj-id).
                        const ids = [...evt.to.querySelectorAll('[data-obj-id]')].map((el) => el.dataset.objId);
                        // Undo Sortable's DOM move so Alpine's keyed x-for stays the single source of
                        // truth (avoids the classic Alpine+Sortable double-move corruption)…
                        evt.from.insertBefore(evt.item, evt.item._objNext || null);
                        // …then restack each layer system and rebuild. Text and clipart are two
                        // separate overlays, so split the dropped order by type and reorder each.
                        const isArt = (id) => id.startsWith('art_');
                        const textIds = ids.filter((id) => !isArt(id));
                        const artIds = ids.filter(isArt);
                        if (textIds.length) window.__lessonTextLayer?.reorder(textIds);
                        if (artIds.length) this.reorderClipart(ids, textIds, artIds);
                        // _sig is cleared or refresh() would no-op the reordered rows.
                        this._sig = null;
                        this.refresh();
                    },
                });
            },
            // Clipart lives in its own overlay (a single host in one z-band). Reorder the clipart
            // layers among themselves and, when the clipart block was dragged above/below the text
            // block, raise/lower the whole overlay past the text layer. Persisted server-side.
            reorderClipart(allIds, textIds, artRowIds) {
                const layer = window.__lessonArtworkLayer;
                if (!layer) return;
                // Did the clipart block land above or below the text block? (compare mean row index)
                const avg = (list) => list.reduce((s, id) => s + allIds.indexOf(id), 0) / list.length;
                const onTop = textIds.length ? (avg(artRowIds) < avg(textIds)) : (layer.onTop ?? false);
                layer.setOnTop(onTop);
                const paintOrder = layer.reorder(artRowIds.map((id) => Number(id.slice(4))));  // bottom-first
                const host = document.querySelector('.wizard-artwork-host');
                if (host) host.style.zIndex = onTop ? '8' : '2';  // above (8) / below (2) the text overlay
                window.Livewire?.dispatch('artwork:reorder', { assetIds: paintOrder, onTop });
            },
            // Click a row → select that object: highlight the row, ring the object on the canvas
            // (the layer broadcasts back so canvas↔list stay in sync), and scroll it into view.
            select(obj) {
                this.selectedId = obj.id;
                if (obj.voyage) {
                    // Route waypoint layers → open the Format inspector and focus the Waypoint tab
                    // (Gallery also scrolls to its section). The voyage inspector listens for this.
                    try { window.dispatchEvent(new CustomEvent('inspector-open')); } catch (_) { /* noop */ }
                    try { window.dispatchEvent(new CustomEvent('voyage-obj-focus', { detail: { which: obj.voyage } })); } catch (_) { /* noop */ }
                    return;
                }
                if (obj.bg) {
                    window.__lessonTextLayer?.select?.('__bg__');   // clears any canvas object ring
                    this.locate(obj);                                // route to the Background inspector
                    return;
                }
                if (obj.art) {
                    window.__lessonArtworkLayer?.select?.(obj.id);   // ring the clipart (clears text ring)
                    document.querySelector(`[data-layer-id="${obj.id}"]`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    return;
                }
                window.__lessonTextLayer?.select?.(obj.id);
                document.querySelector(`[data-text-id="${obj.id}"]`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            },
            // Delete any object type — the server router (scene:delete-object) dispatches to
            // detachArtwork (art_) or deleteSceneText (txt_/rect_) by the id. Background isn't deletable.
            deleteObject(obj) {
                if (!obj || obj.bg) return;
                window.Livewire.dispatch('scene:delete-object', { objectId: obj.id });
            },
            // Hover "adjust" icon → select the object, then open its editor: focus a text box
            // (reveals its font/size/align toolbar) or surface the panel's side/colour bar.
            edit(obj) {
                this.select(obj);
                if (obj.bg || obj.art) return;   // select() already handled these
                document.querySelector(`[data-text-id="${obj.id}"] [contenteditable]`)?.focus();
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

    // One selection bridge owns the inspector target. Text, backing panels, and clipart each
    // select their own Format controls; the background or empty stage returns to scene settings.
    (() => {
        if (window.__lessonSelectionBridgeMounted) return;
        window.__lessonSelectionBridgeMounted = true;

        let lastSelection = Symbol('initial-selection');
        window.addEventListener('scene-object-selected', (e) => {
            const id = (e.detail && e.detail.id) || '';
            const isArtwork = id.indexOf('art_') === 0;
            const isText = !!window.__lessonTextLayer?._texts?.some((text) => text.id === id);
            const objectId = (isArtwork || isText) ? id : '';
            if (objectId) window.dispatchEvent(new CustomEvent('inspector-open'));
            if (objectId === lastSelection) return;
            lastSelection = objectId;
            window.Livewire?.dispatch('scene:selection-changed', { objectId });
        });
        // Empty canvas / background selection is scene-level formatting, not object formatting.
        document.addEventListener('pointerdown', (e) => {
            const target = e.target instanceof Element ? e.target : null;
            if (!target?.closest('#lesson-canvas-root') || target.closest('[data-text-id], [data-layer-id]')) return;
            window.__lessonTextLayer?.select?.('__bg__');
        });
        // Called from an inspector's Back button so selecting the same object later reopens it.
        window.__clearLayerGuard = () => { lastSelection = Symbol('cleared-selection'); };
    })();

    // Registered on BOTH alpine:init and livewire:navigated (idempotent). Without the navigated hook,
    // arriving in the wizard via a wire:navigate SPA link (e.g. from the preview / dashboard) left
    // $store.view undefined — the panel x-effects then threw and the View toolbar couldn't move panels.
    const __registerViewStore = () => {
        if (!window.Alpine || window.Alpine.store('view')) return;
        Alpine.store('view', {
            scenes: true, objects: false, rulers: false, notes: false, script: true, railLast: 176,
            objectsW: 208,   // object-list width (px); drag-resizable, ≤108px → icons-only
            init() {
                try { Object.assign(this, JSON.parse(localStorage.getItem('wizard.view') || '{}')); } catch (_) {}
                // Seed --rail-w / --objlist-w from the persisted widths so a dragged-narrow (compact)
                // rail or object list survives a reload / SPA navigation.
                if (!(this.railLast > 0)) this.railLast = 176;
                if (!(this.objectsW > 0)) this.objectsW = 208;
                document.documentElement.style.setProperty('--rail-w', this.scenes ? this.railLast + 'px' : '0px');
                document.documentElement.style.setProperty('--objlist-w', this.objects ? this.objectsW + 'px' : '0px');
            },
            _save() {
                localStorage.setItem('wizard.view', JSON.stringify({
                    scenes: this.scenes, objects: this.objects, rulers: this.rulers,
                    notes: this.notes, script: this.script, railLast: this.railLast, objectsW: this.objectsW,
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
            hide(k) { this[k] = false; this._save(); },
        });
    };
    document.addEventListener('alpine:init', __registerViewStore);
    document.addEventListener('livewire:navigated', __registerViewStore);
    // …and register NOW when Alpine has already booted. This script is pushed to the scripts stack,
    // so on some loads it runs AFTER alpine:init has fired — then neither listener ever calls it,
    // $store.view stays undefined, and every x-effect reading it throws (which kills the panels and
    // the preview mount). NB: never write a bare Blade directive name in these comments — Blade
    // compiles it even inside a JS comment.
    __registerViewStore();
    </script>
    @endpush

    {{-- Top toolbar — Keynote-style: Play + insert tools as stacked icon-over-label buttons.
         Solid background (no opacity/blur) so the composition never shows through. wire:ignore
         keeps the 3s status poll from morphing the strip and snapping the Panel popover shut. --}}
    {{-- z-50: must out-stack the z-40 resize handles (rail + object list) — they are later
         fixed siblings, so at equal z they'd sit above this toolbar's DROPDOWNS and swallow
         clicks on menu items (View ▸ Object list was dead at the default rail width). --}}
    <div class="fixed right-0 top-0 z-50 flex h-16 items-center justify-between border-b border-slate-800 bg-slate-900 px-3 shadow-lg left-0 pl-16"
         wire:ignore
         {{-- fmtOpen/fmtView mirror the Format panel's state (broadcast by the aside via
              inspector-state) so toolbar buttons can show a proper OPEN state — the toolbar is
              wire:ignore and a sibling Alpine scope, so it can't read the panel directly. --}}
         x-data="{ addOpen: false, viewOpen: false, fmtOpen: false, fmtView: 'scene' }"
         x-init="$nextTick(() => window.dispatchEvent(new CustomEvent('inspector-state-request')))"
         x-on:inspector-state.window="fmtOpen = $event.detail.open; if ($event.detail.view) fmtView = $event.detail.view">
        {{-- Left group: View menu + Play + insert tools --}}
        <div class="flex items-stretch gap-0.5">
        {{-- View menu — show/hide workspace surfaces (Keynote's View) --}}
        <div class="relative">
            <button type="button" @click="viewOpen = !viewOpen"
                    class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-amber-300"
                    :class="viewOpen && 'bg-sky-500/15 text-sky-300'"
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
                    { k: 'script',  label: @js(__('Script')) },
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
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-base-200 hover:text-amber-300"
                title="{{ __('Play the lesson') }}" aria-label="{{ __('Play the lesson') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Play') }}</span>
        </button>

        <div class="relative">
            <button type="button" @click="addOpen = !addOpen"
                    class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-base-200 hover:text-amber-300"
                    :class="addOpen && 'bg-sky-500/15 text-sky-300'"
                    aria-haspopup="menu" :aria-expanded="addOpen.toString()"
                    title="{{ __('Add a scene, text, image, or clipart') }}" aria-label="{{ __('Add') }}">
                <x-icons.plus class="h-6 w-6" />
                <span class="text-[10px] font-medium">{{ __('Add') }}</span>
            </button>
            <div x-show="addOpen" x-cloak @click.outside="addOpen = false" x-transition.opacity.duration.150ms
                 class="absolute left-0 top-full z-50 mt-1.5 w-56 rounded-xl border border-slate-700 bg-base-300 p-1.5 shadow-2xl" role="menu">
                <p class="px-2 py-1 text-[10px] uppercase tracking-widest text-slate-500">{{ __('Add Scene') }}</p>
                <button type="button" @click="Livewire.dispatch('scene:add'); addOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-200 hover:bg-base-200" role="menuitem">
                    <x-icons.plus class="h-4 w-4 shrink-0 text-amber-400" />
                    <span class="flex-1 font-medium">{{ __('Add Scene') }}</span>
                    <span class="text-xs text-slate-500">{{ __('Below current') }}</span>
                </button>
                <div class="my-1 border-t border-slate-700" role="separator"></div>
                <p class="px-2 py-1 text-[10px] uppercase tracking-widest text-slate-500">{{ __('Add Layer') }}</p>
                <button type="button" @click="window.dispatchEvent(new CustomEvent('lesson:add-text')); addOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-200 hover:bg-base-200" role="menuitem">
                    <x-icons.pencil class="h-4 w-4 shrink-0 text-slate-400" />
                    <span>{{ __('Text') }}</span>
                </button>
                <button type="button" @click="Livewire.dispatch('open-image-library'); addOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-200 hover:bg-base-200" role="menuitem">
                    <x-icons.photo class="h-4 w-4 shrink-0 text-slate-400" />
                    <span>{{ __('Image') }}</span>
                </button>
                <button type="button" @click="Livewire.dispatch('open-svg-library'); addOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-200 hover:bg-base-200" role="menuitem">
                    <x-icons.puzzle-piece class="h-4 w-4 shrink-0 text-slate-400" />
                    <span>{{ __('Clipart') }}</span>
                </button>
                {{-- 3D model / video AS A LAYER (an iframe layer on top of the scene, like clipart). --}}
                <button type="button" @click="const l = window.prompt(@js(__('Paste a Sketchfab 3D model link'))); if (l) $wire.addEmbedLayer(l, '3d'); addOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-200 hover:bg-base-200" role="menuitem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
                    <span>{{ __('3D model') }}</span>
                </button>
                <button type="button" @click="const l = window.prompt(@js(__('Paste a YouTube / Vimeo link'))); if (l) $wire.addEmbedLayer(l, 'video'); addOpen = false"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-200 hover:bg-base-200" role="menuitem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h8.25a2.25 2.25 0 0 0 2.25-2.25V7.5a2.25 2.25 0 0 0-2.25-2.25H4.5A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    <span>{{ __('Video') }}</span>
                </button>
                <div class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm text-slate-200 hover:bg-base-200" role="group" aria-label="{{ __('Add panel') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m-4.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z" />
                    </svg>
                    <span class="flex-1">{{ __('Panel') }}</span>
                    <button type="button" @click="window.dispatchEvent(new CustomEvent('lesson:add-rect', { detail: { side: 'left' } })); addOpen = false"
                            class="rounded border border-slate-600 px-1.5 py-0.5 text-xs text-slate-300 transition hover:border-amber-400 hover:text-amber-300">{{ __('Left') }}</button>
                    <button type="button" @click="window.dispatchEvent(new CustomEvent('lesson:add-rect', { detail: { side: 'right' } })); addOpen = false"
                            class="rounded border border-slate-600 px-1.5 py-0.5 text-xs text-slate-300 transition hover:border-amber-400 hover:text-amber-300">{{ __('Right') }}</button>
                </div>
            </div>
        </div>

        <div class="mx-1 my-1.5 w-px bg-slate-700"></div>
        </div>

        {{-- Right group: global actions, pushed to the far right --}}
        <div class="flex items-stretch gap-0.5">
        {{-- Format — show/hide the fixed inspector panel. The toolbar sits in its own small
             Alpine scope (a SIBLING of step3SceneConfigurator), so this goes via a window
             event the panel component listens for. --}}
        {{-- Selector, not a blind toggle: open + switch to Format; only close when Format is
             already the shown view (so Settings → Format switches instead of closing). --}}
        <button type="button"
                @click="fmtOpen && fmtView !== 'settings'
                    ? window.dispatchEvent(new CustomEvent('inspector-toggle'))
                    : (Livewire.dispatch('open-lesson-format'), window.dispatchEvent(new CustomEvent('inspector-open')))"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-amber-300"
                :class="fmtOpen && fmtView !== 'settings' && 'bg-sky-500/15 text-sky-300'"
                title="{{ __('Show or hide the Format panel') }}" aria-label="{{ __('Format') }}">
            {{-- Painting/picture icon (Noun Project, filled) — reads as "scene formatting". --}}
            <svg viewBox="0 0 100 100" fill="currentColor" class="h-6 w-6" aria-hidden="true">
                <path d="m79.168 26.043h-12.203l-7.8867-11.793c-1.9961-3.0508-5.3828-4.875-9.0742-4.875s-7.0781 1.8242-9.0547 4.8477l-7.9023 11.82h-12.203c-6.3203 0-11.457 5.1367-11.457 11.457v41.668c0 6.3203 5.1367 11.457 11.457 11.457h58.332c6.3203 0 11.457-5.1367 11.457-11.457v-41.668c0-6.3203-5.1367-11.457-11.457-11.457zm-33.008-8.375c1.6445-2.5195 6.0195-2.5508 7.6992 0.027343l5.582 8.3477h-18.883zm38.215 61.5c0 2.8711-2.3359 5.207-5.207 5.207h-58.336c-2.8711 0-5.207-2.3359-5.207-5.207v-2.457l13.109-8.4141c2.8125-1.4141 6.1172-1.3125 9.0273 0.35156l18.918 9.168c0.4375 0.21094 0.90234 0.3125 1.3633 0.3125 1.1602 0 2.2734-0.64453 2.8125-1.7617 0.75391-1.5547 0.10547-3.4219-1.4492-4.1758l-0.66406-0.32031 4.7578-1.1914c1.9258-0.47656 3.9141-0.34766 5.7539 0.39062l15.113 6.0469v2.0508zm0-8.7852-12.797-5.1172c-3.0703-1.2344-6.3906-1.4531-9.5898-0.64844l-12.016 3.0039-9.2891-4.4961c-4.543-2.6172-10.059-2.7656-15.035-0.25781l-10.023 6.4219v-31.789c0-2.8711 2.3359-5.207 5.207-5.207h58.332c2.8711 0 5.207 2.3359 5.207 5.207v32.883zm-30.207-31.84c-6.3203 0-11.457 5.1367-11.457 11.457s5.1367 11.457 11.457 11.457c6.3203 0 11.457-5.1367 11.457-11.457s-5.1367-11.457-11.457-11.457zm0 16.668c-2.8711 0-5.207-2.3359-5.207-5.207s2.3359-5.207 5.207-5.207c2.8711 0 5.207 2.3359 5.207 5.207s-2.3359 5.207-5.207 5.207z"/>
            </svg>
            <span class="text-[10px] font-medium">{{ __('Format') }}</span>
        </button>

        {{-- Settings — global class/lesson settings (Story + Music). Lives on the toolbar, not
             inside the per-scene inspector. --}}
        <button type="button"
                @click="fmtOpen && fmtView === 'settings'
                    ? window.dispatchEvent(new CustomEvent('inspector-toggle'))
                    : (Livewire.dispatch('open-lesson-settings'), window.dispatchEvent(new CustomEvent('inspector-open')))"
                class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-amber-300"
                :class="fmtOpen && fmtView === 'settings' && 'bg-sky-500/15 text-sky-300'"
                title="{{ __('Class & lesson settings') }}" aria-label="{{ __('Settings') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Settings') }}</span>
        </button>

        {{-- Publish — now, or schedule for later (every scene must be ready) --}}
        <div x-data="{ open: false, when: '' }" class="relative" @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" @click="open = !open"
                    class="flex w-14 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-amber-300"
                    :class="open && 'bg-amber-500/15 text-amber-300'"
                    title="{{ __('Publish this lesson') }}" aria-label="{{ __('Publish') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                <span class="text-[10px] font-medium">{{ __('Publish') }}</span>
            </button>
            <div x-show="open" x-transition x-cloak
                 class="absolute right-0 top-full z-70 mt-1 w-64 rounded-xl border border-slate-700 bg-base-300 p-2 text-left shadow-2xl">
                <button type="button" @click="open = false; Livewire.dispatch('lesson:publish')"
                        class="flex w-full items-center gap-2 rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-slate-950 transition hover:bg-amber-400">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/></svg>
                    {{ __('Publish now') }}
                </button>
                <div class="mt-2 border-t border-slate-700/50 pt-2">
                    <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Schedule for later') }}</span>
                    <input type="datetime-local" x-model="when"
                           class="input input-xs input-bordered w-full bg-slate-900" />
                    <button type="button" x-bind:disabled="!when"
                            @click="if (when) { $wire.schedulePublish(when); open = false }"
                            class="mt-1.5 flex w-full items-center justify-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-slate-100 transition hover:bg-slate-700 disabled:opacity-40">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        {{ __('Schedule') }}
                    </button>
                </div>
                @if ($lesson->scheduled_publish_at)
                    <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-700/50 pt-2 text-[11px] text-amber-300">
                        <span>{{ __('Scheduled') }}: {{ $lesson->scheduled_publish_at->isoFormat('D MMM, HH:mm') }}</span>
                        <button type="button" wire:click="cancelSchedule" class="text-rose-300 underline hover:text-rose-200">{{ __('Cancel') }}</button>
                    </div>
                @endif
            </div>
        </div>
        </div>
    </div>

    {{-- ══ View surfaces (toggled by the View menu) ══════════════════════════════════ --}}

    {{-- Rulers — hug the actual canvas stage (top edge + left edge), positioned by JS so they
         always sit flush against the canvas and shift right when the rail/object list push it.
         The canvas reserves --ruler-w on its top & left so the rulers never overlap it. --}}
    {{-- Visibility AND position are driven entirely by the JS below (not Alpine x-show), so neither
         the 3s Livewire poll nor an Alpine re-init can flicker them. wire:ignore keeps the poll off
         their JS-injected number labels. --}}
    <div id="ruler-top" wire:ignore class="pointer-events-none fixed z-30"
         style="display:none; background: #0f172a repeating-linear-gradient(to right, rgba(148,163,184,.5) 0 1px, transparent 1px 50px);
                border-bottom: 1px solid rgba(148,163,184,.3);"></div>
    <div id="ruler-left" wire:ignore class="pointer-events-none fixed z-30"
         style="display:none; background: #0f172a repeating-linear-gradient(to bottom, rgba(148,163,184,.5) 0 1px, transparent 1px 50px);
                border-right: 1px solid rgba(148,163,184,.3);"></div>
    @push('scripts')
    <script>
    (() => {
        const RW = 20;
        // Number labels every 100px, origin (0) at the canvas top-left corner — like Keynote.
        const buildLabels = (el, len, axis) => {
            const key = Math.round(len / 100);
            let layer = el.querySelector('.ruler-nums');
            if (el._len === key && layer) return;   // rebuild on a 100px change or if morphed away
            el._len = key;
            if (!layer) {
                layer = document.createElement('div');
                layer.className = 'ruler-nums';
                layer.style.cssText = 'position:absolute;inset:0;overflow:hidden';
                el.appendChild(layer);
            }
            let html = '';
            for (let n = 0; n <= len; n += 100) {
                html += axis === 'x'
                    ? `<span style="position:absolute;left:${n + 2}px;bottom:1px;font-size:8px;line-height:1;color:#94a3b8;font-variant-numeric:tabular-nums">${n}</span>`
                    : `<span style="position:absolute;top:${n + 1}px;left:2px;font-size:8px;line-height:1;color:#94a3b8;font-variant-numeric:tabular-nums">${n}</span>`;
            }
            layer.innerHTML = html;
        };
        const boot = () => {
            const canvas = document.getElementById('lesson-canvas-root');
            const top = document.getElementById('ruler-top');
            const left = document.getElementById('ruler-left');
            if (!canvas || !top || !left) return;
            const place = () => {
                const r = canvas.getBoundingClientRect();
                Object.assign(top.style, { left: `${r.left}px`, top: `${r.top - RW}px`, width: `${r.width}px`, height: `${RW}px` });
                Object.assign(left.style, { left: `${r.left - RW}px`, top: `${r.top}px`, width: `${RW}px`, height: `${r.height}px` });
                buildLabels(top, r.width, 'x');
                buildLabels(left, r.height, 'y');
            };
            const tick = () => {
                const on = !!window.Alpine?.store('view')?.rulers;
                const disp = on ? 'block' : 'none';
                if (top.style.display !== disp) { top.style.display = disp; left.style.display = disp; }
                if (on) place();
            };
            window.__placeRulers = tick;   // View x-effect nudges visibility + position on toggle
            tick();
            new ResizeObserver(() => { if (window.Alpine?.store('view')?.rulers) place(); }).observe(canvas);
            window.addEventListener('resize', tick);
            // A light poll keeps the rulers glued to the stage through rail / object-list shifts.
            setInterval(tick, 120);
        };
        if (document.readyState !== 'loading') boot();
        else document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('livewire:navigated', boot);
    })();
    </script>
    @endpush

    {{-- Ruler guides — drag from a ruler onto the stage to drop a dotted guide line with a live
         pixel label. Guides are magnetic: an object snaps when dragged within 10px (the snap
         helper is read by TextOverlayLayer). Session-only; shown with the rulers. --}}
    @push('scripts')
    <script>
    (() => {
        const AMBER = '#f59e0b';
        const boot = () => {
            const canvas = document.getElementById('lesson-canvas-root');
            const rTop = document.getElementById('ruler-top');
            const rLeft = document.getElementById('ruler-left');
            if (!canvas || !rTop || !rLeft) return;
            document.getElementById('guide-host')?.remove();   // fresh start (SPA nav recreates the canvas)

            const host = document.createElement('div');
            host.id = 'guide-host';
            host.style.cssText = 'position:fixed;z-index:20;pointer-events:none;overflow:visible';
            document.body.appendChild(host);

            const label = document.createElement('div');
            label.style.cssText = 'position:fixed;z-index:60;pointer-events:none;display:none;background:' + AMBER +
                ';color:#1a1206;font:600 10px/1.4 ui-sans-serif,system-ui;padding:1px 5px;border-radius:3px;font-variant-numeric:tabular-nums';
            document.body.appendChild(label);
            const showLabel = (x, y, t) => { label.textContent = t; label.style.left = (x + 12) + 'px'; label.style.top = (y + 12) + 'px'; label.style.display = 'block'; };
            const hideLabel = () => { label.style.display = 'none'; };

            const guides = [];
            let idc = 0;
            const rect = () => canvas.getBoundingClientRect();

            const layout = () => {
                const r = rect();
                host.style.display = window.Alpine?.store('view')?.rulers ? 'block' : 'none';
                host.style.left = r.left + 'px'; host.style.top = r.top + 'px';
                host.style.width = r.width + 'px'; host.style.height = r.height + 'px';
                for (const g of guides) {
                    if (g.axis === 'v') {
                        g.el.style.cssText = `position:absolute;top:0;bottom:0;left:${g.frac * r.width}px;width:9px;margin-left:-4px;pointer-events:auto;cursor:ew-resize`;
                        g.line.style.cssText = 'position:absolute;left:4px;top:0;bottom:0;border-left:1px dotted ' + AMBER;
                    } else {
                        g.el.style.cssText = `position:absolute;left:0;right:0;top:${g.frac * r.height}px;height:9px;margin-top:-4px;pointer-events:auto;cursor:ns-resize`;
                        g.line.style.cssText = 'position:absolute;top:4px;left:0;right:0;border-top:1px dotted ' + AMBER;
                    }
                }
            };

            const moveTo = (g, cx, cy) => {
                const r = rect();
                if (g.axis === 'v') { const px = Math.max(0, Math.min(r.width, cx - r.left)); g.frac = px / r.width; showLabel(cx, cy, 'X ' + Math.round(px)); }
                else { const py = Math.max(0, Math.min(r.height, cy - r.top)); g.frac = py / r.height; showLabel(cx, cy, 'Y ' + Math.round(py)); }
                layout();
            };
            const offCanvas = (cx, cy) => { const r = rect(); return cx < r.left - 10 || cx > r.right + 10 || cy < r.top - 10 || cy > r.bottom + 10; };
            const del = (g) => { g.el.remove(); const i = guides.indexOf(g); if (i >= 0) guides.splice(i, 1); };

            const startDrag = (g, cx, cy) => {
                moveTo(g, cx, cy);
                const mv = (ev) => moveTo(g, ev.clientX, ev.clientY);
                const up = (ev) => {
                    window.removeEventListener('pointermove', mv); window.removeEventListener('pointerup', up);
                    hideLabel();
                    if (offCanvas(ev.clientX, ev.clientY)) del(g);
                };
                window.addEventListener('pointermove', mv); window.addEventListener('pointerup', up);
            };

            const add = (axis) => {
                const el = document.createElement('div'); const line = document.createElement('div');
                el.appendChild(line); host.appendChild(el);
                const g = { id: ++idc, axis, frac: 0, el, line };
                el.addEventListener('pointerdown', (e) => { e.preventDefault(); e.stopPropagation(); startDrag(g, e.clientX, e.clientY); });
                guides.push(g); return g;
            };

            const pull = (ruler, axis) => {
                ruler.style.pointerEvents = 'auto';
                ruler.style.cursor = axis === 'v' ? 'ew-resize' : 'ns-resize';
                ruler.addEventListener('pointerdown', (e) => { e.preventDefault(); startDrag(add(axis), e.clientX, e.clientY); });
            };
            pull(rLeft, 'v'); pull(rTop, 'h');

            // Magnetic snap — TextOverlayLayer calls this with the dragged object's screen rect.
            window.__guides = {
                snap(objRect) {
                    const r = rect(), T = 10; let dx = 0, dy = 0, bestX = T, bestY = T;
                    for (const g of guides) {
                        if (g.axis === 'v') {
                            const gx = r.left + g.frac * r.width;
                            for (const ox of [objRect.left, (objRect.left + objRect.right) / 2, objRect.right]) {
                                const d = gx - ox; if (Math.abs(d) <= bestX) { bestX = Math.abs(d); dx = d; }
                            }
                        } else {
                            const gy = r.top + g.frac * r.height;
                            for (const oy of [objRect.top, (objRect.top + objRect.bottom) / 2, objRect.bottom]) {
                                const d = gy - oy; if (Math.abs(d) <= bestY) { bestY = Math.abs(d); dy = d; }
                            }
                        }
                    }
                    return { dx, dy };
                },
                // Screen-x of every vertical guide + the canvas rect — TextOverlayLayer uses these
                // to auto-align a fresh title to a ruler dropped on the left half of the stage.
                verticals() {
                    const r = rect();
                    return { rect: r, xs: guides.filter(g => g.axis === 'v').map(g => r.left + g.frac * r.width) };
                },
            };

            layout();
            new ResizeObserver(layout).observe(canvas);
            window.addEventListener('resize', layout);
            setInterval(layout, 150);
        };
        if (document.readyState !== 'loading') boot();
        else document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('livewire:navigated', boot);
    })();
    </script>
    @endpush

    {{-- Object list — a full-height panel docked to the right of the Scenes rail. Lists the
         scene's objects (title/text boxes, backing panels); click to flash-locate on the stage.
         Drag its right edge to resize; drag it narrow (≤108px) and it collapses to icons-only
         (labels + adjust hidden, icons centred) so layers can still be reordered in a sliver. --}}
    {{-- Compact (icons-only) toggled by data-compact (set from the panel's clientWidth in the
         objectList Alpine). NOT a CSS container query: container-type would establish a containing
         block for SortableJS's position:fixed reorder ghost and break the layer-drag interaction. --}}
    <style>
        [data-objlist-col][data-compact] [data-obj-label] { display: none !important; }
        [data-objlist-col][data-compact] [data-obj-adjust] { display: none !important; }
        [data-objlist-col][data-compact] [data-obj-row] { justify-content: center; padding-left: 0.25rem; padding-right: 0.25rem; gap: 0; }
    </style>
    <div x-show="$store.view.objects" x-cloak x-data="objectList()" x-init="init()"
         @scene-objects-changed.window="refresh()"
         @scene-object-selected.window="selectedId = $event.detail.id"
         data-objlist-col
         class="fixed z-30 overflow-hidden border-r border-slate-700 bg-slate-900"
         style="left: var(--rail-w, 11rem); width: var(--objlist-w, 13rem); top: 4rem; bottom: 0;">
        <div x-ref="list" class="h-full space-y-0.5 overflow-y-auto p-1.5">
            <template x-for="obj in items" :key="obj.id">
                {{-- The whole row is the drag handle (grab cursor); only the adjust button opts out.
                     Text and clipart rows reorder; the background is pinned to the bottom ([data-bg]). --}}
                <div :data-obj-id="obj.bg ? null : obj.id" :data-bg="obj.bg ? '1' : null"
                     data-obj-row
                     @click="select(obj)"
                     :class="[
                        obj.bg ? 'cursor-pointer' : 'cursor-grab active:cursor-grabbing',
                        selectedId === obj.id ? 'bg-sky-500/15 text-sky-100 ring-1 ring-sky-400/50' : 'text-slate-200 hover:bg-slate-800',
                     ]"
                     class="group flex w-full select-none items-center gap-1.5 rounded-lg px-2 py-1.5 text-left text-sm">
                    <span class="shrink-0 text-slate-400" x-html="iconSvg(obj)" aria-hidden="true" :title="obj.label"></span>
                    <span data-obj-label class="flex-1 truncate" x-text="obj.label"></span>
                    {{-- Row actions (hover-revealed, Keynote-style). data-nodrag stops a press here
                         from starting a Sortable drag; @click.stop keeps the row's select from firing.
                         data-obj-adjust hides both in the compact (icons-only) rail. --}}
                    {{-- Adjust — select the object and open its Format inspector. --}}
                    <button type="button" data-nodrag data-obj-adjust @click.stop="edit(obj)"
                            class="btn btn-ghost btn-xs btn-square shrink-0 text-slate-400 opacity-0 transition hover:text-amber-300 group-hover:opacity-100"
                            aria-label="{{ __('Adjust settings') }}" :title="@js(__('Adjust settings'))">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                        </svg>
                    </button>
                    {{-- Delete THIS object (any type — deleteObject routes by obj.id: art_/txt_/rect_).
                         Hidden on the background row (not deletable). No confirm — one click removes it. --}}
                    <button type="button" data-nodrag data-obj-adjust x-show="!obj.bg"
                            @click.stop="deleteObject(obj)"
                            class="btn btn-ghost btn-xs btn-square shrink-0 text-slate-500 opacity-0 transition hover:text-rose-400 group-hover:opacity-100"
                            aria-label="{{ __('Delete object') }}" :title="@js(__('Delete'))">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </template>
        </div>
    </div>

    {{-- Object-list resize handle — sits at the panel's right edge (rail + objlist). Drag to
         resize; drag it under 40px and the list hides (View ▸ Object list re-opens). Only
         rendered while the list is shown. --}}
    <div x-show="$store.view.objects" x-cloak id="objlist-resize" wire:ignore tabindex="0"
         class="group fixed z-40 -ml-1.5 w-3 cursor-col-resize focus:outline-none focus-visible:bg-sky-400/20"
         style="left: calc(var(--rail-w, 11rem) + var(--objlist-w, 13rem)); top: 4rem; bottom: var(--work-bottom, 0px)"
         role="separator" aria-orientation="vertical" aria-label="{{ __('Resize object list (arrow keys)') }}"
         title="{{ __('Drag to resize · drag narrow for icons only · drag to the edge to hide') }}">
        <div class="mx-auto h-full w-0.5 bg-transparent transition-colors group-hover:bg-sky-400"></div>
    </div>
    @push('scripts')
    <script>
    (() => {
        const rootEl = document.documentElement;   // --objlist-w lives on <html> so the stage inherits it
        const railW = () => parseFloat(getComputedStyle(rootEl).getPropertyValue('--rail-w')) || 0;
        const clamp = (v) => Math.max(0, Math.min(320, v));
        const boot = () => {
            const handle = document.getElementById('objlist-resize');
            if (!handle || handle.__wired) return;
            handle.__wired = true;
            let dragging = false;
            handle.addEventListener('pointerdown', (e) => {
                dragging = true; handle.setPointerCapture(e.pointerId);
                document.body.style.userSelect = 'none'; e.preventDefault();
            });
            handle.addEventListener('pointermove', (e) => {
                if (!dragging) return;
                rootEl.style.setProperty('--objlist-w', clamp(e.clientX - railW()) + 'px');
            });
            const end = (e) => {
                if (!dragging) return;
                dragging = false;
                try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
                document.body.style.userSelect = '';
                const rawO = parseFloat(rootEl.style.getPropertyValue('--objlist-w'));
                let w = clamp(Number.isFinite(rawO) ? rawO : 208);   // keep a real 0 (don't let || swallow the hide gesture)
                const store = window.Alpine?.store('view');
                if (w < 40) {                     // dragged to the edge → hide the list
                    rootEl.style.setProperty('--objlist-w', '0px');
                    if (store) { store.objects = false; store._save?.(); }
                    return;
                }
                if (w < 56) w = 56;               // icons-only floor
                rootEl.style.setProperty('--objlist-w', w + 'px');
                if (store) { store.objectsW = w; store._save?.(); }
            };
            handle.addEventListener('pointerup', end);
            handle.addEventListener('pointercancel', end);
            // Keyboard: arrows resize, Home resets, End hides (WAI-ARIA splitter pattern).
            handle.addEventListener('keydown', (e) => {
                const step = e.shiftKey ? 32 : 12;
                let w = parseFloat(getComputedStyle(rootEl).getPropertyValue('--objlist-w')) || 0;
                if (e.key === 'ArrowLeft') w -= step;
                else if (e.key === 'ArrowRight') w += step;
                else if (e.key === 'Home') w = 208;
                else if (e.key === 'End') w = 0;
                else return;
                e.preventDefault();
                w = clamp(w);
                const store = window.Alpine?.store('view');
                if (w < 40) { rootEl.style.setProperty('--objlist-w', '0px'); if (store) { store.objects = false; store._save?.(); } return; }
                if (w < 56) w = 56;
                rootEl.style.setProperty('--objlist-w', w + 'px');
                if (store) { store.objectsW = w; store._save?.(); }
            });
        };
        if (document.readyState !== 'loading') boot();
        else document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('livewire:navigated', boot);
    })();
    </script>
    @endpush

    {{-- Backspace / Delete removes the currently-selected scene object (text box, backing panel, or
         image/clipart layer). Guarded so it NEVER fires while typing: any input/textarea/select or
         contenteditable (on-canvas text, the script editor, the scene caption, internal notes) puts
         document.activeElement on that surface, and a live text selection inside an editable region
         is also skipped. Routes through the same scene:delete-object as the object-list ×. --}}
    @push('scripts')
    <script>
    (() => {
        if (window.__lessonDeleteKeyMounted) return;
        window.__lessonDeleteKeyMounted = true;
        window.addEventListener('keydown', (e) => {
            if (e.key !== 'Backspace' && e.key !== 'Delete') return;
            if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) return;
            const a = document.activeElement;
            if (a && (a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) return;
            const sel = window.getSelection?.();
            if (sel && !sel.isCollapsed && sel.anchorNode?.parentElement?.closest?.('[contenteditable], input, textarea')) return;
            const id = window.__lessonArtworkLayer?._selectedId || window.__lessonTextLayer?._selectedId;
            if (!id || id === '__bg__') return;
            e.preventDefault();
            window.Livewire.dispatch('scene:delete-object', { objectId: id });
        });
    })();
    </script>
    @endpush

    {{-- Script editing view — timecoded narration synced to a play bar (View ▸ Script). --}}
    @if ($this->selectedSceneModel)
        <x-lesson.script-editor :scene="$this->selectedSceneModel" wire:key="script-{{ $selectedSceneId }}" />
    @endif

    {{-- Internal notes — the teacher's private per-lesson scratchpad (this browser). --}}
    <div x-show="$store.view.notes" x-cloak
         x-data="{ note: '', key: 'wizard.notes.{{ $lesson->id }}',
                   init() { this.note = localStorage.getItem(this.key) || ''; },
                   save() { localStorage.setItem(this.key, this.note); } }"
         class="fixed bottom-0 z-40 w-72 overflow-hidden border-l border-t border-slate-700 bg-base-300"
         style="right: var(--work-right, 16rem);">
        <div class="flex items-center justify-between border-b border-slate-700/60 bg-base-200/60 px-3 py-2">
            <span class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">{{ __('Internal notes') }}</span>
            <button type="button" @click="$store.view.hide('notes')" class="text-slate-500 hover:text-slate-200" aria-label="Close">✕</button>
        </div>
        <textarea x-model="note" @input.debounce.400ms="save()" rows="6"
                  placeholder="{{ __('Private notes for this lesson. Only you can see these.') }}"
                  class="w-full resize-none border-0 bg-transparent p-3 text-sm text-slate-200 focus:outline-none"></textarea>
    </div>

    {{-- Scene rail (vertical, left edge) --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" editable />

    {{-- Rail resize handle — drag the rail's right edge to resize it; drag it under 24px (near
         the edge) and it disappears completely. Sits at the rail's right edge; when the rail is
         hidden (0) it rests at the far left so it can be dragged back out. --}}
    <div id="rail-resize" wire:ignore tabindex="0"
         class="group fixed top-16 z-40 -ml-1.5 w-3 cursor-col-resize focus:outline-none focus-visible:bg-sky-400/20"
         style="left: var(--rail-w, 11rem); bottom: var(--work-bottom, 0px)"
         role="separator" aria-orientation="vertical" aria-label="{{ __('Resize scene rail (arrow keys)') }}"
         title="{{ __('Drag to resize · drag to the edge to hide') }}">
        <div class="mx-auto h-full w-0.5 bg-transparent transition-colors group-hover:bg-sky-400"></div>
    </div>
    @push('scripts')
    <script>
    (() => {
        const KEY = 'wizard.rail.w';
        const rootEl = document.documentElement;   // --rail-w lives on <html> so panels inherit it
        const clamp = (v) => Math.max(0, Math.min(280, v));
        const boot = () => {
            const handle = document.getElementById('rail-resize');
            if (!handle || handle.__wired) return;
            handle.__wired = true;
            let dragging = false;
            handle.addEventListener('pointerdown', (e) => {
                dragging = true; handle.setPointerCapture(e.pointerId);
                document.body.style.userSelect = 'none'; e.preventDefault();
            });
            handle.addEventListener('pointermove', (e) => {
                if (!dragging) return;
                rootEl.style.setProperty('--rail-w', clamp(e.clientX) + 'px');
            });
            const commit = (w) => {
                w = clamp(w);
                if (w < 24) w = 0;                 // under 24px → hide completely
                else if (w < 44) w = 44;           // compact floor (numbers-only rail; ≤92px shows numbers)
                rootEl.style.setProperty('--rail-w', w + 'px');
                localStorage.setItem(KEY, String(w));
                // Keep the View ▸ Scenes checkbox in sync with a hide / drag-out.
                const store = window.Alpine?.store('view');
                if (store) { store.scenes = w > 0; if (w > 0) store.railLast = w; store._save?.(); }
            };
            const end = (e) => {
                if (!dragging) return;
                dragging = false;
                try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
                document.body.style.userSelect = '';
                const rawR = parseFloat(rootEl.style.getPropertyValue('--rail-w'));
                commit(Number.isFinite(rawR) ? rawR : 176);   // keep a real 0 (don't let || swallow the hide gesture)
            };
            handle.addEventListener('pointerup', end);
            handle.addEventListener('pointercancel', end);
            // Keyboard: arrows resize, Home resets, End hides (WAI-ARIA splitter pattern).
            handle.addEventListener('keydown', (e) => {
                const step = e.shiftKey ? 32 : 12;
                let w = parseFloat(rootEl.style.getPropertyValue('--rail-w')) || 0;
                if (e.key === 'ArrowLeft') w -= step;
                else if (e.key === 'ArrowRight') w += step;
                else if (e.key === 'Home') w = 176;
                else if (e.key === 'End') w = 0;
                else return;
                e.preventDefault();
                commit(w);
            });
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
         role="dialog" aria-modal="true"
         x-data x-on:keydown.escape.window="if ($wire.addSceneOpen) $wire.set('addSceneOpen', false)">
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
                <button type="button" wire:click="addScene('gallery')"
                        class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                    <x-lesson.scene-type-thumb kind="gallery" />
                    <span class="text-sm font-medium text-slate-200">Gallery</span>
                </button>
                {{-- Branching is meaningless on a voyage lesson, whose spine is the route. --}}
                @if (($lesson->game_type ?? null) !== 'voyage')
                    <button type="button" wire:click="addScene('branch')"
                            class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                        <x-lesson.scene-type-thumb kind="branch" />
                        <span class="text-sm font-medium text-slate-200">Decision point</span>
                    </button>
                @endif
                @if (($lesson->game_type ?? null) === 'voyage')
                    {{-- Drop the new leg's destination at the current map centre (fallback in PHP if none). --}}
                    <button type="button"
                            @click="const c = (window.__voyageCenter && window.__voyageCenter()) || null; $wire.addScene('voyage', null, c ? c.lng : null, c ? c.lat : null)"
                            class="group flex flex-col gap-2 rounded-box border border-slate-700/70 bg-base-200/60 p-2 text-left transition hover:border-amber-400">
                        <x-lesson.scene-type-thumb kind="voyage" />
                        <span class="text-sm font-medium text-slate-200">Route waypoint</span>
                    </button>
                @endif
            </div>
        </div>
        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('addSceneOpen', false)"></button>
    </div>

    {{-- Painting picker — public-domain artworks from the corpus. Click a thumbnail to set it
         as this scene's background (downloaded to lesson storage; attribution stored on the scene). --}}
    {{-- Visibility is driven by DaisyUI's `.modal-open` class (native open/close animation), NOT
         x-show — an x-show/x-cloak display:none stops DaisyUI 5's @starting-style opacity
         transition on .modal-box from ever running, leaving the box stuck at opacity 0 (invisible
         "open" modal). `dismissing` still gives an instant close when a thumbnail is picked. --}}
    <div class="modal modal-bottom sm:modal-middle"
         x-data="{ dismissing: false }"
         :class="{ 'modal-open': $wire.paintingPickerOpen && !dismissing }"
         x-on:painting-picker:open.window="dismissing = false"
         x-on:keydown.escape.window="if ($wire.paintingPickerOpen) $wire.set('paintingPickerOpen', false)"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-6xl border border-slate-700/70 bg-base-300">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-200">
                    @switch($paintingPickerMode)
                        @case('layer') {{ __('Add an image on the slide') }} @break
                        @case('voyage_stop') {{ __('Add a landfall image') }} @break
                        @case('gallery_image') {{ __('Add a gallery image') }} @break
                        @default {{ __('Set the scene background') }}
                    @endswitch
                </h2>
                <button type="button" class="btn btn-ghost btn-sm btn-circle text-slate-400"
                        aria-label="{{ __('Close') }}" wire:click="$set('paintingPickerOpen', false)">✕</button>
            </div>

            {{-- Filters on one line; search collapses to an icon on the right that opens an overlay. --}}
            <div class="relative mb-5" x-data="{ searching: @entangle('searchOpen').live }">
                <div class="flex items-center gap-2">
                    @foreach (['' => __('Everything'), 'painting' => __('Paintings'), 'city_map' => __('City plans')] as $kindVal => $kindLabel)
                        <button type="button" wire:click="$set('paintingKind', '{{ $kindVal }}')"
                                class="btn btn-xs flex-none {{ $paintingKind === $kindVal ? 'bg-amber-500 text-slate-950 border-0 hover:bg-amber-400' : 'btn-outline border-slate-600 text-slate-400 hover:border-amber-400 hover:text-amber-300' }}">
                            {{ $kindLabel }}
                        </button>
                    @endforeach

                    <span class="mx-1 hidden h-4 w-px flex-none bg-slate-700 sm:block"></span>
                    <span class="hidden flex-none text-[10px] font-semibold uppercase tracking-wider text-slate-500 lg:block">{{ __('Region') }}</span>
                    {{-- Region chips need ~26rem — on smaller screens they overflowed the modal,
                         so below lg they collapse into a compact dropdown. --}}
                    <div class="hidden flex-none items-center gap-2 lg:flex">
                        @foreach (['' => __('Auto'), 'european' => __('Europe'), 'americas' => __('Americas'), 'asia' => __('Asia'), 'africa' => __('Africa'), 'all' => __('All')] as $regVal => $regLabel)
                            <button type="button" wire:click="$set('paintingRegion', '{{ $regVal }}')"
                                    class="btn btn-xs flex-none {{ $paintingRegion === $regVal ? 'bg-sky-600 text-white border-0 hover:bg-sky-500' : 'btn-outline border-slate-600 text-slate-400 hover:border-sky-400 hover:text-sky-300' }}">
                                {{ $regLabel }}
                            </button>
                        @endforeach
                    </div>
                    <select class="select select-xs flex-none border-slate-600 bg-base-200 text-slate-300 lg:hidden"
                            aria-label="{{ __('Region') }}"
                            wire:change="$set('paintingRegion', $event.target.value)">
                        @foreach (['' => __('Region: Auto'), 'european' => __('Europe'), 'americas' => __('Americas'), 'asia' => __('Asia'), 'africa' => __('Africa'), 'all' => __('All')] as $regVal => $regLabel)
                            <option value="{{ $regVal }}" @selected($paintingRegion === $regVal)>{{ $regLabel }}</option>
                        @endforeach
                    </select>

                    <button type="button" x-show="!searching"
                            @click="searching = true; $nextTick(() => $refs.psearch.focus())"
                            class="btn btn-xs btn-circle btn-ghost ml-auto flex-none text-slate-400 hover:text-amber-300"
                            aria-label="{{ __('Search paintings') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                            <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                        </svg>
                    </button>
                </div>

                {{-- Overlay search bar, revealed by the icon --}}
                <div x-show="searching" x-cloak x-transition.opacity.duration.150ms
                     class="absolute inset-x-0 -top-2.5 z-20 flex items-center gap-3 rounded-xl border border-amber-500/50 bg-base-200 px-4 py-3 shadow-2xl">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5 flex-none text-slate-400">
                        <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input x-ref="psearch" type="search"
                           wire:model.live.debounce.400ms="paintingQuery"
                           placeholder="{{ __('Search paintings or painters (e.g. Caesar, Rembrandt)…') }}"
                           class="grow bg-transparent text-sm outline-none placeholder:text-slate-500"
                           @keydown.escape="searching = false; $wire.set('paintingQuery', '')" />
                </div>
            </div>

            @if ($paintingPickerOpen)
                {{-- wire:init loads the scored grid AFTER the modal paints; a skeleton shows meanwhile. --}}
                <div wire:init="preparePaintings">
                @if (! $paintingReady)
                    {{-- Skeleton fills the SAME fixed height as the loaded grid → no size jump. --}}
                    <div class="h-[62vh] overflow-hidden p-1.5">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @for ($i = 0; $i < 15; $i++)
                                <div class="animate-pulse rounded-lg bg-slate-700/40" style="aspect-ratio:16/10"></div>
                            @endfor
                        </div>
                    </div>
                @else
                {{-- Fixed-height scroll WRAPPER (not the grid — a fixed height on the grid would
                     stretch the tiles and kill their aspect ratio). p-1.5 keeps hover rings unclipped. --}}
                <div class="h-[62vh] overflow-y-auto p-1.5"
                     wire:loading.class="opacity-40"
                     wire:target="paintingQuery, paintingKind, paintingRegion, applyPaintingChoice">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @forelse ($this->paintingResults as $art)
                        {{-- @js() escapes the source/key as safe JS literals (titles carry apostrophes,
                             e.g. "Anon - 'Les Noyades…'", which broke the old inline addslashes
                             wire:click → "Public property [$]"). $wire.… keeps wire:loading tracking. --}}
                        <button type="button"
                                wire:key="art-{{ md5($art['source'].$art['key']) }}"
                                @click="dismissing = true; $wire.applyPaintingChoice(@js($art['source']), @js($art['key']))"
                                wire:loading.attr="disabled" wire:target="applyPaintingChoice"
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
                                      title="{{ __('Match correctness: soft criteria met') }}">
                                    ✓ {{ $art['correctness'] }}
                                </span>
                            @endif
                            <span class="absolute inset-x-0 bottom-0 truncate bg-black/70 px-2 py-1 text-left text-[10px] text-white">
                                {{ $art['title'] }}@if($art['caption']) · {{ $art['caption'] }}@endif
                            </span>
                        </button>
                    @empty
                        {{-- When the curated corpus comes up empty, "try another word" is dead-end advice:
                             the thing that actually finds obscure subjects is the Commons search, which
                             sits in the footer below and went unnoticed. Point at it instead. --}}
                        <div class="col-span-full py-10 text-center text-sm text-slate-400">
                            @if ($paintingSearchThrottled)
                                {{-- Wikimedia throttled us. Saying "not found" here would be a lie about
                                     an archive that may well hold the painting. --}}
                                <p>{{ __('Wikimedia is busy right now and asked us to slow down.') }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ __('Give it a few seconds, then search again.') }}</p>
                            @elseif ($paintingCommonsLoaded)
                                {{ __('No paintings found. Try a name, place or event, in Dutch or English.') }}
                            @else
                                <p>{{ __('Nothing in the curated collection matches that.') }}</p>
                                <button type="button" wire:click="$set('paintingCommonsLoaded', true)"
                                        class="btn btn-sm btn-outline mt-3 border-sky-500/60 text-sky-300 hover:border-sky-400 hover:text-sky-200">
                                    {{ __('Search all of Wikimedia Commons') }}
                                </button>
                            @endif
                        </div>
                    @endforelse
                </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    @unless ($paintingCommonsLoaded)
                        <button type="button"
                                wire:click="$set('paintingCommonsLoaded', true)"
                                wire:loading.attr="disabled" wire:target="paintingCommonsLoaded"
                                class="btn btn-sm btn-outline border-slate-600 text-slate-300 hover:border-sky-400 hover:text-sky-300 inline-flex items-center gap-1.5">
                            <span wire:loading wire:target="paintingCommonsLoaded"><x-icons.spinner class="w-3 h-3 animate-spin" /></span>
                            <span wire:loading.remove wire:target="paintingCommonsLoaded">{{ __('More from Wikimedia Commons') }}</span>
                            <span wire:loading wire:target="paintingCommonsLoaded">{{ __('Searching Wikimedia…') }}</span>
                        </button>
                    @endunless

                    {{-- Upload your own image — routed to the current mode (landfall / gallery / background). --}}
                    <label class="btn btn-sm border-0 bg-amber-500 text-slate-950 hover:bg-amber-400 inline-flex cursor-pointer items-center gap-1.5">
                        <span wire:loading.remove wire:target="uploadImage" class="inline-flex items-center gap-1.5">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                            {{ __('Upload image') }}
                        </span>
                        <span wire:loading wire:target="uploadImage" class="inline-flex items-center gap-1.5">
                            <x-icons.spinner class="h-3 w-3 animate-spin" /> {{ __('Uploading…') }}
                        </span>
                        <input type="file" wire:model="uploadImage" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" />
                    </label>
                </div>
                @error('uploadImage')
                    <p class="mt-1 text-[11px] text-rose-300">{{ $message }}</p>
                @enderror
                <p class="mt-3 text-[11px] text-slate-500">
                    {{-- Not "public-domain works": the license filter admits CC BY and CC BY-SA too
                         (it only rejects NC/ND), so the grid genuinely serves openly licensed
                         photographs alongside public-domain art. Saying "public domain" told
                         teachers something false about what they were putting in front of a class. --}}
                    {{ __('Public-domain and openly licensed works from Wikimedia Commons, saved to this lesson and credited automatically. Or upload your own (JPG, PNG, WebP; max 8 MB).') }}
                </p>
                @endif
                </div>
            @endif
        </div>
        <button type="button" class="modal-backdrop" aria-label="Close"
                wire:click="$set('paintingPickerOpen', false)"></button>
    </div>

    {{-- Clipart library — teacher-imported public-domain SVGs, drawn line-by-line.
         The nested Livewire component only mounts while the modal is open. --}}
    <div class="modal modal-bottom sm:modal-middle {{ $svgLibraryOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-4xl border border-slate-700/70 bg-base-300">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-slate-100">{{ __('Clipart library') }}</h2>
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

    {{-- Scenes payload as inert JSON so we don't string-interpolate it into JS. Built in PHP
         (scenesData(), batched asset titles) and wire:ignore'd — the preview bridge reads it once on
         first paint (live edits flow through scene:load), so the 3s status poll must not re-morph
         this large blob every tick. --}}
    <script type="application/json" id="step3-scenes-data" wire:ignore>
        {!! json_encode($this->scenesData()) !!}
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
                // The Format panel is FIXED (docked right, below the header) — the old floating
                // drag/dock machinery is gone; the toolbar "Format" button toggles visibility.
                inspectorOpen: true,
                docked: true,

                async init() {
                    this.inspectorOpen = (localStorage.getItem('wizard.inspector') ?? '1') === '1';
                    this.$watch('inspectorOpen', v => {
                        localStorage.setItem('wizard.inspector', v ? '1' : '0');
                    });

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

                // App nav height (h-16 = 64px) — the fixed panel sits flush under it, no gap.
                _headerOffset: 64,

                inspectorBodyStyle() {
                    // viewport − header − card title bar
                    return `max-height:${window.innerHeight - this._headerOffset - 44}px;`;
                },

                toggleInspector() {
                    this.inspectorOpen = !this.inspectorOpen;
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
