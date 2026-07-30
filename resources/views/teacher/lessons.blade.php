<x-layouts.app :title="__('Lessons')">
<div class="space-y-10">
    <header class="flex flex-col gap-6 border-b border-slate-800 pb-8 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1>
                {{ __('Lessons') }}
            </h1>
            <p class="mt-4 text-sm text-slate-400">
                {{ trans_choice(':count lesson|:count lessons', $lessonCount, ['count' => $lessonCount]) }}
            </p>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('teacher.lessons.chat') }}" class="btn btn-primary">
                <x-icons.sparkles class="h-4 w-4" />
                {{ __('New lesson') }}
            </a>
        </div>
    </header>

    @include('teacher.partials.lesson-cards')
</div>
</x-layouts.app>
