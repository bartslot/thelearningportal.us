@php
    /** @var \App\Models\LessonModule $module */
    /** @var \App\Lessons\Modules\Audience $audience */
    $cfg = $module->config ?? [];
    $heading = trim($cfg['heading'] ?? '') !== ''
        ? $cfg['heading']
        : __('What we learned');
    $takeaways = $cfg['takeaways'] ?? [];
@endphp

<section class="card bg-base-200 lp-bg-card shadow-xl">
    <div class="card-body items-center gap-6 py-12 text-center">
        <span class="badge badge-primary badge-outline">{{ $module->type->label() }}</span>

        <h1 class="card-title text-4xl leading-tight">{{ $heading }}</h1>

        @if (! empty($takeaways))
            <ul class="max-w-prose space-y-3 text-left text-xl">
                @foreach ($takeaways as $takeaway)
                    <li class="flex items-start gap-3">
                        <span class="badge badge-success badge-sm mt-1.5 shrink-0" aria-hidden="true"></span>
                        <span>{{ $takeaway }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-lg text-base-content/70">
                {{ __('No takeaways yet. Add them in the composer.') }}
            </p>
        @endif

        @if (! empty($cfg['whats_next']))
            <div class="alert alert-info max-w-prose" role="note">
                <span><strong>{{ __("What's next") }}:</strong> {{ $cfg['whats_next'] }}</span>
            </div>
        @endif
    </div>
</section>
