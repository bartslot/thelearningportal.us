@php
    /** @var \App\Enums\ModuleType $type */
    // Schematic mini-preview of a module type for the "Add a Module" picker (Keynote-style).
    // Decorative only — fills its container (h-full w-full). Uses theme tokens, no raw colors.
@endphp
@switch($type->value)
    @case('intro')
    @case('title_block')
        <div class="flex h-full w-full flex-col items-center justify-center gap-1 bg-base-100">
            <div class="h-2 w-1/2 rounded-sm bg-primary"></div>
            <div class="h-1 w-1/3 rounded-sm bg-base-300"></div>
        </div>
        @break

    @case('quiz_mcq')
    @case('quiz_image')
        <div class="flex h-full w-full flex-col gap-1 bg-base-100 p-2">
            <div class="h-1.5 w-3/4 rounded-sm bg-base-content/40"></div>
            <div class="grid flex-1 grid-cols-2 gap-1">
                <div class="rounded-sm bg-success/60"></div>
                <div class="rounded-sm bg-base-300"></div>
                <div class="rounded-sm bg-base-300"></div>
                <div class="rounded-sm bg-base-300"></div>
            </div>
        </div>
        @break

    @case('story_block')
        <div class="flex h-full w-full items-start gap-1.5 bg-base-100 p-2">
            <div class="h-4 w-4 shrink-0 rounded-full bg-secondary"></div>
            <div class="flex flex-1 flex-col gap-1 pt-0.5">
                <div class="h-1 w-full rounded-sm bg-base-300"></div>
                <div class="h-1 w-5/6 rounded-sm bg-base-300"></div>
                <div class="h-1 w-2/3 rounded-sm bg-base-300"></div>
            </div>
        </div>
        @break

    @case('prior_knowledge')
    @case('reflection')
        <div class="flex h-full w-full items-center justify-center bg-base-100">
            <div class="rounded-lg rounded-bl-none bg-primary/15 px-2.5 py-1.5 text-sm font-bold text-primary">?</div>
        </div>
        @break

    @case('timeline_map')
    @case('quiz_map')
    @case('map_challenge')
        <div class="flex h-full w-full flex-col justify-center gap-1.5 bg-base-100 p-2">
            <div class="relative h-1 w-full rounded-sm bg-base-300">
                <div class="absolute -top-1 left-1/3 h-3 w-3 rounded-full border-2 border-primary bg-base-100"></div>
            </div>
            <div class="h-1/2 w-full rounded-sm bg-accent/25"></div>
        </div>
        @break

    @case('three_d_model')
        <div class="flex h-full w-full items-center justify-center bg-base-100">
            <div class="h-7 w-7 rotate-12 rounded-sm border-2 border-primary/70 bg-primary/10"></div>
        </div>
        @break

    @case('conclusion')
        <div class="flex h-full w-full flex-col items-center justify-center gap-1 bg-base-100">
            <div class="h-2 w-1/2 rounded-sm bg-primary"></div>
            <div class="text-xs text-success">✓</div>
        </div>
        @break

    @default
        <div class="flex h-full w-full flex-col justify-center gap-1 bg-base-100 p-2">
            <div class="h-1 w-full rounded-sm bg-base-300"></div>
            <div class="h-1 w-5/6 rounded-sm bg-base-300"></div>
            <div class="h-1 w-2/3 rounded-sm bg-base-300"></div>
        </div>
@endswitch
