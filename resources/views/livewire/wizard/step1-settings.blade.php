<div class="space-y-8 pt-6">

    {{-- 1. Topic & Source --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">1. Topic & Source</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="form-control" for="lw-topic">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Topic</span>
                <input id="lw-topic" name="topic" type="text" wire:model="topic"
                       class="input input-bordered bg-slate-900 mt-1" />
                @error('topic') <span class="text-rose-400 text-xs mt-1">{{ $message }}</span> @enderror
            </label>

            <label class="form-control" for="lw-subject">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Subject</span>
                <select id="lw-subject" name="subject" wire:model="subject"
                        class="select select-bordered bg-slate-900 mt-1">
                    <option value="history">History</option>
                    <option value="science">Science</option>
                    <option value="literature">Literature</option>
                    <option value="civics">Civics</option>
                </select>
            </label>

            <div class="form-control">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">
                    Target audience — {{ $audience_mode === 'age' ? 'Age' : 'Grade' }}
                </span>

                <div class="flex gap-2 mt-1">
                    <div class="join">
                        <button type="button"
                                wire:click="setAudienceMode('grade')"
                                @class([
                                    'btn btn-sm join-item',
                                    'btn-primary'  => $audience_mode === 'grade',
                                    'btn-outline'  => $audience_mode !== 'grade',
                                ])>Grade</button>
                        <button type="button"
                                wire:click="setAudienceMode('age')"
                                @class([
                                    'btn btn-sm join-item',
                                    'btn-primary'  => $audience_mode === 'age',
                                    'btn-outline'  => $audience_mode !== 'age',
                                ])>Age</button>
                    </div>

                    @if ($audience_mode === 'grade')
                        <select id="lw-grade-choice" name="grade_choice"
                                wire:model.live="grade_choice"
                                class="select select-bordered bg-slate-900 flex-1">
                            @foreach ($this->gradeOptions as $g)
                                <option value="{{ $g }}">{{ $g }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="lw-audience-age" name="audience_age" type="number"
                               wire:model.live.debounce.300ms="audience_age"
                               min="6" max="16"
                               class="input input-bordered bg-slate-900 flex-1" />
                    @endif
                </div>
            </div>

            <label class="form-control" for="lw-tone">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Tone (optional)</span>
                <input id="lw-tone" name="tone" type="text" wire:model="tone"
                       class="input input-bordered bg-slate-900 mt-1" />
            </label>

            <label class="form-control md:col-span-2" for="lw-details">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Teacher details (optional)</span>
                <textarea id="lw-details" name="details" wire:model="details" rows="3"
                          class="textarea textarea-bordered bg-slate-900 mt-1"></textarea>
            </label>
        </div>

        <div class="space-y-2">
            <p class="text-xs uppercase tracking-wider text-slate-400">Source</p>
            <div class="flex flex-wrap gap-3">
                @foreach (['wikipedia' => 'Wikipedia only', 'upload' => 'My document only', 'both' => 'Both combined'] as $val => $label)
                    <button type="button"
                            wire:click="$set('source_mode', '{{ $val }}')"
                            @class([
                                'px-4 py-2 rounded-lg text-sm border-2 transition-all',
                                'border-amber-400 bg-amber-500/10 text-amber-300' => $source_mode === $val,
                                'border-slate-600 text-slate-300 hover:border-slate-400' => $source_mode !== $val,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($source_mode !== 'wikipedia')
                <div class="mt-2">
                    <input id="lw-source-upload" name="sourceUpload" type="file"
                           wire:model="sourceUpload" accept=".pdf,.docx"
                           class="file-input file-input-bordered w-full bg-slate-900" />
                    @error('sourceUpload') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>
    </section>

    {{-- 2. Visual Style --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">2. Visual Style</h2>
        <x-wizard.style-picker :styles="$this->styleOptions"
                               :selected="$image_style"
                               :recommended="$this->recommendedStyles" />
    </section>

    {{-- 3. Avatar --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">3. Avatar</h2>
        <x-wizard.avatar-picker :avatars="$this->avatars" :selected-id="$avatar_id" />
        @error('avatar_id') <span class="text-rose-400 text-xs">{{ $message }}</span> @enderror
    </section>

    {{-- 4. Strategy Game --}}
    <section class="bg-base-300 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-semibold text-amber-300">4. Strategy Game</h2>
        <x-wizard.game-picker :games="$this->games"
                              :selected-id="$strategy_game_id"
                              :team-count="$team_count"
                              :split-count="$game_split_count" />
    </section>

    {{-- Actions --}}
    <div class="flex justify-end gap-3 pt-2">
        <button type="button" wire:click="saveDraft"
                class="btn btn-outline">Save as draft</button>
        <button type="button" wire:click="generate"
                class="btn bg-amber-500 text-slate-950 hover:bg-amber-400 border-0">Generate lesson →</button>
    </div>

    {{-- Surface any validation errors so silent fails are visible --}}
    @if ($errors->any())
        <div class="bg-rose-500/10 border border-rose-500/40 rounded-xl p-4 text-sm text-rose-200 space-y-1">
            <p class="font-semibold">Cannot generate yet — fix these:</p>
            <ul class="list-disc ml-5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

</div>
