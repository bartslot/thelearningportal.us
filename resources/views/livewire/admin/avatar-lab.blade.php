@push('head-scripts')
    @vite('resources/js/avatar-3d.js')
@endpush

<div
    x-data="{
        mode: '3d',
        presentationMode: '{{ $presentationMode }}',
        cameraLocked: true,
        activePose: 'relaxed',

        initPlayer() {
            if (this.mode !== '3d') return
            const canvas = this.$refs.canvas3d
            if (!canvas || !canvas.dataset.characterUrl) return

            window._avatar3d?.destroy()
            window._avatar3d = null

            const player = new window.Avatar3DPlayer(canvas, {
                characterUrl:     canvas.dataset.characterUrl,
                frameBackground:  canvas.dataset.bg,
                presentationMode: this.presentationMode,
            })
            window._avatar3d = player
            player.init().then(() => {
                this.cameraLocked = true
                this.activePose   = 'relaxed'
            })
        },

        setPresentation(val) {
            this.presentationMode = val
            $wire.set('presentationMode', val)
            window._avatar3d?.setMode(val)
            this.cameraLocked = true
        },

        toggleCameraLock() {
            this.cameraLocked = window._avatar3d?.toggleLock() ?? this.cameraLocked
        },

        setPose(name) {
            this.activePose = name
            window._avatar3d?.applyPose(name)
        },

        setBackground(hex) {
            $wire.set('frameBackground', hex)
            window._avatar3d?.setBackground(hex)
        }
    }"
    x-init="initPlayer()"
    class="flex h-[82vh] bg-slate-900 rounded-xl border border-white/5 overflow-hidden"
>
    {{-- ── Settings sidebar ──────────────────────────────── --}}
    <div class="w-60 flex-shrink-0 border-r border-white/5 flex flex-col overflow-y-auto p-4 gap-5 text-sm text-slate-200">

        {{-- Avatar picker --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Avatar</p>
            <select wire:model.live="avatarId" wire:change="selectAvatar($event.target.value)"
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-slate-200 text-xs">
                <option value="">— select avatar —</option>
                @foreach($avatars as $av)
                    <option value="{{ $av->id }}">{{ $av->name }}</option>
                @endforeach
            </select>
        </div>

        @if($avatarId)
        {{-- Gender --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Gender</p>
            <div class="flex border border-white/10 rounded-lg overflow-hidden">
                <button wire:click="$set('gender','male')"
                        class="flex-1 py-1.5 text-xs font-semibold {{ $gender === 'male' ? 'bg-indigo-600 text-white' : 'bg-transparent text-slate-400' }}">
                    ♂ Male
                </button>
                <button wire:click="$set('gender','female')"
                        class="flex-1 py-1.5 text-xs font-semibold {{ $gender === 'female' ? 'bg-indigo-600 text-white' : 'bg-transparent text-slate-400' }}">
                    ♀ Female
                </button>
            </div>
        </div>

        {{-- Age --}}
        <div>
            <div class="flex justify-between mb-1">
                <span class="text-slate-400 text-xs">Age</span>
                <span class="text-indigo-400 font-bold text-xs">{{ $age }}</span>
            </div>
            <input type="range" wire:model.live="age" min="8" max="80"
                   class="w-full accent-indigo-500">
            <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5">
                <span>Child</span><span>Teen</span><span>Adult</span><span>Elder</span>
            </div>
        </div>

        {{-- Presentation --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Presentation</p>
            @foreach(['fullscreen' => 'Fullscreen Storyteller', 'framed' => 'Framed Portrait'] as $val => $label)
            <button @click="setPresentation('{{ $val }}')"
                    :class="presentationMode === '{{ $val }}' ? 'border-indigo-500 bg-indigo-500/10 text-indigo-300' : 'border-white/[0.08] text-slate-400 hover:border-white/20'"
                    class="w-full text-left px-3 py-2 mb-1 rounded-lg text-xs border transition">
                {{ $label }}
            </button>
            @endforeach

            {{-- Background colour --}}
            <div class="flex items-center justify-between mt-2">
                <span class="text-slate-500 text-xs">Background</span>
                <input type="color" value="{{ $frameBackground }}"
                       @input="setBackground($event.target.value)"
                       class="w-7 h-6 rounded border border-white/10 bg-transparent cursor-pointer p-0">
            </div>
        </div>

        {{-- Pose presets --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Pose</p>
            <div class="flex flex-col gap-1">
                @foreach([
                    'relaxed'    => ['🧍', 'Relaxed',   'Arms at sides, natural'],
                    'presenting' => ['🙋', 'Presenting', 'Right arm raised, engaged'],
                    'leaning'    => ['↗️',  'Leaning',    'Forward lean, storytelling'],
                    'sitting'    => ['🪑', 'Sitting',    'Seated, legs forward'],
                    'tpose'      => ['✈️',  'T-Pose',     'Bind pose / reference'],
                ] as $pose => [$icon, $label, $hint])
                <button
                    @click="setPose('{{ $pose }}')"
                    :class="activePose === '{{ $pose }}'
                        ? 'border-indigo-500 bg-indigo-500/10 text-indigo-300'
                        : 'border-white/[0.08] text-slate-400 hover:border-white/20 hover:text-slate-300'"
                    class="flex items-center gap-2.5 w-full text-left px-2.5 py-2 rounded-lg text-xs border transition"
                    title="{{ $hint }}"
                >
                    <span class="text-sm leading-none">{{ $icon }}</span>
                    <span class="font-medium">{{ $label }}</span>
                    <span class="ml-auto text-[0.6rem] text-slate-600 hidden group-hover:block">{{ $hint }}</span>
                </button>
                @endforeach
            </div>
        </div>

        {{-- Emotion & Voice --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Emotion &amp; Voice</p>

            <div class="bg-indigo-500/10 border border-indigo-500/40 rounded-lg p-2.5 mb-2">
                <div class="flex justify-between items-center">
                    <span class="text-indigo-300 font-bold text-xs">✦ Auto</span>
                    <span class="bg-indigo-600 text-white text-[0.55rem] px-1.5 py-0.5 rounded font-semibold
                                 {{ $emotionStyle === 'auto' ? '' : 'opacity-40' }}">DEFAULT</span>
                </div>
                <p class="text-slate-500 text-[0.65rem] mt-0.5">Uses <code class="text-indigo-400">[cheerful]</code>…<code class="text-indigo-400">[/cheerful]</code> tags</p>
            </div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-600 mb-1">Override entire lesson</p>
            <div class="grid grid-cols-2 gap-1">
                @foreach(['narrative' => '📖 Narrative', 'cheerful' => '🎉 Cheerful', 'serious' => '🎓 Serious', 'excited' => '🤩 Excited', 'empathetic' => '😢 Empathetic', 'whispering' => '🕵️ Whispering'] as $val => $label)
                <button wire:click="$set('emotionStyle','{{ $val }}')"
                        class="text-[0.7rem] py-1.5 rounded-md border transition
                               {{ $emotionStyle === $val ? 'border-indigo-500 bg-indigo-500/15 text-indigo-300' : 'border-white/[0.08] text-slate-500 hover:border-white/20' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>
            @if($emotionStyle !== 'auto')
            <button wire:click="$set('emotionStyle','auto')" class="w-full text-[0.65rem] text-slate-500 mt-1 hover:text-slate-300">↩ reset to Auto</button>
            @endif

            <div class="mt-3">
                <div class="flex justify-between mb-1">
                    <span class="text-slate-400 text-xs">Expressiveness</span>
                    <span class="text-indigo-400 font-bold text-xs">{{ number_format($expressiveness, 1) }}×</span>
                </div>
                <input type="range" wire:model.live="expressiveness" min="0.1" max="2.0" step="0.1" class="w-full accent-indigo-500">
                <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5"><span>Subtle</span><span>Natural</span><span>Dramatic</span></div>
            </div>

            <div class="mt-2">
                <div class="flex justify-between mb-1">
                    <span class="text-slate-400 text-xs">Speaking speed</span>
                    <span class="text-indigo-400 font-bold text-xs">{{ number_format($speakingSpeed, 1) }}×</span>
                </div>
                <input type="range" wire:model.live="speakingSpeed" min="0.5" max="2.0" step="0.1" class="w-full accent-indigo-500">
                <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5"><span>Slow</span><span>Normal</span><span>Fast</span></div>
            </div>
        </div>

        {{-- Test script --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Test Script</p>
            <textarea wire:model="testScript" rows="4"
                      placeholder="Friends, Romans… [serious]Heavy losses.[/serious]"
                      class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-200 resize-none focus:border-indigo-500 focus:outline-none"></textarea>
        </div>

        {{-- Actions --}}
        <div class="flex flex-col gap-2 mt-auto">
            <button wire:click="generatePreview" wire:loading.attr="disabled"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold py-2.5 rounded-lg transition disabled:opacity-50">
                <span wire:loading.remove wire:target="generatePreview">▶ Generate Preview</span>
                <span wire:loading wire:target="generatePreview">Generating…</span>
            </button>
            <button wire:click="saveSettings"
                    class="w-full border border-white/10 text-slate-400 hover:text-white text-xs py-2 rounded-lg transition">
                Save Settings
            </button>
        </div>

        @if(session('message'))
            <p class="text-emerald-400 text-xs text-center">{{ session('message') }}</p>
        @endif
        @endif
    </div>

    {{-- ── Viewport ──────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col min-w-0">

        {{-- Top bar --}}
        <div class="flex items-center justify-between px-5 py-2.5 border-b border-white/5 bg-white/[0.02] flex-shrink-0 z-10">
            <span class="text-sm font-semibold text-slate-200">🧪 Avatar Lab</span>

            <div class="flex items-center gap-1 bg-white/5 border border-white/10 rounded-lg p-1">
                <button @click="mode='2d'"
                        :class="mode==='2d' ? 'bg-white/10 text-white' : 'text-slate-500'"
                        class="px-3 py-1 rounded-md text-xs font-semibold transition">2D</button>
                <button @click="mode='3d'; initPlayer()"
                        :class="mode==='3d' ? 'bg-indigo-600 text-white' : 'text-slate-500'"
                        class="px-3 py-1 rounded-md text-xs font-semibold transition">3D ✦</button>
            </div>

            <span class="text-[0.65rem] text-slate-600">Admin only · Experimental</span>
        </div>

        {{-- Canvas area --}}
        <div class="flex-1 relative overflow-hidden">

            @if(!$avatarId)
                <div class="absolute inset-0 flex items-center justify-center">
                    <p class="text-slate-500 text-sm">← Select an avatar to begin</p>
                </div>

            @elseif(!$hasCharacter)
                <div class="absolute inset-0 flex items-center justify-center">
                    <div class="text-center text-slate-500 text-sm max-w-xs">
                        <p class="text-2xl mb-3">📦</p>
                        <p class="font-semibold text-slate-400 mb-1">No 3D character uploaded yet</p>
                        <p class="text-xs leading-relaxed">
                            Place your MPFB GLB export at<br>
                            <code class="text-indigo-400">public/avatars/{{ $avatarId }}/character.glb</code>
                        </p>
                    </div>
                </div>

            @else
                {{--
                    Single canvas element. Its wrapper changes shape based on presentationMode:
                      - fullscreen: fills the entire pane (absolute inset-0)
                      - framed: centered portrait with rounded corners
                    We use x-show on the two wrapper divs and move the canvas between them via
                    a shared x-ref="canvas3d". Because x-show keeps both in the DOM, we render
                    the canvas inside only the active wrapper and keep the other wrapper empty.
                    Simplest: one canvas always present, wrapper changes via :class bindings.
                --}}
                <div
                    x-show="mode === '3d'"
                    x-cloak
                    class="absolute inset-0"
                    :class="presentationMode === 'fullscreen' ? 'flex' : 'flex items-center justify-center'"
                    style="background: radial-gradient(ellipse at 50% 80%, rgba(99,102,241,0.04) 0%, transparent 70%);"
                >
                    {{-- Canvas wrapper — shape varies by mode --}}
                    <div
                        :class="presentationMode === 'fullscreen'
                            ? 'absolute inset-0'
                            : 'relative flex-shrink-0'"
                        :style="presentationMode === 'framed'
                            ? 'width:300px;height:300px;border-radius:999px;overflow:hidden;ring:10px solid rgba(255,255,255,0.05);'
                            : ''"
                    >
                        <canvas
                            x-ref="canvas3d"
                            wire:ignore
                            data-character-url="{{ asset('avatars/' . $avatarId . '/character.glb') }}"
                            data-bg="{{ $frameBackground }}"
                            class="block w-full h-full"
                        ></canvas>

                        {{-- Camera controls panel — fullscreen only --}}
                        <template x-if="presentationMode === 'fullscreen'">
                            <div class="absolute top-3 right-3 bg-black/55 backdrop-blur-sm border border-white/10 rounded-xl p-2.5 flex flex-col gap-2 min-w-[118px]">

                                {{-- Section label --}}
                                <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 font-semibold leading-none px-0.5">Camera</p>

                                {{-- Lock / Free toggle --}}
                                <button
                                    @click="toggleCameraLock()"
                                    :class="cameraLocked
                                        ? 'border-white/10 text-slate-300 hover:border-white/25 hover:text-white'
                                        : 'border-indigo-500/60 bg-indigo-600/25 text-indigo-300 hover:bg-indigo-600/40'"
                                    class="flex items-center gap-2 w-full border rounded-lg px-2.5 py-1.5 text-[0.7rem] font-medium transition"
                                >
                                    {{-- closed padlock --}}
                                    <svg x-show="cameraLocked" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                    {{-- open padlock --}}
                                    <svg x-show="!cameraLocked" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>
                                    </svg>
                                    <span x-text="cameraLocked ? 'Locked' : 'Free orbit'"></span>
                                </button>

                                {{-- Reset — only shown when unlocked --}}
                                <button
                                    x-show="!cameraLocked"
                                    @click="window._avatar3d?.resetCamera()"
                                    class="flex items-center gap-2 w-full border border-white/10 hover:border-white/25 text-slate-400 hover:text-white rounded-lg px-2.5 py-1.5 text-[0.7rem] font-medium transition"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
                                    </svg>
                                    Reset view
                                </button>
                            </div>
                        </template>
                    </div>

                    {{-- Status overlay (fullscreen: floating bottom-centre; framed: below portrait) --}}
                    @if($previewStatus === 'generating')
                        <div wire:poll.2s="pollPreviewStatus"
                             :class="presentationMode === 'fullscreen'
                                 ? 'absolute bottom-4 left-1/2 -translate-x-1/2 bg-black/60 backdrop-blur border-white/10'
                                 : 'absolute bottom-4 left-1/2 -translate-x-1/2 bg-white/5 border-white/[0.08]'"
                             class="flex items-center gap-2 border rounded-lg px-4 py-2 text-xs text-slate-300 whitespace-nowrap">
                            <span class="animate-pulse w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                            Generating with Azure Speech…
                        </div>
                    @elseif($previewStatus === 'ready')
                        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-3">
                            <div
                                :class="presentationMode === 'fullscreen' ? 'bg-black/60 backdrop-blur border-white/10' : 'bg-white/5 border-white/[0.08]'"
                                class="flex items-center gap-2 border rounded-lg px-4 py-2 text-xs text-slate-300 whitespace-nowrap"
                            >
                                <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                                Ready · {{ $gender }} · age {{ $age }} · {{ $emotionStyle }}
                            </div>
                            <button @click="window._avatar3d?.play()"
                                    class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2 rounded-lg transition whitespace-nowrap">
                                ▶ Play
                            </button>
                        </div>
                    @elseif($previewStatus === 'error')
                        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 bg-rose-500/20 backdrop-blur border border-rose-500/40 rounded-lg px-4 py-2 text-xs text-rose-400 max-w-sm text-center">
                            ⚠ {{ $previewError }}
                        </div>
                    @endif
                </div>

                {{-- 2D placeholder --}}
                <div
                    x-show="mode === '2d'"
                    x-cloak
                    class="absolute inset-0 flex items-center justify-center"
                >
                    <p class="text-slate-600 text-sm">2D preview not available in Avatar Lab</p>
                </div>
            @endif
        </div>
    </div>
</div>
