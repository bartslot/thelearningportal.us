<x-layouts.app title="Admin Dashboard">
<div class="space-y-8">

    <div>
        <p class="text-xs uppercase tracking-widest text-rose-400">Super Admin</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-100">Admin dashboard</h1>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('admin.avatars.index') }}"
           class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 hover:border-amber-600/50 transition-colors group">
            <p class="text-3xl mb-3">🎭</p>
            <h2 class="font-semibold text-slate-200 group-hover:text-amber-400 transition-colors">Avatars</h2>
            <p class="text-sm text-slate-500 mt-1">{{ $avatarCount }} configured</p>
        </a>

        <a href="{{ route('teacher.dashboard') }}"
           class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 hover:border-amber-600/50 transition-colors group">
            <p class="text-3xl mb-3">📚</p>
            <h2 class="font-semibold text-slate-200 group-hover:text-amber-400 transition-colors">Lessons</h2>
            <p class="text-sm text-slate-500 mt-1">Manage all lessons</p>
        </a>
    </div>

</div>
</x-layouts.app>
