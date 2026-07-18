@props(['scene' => null])

{{-- Script editing view (Figma "Script panel"): the scene's narration as timecoded lines
     synced to a REAL WaveSurfer play bar. Docked below the stage — it reserves --work-bottom
     so the canvas shrinks above it and it never overlays the scene. Drag the header down to
     hide it (View ▸ Script re-opens). No close button, no cogwheel. --}}
@php
    $segments = $scene?->scriptSegments() ?? [];
    $audioUrl = $scene && $scene->audio_path ? asset('storage/'.$scene->audio_path) : null;
    $fractions = array_map(fn ($s) => $s['fraction'], $segments);
@endphp

<div x-show="$store.view.script" x-cloak
     x-data="scriptEditor(@js($audioUrl), @js($fractions))"
     class="fixed bottom-0 z-30 flex flex-col overflow-hidden border-t border-slate-700/70 bg-base-300"
     style="left: var(--rail-w, 11rem); right: var(--work-right, 16rem);"
     :style="dragging
        ? `left:var(--rail-w,11rem);right:var(--work-right,16rem);transform:translateY(${dragY}px);transition:none;opacity:${Math.max(0.4, 1 - dragY / 240)}`
        : `left:var(--rail-w,11rem);right:var(--work-right,16rem);transform:translateY(0);transition:transform .18s ease,opacity .18s ease`">

    {{-- Header = drag handle. Drag it down past the threshold to hide the panel. --}}
    <div class="relative flex cursor-grab touch-none select-none items-center justify-center border-b border-slate-700/60 bg-base-200/60 px-3 py-1.5"
         x-on:pointerdown="dragStart($event)"
         x-on:pointermove.window="dragMove($event)"
         x-on:pointerup.window="dragEnd($event)"
         x-on:pointercancel.window="dragEnd($event)"
         role="button" aria-label="{{ __('Drag down to hide the script') }}">
        <span class="h-1 w-9 rounded-full bg-slate-600" aria-hidden="true"></span>
    </div>

    @if ($segments === [])
        <p class="px-4 py-6 text-center text-xs text-slate-500">{{ __('No narration yet for this scene.') }}</p>
    @else
        {{-- Timecoded narration --}}
        <div class="overflow-y-auto px-3 py-3" style="max-height: 30vh;">
            <div class="space-y-3">
                @foreach ($segments as $i => $seg)
                    <div class="flex gap-3">
                        <button type="button" x-on:click="seek({{ $i }})"
                                class="mt-0.5 w-9 shrink-0 text-right font-mono text-[10px] tabular-nums text-slate-500 transition hover:text-amber-300"
                                x-text="fmt(starts[{{ $i }}] ?? {{ $seg['start'] }})">{{ $seg['timecode'] }}</button>
                        <p x-on:click="seek({{ $i }})"
                           :class="active === {{ $i }} ? 'text-amber-100' : 'text-slate-300 hover:text-slate-100'"
                           class="cursor-pointer font-serif text-[15px] leading-relaxed transition">{{ $seg['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Play bar — the real WaveSurfer waveform. --}}
        <div class="flex items-center gap-3 border-t border-slate-700/60 bg-base-200/60 px-3 py-2">
            <button type="button" x-on:click="toggle()" :disabled="!ready"
                    class="btn btn-circle btn-sm btn-warning shrink-0 disabled:opacity-40"
                    aria-label="{{ __('Play narration') }}">
                <svg x-show="!playing" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z"/></svg>
                <svg x-show="playing" x-cloak viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M6.75 5.25h3v13.5h-3zM14.25 5.25h3v13.5h-3z"/></svg>
            </button>
            {{-- wire:ignore so Livewire morphs never wipe WaveSurfer's rendered canvas. --}}
            <div x-ref="waveform" wire:ignore class="h-9 min-w-0 flex-1 cursor-pointer"></div>
            <span class="shrink-0 font-mono text-[10px] tabular-nums text-slate-400" x-text="fmt(t) + ' / ' + fmt(dur)"></span>
        </div>
    @endif
</div>

@once
    @push('scripts')
    <script>
        const SCRIPT_HIDE_THRESHOLD_PX = 80;
        window.scriptEditor = window.scriptEditor || function (url, fractions) {
            return {
                ws: null,
                t: 0,
                dur: 0,
                playing: false,
                ready: false,
                active: 0,
                fractions: Array.isArray(fractions) ? fractions : [],
                starts: [],
                dragging: false,
                dragY: 0,
                _startY: 0,
                _ro: null,
                _waveRo: null,

                async init() {
                    this.starts = this.fractions.map(() => 0);
                    this.$nextTick(() => this.reserveSpace());
                    // Keep the reserved bottom space in sync with the panel's real height + its
                    // shown/hidden state, so the stage always sits ABOVE the script panel.
                    this._ro = new ResizeObserver(() => this.reserveSpace());
                    this._ro.observe(this.$el);
                    this.$watch('$store.view.script', () => this.$nextTick(() => this.reserveSpace()));

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
                    document.getElementById('lesson-canvas-root')?.style.setProperty('--work-bottom', '0px');
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

                dragStart(e) {
                    this.dragging = true;
                    this._startY = e.clientY;
                    try { e.target.setPointerCapture?.(e.pointerId); } catch (_) {}
                },
                dragMove(e) {
                    if (!this.dragging) return;
                    this.dragY = Math.max(0, e.clientY - this._startY);
                },
                dragEnd() {
                    if (!this.dragging) return;
                    if (this.dragY > SCRIPT_HIDE_THRESHOLD_PX) this.$store.view.hide('script');
                    this.dragging = false;
                    this.dragY = 0;
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
