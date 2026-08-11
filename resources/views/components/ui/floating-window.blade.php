@props([
    /** Storage key and view-store key. `layers` persists to `wizard.window.layers`. */
    'name',
    /** Title bar text. Already translated by the caller. */
    'title',
    /** Alpine expression deciding visibility, e.g. "$store.view.layers". */
    'show',
    /** Alpine statement run by the close button, e.g. "$store.view.hide('layers')". */
    'onClose',
    /** Width in rem. */
    'width' => '18rem',
])

@php
    // A width in px for the first placement only. getBoundingClientRect() reads 0 while the window
    // is still x-cloak'd, and a 0 width would let the default position sit flush against the right
    // edge and then jump on the first drag.
    $widthPx = (float) $width * 16;
@endphp

{{-- A draggable, closable tool window that floats over the scene.

     WHY IT CLAMPS, AND WHY THAT IS THE INTERESTING PART. This screen has carried a floating panel
     before and it was pulled — see the note above the Format panel in step3-scene-configurator:
     "teachers lost it or dragged it over the canvas". Losing it is not a design opinion, it is a
     geometry bug with no error and no empty state: drag it 95% off the edge, or reopen a lesson on
     a smaller screen than the one you last used, and the panel is simply not there. So the geometry
     lives in resources/js/ui/floating-window.js where floating-window.test.js holds it, and this
     template only does the pointer handling.

     NOT A MODAL. No backdrop, nothing blocked behind it, and it must stay open while you work on
     the thing underneath — that is the entire point of a tool palette. It still closes with Esc as
     well as the X, because a panel that only closes one way is a panel someone gets stuck with.

     The window is positioned with `left`/`top` rather than a transform: a transform on the wrapper
     would establish a containing block for any `position: fixed` descendant, and popovers inside a
     panel are exactly the kind of thing that would then anchor to the wrong element. --}}
<div x-show="{{ $show }}" x-cloak
     wire:ignore
     x-data="{
         pos: { left: 0, top: 0 },
         dragging: false,
         _from: { x: 0, y: 0 },
         _origin: { left: 0, top: 0 },
         key: 'wizard.window.{{ $name }}',

         placed: false,

         init() {
             // NOT placed here. Measured in the browser pane, which reports innerWidth 0 until it
             // is resized (docs/browser-pane-measurement.md): placing at init put the window at
             // left -224, sixty-four pixels of it poking off the left edge, and it STAYED there —
             // the resize handler re-clamps, and -224 is a perfectly legal position, so nothing
             // ever moved it back. A real user hits the same thing by opening the panel while the
             // window is small, or before layout has settled.
             //
             // So the first placement waits for the window to actually be shown AND for the
             // viewport to be real. Until then there is nothing to position against.
             window.addEventListener('resize', () => this.placed && this.place(this.pos));
         },

         /** The viewport, or null while it is not yet worth measuring against. */
         viewport() {
             const view = { width: window.innerWidth, height: window.innerHeight };
             return window.__uiViewportUsable(view) ? view : null;
         },

         place(desired = null) {
             const view = this.viewport();
             if (!view) return;
             const box = this.$el.getBoundingClientRect();
             const size = { width: box.width || @js($widthPx), height: box.height || 320 };
             this.pos = desired
                 ? window.__uiClampWindow(desired, size, view)
                 : window.__uiRestoreWindow(this.key, size, view, window.__uiDefaultWindowPos(size, view));
             this.placed = true;
         },

         /** Called when the window becomes visible: first open positions it, later opens re-clamp. */
         onShow() {
             this.$nextTick(() => this.place(this.placed ? this.pos : null));
         },

         /**
          * MOVE AND UP ARE BOUND TO THE WINDOW, not to the title bar, and that is not defensive
          * styling — it is the fix for a measured bug. Bound to the element and relying on
          * setPointerCapture to redirect events back to it, a drag that ended outside the title bar
          * never delivered its pointerup: the panel kept the position from its last move (23px past
          * the clamp limit, at top 859 in a 900px viewport) and persisted it. Nothing errored.
          *
          * Capture is best-effort and can silently not apply; the window always sees the events.
          */
         start(e) {
             if (e.button !== undefined && e.button !== 0) return;
             this.dragging = true;
             this._from = { x: e.clientX, y: e.clientY };
             this._origin = { ...this.pos };
             this._onMove = (ev) => this.move(ev);
             this._onEnd = (ev) => this.end(ev);
             window.addEventListener('pointermove', this._onMove);
             window.addEventListener('pointerup', this._onEnd);
             window.addEventListener('pointercancel', this._onEnd);
             e.preventDefault();
         },
         move(e) {
             if (!this.dragging) return;
             this.place({
                 left: this._origin.left + (e.clientX - this._from.x),
                 top: this._origin.top + (e.clientY - this._from.y),
             });
         },
         end() {
             if (!this.dragging) return;
             this.dragging = false;
             window.removeEventListener('pointermove', this._onMove);
             window.removeEventListener('pointerup', this._onEnd);
             window.removeEventListener('pointercancel', this._onEnd);
             // Re-clamp before saving. move() clamps too, but it measures the element mid-flight;
             // the position that gets PERSISTED is the one that has to still be reachable tomorrow,
             // so it is recomputed once against a settled layout.
             this.place(this.pos);
             window.__uiSaveWindow(this.key, this.pos);
         },
     }"
     x-effect="{{ $show }} && onShow()"
     @keydown.escape.window="{{ $show }} && ({{ $onClose }})"
     :style="`left:${pos.left}px; top:${pos.top}px; width: {{ $width }};`"
     class="fixed z-50 overflow-hidden rounded-box border border-slate-700 bg-base-300 shadow-2xl"
     role="dialog" aria-label="{{ $title }}">

    {{-- Title bar: the drag handle. `touch-none` stops a touch drag scrolling the page instead. --}}
    <div class="flex cursor-move touch-none select-none items-center justify-between border-b border-slate-700/60 bg-base-200/60 px-3 py-2"
         @pointerdown="start($event)">
        <span class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">{{ $title }}</span>
        {{-- The close button must not start a drag, or a click that moves one pixel becomes a drag
             and never closes. --}}
        <button type="button" @pointerdown.stop @click="{{ $onClose }}"
                class="text-slate-500 hover:text-slate-200" aria-label="{{ __('Close') }}">&times;</button>
    </div>

    {{ $slot }}
</div>
