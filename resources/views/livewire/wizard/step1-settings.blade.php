<div class="space-y-4 pt-6">

    {{-- ═══════════════════════════════════════════════
         TOPIC (always visible, hero field)
    ════════════════════════════════════════════════ --}}
    <div class="bg-base-300 rounded-2xl p-6 space-y-3">

        {{-- Topic — locked to the curated, Wikipedia-grounded catalog (A1) --}}
        <div x-data="{ open: false }" class="relative form-control">
            <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                Topic <span class="text-amber-400/70 normal-case tracking-normal">· pick from the catalog</span>
            </span>
            <div class="relative">
                <input id="lw-topic" name="topic" type="text"
                       wire:model.live.debounce.250ms="topic"
                       x-on:focus="open = true"
                       x-on:blur="setTimeout(() => open = false, 150)"
                       x-on:keydown.escape="open = false; $el.blur()"
                       placeholder="Search empires, kingdoms, rulers… e.g. Roman Empire"
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
                    Grounded in <a href="{{ $topicWikipediaUrl }}" target="_blank" rel="noopener" class="underline">this Wikipedia article</a>.
                </span>
            @elseif (strlen(trim($topic)) >= 2 && !$topicId)
                <span class="text-xs text-amber-400/70 mt-1">Select an entry from the list to continue.</span>
            @endif

            {{-- Catalog dropdown --}}
            @if (count($this->topicSuggestions) > 0)
                <ul
                    data-topic-suggestions
                    x-show="open"
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
                                        {{ $s['figure_kind'] === 'ruler' ? 'Ruler' : 'Person' }}
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
            @endif
        </div>

        {{-- Optional focus / angle (free text — the only free-text in topic selection) --}}
        <div class="form-control">
            <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                Focus / angle <span class="normal-case tracking-normal text-slate-500">· optional</span>
            </span>
            <input type="text" name="focus"
                   wire:model.blur="focus"
                   placeholder="e.g. daily life of a soldier, the road to revolution…"
                   maxlength="200"
                   class="input input-bordered bg-slate-900 mt-1 text-sm w-full" />
            @error('focus') <span class="text-rose-400 text-xs mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- Region & era enrichment --}}
        <div>
            @if (!$show_region_era)
                <button type="button"
                        wire:click="$set('show_region_era', true)"
                        class="btn btn-ghost btn-xs text-slate-400 hover:text-white pl-0 gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add region &amp; era
                </button>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    <div class="form-control">
                        <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-1">Region</span>
                        <x-ui.combobox
                            :options="$this->regionOptions"
                            wire-model="region"
                            :initial-value="$region ?? ''"
                            placeholder="e.g. France, US South, Ottoman Empire…"
                        />
                    </div>

                    <div class="form-control">
                        <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-1">Era</span>
                        @if ($region)
                            <x-ui.combobox
                                :options="$this->eraOptions"
                                wire-model="era"
                                :initial-value="$era ?? ''"
                                placeholder="Select or type an era…"
                            />
                        @else
                            <input type="text" disabled
                                   placeholder="Pick a region first"
                                   class="input input-bordered bg-slate-900 w-full opacity-40 cursor-not-allowed" />
                        @endif
                    </div>

                    <button type="button"
                            wire:click="$set('show_region_era', false)"
                            class="btn btn-ghost btn-xs text-slate-500 hover:text-slate-300 col-span-full w-fit pl-0">
                        ✕ Hide
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════
         AUDIENCE (always visible)
    ════════════════════════════════════════════════ --}}
    <div class="bg-base-300 rounded-2xl p-6">
        <div class="form-control">
            <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-2">
                Target audience
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
                            Age
                        </button>
                    </div>
                    


                    @if ($audience_system === 'age')
                        <div class="flex items-center gap-2">
                            <input type="number"
                                wire:model.live.debounce.300ms="audience_age"
                                min="{{ \App\Livewire\Wizard\Step1Settings::AGE_MIN }}"
                                max="{{ \App\Livewire\Wizard\Step1Settings::AGE_MAX }}"
                                class="input input-bordered bg-slate-900 w-20 text-center" />
                            <span class="text-slate-400 text-sm">years old</span>
                        </div>
                        @else
                        <p class="text-xs text-slate-500 mt-2">
                            Not your system? Switch to <button type="button" wire:click="setAudienceSystem('age')" class="underline hover:text-slate-300">Age</button> instead.
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
                                        <div x-show="track" class="flex items-center gap-3">
                                            <span class="text-xs uppercase tracking-wider text-slate-400">Jaar</span>
                                            <select x-model="jaar" class="select select-bordered select-sm bg-slate-900">
                                                <template x-for="j in jaarOptions" :key="j">
                                                    <option :value="j" x-text="'Jaar ' + j"></option>
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
                            <option value="">— select —</option>
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
    ════════════════════════════════════════════════ --}}

    {{-- Tone & Details --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">Tone &amp; details</span>
            <span class="text-slate-400 text-xs">
                @php
                    $toneLabel = $tone && isset($this->tones[$tone])
                        ? $this->tones[$tone]['emoji'] . ' ' . $this->tones[$tone]['label']
                        : ($details ? 'Details added' : 'Optional');
                @endphp
                {{ $toneLabel }}
            </span>
        </div>
        <div class="collapse-content space-y-4">

            {{-- Tone pill picker --}}
            <div>
                <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-2 block">Tone</span>
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
                                <span>{{ $t['emoji'] }}</span>
                                <span>{{ $t['label'] }}</span>
                                @if ($isRec)
                                    <span class="text-xs">⭐</span>
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
                            <span>— No preference</span>
                        </button>
                        <div class="pointer-events-none absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                                    hidden group-hover:block z-50 w-52
                                    bg-slate-800 border border-slate-600 rounded-lg shadow-xl
                                    px-3 py-2 text-xs text-slate-300 leading-relaxed">
                            <span class="font-semibold text-white block mb-0.5">No preference</span>
                            Let the AI pick the best tone for the topic and age group automatically.
                            <span class="absolute top-full left-1/2 -translate-x-1/2
                                         border-4 border-transparent border-t-slate-600"></span>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-amber-500 mt-2">⭐ Recommended for Age {{ $audience_age }}</p>
            </div>

            {{-- Teacher details --}}
            <label class="form-control flex flex-col gap-2" for="lw-details">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Teacher details (optional)</span>
                <textarea id="lw-details" wire:model="details" rows="3"
                          placeholder="Extra context, learning goals, things to emphasise…"
                          class="textarea textarea-bordered bg-slate-900 mt-1 w-full"></textarea>
            </label>
        </div>
    </div>

    {{-- Source --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl overflow-visible">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">Source</span>
            <span class="text-slate-400 text-xs">
                @if ($source_mode === 'internet')
                    Internet <span class="text-slate-600">(worldhistory.org / wikipedia)</span>
                @else
                    Local source
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
                    Internet
                    <span class="text-xs opacity-60 ml-1">worldhistory.org / wikipedia</span>
                </button>
                <button type="button"
                        wire:click="$set('source_mode', 'local')"
                        @class([
                            'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                            'border-amber-400 bg-amber-500/10 text-white' => $source_mode === 'local',
                            'border-slate-600 text-slate-300 hover:border-slate-400' => $source_mode !== 'local',
                        ])>
                    Local source
                    <span class="text-xs opacity-60 ml-1">link or PDF</span>
                </button>
            </div>

            @if ($source_mode === 'local')
                <div class="space-y-2">
                    <input type="url" wire:model.live="source_url"
                           placeholder="https://drive.google.com/… (optional)"
                           class="input input-bordered bg-slate-900 w-full text-sm" />
                    <p class="text-xs text-slate-500">Or upload a document:</p>
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
            <span class="font-medium text-white text-sm">Visual style</span>
            <span class="text-slate-400 text-xs capitalize">{{ $image_style }}</span>
        </div>
        <div class="collapse-content pt-2">
            <x-wizard.style-picker :styles="$this->styleOptions"
                                   :selected="$image_style"
                                   :recommended="$this->recommendedStyles" />
        </div>
    </div>

    {{-- Avatar --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">Avatar</span>
            <span class="text-slate-400 text-xs">
                {{ $this->avatars->firstWhere('id', $avatar_id)?->name ?? 'None selected' }}
            </span>
        </div>
        <div class="collapse-content pt-2">
            <x-wizard.avatar-picker :avatars="$this->avatars" :selected-id="$avatar_id" />
            @error('avatar_id') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
        </div>
    </div>

    {{-- Game --}}
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

    {{-- ═══════════════════════════════════════════════
         ACTIONS
    ════════════════════════════════════════════════ --}}
    <div class="flex justify-end gap-3 pt-2">
        <button type="button" wire:click="saveDraft"
                class="btn btn-outline">Save as draft</button>
        <button type="button" wire:click="generate"
                wire:loading.attr="disabled" wire:target="generate"
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">
            <span wire:loading.remove wire:target="generate">Next: Story →</span>
            <span wire:loading wire:target="generate" class="flex items-center gap-2">
                <span class="w-4 h-4 border-2 border-slate-950/40 border-t-slate-950 rounded-full animate-spin"></span>
                Saving…
            </span>
        </button>
    </div>

    @if ($errors->any())
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-xl p-4 text-sm text-rose-200 space-y-1">
            <p class="font-semibold">Cannot continue yet — fix these:</p>
            <ul class="list-disc ml-5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

</div>
