<div class="grid gap-8 lg:grid-cols-[1.4fr_1fr]">

    {{-- ── Left: editable fields ─────────────────────────────────────────────── --}}
    <div class="space-y-6">

        {{-- Saved flash --}}
        @if($saved)
            <div
                x-data="{ show: true }"
                x-show="show"
                x-init="setTimeout(() => show = false, 3000)"
                x-transition:leave="transition ease-in duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="rounded-xl border border-emerald-700 bg-emerald-950/50 px-4 py-3 text-sm text-emerald-300"
            >
                Settings saved.
            </div>
        @endif

        <form wire:submit="save" class="space-y-6">

            {{-- Lesson code --}}
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-4">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-1">Lesson code</h3>
                    <p class="text-xs text-slate-400">Share this code with students so they can access this specific lesson.</p>
                </div>
                <div class="flex items-center gap-3">
                    <input
                        wire:model="lessonCode"
                        type="text"
                        maxlength="8"
                        class="w-40 rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm font-mono font-semibold
                               uppercase tracking-widest text-amber-300 placeholder-slate-600
                               focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        placeholder="e.g. ROME42"
                    >
                    <button
                        type="button"
                        wire:click="regenerateCode"
                        class="rounded-xl border border-slate-700 px-4 py-2.5 text-xs font-medium text-slate-400
                               hover:border-slate-600 hover:text-slate-200 transition-colors"
                    >
                        Regenerate
                    </button>
                </div>
                @error('lessonCode')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Target audience --}}
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-4">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-1">Target audience</h3>
                    <p class="text-xs text-slate-400">The grade level this lesson is designed for.</p>
                </div>
                <div>
                    <input
                        wire:model="gradeLevel"
                        type="text"
                        list="settings-grade-options"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-slate-100
                               placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        placeholder="e.g. 9th grade"
                    >
                    <datalist id="settings-grade-options">
                        @foreach(['3rd grade','4th grade','5th grade','6th grade','7th grade','8th grade','9th grade','10th grade','11th grade','12th grade'] as $grade)
                            <option value="{{ $grade }}"></option>
                        @endforeach
                    </datalist>
                </div>
                @error('gradeLevel')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Lesson duration --}}
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-4">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-1">Lesson duration</h3>
                    <p class="text-xs text-slate-400">Override the auto-detected duration if needed.</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <input
                            wire:model="durationMinutes"
                            type="number"
                            min="0"
                            max="999"
                            class="w-20 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-slate-100
                                   text-center focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="0"
                        >
                        <span class="text-xs text-slate-400">min</span>
                    </div>
                    <span class="text-slate-400">:</span>
                    <div class="flex items-center gap-2">
                        <input
                            wire:model="durationSeconds"
                            type="number"
                            min="0"
                            max="59"
                            class="w-20 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-slate-100
                                   text-center focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="0"
                        >
                        <span class="text-xs text-slate-400">sec</span>
                    </div>
                </div>
                @error('durationMinutes')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
                @error('durationSeconds')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Save button --}}
            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-6 py-2.5 text-sm font-semibold
                           text-slate-950 hover:bg-amber-400 transition-colors disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="save">Save settings</span>
                    <span wire:loading wire:target="save" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Saving…
                    </span>
                </button>
            </div>

        </form>
    </div>

    {{-- ── Right: portrait ──────────────────────────────────────────────────── --}}
    <div class="space-y-4">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 space-y-5">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-1">Avatar portrait</h3>
                <p class="text-xs text-slate-400">
                    Replaces the downloaded or default portrait used for the avatar video.
                    Accepts JPG, PNG, or WebP — max 4 MB. Will be resized to 512 × 512.
                </p>
            </div>

            {{-- Current portrait --}}
            <div class="flex items-center gap-4">
                <img
                    src="{{ $this->currentPortraitUrl }}"
                    alt="Current portrait"
                    class="h-20 w-20 rounded-xl object-cover border border-slate-700 flex-shrink-0"
                >
                <div class="text-xs text-slate-400 leading-5">
                    @if($lesson->portrait_path)
                        <span class="text-emerald-400">Portrait on file</span><br>
                        {{ basename($lesson->portrait_path) }}
                    @else
                        Using default portrait
                    @endif
                </div>
            </div>

            {{-- Upload new portrait --}}
            <div>
                <label class="block text-xs font-medium uppercase tracking-wider text-slate-400 mb-2">
                    Upload new portrait
                </label>

                <input
                    wire:model="portrait"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="block w-full text-sm text-slate-400
                           file:mr-4 file:rounded-lg file:border-0 file:bg-slate-800
                           file:px-4 file:py-2 file:text-xs file:font-medium file:text-slate-300
                           hover:file:bg-slate-700 cursor-pointer"
                >

                @error('portrait')
                    <p class="mt-2 text-sm text-rose-400">{{ $message }}</p>
                @enderror

                {{-- Preview uploaded image before save --}}
                @if($portrait)
                    <div class="mt-3 flex items-center gap-3">
                        <img src="{{ $portrait->temporaryUrl() }}"
                             alt="Preview"
                             class="h-16 w-16 rounded-lg object-cover border border-amber-500/40">
                        <p class="text-xs text-amber-400">Preview — click "Save settings" to apply.</p>
                    </div>
                @endif
            </div>

            {{-- Save portrait note --}}
            <p class="text-xs text-slate-400 border-t border-slate-800 pt-4">
                After uploading a new portrait, re-run the generation pipeline to create a new avatar video with the updated image.
            </p>
        </div>
    </div>

</div>
