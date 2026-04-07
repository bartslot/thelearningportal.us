<div class="grid gap-8 lg:grid-cols-[1.3fr_0.7fr]">

    {{-- ── Form ────────────────────────────────────────────────────────────── --}}
    <section class="rounded-3xl border border-slate-800 bg-slate-900/60 p-8 shadow-xl">
        <div class="mb-8">
            <p class="text-xs uppercase tracking-widest text-amber-400">Teacher workspace</p>
            <h1 class="mt-2 text-3xl font-semibold text-slate-100">Create a new lesson</h1>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                Fill in the details below. The AI will generate a narrated lesson with a quiz.
            </p>
        </div>

        <form wire:submit="submit" class="space-y-6">

            {{-- Topic --}}
            <div>
                <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                    Topic <span class="text-rose-500">*</span>
                </label>
                <input
                    wire:model.live="topic"
                    type="text"
                    list="history-topic-ideas"
                    autocomplete="off"
                    class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                           placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                    placeholder="e.g. The French Revolution"
                >
                <datalist id="history-topic-ideas">
                    @foreach($topicSuggestions as $idea)
                        <option value="{{ $idea }}"></option>
                    @endforeach
                </datalist>
                <p class="mt-1.5 text-xs text-slate-400">The lesson title will be generated automatically from your topic.</p>
                @error('topic') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
            </div>

            {{-- Subject + Grade --}}
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Subject</label>
                    <select
                        wire:model.live="subject"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                               focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                    >
                        <option value="history">🏛️ History</option>
                        <option value="science">🔬 Science</option>
                        <option value="literature">📖 Literature</option>
                        <option value="civics">⚖️ Civics</option>
                    </select>
                    @error('subject') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">Grade level <span class="text-rose-500">*</span></label>
                    <input
                        wire:model.live="grade_level"
                        type="text"
                        list="grade-level-options"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                               placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        placeholder="9th grade"
                    >
                    <datalist id="grade-level-options">
                        @foreach(['3rd grade','4th grade','5th grade','6th grade','7th grade','8th grade','9th grade','10th grade','11th grade','12th grade'] as $grade)
                            <option value="{{ $grade }}"></option>
                        @endforeach
                    </datalist>
                    @error('grade_level') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Tone --}}
            <div>
                <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                    Narrative voice
                    <span class="ml-1 font-normal normal-case tracking-normal text-slate-400">(optional)</span>
                </label>
                <input
                    wire:model.live="tone"
                    type="text"
                    class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                           placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                    placeholder="e.g. calm and reflective, urgent and direct, warm storyteller"
                >
                <p class="mt-1.5 text-xs text-slate-400">
                    Describe a personality, not just a mood.
                    <span class="italic">"Urgent and direct" sounds like: "This was not a debate — it was a turning point."</span>
                </p>
                @error('tone') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
            </div>

            {{-- Details --}}
            <div>
                <label class="mb-2 block text-xs font-medium uppercase tracking-wider text-slate-400">
                    Details / special requirements
                    <span class="ml-1 font-normal normal-case tracking-normal text-slate-400">(optional)</span>
                </label>
                <textarea
                    wire:model.live="details"
                    rows="3"
                    class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100
                           placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 resize-none"
                    placeholder="e.g. Focus on economic causes. Avoid graphic content. Suitable for ESL students."
                ></textarea>
                <p class="mt-1.5 text-xs text-slate-400">Constraints, focus areas, or teaching goals. Leave blank for a standard lesson.</p>
                @error('details') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
            </div>

            {{-- Avatar picker --}}
            @if($this->avatars->count() > 0)
                <div>
                    <label class="mb-3 block text-xs font-medium uppercase tracking-wider text-slate-400">
                        Narrator avatar
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($this->avatars as $avatar)
                            <label
                                wire:key="avatar-{{ $avatar->id }}"
                                class="flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-all
                                    {{ $avatar_id == $avatar->id
                                        ? 'border-amber-500 bg-amber-950/20'
                                        : 'border-slate-700 bg-slate-950 hover:border-slate-600' }}"
                            >
                                <input type="radio" wire:model.live="avatar_id"
                                       value="{{ $avatar->id }}" class="sr-only">
                                @if($avatar->portraitUrl())
                                    <img src="{{ $avatar->portraitUrl() }}"
                                         alt="{{ $avatar->name }}"
                                         class="h-12 w-12 flex-shrink-0 rounded-lg object-cover">
                                @else
                                    <div class="h-12 w-12 flex-shrink-0 rounded-lg bg-slate-800 flex items-center justify-center text-xl">🎭</div>
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm font-medium
                                        {{ $avatar_id == $avatar->id ? 'text-amber-300' : 'text-slate-200' }}">
                                        {{ $avatar->name }}
                                    </p>
                                    <p class="text-xs text-slate-500 truncate mt-0.5">{{ $avatar->description }}</p>
                                </div>
                                @if($avatar_id == $avatar->id)
                                    <span class="ml-auto text-amber-400 text-sm flex-shrink-0">✓</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    @error('avatar_id') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
            @endif

            {{-- Submit --}}
            <div class="flex items-center gap-4 pt-2 border-t border-slate-800">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-6 py-3 text-sm font-semibold text-slate-950
                           hover:bg-amber-400 transition-colors disabled:opacity-60"
                >
                    <span wire:loading.remove>Generate lesson →</span>
                    <span wire:loading class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Queuing...
                    </span>
                </button>
                <p class="text-xs text-slate-400">You'll be taken to the lesson page where you can track progress live.</p>
            </div>

        </form>
    </section>

    {{-- ── Sidebar ───────────────────────────────────────────────────────────── --}}
    <aside class="space-y-4">

        {{-- What happens --}}
        <div class="rounded-3xl border border-slate-800 bg-slate-900/60 p-6">
            <h2 class="text-sm font-semibold text-slate-200 mb-4">What the AI generates</h2>
            <ol class="space-y-3">
                @foreach([
                    ['🌐', 'Fetches facts', 'from Wikipedia to prevent hallucination'],
                    ['✍️', 'Writes a title + script', 'derived from your topic and grade level'],
                    ['❓', 'Creates a quiz', '4 comprehension questions with answers'],
                    ['🔊', 'Generates audio', 'text-to-speech narration'],
                ] as [$icon, $title, $desc])
                    <li class="flex items-start gap-3 text-sm">
                        <span class="flex-shrink-0 w-5 text-center">{{ $icon }}</span>
                        <span>
                            <span class="font-medium text-slate-300">{{ $title }}</span>
                            <span class="text-slate-400"> {{ $desc }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- Tips --}}
        <div class="rounded-3xl border border-slate-800 bg-slate-900/60 p-6">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Tips</h2>
            <ul class="space-y-2 text-xs text-slate-400">
                <li>💡 Specific topics give better results — "Battle of Thermopylae" vs "Ancient Greece"</li>
                <li>🎭 Describing a voice ("warm storyteller") beats a single adjective ("dramatic")</li>
                <li>📝 You can review the script and quiz before publishing to students</li>
            </ul>
        </div>

    </aside>

</div>
