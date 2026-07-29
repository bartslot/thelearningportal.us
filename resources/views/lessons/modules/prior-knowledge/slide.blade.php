@php
    /** @var \App\Models\LessonModule $module */
    /** @var \App\Lessons\Modules\Audience $audience */
    $cfg = $module->config ?? [];
    $question = trim($cfg['question'] ?? '') !== ''
        ? $cfg['question']
        : __('What do you already know about this topic?');
    $seedTerms = $cfg['seed_terms'] ?? [];
@endphp

<section class="card bg-base-200 lp-bg-card shadow-xl" x-data="{ revealed: false }">
    <div class="card-body items-center gap-6 py-12 text-center">
        <span class="badge badge-primary badge-outline">{{ $module->type->label() }}</span>

        <h1 class="card-title max-w-prose text-4xl leading-tight">
            {{ $question }}
        </h1>

        <p class="text-lg text-base-content/70">
            {{ __('Share everything that comes to mind. There are no wrong answers.') }}
        </p>

        @if (! empty($seedTerms))
            <button type="button" class="btn btn-outline btn-primary"
                    x-show="! revealed" @click="revealed = true">
                {{ __('Reveal hint words') }}
            </button>

            <div class="flex flex-wrap justify-center gap-2" x-show="revealed" x-cloak>
                @foreach ($seedTerms as $term)
                    <span class="badge badge-secondary badge-lg">{{ $term }}</span>
                @endforeach
            </div>
        @endif
    </div>
</section>
