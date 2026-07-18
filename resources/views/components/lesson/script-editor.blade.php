@props(['scene' => null])

{{-- Script editing view (Figma "Script panel"): the scene's narration as timecoded lines
     synced to a REAL WaveSurfer play bar. Docked below the stage — it reserves --work-bottom
     so the canvas shrinks above it and it never overlays the scene. Drag the TOP EDGE to
     resize it (it can go compact for small screens); drag it very small to hide (View ▸ Script
     re-opens). No close button, no cogwheel. --}}
@php
    // One editable box per PARAGRAPH (blank-line separated). Single Enter = soft newline within a
    // paragraph; double Enter splits into a new paragraph (its own timecode + TTS topic).
    $paragraphs = $scene?->scriptParagraphs() ?? [];
    $audioUrl = $scene && $scene->audio_path ? asset('storage/'.$scene->audio_path) : null;
@endphp

{{-- {{ $attributes }} carries the wire:key="script-{sceneId}" from the parent so a scene change
     is a full teardown+rebuild (fresh lines + fresh WaveSurfer), not an in-place morph — otherwise
     the wire:ignore'd lines below would freeze the previous scene's script. --}}
<div x-show="$store.view.script" x-cloak
     {{ $attributes }}
     x-data="scriptEditor(@js($audioUrl), @js($paragraphs), {{ $scene?->id ?? 'null' }})"
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

    @if ($paragraphs === [])
        <p class="px-4 py-6 text-center text-xs text-slate-500">{{ __('No narration yet for this scene.') }}</p>
    @else
        {{-- Per-paragraph editable boxes, Alpine-managed (paras[]). wire:ignore so the 3s wire:poll
             morph can't reset the contenteditable text mid-edit; Alpine still updates timecodes /
             active state, and a scene change re-renders the whole component via wire:key.
             focusout saves; if focus left the panel entirely it also closes the script toolbar. --}}
        <div wire:ignore class="min-h-0 flex-1 overflow-y-auto px-3 py-2.5" x-on:focusout="onFocusOut($event)">
            <div class="space-y-3">
                <template x-for="(p, i) in paras" :key="p.id">
                    <div class="flex gap-3">
                        {{-- Timecode seeks; the paragraph is editable in place. --}}
                        <button type="button" x-on:click="seek(i)"
                                class="mt-0.5 w-9 shrink-0 cursor-pointer text-right font-mono text-[10px] tabular-nums text-slate-500 transition hover:text-amber-300"
                                x-text="fmt(starts[i] ?? 0)"></button>
                        {{-- contenteditable paragraph. white-space:pre-wrap keeps soft newlines (single
                             Enter). Double Enter splits into a new box; Backspace at start merges up.
                             x-init seeds the text once (Alpine won't clobber it on later renders). --}}
                        <p data-line contenteditable="true" spellcheck="false"
                           x-init="$el.textContent = p.text"
                           x-on:input="onInput(i)"
                           x-on:keydown="onKeydown($event, i)"
                           x-on:paste="onPaste($event, i)"
                           x-on:focus="active = i; focusedPara = i"
                           x-on:keydown.escape.stop.prevent="$event.target.blur()"
                           :class="{
                               'text-amber-100': active === i,
                               'text-slate-300': active !== i,
                               'border-l-2 border-amber-500/60 pl-2': p.dirty,
                           }"
                           class="min-w-0 flex-1 cursor-text whitespace-pre-wrap rounded font-serif text-[15px] leading-relaxed transition hover:bg-white/5 focus:bg-white/10 focus:outline-none focus:ring-1 focus:ring-amber-500/40"></p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Script editing toolbar — shown while a paragraph is focused. Regenerate rewrites the
             focused paragraph from a short prompt; Summarize to list drops an on-slide bullet card.
             mousedown.prevent keeps the paragraph's focus (and focusedPara) alive through the click. --}}
        <div x-show="focusedPara !== null" x-cloak
             class="flex shrink-0 flex-wrap items-center gap-2 border-t border-slate-700/60 bg-base-200/40 px-3 py-1.5"
             x-on:mousedown.prevent>
            <span class="text-[10px] font-semibold uppercase tracking-widest text-slate-500">{{ __('Paragraph') }}</span>
            {{-- Regenerate with a prompt (inline expanding input). --}}
            <div class="flex items-center gap-1" x-show="!promptOpen">
                <button type="button" x-on:click="openPrompt()"
                        class="flex items-center gap-1 rounded-md border border-slate-600/70 px-2 py-1 text-[11px] text-slate-200 transition hover:border-amber-400 hover:text-amber-200 disabled:opacity-40"
                        :disabled="regenPara"
                        title="{{ __('Rewrite this paragraph from a prompt') }}">
                    <svg x-show="!regenPara" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    <svg x-show="regenPara" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg>
                    <span x-text="regenPara ? '{{ __('rewriting…') }}' : '{{ __('Regenerate') }}'"></span>
                </button>
            </div>
            <div class="flex min-w-0 flex-1 items-center gap-1" x-show="promptOpen" x-cloak>
                <input type="text" x-ref="prompt" x-model="promptText"
                       x-on:keydown.enter.stop.prevent="submitPrompt()"
                       x-on:keydown.escape.stop.prevent="closePrompt()"
                       placeholder="{{ __('e.g. make it shorter and more dramatic') }}"
                       class="min-w-0 flex-1 rounded-md border border-slate-600/70 bg-base-300 px-2 py-1 text-[12px] text-slate-100 placeholder:text-slate-500 focus:border-amber-400 focus:outline-none" />
                <button type="button" x-on:click="submitPrompt()" :disabled="regenPara"
                        class="rounded-md bg-amber-500 px-2 py-1 text-[11px] font-semibold text-slate-950 transition hover:bg-amber-400 disabled:opacity-40">{{ __('Rewrite') }}</button>
                <button type="button" x-on:click="closePrompt()"
                        class="rounded-md px-1.5 py-1 text-[11px] text-slate-400 hover:text-slate-200">✕</button>
            </div>
            {{-- Summarize the whole scene to an on-slide bullet list. --}}
            <button type="button" x-on:click="summarizeToList()" :disabled="summarizing"
                    class="flex items-center gap-1 rounded-md border border-slate-600/70 px-2 py-1 text-[11px] text-slate-200 transition hover:border-amber-400 hover:text-amber-200 disabled:opacity-40"
                    title="{{ __('Summarize the narration into a bullet list on the slide') }}">
                <svg x-show="!summarizing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                <svg x-show="summarizing" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg>
                <span x-text="summarizing ? '{{ __('summarizing…') }}' : '{{ __('Summarize to list') }}'"></span>
            </button>
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
        const SCRIPT_H_KEY = 'wizard.script.h';   // persist the resized height across scene changes
        window.scriptEditor = window.scriptEditor || function (url, paragraphs, sceneId) {
            return {
                ws: null,
                sceneId: sceneId ?? null,
                t: 0,
                dur: 0,
                playing: false,
                ready: false,
                active: 0,
                // One entry per PARAGRAPH: { id, text, dirty }. Seeded from the server; edits
                // (type / split / merge / paste) mutate this array and the DOM boxes track it.
                paras: [],
                starts: [],
                _pid: 0,
                _seed: Array.isArray(paragraphs) ? paragraphs : [],
                // Script-editing toolbar (shown while a paragraph is focused).
                focusedPara: null,
                promptOpen: false,
                promptText: '',
                regenPara: false,
                summarizing: false,
                // Resize (drag the top edge). panelH is the panel's live height; seeded from the
                // last resized height so switching scenes (which rebuilds this component via
                // wire:key) doesn't snap the panel back to the default. Re-clamped to the current
                // viewport so a height saved on a tall window can't exceed a shorter one (which
                // would push the resize grip off-screen and collapse the stage).
                panelH: (() => {
                    const h = parseFloat(localStorage.getItem(SCRIPT_H_KEY));
                    const max = Math.max(120, Math.round((window.innerHeight || 800) * 0.6));
                    return h > 0 ? Math.min(h, max) : SCRIPT_DEF_H;
                })(),
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
                    this.paras = this._seed.map((p) => ({ id: this._pid++, text: p.text || '', dirty: false }));
                    this.starts = this._seed.map((p) => p.start || 0);   // server timecodes until audio loads
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
                    // Regenerate-paragraph result: the server rewrites one paragraph and hands it back.
                    this._unwatchPara = window.Livewire.on('scene:paragraph-result', (e) => {
                        const p = Array.isArray(e) ? e[0] : e;
                        if (p && p.sceneId === this.sceneId && this.regenPara) this.applyParagraph(p.text);
                    });
                    // Summarize-to-list finished (or failed) — drop the spinner.
                    this._unwatchSummary = window.Livewire.on('scene:summarize-done', (e) => {
                        const p = Array.isArray(e) ? e[0] : e;
                        if (p && p.sceneId === this.sceneId) this.summarizing = false;
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
                            this.recomputeStarts();   // spread paragraph timecodes over the REAL length
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

                // Pull each box's live text back into paras, then serialise: soft newlines (single
                // \n) stay inside a paragraph; paragraphs join with \n\n so the model — and TTS —
                // treat each as its own topic.
                _boxes() { return [...this.$el.querySelectorAll('[data-line]')]; },
                _syncFromDom() {
                    this._boxes().forEach((el, i) => { if (this.paras[i]) this.paras[i].text = el.textContent; });
                },
                _currentScriptText() {
                    this._syncFromDom();
                    return this.paras
                        .map((p) => (p.text || '').replace(/[ \t]+$/gm, '').replace(/\n{3,}/g, '\n\n').trim())
                        .filter(Boolean)
                        .join('\n\n');
                },
                saveScript() {
                    const text = this._currentScriptText();
                    // Never persist an empty script: a focusout can fire mid-teardown (scene switch
                    // rebuilds this component) when the boxes are already gone → text would be ''
                    // and wipe the scene's narration. Deleting all narration isn't an inline edit.
                    if (!text) return;
                    if (text === this._lastSaved) return;   // no change → no round-trip
                    this._lastSaved = text;
                    // sceneId lets the server reject a stale save aimed at a scene we already left.
                    try { window.Livewire.dispatch('scene:update-script', { text, sceneId: this.sceneId }); } catch (_) {}
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
                    if (this._unwatchPara) { try { this._unwatchPara(); } catch (_) {} }
                    if (this._unwatchSummary) { try { this._unwatchSummary(); } catch (_) {} }
                    document.getElementById('lesson-canvas-root')?.style.setProperty('--work-bottom', '0px');
                },

                // ── Paragraph editing (single Enter = soft newline, double Enter = new paragraph) ──
                recomputeStarts() {
                    const total = Math.max(1, this.paras.reduce((n, p) => n + (p.text || '').length + 2, 0));
                    let at = 0;
                    this.starts = this.paras.map((p) => {
                        const s = (at / total) * (this.dur || 0);
                        at += (p.text || '').length + 2;   // + the "\n\n" join
                        return s;
                    });
                },
                onInput(i) {
                    const el = this._boxes()[i];
                    if (el && this.paras[i]) this.paras[i].text = el.textContent;
                    this.markDirty(i);
                },
                onKeydown(e, i) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const el = e.target;
                        if (e.shiftKey) { this._insertText('\n'); this.onInput(i); return; }
                        // Enter right after a soft newline (or on a blank trailing line) → split.
                        if (this._textBeforeCaret(el).endsWith('\n')) { this.splitPara(i, el); return; }
                        this._insertText('\n'); this.onInput(i);   // single Enter → soft newline
                        return;
                    }
                    if (e.key === 'Backspace' && i > 0 && !this._hasSelection() && this._caretOffset(e.target) === 0) {
                        e.preventDefault();
                        this.mergeUp(i, e.target);
                    }
                },
                splitPara(i, el) {
                    const caret = this._caretOffset(el);
                    const text = el.textContent;
                    const before = text.slice(0, caret).replace(/\n+$/, '');   // drop the soft newline that triggered the split
                    const after = text.slice(caret);
                    this.paras[i].text = before;
                    this.paras[i].dirty = true;
                    el.textContent = before;                                    // x-init won't re-run, so sync the DOM
                    this.paras.splice(i + 1, 0, { id: this._pid++, text: after, dirty: true });
                    this.dirty = true;
                    this.recomputeStarts();
                    this.$nextTick(() => this._focusPara(i + 1, 0));
                },
                mergeUp(i, el) {
                    const prev = this.paras[i - 1];
                    const joinAt = (prev.text || '').length;
                    const cur = el.textContent;
                    prev.text = (prev.text || '') + (cur ? ' ' + cur : '');
                    prev.dirty = true;
                    this.dirty = true;
                    this.paras.splice(i, 1);
                    this.recomputeStarts();
                    this.$nextTick(() => {
                        const prevEl = this._boxes()[i - 1];
                        if (prevEl) { prevEl.textContent = prev.text; this._focusPara(i - 1, joinAt); }
                    });
                },
                onPaste(e, i) {
                    e.preventDefault();
                    const cd = e.clipboardData;
                    const html = cd && cd.getData('text/html');
                    const chunks = html ? this._htmlToParagraphs(html) : this._plainToParagraphs((cd && cd.getData('text/plain')) || '');
                    if (chunks.length === 0) return;
                    this._pasteParagraphs(i, e.target, chunks);
                },
                // Sanitise pasted HTML to plain paragraphs: strip ALL tags/attributes (no css/id/
                // aria/font). Block elements (p, h1-6, div, li, …) become paragraph breaks; <br>
                // becomes a soft newline. Only structure survives — never styling.
                _htmlToParagraphs(html) {
                    let doc;
                    try { doc = new DOMParser().parseFromString(html, 'text/html'); }
                    catch (_) { return this._plainToParagraphs(html); }
                    const BLOCK = new Set(['P','DIV','H1','H2','H3','H4','H5','H6','LI','UL','OL','BLOCKQUOTE','SECTION','ARTICLE','HEADER','FOOTER','TABLE','TR','PRE','HR','FIGURE','FIGCAPTION']);
                    const walk = (node) => {
                        let out = '';
                        node.childNodes.forEach((child) => {
                            if (child.nodeType === 3) { out += child.textContent.replace(/\s+/g, ' '); }
                            else if (child.nodeType === 1) {
                                const tag = child.tagName;
                                if (tag === 'BR') out += '\n';
                                else if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'HEAD') { /* drop */ }
                                else if (BLOCK.has(tag)) out += '\n\n' + walk(child) + '\n\n';
                                else out += walk(child);   // inline (span, b, i, a, strong, em…) → unwrap
                            }
                        });
                        return out;
                    };
                    return this._normalizeParas(walk(doc.body));
                },
                _plainToParagraphs(text) { return this._normalizeParas((text || '').replace(/\r\n?/g, '\n')); },
                _normalizeParas(raw) {
                    return raw
                        .replace(/ /g, ' ')
                        .replace(/[ \t]+/g, ' ')
                        .replace(/ *\n */g, '\n')
                        .replace(/\n{3,}/g, '\n\n')
                        .split(/\n[ \t]*\n+/)
                        .map((p) => p.split('\n').map((l) => l.trim()).filter(Boolean).join('\n').trim())
                        .filter(Boolean);
                },
                _pasteParagraphs(i, el, chunks) {
                    const caret = this._caretOffset(el);
                    const text = el.textContent;
                    const before = text.slice(0, caret);
                    const after = text.slice(caret);
                    if (chunks.length === 1) { this._insertText(chunks[0]); this.onInput(i); return; }
                    // First chunk joins the text before the caret; last joins the text after it; the
                    // middle chunks (and the last) become fresh paragraph boxes.
                    const first = before + chunks[0];
                    const tail = chunks.slice(1);
                    tail[tail.length - 1] = tail[tail.length - 1] + after;
                    this.paras[i].text = first; this.paras[i].dirty = true; el.textContent = first;
                    const inserted = tail.map((t) => ({ id: this._pid++, text: t, dirty: true }));
                    this.paras.splice(i + 1, 0, ...inserted);
                    this.dirty = true;
                    this.recomputeStarts();
                    const caretPos = chunks[chunks.length - 1].length;
                    this.$nextTick(() => this._focusPara(i + inserted.length, caretPos));
                },

                // ── caret / selection helpers (char offsets within a contenteditable box) ──
                _insertText(t) {
                    const sel = window.getSelection();
                    if (!sel || !sel.rangeCount) return;
                    const range = sel.getRangeAt(0);
                    range.deleteContents();
                    const node = document.createTextNode(t);
                    range.insertNode(node);
                    range.setStartAfter(node); range.setEndAfter(node); range.collapse(true);
                    sel.removeAllRanges(); sel.addRange(range);
                },
                _hasSelection() { const s = window.getSelection(); return !!s && !s.isCollapsed; },
                _textBeforeCaret(el) {
                    const sel = window.getSelection();
                    if (!sel || !sel.rangeCount) return '';
                    const r = sel.getRangeAt(0).cloneRange();
                    r.selectNodeContents(el);
                    r.setEnd(sel.getRangeAt(0).endContainer, sel.getRangeAt(0).endOffset);
                    return r.toString();
                },
                _caretOffset(el) { return this._textBeforeCaret(el).length; },
                _setCaret(el, offset) {
                    const sel = window.getSelection();
                    const range = document.createRange();
                    let remaining = Math.max(0, offset), target = null, targetOff = 0;
                    const walk = (n) => {
                        if (target) return;
                        if (n.nodeType === 3) {
                            const len = n.textContent.length;
                            if (remaining <= len) { target = n; targetOff = remaining; } else { remaining -= len; }
                        } else { n.childNodes.forEach(walk); }
                    };
                    walk(el);
                    if (target) range.setStart(target, targetOff);
                    else { range.selectNodeContents(el); range.collapse(false); }
                    range.collapse(true);
                    sel.removeAllRanges(); sel.addRange(range);
                },
                _focusPara(i, offset) {
                    const el = this._boxes()[i];
                    if (!el) return;
                    el.focus();
                    this._setCaret(el, offset ?? 0);
                },

                // ── Script-editing toolbar (regenerate paragraph / summarize to list) ──
                onFocusOut(e) {
                    this.saveScript();
                    // Close the toolbar only when focus left the whole script panel.
                    if (!this.$el.contains(e.relatedTarget)) { this.focusedPara = null; this.closePrompt(); }
                },
                openPrompt() { this.promptOpen = true; this.promptText = ''; this.$nextTick(() => this.$refs.prompt?.focus()); },
                closePrompt() { this.promptOpen = false; this.promptText = ''; },
                submitPrompt() {
                    const prompt = (this.promptText || '').trim();
                    const i = this.focusedPara;
                    if (this.regenPara || i == null || !this.paras[i]) return;
                    this._syncFromDom();
                    const text = (this.paras[i].text || '').trim();
                    if (!text) { this.closePrompt(); return; }
                    this.regenPara = true;
                    this._regenIndex = i;
                    try { window.Livewire.dispatch('scene:regenerate-paragraph', { sceneId: this.sceneId, text, prompt }); } catch (_) { this.regenPara = false; }
                },
                applyParagraph(newText) {
                    this.regenPara = false;
                    this.closePrompt();
                    const i = this._regenIndex;
                    if (i == null || !this.paras[i] || !newText) return;
                    this.paras[i].text = newText;
                    this.paras[i].dirty = true;
                    this.dirty = true;
                    const el = this._boxes()[i];
                    if (el) el.textContent = newText;
                    this.recomputeStarts();
                    this.saveScript();
                },
                summarizeToList() {
                    if (this.summarizing || this.sceneId == null) return;
                    this.saveScript();   // summarize the latest text
                    this.summarizing = true;
                    try { window.Livewire.dispatch('scene:summarize-to-list', { sceneId: this.sceneId }); } catch (_) { this.summarizing = false; }
                },

                // ── Edited-text → stale audio ────────────────────────────────────────────
                markDirty(i) {
                    this.dirty = true;
                    if (this.paras[i]) this.paras[i].dirty = true;
                },
                _clearDirty() { this.dirty = false; this.paras.forEach((p) => { p.dirty = false; }); },
                onPlay() {
                    if (this.dirty) { this.renarrate(); return; }   // stale → re-narrate before playing
                    this.toggle();
                },
                renarrate() {
                    if (this.regenerating) return;
                    this.saveScript();               // persist the edited text first
                    if (this.sceneId == null) { this._clearDirty(); return; }
                    this.regenerating = true;
                    try { window.Livewire.dispatch('scene:renarrate', { sceneId: this.sceneId }); } catch (_) {}
                },
                reloadAudio(newUrl) {
                    this.regenerating = false;
                    this._clearDirty();
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
                    // Persist so a scene change (which rebuilds this component) keeps the height.
                    try { localStorage.setItem(SCRIPT_H_KEY, String(Math.round(this.panelH))); } catch (_) {}
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
