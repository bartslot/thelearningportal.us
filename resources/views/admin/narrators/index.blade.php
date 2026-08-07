<x-layouts.app title="Narrators">
<div class="space-y-8">

    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-widest text-rose-400">Admin · Narrators</p>
            <h1 class="font-history text-5xl font-semibold text-slate-100 md:text-6xl xl:text-7xl">Narrator management</h1>
            <p class="mt-1 text-sm text-slate-500">Configure voice, portrait and behaviour for each narrator.</p>
        </div>
        <a href="{{ route('admin.narrators.create') }}"
           class="btn btn-primary shrink-0">
            <span aria-hidden="true">＋</span> Add narrator
        </a>
    </div>

    <div class="space-y-4">
        @forelse($narrators as $narrator)
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 flex items-center gap-5">

                {{-- Portrait --}}
                @if($narrator->portraitUrl())
                    <img src="{{ $narrator->portraitUrl() }}"
                         alt="{{ $narrator->name }}"
                         class="h-16 w-16 flex-shrink-0 rounded-xl object-cover border border-slate-700">
                @else
                    <div class="h-16 w-16 flex-shrink-0 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center">
                        <x-icons.user-circle class="w-8 h-8 text-slate-500" />
                    </div>
                @endif

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs border
                            {{ $narrator->is_active ? 'bg-emerald-950/60 border-emerald-700 text-emerald-300' : 'bg-slate-900 border-slate-700 text-slate-500' }}">
                            {{ $narrator->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="text-xs text-slate-600">·</span>
                        <span class="text-xs text-slate-500 capitalize">{{ $narrator->subject === 'all' ? 'All subjects' : $narrator->subject }}</span>
                    </div>
                    <h2 class="text-base font-semibold text-slate-100">{{ $narrator->name }}</h2>
                    <p class="text-sm text-slate-500 mt-0.5 truncate">{{ $narrator->description }}</p>
                    <p class="text-xs text-slate-600 mt-1">
                        Voice: <span class="text-slate-400">{{ $narrator->voice_provider }} / {{ $narrator->voice_id }}</span>
                        · Speed: <span class="text-slate-400">{{ $narrator->voice_speed }}×</span>
                        · <span class="text-slate-400">{{ $narrator->lessons_count }} lessons</span>
                    </p>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="{{ route('admin.narrators.studio', $narrator) }}"
                       class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors">
                        Open Studio
                    </a>
                    <form method="POST" action="{{ route('admin.narrators.toggle', $narrator) }}">
                        @csrf @method('PATCH')
                        <button type="submit"
                                class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-400 hover:border-slate-500 hover:text-slate-300 transition-colors">
                            {{ $narrator->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                </div>

            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-700 p-10 text-center">
                <div class="mb-2 flex justify-center">
                    <x-icons.user-circle class="w-8 h-8 text-slate-500" />
                </div>
                <p class="text-sm text-slate-400">No narrators yet. Create the first one to start building your cast.</p>
                <a href="{{ route('admin.narrators.create') }}" class="btn btn-primary btn-sm mt-4">Add your first narrator</a>
            </div>
        @endforelse
    </div>

</div>
</x-layouts.app>
