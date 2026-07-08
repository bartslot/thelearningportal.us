<div class="mx-auto max-w-5xl px-4 py-8 space-y-4">
    <div class="flex items-center justify-between gap-2">
        <h1 class="text-xl font-bold">{{ __('Results') }}</h1>
        <div class="flex gap-2">
        <select wire:model.live="lessonId" class="select select-sm select-bordered">
            <option value="">{{ __('All lessons') }}</option>
            @foreach (\App\Models\Lesson::where('teacher_id', auth()->id())->orderBy('title')->get(['id','title','topic']) as $l)
                <option value="{{ $l->id }}">{{ $l->title ?? $l->topic }}</option>
            @endforeach
        </select>
        <select wire:model.live="range" class="select select-sm select-bordered">
            <option value="7">{{ __('Last 7 days') }}</option>
            <option value="30">{{ __('Last 30 days') }}</option>
            <option value="90">{{ __('Last 90 days') }}</option>
            <option value="all">{{ __('All time') }}</option>
        </select>
        </div>
    </div>

    <div class="space-y-1.5">
        @forelse ($this->activity as $row)
            <a href="{{ route('teacher.lessons.results', $row['lesson']) }}"
               class="flex items-center gap-3 rounded-xl border border-base-300 px-4 py-3 hover:border-warning/60 transition">
                <div class="flex-1">
                    <span class="font-semibold">{{ $row['lesson']->title ?? $row['lesson']->topic }}</span>
                    <span class="text-xs opacity-60 ml-2">{{ \Carbon\Carbon::parse($row['day'])->translatedFormat('d M Y') }}</span>
                </div>
                <span class="text-sm">{{ $row['players'] }} {{ __('players') }}</span>
                <span class="text-sm font-semibold">{{ $row['avg_correct_pct'] }}%</span>
                @if ($row['needs_help'] > 0)
                    <span class="badge badge-error badge-outline">{{ $row['needs_help'] }} {{ __('need help') }}</span>
                @else
                    <span class="badge badge-success badge-outline">{{ __('on track') }}</span>
                @endif
            </a>
        @empty
            <p class="text-sm opacity-60">{{ __('No quiz activity yet. Results appear here after students play.') }}</p>
        @endforelse
    </div>
</div>
