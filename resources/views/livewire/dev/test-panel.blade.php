{{-- data-dev-panel lets the help-screenshot capture hide this local-only widget. --}}
<div x-data="{ open: false }"
     class="fixed bottom-4 right-4 z-100 print:hidden"
     data-dev-panel
     wire:key="dev-test-panel">

    {{-- Toggle button --}}
    <button type="button" @click="open = !open"
            class="btn btn-circle btn-sm border-none bg-fuchsia-600 text-white shadow-lg hover:bg-fuchsia-500"
            title="Dev test tools (local only)">
        <span x-show="!open"><x-icons.beaker class="w-5 h-5" /></span>
        <span x-show="open" x-cloak>✕</span>
    </button>

    {{-- Panel --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-12 right-0 max-h-[80vh] w-72 overflow-y-auto overscroll-contain rounded-xl border border-fuchsia-700/60 bg-slate-900 p-4 shadow-2xl">

        <div class="mb-3 flex items-center gap-2">
            <x-icons.beaker class="w-5 h-5 text-fuchsia-400" />
            <div class="text-sm font-semibold text-fuchsia-300">Test tools</div>
            <span class="badge badge-xs border-fuchsia-700 bg-fuchsia-900/50 text-fuchsia-200">local</span>
        </div>

        @if (session('dev_status'))
            <div class="mb-3 rounded-lg border border-emerald-700 bg-emerald-900/40 px-3 py-2 text-xs text-emerald-300">
                {{ session('dev_status') }}
            </div>
        @endif

        @if ($lessonId)
            <div class="mb-3 text-xs text-slate-400">
                This lesson: <span class="font-mono text-slate-200">{{ $lessonLabel }}</span>
                · <span class="text-slate-300">{{ $resultCount }}</span> results
            </div>

            <div class="flex flex-col gap-2">
                <button type="button" wire:click="seedResults(false)"
                        wire:loading.attr="disabled" wire:target="seedResults"
                        class="btn btn-sm btn-primary justify-start">
                    <span wire:loading.remove wire:target="seedResults" class="flex items-center gap-2">
                        <x-icons.plus class="w-4 h-4" />
                        Seed 12 results
                    </span>
                    <span wire:loading wire:target="seedResults" class="loading loading-spinner loading-xs"></span>
                </button>

                <button type="button" wire:click="seedResults(true)"
                        wire:loading.attr="disabled" wire:target="seedResults"
                        class="btn btn-sm btn-secondary justify-start">
                    <x-icons.users class="w-4 h-4" />
                    Seed + test class
                </button>

                <button type="button" wire:click="downloadPaperSheets"
                        wire:loading.attr="disabled" wire:target="downloadPaperSheets"
                        class="btn btn-sm btn-outline justify-start">
                    <x-icons.camera class="w-4 h-4" />
                    Download test answer sheets
                </button>

                <button type="button" wire:click="clearResults"
                        wire:confirm="Delete ALL results for this lesson?"
                        wire:loading.attr="disabled" wire:target="clearResults"
                        class="btn btn-sm btn-outline btn-error justify-start">
                    <x-icons.trash class="w-4 h-4" />
                    Clear results
                </button>
            </div>
            <p class="mt-2 text-[0.65rem] leading-snug text-slate-500">
                Sheets are a PNG "photo" (Emma all-correct, Liam mixed) — upload it into
                <span class="text-slate-400">Import paper answers</span> to test vision extraction.
            </p>
        @else
            <div class="mb-3 rounded-lg border border-slate-700 bg-slate-800/60 px-3 py-2 text-xs text-slate-400">
                Open a lesson's Results page to seed data for it.
            </div>
        @endif

        <div class="mt-3 border-t border-slate-800 pt-3">
            <div class="mb-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">Signed in as</div>
            <div class="mb-2 text-xs text-slate-400">
                <span class="text-slate-200">{{ $currentUserName }}</span>
                <span class="badge badge-xs ml-1 border-slate-700 bg-slate-800 text-slate-300">{{ $currentRole ?: 'no role' }}</span>
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($roles as $role => $label)
                    <button type="button"
                            wire:click="switchRole('{{ $role }}')"
                            wire:loading.attr="disabled" wire:target="switchRole"
                            @disabled($role === $currentRole)
                            class="btn btn-xs {{ $role === $currentRole ? 'btn-primary' : 'btn-outline' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <p class="mt-1.5 text-[0.65rem] leading-snug text-slate-500">
                Switches account instantly, no logout needed. Switching back returns to the same account.
            </p>
        </div>

        {{-- Hero demo tuning. Only appears on a page that actually runs the animation, because
             window.heroDemo is exposed by resources/js/hero/lesson-demo.js. Values are written
             straight into the live settings object and persisted to localStorage, so a replay
             uses them immediately — no edit-and-rebuild loop. --}}
        @php
            $heroDials = [
                ['beat', 'Beat (pause between steps)', 0, 4, 0.1, 's'],
                ['typeSpeed', 'Typing speed', 5, 60, 1, 'ch/s'],
                ['inhale', 'Inhale (held breath)', 0, 3, 0.1, 's'],
                ['windUp', 'Wind-up', 0.5, 10, 0.1, 's'],
                ['brake', 'Brake', 0.3, 4, 0.1, 's'],
                ['timeScaleStart', 'Time scale, story', 0.2, 2, 0.05, '×'],
                ['timeScaleWarp', 'Time scale, warp', 0.5, 5, 0.1, '×'],
                ['warpRate', 'Warp rate', 0.2, 8, 0.1, 'e/s'],
                ['warpSpin', 'Warp spin', 0, 4, 0.05, 'rad/s'],
                ['warpTrail', 'Streak length', 0.05, 1, 0.01, ''],
                ['warpDensity', 'Card density', 0.1, 1, 0.05, ''],
                ['warpGlow', 'Mouth glow', 0, 1, 0.05, ''],
            ];
        @endphp

        <div class="mt-3 border-t border-slate-800 pt-3"
             x-data="{
                ready: false,
                s: {},
                scrub: 0,
                attach() {
                    if (!window.heroDemo) return false;
                    this.s = window.heroDemo.settings;
                    this.ready = true;
                    return true;
                },
                init() {
                    if (this.attach()) return;
                    const poll = setInterval(() => { if (this.attach()) clearInterval(poll) }, 250);
                    setTimeout(() => clearInterval(poll), 8000);
                },
                apply() { window.heroDemo.save(); window.heroDemo.replay(); this.scrub = 0 },
                onScrub() { window.heroDemo.seek(this.scrub / 100) },
             }"
             x-show="ready" x-cloak>
            <div class="mb-1.5 flex items-center justify-between">
                <span class="text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">Hero demo</span>
                <button type="button" @click="window.heroDemo.reset(); s = window.heroDemo.settings; scrub = 0"
                        class="text-[0.65rem] text-slate-500 underline hover:text-slate-300">reset</button>
            </div>

            {{-- The four warp characters. Scored in resources/js/hero/__tests__/variants.bench.test.js
                 on how many lesson pictures a teacher can actually read. --}}
            <div class="mb-2 flex flex-wrap gap-1">
                <template x-for="(label, name) in (window.heroDemo?.variants ?? {})" :key="name">
                    <button type="button"
                            @click="window.heroDemo.setVariant(name); s = window.heroDemo.settings; scrub = 0"
                            class="btn btn-xs"
                            :class="s.variant === name ? 'btn-primary' : 'btn-outline'"
                            x-text="label"></button>
                </template>
            </div>

            <div class="space-y-2">
                @foreach ($heroDials as [$key, $label, $min, $max, $step, $unit])
                    <label class="block">
                        <span class="flex items-baseline justify-between text-[0.65rem] text-slate-400">
                            {{ $label }}
                            <span class="font-mono text-slate-200"
                                  x-text="Number(s.{{ $key }}).toFixed({{ $step < 1 ? 2 : 0 }}) + '{{ $unit }}'"></span>
                        </span>
                        <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
                               x-model.number="s.{{ $key }}" @change="apply()"
                               class="range range-xs mt-0.5 w-full">
                    </label>
                @endforeach
            </div>

            <label class="mt-3 block">
                <span class="text-[0.65rem] text-slate-400">Scrub</span>
                <input type="range" min="0" max="100" step="0.5"
                       x-model.number="scrub" @input="onScrub()"
                       class="range range-xs range-secondary mt-0.5 w-full">
            </label>

            <div class="mt-2 flex gap-1.5">
                <button type="button" @click="apply()" class="btn btn-xs btn-primary flex-1">Replay</button>
                <button type="button" @click="window.heroDemo.play()" class="btn btn-xs btn-outline">Play</button>
                <button type="button" @click="window.heroDemo.pause()" class="btn btn-xs btn-outline">Pause</button>
            </div>
            <p class="mt-1.5 text-[0.65rem] leading-snug text-slate-500">
                Saved in this browser. Once it feels right, tell Claude the numbers and they become
                the defaults in <span class="text-slate-400 font-mono">hero/lesson-demo.js</span>.
            </p>
        {{-- Settings — sliders for whatever this screen has registered as tunable. Live, never
             saved: the number you land on is one for a human to write into the code deliberately.
             The panel builds itself in JS (resources/js/dev/tuner.js) because what it contains is
             decided at runtime by whatever happens to be mounted. --}}
        <div class="mt-3 border-t border-slate-800 pt-3">
            <button type="button"
                    x-on:click="window.dispatchEvent(new CustomEvent('dev:tune'))"
                    class="btn btn-sm w-full justify-start border-fuchsia-700 bg-fuchsia-900/40 text-fuchsia-200 hover:bg-fuchsia-900/70">
                Settings
                <span class="ml-auto text-[0.65rem] font-normal text-fuchsia-400/80">sliders for this screen</span>
            </button>
        </div>

        <div class="mt-3 border-t border-slate-800 pt-3">
            <div class="mb-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">Jump to</div>
            <div class="flex flex-wrap gap-1.5">
                <a href="{{ route('teacher.results.hub') }}" class="btn btn-xs btn-ghost">Results hub</a>
                <a href="{{ route('teacher.dashboard') }}" class="btn btn-xs btn-ghost">Lessons</a>
                <a href="{{ route('teacher.lessons.create') }}" class="btn btn-xs btn-ghost">New lesson</a>
            </div>
        </div>
    </div>
</div>
