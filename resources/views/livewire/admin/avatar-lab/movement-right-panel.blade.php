{{-- Clip header --}}
<div class="bg-indigo-500/10 border border-indigo-500/20 rounded-xl px-4 py-3 flex items-start justify-between">
    <div>
        <div class="font-mono text-[11px] text-indigo-400 mb-1">
            public/{{ $selectedClip->fbx_path }}
        </div>
        <div class="text-slate-100 font-semibold text-sm">{{ $selectedClip->name }}</div>
        <div class="text-slate-500 text-[11px] mt-0.5">{{ $selectedClip->clip_id }} · CMU Subject 138 · free to use</div>
    </div>

    {{-- Status badge --}}
    @php
        $badgeClass = match($selectedClip->status) {
            'ready'      => 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
            'converting' => 'bg-amber-500/20 text-amber-400 border-amber-500/30',
            'failed'     => 'bg-red-500/20 text-red-400 border-red-500/30',
            default      => 'bg-slate-700 text-slate-400 border-slate-600',
        };
        $badgeText = match($selectedClip->status) {
            'ready'      => '◉ converted',
            'converting' => '⏳ converting…',
            'failed'     => '✕ failed',
            default      => '○ pending',
        };
    @endphp
    <span class="text-[10px] border rounded-full px-2 py-1 {{ $badgeClass }}">{{ $badgeText }}</span>
</div>

{{-- fbx2gltf not installed warning --}}
@if(! $fbx2gltfInstalled)
    <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl px-4 py-3 text-xs text-amber-300">
        ⚠️ <strong>fbx2gltf not found.</strong>
        Install from <a href="https://github.com/facebookincubator/FBX2glTF" target="_blank" class="underline">github.com/facebookincubator/FBX2glTF</a>
        and set <code>FBX2GLTF_BINARY</code> in your <code>.env</code>.
    </div>
@endif

{{-- Convert button --}}
@if(in_array($selectedClip->status, ['pending', 'failed']))
    <button
        wire:click="convertClip({{ $selectedClip->id }})"
        @disabled(! $fbx2gltfInstalled)
        class="w-full bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-semibold rounded-xl py-2.5 text-sm transition-colors"
    >
        ⚙️ Convert to GLB
    </button>

    @if($selectedClip->conversion_error)
        <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-4 py-3 text-[11px] text-red-300 font-mono whitespace-pre-wrap break-all">
            {{ $selectedClip->conversion_error }}
        </div>
    @endif
@endif

@if($selectedClip->status === 'converting')
    <div class="flex items-center gap-3 text-amber-400 text-sm py-2">
        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Converting… this may take 10–30 seconds
    </div>
@endif

{{-- Three.js preview canvas --}}
<div
    class="relative bg-black/50 rounded-xl border border-slate-700/50 overflow-hidden"
    style="height: 220px;"
    id="movement-preview-container"
>
    @if($selectedClip->isConverted())
        <canvas
            id="movement-preview-canvas"
            class="w-full h-full"
            data-glb-url="{{ asset($selectedClip->glb_path) }}"
            data-avatar-glb="{{ $avatar->glb_path ? asset($avatar->glb_path) : '' }}"
        ></canvas>

        {{-- Badges --}}
        <div class="absolute top-2 left-2 flex gap-2 text-[10px]">
            <span class="bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 rounded px-2 py-0.5">✓ root motion stripped</span>
            <span class="bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 rounded px-2 py-0.5">✓ bone map applied</span>
        </div>

        {{-- Playback controls --}}
        <div class="absolute bottom-2 left-0 right-0 flex justify-center gap-2">
            <button id="movement-play-btn" class="bg-indigo-600/80 hover:bg-indigo-500/80 text-white rounded-full px-4 py-1.5 text-xs">▶ Play</button>
            <button id="movement-stop-btn" class="bg-slate-700/80 hover:bg-slate-600/80 text-white rounded-full px-4 py-1.5 text-xs">⏹ Stop</button>
            <button id="movement-loop-btn" class="bg-slate-700/80 hover:bg-slate-600/80 text-white rounded-full px-4 py-1.5 text-xs">🔁 Loop</button>
        </div>
    @else
        <div class="flex items-center justify-center h-full text-slate-500 text-sm">
            @if($selectedClip->status === 'converting')
                Converting…
            @else
                Convert clip to preview animation
            @endif
        </div>
    @endif
</div>

{{-- ── Animation Controller Assignment ───────────────────────────────── --}}
<div class="border-t border-slate-700/50 pt-4">
    <h4 class="font-semibold text-slate-200 text-sm mb-3">🎮 Assign to Controller</h4>

    @php
        $slots = [
            'idle'            => ['label' => '🧍 Idle',       'color' => 'indigo'],
            'walk'            => ['label' => '🚶 Walk',        'color' => 'emerald'],
            'gesture'         => ['label' => '👉 Gesture',     'color' => 'amber'],
            'emotion_excited' => ['label' => '😄 [excited]',   'color' => 'orange'],
            'emotion_serious' => ['label' => '😐 [serious]',   'color' => 'slate'],
            'emotion_whisper' => ['label' => '🤫 [whisper]',   'color' => 'purple'],
        ];

        $controllerData = $controller?->controller ?? \App\Models\AvatarAnimationController::defaultControllerData();
    @endphp

    <div class="flex flex-col gap-3">
        @foreach($slots as $slotKey => $slotMeta)
            @php
                $slotData   = $controllerData['slots'][$slotKey] ?? ['mode' => 'random', 'clips' => []];
                $slotClips  = $slotData['clips'] ?? [];
                $slotMode   = $slotData['mode']  ?? 'random';
                $inThisSlot = in_array($selectedClip->clip_id, $slotClips, true);
            @endphp

            <div class="bg-slate-800/60 rounded-xl p-3 border border-slate-700/40">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-300">{{ $slotMeta['label'] }}</span>

                    {{-- Mode toggle --}}
                    <div class="flex bg-slate-900/60 rounded-full p-0.5 gap-0.5 text-[10px]">
                        <button
                            wire:click="setSlotMode('{{ $slotKey }}', 'random')"
                            class="{{ $slotMode === 'random' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-slate-200' }} rounded-full px-2.5 py-0.5 transition-colors"
                        >random</button>
                        <button
                            wire:click="setSlotMode('{{ $slotKey }}', 'sequential')"
                            class="{{ $slotMode === 'sequential' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-slate-200' }} rounded-full px-2.5 py-0.5 transition-colors"
                        >sequential</button>
                    </div>
                </div>

                {{-- Current pool --}}
                @if(count($slotClips) > 0)
                    <div class="flex flex-wrap gap-1.5 mb-2">
                        @foreach($slotClips as $poolClipId)
                            @php $poolClip = $clips->firstWhere('clip_id', $poolClipId); @endphp
                            @if($poolClip)
                                <span class="flex items-center gap-1 bg-slate-700/60 border border-slate-600/50 rounded-full pl-2 pr-1 py-0.5 text-[10px] text-slate-300">
                                    <span class="font-mono">{{ $poolClipId }}</span>
                                    <button
                                        wire:click="removeClipFromSlot({{ $poolClip->id }}, '{{ $slotKey }}')"
                                        class="text-slate-500 hover:text-red-400 transition-colors leading-none"
                                        title="Remove from pool"
                                    >×</button>
                                </span>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="text-[10px] text-slate-600 mb-2 italic">No clips assigned</p>
                @endif

                {{-- Add / Already in pool --}}
                @if($inThisSlot)
                    <span class="text-[10px] text-emerald-400">✓ {{ $selectedClip->clip_id }} is in this pool</span>
                @else
                    <button
                        wire:click="assignClipToSlot({{ $selectedClip->id }}, '{{ $slotKey }}')"
                        class="text-[10px] text-indigo-400 hover:text-indigo-300 border border-indigo-500/30 hover:border-indigo-400/50 rounded-full px-3 py-0.5 transition-colors"
                    >
                        + Add {{ $selectedClip->clip_id }} to pool
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</div>
