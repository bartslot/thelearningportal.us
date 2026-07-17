@props(['scene' => null])

{{-- Script editing view (Figma "Script panel"): the scene's narration laid out as
     timecoded lines synced to a play bar. The active line highlights as the audio
     plays; clicking a line (or its timecode) seeks there. Toggled from View ▸ Script
     (on by default) and the scene-settings cogwheel. --}}
@php
    $segments = $scene?->scriptSegments() ?? [];
    $audioUrl = $scene && $scene->audio_path ? asset('storage/'.$scene->audio_path) : null;
    $duration = (float) ($scene?->duration_seconds ?: 0);
@endphp

<div x-show="$store.view.script" x-cloak
     x-data="scriptEditor(@js($audioUrl), {{ $duration ?: 0 }})"
     class="fixed bottom-0 z-30 overflow-hidden border-t border-slate-700/70 bg-base-300"
     style="left: var(--work-left, 12rem); right: var(--work-right, 16rem);"
     :style="dragging
        ? `left:var(--work-left,12rem);right:var(--work-right,16rem);transform:translateY(${dragY}px);transition:none;opacity:${Math.max(0.35, 1 - dragY / 240)}`
        : `left:var(--work-left,12rem);right:var(--work-right,16rem);transform:translateY(0);transition:transform .18s ease,opacity .18s ease`">

    {{-- Header doubles as a drag handle: drag it down past the threshold to hide the panel. --}}
    <div class="relative flex cursor-grab touch-none select-none items-center justify-center border-b border-slate-700/60 bg-base-200/60 px-3 py-1.5"
         x-on:pointerdown="dragStart($event)"
         x-on:pointermove.window="dragMove($event)"
         x-on:pointerup.window="dragEnd($event)"
         x-on:pointercancel.window="dragEnd($event)"
         role="button" aria-label="{{ __('Drag down to hide the script') }}">
        <span class="pointer-events-none absolute left-3 text-[10px] font-semibold uppercase tracking-widest text-slate-400">{{ __('Script') }}</span>
        <span class="h-1 w-9 rounded-full bg-slate-600" aria-hidden="true"></span>
    </div>

    @if ($segments === [])
        <p class="px-4 py-6 text-center text-xs text-slate-500">{{ __('No narration yet for this scene.') }}</p>
    @else
        {{-- Timecoded narration --}}
        <div class="flex gap-3 overflow-y-auto px-3 py-3" style="max-height: 30vh;">
            <div class="flex-1 space-y-3">
                @foreach ($segments as $i => $seg)
                    <div class="flex gap-3">
                        <button type="button" x-on:click="seek({{ $seg['start'] }})"
                                data-start="{{ $seg['start'] }}"
                                class="mt-0.5 shrink-0 font-mono text-[10px] tabular-nums text-slate-500 transition hover:text-amber-300">
                            {{ $seg['timecode'] }}
                        </button>
                        <p x-on:click="seek({{ $seg['start'] }})"
                           :class="active === {{ $i }} ? 'text-amber-100' : 'text-slate-300 hover:text-slate-100'"
                           class="cursor-pointer font-serif text-[15px] leading-relaxed transition">{{ $seg['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Play bar with a lightweight waveform + progress --}}
        <div class="flex items-center gap-3 border-t border-slate-700/60 bg-base-200/60 px-3 py-2">
            <button type="button" x-on:click="toggle()" @disabled(! $audioUrl)
                    class="btn btn-circle btn-sm btn-warning shrink-0 disabled:opacity-40"
                    aria-label="{{ __('Play narration') }}">
                <svg x-show="!playing" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z"/></svg>
                <svg x-show="playing" x-cloak viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M6.75 5.25h3v13.5h-3zM14.25 5.25h3v13.5h-3z"/></svg>
            </button>

            {{-- Waveform track — clicking seeks; the played portion fills amber. --}}
            <div class="relative h-6 flex-1 cursor-pointer" x-on:click="seekFraction($event)">
                <div class="flex h-full items-center gap-[2px]">
                    @for ($b = 0; $b < 72; $b++)
                        <span class="w-[3px] shrink-0 rounded-full bg-slate-600"
                              style="height: {{ 24 + (int) (abs(sin($b * 1.7)) * 68) }}%"></span>
                    @endfor
                </div>
                <div class="pointer-events-none absolute inset-y-0 left-0 rounded bg-amber-400/25"
                     :style="`width:${dur ? Math.min(100, (t / dur) * 100) : 0}%`"></div>
                <div class="pointer-events-none absolute inset-y-0 w-px bg-amber-300"
                     :style="`left:${dur ? Math.min(100, (t / dur) * 100) : 0}%`"></div>
            </div>

            <span class="shrink-0 font-mono text-[10px] tabular-nums text-slate-400" x-text="fmt(t) + ' / ' + fmt(dur)"></span>
        </div>
    @endif
</div>

@once
    @push('scripts')
    <script>
        const SCRIPT_HIDE_THRESHOLD_PX = 80;
        window.scriptEditor = window.scriptEditor || function (url, duration) {
            return {
                audio: null,
                t: 0,
                dur: duration || 0,
                playing: false,
                active: 0,
                starts: [],
                // Drag-down-to-hide (replaces the ✕): the header follows the pointer, and
                // releasing past the threshold hides the panel via the shared view store.
                dragging: false,
                dragY: 0,
                _startY: 0,
                dragStart(e) {
                    this.dragging = true;
                    this._startY = e.clientY;
                    try { e.target.setPointerCapture?.(e.pointerId); } catch (_) { /* synthetic pointers throw */ }
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
                init() {
                    this.starts = [...this.$el.querySelectorAll('[data-start]')].map((e) => parseFloat(e.dataset.start));
                    if (!url) return;
                    this.audio = new Audio(url);
                    this.audio.preload = 'metadata';
                    this.audio.addEventListener('loadedmetadata', () => { if (!this.dur) this.dur = this.audio.duration || 0; });
                    this.audio.addEventListener('timeupdate', () => { this.t = this.audio.currentTime; this._syncActive(); });
                    this.audio.addEventListener('ended', () => { this.playing = false; });
                },
                destroy() { if (this.audio) { this.audio.pause(); this.audio = null; } },
                toggle() {
                    if (!this.audio) return;
                    if (this.playing) { this.audio.pause(); this.playing = false; }
                    else { this.audio.play().then(() => { this.playing = true; }).catch(() => {}); }
                },
                seek(seconds) {
                    this.t = seconds;
                    if (this.audio) { this.audio.currentTime = seconds; if (!this.playing) this.toggle(); }
                    this._syncActive();
                },
                seekFraction(ev) {
                    const rect = ev.currentTarget.getBoundingClientRect();
                    const frac = rect.width ? (ev.clientX - rect.left) / rect.width : 0;
                    this.seek(Math.max(0, Math.min(1, frac)) * (this.dur || 0));
                },
                _syncActive() {
                    let a = 0;
                    for (let i = 0; i < this.starts.length; i++) { if (this.t >= this.starts[i]) a = i; }
                    this.active = a;
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
