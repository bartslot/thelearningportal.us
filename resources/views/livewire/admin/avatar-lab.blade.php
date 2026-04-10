<div
    class="flex overflow-hidden"
    style="height: calc(100vh - 64px)"
    x-data="{
        openSection: 'animation',
        flickities: {},
        initFlickity() {
            ['idle','presenting','greeting'].forEach(cat => {
                this.flickities[cat]?.destroy();
                const el = this.$el.querySelector('[data-flickity-cat=' + cat + ']');
                if (el && el.children.length) {
                    this.flickities[cat] = new Flickity(el, {
                        freeScroll: true,
                        contain: true,
                        prevNextButtons: false,
                        pageDots: false,
                        cellAlign: 'left',
                    });
                }
            });
        }
    }"
    x-init="
        $nextTick(() => initFlickity());
        $wire.on('clip-uploaded', () => $nextTick(() => initFlickity()));
    "
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
                        class="text-left px-2 py-1.5 rounded-md text-xs transition-colors {{ $activeSection === 'animation-groups' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                    >Animation Groups</button>
                    <button
                        wire:click="$set('activeSection', 'controller')"
                        class="text-left px-2 py-1.5 rounded-md text-xs transition-colors {{ $activeSection === 'controller' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                    >Controller</button>
                </div>
            </div>

            {{-- Narration & Audio --}}
            <div>
                <button
                    @click="openSection = openSection === 'narration' ? null : 'narration'"
                    class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 transition-colors"
                >
                    Narration &amp; Audio
                    <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'narration' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4-4 4"/></svg>
                </button>
            </div>

            {{-- Audio --}}
            <div>
                <button
                    @click="openSection = openSection === 'audio' ? null : 'audio'"
                    class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 transition-colors"
                >
                    Audio
                    <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'audio' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4-4 4"/></svg>
                </button>
                <div x-show="openSection === 'audio'" class="px-2 pt-2 pb-1">
                    <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-1 block">Test Script</label>
                    <textarea
                        wire:model="testScript"
                        rows="4"
                        placeholder="Friends, Romans..."
                        class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-lg px-3 py-2 resize-none focus:outline-none focus:border-indigo-500"
                    ></textarea>
                </div>
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
    <div class="w-[420px] shrink-0 border-r border-slate-700/50 overflow-y-auto p-6 bg-slate-900/30">

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
                        <p class="text-slate-600 text-xs py-4 italic">No clips yet — upload a Mixamo FBX with "In Place" checked.</p>
                    @else
                        <div data-flickity-cat="{{ $cat }}" class="flex gap-3 overflow-hidden">
                            @foreach($clips as $clip)
                                @php
                                    $icons = [
                                        'idle'       => 'standing',
                                        'presenting' => 'walking',
                                        'greeting'   => 'waving',
                                    ];
                                    $icon = $icons[$cat] ?? 'standing';
                                @endphp
                                <div class="shrink-0">
                                    <button
                                        wire:click="loadPreview({{ $clip->id }})"
                                        class="w-36 rounded-xl pt-3 px-3 pb-0 flex flex-col items-center transition-all border
                                            {{ $previewClipId === $clip->id
                                                ? 'border-amber-400 bg-slate-700/80 shadow-lg shadow-amber-500/10'
                                                : 'border-slate-700 bg-slate-800/80 hover:border-indigo-500/50 hover:bg-slate-800' }}"
                                    >
                                        <div class="w-full flex justify-end mb-1">
                                            <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0
                                                {{ $assignedClipIds->contains($clip->id)
                                                    ? 'bg-indigo-500 border-indigo-400 text-white'
                                                    : 'border-slate-500 bg-transparent' }}">
                                                @if($assignedClipIds->contains($clip->id))
                                                    <svg class="w-2.5 h-2.5" viewBox="0 0 10 8" fill="none"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                @endif
                                            </div>
                                        </div>

                                        <x-animation-icon :type="$icon" class="w-16 h-16 text-indigo-400" />

                                        <span class="text-xs text-slate-300 text-center leading-tight mt-2 pb-3 line-clamp-2">
                                            {{ $clip->name }}
                                        </span>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

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
    <div class="flex-1 relative overflow-hidden bg-slate-950">

        {{-- Top overlay: animation name + Use button --}}
        @if($previewClipId)
            <div class="absolute top-4 right-4 z-10 flex items-center gap-3">
                <span class="text-slate-300 text-sm font-medium drop-shadow">{{ $previewClipName }}</span>
                <button
                    wire:click="useClip"
                    class="flex items-center gap-2 px-4 py-2 bg-slate-800/90 border border-slate-600 rounded-xl text-sm text-slate-200 hover:border-indigo-500 transition-colors backdrop-blur-sm"
                >
                    Use
                    <span class="w-5 h-5 bg-indigo-600 rounded-full flex items-center justify-center">
                        <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 10 8" fill="none"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                </button>
            </div>
        @endif

        @if(! $selectedAvatarId)
            <div class="absolute inset-0 flex items-center justify-center text-slate-600 text-sm">
                ← Select an avatar to load the 3D viewport
            </div>
        @else
            <canvas
                id="avatar-lab-canvas"
                class="w-full h-full block"
                x-init="
                    const canvas = $el;
                    const characterUrl = '/avatars/{{ $selectedAvatarId }}/character.glb';
                    if (window.Avatar3DPlayer) {
                        window._avatar3d?.destroy();
                        window._avatar3d = new Avatar3DPlayer(canvas, { characterUrl });
                        window._avatar3d.init().catch(console.error);
                    }
                "
            ></canvas>
        @endif

    </div>

</div>

@vite('resources/js/avatar-3d.js')
