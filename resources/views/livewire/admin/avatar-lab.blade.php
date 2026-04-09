<div
    x-data="{
        mode: '3d',
        player: null,
        initPlayer() {
            if (this.mode !== '3d') return
            const canvas = this.$refs.canvas3d
            if (!canvas || !canvas.dataset.characterUrl) return
            import('/build/assets/avatar-3d.js').then(({ Avatar3DPlayer }) => {
                if (this.player) this.player.destroy()
                this.player = new Avatar3DPlayer(canvas, {
                    characterUrl:    canvas.dataset.characterUrl,
                    frameBackground: canvas.dataset.bg,
                })
                window._avatar3d = this.player
                this.player.init()
            })
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
            @foreach(['fullscreen' => 'Fullscreen Storyteller', 'framed' => 'Framed', 'hidden' => 'Hidden (audio only)'] as $val => $label)
            <button wire:click="$set('presentationMode','{{ $val }}')"
                    class="w-full text-left px-3 py-2 mb-1 rounded-lg text-xs border transition
                           {{ $presentationMode === $val ? 'border-indigo-500 bg-indigo-500/10 text-indigo-300' : 'border-white/8 text-slate-400 hover:border-white/20' }}">
                {{ $label }}
            </button>
            @endforeach
            @if($presentationMode === 'framed')
            <div class="flex items-center justify-between mt-1">
                <span class="text-slate-500 text-xs">Frame bg</span>
                <input type="color" wire:model.live="frameBackground"
                       class="w-7 h-6 rounded border border-white/10 bg-transparent cursor-pointer p-0">
            </div>
            @endif
        </div>

        {{-- Emotion & Voice --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Emotion &amp; Voice</p>

            {{-- Speaking style --}}
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
                               {{ $emotionStyle === $val ? 'border-indigo-500 bg-indigo-500/15 text-indigo-300' : 'border-white/8 text-slate-500 hover:border-white/20' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>
            @if($emotionStyle !== 'auto')
            <button wire:click="$set('emotionStyle','auto')" class="w-full text-[0.65rem] text-slate-500 mt-1 hover:text-slate-300">↩ reset to Auto</button>
            @endif

            {{-- Expressiveness --}}
            <div class="mt-3">
                <div class="flex justify-between mb-1">
                    <span class="text-slate-400 text-xs">Expressiveness</span>
                    <span class="text-indigo-400 font-bold text-xs">{{ number_format($expressiveness, 1) }}×</span>
                </div>
                <input type="range" wire:model.live="expressiveness" min="0.1" max="2.0" step="0.1" class="w-full accent-indigo-500">
                <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5"><span>Subtle</span><span>Natural</span><span>Dramatic</span></div>
            </div>

            {{-- Speed --}}
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
    <div class="flex-1 flex flex-col">

        {{-- Top bar --}}
        <div class="flex items-center justify-between px-5 py-2.5 border-b border-white/5 bg-white/[0.02]">
            <span class="text-sm font-semibold text-slate-200">🧪 Avatar Lab</span>

            {{-- 2D / 3D toggle --}}
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
        <div class="flex-1 flex flex-col items-center justify-center gap-4 relative"
             style="background: radial-gradient(ellipse at 50% 80%, rgba(99,102,241,0.05) 0%, transparent 70%);">

            @if(!$avatarId)
                <p class="text-slate-500 text-sm">← Select an avatar to begin</p>

            @elseif(!$hasCharacter)
                <div class="text-center text-slate-500 text-sm max-w-xs">
                    <p class="text-2xl mb-3">📦</p>
                    <p class="font-semibold text-slate-400 mb-1">No 3D character uploaded yet</p>
                    <p class="text-xs leading-relaxed">
                        Place your MPFB GLB export at<br>
                        <code class="text-indigo-400">public/avatars/{{ $avatarId }}/character.glb</code><br>
                        and a morph map at<br>
                        <code class="text-indigo-400">public/avatars/{{ $avatarId }}/morph-map.json</code>
                    </p>
                </div>

            @else
                {{-- 3D canvas --}}
                <div x-show="mode==='3d'" class="relative">
                    <canvas
                        x-ref="canvas3d"
                        wire:ignore
                        data-character-url="{{ asset('avatars/' . $avatarId . '/character.glb') }}"
                        data-bg="{{ $frameBackground }}"
                        class="rounded-xl shadow-2xl"
                        style="width:300px;height:400px;"
                    ></canvas>
                </div>

                {{-- Status bar --}}
                @if($previewStatus === 'generating')
                    <div wire:poll.2s="pollPreviewStatus"
                         class="flex items-center gap-2 bg-white/5 border border-white/8 rounded-lg px-4 py-2 text-xs text-slate-400">
                        <span class="animate-pulse w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                        Generating with Azure Speech…
                    </div>
                @elseif($previewStatus === 'ready')
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 bg-white/5 border border-white/8 rounded-lg px-4 py-2 text-xs text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                            Ready · {{ $gender }} · age {{ $age }} · {{ $emotionStyle }}
                        </div>
                        <button @click="window._avatar3d?.play()"
                                class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2 rounded-lg transition">
                            ▶ Play
                        </button>
                    </div>
                @elseif($previewStatus === 'error')
                    <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg px-4 py-2 text-xs text-rose-400 max-w-sm text-center">
                        ⚠ {{ $previewError }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
