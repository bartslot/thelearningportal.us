{{-- Module card for the lesson composer list. --}}
<div
    class="card bg-base-100 shadow"
    data-module-id="{{ $module->id }}"
    wire:key="module-card-{{ $module->id }}"
>
    <div class="card-body">
        {{-- Header row: drag handle + type + title + status --}}
        <div class="flex items-start gap-3">
            {{-- Drag handle --}}
            <button
                type="button"
                class="btn btn-ghost btn-xs"
                data-drag-handle
                aria-label="{{ __('Drag to reorder') }}"
            >
                <svg
                    class="w-5 h-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M4 6h16M4 12h16M4 18h16"
                    />
                </svg>
            </button>

            {{-- Type + Title --}}
            <div class="flex-1 min-w-0">
                <div class="text-xs text-base-content/60 uppercase tracking-wide">
                    {{ $module->type->label() }}
                </div>
                <div class="text-lg font-semibold">
                    {{ $module->title ?? __('Untitled') }}
                </div>
            </div>

            {{-- Status badge (text-based) --}}
            <div class="badge badge-sm gap-1">
                @switch($module->status)
                    @case('ready')
                        <span class="text-success">✓</span>
                        {{ __('Ready') }}
                    @break

                    @case('needs_review')
                        <span class="text-warning">!</span>
                        {{ __('Review') }}
                    @break

                    @case('missing_content')
                        <span class="text-error">×</span>
                        {{ __('Missing') }}
                    @break

                    @case('generation_failed')
                        <span class="text-error">✕</span>
                        {{ __('Failed') }}
                    @break

                    @default
                        {{ $module->status }}
                @endswitch
            </div>
        </div>

        {{-- Estimated duration --}}
        @if ($module->estimated_duration_seconds > 0)
            <div class="text-xs text-base-content/60 mt-2">
                ⏱ {{ intdiv($module->estimated_duration_seconds, 60) }}
                {{ __('min') }}
            </div>
        @endif

        {{-- Module editor (inline or collapsible) --}}
        <details class="mt-4">
            <summary class="cursor-pointer font-medium text-base-content/80">
                {{ __('Edit') }}
            </summary>
            <div class="mt-3 border-t border-base-300 pt-3">
                {!! $module->implementation()->renderEditor($module) !!}
            </div>
        </details>

        {{-- Action buttons (mobile-friendly) --}}
        <div class="flex gap-2 mt-4 flex-wrap">
            <button
                type="button"
                class="btn btn-sm btn-ghost"
                wire:click="duplicateModule({{ $module->id }})"
                title="{{ __('Duplicate this module') }}"
            >
                {{ __('Duplicate') }}
            </button>

            <button
                type="button"
                class="btn btn-sm btn-ghost"
                wire:click="moveUp({{ $module->id }})"
                title="{{ __('Move up') }}"
            >
                {{ __('↑') }}
            </button>

            <button
                type="button"
                class="btn btn-sm btn-ghost"
                wire:click="moveDown({{ $module->id }})"
                title="{{ __('Move down') }}"
            >
                {{ __('↓') }}
            </button>

            <button
                type="button"
                class="btn btn-sm btn-error btn-outline ml-auto"
                wire:click="deleteModule({{ $module->id }})"
                wire:confirm="{{ __('Are you sure you want to delete this module?') }}"
            >
                {{ __('Delete') }}
            </button>
        </div>
    </div>
</div>
