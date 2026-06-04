@push('head-scripts')
@if($selectedAvatarId)
<link rel="preload" href="/avatars/{{ $selectedAvatarId }}/character.glb" as="fetch" crossorigin="anonymous">
@endif
<script>
    document.addEventListener('alpine:init', () => {
        if (Alpine.store('voiceStrip')) return;
        Alpine.store('voiceStrip', {
            selectedId: '',
            playingId: null,
            _el: null,
            play(voiceId, previewUrl) {
                if (this._el) { this._el.pause(); this._el = null; }
                if (this.playingId === voiceId) { this.playingId = null; return; }
                if (!previewUrl) return;
                this.playingId = voiceId;
                this._el = new Audio(previewUrl);
                this._el.play();
                this._el.onended = () => { this.playingId = null; this._el = null; };
            }
        });
        window.addEventListener('voice-selected', (e) => {
            Alpine.store('voiceStrip').selectedId = e.detail.voiceId ?? '';
        });
    });
</script>
@endpush

<div
    class="flex overflow-hidden"
    style="height: calc(100vh - 64px)"
    x-data
    x-on:avatar3d:zoomtohead.window="window._avatar3d?.zoomToHead()"
    x-on:avatar3d:setbg.window="window._avatar3d?.setSceneBackground($event.detail.hex)"
    x-init="
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                $wire.arrowNav(e.key === 'ArrowRight' ? 1 : -1);
            }
        });
        const savedBg = localStorage.getItem('avatarLabBg');
        if (savedBg) $wire.set('sceneBackground', savedBg);
        $wire.$watch('sceneBackground', val => localStorage.setItem('avatarLabBg', val));
    "
>

    {{-- ── LEFT SIDEBAR ──────────────────────────────────────────────────── --}}
    <aside class="w-50 shrink-0 border-r border-slate-700/50 flex flex-col bg-slate-900/50">

        <nav class="flex-1 overflow-y-auto py-2">

            {{-- Avatar --}}
            <button
                wire:click="$set('activeSection', 'avatar')"
                @click="window._avatar3d?.zoomToBody()"
                class="w-full text-left px-4 py-2.5 text-sm font-medium transition-colors
                    {{ $activeSection === 'avatar' ? 'text-amber-400 bg-amber-500/10' : 'text-slate-300 hover:text-slate-100 hover:bg-slate-800/50' }}"
            >Avatar</button>

            <div class="mx-4 my-1 border-t border-slate-700/50"></div>

            {{-- Animation --}}
            <button
                wire:click="$set('activeSection', 'animation-groups')"
                @click="window._avatar3d?.zoomToBody()"
                class="w-full text-left px-4 py-2.5 text-sm font-medium transition-colors
                    {{ $activeSection === 'animation-groups' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-300 hover:text-slate-100 hover:bg-slate-800/50' }}"
            >Animation</button>

            {{-- Animation Controller --}}
            <button
                wire:click="$set('activeSection', 'controller')"
                @click="window._avatar3d?.zoomToBody()"
                class="w-full text-left px-4 py-2.5 text-sm font-medium transition-colors
                    {{ $activeSection === 'controller' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-300 hover:text-slate-100 hover:bg-slate-800/50' }}"
            >Animation Controller</button>

            <div class="mx-4 my-1 border-t border-slate-700/50"></div>

            {{-- Narration & Audio --}}
            <button
                wire:click="$set('activeSection', 'narration')"
                @click="window._avatar3d?.zoomToHead()"
                class="w-full text-left px-4 py-2.5 text-sm font-medium transition-colors
                    {{ $activeSection === 'narration' ? 'text-amber-400 bg-amber-500/10' : 'text-slate-300 hover:text-slate-100 hover:bg-slate-800/50' }}"
            >Narration &amp; Audio</button>

            <div class="mx-4 my-1 border-t border-slate-700/50"></div>

            {{-- Settings --}}
            <button
                wire:click="$set('activeSection', 'settings')"
                @click="window._avatar3d?.zoomToBody()"
                class="w-full text-left px-4 py-2.5 text-sm font-medium transition-colors
                    {{ $activeSection === 'settings' ? 'text-slate-100 bg-slate-700/50' : 'text-slate-300 hover:text-slate-100 hover:bg-slate-800/50' }}"
            >Settings</button>

        </nav>

        {{-- Selected avatar name at bottom --}}
        @if($selectedAvatarId)
            <div class="px-4 py-3 border-t border-slate-700/50">
                <p class="text-[10px] uppercase tracking-widest text-slate-600 mb-0.5">Active avatar</p>
                <p class="text-xs text-slate-400 truncate">{{ $avatars->firstWhere('id', $selectedAvatarId)?->name ?? '' }}</p>
            </div>
        @endif

    </aside>

    {{-- ── MIDDLE PANEL ──────────────────────────────────────────────────── --}}
    <div class="w-[420px] shrink-0 border-r border-slate-700/50 overflow-y-auto overflow-x-hidden p-6 bg-slate-900/30"
         @wheel.self.stop>

        @if($activeSection === 'avatar')

            <h2 class="text-lg font-semibold text-slate-100 mb-4">Avatar</h2>

            {{-- Single poll for processing avatars --}}
            @if($avatars->contains(fn($a) => ($a->morph_status ?? 'ready') === 'processing'))
                <div wire:poll.3000ms="refreshAvatarList" class="hidden"></div>
            @endif

            <div class="grid grid-cols-4 gap-2">
                @foreach($avatars as $avatar)
                    @php
                        $thumbPath  = public_path("avatars/{$avatar->id}/thumbnail.webp");
                        $thumbUrl   = file_exists($thumbPath) ? asset("avatars/{$avatar->id}/thumbnail.webp") : null;
                        $isSelected = $selectedAvatarId === $avatar->id;
                    @endphp
                    <button
                        wire:click="selectAvatar({{ $avatar->id }})"
                        title="{{ $avatar->name }}"
                        class="group relative rounded-xl overflow-hidden aspect-square transition-all
                            {{ $isSelected
                                ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'
                                : 'ring-1 ring-slate-700/50 hover:ring-slate-500 opacity-60 hover:opacity-100' }}"
                    >
                        @if($thumbUrl)
                            <img src="{{ $thumbUrl }}" alt="{{ $avatar->name }}" class="w-full h-full object-cover object-top" />
                        @else
                            <div class="w-full h-full bg-slate-800 flex items-center justify-center">
                                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
                            </div>
                        @endif
                        {{-- Processing overlay --}}
                        @if(($avatar->morph_status ?? 'ready') === 'processing')
                            <div class="absolute inset-0 bg-slate-900/75 flex flex-col items-center justify-center gap-1">
                                <span class="loading loading-spinner loading-xs text-amber-400"></span>
                                <span class="text-[8px] text-amber-300 font-medium">Processing</span>
                            </div>
                        @elseif(($avatar->morph_status ?? 'ready') === 'failed')
                            <div class="absolute inset-0 bg-red-900/60 flex items-center justify-center">
                                <span class="text-[8px] text-red-300 font-medium">Failed</span>
                            </div>
                        @endif
                        {{-- Name tooltip on hover --}}
                        <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/80 to-transparent px-1 py-1
                            opacity-0 group-hover:opacity-100 {{ $isSelected ? 'opacity-100' : '' }} transition-opacity">
                            <p class="text-[9px] text-white leading-tight truncate">{{ $avatar->name }}</p>
                        </div>
                    </button>
                @endforeach

                {{-- Upload new avatar card --}}
                <label
                    class="group relative rounded-xl overflow-hidden aspect-square cursor-pointer
                           ring-1 ring-slate-700/50 hover:ring-slate-500 bg-slate-800/60 hover:bg-slate-800
                           flex items-center justify-center transition-all"
                    title="Upload new avatar GLB"
                >
                    <input
                        type="file"
                        accept=".glb"
                        class="sr-only"
                        wire:model="newAvatarGlbFile"
                    >
                    <svg class="w-8 h-8 text-slate-600 group-hover:text-slate-400 transition-colors"
                         fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    <div wire:loading wire:target="newAvatarGlbFile"
                         class="absolute inset-0 bg-slate-900/80 flex items-center justify-center">
                        <span class="loading loading-spinner loading-sm text-amber-400"></span>
                    </div>
                </label>
            </div>

        @elseif($activeSection === 'animation-groups')

            <h2 class="text-lg font-semibold text-slate-100 mb-4">Animation</h2>

            @foreach(['introduction' => 'Introduction', 'idle' => 'Idle', 'expression' => 'Expressions', 'dance' => 'Dance'] as $cat => $label)
                @php $clips = $clipsByCategory->get($cat, collect()) @endphp

                {{-- DaisyUI collapse — checkbox is z-1 and covers the title area,
                     so the Add+ label needs relative z-10 to sit above it. --}}
                <div class="collapse collapse-arrow rounded-xl border border-slate-700/40 bg-slate-900/50 mb-3 overflow-visible">
                    <input type="checkbox" checked class="min-h-0" />
                    <div class="collapse-title flex items-center justify-between py-3 px-4 min-h-0 text-sm font-semibold text-slate-200">
                        {{ $label }}
                        @if($cat === 'introduction')
                            <span class="relative z-10 text-[9px] font-normal text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 rounded px-1.5 py-0.5 mr-2">feet on</span>
                        @endif
                        <label
                            class="relative z-10 cursor-pointer inline-flex items-center gap-1 px-2.5 py-1 mr-6
                                   rounded-lg bg-teal-700/30 border border-teal-600/30 text-teal-300 text-xs
                                   font-medium hover:bg-teal-700/50 transition-colors"
                        >
                            Add +
                            <input
                                type="file"
                                wire:model="{{ $cat === 'expression' ? 'expressionFile' : ($cat === 'dance' ? 'danceFile' : ($cat === 'introduction' ? 'introductionFile' : 'idleFile')) }}"
                                accept=".fbx"
                                class="hidden"
                            />
                        </label>
                    </div>

                    <div class="collapse-content !pt-0 !px-3 !pb-3">
                        @if($clips->isEmpty())
                            <p class="text-slate-600 text-xs py-2 italic">No clips.</p>
                        @else
                            <div class="grid grid-cols-4 gap-2">
                                @foreach($clips as $clip)
                                    @php
                                        $thumb    = $clip->thumbnailUrl();
                                        $isActive = $previewClipId === $clip->id;
                                    @endphp
                                    <button
                                        wire:click="loadPreview({{ $clip->id }})"
                                        title="{{ $clip->name }}"
                                        class="group relative rounded-xl overflow-hidden aspect-square transition-all
                                            {{ $isActive
                                                ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'
                                                : 'ring-1 ring-slate-700/50 hover:ring-slate-500 opacity-70 hover:opacity-100' }}"
                                        style="background-color: {{ $sceneBackground }}"
                                    >
                                        @if($thumb)
                                            <img src="{{ $thumb }}" alt="{{ $clip->name }}"
                                                 class="w-full h-full object-cover object-top" />
                                        @else
                                            <div class="w-full h-full flex items-center justify-center" style="background-color: {{ $sceneBackground }}">
                                                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0"/>
                                                </svg>
                                            </div>
                                        @endif

                                        {{-- Assigned indicator (top-right dot) --}}
                                        @if($assignedClipIds->contains($clip->id))
                                            <div class="absolute top-1 right-1 w-4 h-4 rounded-full bg-indigo-500 border border-indigo-400 flex items-center justify-center">
                                                <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 10 8" fill="none">
                                                    <path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                        @endif

                                        {{-- Name label — always visible when selected, on hover otherwise --}}
                                        <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/80 to-transparent px-1 py-1
                                            opacity-0 group-hover:opacity-100 {{ $isActive ? 'opacity-100' : '' }} transition-opacity">
                                            <p class="text-[9px] text-white leading-tight truncate">{{ $clip->name }}</p>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
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

                    {{-- Voice card strip — no x-data needed.
                         All reactive expressions use $store.voiceStrip which Alpine tracks globally. --}}
                    <div
                        class="flex gap-2 pb-2 overflow-x-auto scroll-smooth"
                        style="scroll-snap-type:x mandatory; scrollbar-width:none;"
                    >
                        @foreach($this->voices as $voice)
                        <button
                            x-on:click="
                                $wire.selectVoice('{{ $voice['id'] }}');
                                @if($voice['preview_url'])
                                $store.voiceStrip.playingId = '{{ $voice['id'] }}';
                                $wire.previewVoiceWithAlignment('{{ $voice['id'] }}');
                                window.addEventListener('avatar3d:speakend', () => {
                                    if ($store.voiceStrip.playingId === '{{ $voice['id'] }}') $store.voiceStrip.playingId = null;
                                }, { once: true });
                                @endif
                            "
                            :class="$store.voiceStrip.selectedId === '{{ $voice['id'] }}'
                                ? 'border-amber-400'
                                : 'border-slate-700/60 hover:border-indigo-500/50'"
                            class="vg-card {{ $voice['gradient_class'] }} shrink-0 w-18 rounded-xl p-2 border relative cursor-pointer transition-all"
                            style="scroll-snap-align:start; min-height:80px;"
                            title="{{ $voice['label'] }}"
                        >
                            {{-- Selected indicator / playing animation --}}
                            <div class="absolute top-1 right-1 z-10">
                                <span
                                    x-show="$store.voiceStrip.playingId === '{{ $voice['id'] }}'"
                                    class="flex gap-0.5 items-end h-3 text-indigo-400"
                                >
                                    <span class="wave-bar h-3"></span>
                                    <span class="wave-bar h-2"></span>
                                    <span class="wave-bar h-3"></span>
                                </span>
                                <span
                                    x-show="$store.voiceStrip.playingId !== '{{ $voice['id'] }}' && $store.voiceStrip.selectedId === '{{ $voice['id'] }}'"
                                    class="text-amber-400 text-xs leading-none"
                                >✓</span>
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
                        {{ $narrationAudioUrl && $narrationCachedScript === $narrationScript ? '▶ Play Narration' : 'Generate & Play' }}
                    </span>
                </button>

                @if(! $voiceId)
                    <p class="text-xs text-slate-500 mt-2 text-center">Pick a voice above to get started.</p>
                @endif

                {{-- ── Introduction piece ──────────────────────────── --}}
                <div class="mt-8 border-t border-slate-700/40 pt-6">
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="text-sm font-semibold text-slate-200">Introduction</h3>
                        <span class="text-[9px] font-medium text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 rounded px-1.5 py-0.5">Scene Opener</span>
                    </div>
                    <p class="text-xs text-slate-500 mb-3">Avatar walks in from the right, stops at center, then speaks this intro. Use <code class="text-indigo-400">{name}</code> for the avatar's name.</p>

                    <textarea
                        wire:model="introScript"
                        rows="4"
                        class="w-full bg-slate-900 border border-slate-700 text-slate-200 text-sm rounded-xl px-4 py-3 resize-none focus:outline-none focus:border-indigo-500 leading-relaxed mb-3"
                        placeholder="Hello everyone! My name is {name}. Welcome to today's lesson…"
                    ></textarea>

                    <div class="flex gap-2">
                        <button
                            wire:click="playIntroSequence"
                            class="flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold
                                   bg-indigo-600/30 border border-indigo-500/40 text-indigo-300 hover:bg-indigo-600/50 transition-colors"
                            title="Play the walking-in sequence without audio"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/>
                            </svg>
                            Walk In
                        </button>
                        <button
                            wire:click="speakIntroScript"
                            wire:loading.attr="disabled"
                            @disabled($narrationBusy || !$voiceId)
                            class="flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold transition-colors
                                   {{ $narrationBusy || !$voiceId ? 'bg-slate-800 text-slate-600 cursor-not-allowed border border-slate-700' : 'bg-amber-500/20 border border-amber-500/40 text-amber-300 hover:bg-amber-500/30' }}"
                            title="Generate intro audio and play it after walking in"
                        >
                            <span wire:loading wire:target="speakIntroScript">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            </span>
                            <span wire:loading.remove wire:target="speakIntroScript">
                                <svg width="8" height="8" viewBox="0 0 8 8" fill="none"><path d="M0 0L8 4.19615L0 8V0Z" fill="currentColor"/></svg>
                            </span>
                            <span wire:loading wire:target="speakIntroScript">Generating…</span>
                            <span wire:loading.remove wire:target="speakIntroScript">Speak Intro</span>
                        </button>
                    </div>
                </div>

            @endif

        @elseif($activeSection === 'settings')

            <h2 class="text-lg font-semibold text-slate-100 mb-6">Settings</h2>

            <div class="flex flex-col gap-6 max-w-sm">

                {{-- Name --}}
                <div>
                    <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-1 block">Name</label>
                    <input
                        type="text"
                        wire:model.live.debounce.600ms="name"
                        class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg px-3 py-2 focus:outline-none focus:border-indigo-500"
                    />
                </div>

                {{-- Gender --}}
                <div>
                    <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-2 block">Gender</label>
                    <div class="flex gap-2">
                        <button
                            wire:click="$set('gender', 'male')"
                            class="flex-1 py-2 rounded-lg text-sm font-medium transition-colors {{ $gender === 'male' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-500' }}"
                        >♂ Male</button>
                        <button
                            wire:click="$set('gender', 'female')"
                            class="flex-1 py-2 rounded-lg text-sm font-medium transition-colors {{ $gender === 'female' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-500' }}"
                        >♀ Female</button>
                    </div>
                </div>

                {{-- Age --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[10px] uppercase tracking-widest text-slate-500">Age</label>
                        <span class="text-indigo-400 text-sm font-semibold">{{ $age }}</span>
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

                {{-- Scene Background + Skybox --}}
                <div x-data="skyboxPanel()" x-on:avatar3d:setbg.window="clearAll()">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-2 block">Scene Background</label>

                    {{-- Swatch row: preset colors + custom color picker + skybox image upload --}}
                    <div class="flex gap-2 flex-wrap">
                        @foreach([
                            '#f0f0f0' => 'Studio',
                            '#ffffff' => 'White',
                            '#1e293b' => 'Dark',
                            '#000000' => 'Black',
                        ] as $hex => $label)
                            <button
                                wire:click="$set('sceneBackground', '{{ $hex }}')"
                                @click="clearAll()"
                                title="{{ $label }}"
                                class="w-9 h-9 rounded-lg border-2 transition-all"
                                :class="skyboxImages.length === 0 && '{{ $sceneBackground }}' === '{{ $hex }}' ? 'border-amber-400 scale-110' : 'border-slate-600 hover:border-slate-400'"
                                style="background-color: {{ $hex }}"
                            ></button>
                        @endforeach

                        {{-- Custom color picker --}}
                        <label class="relative w-9 h-9 rounded-lg border-2 transition-all cursor-pointer"
                            :class="skyboxImages.length === 0 && {{ json_encode(!in_array($sceneBackground, ['#f0f0f0','#ffffff','#1e293b','#000000'])) }} ? 'border-amber-400 scale-110' : 'border-slate-600 hover:border-slate-400'"
                            style="background: conic-gradient(red,yellow,lime,cyan,blue,magenta,red)"
                            title="Custom color"
                        >
                            <input type="color" wire:model.live="sceneBackground" class="absolute inset-0 opacity-0 w-full h-full cursor-pointer" />
                        </label>

                        {{-- Skybox image upload — image icon, same swatch style --}}
                        <label
                            class="relative w-9 h-9 rounded-lg border-2 transition-all cursor-pointer flex items-center justify-center bg-slate-800/60 hover:bg-slate-800"
                            :class="skyboxImages.length > 0 ? 'border-amber-400' : 'border-slate-600 hover:border-slate-400'"
                            title="Add skybox images (equirectangular)"
                        >
                            <input type="file" accept="image/png,image/webp,image/jpeg" multiple class="sr-only"
                                @change="onImagesAdd($event.target.files); $event.target.value = ''"
                            />
                            <svg class="w-4 h-4 text-slate-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <polyline points="21 15 16 10 5 21"/>
                            </svg>
                        </label>
                    </div>

                    {{-- Skybox image list (draggable) + add-more + controls --}}
                    <div class="mt-2">

                        {{-- Draggable image rows --}}
                        <template x-for="(img, i) in skyboxImages" :key="img.idbKey">
                            <div
                                class="flex items-center gap-2 mb-1 rounded transition-all select-none"
                                :class="{
                                    'opacity-40': _dragSrcIdx === i,
                                    'ring-1 ring-indigo-400': _dragOverIdx === i && _dragSrcIdx !== i
                                }"
                                draggable="true"
                                @dragstart="dragStart(i)"
                                @dragover.prevent="dragOver(i)"
                                @dragleave="_dragOverIdx = null"
                                @drop.prevent="drop(i)"
                                @dragend="dragEnd()"
                            >
                                <svg class="w-3 h-3 text-slate-600 cursor-grab active:cursor-grabbing shrink-0" viewBox="0 0 16 16" fill="currentColor">
                                    <rect y="2.5"  width="16" height="1.5" rx="0.75"/>
                                    <rect y="7.25" width="16" height="1.5" rx="0.75"/>
                                    <rect y="12"   width="16" height="1.5" rx="0.75"/>
                                </svg>
                                <div class="relative w-9 h-9 rounded-lg overflow-hidden shrink-0 border"
                                     :class="i === 0 ? 'border-amber-400/70' : 'border-indigo-400/50'">
                                    <img :src="img.objectUrl" class="w-full h-full object-cover" alt=""/>
                                </div>
                                <button @click.prevent="onImageRemove(i)"
                                    class="ml-auto text-slate-600 hover:text-red-400 transition-colors text-xs leading-none"
                                    title="Remove image">✕</button>
                            </div>
                        </template>

                        {{-- Add more — + icon square, only when images already exist --}}
                        <label x-show="skyboxImages.length > 0" x-cloak
                            class="mt-1 mb-2 relative w-9 h-9 rounded-lg border-2 border-slate-600 hover:border-slate-400 transition-all cursor-pointer flex items-center justify-center bg-slate-800/60 hover:bg-slate-800"
                            title="Add more images"
                        >
                            <input type="file" accept="image/png,image/webp,image/jpeg" multiple class="sr-only"
                                @change="onImagesAdd($event.target.files); $event.target.value = ''"
                            />
                            <svg class="w-4 h-4 text-slate-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </label>

                        {{-- Controls — only when at least one image --}}
                        <div x-show="skyboxImages.length > 0" x-cloak class="flex flex-col gap-3">

                            {{-- Blur --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-[10px] uppercase tracking-widest text-slate-500">Blur</label>
                                    <span class="text-[10px] font-mono text-indigo-400" x-text="Number(skyboxBlur).toFixed(2)"></span>
                                </div>
                                <input type="range" min="0.01" max="0.9" step="0.01" x-model="skyboxBlur"
                                    @change="window.dispatchEvent(new CustomEvent('avatar3d:setskyboxblur', { detail: { blur: Number(skyboxBlur) } })); _saveSettings();"
                                    class="w-full accent-indigo-500"
                                />
                            </div>

                            {{-- Opacity --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-[10px] uppercase tracking-widest text-slate-500">Opacity</label>
                                    <span class="text-[10px] font-mono text-indigo-400" x-text="Math.round(skyboxOpacity * 100) + '%'"></span>
                                </div>
                                <input type="range" min="0" max="1" step="0.01" x-model="skyboxOpacity"
                                    @input="window.dispatchEvent(new CustomEvent('avatar3d:setskyboxopacity', { detail: { opacity: Number(skyboxOpacity) } }))"
                                    @change="_saveSettings()"
                                    class="w-full accent-indigo-500"
                                />
                            </div>

                            {{-- Grain + color --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-[10px] uppercase tracking-widest text-slate-500">Grain</label>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] font-mono text-indigo-400" x-text="Math.round(skyboxGrain * 100) + '%'"></span>
                                        <label class="relative w-4 h-4 rounded cursor-pointer border border-slate-600 overflow-hidden shrink-0" :style="'background:' + skyboxGrainColor" title="Grain color">
                                            <input type="color" x-model="skyboxGrainColor" @change="_saveSettings()" class="absolute inset-0 opacity-0 w-full h-full cursor-pointer"/>
                                        </label>
                                    </div>
                                </div>
                                <input type="range" min="0" max="0.3" step="0.01" x-model="skyboxGrain" @change="_saveSettings()" class="w-full accent-indigo-500"/>
                            </div>

                            {{-- Transition settings — only when 2+ images --}}
                            <template x-if="skyboxImages.length > 1">
                                <div class="flex flex-col gap-3">

                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="text-[10px] uppercase tracking-widest text-slate-500">Noise color</label>
                                            <div class="flex items-center gap-2">
                                                <span class="text-[10px] font-mono text-indigo-400" x-text="Math.round(skyboxNoiseAlpha * 100) + '%'"></span>
                                                <label class="relative w-4 h-4 rounded cursor-pointer border border-slate-600 overflow-hidden shrink-0" :style="'background:' + skyboxNoiseColor" title="Transition noise color">
                                                    <input type="color" x-model="skyboxNoiseColor"
                                                        @input="window.dispatchEvent(new CustomEvent('avatar3d:setnoisecolor', { detail: { hex: skyboxNoiseColor, alpha: Number(skyboxNoiseAlpha) } }))"
                                                        @change="_saveSettings()"
                                                        class="absolute inset-0 opacity-0 w-full h-full cursor-pointer"/>
                                                </label>
                                            </div>
                                        </div>
                                        <input type="range" min="0" max="1" step="0.05" x-model="skyboxNoiseAlpha"
                                            @input="window.dispatchEvent(new CustomEvent('avatar3d:setnoisecolor', { detail: { hex: skyboxNoiseColor, alpha: Number(skyboxNoiseAlpha) } }))"
                                            @change="_saveSettings()"
                                            class="w-full accent-indigo-500"/>
                                    </div>

                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="text-[10px] uppercase tracking-widest text-slate-500">Image time</label>
                                            <span class="text-[10px] font-mono text-indigo-400" x-text="skyboxHoldTime + 's'"></span>
                                        </div>
                                        <input type="range" min="2" max="30" step="1" x-model="skyboxHoldTime"
                                            @input="window.dispatchEvent(new CustomEvent('avatar3d:settransitiontimes', { detail: { hold: Number(skyboxHoldTime), fade: Number(skyboxFadeTime) } }))"
                                            @change="_saveSettings()"
                                            class="w-full accent-indigo-500"/>
                                    </div>

                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="text-[10px] uppercase tracking-widest text-slate-500">Fade time</label>
                                            <span class="text-[10px] font-mono text-indigo-400" x-text="skyboxFadeTime + 's'"></span>
                                        </div>
                                        <input type="range" min="0.5" max="5" step="0.5" x-model="skyboxFadeTime"
                                            @input="window.dispatchEvent(new CustomEvent('avatar3d:settransitiontimes', { detail: { hold: Number(skyboxHoldTime), fade: Number(skyboxFadeTime) } }))"
                                            @change="_saveSettings()"
                                            class="w-full accent-indigo-500"/>
                                    </div>

                                </div>
                            </template>

                        </div>

                        {{-- Grain overlay driver --}}
                        <div x-effect="
                            (() => {
                                const hex = skyboxGrainColor.replace('#', '');
                                const r = (parseInt(hex.slice(0,2), 16) / 255).toFixed(3);
                                const g = (parseInt(hex.slice(2,4), 16) / 255).toFixed(3);
                                const b = (parseInt(hex.slice(4,6), 16) / 255).toFixed(3);
                                const m = '0 0 0 0 ' + r + ' 0 0 0 0 ' + g + ' 0 0 0 0 ' + b + ' 0.3 0.59 0.11 0 -0.15';
                                const svg = '<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'256\' height=\'256\'><filter id=\'n\'><feTurbulence type=\'fractalNoise\' baseFrequency=\'0.85\' numOctaves=\'3\' stitchTiles=\'stitch\'/><feColorMatrix type=\'matrix\' values=\'' + m + '\'/></filter><rect width=\'256\' height=\'256\' filter=\'url(#n)\'/></svg>';
                                const url = 'data:image/svg+xml,' + encodeURIComponent(svg);
                                const el = document.querySelector('.skybox-grain-overlay');
                                if (el) {
                                    el.style.backgroundImage = 'url(\'' + url + '\')';
                                    el.style.opacity = skyboxImages.length > 0 ? skyboxGrain : 0;
                                }
                            })()
                        "></div>
                    </div>
                </div>

            </div>

        @elseif($activeSection === 'controller')

            <h2 class="text-lg font-semibold text-slate-100 mb-6">Controller</h2>

            @if(! $selectedAvatarId)
                <p class="text-slate-500 text-sm">Select an avatar to view its controller.</p>
            @else
                @foreach(['introduction' => 'Introduction', 'idle' => 'Idle', 'expression' => 'Expressions', 'dance' => 'Dance'] as $cat => $label)
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
            glassesVisible: true, hasGlasses: false,
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
            toggleGlasses() {
                this.glassesVisible = !this.glassesVisible;
                window.dispatchEvent(new CustomEvent('avatar3d:showGlasses', { detail: { visible: this.glassesVisible } }));
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
        x-on:avatar3d:glassesavailable.window="
            hasGlasses = $event.detail.hasGlasses;
            glassesVisible = true;
        "
    >

        {{-- Top overlay: clip name + Use/Remove toggle + glasses toggle --}}
        <div
            class="absolute top-4 right-4 z-10 flex items-center gap-3"
            x-show="clipId !== null || hasGlasses"
            x-cloak
            id="viewport-overlay"
        >
            {{-- Glasses toggle — only shown when avatar has a glasses mesh --}}
            <button
                x-show="hasGlasses"
                @click="toggleGlasses()"
                :class="glassesVisible
                    ? 'bg-slate-800/90 border-slate-600 text-slate-200 hover:border-slate-400'
                    : 'bg-slate-900/90 border-slate-700 text-slate-500 hover:border-slate-500'"
                class="flex items-center gap-1.5 px-3 py-2 border rounded-xl text-xs transition-colors backdrop-blur-sm"
                title="Toggle glasses"
            >
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span x-text="glassesVisible ? 'Glasses on' : 'Glasses off'"></span>
            </button>

            <span x-show="clipId !== null" class="text-slate-300 text-sm font-medium drop-shadow" x-text="clipName"></span>
            <button
                x-show="clipId !== null"
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

        {{-- Bottom control bar: sliders + delete — only in animation tab --}}
        <div
            class="absolute bottom-5 left-1/2 -translate-x-1/2 z-10 w-110 max-w-[calc(100%-2rem)]"
            x-show="clipId !== null && !confirmDelete && $wire.activeSection === 'animation-groups'"
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

        {{-- Canvas — wire:ignore prevents Livewire from touching the WebGL context.
             The canvas is always present so Three.js can init before the GLB arrives.
             A loading overlay fades out once the character is parsed. --}}
        <div
            class="w-full h-full relative"
            wire:ignore
            x-data="{ glbLoading: false, skyboxActive: false }"
            @avatar3d:loadstart.window="glbLoading = true"
            @avatar3d:loadend.window="glbLoading = false"
            @avatar3d:rebuildskybox.window="skyboxActive = true"
            @avatar3d:clearskybox.window="skyboxActive = false"
            @avatar3d:setbg.window="skyboxActive = false"
        >
            {{-- Film grain overlay — opacity driven by the grain slider via x-effect --}}
            <div class="skybox-grain-overlay"></div>

            <canvas
                id="avatar-lab-canvas"
                data-character-url="{{ $selectedAvatarId ? '/avatars/'.$selectedAvatarId.'/character.glb' : '' }}"
                data-azure-key="{{ config('services.azure_speech.key') }}"
                data-azure-region="{{ config('services.azure_speech.region', 'eastus') }}"
                data-prefetch-urls="{{ $avatars->pluck('id')->map(fn($id) => '/avatars/'.$id.'/character.glb')->join(',') }}"
                class="w-full h-full block"
            ></canvas>

            {{-- Viseme debug panel --}}
            <div
                x-data="{ open: false }"
                class="absolute top-2 right-2 z-20 select-none"
            >
                <button
                    x-on:click="open = !open"
                    class="btn btn-xs bg-slate-800/80 border-slate-600 text-slate-400 hover:text-slate-100 font-mono"
                    title="Viseme debug panel"
                >👄 visemes</button>
                <div
                    x-show="open"
                    x-transition
                    class="mt-1 p-2 bg-slate-900/95 border border-slate-700 rounded-xl shadow-xl w-64"
                >
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest mb-2">Oculus visemes</p>
                    <div class="grid grid-cols-5 gap-1">
                        @foreach(['viseme_sil','viseme_PP','viseme_FF','viseme_TH','viseme_DD','viseme_kk','viseme_CH','viseme_SS','viseme_nn','viseme_RR','viseme_aa','viseme_E','viseme_I','viseme_O','viseme_U'] as $v)
                        <button
                            x-on:click="window._avatar3d?.debugViseme('{{ $v }}')"
                            class="btn btn-xs bg-slate-800 border-slate-700 text-slate-300 hover:bg-indigo-700 hover:text-white font-mono text-[9px] px-0 leading-tight"
                            title="{{ $v }}"
                        >{{ str_replace('viseme_', '', $v) }}</button>
                        @endforeach
                    </div>
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest mt-2 mb-1">ARKit jaw/mouth</p>
                    <div class="grid grid-cols-4 gap-1">
                        @foreach(['jawOpen','mouthFunnel','mouthPucker','mouthSmileLeft','mouthSmileRight','mouthRollLower','mouthRollUpper','mouthShrugUpper'] as $v)
                        <button
                            x-on:click="window._avatar3d?.debugViseme('{{ $v }}')"
                            class="btn btn-xs bg-slate-800 border-slate-700 text-slate-300 hover:bg-amber-700 hover:text-white font-mono text-[9px] px-0 leading-tight"
                            title="{{ $v }}"
                        >{{ Str::after($v, 'mouth') ?: $v }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Loading spinner overlay --}}
            <div
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-950/80 pointer-events-none transition-opacity duration-300"
                style="display:none"
                x-show="glbLoading"
            >
                <svg class="w-8 h-8 text-indigo-400 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <p class="text-xs text-slate-400">Loading avatar…</p>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/microsoft-cognitiveservices-speech-sdk@latest/distrib/browser/microsoft.cognitiveservices.speech.sdk.bundle-min.js"></script>
    @vite('resources/js/avatar-3d.js')

    {{-- ── New Avatar Modal ──────────────────────────────────────────────────── --}}
    @if($newAvatarId)
    <dialog class="modal modal-open">
        <div class="modal-box bg-slate-900 border border-slate-700/60 max-w-md w-full">

            {{-- Header --}}
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-slate-100">New Avatar</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        @if($newAvatarMorphStatus === 'processing')
                            <span class="inline-flex items-center gap-1">
                                <span class="loading loading-spinner loading-xs text-amber-400"></span>
                                <span class="text-amber-400">Transferring morph targets…</span>
                            </span>
                        @elseif($newAvatarMorphStatus === 'ready')
                            <span class="text-emerald-400">✓ Morph transfer complete</span>
                        @else
                            <span class="text-red-400">⚠ Morph transfer failed — avatar may lack lip sync</span>
                        @endif
                    </p>
                </div>
                <button wire:click="closeNewAvatarModal" class="btn btn-ghost btn-xs btn-circle text-slate-400">✕</button>
            </div>

            {{-- Tabs --}}
            <div role="tablist" class="tabs tabs-border mb-4">
                <button
                    role="tab"
                    wire:click="$set('newAvatarModalTab', 'info')"
                    class="tab {{ $newAvatarModalTab === 'info' ? 'tab-active text-amber-400' : 'text-slate-400' }}"
                >Info</button>
                <button
                    role="tab"
                    wire:click="$set('newAvatarModalTab', 'voice')"
                    class="tab {{ $newAvatarModalTab === 'voice' ? 'tab-active text-amber-400' : 'text-slate-400' }}"
                >Voice</button>
            </div>

            {{-- Info tab --}}
            @if($newAvatarModalTab === 'info')
            <div class="space-y-4">
                <label class="form-control">
                    <span class="label-text text-slate-400 text-xs mb-1 block">Name</span>
                    <input
                        type="text"
                        wire:model.live.debounce.600ms="newAvatarName"
                        wire:change="saveNewAvatarMeta"
                        placeholder="e.g. Cleopatra"
                        class="input input-sm bg-slate-800 border-slate-700 text-slate-100 w-full"
                        autofocus
                    >
                </label>

                <label class="form-control">
                    <span class="label-text text-slate-400 text-xs mb-1 block">Gender</span>
                    <select
                        wire:model.live="newAvatarGender"
                        wire:change="saveNewAvatarMeta"
                        class="select select-sm bg-slate-800 border-slate-700 text-slate-100 w-full"
                    >
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </label>

                <label class="form-control">
                    <span class="label-text text-slate-400 text-xs mb-1 block">Age</span>
                    <input
                        type="number"
                        min="1"
                        max="120"
                        wire:model.live="newAvatarAge"
                        wire:change="saveNewAvatarMeta"
                        class="input input-sm bg-slate-800 border-slate-700 text-slate-100 w-32"
                    >
                </label>
            </div>
            @endif

            {{-- Voice tab --}}
            @if($newAvatarModalTab === 'voice')
            <div>
                <p class="text-xs text-slate-500 mb-3">
                    Showing {{ $newAvatarGender }} voices.
                </p>
                <div class="flex gap-2 overflow-x-auto pb-2" style="scroll-snap-type: x mandatory">
                    @foreach($this->newAvatarVoices as $voice)
                    @php $isSelected = $newAvatarVoiceId === $voice['id']; @endphp
                    <button
                        wire:click="saveNewAvatarVoice('{{ $voice['id'] }}')"
                        title="{{ $voice['label'] }}"
                        class="shrink-0 snap-start w-18 h-18 rounded-xl border transition-all relative overflow-hidden
                            {{ $isSelected ? 'border-amber-400 ring-2 ring-amber-400/50' : 'border-slate-700/60 hover:border-slate-500' }}
                            {{ $voice['gradient_class'] ?? 'vg-base' }}"
                    >
                        <div class="absolute inset-0 flex flex-col items-center justify-center px-1 text-center">
                            <span class="text-[9px] font-semibold text-white/90 leading-tight line-clamp-3">
                                {{ $voice['label'] }}
                            </span>
                        </div>
                        @if(!empty($voice['preview_url']))
                        <span
                            role="button"
                            tabindex="0"
                            x-on:click.stop="(new Audio('{{ $voice['preview_url'] }}')).play()"
                            class="absolute top-1 right-1 text-white/60 hover:text-white text-xs cursor-pointer"
                        >▶</span>
                        @endif
                    </button>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Footer --}}
            <div class="modal-action mt-6">
                <button
                    wire:click="saveAndCloseNewAvatar"
                    class="btn btn-primary btn-sm"
                    @disabled(!$newAvatarName)
                >Save &amp; Close</button>
            </div>

        </div>
        <div class="modal-backdrop" wire:click="closeNewAvatarModal"></div>
    </dialog>
    @endif

</div>


