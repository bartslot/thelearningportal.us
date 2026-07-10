<div class="mx-auto max-w-2xl space-y-6 px-4 py-8">

    <div>
        <p class="text-xs uppercase tracking-widest text-primary">{{ __('Teacher workspace') }}</p>
        <h1 class="mt-2 text-3xl font-semibold">{{ __('Quick lesson chat') }}</h1>
        <p class="mt-1 text-sm opacity-70">{{ __('Tell me the learning goal — I set up the rest. Ready in about a minute.') }}</p>
    </div>

    {{-- ── Bot: opening question ─────────────────────────────────────────────── --}}
    <div class="chat chat-start">
        <div class="chat-bubble">
            {{ __('Hi! What should your students learn? Describe the learning goal in one sentence.') }}
        </div>
    </div>

    {{-- ── Teacher: the goal (textarea until sent, bubble after) ─────────────── --}}
    @if ($state === 'goal')
        <form wire:submit="submitGoal" class="space-y-2">
            <textarea
                wire:model="goal"
                rows="3"
                class="textarea textarea-bordered w-full"
                placeholder="{{ __('e.g. Students understand why the Dutch revolted against Spain') }}"
                aria-label="{{ __('Learning goal') }}"
            ></textarea>
            @error('goal') <p class="text-sm text-error">{{ $message }}</p> @enderror
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="submitGoal">
                    <span wire:loading.remove wire:target="submitGoal">{{ __('Send') }}</span>
                    <span wire:loading wire:target="submitGoal" class="loading loading-dots loading-sm"></span>
                </button>
            </div>
        </form>
    @else
        <div class="chat chat-end">
            <div class="chat-bubble chat-bubble-primary">{{ $goal }}</div>
        </div>
    @endif

    {{-- ── Bot: proposals as tappable chips ──────────────────────────────────── --}}
    @if (in_array($state, ['proposals', 'confirm', 'creating'], true))
        <div class="chat chat-start">
            <div class="chat-bubble w-full max-w-full space-y-5">
                <p>{{ __('Got it! Tap what fits — you can change anything before I build the lesson.') }}</p>

                {{-- (a) lesson type --}}
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide opacity-70">{{ __('Lesson type') }}</p>
                    <div class="flex flex-col gap-2">
                        @foreach ($this->presetChips as $chip)
                            <button
                                type="button"
                                wire:click="selectPreset('{{ $chip['key'] }}')"
                                @class([
                                    'btn btn-sm h-auto justify-start gap-2 py-2 text-left normal-case',
                                    'btn-primary' => $presetKey === $chip['key'],
                                    'btn-outline' => $presetKey !== $chip['key'],
                                ])
                            >
                                <span class="font-semibold">{{ __($chip['label']) }}</span>
                                <span class="text-xs font-normal opacity-70">{{ __($chip['description']) }}</span>
                                @if ($chip['suggested'])
                                    <span class="badge badge-accent badge-sm">{{ __('Suggested') }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- (b) grade band --}}
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide opacity-70">{{ __('Student age') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Livewire\Teacher\LessonChat::GRADE_CHIP_AGES as $age)
                            <button
                                type="button"
                                wire:click="selectGrade({{ $age }})"
                                @class([
                                    'btn btn-sm',
                                    'btn-primary' => $gradeLevel === 'Age '.$age,
                                    'btn-outline' => $gradeLevel !== 'Age '.$age,
                                ])
                            >{{ __('Age') }} {{ $age }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- (c) story grounding --}}
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide opacity-70">{{ __('Ground the lesson in') }}</p>
                    <div class="flex flex-col gap-2">
                        @foreach ($this->storyMatches as $story)
                            <button
                                type="button"
                                wire:click="selectStory({{ $story['id'] }})"
                                @class([
                                    'btn btn-sm h-auto justify-start py-2 text-left normal-case',
                                    'btn-primary' => $storyId === $story['id'],
                                    'btn-outline' => $storyId !== $story['id'],
                                ])
                            >
                                <span class="font-semibold">{{ __('Ground in:') }} {{ $story['title'] }}</span>
                                @if ($story['subtitle'])
                                    <span class="text-xs font-normal opacity-70">{{ $story['subtitle'] }}</span>
                                @endif
                            </button>
                        @endforeach
                        <button
                            type="button"
                            wire:click="selectFreeTopic"
                            @class([
                                'btn btn-sm h-auto justify-start py-2 text-left normal-case',
                                'btn-primary' => $freeTopic,
                                'btn-outline' => ! $freeTopic,
                            ])
                        >{{ __('Free topic:') }} {{ $topic }}</button>
                    </div>
                    <p class="mt-1 text-xs opacity-60">{{ __('Curated stories come with vetted sources; a free topic is researched from Wikipedia.') }}</p>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Bot: confirm summary + create ─────────────────────────────────────── --}}
    @if (in_array($state, ['confirm', 'creating'], true) && $this->selectedPreset)
        <div class="chat chat-start">
            <div class="chat-bubble w-full max-w-full">
                <div class="card bg-base-200">
                    <div class="card-body gap-3 p-4">
                        <h2 class="card-title text-base">{{ __('Ready to build this lesson?') }}</h2>
                        <ul class="space-y-1 text-sm">
                            <li><span class="font-semibold">{{ __('Lesson type') }}:</span> {{ __($this->selectedPreset->label) }}</li>
                            <li><span class="font-semibold">{{ __('Student age') }}:</span> {{ str_replace('Age', __('Age'), $gradeLevel) }}</li>
                            <li>
                                <span class="font-semibold">{{ $this->selectedStory ? __('Story') : __('Topic') }}:</span>
                                {{ $this->selectedStory?->title ?? $topic }}
                            </li>
                            <li><span class="font-semibold">{{ __('Target duration') }}:</span> {{ $this->selectedPreset->durationTargetMinutes }} {{ __('minutes') }}</li>
                        </ul>
                        <p class="text-xs opacity-70">
                            {{ __('Next: I generate the story, images and narration. You land on the progress screen and can refine everything afterwards.') }}
                        </p>
                        <div class="card-actions justify-end">
                            <button
                                type="button"
                                wire:click="create"
                                class="btn btn-primary"
                                wire:loading.attr="disabled"
                                wire:target="create"
                            >
                                <span wire:loading.remove wire:target="create">{{ __('Create lesson') }}</span>
                                <span wire:loading wire:target="create" class="loading loading-dots loading-sm"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
