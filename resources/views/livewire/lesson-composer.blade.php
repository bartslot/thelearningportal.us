<div class="min-h-screen bg-base-200 text-base-content pb-32" wire:key="composer-{{ $lesson->id }}">

    {{-- Header --}}
    <div class="sticky top-0 z-10 bg-base-100 border-b border-base-300 shadow">
        <div class="max-w-3xl mx-auto px-4 py-4">
            <h1 class="text-2xl font-bold">{{ __('Build Your Lesson') }}</h1>
            <p class="text-sm text-base-content/70 mt-1">
                {{ __('Add, arrange, and configure lesson modules') }}
            </p>
        </div>
    </div>

    {{-- Module list --}}
    <div class="max-w-3xl mx-auto px-4 py-6">

        @if ($modules->isEmpty())
            <div class="card bg-base-100 shadow">
                <div class="card-body items-center text-center">
                    <p class="text-base-content/60">
                        {{ __('No modules yet. Add one to get started.') }}
                    </p>
                </div>
            </div>
        @else
            {{-- Sortable list of module cards. SortableJS is registered globally in app.js. --}}
            <div
                class="space-y-3"
                x-data="lessonComposerSortable()"
                x-init="init()"
                wire:key="modules-list"
            >
                @foreach ($modules as $module)
                    @include('livewire.lesson-composer-card', ['module' => $module])
                @endforeach
            </div>
        @endif

    </div>

    {{-- Sticky bottom action bar (mobile-first) --}}
    <div class="fixed bottom-0 left-0 right-0 bg-base-100 border-t border-base-300 shadow-lg">
        <div class="max-w-3xl mx-auto px-4 py-4 flex gap-2">
            <button
                type="button"
                class="btn btn-primary flex-1"
                wire:click="$set('addModuleOpen', true)"
            >
                <span class="text-lg" aria-hidden="true">+</span>
                {{ __('Add Module') }}
            </button>
        </div>
    </div>

    {{-- Add-module picker (Keynote "add slide" style): bottom sheet on mobile, centered on desktop.
         Each tile shows a schematic preview of the block; roadmap types appear as disabled "Soon". --}}
    <div class="modal modal-bottom sm:modal-middle {{ $addModuleOpen ? 'modal-open' : '' }}"
         role="dialog" aria-modal="true">
        <div class="modal-box max-w-lg">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold">{{ __('Add a module') }}</h2>
                <button type="button" class="btn btn-sm btn-circle btn-ghost"
                        aria-label="{{ __('Close') }}" wire:click="$set('addModuleOpen', false)">✕</button>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {{-- Buildable now --}}
                @foreach ($availableTypes as $type)
                    <button type="button" wire:click="addModule('{{ $type->value }}')"
                        class="group flex flex-col gap-2 rounded-box border border-base-300 bg-base-200 p-2 text-left transition hover:border-primary hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <div class="h-16 w-full overflow-hidden rounded-md ring-1 ring-base-300 transition group-hover:ring-primary sm:h-20">
                            @include('lessons.modules.picker-thumb', ['type' => $type])
                        </div>
                        <span class="text-sm font-medium">{{ $type->label() }}</span>
                    </button>
                @endforeach

                {{-- Roadmap preview (not yet buildable) --}}
                @foreach ($comingSoonTypes as $type)
                    <div class="relative flex cursor-not-allowed flex-col gap-2 rounded-box border border-base-300 bg-base-200/50 p-2"
                         aria-disabled="true">
                        <span class="badge badge-sm absolute right-2 top-2 z-10">{{ __('Soon') }}</span>
                        <div class="h-16 w-full overflow-hidden rounded-md opacity-50 grayscale ring-1 ring-base-300 sm:h-20">
                            @include('lessons.modules.picker-thumb', ['type' => $type])
                        </div>
                        <span class="text-sm font-medium text-base-content/50">{{ $type->label() }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Click-outside backdrop closes the picker. --}}
        <button
            type="button"
            class="modal-backdrop"
            aria-label="{{ __('Close') }}"
            wire:click="$set('addModuleOpen', false)"
        ></button>
    </div>

</div>

@assets
<style>
    /* Drop slot left behind by the card being dragged. */
    .lp-drag-ghost {
        opacity: .4;
        outline: 2px dashed var(--color-primary);
        outline-offset: -2px;
    }
    .lp-drag-chosen { cursor: grabbing; }
    /* The floating clone under the pointer — a subtle lift. */
    .lp-drag-active {
        box-shadow: 0 14px 30px -10px rgba(2, 6, 23, .45);
        transform: scale(1.03);
        transition: transform 150ms cubic-bezier(0.16, 1, 0.3, 1);
    }
    @media (prefers-reduced-motion: reduce) {
        .lp-drag-active { transform: none; box-shadow: none; }
    }
</style>
@endassets

@script
<script>
    Alpine.data('lessonComposerSortable', () => ({
        sortable: null,

        init() {
            if (typeof window.Sortable === 'undefined') {
                console.warn('SortableJS not loaded; drag-to-reorder unavailable (move buttons still work).');
                return;
            }

            // Honour reduced-motion: keep the reorder instant, no springy shift.
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // this.$el IS the list container.
            this.sortable = window.Sortable.create(this.$el, {
                handle: '[data-drag-handle]',
                // Siblings ease aside to make room as a card is dragged between them.
                // Restrained spring (mild overshoot) — elastic feel without the dated bounce.
                // Dial the third value: 1 = clean ease-out, ~1.4 = bouncier.
                animation: reduceMotion ? 0 : 220,
                easing: 'cubic-bezier(0.34, 1.25, 0.64, 1)',
                // Fallback drag = a styleable clone, so the lift + ghost render consistently
                // (incl. touch on mobile), unlike the unstyleable native drag image.
                forceFallback: true,
                fallbackTolerance: 3,
                ghostClass: 'lp-drag-ghost',
                chosenClass: 'lp-drag-chosen',
                dragClass: 'lp-drag-active',
                onEnd: () => {
                    const orderedIds = Array.from(this.$el.querySelectorAll('[data-module-id]'))
                        .map((node) => parseInt(node.dataset.moduleId, 10));

                    // Single array argument — matches reorder(array $orderedIds).
                    this.$wire.call('reorder', orderedIds);
                },
            });
        },
    }));
</script>
@endscript
