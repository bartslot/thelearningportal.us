<div class="mx-auto max-w-3xl px-0 py-2 sm:px-4 sm:py-8">
    <header class="mb-7">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">{{ __('Teacher workspace') }}</p>
        <h1 class="mt-2 font-history text-3xl font-semibold sm:text-4xl">{{ __('Build a lesson together') }}</h1>
        <p class="mt-2 max-w-xl text-sm leading-6 text-base-content/60">
            {{ __('Describe the learning goal. I’ll suggest a lesson and ask one question at a time.') }}
        </p>
    </header>

    <section
        class="relative flex h-[clamp(32rem,72dvh,48rem)] flex-col overflow-hidden rounded-2xl border border-base-content/10 bg-base-200/70 shadow-2xl shadow-black/20"
        x-data="{
            pinnedToBottom: true,
            transcriptObserver: null,
            init() {
                this.$nextTick(() => {
                    const transcript = this.$refs.transcript;
                    if (! transcript) return;

                    this.transcriptObserver = new MutationObserver(() => {
                        if (this.pinnedToBottom) this.scrollToBottom('auto');
                    });
                    this.transcriptObserver.observe(transcript, {
                        childList: true,
                        subtree: true,
                        characterData: true,
                    });
                    this.resizeGoalTextarea();
                    this.scrollToBottom('auto');
                });
            },
            trackScroll() {
                const transcript = this.$refs.transcript;
                if (! transcript) return;

                this.pinnedToBottom =
                    transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight < 72;
            },
            scrollToBottom(behavior = 'smooth') {
                this.pinnedToBottom = true;
                this.$nextTick(() => {
                    const transcript = this.$refs.transcript;
                    if (! transcript) return;

                    transcript.scrollTo({ top: transcript.scrollHeight, behavior });
                });
            },
            resizeGoalTextarea() {
                const textarea = this.$refs.goalInput;
                if (! textarea) {
                    return;
                }

                textarea.style.height = 'auto';
                const computed = getComputedStyle(textarea);
                const lineHeight = parseFloat(computed.lineHeight) || 24;
                const padding = parseFloat(computed.paddingTop) + parseFloat(computed.paddingBottom);
                const maxHeight = Math.round(lineHeight * 2 + padding);
                const desiredHeight = Math.min(textarea.scrollHeight, maxHeight);

                textarea.style.height = `${desiredHeight}px`;
                textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden';
            },
            handleGoalTextareaKeydown(event) {
                if (event.key !== 'Enter') {
                    return;
                }

                const textarea = this.$refs.goalInput;
                if (! textarea) {
                    return;
                }

                if (event.ctrlKey || event.metaKey || event.shiftKey) {
                    event.preventDefault();
                    const start = textarea.selectionStart;
                    const end = textarea.selectionEnd;
                    const value = textarea.value;
                    textarea.value = `${value.slice(0, start)}\n${value.slice(end)}`;
                    textarea.setSelectionRange(start + 1, start + 1);
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                    return;
                }

                event.preventDefault();
                const form = textarea.form;
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            },
            destroy() {
                this.transcriptObserver?.disconnect();
            },
        }"
        x-effect="$wire.state; scrollToBottom()"
    >
        <div class="pointer-events-none absolute inset-x-0 top-0 z-10 h-px bg-gradient-to-r from-transparent via-primary/60 to-transparent"></div>

        <div
            x-ref="transcript"
            x-on:scroll.passive="trackScroll"
            class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain scroll-smooth p-4 sm:p-7"
        >
            <x-teacher.typed-chat-message
                :message="__('What should your students understand by the end of this lesson?')"
                :animate="$state === 'goal'"
                wire:key="opening-question"
            />

            @if ($state !== 'goal')
                <div class="chat chat-end" wire:key="teacher-goal">
                    <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                    <div class="chat-bubble chat-bubble-primary max-w-[92%] whitespace-pre-line p-3 text-sm leading-5 sm:max-w-[82%] sm:text-base">{{ $goal }}</div>
                </div>

                <x-teacher.typed-chat-message
                    :message="$this->goalContextMessage"
                    :animate="$state === 'focus'"
                    wire:key="goal-context"
                />

                @if ($focusChoice !== null)
                    <div class="chat chat-end" wire:key="focus-choice">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary p-3">{{ $focusChoice }}</div>
                    </div>
                @endif

                @if (in_array($state, ['era', 'preset_confirmation', 'preset_options', 'age_confirmation', 'age_options', 'grounding_options', 'confirm', 'creating'], true))
                    <x-teacher.typed-chat-message
                        :message="$this->eraPrompt"
                        :animate="$state === 'era'"
                        wire:key="era-prompt"
                    />
                @endif

                @if ($eraChoice !== null)
                    <div class="chat chat-end" wire:key="era-choice">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary p-3">{{ $eraChoice }}</div>
                    </div>
                @endif

                @if (in_array($state, ['preset_confirmation', 'preset_options', 'age_confirmation', 'age_options', 'grounding_options', 'confirm', 'creating'], true))
                    <x-teacher.typed-chat-message
                        :message="$this->openingProposalMessage"
                        :animate="$state === 'preset_confirmation' || ($state === 'preset_options' && $suggestedPresetKey === null)"
                        wire:key="opening-proposal"
                    />
                @endif

                @if ($presetAnswer !== null)
                    <div class="chat chat-end" wire:key="preset-answer">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary px-2 py-1">
                            {{ $presetAnswer === 'continue' ? __('Continue') : __('Change') }}
                        </div>
                    </div>
                @endif

                @if ($presetAnswer === 'change')
                    <x-teacher.typed-chat-message
                        :message="$this->presetOptionsMessage"
                        :animate="$state === 'preset_options'"
                        wire:key="preset-options"
                    />
                @endif

                @if ($presetChoice !== null)
                    <div class="chat chat-end" wire:key="preset-choice">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary p-3">{{ $presetChoice }}</div>
                    </div>
                @endif

                @if ($presetKey !== null && in_array($state, ['age_confirmation', 'age_options', 'grounding_options', 'confirm', 'creating'], true))
                    <x-teacher.typed-chat-message
                        :message="$this->agePrompt"
                        :animate="$state === 'age_confirmation'"
                        wire:key="age-question"
                    />
                @endif

                @if ($ageAnswer !== null)
                    <div class="chat chat-end" wire:key="age-answer">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary px-2 py-1">
                            {{ $ageAnswer === 'continue' ? __('Continue') : __('Change') }}
                        </div>
                    </div>
                @endif

                @if ($ageAnswer === 'change')
                    <x-teacher.typed-chat-message
                        :message="$this->ageOptionsMessage"
                        :animate="$state === 'age_options'"
                        wire:key="age-options"
                    />
                @endif

                @if ($ageChoice !== null)
                    <div class="chat chat-end" wire:key="age-choice">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary p-3">{{ $ageChoice }}</div>
                    </div>
                @endif

                @if ($state === 'grounding_options' || $groundingChoice !== null)
                    <x-teacher.typed-chat-message
                        :message="$this->groundingPrompt"
                        :animate="$state === 'grounding_options'"
                        wire:key="grounding-options"
                    />
                @endif

                @if ($groundingChoice !== null)
                    <div class="chat chat-end" wire:key="grounding-choice">
                        <div class="chat-header mb-1 text-xs text-base-content/55">{{ __('You') }}</div>
                        <div class="chat-bubble chat-bubble-primary p-3">{{ $groundingChoice }}</div>
                    </div>
                @endif

                @if (in_array($state, ['confirm', 'creating'], true))
                    <x-teacher.typed-chat-message
                        :message="$this->confirmationMessage"
                        :animate="$state === 'confirm'"
                        wire:key="confirmation"
                    />
                @endif
            @endif
        </div>

        <div class="shrink-0 border-t border-base-content/10 bg-base-200/95 p-3 shadow-[0_-16px_32px_rgba(2,6,23,0.35)] backdrop-blur sm:p-4">
            @if ($state === 'goal')
                <form wire:submit="submitGoal" class="space-y-3">
                    <textarea
                        x-ref="goalInput"
                        wire:model="goal"
                        rows="1"
                        class="textarea textarea-bordered w-full resize-none text-base leading-6"
                        placeholder="{{ __('Type the learning goal…') }}"
                        aria-label="{{ __('Learning goal') }}"
                        autofocus
                        @input="$root.resizeGoalTextarea()"
                        @keydown="if ($event.key === 'Enter') { if ($event.ctrlKey || $event.metaKey || $event.shiftKey) { $event.preventDefault(); const start = $el.selectionStart; const end = $el.selectionEnd; const value = $el.value; $el.value = `${value.slice(0, start)}\n${value.slice(end)}`; $el.setSelectionRange(start + 1, start + 1); $el.dispatchEvent(new Event('input', { bubbles: true })); } else { $event.preventDefault(); const form = $el.form; if (form) { if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); } } } }"
                    ></textarea>

                    @error('goal')
                        <p class="text-sm text-error">{{ $message }}</p>
                    @enderror

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="btn btn-primary min-w-24"
                            wire:loading.attr="disabled"
                            wire:target="submitGoal"
                        >
                            <span wire:loading.remove wire:target="submitGoal">{{ __('Send') }}</span>
                            <span wire:loading wire:target="submitGoal" class="loading loading-dots loading-sm"></span>
                        </button>
                    </div>
                </form>
            @elseif ($state === 'focus')
                @php
                    $focusFull = count($focusTags) >= 3;
                    $focusAll = \App\Lessons\FocusTags::all();
                @endphp
                <div class="space-y-3">
                    @php
                        $selectedFocusCount = count($focusTags);
                    @endphp
                    <div class="rounded-lg border border-base-content/10 bg-base-100/35 px-3 py-3">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <span class="text-xs text-base-content/55">
                                {{ __('Optional focus') }} · {{ $selectedFocusCount }}/3
                            </span>
                            <div class="flex items-center gap-1.5" aria-label="{{ __('Selected focus points') }}">
                                @for ($slotIndex = 0; $slotIndex < 3; $slotIndex++)
                                    @php $slot = $focusTags[$slotIndex] ?? null; @endphp
                                    @if ($slot)
                                        <button
                                            type="button"
                                            wire:click="toggleFocusTag('{{ $slot }}')"
                                            title="{{ __('Remove :focus', ['focus' => __($focusAll[$slot]['label'] ?? $slot)]) }}"
                                            class="group inline-flex items-center gap-1 rounded-md border border-primary/50 bg-primary/15 px-2 py-1 text-[11px] font-medium text-primary transition hover:border-error/60 hover:bg-error/10 hover:text-error"
                                        >
                                            {{ __($focusAll[$slot]['label'] ?? $slot) }}
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3 opacity-60 group-hover:opacity-100" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    @else
                                        <span class="inline-flex h-[26px] w-12 items-center justify-center rounded-md border border-dashed border-base-content/20 text-base-content/25" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                        </span>
                                    @endif
                                @endfor
                            </div>
                        </div>

                        @if (! $focusFull)
                            <div class="flex flex-wrap gap-2">
                                @foreach ($focusAll as $slug => $tag)
                                    @php $isSelected = in_array($slug, $focusTags, true); @endphp
                                    <button
                                        type="button"
                                        wire:click="toggleFocusTag('{{ $slug }}')"
                                        @class([
                                            'btn btn-xs',
                                            'btn-primary' => $isSelected,
                                            'btn-outline' => ! $isSelected,
                                        ])
                                    >
                                        {{ __($tag['label']) }}
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-base-content/45">
                                {{ __('3 of 3 chosen — clear a slot above to swap.') }}
                            </p>
                        @endif
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="button"
                            wire:click="continueAfterFocus"
                            class="btn btn-primary min-w-24"
                        >
                            {{ __('Continue') }}
                        </button>
                    </div>
                </div>
            @elseif ($state === 'era')
                <div class="flex flex-wrap justify-end gap-2">
                    @foreach ($this->eraOptions as $option)
                        <button
                            type="button"
                            wire:click="selectEra({{ $option['index'] }})"
                            @class([
                                'btn h-auto py-2 normal-case',
                                'btn-primary' => $option['suggested'],
                                'btn-outline' => ! $option['suggested'],
                            ])
                        >
                            {{ $option['label'] }}
                        </button>
                    @endforeach
                </div>
            @elseif ($state === 'preset_confirmation')
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="showPresetOptions" class="btn btn-ghost">
                        {{ __('Change') }}
                    </button>
                    <button type="button" wire:click="continueWithSuggestedPreset" class="btn btn-primary">
                        {{ __('Continue') }}
                    </button>
                </div>
            @elseif ($state === 'preset_options')
                <div class="flex flex-wrap justify-end gap-2">
                    @foreach ($this->presetOptions as $option)
                        <button
                            type="button"
                            wire:click="selectPreset('{{ $option['key'] }}')"
                            class="btn btn-outline h-auto py-2 normal-case"
                            title="{{ $option['pitch'] }}"
                        >
                            {{ $option['label'] }}
                        </button>
                    @endforeach
                </div>
            @elseif ($state === 'age_confirmation')
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="showAgeOptions" class="btn btn-ghost">
                        {{ __('Change') }}
                    </button>
                    <button type="button" wire:click="continueWithSuggestedAge" class="btn btn-primary">
                        {{ __('Continue') }}
                    </button>
                </div>
            @elseif ($state === 'age_options')
                <div class="flex flex-wrap justify-end gap-2">
                    @foreach ($this->ageOptions as $option)
                        <button
                            type="button"
                            wire:click="selectGrade({{ $option['age'] }})"
                            class="btn btn-outline"
                        >
                            {{ $option['label'] }}
                        </button>
                    @endforeach
                </div>
            @elseif ($state === 'grounding_options')
                <div class="flex flex-wrap justify-end gap-2">
                    @foreach ($this->groundingOptions as $option)
                        @if ($option['type'] === 'story')
                            <button
                                type="button"
                                wire:click="selectStory({{ $option['id'] }})"
                                class="btn btn-outline h-auto py-2 normal-case"
                            >
                                {{ __('Use :story', ['story' => $option['label']]) }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="selectFreeTopic"
                                class="btn btn-outline h-auto py-2 normal-case"
                            >
                                {{ __('Research :topic', ['topic' => $option['label']]) }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @elseif (in_array($state, ['confirm', 'creating'], true))
                <div class="flex justify-end">
                    <button
                        type="button"
                        wire:click="create"
                        class="btn btn-primary"
                        wire:loading.attr="disabled"
                        wire:target="create"
                    >
                        <span wire:loading.remove wire:target="create">{{ __('Build this lesson') }}</span>
                        <span wire:loading wire:target="create" class="loading loading-dots loading-sm"></span>
                    </button>
                </div>
            @endif
        </div>
    </section>
</div>
