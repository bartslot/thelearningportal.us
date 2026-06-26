@php
use App\Enums\LessonStatus;

// Per-status config: icon, message, base progress pct (null = use scene progress)
$statusSteps = [
    LessonStatus::SourceReady->value      => ['icon' => '⏳', 'label' => 'Your lesson is in the queue — starting up…',                         'pct' =>  2],
    LessonStatus::FetchingSources->value  => ['icon' => '📡', 'label' => 'Reading up on the topic — looking it up on Wikipedia…',              'pct' =>  8],
    LessonStatus::Outlining->value        => ['icon' => '🧠', 'label' => 'Planning the story — deciding what to cover and in what order…',     'pct' => 18],
    LessonStatus::ScenesGenerating->value => ['icon' => '✍️', 'label' => 'Writing the lesson — creating each scene, image and narration…',    'pct' => null],
    LessonStatus::ScenesReady->value      => ['icon' => '📝', 'label' => 'Almost there — writing quiz questions for your students…',           'pct' => 95],
    LessonStatus::Configuring->value      => ['icon' => '✅', 'label' => 'Your lesson is ready!',                                              'pct' => 100],
];

$pipelineOrder = ['fetching_sources' => 0, 'outlining' => 1, 'scenes_generating' => 2, 'scenes_ready' => 3];

$currentStatus  = $lesson->status->value;
$currentOrder   = $pipelineOrder[$currentStatus] ?? -1;
$step           = $statusSteps[$currentStatus] ?? ['icon' => '⏳', 'label' => 'Processing…', 'pct' => 2];

// Progress bar value — scenes phase maps scene progress into 20-94 range
if ($step['pct'] !== null) {
    $displayPct = $step['pct'];
} elseif ($this->overallProgress > 0) {
    $displayPct = 20 + (int) round($this->overallProgress * 0.74);
} else {
    $displayPct = 20;
}

$isDone    = in_array($lesson->status, [LessonStatus::Configuring]);
$isFailed  = $lesson->status === LessonStatus::Failed;
@endphp

<div class="pt-6 space-y-6"
     @if($this->autoAdvanceActive) wire:poll.2s="checkAndAutoAdvance" @endif>

    @if ($isFailed)
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-2xl p-6">
            <h2 class="text-lg font-semibold text-rose-300">Generation failed</h2>
            <p class="text-sm text-rose-200/80 mt-1">{{ $lesson->error_message }}</p>
            <button wire:click="retryOutline"
                    class="mt-4 btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Retry</button>
        </div>
    @else
        <header class="space-y-3">

            {{-- Status headline --}}
            <div class="flex items-center gap-2">
                <span class="text-xl leading-none">{{ $step['icon'] }}</span>
                <h2 class="text-base font-semibold text-amber-300">{!! $step['label'] !!}</h2>
                @if (! $isDone)
                    <span class="w-4 h-4 border-2 border-amber-400/60 border-t-amber-400 rounded-full animate-spin shrink-0"></span>
                @endif
            </div>

            {{-- Pipeline breadcrumb --}}
            <div class="flex items-center gap-1 text-xs">
                @foreach ([
                    ['val' => 'fetching_sources', 'label' => '1. Research'],
                    ['val' => 'outlining',         'label' => '2. Story plan'],
                    ['val' => 'scenes_generating', 'label' => '3. Scenes'],
                    ['val' => 'scenes_ready',      'label' => '4. Quiz'],
                ] as $i => $st)
                    @php
                        $stepOrder = $pipelineOrder[$st['val']] ?? 99;
                        $done   = $currentOrder > $stepOrder;
                        $active = $currentOrder === $stepOrder;
                    @endphp
                    @if ($i > 0)
                        <span class="w-5 h-px {{ $done ? 'bg-emerald-500' : 'bg-slate-700' }}"></span>
                    @endif
                    <span @class([
                        'px-2 py-0.5 rounded-full font-medium',
                        'bg-emerald-500/20 text-emerald-400' => $done,
                        'bg-amber-500/20 text-amber-400'     => $active && !$done,
                        'text-slate-600'                     => !$done && !$active,
                    ])>
                        @if ($done)✓ @endif{{ $st['label'] }}
                    </span>
                @endforeach
            </div>

            {{-- Progress bar --}}
            <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                <div class="h-full bg-amber-500 transition-all duration-700 ease-out rounded-full"
                     style="width: {{ $displayPct }}%"></div>
            </div>
            <p class="text-xs text-slate-500">{{ $displayPct }}% complete</p>
        </header>

        {{-- Scene cards — only the generatable narration scenes (the "script" blocks). Map and
             game scenes have no script/image/audio, so they never belong in this progress list
             (a map shown here would sit on amber "pending" dots forever). Renumber 1..N so the
             first narration scene reads "Scene 1", not "Scene 2" when a map leads the lesson. --}}
        @php $generatable = $this->scenes->where('kind', 'narration')->values(); @endphp
        @if ($generatable->isNotEmpty())
            <div class="space-y-3">
                @foreach ($generatable as $scene)
                    <div @class([
                        'rounded-2xl p-4 flex items-start gap-4 border',
                        'border-rose-500/40 bg-rose-500/5'       => $scene->status === 'failed',
                        'border-emerald-500/40 bg-emerald-500/5' => $scene->status === 'ready',
                        'border-slate-700 bg-slate-900/40'       => ! in_array($scene->status, ['failed','ready']),
                    ])>
                        <div class="text-sm font-semibold text-slate-200 w-24 shrink-0">
                            Scene {{ $loop->iteration }}
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
                                    <span @class([
                                        'w-2 h-2 rounded-full shrink-0',
                                        'bg-emerald-400'            => $has,
                                        'bg-rose-400'               => !$has && $scene->status === 'failed',
                                        'bg-amber-400 animate-pulse'=> !$has && $scene->status !== 'failed',
                                    ])></span>
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
