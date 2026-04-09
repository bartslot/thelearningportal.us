<div>
    {{-- Tab bar --}}
    <div class="flex border-b border-slate-700 mb-6">
        <button
            wire:click="$set('activeTab', 'preview')"
            class="px-5 py-3 text-sm font-medium transition-colors {{ $activeTab === 'preview' ? 'text-indigo-400 border-b-2 border-indigo-500' : 'text-slate-400 hover:text-slate-200' }}"
        >
            Preview
        </button>
        <button
            wire:click="$set('activeTab', 'movement')"
            class="px-5 py-3 text-sm font-medium transition-colors {{ $activeTab === 'movement' ? 'text-indigo-400 border-b-2 border-indigo-500' : 'text-slate-400 hover:text-slate-200' }}"
        >
            Movement
        </button>
    </div>

    {{-- Preview tab --}}
    @if($activeTab === 'preview')
        <div class="text-slate-400 text-sm p-8 text-center opacity-60">
            3D Preview coming soon — see 3D Avatar Lab spec.
        </div>
    @endif

    {{-- Movement tab --}}
    @if($activeTab === 'movement')
        @include('livewire.admin.avatar-lab.movement-tab')
    @endif

    {{-- Polling for conversion status --}}
    @if($polling)
        <div wire:poll.1s="pollConversionStatus"></div>
    @endif
</div>
