@props(['scene' => null])

{{-- Script editing view (Figma "Script panel"): the scene's narration as timecoded lines
     synced to a REAL WaveSurfer play bar. Docked below the stage — it reserves --work-bottom
     so the canvas shrinks above it and it never overlays the scene. Drag the TOP EDGE to
     resize it (it can go compact for small screens); drag it very small to hide (View ▸ Script
     re-opens). No close button, no cogwheel. --}}
@php
    $segments = $scene?->scriptSegments() ?? [];
    $audioUrl = $scene && $scene->audio_path ? asset('storage/'.$scene->audio_path) : null;
    $fractions = array_map(fn ($s) => $s['fraction'], $segments);
@endphp

<div x-show="$store.view.script" x-cloak
     x-data="scriptEditor(@js($audioUrl), @js($fractions), {{ $scene?->id ?? 'null' }})"
     class="fixed bottom-0 z-30 flex flex-col overflow-hidden border-t border-slate-700/70 bg-base-300"
     :style="`left:var(--rail-w,11rem);right:var(--work-right,16rem);height:${panelH}px`">

    {{-- Top edge = resize handle. Drag up/down to resize; drag it small to hide. --}}
    <div class="relative flex shrink-0 cursor-ns-resize touch-none select-none items-center justify-center border-b border-slate-700/60 bg-base-200/60 py-1.5"
         x-on:pointerdown="dragStart($event)"
         x-on:pointermove.window="dragMove($event)"
         x-on:pointerup.window="dragEnd($event)"
         x-on:pointercancel.window="dragEnd($event)"
         role="separator" aria-orientation="horizontal"
         aria-label="{{ __('Resize the script panel · drag small to hide') }}">
        <span class="h-1 w-9 rounded-full bg-slate-600" aria-hidden="true"></span>
    </div>

    @if ($segments === [])
        <p class="px-4 py-6 text-center text-xs text-slate-500">{{ __('No narration yet for this scene.') }}</p>
    @else
        {{-- Timecoded narration. focusout (bubbles) saves whenever any edited line loses focus.
             wire:ignore so the 3s wire:poll morph can't reset the contenteditable text mid-edit
             (Alpine timecodes/active state still update; a scene change re-renders via wire:key). --}}
        <div wire:ignore class="min-h-0 flex-1 overflow-y-auto px-3 py-2.5" x-on:focusout="saveScript()">
            <div class="space-y-3">
                @foreach ($segments as $i => $seg)
                    <div class="flex gap-3">
                        {{-- Timecode seeks; the text line is editable in place. --}}
                        <button type="button" x-on:click="seek({{ $i }})"
                                class="mt-0.5 w-9 shrink-0 cursor-pointer text-right font-mono text-[10px] tabular-nums text-slate-500 transition hover:text-amber-300"
                                x-text="fmt(starts[{{ $i }}] ?? {{ $seg['start'] }})">{{ $seg['timecode'] }}</button>
                        {{-- Inline-editable narration (no textarea): edit the words, blur saves.
                             Empty a line to delete that sentence. Re-narrate to refresh the audio. --}}
                        <p data-line contenteditable="true" spellcheck="false"
                           x-on:input="markDirty({{ $i }})"
                           x-on:keydown.escape.stop.prevent="$event.target.blur()"
                           :class="{
                               'text-amber-100': active === {{ $i }},
                               'text-slate-300': active !== {{ $i }},
                               'border-l-2 border-amber-500/60 pl-2': dirtyLines.includes({{ $i }}),
                           }"
                           class="flex-1 cursor-text rounded font-serif text-[15px] leading-relaxed transition hover:bg-white/5 focus:bg-white/10 focus:outline-none focus:ring-1 focus:ring-amber-500/40">{{ $seg['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Play bar — the real WaveSurfer waveform, compact. When the text was edited the
             audio is stale: the waveform greys out and Play re-narrates before playing. --}}
        <div class="flex shrink-0 items-center gap-2.5 border-t border-slate-700/60 bg-base-200/60 px-3 py-1.5">
            <button type="button" x-on:click="onPlay()" :disabled="!ready && !dirty"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-500 text-slate-950 transition hover:bg-amber-400 disabled:opacity-40"
                    :title="dirty ? '{{ __('Re-narrate the edited audio') }}' : (playing ? '{{ __('Pause') }}' : '{{ __('Play') }}')"
                    aria-label="{{ __('Play narration') }}">
                <svg x-show="regenerating" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg>
                <svg x-show="!regenerating && !playing" viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5"><path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z"/></svg>
                <svg x-show="!regenerating && playing" x-cloak viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5"><path d="M6.75 5.25h3v13.5h-3zM14.25 5.25h3v13.5h-3z"/></svg>
            </button>
            {{-- wire:ignore so Livewire morphs never wipe WaveSurfer's rendered canvas. --}}
            <div x-ref="waveform" wire:ignore class="h-7 min-w-0 flex-1 cursor-pointer transition-opacity"
                 :class="dirty && 'pointer-events-none opacity-40'"></div>
            <span class="shrink-0 font-mono text-[10px] tabular-nums text-slate-400"
                  x-text="regenerating ? '{{ __('updating…') }}' : (fmt(t) + ' / ' + fmt(dur))"></span>
        </div>
    @endif
</div>

@once
    @push('scripts')
    <script>
        const SCRIPT_MIN_H = 40;    // px — drag below this on release → hide
        const SCRIPT_DEF_H = 240;   // px — default / reopened height
        window.scriptEditor = window.scriptEditor || function (url, fractions, sceneId) {
            return {
                ws: null,
                sceneId: sceneId ?? null,
                t: 0,
                dur: 0,
                playing: false,
                ready: false,
                active: 0,
                fractions: Array.isArray(fractions) ? fractions : [],
                starts: [],
                // Resize (drag the top edge). panelH is the panel's live height.
                panelH: SCRIPT_DEF_H,
                dragging: false,
                _startY: 0,
                _startH: 0,
                // Edited-since-narration state: the audio is stale until re-narrated on Play.
                dirty: false,
                dirtyLines: [],
                regenerating: false,
                _ro: null,
                _waveRo: null,
                _lastSaved: '',
                _unwatchAudio: null,

                async init() {
                    this.starts = this.fractions.map(() => 0);
                    this.$nextTick(() => { this.reserveSpace(); this._lastSaved = this._currentScriptText(); });
                    // Keep the reserved bottom space in sync with the panel's real height + its
                    // shown/hidden state, so the stage always sits ABOVE the script panel.
                    this._ro = new ResizeObserver(() => this.reserveSpace());
                    this._ro.observe(this.$el);
                    this.$watch('$store.view.script', () => this.$nextTick(() => this.reserveSpace()));
                    // A re-narrate flips the scene status; the wizard's status poll re-fires
                    // scene:load with the fresh audio URL. Reload the waveform only when WE
                    // asked for it (regenerating) and it's this scene.
                    this._unwatchAudio = window.Livewire.on('scene:load', (e) => {
                        const p = Array.isArray(e) ? e[0]?.payload : e?.payload;
                        if (p && this.regenerating && p.sceneId === this.sceneId && p.audioUrl) {
                            this.reloadAudio(p.audioUrl);
                        }
                    });

                    if (!url) return;
                    try {
                        const WS = await window.ensureWaveSurfer();
                        this.ws = WS.create({
                            container: this.$refs.waveform,
                            url,
                            waveColor: '#475569',      // slate-600
                            progressColor: '#f59e0b',  // amber-500
                            cursorColor: '#fbbf24',    // amber-400 playhead
                            barWidth: 2, barGap: 1, barRadius: 2, height: 32, normalize: true,
                        });
                        this.ws.on('ready', (d) => {
                            this.dur = d || 0;
                            this.ready = true;
                            this.starts = this.fractions.map((f) => f * this.dur);   // spread over REAL length
                            this.refreshWave();
                        });
                        this.ws.on('audioprocess', (t) => { this.t = t; this.syncActive(); });
                        this.ws.on('interaction', () => { this.t = this.ws.getCurrentTime(); this.syncActive(); });
                        this.ws.on('play', () => { this.playing = true; });
                        this.ws.on('pause', () => { this.playing = false; });
                        this.ws.on('finish', () => { this.playing = false; });

                        // WaveSurfer draws nothing if it initialised while the panel was hidden
                        // (script off) or 0-width. Redraw whenever the container gets a real width.
                        this._waveRo = new ResizeObserver(() => this.refreshWave());
                        this._waveRo.observe(this.$refs.waveform);
                    } catch (_) { /* WaveSurfer failed to load — leave the play bar disabled */ }
                },

                // Inline script editing — gather the editable lines and persist on blur.
                _currentScriptText() {
                    return [...this.$el.querySelectorAll('[data-line]')]
                        .map((el) => el.textContent.replace(/\s+/g, ' ').trim())
                        .filter(Boolean)
                        .join(' ');
                },
                saveScript() {
                    const text = this._currentScriptText();
                    if (text === this._lastSaved) return;   // no change → no round-trip
                    this._lastSaved = text;
                    try { window.Livewire.dispatch('scene:update-script', { text }); } catch (_) {}
                },

                refreshWave() {
                    if (!this.ws || !this.$refs.waveform) return;
                    const width = this.$refs.waveform.clientWidth;
                    if (width > 0) { try { this.ws.setOptions({ width }); } catch (_) {} }
                    else requestAnimationFrame(() => this.refreshWave());
                },
                destroy() {
                    try { this.ws?.destroy(); } catch (_) {}
                    if (this._ro) this._ro.disconnect();
                    if (this._waveRo) this._waveRo.disconnect();
                    if (this._unwatchAudio) { try { this._unwatchAudio(); } catch (_) {} }
                    document.getElementById('lesson-canvas-root')?.style.setProperty('--work-bottom', '0px');
                },

                // ── Edited-text → stale audio ────────────────────────────────────────────
                markDirty(i) {
                    this.dirty = true;
                    if (!this.dirtyLines.includes(i)) this.dirtyLines.push(i);
                },
                onPlay() {
                    if (this.dirty) { this.renarrate(); return; }   // stale → re-narrate before playing
                    this.toggle();
                },
                renarrate() {
                    if (this.regenerating) return;
                    this.saveScript();               // persist the edited text first
                    if (this.sceneId == null) { this.dirty = false; this.dirtyLines = []; return; }
                    this.regenerating = true;
                    try { window.Livewire.dispatch('scene:renarrate', { sceneId: this.sceneId }); } catch (_) {}
                },
                reloadAudio(newUrl) {
                    this.regenerating = false;
                    this.dirty = false;
                    this.dirtyLines = [];
                    if (!this.ws || !newUrl) return;
                    // Bust the HTTP cache — the re-narrated file often reuses the same path.
                    const bust = newUrl + (newUrl.includes('?') ? '&' : '?') + 't=' + Date.now();
                    try { this.ws.load(bust); } catch (_) {}
                },

                reserveSpace() {
                    const root = document.getElementById('lesson-canvas-root');
                    if (!root) return;
                    const shown = !!this.$store.view.script;
                    const h = shown ? Math.round(this.$el.getBoundingClientRect().height) : 0;
                    // Only write + nudge when the value actually changes, so the ResizeObserver
                    // can't churn a resize-dispatch loop.
                    if (root.style.getPropertyValue('--work-bottom') === h + 'px') return;
                    root.style.setProperty('--work-bottom', h + 'px');
                    window.dispatchEvent(new Event('resize'));   // refit the WebGL stage
                },

                toggle() { this.ws?.playPause(); },
                seek(i) {
                    if (!this.ws) return;
                    const s = this.starts[i] ?? 0;
                    if (typeof this.ws.setTime === 'function') this.ws.setTime(s);
                    else this.ws.seekTo(this.dur ? s / this.dur : 0);
                    this.t = s;
                    this.syncActive();
                    if (!this.playing) this.ws.play();
                },
                syncActive() {
                    let a = 0;
                    for (let i = 0; i < this.starts.length; i++) { if (this.t >= this.starts[i]) a = i; }
                    this.active = a;
                },

                // Drag the TOP edge to resize. Up = taller, down = shorter. On release, if the
                // panel was dragged very small, hide it (View ▸ Script re-opens at the default).
                dragStart(e) {
                    this.dragging = true;
                    this._startY = e.clientY;
                    this._startH = this.panelH;
                    try { e.target.setPointerCapture?.(e.pointerId); } catch (_) {}
                },
                dragMove(e) {
                    if (!this.dragging) return;
                    const maxH = Math.round(window.innerHeight * 0.6);
                    // clientY drops as you drag up → grow; rises as you drag down → shrink.
                    this.panelH = Math.min(maxH, Math.max(SCRIPT_MIN_H, this._startH + (this._startY - e.clientY)));
                    this.refreshWave();
                },
                dragEnd() {
                    if (!this.dragging) return;
                    this.dragging = false;
                    if (this.panelH <= SCRIPT_MIN_H + 24) {   // dragged down to the floor → hide
                        this.$store.view.hide('script');
                        this.panelH = SCRIPT_DEF_H;            // reopen at a sensible height
                    }
                    this.$nextTick(() => { this.reserveSpace(); this.refreshWave(); });
                },
                fmt(s) {
                    s = Math.max(0, Math.round(s || 0));
                    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
                },
            };
        };
    </script>
    @endpush
@endonce
