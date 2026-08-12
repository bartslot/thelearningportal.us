{{--
    The territory information card.

    Figma: History Portal › territory-information-panel (node 1462:1886). The rules it follows, and
    why, are in .claude/skills/history-portal-ui/SKILL.md; the values are in resources/css/brand-kit.css.
    Read the skill before changing anything here — this card is where that design language is defined,
    so a shortcut taken here becomes the house style by example.

    THE CARD SITS ON A PHOTOGRAPHED PLANET. That is the constraint behind every choice below: no
    accent colour anywhere (the map is already the colourful thing), affordance carried by an
    underline instead, and a body that FADES rather than clipping — a gradient overlay would have to
    know the colour of a map that changes with the year, the style and the time of day.

    Extracted from time-map.blade.php rather than left inline: that file is edited by nearly every
    map session, and 150 lines of card in the middle of it made every one of them a conflict.
--}}
<aside x-data="{
           tab: 'summary',
           polity: null,
           loading: false,
           lead: null,
           selected: [],
           {{-- The fade means 'there is more'. So it is on only while there IS more: a body scrolled
                to its last line drops it, or the final sentence sits permanently half-dissolved and
                the fade stops meaning anything. Recomputed on scroll and whenever the body changes. --}}
           more: false,
           measure() {
               const el = $refs.body;
               if (!el) { this.more = false; return; }
               this.more = el.scrollHeight - el.scrollTop - el.clientHeight > 1;
           },
           {{-- WHAT IDENTIFIES A TERRITORY IS ON THE EVENT, NOT IN THE RESPONSE.
                This is captured when the panel opens rather than read off `polity` at the click,
                because `polity` is the enrichment endpoint's JSON and that response carries no
                `wikidata_id`, no `territoryId` and no `hand` — see the json() in routes/web.php.
                Reading them there yields undefined every time, so every report would file with a
                null QID and claim to be Cliopatria's: valid, stored, and wrong in exactly the
                fields that make it actionable. `territoryId` and `hand` are absent from the detail
                too until the hand-authored territories branch lands, and are read defensively so
                they start working the moment it does. --}}
           reportContext: null,
           {{-- The year is read at the CLICK, not here: the teacher may scrub the slider before
                they finish typing, and what they are reporting is what was on screen when they
                decided something was wrong. --}}
           report() {
               if (!this.reportContext) return;
               window.dispatchEvent(new CustomEvent('timemap-report-requested', { detail: {
                   ...this.reportContext,
                   year: Math.round(window.__portal?.year ?? 0),
               } }));
           },
           {{-- No space, per the Figma: '500BCE', not '500 BCE'. --}}
           era(year) { return year < 0 ? Math.abs(year) + 'BCE' : year + 'CE'; },
           {{-- Closing has to undo the whole click, not just the panel. `polity = null` hides the
                card; without the other two the territory stays lit and the voice keeps talking. --}}
           close() {
               this.polity = null;
               this.reportContext = null;
               window.__timemapStopSpeak && window.__timemapStopSpeak();
               window.__timemapClearSelection && window.__timemapClearSelection();
           },
       }"
       x-show="polity || loading"
       x-transition.opacity.duration.150ms
       {{-- Closes three ways. X and Esc are here; the third is the map itself, which dispatches
            polity-selected with no id when you click away — the backdrop, for a panel that has none. --}}
       x-on:keydown.escape.window="close()"
       x-on:polity-selected.window="
            window.__timemapStopSpeak && window.__timemapStopSpeak();
            if (!$event.detail.id) { polity = null; loading = false; reportContext = null; return; }
            tab = 'summary'; selected = []; lead = null;
            {{-- Before any of the three ways a panel can be filled below, because all three of them
                 return early and only the event knows which territory was actually clicked. --}}
            reportContext = {
                territory_id: $event.detail.territoryId || null,
                wikidata_qid: $event.detail.qid || null,
                label: $event.detail.name || '',
                source: $event.detail.hand ? 'hand' : 'cliopatria',
            };
            if ($event.detail.articleUrl) {
                // Curated external-article marker (e.g. worldhistory.org) — render directly, no server call.
                polity = { label: $event.detail.name, summary: $event.detail.summary || null,
                           wikipedia_url: $event.detail.articleUrl,
                           inception: $event.detail.inception ?? null, dissolution: $event.detail.dissolution ?? null,
                           flag_path: null, predecessor: null, successor: null };
                loading = false;
                window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity);
                return;
            }
            // Instant from the prefetch cache when available; else fetch (and cache).
            const cached = (window.__polityCache || {})[$event.detail.id];
            if (cached) {
                polity = { ...cached, label: $event.detail.name || cached.label }; loading = false;
                window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity);
                return;
            }
            loading = true; polity = null;
            fetch('/teacher/timemap/polity/' + $event.detail.id + '?name=' + encodeURIComponent($event.detail.name || '') + ($event.detail.qid ? '&qid=' + encodeURIComponent($event.detail.qid) : ''))
                .then(r => r.json()).then(d => { polity = d; loading = false; (window.__polityCache = window.__polityCache || {})[$event.detail.id] = d; window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity); });
       "
       {{-- Mobile: start below the settings cog (right-4 top-4, z-30) — on a phone the cog lands
            exactly on the panel's close button and steals its taps. --}}
       class="absolute left-4 top-18 z-20 flex max-h-[calc(100%-10.5rem)] flex-col overflow-hidden rounded-card bg-card-surface sm:top-4 sm:max-h-[calc(100%-7rem)]"
       style="display:none; width: var(--card-width)">

    <template x-if="loading">
        <p class="flex items-center gap-2 p-card-gutter text-card-body">
            <span class="loading loading-spinner loading-sm"></span> {{ __('Loading…') }}
        </p>
    </template>

    <template x-if="polity">
        <div class="flex min-h-0 flex-col">

            {{-- ── Header: flag, title, era, close ──────────────────────────────────────────── --}}
            <div class="flex shrink-0 items-center justify-between gap-3 px-4 pb-4 pt-6">
                <div class="flex min-w-0 items-center gap-3">
                    {{-- A flag only. The design carries no photograph here, and the 144px Wikipedia
                         image the old panel showed pushed everything below it off a laptop screen.

                         A MISSING FILE REMOVES THE THUMB RATHER THAN DRAWING A BROKEN ONE. 581
                         polities carry a flag_path and the files are generated, not committed
                         (/public/flags is gitignored), so an environment without them is normal —
                         and a broken-image glyph in the header is worse than no flag at all. --}}
                    <template x-if="polity.flag_path">
                        <img :src="polity.flag_path" alt="" x-on:error="$el.remove()"
                             class="h-10 w-16 shrink-0 rounded-md border border-card-hairline object-cover">
                    </template>

                    <div class="flex min-w-0 flex-col">
                        <h2 class="wrap-break-word text-base font-bold text-card-title" x-text="polity.label"></h2>

                        {{-- The era is WHITE, and the UNDERLINE is what says both dates are live —
                             they scrub the timeline to that year. Not the accent: this card is
                             monochrome throughout, and colour is not what makes a date clickable. --}}
                        <p class="flex items-center">
                            <template x-if="polity.inception != null">
                                <button type="button" class="lp-card-label lp-card-label-sm cursor-pointer text-card-title underline"
                                        x-on:click="window.__setTimemapYear && window.__setTimemapYear(polity.inception)"
                                        x-text="era(polity.inception)"></button>
                            </template>
                            <template x-if="polity.inception == null">
                                <span class="lp-card-label lp-card-label-sm">?</span>
                            </template>

                            <span class="w-5 text-center text-card-label text-card-muted opacity-20" aria-hidden="true">|</span>

                            <template x-if="polity.dissolution != null">
                                <button type="button" class="lp-card-label lp-card-label-sm cursor-pointer text-card-title underline"
                                        x-on:click="window.__setTimemapYear && window.__setTimemapYear(polity.dissolution)"
                                        x-text="era(polity.dissolution)"></button>
                            </template>
                            <template x-if="polity.dissolution == null">
                                <span class="lp-card-label lp-card-label-sm">?</span>
                            </template>
                        </p>
                    </div>
                </div>

                <button type="button" x-on:click="close()" aria-label="{{ __('Close') }}"
                        class="shrink-0 cursor-pointer text-card-muted">
                    <x-icons.x-mark class="h-4 w-4" />
                </button>
            </div>

            {{-- ── Tabs: the active one is a RAISED SURFACE, not an underline ───────────────── --}}
            <div role="tablist"
                 class="flex shrink-0 items-center gap-1 border-y border-card-hairline px-4 py-2">
                @foreach ([['summary', __('Summary')], ['people', __('People')], ['overtime', __('Over time')]] as [$key, $label])
                    <button type="button" role="tab" @class(['cursor-pointer rounded-md px-3.5 py-1.5 text-[13px] whitespace-nowrap'])
                            :aria-selected="tab === '{{ $key }}'"
                            :class="tab === '{{ $key }}'
                                ? 'bg-card-surface-raised font-semibold text-card-body'
                                : 'font-medium text-card-muted'"
                            x-on:click="tab = '{{ $key }}'; $nextTick(() => measure())">{{ $label }}</button>
                @endforeach
            </div>

            {{-- ── Body: one label, one scrolling area, one fade — the same on all three tabs ─ --}}
            <div class="flex min-h-0 shrink flex-col gap-2 py-4 pl-4 pr-2" style="height: var(--card-body-height)">
                <p class="lp-card-label shrink-0" x-text="polity.label"></p>

                <div x-ref="body"
                     x-on:scroll="measure()"
                     x-effect="lead, tab, polity, $nextTick(() => measure())"
                     :class="more && 'lp-card-fade'"
                     class="min-h-0 flex-1 overflow-y-auto pr-2 text-[13px]/[1.4] text-card-body">

                    {{-- Summary. The corpus gives a sentence or two; Wikipedia's lead section is
                         fetched on open and replaces it when it lands, which is what gives this
                         area something to scroll — and the fade something to mean. --}}
                    <div x-show="tab === 'summary'">
                        <p class="whitespace-pre-line" x-text="lead || polity.summary || @js(__('No summary yet.'))"></p>
                    </div>

                    {{-- People: rulers and notable figures. Selecting one carries it into the lesson
                         wizard as the protagonist, which is why the button below changes its label. --}}
                    <div x-show="tab === 'people'" class="flex flex-col gap-2">
                        <template x-if="!polity.figures || polity.figures.length === 0">
                            <p class="text-card-muted">{{ __('No people linked yet for this territory.') }}</p>
                        </template>
                        <template x-for="f in (polity.figures || [])" :key="f.qid">
                            <div class="flex items-center gap-3 rounded-md border border-card-hairline p-2">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-card-surface-raised">
                                    <template x-if="f.image_url">
                                        <img :src="f.image_url + '?width=80'" :alt="f.name"
                                             class="h-full w-full object-cover" loading="lazy">
                                    </template>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="wrap-break-word font-semibold leading-tight text-card-title" x-text="f.name"></p>
                                    <p class="lp-card-label truncate" x-text="[f.kind, f.era].filter(Boolean).join(' · ')"></p>
                                </div>
                                <button type="button"
                                        x-on:click="selected.some(s => s.qid === f.qid) ? (selected = selected.filter(s => s.qid !== f.qid)) : (selected = [...selected, f])"
                                        class="btn btn-xs shrink-0 border-card-hairline bg-transparent text-card-body"
                                        :class="selected.some(s => s.qid === f.qid) && 'bg-card-surface-raised'">
                                    <template x-if="selected.some(s => s.qid === f.qid)">
                                        <x-icons.check-circle class="h-3.5 w-3.5" />
                                    </template>
                                    <span x-text="selected.some(s => s.qid === f.qid) ? @js(__('Selected')) : @js(__('Use in lesson'))"></span>
                                </button>
                            </div>
                        </template>
                    </div>

                    <div x-show="tab === 'overtime'" class="flex flex-col gap-1">
                        <p><span class="text-card-muted">{{ __('Preceded by') }}:</span> <span x-text="polity.predecessor || '—'"></span></p>
                        <p><span class="text-card-muted">{{ __('Succeeded by') }}:</span> <span x-text="polity.successor || '—'"></span></p>
                    </div>
                </div>
            </div>

            {{-- ── Read more: a label and a link, not a sentence ────────────────────────────── --}}
            <template x-if="polity.wikipedia_url">
                <div class="flex shrink-0 items-center justify-center gap-2 p-4">
                    <span class="lp-card-label lp-card-label-sm">{{ __('Read more on') }}</span>
                    <a :href="polity.wikipedia_url" target="_blank" rel="noopener"
                       class="lp-card-label lp-card-label-sm text-card-title underline"
                       x-text="polity.wikipedia_url.includes('wikipedia.org') ? 'wikipedia.org' : new URL(polity.wikipedia_url).hostname.replace(/^www\./, '')"></a>
                </div>
            </template>

            {{-- ── The one primary action ───────────────────────────────────────────────────── --}}
            <div class="shrink-0 border-y border-card-hairline p-4">
                <a :href="'{{ route('teacher.lessons.create') }}?topic=' + encodeURIComponent(polity.label) + (selected.length ? '&protagonist_qid=' + encodeURIComponent(selected[0].qid) + '&protagonist_name=' + encodeURIComponent(selected[0].name) : '')"
                   wire:navigate
                   class="btn h-9 min-h-0 w-full gap-2 rounded-card-pill border-none bg-card-action text-[13px] font-bold text-card-action-label hover:bg-card-action">
                    <x-icons.academic-cap class="h-4 w-4" />
                    <span x-text="selected.length ? @js(__('Create lesson with').' ') + selected[0].name + (selected.length > 1 ? ' +' + (selected.length - 1) : '') : @js(__('Create Lesson'))"></span>
                </a>
            </div>

            {{-- ── Say when something might be wrong ────────────────────────────────────────── --}}
            <div class="flex shrink-0 items-center justify-center gap-2 px-4 py-6">
                <span class="lp-card-label lp-card-label-sm">{{ __('Something not right?') }}</span>
                <button type="button" x-on:click="report()"
                        class="lp-card-label lp-card-label-sm cursor-pointer text-card-title underline">{{ __('Report') }}</button>
            </div>
        </div>
    </template>
</aside>
