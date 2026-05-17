<div class="min-h-screen bg-base-200 text-base-content" wire:key="lesson-wizard-{{ $lesson?->id ?? 'new' }}">

    <x-wizard.step-indicator :step="$step" />

    <div class="max-w-5xl mx-auto px-4 pb-24">
        @if ($step === 1)
            <livewire:wizard.step1-settings :lesson="$lesson" :key="'step1-' . ($lesson?->id ?? 'new')" />
        @elseif ($step === 2)
            <livewire:wizard.step2-generate :lesson="$lesson" :key="'step2-' . $lesson?->id" />
        @elseif ($step === 3)
            <livewire:wizard.step3-scene-configurator :lesson="$lesson" :key="'step3-' . $lesson?->id" />
        @elseif ($step === 4)
            <livewire:wizard.step4-preview :lesson="$lesson" :key="'step4-' . $lesson?->id" />
        @endif
    </div>

</div>
