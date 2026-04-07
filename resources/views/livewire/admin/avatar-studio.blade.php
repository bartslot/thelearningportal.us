<div class="space-y-10" x-data="{ activeTab: 'voice' }">

    {{-- ── Header ──────────────────────────────────────────────────────────── --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-center gap-5">
            <img
                src="{{ $avatar->portraitUrl() }}"
                alt="{{ $avatar->name }}"
                class="h-20 w-20 rounded-2xl object-cover border-2 border-amber-500/30"
            >
            <div>
                <p class="text-xs uppercase tracking-widest text-amber-400">Avatar Studio · Admin</p>
                <h1 class="mt-1 text-3xl font-semibold text-slate-100">{{ $avatar->name }}</h1>
                <p class="mt-1 text-sm text-slate-400">{{ $avatar->description }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-medium border
                {{ $avatar->is_active ? 'bg-emerald-950/60 border-emerald-700 text-emerald-300' : 'bg-slate-900 border-slate-700 text-slate-500' }}">
                {{ $avatar->is_active ? 'Active' : 'Inactive' }}
            </span>
            <span class="text-xs text-slate-600">·</span>
            <span class="text-xs text-slate-500 capitalize">{{ $avatar->subject === 'all' ? 'All subjects' : $avatar->subject }}</span>
        </div>
    </div>
    
        {{-- ── Flash message ────────────────────────────────────────────────────── --}}
        @if($flashMessage)
            <div class="rounded-xl border px-4 py-3 text-sm
                {{ $flashError ? 'border-rose-700 bg-rose-950/40 text-rose-300' : 'border-emerald-700 bg-emerald-950/40 text-emerald-300' }}">
                {{ $flashMessage }}
            </div>
        @endif

        {{-- ── Tab bar ─────────────────────────────────────────────────────────── --}}
        <div class="flex gap-1 border-b border-slate-800">
            @foreach([['greeting', '👋 Greeting'], ['voice', '🎙️ Voice Studio'], ['settings', '⚙️ Settings'], ['samples', '🎧 All samples']] as [$tab, $label])
                <button
                    @click="activeTab = '{{ $tab }}'"
                    :class="activeTab === '{{ $tab }}'
                        ? 'border-amber-400 text-amber-400'
                        : 'border-transparent text-slate-500 hover:text-slate-300'"
                    class="border-b-2 px-4 py-3 text-sm font-medium transition-colors -mb-px"
                >{{ $label }}</button>
            @endforeach
        </div>
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        {{-- Container y scroll                                                          --}}
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        <div class="h-[66vh] overflow-y-auto rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        {{-- TAB: Greeting                                                          --}}
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        <div x-show="activeTab === 'greeting'" x-cloak class="space-y-6 max-w-2xl">

            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-slate-200">Teacher greeting</h2>
                <p class="text-xs text-slate-500">
                    When a teacher logs in for the first time, they hear the avatar introduce itself personally.
                    Preview and generate that greeting here.
                </p>

                {{-- Preview text (live) --}}
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Greeting text (live preview)</p>
                    <p class="text-sm text-slate-200 italic leading-6">"{{ $this->greetingText }}"</p>
                </div>

                {{-- Teacher name input --}}
                <div>
                    <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-400">
                        Teacher's first name (for preview)
                    </label>
                    <input
                        wire:model.live="greetingTeacherName"
                        type="text"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-slate-100
                            placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        placeholder="Sarah"
                    >
                    <p class="mt-1.5 text-xs text-slate-600">This is just for your preview — each teacher gets their own real name.</p>
                </div>

                {{-- Generate greeting --}}
                <div class="flex items-center gap-3">
                    <button
                        wire:click="generateGreeting"
                        wire:loading.attr="disabled"
                        wire:target="generateGreeting"
                        class="rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-950
                            hover:bg-amber-400 transition-colors disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="generateGreeting">▶ Generate greeting audio</span>
                        <span wire:loading wire:target="generateGreeting" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Generating…
                        </span>
                    </button>
                    <p class="text-xs text-slate-600">Uses the active voice settings.</p>
                </div>

                {{-- Audio result --}}
                @if($greetingAudioUrl)
                    <div
                        wire:key="greeting-player-{{ md5((string) $greetingAudioUrl) }}"
                        class="rounded-xl border border-emerald-800 bg-emerald-950/30 p-4 space-y-3"
                    >
                        <p class="text-xs text-emerald-400 font-medium">Greeting generated — listen below:</p>
                    <div wire:ignore>
                        <x-audio-player
                            :src="$greetingAudioUrl"
                            label="Greeting preview"
                            :transcript="$this->greetingText"
                            :word-timings="$greetingWordTimings"
                        />
                    </div>
                        <p class="text-xs text-slate-600">
                            Happy with this voice?
                            Go to <button @click="activeTab = 'settings'" class="text-amber-400 underline">Settings</button>
                            and confirm the voice provider and ID are saved.
                        </p>
                    </div>
                @endif
            </div>

            {{-- Tip about the avatar title and short name --}}
            <div class="rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-xs text-slate-500 space-y-1.5">
                <p class="text-slate-400 font-medium">Greeting uses:</p>
                <p>· <span class="text-slate-300">Short name</span> → "I am <em>{{ $avatar->short_name ?: $avatar->name }}</em>"</p>
                <p>· <span class="text-slate-300">Avatar title</span> → "a <em>{{ $avatar->avatar_title ?: 'Professor' }}</em> here at The History Portal"</p>
                <p class="pt-1">Edit both in the <button @click="activeTab = 'settings'" class="text-amber-400 underline">Settings tab</button>.</p>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        {{-- TAB: Voice Studio                                                      --}}
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        <div x-show="activeTab === 'voice'" x-cloak class="space-y-8">

            {{-- Provider toggle --}}
            <div class="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-900/40 p-1 w-fit">
                @foreach(['edge_tts' => '★ edge-tts (free)', 'kokoro' => 'Kokoro (local)'] as $prov => $label)
                    <button
                        wire:click="$set('previewProvider', '{{ $prov }}')"
                        class="rounded-lg px-4 py-2 text-xs font-medium transition-colors
                            {{ $previewProvider === $prov
                                ? 'bg-amber-500 text-slate-950'
                                : 'text-slate-400 hover:text-slate-200' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>

            {{-- Current active voice --}}
            <div class="rounded-2xl border border-amber-800/40 bg-amber-950/20 p-5 flex items-center gap-4">
                <span class="text-2xl">🎙️</span>
                <div>
                    <p class="text-xs text-amber-400 uppercase tracking-widest mb-0.5">Active voice</p>
                    <p class="text-sm font-medium text-slate-100">
                        {{ $this->voices[$voice_id] ?? $voice_id }}
                        <span class="text-slate-500">· speed {{ $voice_speed }}×</span>
                    </p>
                </div>
            </div>

            {{-- Generate preview controls ---------------------------------------- --}}
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-slate-200">Generate voice samples</h2>
                <p class="text-xs text-slate-500">
                    Pick a voice and speed, choose a phrase, then listen. Click "Use this voice" to make it active.
                </p>

                {{-- Voice selector + speed --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-400">Voice</label>
                        <select
                            wire:model.live="previewVoiceId"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        >
                            @foreach($this->voices as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-400">
                            Speed: <span class="text-amber-400">{{ $previewVoiceSpeed }}×</span>
                        </label>
                        <input
                            type="range"
                            wire:model.live="previewVoiceSpeed"
                            min="0.5" max="1.5" step="0.05"
                            class="w-full accent-amber-400"
                        >
                        <div class="flex justify-between text-xs text-slate-600 mt-1">
                            <span>0.5× slow</span><span>1.0× normal</span><span>1.5× fast</span>
                        </div>
                    </div>
                </div>

                {{-- Standard phrases --}}
                <div class="space-y-2">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Standard phrases</p>
                    @foreach($this->samplePhrases as $i => $phrase)
                        <div class="flex items-start gap-3 rounded-xl border border-slate-800 bg-slate-950/40 p-3">
                            <p class="flex-1 text-sm text-slate-300 leading-5">{{ $phrase }}</p>
                            <button
                                wire:click="generateSample('{{ addslashes($phrase) }}', '{{ $previewVoiceId }}', {{ $previewVoiceSpeed }})"
                                wire:loading.attr="disabled"
                                class="flex-shrink-0 rounded-lg border border-slate-700 px-3 py-1.5 text-xs text-slate-400
                                    hover:border-amber-500 hover:text-amber-400 transition-colors disabled:opacity-40"
                            >
                                <span wire:loading.remove wire:target="generateSample">▶ Generate</span>
                                <span wire:loading wire:target="generateSample" class="flex items-center gap-1">
                                    <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    Working...
                                </span>
                            </button>
                        </div>
                    @endforeach
                </div>

                {{-- Custom phrase --}}
                <div class="border-t border-slate-800 pt-5 space-y-3">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Custom phrase</p>
                    <div class="flex gap-3">
                        <input
                            wire:model="customPhrase"
                            type="text"
                            class="flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-slate-100
                                placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="Type any phrase to hear the Professor say it..."
                        >
                        <button
                            wire:click="generateCustomSample"
                            wire:loading.attr="disabled"
                            class="flex-shrink-0 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-slate-950
                                hover:bg-amber-400 transition-colors disabled:opacity-50"
                        >▶ Generate</button>
                    </div>
                    @error('customPhrase') <p class="text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                {{-- Batch: all voices with phrase 1 --}}
                <div class="border-t border-slate-800 pt-5">
                    <button
                        wire:click="generateAllVoiceSamples"
                        wire:loading.attr="disabled"
                        wire:confirm="This will generate {{ count($this->voices) }} audio clips (one per voice). Takes a minute. Continue?"
                        class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm text-slate-400
                            hover:border-amber-500 hover:text-amber-400 transition-colors disabled:opacity-40"
                    >
                        Generate all {{ count($this->voices) }} voices with sample phrase 1
                    </button>
                    <p class="mt-1.5 text-xs text-slate-600">Useful for a side-by-side comparison of every available voice.</p>
                </div>
            </div>

            {{-- Recent samples for this avatar ---------------------------------------------- --}}
            @if($this->voiceSamples->isNotEmpty())
                <div class="space-y-3">
                    <h2 class="text-sm font-semibold text-slate-200">Generated samples</h2>
                    @foreach($this->voiceSamples->take(20) as $sample)
                        <div
                            wire:key="voice-sample-recent-{{ $sample->id }}-{{ md5((string) $sample->audio_path) }}"
                            class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4 space-y-3"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-slate-400">{{ $sample->label() }}</p>
                                    <p class="text-sm text-slate-300 mt-0.5 truncate">{{ $sample->phrase }}</p>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    @if($sample->voice_id === $avatar->voice_id && round($sample->voice_speed, 2) === round($avatar->voice_speed, 2))
                                        <span class="text-xs text-amber-400 font-medium">● Active</span>
                                    @else
                                        <button
                                            wire:click="applyVoice({{ $sample->id }})"
                                            class="rounded-lg border border-emerald-700 px-3 py-1 text-xs text-emerald-400
                                                hover:bg-emerald-950/40 transition-colors"
                                        >Use this voice</button>
                                    @endif
                                    <button
                                        wire:click="deleteSample({{ $sample->id }})"
                                        wire:confirm="Delete this sample?"
                                        class="rounded-lg border border-slate-700 px-2 py-1 text-xs text-slate-500
                                            hover:border-rose-700 hover:text-rose-400 transition-colors"
                                    >✕</button>
                                </div>
                            </div>

                            <div wire:ignore>
                            <x-audio-player
                                :src="$sample->audioUrl()"
                                :mime="$sample->audio_extension === 'm4a' ? 'audio/mp4' : 'audio/mpeg'"
                                :transcript="$sample->phrase"
                                :word-timings="$sample->settings_snapshot['word_timings'] ?? []"
                            />
                        </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-slate-700 p-8 text-center">
                    <p class="text-2xl mb-2">🎙️</p>
                    <p class="text-sm text-slate-400">No samples yet — generate one above to start auditioning voices.</p>
                </div>
            @endif
        </div>

        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        {{-- TAB: Settings                                                          --}}
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        <div x-show="activeTab === 'settings'" x-cloak>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-8 space-y-6 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-200">Avatar settings</h2>

                {{-- Name --}}
                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Full name</label>
                    <input wire:model="name" type="text"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        placeholder="The Professor">
                    @error('name') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                {{-- Short name + title (greeting) --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                            Short name <span class="text-slate-600 normal-case font-normal tracking-normal">(greeting)</span>
                        </label>
                        <input wire:model.live="short_name" type="text"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                    focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="The Professor">
                        <p class="mt-1 text-xs text-slate-600">"I am <em>{{ $short_name ?: $name }}</em>"</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                            Avatar title <span class="text-slate-600 normal-case font-normal tracking-normal">(greeting)</span>
                        </label>
                        <input wire:model.live="avatar_title" type="text"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                    focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="History Professor">
                        <p class="mt-1 text-xs text-slate-600">"a <em>{{ $avatar_title ?: 'Professor' }}</em> here at…"</p>
                    </div>
                </div>

                {{-- Description --}}
                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                        Description <span class="text-slate-600 normal-case font-normal tracking-normal">(shown to teachers)</span>
                    </label>
                    <textarea wire:model="description" rows="2"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                    resize-none focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"></textarea>
                    @error('description') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                {{-- Subject --}}
                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Subject</label>
                    <select wire:model="subject"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500">
                        <option value="all">All subjects</option>
                        <option value="history">History</option>
                        <option value="science">Science</option>
                        <option value="literature">Literature</option>
                        <option value="civics">Civics</option>
                    </select>
                </div>

                {{-- Voice provider --}}
                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Voice provider</label>
                    <select wire:model.live="voice_provider"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500">
                        <option value="kokoro">Kokoro TTS (local / free)</option>
                        <option value="openai">OpenAI TTS</option>
                        <option value="elevenlabs">ElevenLabs</option>
                    </select>
                </div>

                {{-- Voice ID (Kokoro) --}}
                @if($voice_provider === 'kokoro')
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Kokoro speaker</label>
                        <select wire:model="voice_id"
                                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                    focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500">
                            @foreach($this->voices as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-slate-600">Use the Voice Studio tab to audition each voice before choosing.</p>
                    </div>
                @else
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Voice ID</label>
                        <input wire:model="voice_id" type="text"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                                    focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="e.g. onyx, echo, or ElevenLabs voice ID">
                    </div>
                @endif

                {{-- Speed --}}
                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                        Narration speed: <span class="text-amber-400">{{ $voice_speed }}×</span>
                    </label>
                    <input type="range" wire:model.live="voice_speed"
                        min="0.5" max="1.5" step="0.05"
                        class="w-full accent-amber-400">
                    <div class="flex justify-between text-xs text-slate-600 mt-1">
                        <span>0.5× (slow)</span><span>0.9× (professor)</span><span>1.5× (fast)</span>
                    </div>
                </div>

                {{-- Active toggle --}}
                <div class="flex items-center gap-3">
                    <input type="checkbox" wire:model="is_active" id="is_active"
                        class="rounded border-slate-700 bg-slate-800 text-amber-500 focus:ring-amber-500">
                    <label for="is_active" class="text-sm text-slate-300">Avatar is active (visible to teachers)</label>
                </div>

                {{-- Save --}}
                <div class="pt-2 border-t border-slate-800">
                    <button
                        wire:click="saveSettings"
                        class="rounded-xl bg-amber-500 px-6 py-3 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors"
                    >Save settings</button>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        {{-- TAB: All samples                                                       --}}
        {{-- ══════════════════════════════════════════════════════════════════════ --}}
        <div x-show="activeTab === 'samples'" x-cloak>
            @if($this->voiceSamples->isNotEmpty())
                <div class="space-y-3">
                    @foreach($this->voiceSamples as $sample)
                        <div
                            wire:key="voice-sample-all-{{ $sample->id }}-{{ md5((string) $sample->audio_path) }}"
                            class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4 space-y-2"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-slate-400">{{ $sample->label() }}</p>
                                    <p class="text-sm text-slate-300 mt-0.5">{{ $sample->phrase }}</p>
                                    <p class="text-xs text-slate-600 mt-1">{{ $sample->created_at->diffForHumans() }}</p>
                                </div>
                                <div class="flex gap-2 flex-shrink-0">
                                    @if($sample->voice_id !== $avatar->voice_id || round($sample->voice_speed, 2) !== round($avatar->voice_speed, 2))
                                        <button wire:click="applyVoice({{ $sample->id }})"
                                                class="rounded-lg border border-emerald-700 px-3 py-1 text-xs text-emerald-400 hover:bg-emerald-950/40 transition-colors">
                                            Use voice
                                        </button>
                                    @endif
                                    <button wire:click="deleteSample({{ $sample->id }})"
                                            wire:confirm="Delete this sample?"
                                            class="rounded-lg border border-slate-700 px-2 py-1 text-xs text-slate-500 hover:border-rose-700 hover:text-rose-400 transition-colors">
                                        ✕
                                    </button>
                                </div>
                            </div>
                            <div wire:ignore>
                            <x-audio-player
                                :src="$sample->audioUrl()"
                                :mime="$sample->audio_extension === 'm4a' ? 'audio/mp4' : 'audio/mpeg'"
                                :label="$sample->label()"
                                :transcript="$sample->phrase"
                                :word-timings="$sample->settings_snapshot['word_timings'] ?? []"
                            />
                        </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-slate-500 text-center py-12">No samples generated yet.</p>
            @endif
        </div>
    </div>
</div>
