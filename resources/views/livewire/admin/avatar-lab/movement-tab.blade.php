<div class="grid grid-cols-[260px_1fr] min-h-[560px]">

    {{-- ── LEFT PANEL: Clip list ─────────────────────────────────── --}}
    <div class="border-r border-slate-700/50 p-4 flex flex-col gap-3">

        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-slate-200 text-sm">Subject 138 — Walk &amp; Talk</h3>
            <span class="text-xs text-slate-500">{{ $clips->count() }} clips</span>
        </div>

        {{-- Legend --}}
        <div class="flex gap-3 text-[10px] text-slate-500 pb-2 border-b border-slate-700/50">
            <span>✓ assigned</span>
            <span>◉ converted</span>
            <span>○ pending</span>
        </div>

        {{-- Clip list --}}
        <div class="flex flex-col gap-1 overflow-y-auto max-h-[460px] pr-1">
            @foreach($clips->groupBy('name') as $group => $groupClips)
                <div class="text-[10px] uppercase tracking-widest text-indigo-400/70 py-1 mt-2 border-b border-indigo-500/20 first:mt-0">
                    {{ $group }}
                </div>

                @foreach($groupClips as $clip)
                    @php
                        $isSelected  = $selectedClip?->id === $clip->id;
                        $isAssigned  = $assignedClipIds->contains($clip->clip_id);
                        $isConverted = $clip->isConverted();
                    @endphp

                    <button
                        wire:click="selectClip({{ $clip->id }})"
                        class="w-full text-left rounded-lg px-3 py-2 flex items-center justify-between text-xs transition-colors
                            {{ $isSelected
                                ? 'bg-indigo-500/20 border-2 border-indigo-500 text-slate-100'
                                : ($isAssigned
                                    ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-200 hover:bg-emerald-500/15'
                                    : 'bg-slate-800/50 border border-slate-700/50 text-slate-300 hover:bg-slate-700/50') }}"
                    >
                        <span>
                            <span class="font-mono text-[10px] {{ $isSelected ? 'text-indigo-400' : 'text-slate-500' }} mr-2">{{ $clip->clip_id }}</span>
                            {{ $clip->name }}
                        </span>
                        <span class="ml-2 shrink-0 {{ $isAssigned ? 'text-emerald-400' : ($isConverted ? 'text-indigo-400' : 'text-slate-600') }}">
                            {{ $isAssigned ? '✓' : ($isConverted ? '◉' : '○') }}
                        </span>
                    </button>
                @endforeach
            @endforeach
        </div>

        {{-- Bone map info --}}
        <div class="mt-auto border-t border-slate-700/50 pt-3">
            @php
                $boneMapFile = public_path('avatars/animations/bone_maps/cmu_to_rigify.json');
                $boneCount = 0;
                if (file_exists($boneMapFile)) {
                    $boneMapData = json_decode(file_get_contents($boneMapFile), true);
                    $boneCount = count($boneMapData['bones'] ?? []);
                }
            @endphp
            <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-3 py-2 text-xs flex justify-between items-center">
                <div>
                    <span class="text-emerald-400 font-semibold">🗺️ CMU → Rigify</span>
                    <span class="text-slate-500 ml-2">{{ $boneCount }} bones mapped</span>
                </div>
                <a href="{{ asset('avatars/animations/bone_maps/cmu_to_rigify.json') }}" target="_blank" class="text-emerald-400/60 hover:text-emerald-400 text-[10px]">View</a>
            </div>
        </div>

    </div>

    {{-- ── RIGHT PANEL: Convert + Preview + Assign ────────────────── --}}
    <div class="p-5 flex flex-col gap-4">
        @if($selectedClip)
            @include('livewire.admin.avatar-lab.movement-right-panel')
        @else
            <div class="flex items-center justify-center h-full text-slate-500 text-sm">
                ← Select a clip to preview and assign
            </div>
        @endif
    </div>

</div>
