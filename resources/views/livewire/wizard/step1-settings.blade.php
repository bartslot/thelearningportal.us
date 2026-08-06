<div class="space-y-4 pt-6" x-data="wizardFlow()" x-on:flow-next.window="advance()">

    {{-- ═══════════════════════════════════════════════
         VOYAGE lessons — picked from the historical-voyage catalog (no topic/generation).
         Only offered when starting a brand-new lesson.
    ════════════════════════════════════════════════ --}}
    @if (! $lesson && count($this->voyageOptions))
        {{-- Selection lives in Alpine (pick) and is handed to the server only on Create, so choosing
             a voyage never round-trips + collapses the panel mid-flow (Livewire re-render gotcha). --}}
        {{-- NOT a flow group: this is a collapsed shortcut banner (~53px), and giving it the 75vh
             flow treatment pushed the Topic field — the first thing anyone actually needs — below
             the fold behind ~490px of empty space, dimmed to 38%. The flow starts at Topic. --}}
        <div class="rounded-2xl border border-indigo-500/30 bg-indigo-950/20 p-4"
             x-data="{ open: false, pick: '' }">
            <button type="button" class="flex w-full items-center gap-3 text-left" x-on:click="open = !open">
                <x-lesson.icon-voyage class="h-7 w-7 text-indigo-300" />
                <span class="flex-1">
                    <span class="block text-sm font-semibold text-indigo-200">{{ __('Start from a historical voyage') }}</span>
                    <span class="block text-xs text-slate-400">{{ __('Sail a real expedition on the map. No topic or generation needed, ready to edit in seconds.') }}</span>
                </span>
                <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
            </button>

            <div x-show="open" x-collapse class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                <label class="form-control flex-1">
                    <span class="text-[10px] uppercase tracking-wider text-slate-400">{{ __('Voyage') }}</span>
                    <select x-model="pick" class="select select-sm select-bordered bg-slate-900 mt-1">
                        <option value="">{{ __('Choose a voyage…') }}</option>
                        @foreach ($this->voyageOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('voyagePick') <span class="mt-1 text-xs text-rose-300">{{ $message }}</span> @enderror
                </label>
                <button type="button" x-on:click="$wire.createVoyageLesson(pick)"
                        wire:loading.attr="disabled" wire:target="createVoyageLesson"
                        class="btn btn-sm btn-primary">
                    <span wire:loading.remove wire:target="createVoyageLesson">{{ __('Create voyage lesson') }}</span>
                    <span wire:loading wire:target="createVoyageLesson">{{ __('Building…') }}</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════
         TOPIC (always visible, hero field)
    ════════════════════════════════════════════════ --}}
    <div data-flow-group class="wizard-flow-group bg-base-300 rounded-2xl p-6 space-y-3">

        {{-- Curated STORY catalog — the preferred path: human-reviewed stories with
             learning objectives and real narrative sources. --}}
        @if ($storyId)
            @php $chosen = collect($this->storyChoices)->firstWhere('id', $storyId); @endphp
            <div class="alert bg-emerald-500/10 border border-emerald-500/40 flex items-center justify-between">
                <div class="flex flex-col gap-0.5">
                    <span class="text-sm text-emerald-300 font-semibold">{{ __('Story') }}: {{ $chosen['title'] ?? $topic }}</span>
                    <span class="text-xs text-slate-400">
                        {{ collect([$chosen['era'] ?? null, $chosen['region'] ?? null, $chosen['grade_band'] ?? null])->filter()->implode(' · ') }}
                        · {{ __('curated: objectives and sources included') }}
                    </span>
                </div>
                <button type="button" wire:click="clearStory" class="btn btn-xs btn-ghost">{{ __('Change') }}</button>
            </div>
        @elseif (count($this->storyChoices) > 0)
            <div class="form-control space-y-2">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                    {{ __('Pick a story') }} <span class="text-amber-400/70 normal-case tracking-normal">· {{ __('curated, with learning goals') }}</span>
                </span>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 max-h-72 overflow-y-auto pr-1">
                    @foreach ($this->storyChoices as $choice)
                        <button type="button" wire:click="selectStory({{ $choice['id'] }})"
                                class="card bg-slate-900 hover:bg-slate-800 border border-white/10 hover:border-amber-500/50 text-left transition">
                            <div class="card-body p-3 gap-1">
                                <span class="text-sm text-white font-medium leading-tight">{{ $choice['title'] }}</span>
                                @if ($choice['subtitle'])
                                    <span class="text-xs text-slate-400 leading-tight">{{ $choice['subtitle'] }}</span>
                                @endif
                                <span class="text-[11px] text-slate-500">
                                    {{ collect([$choice['era'], $choice['region'], $choice['grade_band']])->filter()->implode(' · ') }}
                                </span>
                            </div>
                        </button>
                    @endforeach
                </div>
                <span class="text-xs text-slate-500">{{ __('Nothing that fits? Search a free topic below — we\'ll ground it in Wikipedia.') }}</span>
            </div>
            <div class="divider my-1 text-xs text-slate-500">{{ __('or free topic') }}</div>
        @endif

        {{-- Topic — locked to the curated, Wikipedia-grounded catalog (A1) --}}
        <div x-data="{ open: false }" class="relative form-control" @if($storyId) style="display:none" @endif>
            <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                {{ __('Topic') }} <span class="text-amber-400/70 normal-case tracking-normal">· {{ __('pick from the catalog') }}</span>
            </span>
            <div class="relative">
                <input id="lw-topic" name="topic" type="text"
                       wire:model.live.debounce.250ms="topic"
                       x-on:focus="open = true"
                       x-on:blur="setTimeout(() => open = false, 150)"
                       x-on:keydown.escape="open = false; $el.blur()"
                       placeholder="{{ __('Search topics, events, people… e.g. Black Death, French Revolution') }}"
                       autocomplete="off"
                       class="input input-bordered bg-slate-900 mt-1 text-base w-full
                              @if($topicId) border-emerald-500/60 pr-10 @endif" />
                {{-- Locked check when a catalog item is chosen --}}
                @if ($topicId)
                    <svg class="absolute right-3 top-1/2 -translate-y-1/2 mt-0.5 h-5 w-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                @endif
            </div>
            @error('topic') <span class="text-rose-400 text-xs mt-1">{{ $message }}</span> @enderror

            @if ($topicId && $topicWikipediaUrl)
                <span class="text-xs text-emerald-400/80 mt-1">
                    {!! __('Grounded in :link.', ['link' => '<a href="'.e($topicWikipediaUrl).'" target="_blank" rel="noopener" class="underline">'.e(__('this Wikipedia article')).'</a>']) !!}
                </span>
            @elseif (strlen(trim($topic)) >= 2 && !$topicId)
                <span class="text-xs text-amber-400/70 mt-1">{{ __('Select an entry from the list to continue.') }}</span>
            @endif

            {{-- Catalog dropdown --}}
            @if (count($this->topicSuggestions) > 0)
                <ul
                    data-topic-suggestions
                    x-show="open"
                    {{-- This list is INSERTED by a Livewire morph after the teacher focused the
                         input, and the morph can reset/miss the Alpine `open` flag — leaving
                         perfect results hidden while the teacher keeps typing (the dropdown only
                         appeared after blur + refocus). Re-open on insert while the input has
                         focus so results always show the moment they arrive. --}}
                    x-init="if (document.activeElement && document.activeElement.id === 'lw-topic') open = true"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="menu menu-sm bg-base-200 border border-white/10 rounded-box shadow-xl
                           absolute z-50 w-full top-full mt-1 max-h-64 overflow-y-auto"
                    style="display:none"
                >
                    @foreach ($this->topicSuggestions as $s)
                        <li>
                            <button type="button"
                                    wire:click="selectTopic('{{ $s['id'] }}')"
                                    x-on:mousedown.prevent
                                    x-on:click="open = false"
                                    class="flex flex-row items-center gap-2 py-2">
                                @if ($s['type'] === 'figure')
                                    <span class="badge badge-sm badge-outline border-sky-500/40 text-sky-300 shrink-0">
                                        {{ $s['figure_kind'] === 'ruler' ? __('Ruler') : __('Person') }}
                                    </span>
                                @elseif ($s['type'] === 'place')
                                    <span class="badge badge-sm badge-outline border-emerald-500/40 text-emerald-300 shrink-0">
                                        {{ __('Place') }}
                                    </span>
                                @elseif ($s['type'] === 'event')
                                    <span class="badge badge-sm badge-outline border-amber-500/40 text-amber-300 shrink-0">
                                        {{ __('Event') }}
                                    </span>
                                @endif
                                <span class="flex flex-col items-start gap-0.5">
                                    <span class="text-sm text-white">{{ $s['name'] }}</span>
                                    @if ($s['era'] || $s['region'])
                                        <span class="text-xs text-slate-400">{{ collect([$s['era'] ?: null, $s['region'] ?: null])->filter()->implode(' · ') }}</span>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (strlen(trim($topic)) >= 2 && ! $topicId)
                {{-- Empty state: without this, a miss (e.g. "Domtoren") was a silent dead end —
                     no list, no message, just the "select an entry" hint pointing at nothing. --}}
                <div x-show="open"
                     x-init="if (document.activeElement && document.activeElement.id === 'lw-topic') open = true"
                     style="display:none"
                     class="absolute z-50 w-full top-full mt-1 rounded-box border border-white/10 bg-base-200 p-3 shadow-xl">
                    <p class="text-xs text-slate-300">
                        {{ __('No catalog matches for') }} “{{ $topic }}”.
                        {{ __('Pick a broader topic: a place, person or event such as Utrecht. Put the specifics, like a building or object, under “Anything specific?”.') }}
                    </p>
                </div>
            @endif
        </div>

        {{-- Optional focus tags (thematic lenses) — capped at 3 (FocusTags::MAX_TAGS) --}}
        @php
            $focusFull = count($focusTags) >= 3;
            $focusAll = \App\Lessons\FocusTags::all();
        @endphp
        <div class="form-control">
            <div class="flex items-center justify-between gap-3 mb-2">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                    {{ __('Pick a focus (optional)') }}
                </span>
                {{-- Three slots: fill as you pick (up to 3). Click a filled slot to clear it. --}}
                <div class="flex items-center gap-1.5">
                    @for ($i = 0; $i < 3; $i++)
                        @php $slot = $focusTags[$i] ?? null; @endphp
                        @if ($slot)
                            <button type="button" wire:click="toggleFocusTag('{{ $slot }}')"
                                    title="{{ __('Remove') }}"
                                    class="group inline-flex items-center gap-1 rounded-md border border-amber-500/50 bg-amber-500/15 px-2 py-1 text-[11px] font-medium text-amber-300 transition hover:border-rose-400/60 hover:bg-rose-500/10 hover:text-rose-300">
                                {{ __($focusAll[$slot]['label'] ?? $slot) }}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3 opacity-60 group-hover:opacity-100" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        @else
                            <span class="inline-flex h-[26px] w-12 items-center justify-center rounded-md border border-dashed border-slate-600/70 text-slate-600" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </span>
                        @endif
                    @endfor
                </div>
            </div>
            {{-- Once all 3 slots are filled the grid collapses away — the slots above are the
                 control; clear one (its ✕) to bring the choices back. --}}
            @if (! $focusFull)
                <div class="flex flex-wrap gap-2">
                    @foreach ($focusAll as $slug => $tag)
                        @php $isOn = in_array($slug, $focusTags, true); @endphp
                        <button
                            type="button"
                            wire:click="toggleFocusTag('{{ $slug }}')"
                            @class([
                                'btn btn-xs',
                                'btn-primary' => $isOn,
                                'btn-outline' => ! $isOn,
                            ])
                        >
                            {{ __($tag['label']) }}
                        </button>
                    @endforeach
                </div>
            @else
                <span class="text-xs text-slate-500">{{ __('3 of 3 chosen. Clear a slot above to swap.') }}</span>
            @endif
        </div>

        {{-- Optional focus / angle (free text) — focusing the field offers ready-made angles,
             like the topic picker. The teacher can click one or keep typing their own. --}}
        @php
            $angleSuggestions = [
                __('Causes and consequences'),
                __('Key people involved'),
                __('Daily life of ordinary people'),
                __('The turning point'),
                __('Build-up and aftermath'),
                __('Winners and losers'),
                __('Why it still matters today'),
                __('Myths vs. reality'),
            ];
        @endphp
        <div class="form-control relative" x-data="{ open: false }">
            <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                {{ __('Anything specific?') }} <span class="normal-case tracking-normal text-slate-500">· {{ __('optional') }}</span>
            </span>
            <input type="text" name="focus"
                   wire:model.blur="focus"
                   x-on:focus="open = true"
                   x-on:blur="setTimeout(() => open = false, 150)"
                   x-on:keydown.escape="open = false; $el.blur()"
                   placeholder="{{ __('e.g. daily life of a soldier, the road to revolution…') }}"
                   maxlength="200"
                   autocomplete="off"
                   class="input input-bordered bg-slate-900 mt-1 text-sm w-full" />
            @error('focus') <span class="text-rose-400 text-xs mt-1">{{ $message }}</span> @enderror

            {{-- Angle suggestions --}}
            <div x-show="open" style="display:none"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="absolute z-50 top-full mt-1 w-full rounded-box border border-white/10 bg-base-200 p-2 shadow-xl">
                <p class="px-1 pb-1.5 text-[10px] uppercase tracking-wider text-slate-500">{{ __('Popular angles') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($angleSuggestions as $angle)
                        <button type="button"
                                x-on:mousedown.prevent
                                wire:click="$set('focus', @js($angle))"
                                x-on:click="open = false"
                                class="btn btn-xs btn-outline border-white/15 font-normal normal-case text-slate-300 hover:border-amber-500/50 hover:text-amber-300">
                            {{ $angle }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Region & era enrichment --}}
        <div>
            @if (!$show_region_era)
                <button type="button"
                        wire:click="$set('show_region_era', true)"
                        class="btn btn-ghost btn-xs text-slate-400 hover:text-white pl-0 gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ __('Add region & era') }}
                </button>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    <div class="form-control">
                        <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-1">{{ __('Region') }}</span>
                        <x-ui.combobox
                            :options="$this->regionOptions"
                            wire-model="region"
                            :initial-value="$region ?? ''"
                            :placeholder="__('e.g. France, US South, Ottoman Empire…')"
                        />
                    </div>

                    <div class="form-control">
                        <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-1">{{ __('Era') }}</span>
                        @if ($region)
                            <x-ui.combobox
                                :options="$this->eraOptions"
                                wire-model="era"
                                :initial-value="$era ?? ''"
                                :placeholder="__('Select or type an era…')"
                            />
                        @else
                            <input type="text" disabled
                                   placeholder="{{ __('Pick a region first') }}"
                                   class="input input-bordered bg-slate-900 w-full opacity-40 cursor-not-allowed" />
                        @endif
                    </div>

                    <button type="button"
                            wire:click="$set('show_region_era', false)"
                            class="btn btn-ghost btn-xs text-slate-500 hover:text-slate-300 col-span-full w-fit pl-0">
                        ✕ {{ __('Hide') }}
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════
         AUDIENCE (always visible)
    ════════════════════════════════════════════════ --}}
    <div data-flow-group class="wizard-flow-group bg-base-300 rounded-2xl p-6">
        <div class="form-control">
            <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-2">
                {{ __('Target audience') }}
            </span>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 gap-4 content-start">
                {{-- Top-level toggle: Age vs local system --}}
                <div class="flex gap-2 flex-wrap mb-3">
                    <div class="join shrink-0 mt-1">
                        @if ($this->gradeSystem)
                            <button type="button"
                                    wire:click="setAudienceSystem('local')"
                                    @class(['btn btn-sm join-item', 'btn-primary' => $audience_system === 'local', 'btn-outline' => $audience_system !== 'local'])>
                                {{ $this->gradeSystem['label'] }}
                            </button>
                        @endif
                        <button type="button"
                                wire:click="setAudienceSystem('age')"
                                @class(['btn btn-sm join-item', 'btn-primary' => $audience_system === 'age', 'btn-outline' => $audience_system !== 'age'])>
                            {{ __('Age') }}
                        </button>
                    </div>
                    


                    @if ($audience_system === 'age')
                        <div class="flex items-center gap-2">
                            <input type="number"
                                wire:model.live.debounce.300ms="audience_age"
                                min="{{ \App\Livewire\Wizard\Step1Settings::AGE_MIN }}"
                                max="{{ \App\Livewire\Wizard\Step1Settings::AGE_MAX }}"
                                class="input input-bordered bg-slate-900 w-20 text-center" />
                            <span class="text-slate-400 text-sm">{{ __('years old') }}</span>
                        </div>
                        @else
                        <p class="text-xs text-slate-500 mt-2">
                            {!! __('Not your system? Switch to :age instead.', ['age' => '<button type="button" wire:click="setAudienceSystem(\'age\')" class="underline hover:text-slate-300">'.e(__('Age')).'</button>']) !!}
                        </p>
                    @endif
                </div>
                

                {{-- Local grade picker — tiered (NL) or simple dropdown --}}
                @if ($audience_system === 'local' && $this->gradeSystem)

                    @if (($this->gradeSystem['type'] ?? 'simple') === 'tiered')
                        {{-- ── Tiered picker (NL: Basisonderwijs/Middelbare, US: Elementary/Middle/High) ── --}}
                        <div
                            wire:ignore
                            x-data="{
                                system:     {{ Js::from($this->gradeSystem) }},
                                initial:    '{{ $local_grade }}',
                                activeTier: '',
                                grade:      '',
                                track:      '',
                                jaar:       1,
                                get currentTier() {
                                    return this.system.tiers.find(t => t.key === this.activeTier) ?? this.system.tiers[0];
                                },
                                get tierHasOptions() { return !!(this.currentTier.options?.length); },
                                get tierHasTracks()  { return !!(this.currentTier.tracks?.length); },
                                get tierHasYear()    {
                                    const t = this.currentTier.tracks?.find(t => t.key === this.track);
                                    return !!(t?.max_jaar);
                                },
                                get maxJaar() {
                                    const t = this.currentTier.tracks?.find(t => t.key === this.track);
                                    return t?.max_jaar ?? 6;
                                },
                                get jaarOptions() {
                                    return Array.from({length: this.maxJaar}, (_, i) => i + 1);
                                },
                                get value() {
                                    // NL middelbare: tier has only tracks (no options) → track + Jaar
                                    if (!this.tierHasOptions && this.tierHasTracks && this.track) {
                                        return this.track + ' Jaar ' + this.jaar;
                                    }
                                    // US high: grade + optional non-General track
                                    if (this.tierHasOptions && this.tierHasTracks && this.grade) {
                                        return (this.track && this.track !== 'General')
                                            ? this.grade + ' (' + this.track + ')'
                                            : this.grade;
                                    }
                                    // Simple: just grade option
                                    return this.grade;
                                },
                                setTier(key) {
                                    this.activeTier = key;
                                    this.grade = '';
                                    this.track = '';
                                    this.jaar  = 1;
                                    // Default track for tiers that have tracks
                                    const tier = this.system.tiers.find(t => t.key === key);
                                    if (tier?.tracks?.length) {
                                        this.track = tier.tracks.find(t => t.key === 'General')?.key ?? tier.tracks[0].key;
                                    }
                                },
                                pickGrade(val, tierKey) {
                                    this.grade = val;
                                    // Ensure track defaults for tiers that have tracks
                                    const tier = this.system.tiers.find(t => t.key === tierKey);
                                    if (tier?.tracks?.length && !this.track) {
                                        this.track = tier.tracks.find(t => t.key === 'General')?.key ?? tier.tracks[0].key;
                                    }
                                },
                                init() {
                                    this.activeTier = this.system.tiers[0].key;
                                    const v = this.initial;
                                    if (v) {
                                        // NL middelbare pattern: e.g. &quot;HAVO Jaar 3&quot;
                                        if (v.includes(' Jaar ')) {
                                            const parts = v.split(' Jaar ');
                                            const tier = this.system.tiers.find(t => t.tracks?.some(tr => tr.key === parts[0]));
                                            if (tier) { this.activeTier = tier.key; this.track = parts[0]; this.jaar = parseInt(parts[1]) || 1; }
                                        }
                                        // US high with track: e.g. &quot;9th grade (Honors)&quot;
                                        else if (v.includes(' (') && v.endsWith(')')) {
                                            const m = v.match(/^(.+) \((.+)\)$/);
                                            if (m) {
                                                const tier = this.system.tiers.find(t => t.options?.some(o => o.value === m[1]));
                                                if (tier) { this.activeTier = tier.key; this.grade = m[1]; this.track = m[2]; }
                                            }
                                        }
                                        // Simple option (Groep 7, 5th grade, etc.)
                                        else {
                                            const tier = this.system.tiers.find(t => t.options?.some(o => o.value === v));
                                            if (tier) {
                                                this.activeTier = tier.key;
                                                this.grade = v;
                                                if (tier.tracks?.length) {
                                                    this.track = tier.tracks.find(t => t.key === 'General')?.key ?? tier.tracks[0].key;
                                                }
                                            }
                                        }
                                    }
                                    this.$watch('value', val => { if (val) $wire.set('local_grade', val); });
                                }
                            }"
                            x-init="init()"
                            class="space-y-3"
                        >
                            {{-- School level buttons --}}
                            <div class="join">
                                @foreach ($this->gradeSystem['tiers'] as $tierDef)
                                    <button type="button"
                                            x-on:click="setTier('{{ $tierDef['key'] }}')"
                                            :class="activeTier === '{{ $tierDef['key'] }}' ? 'btn-primary' : 'btn-outline'"
                                            class="btn btn-sm join-item">
                                        {{ $tierDef['label'] }}
                                    </button>
                                @endforeach
                            </div>

                            {{-- Grade option buttons (tiers that have an options array) --}}
                            @foreach ($this->gradeSystem['tiers'] as $tierDef)
                                @if (!empty($tierDef['options']))
                                    <div x-show="activeTier === '{{ $tierDef['key'] }}'" class="flex flex-wrap gap-2 py-4">
                                        @foreach ($tierDef['options'] as $opt)
                                            <button type="button"
                                                    x-on:click="pickGrade('{{ $opt['value'] }}', '{{ $tierDef['key'] }}')"
                                                    :class="grade === '{{ $opt['value'] }}' && activeTier === '{{ $tierDef['key'] }}' ? 'border-amber-400 bg-amber-500/10 text-white' : 'border-slate-600 text-slate-300 hover:border-slate-400'"
                                                    class="px-4 py-2 rounded-lg text-sm border-2 transition-all">
                                                {{ $opt['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach

                            {{-- Track buttons for tiers that have tracks but NO options (NL Middelbare) --}}
                            @foreach ($this->gradeSystem['tiers'] as $tierDef)
                                @if (empty($tierDef['options']) && !empty($tierDef['tracks']))
                                    <div x-show="activeTier === '{{ $tierDef['key'] }}'" class="space-y-3">
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($tierDef['tracks'] as $t)
                                                <button type="button"
                                                        x-on:click="track = '{{ $t['key'] }}'; jaar = 1"
                                                        :class="track === '{{ $t['key'] }}' ? 'border-amber-400 bg-amber-500/10 text-white' : 'border-slate-600 text-slate-300 hover:border-slate-400'"
                                                        class="px-4 py-2 rounded-lg text-sm border-2 transition-all">
                                                    {{ $t['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                        {{-- Display only — the SAVED value keeps the literal ' Jaar ' format
                                             (built in get value(), parsed back in init()), so never localize that. --}}
                                        <div x-show="track" class="flex items-center gap-3">
                                            <span class="text-xs uppercase tracking-wider text-slate-400">{{ __('Year') }}</span>
                                            <select x-model="jaar" class="select select-bordered select-sm bg-slate-900">
                                                <template x-for="j in jaarOptions" :key="j">
                                                    <option :value="j" x-text="@js(__('Year')) + ' ' + j"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </div>
                                @endif
                            @endforeach

                            {{-- Track buttons for tiers that have BOTH options AND tracks (US High) --}}
                            @foreach ($this->gradeSystem['tiers'] as $tierDef)
                                @if (!empty($tierDef['options']) && !empty($tierDef['tracks']))
                                    <div x-show="activeTier === '{{ $tierDef['key'] }}' && grade" class="flex flex-wrap gap-2 pt-1">
                                        @foreach ($tierDef['tracks'] as $t)
                                            <button type="button"
                                                    x-on:click="track = '{{ $t['key'] }}'"
                                                    :class="track === '{{ $t['key'] }}' ? 'border-amber-400 bg-amber-500/10 text-white' : 'border-slate-600 text-slate-300 hover:border-slate-400'"
                                                    class="px-3 py-1.5 rounded-lg text-xs border-2 transition-all">
                                                {{ $t['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>

                    @else
                        {{-- ── Simple dropdown (UK Year, FR Classe, etc.) ── --}}
                        <select wire:model.live="local_grade"
                                class="select select-bordered bg-slate-900 w-full max-w-xs">
                            <option value="">{{ __('Select…') }}</option>
                            @foreach ($this->gradeSystem['options'] as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════
         OPTIONAL SECTIONS — DaisyUI collapse accordions
         (final flow group: refinements + the Generate action)
    ════════════════════════════════════════════════ --}}
    <div data-flow-group class="wizard-flow-group space-y-4">

    {{-- Tone & Details --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">{{ __('Tone & details') }}</span>
            <span class="text-slate-400 text-xs">
                @php
                    $toneLabel = $tone && isset($this->tones[$tone])
                        ? $this->tones[$tone]['label']
                        : ($details ? __('Details added') : __('Optional'));
                @endphp
                {{ $toneLabel }}
            </span>
        </div>
        <div class="collapse-content space-y-4">

            {{-- Tone pill picker --}}
            <div>
                <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-2 block">{{ __('Tone') }}</span>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->tones as $key => $t)
                        @php $isRec = in_array($key, $this->recommendedTones); @endphp
                        <div class="relative group">
                            <button
                                type="button"
                                wire:click="$set('tone', '{{ $tone === $key ? '' : $key }}')"
                                @class([
                                    'flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm transition-all duration-150 cursor-pointer',
                                    'bg-amber-400 text-black font-bold border-2 border-amber-400'   => $tone === $key,
                                    'bg-slate-800 border-2 border-amber-400 text-amber-400 font-semibold' => $tone !== $key && $isRec,
                                    'bg-slate-800 border border-slate-600 text-slate-400'            => $tone !== $key && !$isRec,
                                ])
                            >
                                <x-dynamic-component :component="'icons.'.$t['icon']" class="w-3.5 h-3.5" />
                                <span>{{ $t['label'] }}</span>
                                @if ($isRec)
                                    <x-icons.star class="w-3 h-3 text-amber-400" />
                                @endif
                            </button>

                            {{-- Hover tooltip --}}
                            <div class="pointer-events-none absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                                        hidden group-hover:block z-60 w-64
                                        bg-slate-800 border border-slate-600 rounded-lg shadow-xl
                                        px-3 py-2 text-xs text-slate-300 leading-relaxed">
                                {{ $t['description'] }}
                                {{-- Arrow --}}
                                <span class="absolute top-full left-1/2 -translate-x-1/2
                                             border-4 border-transparent border-t-slate-600"></span>
                            </div>
                        </div>
                    @endforeach

                    {{-- No preference --}}
                    <div class="relative group">
                        <button
                            type="button"
                            wire:click="$set('tone', '')"
                            @class([
                                'flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm transition-all duration-150 cursor-pointer',
                                'bg-amber-400 text-black font-bold border-2 border-amber-400'   => $tone === '',
                                'bg-slate-800 border border-slate-600 text-slate-400'            => $tone !== '',
                            ])
                        >
                            <span>{{ __('No preference') }}</span>
                        </button>
                        <div class="pointer-events-none absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                                    hidden group-hover:block z-50 w-52
                                    bg-slate-800 border border-slate-600 rounded-lg shadow-xl
                                    px-3 py-2 text-xs text-slate-300 leading-relaxed">
                            <span class="font-semibold text-white block mb-0.5">{{ __('No preference') }}</span>
                            {{ __('Let the AI pick the best tone for the topic and age group automatically.') }}
                            <span class="absolute top-full left-1/2 -translate-x-1/2
                                         border-4 border-transparent border-t-slate-600"></span>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-amber-500 mt-2 flex items-center gap-1">
                    <x-icons.star class="w-3 h-3 inline-block text-amber-400" />
                    <span>{{ __('Recommended for age :age', ['age' => $audience_age]) }}</span>
                </p>
            </div>

            {{-- Teacher details --}}
            <label class="form-control flex flex-col gap-2" for="lw-details">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">{{ __('Teacher details (optional)') }}</span>
                <textarea id="lw-details" wire:model="details" rows="3"
                          placeholder="{{ __('Extra context, learning goals, things to emphasise…') }}"
                          class="textarea textarea-bordered bg-slate-900 mt-1 w-full"></textarea>
            </label>
        </div>
    </div>

    {{-- Source --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl overflow-visible">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">{{ __('Source') }}</span>
            <span class="text-slate-400 text-xs">
                @if ($source_mode === 'internet')
                    {{ __('Internet') }} <span class="text-slate-600">(worldhistory.org / wikipedia)</span>
                @else
                    {{ __('Local source') }}
                @endif
            </span>
        </div>
        <div class="collapse-content space-y-3">
            <div class="flex flex-wrap gap-3 pt-1">
                <button type="button"
                        wire:click="$set('source_mode', 'internet')"
                        @class([
                            'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                            'border-amber-400 bg-amber-500/10 text-white' => $source_mode === 'internet',
                            'border-slate-600 text-slate-300 hover:border-slate-400' => $source_mode !== 'internet',
                        ])>
                    {{ __('Internet') }}
                    <span class="text-xs opacity-60 ml-1">worldhistory.org / wikipedia</span>
                </button>
                <button type="button"
                        wire:click="$set('source_mode', 'local')"
                        @class([
                            'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                            'border-amber-400 bg-amber-500/10 text-white' => $source_mode === 'local',
                            'border-slate-600 text-slate-300 hover:border-slate-400' => $source_mode !== 'local',
                        ])>
                    {{ __('Local source') }}
                    <span class="text-xs opacity-60 ml-1">{{ __('link or PDF') }}</span>
                </button>
            </div>

            @if ($source_mode === 'local')
                <div class="space-y-2">
                    <input type="url" wire:model.live="source_url"
                           placeholder="{{ __('https://drive.google.com/… (optional)') }}"
                           class="input input-bordered bg-slate-900 w-full text-sm" />
                    <p class="text-xs text-slate-500">{{ __('Or upload a document:') }}</p>
                    <input id="lw-source-upload" type="file"
                           wire:model="sourceUpload" accept=".pdf,.docx"
                           class="file-input file-input-bordered w-full bg-slate-900" />
                    @error('sourceUpload') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>
    </div>

    {{-- Visual Style --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">{{ __('Visual style') }}</span>
            <span class="text-slate-400 text-xs">{{ \App\Services\Support\ImageStyleTemplate::label($image_style) }}</span>
        </div>
        <div class="collapse-content pt-2">
            <x-wizard.style-picker :styles="$this->styleOptions"
                                   :selected="$image_style"
                                   :recommended="$this->recommendedStyles" />
        </div>
    </div>

    {{-- Narrator --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">{{ __('Narrator') }}</span>
            <span class="text-slate-400 text-xs">
                {{ $this->narrators->firstWhere('id', $avatar_id)?->name ?? __('None selected') }}
            </span>
        </div>
        <div class="collapse-content pt-2">
            <x-wizard.narrator-picker :narrators="$this->narrators" :selected-id="$avatar_id" />
            @error('avatar_id') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
        </div>
    </div>

    {{-- Game config moved to the Story step (step 2), pre-paired to the chosen arc. --}}
    @if (false)
    <div class="bg-base-300 rounded-2xl overflow-hidden"
         x-data="{ open: @js((bool) $include_game) }"
         x-on:livewire-update.window="open = $wire.include_game">
        {{-- Header row: title + toggle --}}
        <div class="flex items-center justify-between px-4 py-3 cursor-pointer select-none"
             x-on:click="if($wire.include_game){ open = !open }">
            <span class="font-medium text-white text-sm">Game</span>
            <div class="flex items-center gap-3">
                <span class="text-slate-400 text-xs">
                    @if ($include_game && $game_type)
                        {{ ['quiz' => 'Quiz', 'strategy' => 'Strategy game', 'debate' => 'Debate'][$game_type] ?? $game_type }}
                    @elseif (! $include_game)
                        Off
                    @endif
                </span>
                {{-- Toggle stops propagation so clicking it doesn't also toggle open --}}
                <input type="checkbox" wire:model.live="include_game"
                       x-on:click.stop
                       x-on:change="open = $event.target.checked"
                       class="toggle toggle-primary toggle-sm" />
            </div>
        </div>
        {{-- Accordion body --}}
        <div x-show="open" x-collapse class="px-4 pb-4 space-y-4 border-t border-white/10 pt-3">

            @if ($include_game)
                <div class="space-y-2">
                    <p class="text-xs uppercase tracking-wider text-slate-400">Game type</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (['quiz' => 'Quiz', 'strategy' => 'Strategy game', 'debate' => 'Debate'] as $val => $label)
                            <button type="button"
                                    wire:click="$set('game_type', '{{ $val }}')"
                                    @class([
                                        'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                                        'border-amber-400 bg-amber-500/10 text-white' => $game_type === $val,
                                        'border-slate-600 text-slate-300 hover:border-slate-400' => $game_type !== $val,
                                    ])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($game_type === 'quiz')
                    <div class="border-t border-white/10 pt-4">
                        <div class="flex gap-6">
                            {{-- 1/3: number of questions --}}
                            <div class="w-1/3 space-y-2 shrink-0">
                                <p class="text-xs uppercase tracking-wider text-slate-400">Number of questions</p>
                                <input type="number" wire:model.live="quiz_question_count"
                                       min="1" max="10"
                                       class="input input-bordered bg-slate-900 w-24 text-center" />
                                <p class="text-xs text-slate-500">Always multiple choice.</p>
                            </div>
                            {{-- 2/3: when to ask --}}
                            <div class="flex-1 space-y-2">
                                <p class="text-xs uppercase tracking-wider text-slate-400">When to ask</p>
                                <div class="grid grid-cols-3 gap-2">
                                    @foreach (['during' => 'During lesson', 'after' => 'After lesson', 'both' => 'Both'] as $val => $label)
                                        <button type="button"
                                                wire:click="$set('quiz_timing', '{{ $val }}')"
                                                @class([
                                                    'px-3 py-2 rounded-lg text-sm border-2 transition-all text-center',
                                                    'border-amber-400 bg-amber-500/10 text-white' => $quiz_timing === $val,
                                                    'border-slate-600 text-slate-300 hover:border-slate-400' => $quiz_timing !== $val,
                                                ])>{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($game_type === 'strategy')
                    <div class="border-t border-white/10 pt-4">
                        <x-wizard.game-picker
                            :games="$this->games"
                            :selected-id="$strategy_game_id"
                            :team-count="$team_count"
                            :split-count="$game_split_count" />
                    </div>
                @endif
            @endif
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════
         ACTIONS
    ════════════════════════════════════════════════ --}}
    <div class="relative flex items-center justify-center pt-2">
        <button type="button" wire:click="saveDraft"
                class="btn btn-outline absolute left-0">{{ __('Save as draft') }}</button>
        <button type="button" wire:click="generate"
                wire:loading.attr="disabled" wire:target="generate"
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">
            <span wire:loading.remove wire:target="generate">{{ __('Next: Story') }} →</span>
            <span wire:loading wire:target="generate" class="flex items-center gap-2">
                <span class="w-4 h-4 border-2 border-slate-950/40 border-t-slate-950 rounded-full animate-spin"></span>
                {{ __('Saving…') }}
            </span>
        </button>
    </div>

    </div>{{-- /final flow group --}}

    @if ($errors->any())
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-xl p-4 text-sm text-rose-200 space-y-1">
            <p class="font-semibold">{{ __('Cannot continue yet. Fix these first:') }}</p>
            <ul class="list-disc ml-5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Progressive-disclosure flow: each setting group is ~75vh so one is in focus at a time;
         the others dim. Picking a topic/story auto-scrolls + focuses the next group.
         The CSS lives in app.css (not an inline <style>) so Livewire morphs on the
         wire:model.live topic field can't strip it mid-session. --}}
    <script>
        window.wizardFlow = window.wizardFlow || function () {
            return {
                groups: [],
                _io: null,
                _ratios: null,
                init() {
                    this.groups = Array.from(this.$el.querySelectorAll('[data-flow-group]'));
                    this._ratios = new Map();
                    // Whichever group is MOST visible is "active"; dim the rest.
                    //
                    // This deliberately keeps a running ratio per group instead of activating straight
                    // from an entry. Two bugs came from doing that:
                    //   - groups are 75vh inside an ~84vh band, so TWO adjacent ones can both exceed
                    //     any fixed threshold at once and whichever entry landed last in the batch won;
                    //   - activation only ever fired on the way IN, so when the active group fell below
                    //     the threshold without another crossing up through it, the highlight got stuck
                    //     on a group that was scrolled off screen while the teacher typed into a dimmed
                    //     field. Recomputing the max after every batch cannot get stuck.
                    this._io = new IntersectionObserver((entries) => {
                        entries.forEach((e) => this._ratios.set(e.target, e.intersectionRatio));
                        this._activateMostVisible();
                    }, { threshold: [0, 0.25, 0.5, 0.75, 1], rootMargin: '-8% 0px -8% 0px' });
                    this.groups.forEach((g) => this._io.observe(g));
                    if (this.groups[0]) this._activate(this.groups[0]);

                    // Whatever the teacher is actually typing in wins over pure scroll position —
                    // a focused field must never sit in a dimmed group.
                    this._onFocus = (e) => {
                        const g = this.groups.find((x) => x.contains(e.target));
                        if (g) this._activate(g);
                    };
                    this.$el.addEventListener('focusin', this._onFocus);
                },
                destroy() {
                    this._io && this._io.disconnect();
                    this._onFocus && this.$el.removeEventListener('focusin', this._onFocus);
                },
                _activateMostVisible() {
                    let best = null, bestRatio = 0;
                    this.groups.forEach((g) => {
                        const r = this._ratios.get(g) ?? 0;
                        if (r > bestRatio) { bestRatio = r; best = g; }
                    });
                    // Nothing meaningfully on screen (mid-fling, or the page is scrolled past the
                    // form) — keep the current highlight rather than dimming every group at once.
                    if (best) this._activate(best);
                },
                _activate(el) {
                    this.groups.forEach((g) => g.classList.toggle('flow-dim', g !== el));
                },
                // Scroll to the group after the active one and focus its first field.
                advance() {
                    const active = this.groups.find((g) => !g.classList.contains('flow-dim')) || this.groups[0];
                    const next = this.groups[this.groups.indexOf(active) + 1];
                    if (!next) return;
                    next.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    setTimeout(() => {
                        const f = next.querySelector('input:not([type=checkbox]):not([disabled]), select, textarea, [contenteditable]');
                        if (f) f.focus({ preventScroll: true });
                    }, 550);
                },
            };
        };
    </script>
</div>
