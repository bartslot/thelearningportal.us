{{--
    The app's ONE notification surface. Every success/error/info message goes through here —
    Livewire `dispatch('toast', ...)`, a `session()->flash('success'|'error')`, or a plain
    `window.dispatchEvent(new CustomEvent('toast', { detail: {...} }))`.

    Shape follows the common convention (Material, Radix, Sonner, Primer): a NEUTRAL card carrying
    the status only as a left accent line plus an icon. Deliberately not a saturated block —
    a full-bleed green bar reads as an alarm and drowns out the page it interrupts.

    Every toast is dismissible and auto-hides. Errors get longer on screen because they usually
    ask something of the reader; they still expire so nothing is left stuck on the page.

    Do NOT add a bespoke coloured banner to a page. Add a type here instead.
--}}
<div
    x-data="toastHost()"
    x-on:toast.window="push($event.detail)"
    class="pointer-events-none fixed inset-x-0 top-20 z-[9999] flex flex-col items-end gap-2 px-4 sm:px-6 lg:px-8"
    role="region"
    aria-label="{{ __('Notifications') }}"
>
    <template x-for="t in toasts" :key="t.id">
        {{-- No x-show flag: Alpine transitions x-for items on insert and removal, and a separate
             "shown" boolean raced the loop's own render — it flipped true before x-show's effect
             existed, leaving finished toasts stuck at display:none. --}}
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="pointer-events-auto flex w-full max-w-md items-start gap-3 overflow-hidden rounded-lg border border-slate-700/80 bg-slate-900/95 py-3 pl-4 pr-3 shadow-xl backdrop-blur-sm"
            {{-- The status colour lives here: a 3px line, not a fill. --}}
            :style="`border-left-width:3px;border-left-color:${accent(t.type)}`"
            :role="t.type === 'error' ? 'alert' : 'status'"
            :aria-live="t.type === 'error' ? 'assertive' : 'polite'"
            x-on:mouseenter="hold(t)"
            x-on:mouseleave="resume(t)"
        >
            {{-- Status icon — Heroicons outline, 24x24, stroke 1.5 (see icon standard).
                 One path with a bound `d`, NOT <template x-if> per type: a template inside <svg>
                 renders into the HTML namespace and the path never draws, which left every toast
                 with a blank gap where its icon should be. --}}
            <svg class="mt-0.5 h-5 w-5 shrink-0" :style="`color:${accent(t.type)}`"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath(t.type)" />
            </svg>

            {{-- Message is untrusted (teacher text, API errors) — x-text, never x-html. --}}
            <div class="min-w-0 flex-1">
                <p class="text-sm leading-snug text-slate-100" x-text="t.message"></p>

                {{-- Optional one-tap follow-up ("Scene deleted." → Undo). The toast stays dumb: it
                     fires the named window event and whoever owns the action listens for it. --}}
                <template x-if="t.action">
                    <button type="button"
                            x-on:click="run(t)"
                            class="mt-1 rounded text-sm font-semibold text-sky-400 underline decoration-sky-400/40 underline-offset-2 transition hover:text-sky-300 hover:decoration-sky-300"
                            x-text="t.action.label"></button>
                </template>
            </div>

            <button type="button" x-on:click="dismiss(t)"
                    class="-mr-1 shrink-0 rounded p-1 text-slate-500 transition hover:bg-slate-800 hover:text-slate-200"
                    :aria-label="@js(__('Dismiss'))">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>

@once
    @push('scripts')
        <script>
            window.toastHost = function () {
                return {
                    toasts: [],
                    _seq: 0,

                    // One accent per status. Nothing else in the toast is tinted.
                    accent(type) {
                        return {
                            success: '#34d399',   // emerald-400
                            error:   '#fb7185',   // rose-400
                            warning: '#fbbf24',   // amber-400
                            info:    '#38bdf8',   // sky-400
                        }[type] || '#38bdf8'
                    },

                    // Heroicons outline paths, one per status.
                    iconPath(type) {
                        return {
                            success: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                            error:   'M12 9v3.75m0 3.75h.007M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                            warning: 'M12 9v3.75m0 3.75h.007M11.25 3.75 2.7 18.75a.75.75 0 0 0 .65 1.125h17.3a.75.75 0 0 0 .65-1.125L12.75 3.75a.75.75 0 0 0-1.3 0Z',
                            info:    'M11.25 11.25h.75v4.5h.75M12 8.25h.007M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                        }[type] || 'M11.25 11.25h.75v4.5h.75M12 8.25h.007M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'
                    },

                    push(detail) {
                        const d = detail || {}
                        const message = String(d.message ?? d.text ?? '').trim()
                        if (!message) return
                        const type = ['success', 'error', 'warning', 'info'].includes(d.type) ? d.type : 'info'
                        // An error usually asks something of the reader, so it lingers.
                        const life = Number(d.duration) > 0 ? Number(d.duration) : (type === 'error' ? 8000 : 4500)

                        // An action needs a label and the window event to fire; anything else is ignored.
                        const action = d.action && d.action.label && d.action.event
                            ? { label: String(d.action.label), event: String(d.action.event) }
                            : null

                        this.toasts.push({ id: ++this._seq, message, type, life, action, timer: null })
                        // Work through the REACTIVE proxy Alpine hands back, never the object
                        // literal above — writes to the raw target do not trigger effects.
                        const t = this.toasts[this.toasts.length - 1]

                        // Cap the stack so a burst of saves cannot paper over the page.
                        while (this.toasts.length > 4) {
                            clearTimeout(this.toasts[0].timer)
                            this.toasts.splice(0, 1)
                        }

                        this.arm(t)
                    },

                    arm(t) {
                        clearTimeout(t.timer)
                        t.timer = setTimeout(() => this.dismiss(t), t.life)
                    },

                    // Fire the action's event and get out of the way — the result usually arrives as
                    // its own toast ("Scene restored."), so leaving this one up would stack them.
                    run(t) {
                        window.dispatchEvent(new CustomEvent(t.action.event, { detail: {} }))
                        this.dismiss(t)
                    },
                    // Pausing on hover is the standard courtesy — a reader must not lose a message
                    // mid-sentence just because it timed out.
                    hold(t) { clearTimeout(t.timer) },
                    resume(t) { this.arm(t) },

                    dismiss(t) {
                        clearTimeout(t.timer)
                        // Removing from the array is what plays the leave transition.
                        const i = this.toasts.findIndex((x) => x.id === t.id)
                        if (i !== -1) this.toasts.splice(i, 1)
                    },
                }
            }

            // No Livewire.on('toast') bridge here on purpose. Livewire 3 already delivers a
            // server-side dispatch('toast') to the DOM: it fires a bubbling CustomEvent on the
            // component's root element, which reaches the x-on:toast.window listener above on its
            // own. Forwarding it a second time to window made every Livewire notification appear
            // as TWO identical bubbles.
        </script>
    @endpush
@endonce
