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
           {{-- The paper trail, for territories we drew ourselves. Null for an imported one, which
                is not a branch in the UI so much as an absence of one: there is no per-feature
                provenance to show for a Cliopatria border, and the dataset-level credit belongs on
                the map's attribution line, not in every card. --}}
           provenance: null,
           sourcesOpen: false,
           grades: {
               A: @js(__('Scholarly atlas')),
               B: @js(__('Specialist project')),
               C: @js(__('Wikipedia, with a source')),
               D: @js(__('Wikipedia, no source given')),
           },
           {{-- A disagreement names a source by key; a teacher needs its title. --}}
           sourceTitle(key) {
               return this.provenance?.sources.find((s) => s.key === key)?.title ?? key;
           },
           {{-- The year is read at the CLICK, not here: the teacher may scrub the slider before
                they finish typing, and what they are reporting is what was on screen when they
                decided something was wrong. --}}
           {{-- The name this territory replaced, when the year moved it on rather than you
                clicking something new. Cleared once the slide has played. --}}
           succeededFrom: null,
           successionKey: 0,
           noteSuccession(from) {
               this.succeededFrom = from || null;
               this.successionKey++;
               clearTimeout(this._successionTimer);
               this._successionTimer = setTimeout(() => { this.succeededFrom = null }, 4200);
           },
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
               this.provenance = null;
               this.sourcesOpen = false;
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
            if (!$event.detail.id) { polity = null; loading = false; reportContext = null; provenance = null; return; }
            if ($event.detail.succeeded) noteSuccession($event.detail.previousName);
            else { succeededFrom = null; }
            tab = 'summary'; selected = []; lead = null;
            provenance = $event.detail.provenance || null;
            sourcesOpen = false;
            {{-- Before any of the three ways a panel can be filled below, because all three of them
                 return early and only the event knows which territory was actually clicked. --}}
            reportContext = {
                territory_id: $event.detail.territoryId || null,
                wikidata_qid: $event.detail.qid || null,
                label: $event.detail.name || '',
                source: $event.detail.hand ? 'hand' : 'cliopatria',
            };
            {{-- A hand-authored territory's dates come from the feature. The enrichment endpoint
                 reads Cliopatria's own era table and answers '?' for a polity that is not in it,
                 which would leave every territory we drew ourselves showing '? | ?'. --}}
            const handDates = $event.detail.hand
                ? { inception: $event.detail.inception ?? null, dissolution: $event.detail.dissolution ?? null }
                : {};
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
                polity = { ...cached, label: $event.detail.name || cached.label, ...handDates }; loading = false;
                window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity);
                return;
            }
            loading = true; polity = null;
            fetch('/teacher/timemap/polity/' + $event.detail.id + '?name=' + encodeURIComponent($event.detail.name || '') + ($event.detail.qid ? '&qid=' + encodeURIComponent($event.detail.qid) : ''))
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(d => { polity = { ...d, ...handDates }; loading = false; (window.__polityCache = window.__polityCache || {})[$event.detail.id] = d; window.__timemapHydratePanel && window.__timemapHydratePanel($data, polity); })
                {{-- There was no catch here at all: when the endpoint failed the promise rejected,
                     `loading` stayed true, and the card spun forever with nothing in it. The corpus
                     database went away on production once and every territory click became an
                     infinite spinner, which reads as a hung page rather than a failed lookup. A
                     failure has to be visible AND leave the card usable. --}}
                .catch(e => {
                    loading = false;
                    polity = { label: $event.detail.name || '', summary: null, lookup_failed: true,
                               inception: null, dissolution: null, ...handDates };
                    console.warn('timemap: polity lookup failed', e);
                });
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

            {{-- ── It became something else ─────────────────────────────────────────────────
                 Shown only when the YEAR moved the selection on, never when you clicked. The card
                 would otherwise swap its own contents silently, which reads as a bug: you are
                 looking at Kingdom of Italy, you nudge the slider, and the words change under you.
                 The slide is the point — it says the country continued and its government did not.
                 Announced politely so a screen reader gets the change too. --}}
            <template x-if="succeededFrom">
                <div class="shrink-0 overflow-hidden px-4 pt-4" role="status" aria-live="polite">
                    <div class="lp-succession flex items-center gap-2 rounded-card-pill bg-card-hairline/40 px-3 py-1.5"
                         :key="successionKey">
                        <x-icons.chevron-right class="h-3.5 w-3.5 shrink-0 text-card-muted" />
                        <span class="lp-card-label lp-card-label-sm min-w-0 truncate text-card-muted"
                              x-text="succeededFrom"></span>
                        <span class="lp-card-label lp-card-label-sm shrink-0 text-card-title">{{ __('became') }}</span>
                    </div>
                </div>
            </template>

            {{-- ── Header: flag, title, era, close ──────────────────────────────────────────── --}}
            <div class="flex shrink-0 items-center justify-between gap-3 px-4 pb-4 pt-6"
                 :class="succeededFrom && 'lp-succession-in'" :key="successionKey">
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
                     {{-- sourcesOpen is in here because expanding the paper trail changes
                          scrollHeight, and a fade that does not recompute then says "no more"
                          over a list that just got longer. --}}
                     x-effect="lead, tab, polity, sourcesOpen, $nextTick(() => measure())"
                     :class="more && 'lp-card-fade'"
                     class="min-h-0 flex-1 overflow-y-auto pr-2 text-[13px]/[1.4] text-card-body">

                    {{-- Summary. The corpus gives a sentence or two; Wikipedia's lead section is
                         fetched on open and replaces it when it lands, which is what gives this
                         area something to scroll — and the fade something to mean. --}}
                    <div x-show="tab === 'summary'">
                        {{-- The lookup failed. Say so, and keep the territory's own name on screen
                             so the click still did something: the teacher at least learns what they
                             clicked. Distinct from "No summary yet", which means we looked and
                             there was nothing — this means we could not look. --}}
                        <template x-if="polity.lookup_failed">
                            <p class="text-card-muted">{{ __('We could not load the details for this region just now.') }}</p>
                        </template>
                        <p x-show="!polity.lookup_failed" class="whitespace-pre-line"
                           x-text="lead || polity.summary || @js(__('No summary yet.'))"></p>

                        {{-- WHERE THIS BORDER COMES FROM — only for territories we drew ourselves.
                             A Cliopatria border has no per-feature paper trail, and the dataset
                             credit belongs on the attribution line rather than in every card.

                             The judgement sentence is never behind the disclosure: a teacher
                             choosing between two lessons has to be told which border is firm
                             without opening anything. The graded list is for the one who checks. --}}
                        <template x-if="provenance">
                            <div class="mt-4 border-t border-card-hairline pt-3">
                                <p class="lp-card-label">{{ __('Where this border comes from') }}</p>
                                <p class="mt-1.5 leading-relaxed text-card-body" x-text="provenance.summary"></p>

                                {{-- Low confidence is said in words, in the place a teacher is
                                     already looking. Not a colour and not an icon: this has to
                                     survive being skim-read, and "amber means uncertain" is a
                                     convention nobody taught them. --}}
                                <p class="mt-2 leading-relaxed text-card-body" x-show="provenance.confidence === 'low'">
                                    <span class="font-semibold text-card-title">{{ __('Low confidence.') }}</span>
                                    {{ __('Use it to show roughly where, never exactly where.') }}
                                </p>

                                <button type="button"
                                        class="lp-card-label mt-3 flex w-full items-center justify-between hover:text-card-body"
                                        :aria-expanded="sourcesOpen ? 'true' : 'false'"
                                        x-on:click="sourcesOpen = !sourcesOpen">
                                    <span x-text="sourcesOpen
                                        ? @js(__('Hide the sources'))
                                        : @js(__('Sources')) + ' (' + provenance.sources.length + ')'"></span>
                                    <x-icons.chevron-down class="h-4 w-4 transition-transform"
                                                          ::class="sourcesOpen && 'rotate-180'" />
                                </button>

                                {{-- Expands into the card's own scrolling body. No inner scroller
                                     and no second fade: the body above already owns both, and two
                                     nested ones make the outer fade lie about what is left. --}}
                                <div x-show="sourcesOpen" x-transition.opacity style="display:none">
                                    <template x-for="s in provenance.sources" :key="s.key">
                                        <div class="border-t border-card-hairline py-2">
                                            <p class="lp-card-label">
                                                <span x-text="grades[s.grade] || s.grade"></span>
                                                <span x-show="s.year != null"> ·
                                                    <span x-text="s.year < 0 ? Math.abs(s.year) + ' BCE' : s.year"></span>
                                                </span>
                                            </p>
                                            {{-- Underlined at rest, in the hairline: a source you
                                                 cannot tell is clickable is a source nobody checks. --}}
                                            <a :href="s.url" target="_blank" rel="noopener"
                                               class="mt-0.5 block leading-snug text-card-body underline decoration-card-hairline underline-offset-2 hover:decoration-current"
                                               x-text="s.title"></a>
                                            <p x-show="s.key === provenance.chosen_source"
                                               class="lp-card-label mt-1 text-card-title">{{ __('Drawn from this one') }}</p>
                                            <p class="mt-1 leading-snug text-card-muted" x-text="s.note"></p>
                                        </div>
                                    </template>

                                    {{-- The disagreements ARE the finding. Tribal territories are
                                         drawn differently by different scholars because the
                                         evidence is thin, and hiding that presents a guess as a
                                         fact. --}}
                                    <div class="border-t border-card-hairline pt-2">
                                        <p class="lp-card-label">{{ __('Where the sources disagree') }}</p>
                                        <template x-for="d in provenance.disagreements" :key="d.source + d.says">
                                            <p class="mt-1.5 leading-snug text-card-muted">
                                                <span class="text-card-body" x-text="sourceTitle(d.source)"></span>
                                                <span x-text="' — ' + d.says"></span>
                                            </p>
                                        </template>
                                    </div>

                                    <div class="mt-2 border-t border-card-hairline pt-2">
                                        <p class="leading-snug text-card-muted" x-text="provenance.method"></p>
                                        <p class="lp-card-label mt-1.5" x-text="provenance.drawn_by"></p>
                                    </div>
                                </div>
                            </div>
                        </template>
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
                {{-- polity?. because :href is an attribute binding, not a template: when the card
                     closes it re-evaluates once against a null polity before x-if tears it down,
                     and an uncaught TypeError there aborts the whole Alpine effect mid-flush. --}}
                <a :href="'{{ route('teacher.lessons.create') }}?topic=' + encodeURIComponent(polity?.label ?? '') + (selected.length ? '&protagonist_qid=' + encodeURIComponent(selected[0].qid) + '&protagonist_name=' + encodeURIComponent(selected[0].name) : '')"
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
