@php
use App\Enums\LessonStatus;

$statusSteps = [
    LessonStatus::SourceReady->value      => ['label' => __('Waiting for the workshop to begin'), 'detail' => __('Your lesson is queued and ready for the first worker.'), 'pct' => 2],
    LessonStatus::FetchingSources->value  => ['label' => __('Researching the historical setting'), 'detail' => __('Finding reliable source material and useful historical images.'), 'pct' => 8],
    LessonStatus::Outlining->value        => ['label' => __('Planning the lesson narrative'), 'detail' => __('Choosing the key events, sequence, and teaching structure.'), 'pct' => 18],
    LessonStatus::ScenesGenerating->value => ['label' => __('Building the lesson scenes'), 'detail' => __('Writing the script, creating visuals, and preparing narration.'), 'pct' => null],
    LessonStatus::ScenesReady->value      => ['label' => __('Preparing the final knowledge check'), 'detail' => __('The scenes are ready. The quiz is being connected to the lesson.'), 'pct' => 95],
    LessonStatus::Configuring->value      => ['label' => __('Your lesson is ready'), 'detail' => __('Continue to review and configure the finished lesson.'), 'pct' => 100],
];

$pipelineOrder = [
    LessonStatus::SourceReady->value => -1,
    LessonStatus::FetchingSources->value => 0,
    LessonStatus::Outlining->value => 1,
    LessonStatus::ScenesGenerating->value => 2,
    LessonStatus::ScenesReady->value => 3,
    LessonStatus::Configuring->value => 4,
];

$currentStatus = $lesson->status->value;
$currentOrder = $pipelineOrder[$currentStatus] ?? -1;
$step = $statusSteps[$currentStatus] ?? [
    'label' => __('Building your lesson'),
    'detail' => __('The workshop is processing the next step.'),
    'pct' => 2,
];

if ($lesson->status === LessonStatus::ScenesReady && $lesson->quizQuestions()->exists() && $this->overallProgress >= 100) {
    $step = [
        'label' => __('Your lesson is ready'),
        'detail' => __('Continue to review and configure the finished lesson.'),
        'pct' => 100,
    ];
}

// 'Configuring' can arrive while scene media is still rendering in the background
// (auto-advance lets the teacher in early) — never claim "ready / 100%" with
// narration or images silently missing. Hold at 95+ with honest copy instead.
if ($lesson->status === LessonStatus::Configuring && $this->overallProgress < 100) {
    $step = [
        'label' => __('Almost ready — media is finishing'),
        'detail' => __('You can continue and configure now; the remaining narration and images finish in the background.'),
        'pct' => max(95, 20 + (int) round($this->overallProgress * .74)),
    ];
}

if ($step['pct'] !== null) {
    $displayPct = $step['pct'];
} elseif ($this->overallProgress > 0) {
    $displayPct = 20 + (int) round($this->overallProgress * .74);
} else {
    $displayPct = 20;
}

$generatable = $this->scenes->where('kind', 'narration')->values();
$sceneCount = $generatable->count();
$scriptCount = $generatable->filter(fn ($scene) => (string) $scene->script_segment !== '')->count();
$imageCount = $generatable->whereNotNull('image_path')->count();
$audioCount = $generatable->whereNotNull('audio_path')->count();
$quizReady = $lesson->quizQuestions()->exists();

$assetState = function (int $done, int $total, bool $active): string {
    if ($total > 0 && $done >= $total) {
        return 'done';
    }

    return $active ? 'active' : 'pending';
};

$tasks = [
    [
        'label' => __('Researching sources'),
        'detail' => __('Wikipedia, curated sources, and historical context'),
        'state' => $currentOrder > 0 ? 'done' : ($currentOrder === -1 || $currentOrder === 0 ? 'active' : 'pending'),
    ],
    [
        'label' => __('Planning the story'),
        'detail' => __('Learning arc, key events, and scene order'),
        'state' => $currentOrder > 1 ? 'done' : ($currentOrder === 1 ? 'active' : 'pending'),
    ],
    [
        'label' => __('Writing the script'),
        'detail' => $sceneCount > 0 ? __(':done of :total scenes written', ['done' => $scriptCount, 'total' => $sceneCount]) : __('Waiting for the scene plan'),
        'state' => $assetState($scriptCount, $sceneCount, $currentOrder === 2),
    ],
    [
        'label' => __('Finding and creating images'),
        'detail' => $sceneCount > 0 ? __(':done of :total scenes illustrated', ['done' => $imageCount, 'total' => $sceneCount]) : __('Begins after the scene plan'),
        'state' => $assetState($imageCount, $sceneCount, $currentOrder === 2 && $scriptCount > 0),
    ],
    [
        'label' => __('Creating narration'),
        'detail' => $sceneCount > 0 ? __(':done of :total scenes narrated', ['done' => $audioCount, 'total' => $sceneCount]) : __('Begins after the script'),
        'state' => $assetState($audioCount, $sceneCount, $currentOrder === 2 && $scriptCount > 0),
    ],
    [
        'label' => __('Preparing the quiz'),
        'detail' => __('Questions are checked against the finished lesson'),
        'state' => $quizReady ? 'done' : ($currentOrder >= 3 ? 'active' : 'pending'),
    ],
];

$isDone = $displayPct === 100;
$isFailed = $lesson->status === LessonStatus::Failed;
$isStalled = $this->isStalled;
$machineState = $isFailed ? 'error' : ($isStalled ? 'stalled' : ($isDone ? 'ready' : 'working'));
@endphp

<div
    class="mx-auto max-w-5xl pb-10 pt-8"
    @if($this->autoAdvanceActive) wire:poll.2s="checkAndAutoAdvance" @endif
>
    <header class="mx-auto max-w-2xl text-center">
        <p class="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-sky-300/75">
            {{ __('History Portal workshop') }}
        </p>
        <h1 class="mt-3 font-history text-3xl font-light tracking-[-0.035em] text-slate-100 sm:text-4xl">
            {{ $step['label'] }}
        </h1>
        <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-400">
            {{ $step['detail'] }}
        </p>
    </header>

    <div class="mt-10 grid items-center gap-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(20rem,.9fr)] lg:gap-16">
        <section class="flex min-w-0 flex-col items-center" aria-label="{{ __('Generation animation and progress') }}">
            <x-wizard.history-time-machine :state="$machineState" />

            <div class="mt-2 w-full max-w-md">
                <div class="flex items-end justify-between gap-4">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-200">
                            {{ $lesson->title ?: $lesson->topic }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $isDone ? __('Ready to configure') : __('Travelling backwards through the source material') }}
                        </p>
                    </div>
                    <span class="shrink-0 font-history text-2xl tabular-nums text-amber-300">{{ $displayPct }}%</span>
                </div>
                <div
                    class="mt-4 h-px overflow-hidden bg-slate-800"
                    role="progressbar"
                    aria-label="{{ __('Lesson generation progress') }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="{{ $displayPct }}"
                >
                    <div class="h-full bg-amber-400 transition-[width] duration-700 ease-out" style="width: {{ $displayPct }}%"></div>
                </div>
            </div>
        </section>

        <section class="min-w-0" aria-labelledby="generation-process-title">
            <div class="flex items-end justify-between gap-4 border-b border-slate-800 pb-4">
                <div>
                    <p class="text-[0.65rem] uppercase tracking-[0.16em] text-slate-500">{{ __('Live process') }}</p>
                    <h2 id="generation-process-title" class="mt-1 text-base font-semibold text-slate-100">
                        {{ __('What the workshop is doing') }}
                    </h2>
                </div>
                @unless ($isDone || $isFailed)
                    <span class="inline-flex items-center gap-1 text-xs text-slate-500" aria-label="{{ __('Thinking') }}">
                        <span>{{ __('Thinking') }}</span>
                        <span class="inline-flex w-5 justify-start gap-0.5" aria-hidden="true">
                            <span class="h-1 w-1 animate-pulse rounded-full bg-amber-300"></span>
                            <span class="h-1 w-1 animate-pulse rounded-full bg-amber-300 [animation-delay:160ms]"></span>
                            <span class="h-1 w-1 animate-pulse rounded-full bg-amber-300 [animation-delay:320ms]"></span>
                        </span>
                    </span>
                @endunless
            </div>

            <ol class="divide-y divide-slate-800/90">
                @foreach ($tasks as $task)
                    <li class="grid grid-cols-[1rem_minmax(0,1fr)] gap-3 py-3.5">
                        <span class="mt-1 flex h-4 w-4 items-center justify-center" aria-hidden="true">
                            @if ($task['state'] === 'done')
                                <svg viewBox="0 0 20 20" class="h-4 w-4 text-emerald-300">
                                    <circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.5" />
                                    <path d="m6.5 10 2.2 2.2 4.8-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            @elseif ($task['state'] === 'active')
                                <svg viewBox="0 0 20 20" class="h-4 w-4 animate-spin text-amber-300">
                                    <circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="30 20" />
                                </svg>
                            @else
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-700"></span>
                            @endif
                        </span>
                        <div class="min-w-0">
                            <p @class([
                                'text-sm',
                                'font-medium text-slate-200' => $task['state'] === 'active',
                                'text-slate-400' => $task['state'] === 'done',
                                'text-slate-600' => $task['state'] === 'pending',
                            ])>{{ $task['label'] }}</p>
                            <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ $task['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    @if ($isFailed || $isStalled)
        <aside
            role="alert"
            @class([
                'mx-auto mt-10 max-w-3xl border p-5 sm:p-6',
                'border-rose-500/35 bg-rose-500/6' => $isFailed,
                'border-orange-400/35 bg-orange-400/6' => $isStalled && ! $isFailed,
            ])
        >
            <div class="flex items-start gap-4">
                <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 text-orange-300" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path d="M12 8v5m0 3.5v.01M10.2 4.5 2.7 18a2 2 0 0 0 1.75 3h15.1a2 2 0 0 0 1.75-3L13.8 4.5a2.05 2.05 0 0 0-3.6 0Z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-slate-100">
                        {{ $isFailed ? __('Lesson generation stopped') : __('This step is taking longer than expected') }}
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-slate-400">
                        @if ($isFailed)
                            {{ $lesson->error_message ?: __('A worker stopped before completing the lesson.') }}
                        @else
                            {{ __('No progress has been reported for :minutes minutes. You can keep waiting, retry this lesson, or remove the stalled session and start clean.', ['minutes' => $this->stalledForMinutes]) }}
                        @endif
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            type="button"
                            wire:click="retryOutline"
                            wire:loading.attr="disabled"
                            wire:target="retryOutline"
                            class="btn btn-sm btn-primary"
                        >
                            <span wire:loading.remove wire:target="retryOutline">{{ __('Retry this lesson') }}</span>
                            <span wire:loading wire:target="retryOutline" class="loading loading-dots loading-xs"></span>
                        </button>
                        <button
                            type="button"
                            wire:click="startNewLesson"
                            wire:confirm="{{ __('Remove this stalled lesson, its queued work, and generated files? This cannot be undone.') }}"
                            wire:loading.attr="disabled"
                            wire:target="startNewLesson"
                            class="btn btn-sm btn-ghost border-rose-400/35 text-rose-200 hover:border-rose-300 hover:bg-rose-400/10"
                        >
                            {{ __('Remove session and start a new lesson') }}
                        </button>
                    </div>
                </div>
            </div>
        </aside>
    @endif

    @if ($generatable->isNotEmpty())
        <details class="mx-auto mt-8 max-w-3xl border-y border-slate-800 py-1">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-3 text-sm text-slate-400 transition hover:text-slate-200">
                <span>{{ __('Scene details') }}</span>
                <span class="text-xs text-slate-600">{{ trans_choice(':count scene|:count scenes', $generatable->count(), ['count' => $generatable->count()]) }}</span>
            </summary>
            <div class="divide-y divide-slate-800">
                @foreach ($generatable as $scene)
                    <div class="py-4">
                        <div class="flex items-start gap-4">
                            <div class="w-16 shrink-0 text-xs font-medium text-slate-400">
                                {{ __('Scene :number', ['number' => $loop->iteration]) }}
                            </div>
                            <div class="grid min-w-0 flex-1 grid-cols-1 gap-3 text-xs sm:grid-cols-3">
                                @foreach (['script' => __('Script'), 'image' => __('Image'), 'audio' => __('Audio')] as $asset => $label)
                                    @php
                                        $has = match ($asset) {
                                            'script' => (string) $scene->script_segment !== '',
                                            'image' => $scene->image_path !== null,
                                            'audio' => $scene->audio_path !== null,
                                        };
                                    @endphp
                                    <div class="flex items-center gap-2">
                                        <span @class([
                                            'h-1.5 w-1.5 shrink-0 rounded-full',
                                            'bg-emerald-300' => $has,
                                            'bg-rose-300' => ! $has && $scene->status === 'failed',
                                            'animate-pulse bg-amber-300' => ! $has && $scene->status !== 'failed',
                                        ])></span>
                                        <span class="text-slate-400">{{ $label }}</span>
                                        @if (! $has && $scene->status === 'failed')
                                            <button
                                                type="button"
                                                wire:click="retryAsset({{ $scene->id }}, '{{ $asset }}')"
                                                class="ms-auto text-amber-300 hover:text-amber-200"
                                            >{{ __('Retry') }}</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @if ($scene->error_message)
                            <p class="mt-2 ps-20 text-xs text-rose-300">{{ $scene->error_message }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    <footer class="relative mt-10 flex flex-col items-stretch justify-center gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:items-center">
        <a
            href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 1]) }}"
            wire:navigate
            class="btn btn-outline sm:absolute sm:start-0"
        >{{ __('Edit settings') }}</a>
        <button
            type="button"
            wire:click="continueToConfigure"
            @disabled(! $this->canContinue)
            class="btn btn-primary disabled:opacity-40"
        >{{ __('Continue to configure') }}</button>
    </footer>
</div>
