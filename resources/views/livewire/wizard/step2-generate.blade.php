<div class="pt-6 space-y-6" wire:poll.2s>

    @if ($lesson->status === \App\Enums\LessonStatus::Failed)
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-2xl p-6">
            <h2 class="text-lg font-semibold text-rose-300">Generation failed</h2>
            <p class="text-sm text-rose-200/80 mt-1">{{ $lesson->error_message }}</p>
            <button wire:click="retryOutline"
                    class="mt-4 btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Retry outline</button>
        </div>
    @else
        <header class="space-y-1">
            <h2 class="text-lg font-semibold text-amber-300">Generating your lesson…</h2>
            <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                <div class="h-full bg-amber-500 transition-all duration-500"
                     style="width: {{ $this->overallProgress }}%"></div>
            </div>
            <p class="text-xs text-slate-400">{{ $this->overallProgress }}% complete</p>
        </header>

        <div class="space-y-3">
            @foreach ($this->scenes as $scene)
                <div @class([
                    'rounded-2xl p-4 flex items-start gap-4 border',
                    'border-rose-500/40 bg-rose-500/5'    => $scene->status === 'failed',
                    'border-emerald-500/40 bg-emerald-500/5' => $scene->status === 'ready',
                    'border-slate-700 bg-slate-900/40'    => ! in_array($scene->status, ['failed','ready']),
                ])>
                    <div class="text-sm font-semibold text-slate-200 w-24 shrink-0">
                        Scene {{ $scene->order }}
                        @if ($scene->kind === 'game') <span class="ml-1 text-amber-400">🎲</span> @endif
                    </div>
                    <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                        @foreach (['script' => 'Script', 'image' => 'Image', 'audio' => 'Audio'] as $asset => $label)
                            @php
                                $has = match ($asset) {
                                    'script' => (string) $scene->script_segment !== '',
                                    'image'  => $scene->image_path !== null,
                                    'audio'  => $scene->audio_path !== null,
                                };
                            @endphp
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full
                                    {{ $has ? 'bg-emerald-400' : ($scene->status === 'failed' ? 'bg-rose-400' : 'bg-amber-400 animate-pulse') }}"></span>
                                <span class="text-slate-300">{{ $label }}</span>
                                @if (! $has && $scene->status === 'failed')
                                    <button wire:click="retryAsset({{ $scene->id }}, '{{ $asset }}')"
                                            class="ml-auto text-amber-300 hover:text-amber-200 underline">Retry</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($scene->error_message)
                    <p class="text-xs text-rose-300 px-4">{{ $scene->error_message }}</p>
                @endif
            @endforeach
        </div>
    @endif

    <div class="flex justify-end gap-3 pt-2">
        <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 1]) }}"
           wire:navigate class="btn btn-outline">← Edit Settings</a>
        <button wire:click="continueToConfigure"
                @disabled(! $this->canContinue)
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-40">
            Continue to Configure →
        </button>
    </div>

</div>
