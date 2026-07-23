<div class="min-h-screen bg-base-200 text-base-content" wire:key="lesson-wizard-{{ $lesson?->id ?? 'new' }}">

    {{-- Canvas steps (Configure/Preview) are fixed full-screen experiences: kill the page
         scrollbar so the only scroll lives inside the inspector (no double scrollbars). --}}
    @if (in_array((int) $step, [4, 5], true))
        <style>body { overflow: hidden; }</style>

        {{-- Exit the full-screen editor → dashboard. A plain wire:navigate link (no $wire method call,
             so it can never 500 with a MethodNotFound); the teacher edits from Preview via the player's
             "Edit scene" pill and moves between steps with the indicator. --}}
        <a href="{{ route('teacher.dashboard') }}" wire:navigate
           class="fixed left-3 top-3 z-60 inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 bg-base-300 text-slate-300 shadow-lg transition hover:border-amber-400 hover:text-amber-300"
           title="{{ __('Back to dashboard') }}" aria-label="{{ __('Back to dashboard') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
        </a>
    @endif

    {{-- The step indicator is obsolete on the full-screen canvas editors (Configure/Play) —
         the top toolbar + back arrow drive navigation there. Kept on the form steps (1–3). --}}
    @unless (in_array((int) $step, [4, 5], true))
        <x-wizard.step-indicator :step="$step" :lesson="$lesson" />
    @endunless

    {{-- pt-14 leaves room under the fixed step indicator (~h-12 + border). --}}
    <div class="max-w-5xl mx-auto px-4 pt-14 pb-24">
        @if ($step === 1)
            <livewire:wizard.step1-settings :lesson="$lesson" :key="'step1-' . ($lesson?->id ?? 'new')" />
        @elseif ($step === 2)
            <livewire:wizard.step2-story :lesson="$lesson" :key="'step2story-' . $lesson?->id" />
        @elseif ($step === 3)
            <livewire:wizard.step2-generate :lesson="$lesson" :key="'step2gen-' . $lesson?->id" />
        @elseif ($step === 4)
            <livewire:wizard.step3-scene-configurator :lesson="$lesson" :key="'step3-' . $lesson?->id" />
        @elseif ($step === 5)
            <livewire:wizard.step4-preview :lesson="$lesson" :key="'step4-' . $lesson?->id" />
        @endif
    </div>

</div>
