<div x-data="{ open: false }"
     class="fixed bottom-4 right-4 z-[100] print:hidden"
     wire:key="dev-test-panel">

    {{-- Toggle button --}}
    <button type="button" @click="open = !open"
            class="btn btn-circle btn-sm border-none bg-fuchsia-600 text-white shadow-lg hover:bg-fuchsia-500"
            title="Dev test tools (local only)">
        <span x-show="!open">🧪</span>
        <span x-show="open" x-cloak>✕</span>
    </button>

    {{-- Panel --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-12 right-0 w-72 rounded-xl border border-fuchsia-700/60 bg-slate-900 p-4 shadow-2xl">

        <div class="mb-3 flex items-center gap-2">
            <span class="text-lg">🧪</span>
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
                    <span wire:loading.remove wire:target="seedResults">➕ Seed 12 results</span>
                    <span wire:loading wire:target="seedResults" class="loading loading-spinner loading-xs"></span>
                </button>

                <button type="button" wire:click="seedResults(true)"
                        wire:loading.attr="disabled" wire:target="seedResults"
                        class="btn btn-sm btn-secondary justify-start">
                    👥 Seed + test class
                </button>

                <button type="button" wire:click="downloadPaperSheets"
                        wire:loading.attr="disabled" wire:target="downloadPaperSheets"
                        class="btn btn-sm btn-outline justify-start">
                    📸 Download test answer sheets
                </button>

                <button type="button" wire:click="clearResults"
                        wire:confirm="Delete ALL results for this lesson?"
                        wire:loading.attr="disabled" wire:target="clearResults"
                        class="btn btn-sm btn-outline btn-error justify-start">
                    🗑 Clear results
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
            <div class="mb-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">Jump to</div>
            <div class="flex flex-wrap gap-1.5">
                <a href="{{ route('teacher.results.hub') }}" class="btn btn-xs btn-ghost">Results hub</a>
                <a href="{{ route('teacher.dashboard') }}" class="btn btn-xs btn-ghost">Lessons</a>
                <a href="{{ route('teacher.lessons.create') }}" class="btn btn-xs btn-ghost">New lesson</a>
            </div>
        </div>
    </div>
</div>
