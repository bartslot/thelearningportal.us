<div class="space-y-4 pt-6">

    {{-- ═══════════════════════════════════════════════
         TOPIC (always visible, hero field)
    ════════════════════════════════════════════════ --}}
    <div class="bg-base-300 rounded-2xl p-6 space-y-3">

        {{-- Topic with typeahead --}}
        <div x-data="{ open: false }" class="relative form-control">
            <span class="label-text text-xs uppercase tracking-wider text-slate-400">Topic</span>
            <input id="lw-topic" name="topic" type="text"
                   wire:model.live.debounce.250ms="topic"
                   x-on:focus="open = true"
                   x-on:blur="setTimeout(() => open = false, 150)"
                   x-on:keydown.escape="open = false; $el.blur()"
                   placeholder="e.g. French Revolution, Civil Rights Movement…"
                   autocomplete="off"
                   class="input input-bordered bg-slate-900 mt-1 text-base w-full" />
            @error('topic') <span class="text-rose-400 text-xs mt-1">{{ $message }}</span> @enderror

            {{-- Suggestions dropdown --}}
            @if (count($this->topicSuggestions) > 0)
                <ul
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
                                    wire:click="selectTopicSuggestion(
                                        '{{ addslashes($s['topic']) }}',
                                        '{{ $s['region'] }}',
                                        '{{ addslashes($s['era']) }}'
                                    )"
                                    x-on:mousedown.prevent
                                    class="flex flex-col items-start gap-0.5 py-2">
                                <span class="text-sm text-white">{{ $s['topic'] }}</span>
                                @if ($s['region'] || $s['era'])
                                    @php
                                        $regionLabel = collect(\App\Services\Support\HistoryTaxonomy::regionsFor('en'))
                                            ->firstWhere('value', $s['region'])['label'] ?? $s['region'];
                                        $hint = collect([$regionLabel ?: null, $s['era'] ?: null])->filter()->implode(' · ');
                                    @endphp
                                    <span class="text-xs text-slate-400">{{ $hint }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Region & era enrichment --}}
        <div x-data="{ open: @js($show_region_era) }">

            <button type="button"
                    x-show="!open"
                    x-on:click="open = true"
                    class="btn btn-ghost btn-xs text-slate-400 hover:text-white pl-0 gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add region &amp; era
            </button>

            <div x-show="open"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2"
                 style="display:none">

                <div class="form-control">
                    <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-1">Region</span>
                    <x-ui.combobox
                        :options="$this->regionOptions"
                        wire-model="region"
                        placeholder="e.g. France, US South, Ottoman Empire…"
                    />
                </div>

                <div class="form-control">
                    <span class="label-text text-xs uppercase tracking-wider text-slate-400 mb-1">Era</span>
                    @if ($region)
                        <x-ui.combobox
                            :options="$this->eraOptions"
                            wire-model="era"
                            placeholder="Select or type an era…"
                        />
                    @else
                        <input type="text" disabled
                               placeholder="Pick a region first"
                               class="input input-bordered bg-slate-900 w-full opacity-40 cursor-not-allowed" />
                    @endif
                </div>

                <button type="button"
                        x-on:click="open = false"
                        class="btn btn-ghost btn-xs text-slate-500 hover:text-slate-300 col-span-full w-fit pl-0">
                    ✕ Hide
                </button>
            </div>
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

            {{-- Top-level toggle: Age vs local system --}}
            <div class="flex gap-2 flex-wrap items-center mb-3">
                <div class="join shrink-0">
                    <button type="button"
                            wire:click="setAudienceSystem('age')"
                            @class(['btn btn-sm join-item', 'btn-primary' => $audience_system === 'age', 'btn-outline' => $audience_system !== 'age'])>
                        Age
                    </button>
                    @if ($this->gradeSystem)
                        <button type="button"
                                wire:click="setAudienceSystem('local')"
                                @class(['btn btn-sm join-item', 'btn-primary' => $audience_system === 'local', 'btn-outline' => $audience_system !== 'local'])>
                            {{ $this->gradeSystem['label'] }}
                        </button>
                    @endif
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
                @endif
            </div>

            {{-- Local grade picker — tiered (NL) or simple dropdown --}}
            @if ($audience_system === 'local' && $this->gradeSystem)

                @if (($this->gradeSystem['type'] ?? 'simple') === 'tiered')
                    {{-- ── Tiered picker (NL: Basisonderwijs/Middelbare, US: Elementary/Middle/High) ── --}}
                    <div
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
                                <div x-show="activeTier === '{{ $tierDef['key'] }}'" class="flex flex-wrap gap-2">
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

                <p class="text-xs text-slate-500 mt-2">
                    Not your system? Switch to <button type="button" wire:click="setAudienceSystem('age')" class="underline hover:text-slate-300">Age</button> instead.
                </p>
            @endif
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
                {{ $tone ?: ($details ? 'Details added' : 'Optional') }}
            </span>
        </div>
        <div class="collapse-content space-y-3">
            <label class="form-control" for="lw-tone">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Tone (optional)</span>
                <input id="lw-tone" type="text" wire:model="tone"
                       placeholder="e.g. dramatic, Socratic, humorous…"
                       class="input input-bordered bg-slate-900 mt-1" />
            </label>
            <label class="form-control" for="lw-details">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Teacher details (optional)</span>
                <textarea id="lw-details" wire:model="details" rows="3"
                          placeholder="Extra context, learning goals, things to emphasise…"
                          class="textarea textarea-bordered bg-slate-900 mt-1"></textarea>
            </label>
        </div>
    </div>

    {{-- Source --}}
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">Source</span>
            <span class="text-slate-400 text-xs">
                @php $sourceLabels = ['wikipedia' => 'Wikipedia only', 'upload' => 'My document', 'both' => 'Both combined']; @endphp
                {{ $sourceLabels[$source_mode] ?? 'Wikipedia only' }}
            </span>
        </div>
        <div class="collapse-content space-y-3">
            <div class="flex flex-wrap gap-3 pt-1">
                @foreach (['wikipedia' => 'Wikipedia only', 'upload' => 'My document only', 'both' => 'Both combined'] as $val => $label)
                    <button type="button"
                            wire:click="$set('source_mode', '{{ $val }}')"
                            @class([
                                'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                                'border-amber-400 bg-amber-500/10 text-white' => $source_mode === $val,
                                'border-slate-600 text-slate-300 hover:border-slate-400' => $source_mode !== $val,
                            ])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($source_mode !== 'wikipedia')
                <input id="lw-source-upload" type="file"
                       wire:model="sourceUpload" accept=".pdf,.docx"
                       class="file-input file-input-bordered w-full bg-slate-900" />
                @error('sourceUpload') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
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
    <div class="collapse collapse-arrow bg-base-300 rounded-2xl">
        <input type="checkbox" />
        <div class="collapse-title flex items-center justify-between pr-10">
            <span class="font-medium text-white text-sm">Game</span>
            <span class="text-slate-400 text-xs">
                @if ($include_game && $game_type)
                    {{ ['quiz' => 'Quiz', 'strategy' => 'Strategy', 'debate' => 'Debate'][$game_type] }}
                @elseif ($include_game)
                    On — pick a type
                @else
                    No game
                @endif
            </span>
        </div>
        <div class="collapse-content space-y-4 pt-2">

            <label class="label cursor-pointer justify-start gap-3">
                <span class="label-text">Include a game in this lesson</span>
                <input type="checkbox" wire:model.live="include_game"
                       class="toggle toggle-primary" />
            </label>

            @if ($include_game)
                <div class="space-y-2">
                    <p class="text-xs uppercase tracking-wider text-slate-400">Game type</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (['quiz' => 'Quiz', 'strategy' => 'Strategy and critical thinking', 'debate' => 'Debate'] as $val => $label)
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
                    <div class="space-y-4 border-t border-white/10 pt-4">
                        <div class="space-y-2">
                            <p class="text-xs uppercase tracking-wider text-slate-400">Number of questions</p>
                            <input type="number" wire:model.live="quiz_question_count"
                                   min="1" max="20"
                                   class="input input-bordered bg-slate-900 w-24 text-center" />
                            <p class="text-xs text-slate-500">Questions are always multiple choice.</p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase tracking-wider text-slate-400">When to ask</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach (['during' => 'During lesson', 'after' => 'After lesson', 'both' => 'Both'] as $val => $label)
                                    <button type="button"
                                            wire:click="$set('quiz_timing', '{{ $val }}')"
                                            @class([
                                                'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                                                'border-amber-400 bg-amber-500/10 text-white' => $quiz_timing === $val,
                                                'border-slate-600 text-slate-300 hover:border-slate-400' => $quiz_timing !== $val,
                                            ])>{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                @if ($game_type === 'strategy')
                    <div class="space-y-2 border-t border-white/10 pt-4">
                        <p class="text-xs uppercase tracking-wider text-slate-400">Strategy game</p>
                        <select wire:model.live="strategy_game"
                                class="select select-bordered bg-slate-900 w-full">
                            <option value="">— select a game —</option>
                            @foreach ($this->games as $game)
                                <option value="{{ $game->id }}">{{ $game->title }}</option>
                            @endforeach
                        </select>
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
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">
            Generate lesson →
        </button>
    </div>

    @if ($errors->any())
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-xl p-4 text-sm text-rose-200 space-y-1">
            <p class="font-semibold">Cannot generate yet — fix these:</p>
            <ul class="list-disc ml-5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

</div>
