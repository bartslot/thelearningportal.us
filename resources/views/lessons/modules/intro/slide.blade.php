@php
    /** @var \App\Models\LessonModule $module */
    $cfg = $module->config ?? [];
@endphp

<section class="card bg-base-200 lp-bg-card shadow-xl">
    <div class="card-body items-center gap-4 text-center">
        <h1 class="card-title text-3xl">
            {{ $cfg['heading'] ?? $module->title ?? __('Lesson') }}
        </h1>

        @if (! empty($cfg['subheading']))
            <p class="text-lg text-base-content/70">{{ $cfg['subheading'] }}</p>
        @endif

        @if (! empty($cfg['objectives']))
            <ul class="max-w-prose list-inside list-disc text-left">
                @foreach ($cfg['objectives'] as $objective)
                    <li>{{ $objective }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
