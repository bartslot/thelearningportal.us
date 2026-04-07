<x-layouts.app title="Avatars">
<div class="space-y-8">

    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-widest text-rose-400">Admin · Avatars</p>
            <h1 class="mt-2 text-3xl font-semibold text-slate-100">Avatar management</h1>
            <p class="mt-1 text-sm text-slate-500">Configure voice, portrait and behaviour for each avatar character.</p>
        </div>
    </div>

    <div class="space-y-4">
        @forelse($avatars as $avatar)
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 flex items-center gap-5">

                {{-- Portrait --}}
                @if($avatar->portraitUrl())
                    <img src="{{ $avatar->portraitUrl() }}"
                         alt="{{ $avatar->name }}"
                         class="h-16 w-16 flex-shrink-0 rounded-xl object-cover border border-slate-700">
                @else
                    <div class="h-16 w-16 flex-shrink-0 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-2xl">🎭</div>
                @endif

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs border
                            {{ $avatar->is_active ? 'bg-emerald-950/60 border-emerald-700 text-emerald-300' : 'bg-slate-900 border-slate-700 text-slate-500' }}">
                            {{ $avatar->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="text-xs text-slate-600">·</span>
                        <span class="text-xs text-slate-500 capitalize">{{ $avatar->subject === 'all' ? 'All subjects' : $avatar->subject }}</span>
                    </div>
                    <h2 class="text-base font-semibold text-slate-100">{{ $avatar->name }}</h2>
                    <p class="text-sm text-slate-500 mt-0.5 truncate">{{ $avatar->description }}</p>
                    <p class="text-xs text-slate-600 mt-1">
                        Voice: <span class="text-slate-400">{{ $avatar->voice_provider }} / {{ $avatar->voice_id }}</span>
                        · Speed: <span class="text-slate-400">{{ $avatar->voice_speed }}×</span>
                        · <span class="text-slate-400">{{ $avatar->lessons_count }} lessons</span>
                    </p>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="{{ route('admin.avatars.studio', $avatar) }}"
                       class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition-colors">
                        Open Studio
                    </a>
                    <form method="POST" action="{{ route('admin.avatars.toggle', $avatar) }}">
                        @csrf @method('PATCH')
                        <button type="submit"
                                class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-400 hover:border-slate-500 hover:text-slate-300 transition-colors">
                            {{ $avatar->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                </div>

            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-700 p-10 text-center">
                <p class="text-2xl mb-2">🎭</p>
                <p class="text-sm text-slate-400">No avatars yet. Run <code class="text-amber-400">php artisan db:seed --class=AvatarSeeder</code> to create The Professor.</p>
            </div>
        @endforelse
    </div>

</div>
</x-layouts.app>
