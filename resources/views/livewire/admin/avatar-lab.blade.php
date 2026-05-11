<div
    class="flex overflow-hidden"
    style="height: calc(100vh - 64px)"
    x-data="{ openSection: 'animation' }"
>

    {{-- ── LEFT SIDEBAR ──────────────────────────────────────────────────── --}}
    <aside class="w-[220px] shrink-0 border-r border-slate-700/50 flex flex-col overflow-y-auto bg-slate-900/50">

        {{-- Avatar selector --}}
        <div class="p-4 border-b border-slate-700/50">
            <p class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Avatar</p>
            <select
                wire:change="selectAvatar($event.target.value)"
                class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg px-3 py-2 focus:outline-none focus:border-indigo-500"
            >
                <option value="">— select —</option>
                @foreach($avatars as $avatar)
                    <option value="{{ $avatar->id }}" @selected($selectedAvatarId === $avatar->id)>
                        {{ $avatar->name }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Navigation sections --}}
        <nav class="flex-1 p-3 flex flex-col gap-1 text-sm">

            {{-- Animation --}}
            <div>
                <button
                    @click="openSection = openSection === 'animation' ? null : 'animation'"
                    class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-200 font-medium hover:bg-slate-800/50 transition-colors"
                >
                    Animation
                    <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'animation' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4-4 4"/></svg>
                </button>
                <div x-show="openSection === 'animation'" class="ml-3 mt-1 flex flex-col gap-0.5">
                    <button
                        wire:click="$set('activeSection', 'animation-groups')"
                        @click="window._avatar3d?.zoomToBody()"
                        class="text-left px-2 py-1.5 rounded-md text-xs transition-colors {{ $activeSection === 'animation-groups' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                    >Animation Groups</button>
                    <button
                        wire:click="$set('activeSection', 'controller')"
                        @click="window._avatar3d?.zoomToBody()"
                        class="text-left px-2 py-1.5 rounded-md text-xs transition-colors {{ $activeSection === 'controller' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                    >Controller</button>
                </div>
            </div>

            {{-- Narration & Audio --}}
            <div>
                <button
                    wire:click="$set('activeSection', 'narration')"
                    class="w-full flex items-center justify-between px-2 py-2 rounded-lg transition-colors {{ $activeSection === 'narration' ? 'text-amber-400 bg-amber-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                >
                    Narration &amp; Audio
                    <svg class="w-3 h-3" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4-4 4"/></svg>
                </button>
            </div>

            {{-- Settings --}}
            <div>
                <button
                    @click="openSection = openSection === 'settings' ? null : 'settings'"
                    class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 transition-colors"
                >
                    Settings
                    <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'settings' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4-4 4"/></svg>
                </button>
                <div x-show="openSection === 'settings'" class="px-2 pt-2 pb-1 flex flex-col gap-4">

                    {{-- Name --}}
                    <div>
                        <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-1 block">Name</label>
                        <input
                            type="text"
                            wire:model="name"
                            class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-lg px-3 py-2 focus:outline-none focus:border-indigo-500"
                        />
                    </div>

                    {{-- Gender --}}
                    <div>
                        <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-2 block">Gender</label>
                        <div class="flex gap-2">
                            <button
                                wire:click="$set('gender', 'male')"
                                class="flex-1 py-1.5 rounded-lg text-xs font-medium transition-colors {{ $gender === 'male' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-500' }}"
                            >♂ Male</button>
                            <button
                                wire:click="$set('gender', 'female')"
                                class="flex-1 py-1.5 rounded-lg text-xs font-medium transition-colors {{ $gender === 'female' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-500' }}"
                            >♀ Female</button>
                        </div>
                    </div>

                    {{-- Age --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-[10px] uppercase tracking-widest text-slate-500">Age</label>
                            <span class="text-indigo-400 text-xs font-semibold">{{ $age }}</span>
                        </div>
                        <input
                            type="range"
                            wire:model.live="age"
                            min="8"
                            max="80"
                            class="w-full accent-indigo-500"
                        />
                        <div class="flex justify-between text-[10px] text-slate-600 mt-1">
                            <span>Child</span><span>Teen</span><span>Adult</span><span>Elder</span>
                        </div>
                    </div>

                </div>
            </div>

        </nav>
    </aside>

    {{-- ── MIDDLE PANEL ──────────────────────────────────────────────────── --}}
    <div class="w-[420px] shrink-0 border-r border-slate-700/50 overflow-y-auto overflow-x-hidden p-6 bg-slate-900/30">

        @if($activeSection === 'animation-groups')

            <h2 class="text-lg font-semibold text-slate-100 mb-6">Animation</h2>

            @foreach(['idle' => 'Idle animations', 'presenting' => 'Presenting', 'greeting' => 'Greeting'] as $cat => $label)
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-200">{{ $label }}</h3>

                        <label class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-700/30 border border-teal-600/30 text-teal-300 text-xs font-medium hover:bg-teal-700/50 transition-colors">
                            Add +
                            <input
                                type="file"
                                wire:model="{{ $cat }}File"
                                accept=".fbx"
                                class="hidden"
                            />
                        </label>
                    </div>

                    @php $clips = $clipsByCategory->get($cat, collect()) @endphp

                    @if($clips->isEmpty())
                        <p class="text-slate-600 text-xs py-3 italic">No clips yet — upload a Mixamo FBX with "In Place" checked.</p>
                    @else
                        @php
                            $icons   = ['idle' => 'standing', 'presenting' => 'walking', 'greeting' => 'waving'];
                            $icon    = $icons[$cat] ?? 'standing';
                            $scroll  = $clips->count() > 4;
                        @endphp
                        {{-- Scroll-snap row: activates when > 4 clips, otherwise wraps --}}
                        <div class="relative">
                            <div class="flex gap-2 pb-2
                                {{ $scroll ? 'overflow-x-auto scroll-smooth' : 'flex-wrap' }}"
                                style="{{ $scroll ? 'scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;' : '' }}"
                            >
                                @foreach($clips as $clip)
                                    <button
                                        wire:click="loadPreview({{ $clip->id }})"
                                        style="{{ $scroll ? 'scroll-snap-align:start;' : '' }}"
                                        class="shrink-0 w-[72px] rounded-xl p-2 flex flex-col items-center gap-1 transition-all border
                                            {{ $previewClipId === $clip->id
                                                ? 'border-amber-400 bg-slate-700/80 shadow-md shadow-amber-500/10'
                                                : 'border-slate-700/60 bg-slate-800/60 hover:border-indigo-500/50 hover:bg-slate-800' }}"
                                    >
                                        {{-- Assigned indicator --}}
                                        <div class="w-full flex justify-end">
                                            <div class="w-3.5 h-3.5 rounded-full border flex items-center justify-center shrink-0
                                                {{ $assignedClipIds->contains($clip->id)
                                                    ? 'bg-indigo-500 border-indigo-400 text-white'
                                                    : 'border-slate-600 bg-transparent' }}">
                                                @if($assignedClipIds->contains($clip->id))
                                                    <svg class="w-2 h-2" viewBox="0 0 10 8" fill="none"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                @endif
                                            </div>
                                        </div>

                                        <x-animation-icon :type="$icon" class="w-8 h-8 text-indigo-400" />

                                        <span class="text-[10px] text-slate-400 text-center leading-tight w-full truncate px-0.5">
                                            {{ $clip->name }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>

                            {{-- Fade-out hint when scrollable --}}
                            @if($scroll)
                                <div class="absolute right-0 top-0 bottom-2 w-8 bg-gradient-to-l from-slate-900/80 to-transparent pointer-events-none rounded-r-xl"></div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach

        @elseif($activeSection === 'narration')

            <h2 class="text-lg font-semibold text-slate-100 mb-1">Narration &amp; Audio</h2>
            <p class="text-xs text-slate-500 mb-6">Speak a script using the avatar's cloned voice. The 3D avatar will lip-sync and camera zooms to the face.</p>

            @if(! $selectedAvatarId)
                <p class="text-slate-500 text-sm">Select an avatar first.</p>
            @else

                {{-- ── Voice picker ───────────────────────────────── --}}
                <div class="mb-5">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-2 block">Voice</label>

                    {{-- Provider toggle --}}
                    <div class="join mb-3">
                        <button wire:click="$set('previewProvider', 'elevenlabs')"
                                class="btn btn-xs join-item {{ $previewProvider === 'elevenlabs' ? 'btn-primary' : 'btn-ghost' }}">
                            ★ ElevenLabs
                        </button>
                        <button wire:click="$set('previewProvider', 'edge_tts')"
                                class="btn btn-xs join-item {{ $previewProvider === 'edge_tts' ? 'btn-primary' : 'btn-ghost' }}">
                            edge-tts
                        </button>
                        <button wire:click="$set('previewProvider', 'pocket_tts')"
                                class="btn btn-xs join-item {{ $previewProvider === 'pocket_tts' ? 'btn-primary' : 'btn-ghost' }}">
                            Pocket TTS
                        </button>
                    </div>

                    {{-- Hidden SVG grain filter --}}
                    <svg style="display:none" aria-hidden="true">
                        <defs>
                            <filter id="grain-lab" x="0%" y="0%" width="100%" height="100%" color-interpolation-filters="sRGB">
                                <feTurbulence type="fractalNoise" baseFrequency="0.65" numOctaves="3" stitchTiles="stitch" result="noise"/>
                                <feColorMatrix type="saturate" values="0" in="noise" result="grey"/>
                                <feBlend in="SourceGraphic" in2="grey" mode="overlay" result="blended"/>
                                <feComponentTransfer><feFuncA type="linear" slope="0.18"/></feComponentTransfer>
                            </filter>
                        </defs>
                    </svg>

                    {{-- Voice card strip --}}
                    <div
                        x-data="{
                            playingId: null,
                            audioEl: null,
                            playPreview(voiceId, previewUrl) {
                                if (this.audioEl) { this.audioEl.pause(); this.audioEl = null; }
                                if (this.playingId === voiceId) { this.playingId = null; return; }
                                if (!previewUrl) return;
                                this.playingId = voiceId;
                                this.audioEl = new Audio(previewUrl);
                                this.audioEl.play();
                                this.audioEl.onended = () => { this.playingId = null; this.audioEl = null; };
                            }
                        }"
                        class="flex gap-2 pb-2 overflow-x-auto scroll-smooth"
                        style="scroll-snap-type:x mandatory; scrollbar-width:none;"
                    >
                        @foreach($this->voices() as $voice)
                        <button
                            wire:click="selectVoice('{{ $voice['id'] }}')"
                            class="vg-card {{ $voice['gradient_class'] }} shrink-0 w-18 rounded-xl p-2 border relative cursor-pointer transition-all
                                   {{ $voiceId === $voice['id'] ? 'border-amber-400' : 'border-slate-700/60 hover:border-indigo-500/50' }}"
                            style="scroll-snap-align:start; min-height:80px;"
                            title="{{ $voice['label'] }}"
                        >
                            <div class="absolute top-1 right-1 z-10">
                                @if($voiceId === $voice['id'])
                                    <span class="text-amber-400 text-xs">✓</span>
                                @elseif($voice['preview_url'])
                                    <button
                                        x-on:click.stop="playPreview('{{ $voice['id'] }}', '{{ $voice['preview_url'] }}')"
                                        class="text-slate-400 hover:text-white text-xs leading-none"
                                        :class="{ 'text-indigo-400': playingId === '{{ $voice['id'] }}' }"
                                    >
                                        <span x-show="playingId !== '{{ $voice['id'] }}'">▶</span>
                                        <span x-show="playingId === '{{ $voice['id'] }}'" class="flex gap-0.5 items-end h-3 text-indigo-400">
                                            <span class="wave-bar h-3"></span>
                                            <span class="wave-bar h-2"></span>
                                            <span class="wave-bar h-3"></span>
                                        </span>
                                    </button>
                                @endif
                            </div>
                            <div class="flex items-center justify-center h-8 z-10 relative mt-1">
                                <svg class="w-6 h-6 text-white/50" viewBox="0 0 24 24" fill="currentColor">
                                    <rect x="2" y="8" width="2" height="8" rx="1"/>
                                    <rect x="6" y="5" width="2" height="14" rx="1"/>
                                    <rect x="10" y="3" width="2" height="18" rx="1"/>
                                    <rect x="14" y="5" width="2" height="14" rx="1"/>
                                    <rect x="18" y="8" width="2" height="8" rx="1"/>
                                </svg>
                            </div>
                            <p class="text-xs text-white/80 text-center truncate z-10 relative mt-1 leading-tight">
                                {{ Str::before($voice['label'], ' ·') ?: $voice['label'] }}
                            </p>
                        </button>
                        @endforeach
                    </div>
                </div>

                {{-- ── Speed slider ──────────────────────────────── --}}
                <div class="mb-5">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-[10px] uppercase tracking-widest text-slate-500">Speed</label>
                        <span class="text-[10px] font-mono text-indigo-400">{{ number_format($voiceSpeed, 2) }}×</span>
                    </div>
                    <input
                        type="range"
                        wire:model.live="voiceSpeed"
                        min="0.5" max="2.0" step="0.05"
                        class="w-full accent-indigo-500"
                    />
                    <div class="flex justify-between text-[9px] text-slate-600 mt-0.5">
                        <span>0.5×</span><span>1×</span><span>2×</span>
                    </div>
                </div>

                {{-- ── Script editor ─────────────────────────────── --}}
                <div class="mb-4">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-2 block">Script</label>
                    <textarea
                        wire:model="narrationScript"
                        rows="6"
                        class="w-full bg-slate-900 border border-slate-700 text-slate-200 text-sm rounded-xl px-4 py-3 resize-none focus:outline-none focus:border-indigo-500 leading-relaxed"
                        placeholder="Hey students! My name is..."
                    ></textarea>
                </div>

                {{-- Status indicator --}}
                @if($narrationAudioUrl && $narrationCachedScript === $narrationScript)
                    <div class="flex items-center gap-1.5 mb-3 text-xs text-emerald-400">
                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        Audio ready — plays instantly
                    </div>
                @elseif($selectedAvatarId && $voiceId)
                    <div class="flex items-center gap-1.5 mb-3 text-xs text-slate-500">
                        <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        Pre-generating audio…
                    </div>
                @endif

                {{-- Play button --}}
                <button
                    wire:click="speakScript"
                    wire:loading.attr="disabled"
                    @disabled($narrationBusy || !$voiceId)
                    class="w-full flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-semibold transition-colors
                           {{ $narrationBusy || !$voiceId ? 'bg-slate-800 text-slate-600 cursor-not-allowed border border-slate-700' : 'bg-amber-500 text-slate-950 hover:bg-amber-400' }}"
                >
                    <span wire:loading wire:target="speakScript,selectAvatar,selectVoice" class="inline-block">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </span>
                    <span wire:loading.remove wire:target="speakScript,selectAvatar,selectVoice">
                        <svg width="8" height="8" viewBox="0 0 8 8" fill="none"><path d="M0 0L8 4.19615L0 8V0Z" fill="currentColor"/></svg>
                    </span>
                    <span wire:loading wire:target="speakScript,selectAvatar,selectVoice">Generating…</span>
                    <span wire:loading.remove wire:target="speakScript,selectAvatar,selectVoice">
                        {{ $narrationAudioUrl && $narrationCachedScript === $narrationScript ? '▶ Play Narration' : 'Generate &amp; Play' }}
                    </span>
                </button>

                @if(! $voiceId)
                    <p class="text-xs text-slate-500 mt-2 text-center">Pick a voice above to get started.</p>
                @endif

            @endif

        @elseif($activeSection === 'controller')

            <h2 class="text-lg font-semibold text-slate-100 mb-6">Controller</h2>

            @if(! $selectedAvatarId)
                <p class="text-slate-500 text-sm">Select an avatar to view its controller.</p>
            @else
                @foreach(['idle' => 'Idle', 'presenting' => 'Presenting', 'greeting' => 'Greeting'] as $cat => $label)
                    <div class="mb-6 bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                        <h3 class="text-sm font-semibold text-slate-200 mb-3">{{ $label }}</h3>

                        @php $assignedIds = $controllerData[$cat] ?? [] @endphp

                        @if(empty($assignedIds))
                            <p class="text-slate-600 text-xs italic">No clips assigned</p>
                        @else
                            <div class="flex flex-col gap-2">
                                @foreach($assignedIds as $clipId)
                                    @php $clip = $clipsByCategory->flatten()->firstWhere('id', (int) $clipId) @endphp
                                    @if($clip)
                                        <div class="flex items-center justify-between bg-slate-900/50 rounded-lg px-3 py-2">
                                            <span class="text-xs text-slate-300">{{ $clip->name }}</span>
                                            <button
                                                wire:click="removeFromController({{ $selectedAvatarId }}, '{{ $cat }}', {{ $clip->id }})"
                                                class="text-slate-600 hover:text-red-400 text-xs transition-colors"
                                            >✕</button>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            @endif

        @endif

    </div>

    {{-- ── RIGHT VIEWPORT ────────────────────────────────────────────────── --}}
    <div
        class="flex-1 relative overflow-hidden bg-slate-950"
        x-data="{
            clipId: null, clipName: '', isAssigned: false, confirmDelete: false,
            speed: 1.0, expressiveness: 1.0,
            baked: false,
            setSpeed(v) {
                this.speed = parseFloat(v);
                this.baked = false;
                window._avatar3d?.setAnimationSpeed(this.speed);
            },
            setExpressiveness(v) {
                this.expressiveness = parseFloat(v);
                this.baked = false;
                window._avatar3d?.setAnimationExpressiveness(this.expressiveness);
            },
            bake() {
                $wire.bakeClip(this.clipId, this.speed, this.expressiveness);
            },
        }"
        x-on:preview-clip.window="
            clipId = $event.detail.clipId;
            clipName = $event.detail.clipName;
            isAssigned = $event.detail.isAssigned;
            confirmDelete = false;
            baked = false;
            speed = $event.detail.speed ?? 1.0;
            expressiveness = $event.detail.expressiveness ?? 1.0;
            window._avatar3d?.setAnimationSpeed(speed);
            window._avatar3d?.setAnimationExpressiveness(expressiveness);
        "
        x-on:clip-baked.window="if ($event.detail.clipId === clipId) { baked = true; }"
    >

        {{-- Top overlay: clip name + Use/Remove toggle --}}
        <div
            class="absolute top-4 right-4 z-10 flex items-center gap-3"
            x-show="clipId !== null"
            x-cloak
            id="viewport-overlay"
        >
            <span class="text-slate-300 text-sm font-medium drop-shadow" x-text="clipName"></span>
            <button
                wire:click="useClip"
                @click="isAssigned = !isAssigned"
                :class="isAssigned
                    ? 'bg-indigo-600/20 border-indigo-500 text-indigo-300 hover:bg-red-900/30 hover:border-red-500 hover:text-red-300'
                    : 'bg-slate-800/90 border-slate-600 text-slate-200 hover:border-indigo-500'"
                class="flex items-center gap-2 px-4 py-2 border rounded-xl text-sm transition-colors backdrop-blur-sm"
            >
                <span x-text="isAssigned ? 'Remove' : 'Use'"></span>
                <span
                    class="w-5 h-5 rounded-full flex items-center justify-center transition-colors"
                    :class="isAssigned ? 'bg-indigo-500' : 'bg-slate-600'"
                >
                    <svg x-show="isAssigned" class="w-2.5 h-2.5 text-white" viewBox="0 0 10 8" fill="none"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg x-show="!isAssigned" class="w-2.5 h-2.5 text-white" viewBox="0 0 10 10" fill="none"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </span>
            </button>
        </div>

        {{-- Bottom control bar: sliders + delete --}}
        <div
            class="absolute bottom-5 left-1/2 -translate-x-1/2 z-10 w-[440px] max-w-[calc(100%-2rem)]"
            x-show="clipId !== null && !confirmDelete"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-2"
        >
            <div class="bg-slate-900/90 border border-slate-700/70 rounded-2xl px-5 py-4 backdrop-blur-md shadow-xl shadow-black/40 flex flex-col gap-4">

                {{-- Sliders --}}
                <div class="grid grid-cols-2 gap-x-6 gap-y-1">

                    {{-- Speed --}}
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between">
                            <label class="text-[10px] uppercase tracking-widest text-slate-500 flex items-center gap-1.5">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Speed
                            </label>
                            <span class="text-[10px] font-mono text-indigo-400" x-text="parseFloat(speed).toFixed(2) + '×'"></span>
                        </div>
                        <input
                            type="range" min="0.25" max="2" step="0.05"
                            x-model="speed"
                            @input="setSpeed($event.target.value)"
                            class="w-full h-1.5 rounded-full appearance-none cursor-pointer accent-indigo-500 bg-slate-700"
                        />
                        <div class="flex justify-between text-[9px] text-slate-600">
                            <span>0.25×</span><span>1×</span><span>2×</span>
                        </div>
                    </div>

                    {{-- Expressiveness --}}
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between">
                            <label class="text-[10px] uppercase tracking-widest text-slate-500 flex items-center gap-1.5">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                                Expressiveness
                            </label>
                            <span class="text-[10px] font-mono text-indigo-400" x-text="Math.round(expressiveness * 100) + '%'"></span>
                        </div>
                        <input
                            type="range" min="0" max="1.5" step="0.05"
                            x-model="expressiveness"
                            @input="setExpressiveness($event.target.value)"
                            class="w-full h-1.5 rounded-full appearance-none cursor-pointer accent-indigo-500 bg-slate-700"
                        />
                        <div class="flex justify-between text-[9px] text-slate-600">
                            <span>Subtle</span><span>Normal</span><span>Exaggerated</span>
                        </div>
                    </div>

                </div>

                {{-- Divider + action row --}}
                <div class="border-t border-slate-700/50 pt-3 flex items-center justify-between gap-3">

                    {{-- Delete (left, subdued) --}}
                    <button
                        @click="confirmDelete = true"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-transparent border border-red-900/50 text-red-500 text-xs hover:bg-red-950/60 hover:border-red-700 hover:text-red-400 transition-colors"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            <path d="M10 11v6M14 11v6"/>
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                        </svg>
                        Delete clip
                    </button>

                    {{-- Bake (right, prominent) --}}
                    <button
                        @click="bake()"
                        :disabled="baked"
                        :class="baked
                            ? 'bg-teal-900/30 border-teal-700/50 text-teal-400 cursor-default'
                            : 'bg-indigo-600/20 border-indigo-500/60 text-indigo-300 hover:bg-indigo-600/40 hover:border-indigo-400 cursor-pointer'"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors"
                    >
                        {{-- Baked: checkmark --}}
                        <template x-if="baked">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </template>
                        {{-- Unbaked: flame / save icon --}}
                        <template x-if="!baked">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/><path d="M12 8v4l3 3"/></svg>
                        </template>
                        <span x-text="baked ? 'Baked' : 'Bake settings'"></span>
                    </button>

                </div>

            </div>
        </div>

        {{-- Delete confirmation modal --}}
        <div
            class="absolute inset-0 z-20 flex items-center justify-center bg-slate-950/70 backdrop-blur-sm"
            x-show="confirmDelete"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <div class="bg-slate-900 border border-slate-700 rounded-2xl p-6 w-80 shadow-2xl shadow-black/60 flex flex-col gap-4">
                {{-- Icon + title --}}
                <div class="flex flex-col items-center gap-3 text-center">
                    <div class="w-12 h-12 rounded-full bg-red-950/60 border border-red-800/50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            <path d="M10 11v6M14 11v6"/>
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-slate-100 font-semibold text-base">Are you sure?</p>
                        <p class="text-slate-400 text-sm mt-1">
                            Delete animation clip
                            <span class="text-slate-200 font-medium" x-text="clipName"></span>?
                        </p>
                        <p class="text-slate-500 text-xs mt-1">This action cannot be undone.</p>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex gap-3">
                    <button
                        @click="confirmDelete = false"
                        class="flex-1 px-4 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-slate-300 text-sm font-medium hover:bg-slate-700 hover:border-slate-600 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="$wire.deleteClip(clipId); clipId = null; confirmDelete = false"
                        class="flex-1 px-4 py-2.5 rounded-xl bg-red-700 border border-red-600 text-white text-sm font-semibold hover:bg-red-600 transition-colors"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>

        {{-- Canvas — wire:ignore on the wrapper prevents Livewire from ever
             touching the WebGL canvas (which would destroy the context).
             Avatar switches are handled via the avatar3d:load JS event below. --}}
        @if(! $selectedAvatarId)
            <div class="absolute inset-0 flex items-center justify-center text-slate-600 text-sm">
                ← Select an avatar to load the 3D viewport
            </div>
        @else
            <div class="w-full h-full" wire:ignore>
                <canvas
                    id="avatar-lab-canvas"
                    data-character-url="/avatars/{{ $selectedAvatarId }}/character.glb"
                    data-azure-key="{{ config('services.azure_speech.key') }}"
                    data-azure-region="{{ config('services.azure_speech.region', 'eastus') }}"
                    class="w-full h-full block"
                ></canvas>
            </div>
        @endif

    </div>

    <script src="https://cdn.jsdelivr.net/npm/microsoft-cognitiveservices-speech-sdk@latest/distrib/browser/microsoft.cognitiveservices.speech.sdk.bundle-min.js"></script>
    @vite('resources/js/avatar-3d.js')

</div>


