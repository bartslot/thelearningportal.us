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

    {{-- Add-module sheet (DaisyUI modal, Livewire-driven open state) --}}
    <div class="modal {{ $addModuleOpen ? 'modal-open' : '' }}" role="dialog" aria-modal="true">
        <div class="modal-box max-w-sm">
            <h2 class="font-bold text-lg mb-4">{{ __('Add a Module') }}</h2>

            <div class="space-y-2">
                @foreach ($availableTypes as $type)
                    <button
                        type="button"
                        class="btn btn-outline w-full justify-start"
                        wire:click="addModule('{{ $type->value }}')"
                    >
                        {{ $type->label() }}
                    </button>
                @endforeach
            </div>

            <div class="modal-action mt-6">
                <button type="button" class="btn btn-ghost" wire:click="$set('addModuleOpen', false)">
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>

        {{-- Click-outside backdrop closes the sheet. --}}
        <button
            type="button"
            class="modal-backdrop"
            aria-label="{{ __('Close') }}"
            wire:click="$set('addModuleOpen', false)"
        ></button>
    </div>

</div>

@script
<script>
    Alpine.data('lessonComposerSortable', () => ({
        sortable: null,

        init() {
            if (typeof window.Sortable === 'undefined') {
                console.warn('SortableJS not loaded; drag-to-reorder unavailable (move buttons still work).');
                return;
            }

            // this.$el IS the list container.
            this.sortable = window.Sortable.create(this.$el, {
                animation: 150,
                handle: '[data-drag-handle]',
                ghostClass: 'opacity-50',
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
