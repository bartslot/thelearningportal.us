@php
    $user = auth()->user();
    $isAdmin = $user && $user->role === 'admin';
    $isTeacher = $user && ($user->isTeacher() || $isAdmin);

    $roleLabel = $isAdmin ? 'Admin' : ($user && $user->isTeacher() ? 'Teacher' : null);

    $items = [];

    if ($isAdmin) {
        $items[] = [
            'label' => 'Dashboard',
            'route' => 'admin.dashboard',
            'pattern' => 'admin.dashboard',
        ];
        $items[] = [
            'label' => 'Avatar Studio',
            'route' => 'admin.avatars.index',
            'pattern' => 'admin.avatars.*',
        ];
        $items[] = [
            'label' => '3D Lab',
            'route' => 'admin.avatar-lab',
            'pattern' => 'admin.avatar-lab',
            'badge' => 'BETA',
        ];
    }

    if ($isTeacher) {
        $items[] = [
            'label' => 'Lessons',
            'route' => 'teacher.dashboard',
            'pattern' => 'teacher.dashboard',
        ];
        $items[] = [
            'label' => 'New Lesson',
            'route' => 'teacher.lessons.create',
            'pattern' => 'teacher.lessons.*',
        ];
    }

    if ($user) {
        $parts = preg_split('/\s+/', trim($user->name)) ?: [];
        $first = $parts[0] ?? '';
        $last  = count($parts) > 1 ? end($parts) : '';
        $initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
        if ($initials === '') {
            $initials = strtoupper(mb_substr($user->email ?? '?', 0, 1));
        }
    }
@endphp

<nav class="sticky top-0 z-50 border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-logo />
            </a>

            <div class="flex items-center gap-6">
                @auth
                    @foreach ($items as $item)
                        @php $active = request()->routeIs($item['pattern']); @endphp
                        <a href="{{ route($item['route']) }}"
                           class="text-sm flex items-center gap-1.5 transition-colors {{ $active ? 'text-amber-400' : 'text-slate-400 hover:text-white' }}">
                            <span>{{ $item['label'] }}</span>
                            @if (!empty($item['badge']))
                                <span class="text-[0.55rem] bg-amber-400 text-slate-900 px-1.5 py-0.5 rounded font-semibold">
                                    {{ $item['badge'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach

                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button"
                             class="flex items-center gap-2 rounded-full pl-1 pr-3 py-1 transition-colors hover:bg-slate-800/60 cursor-pointer">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-amber-400 text-xs font-semibold text-slate-900">
                                {{ $initials }}
                            </span>
                            <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>

                        <ul tabindex="0"
                            class="dropdown-content menu menu-sm mt-3 w-64 rounded-lg border border-slate-800 bg-slate-900 p-2 shadow-lg z-[60]">
                            <li class="menu-title">
                                <div class="flex items-start justify-between gap-2 px-1 py-1">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-slate-100">{{ $user->name }}</div>
                                        <div class="truncate text-xs text-slate-400">{{ $user->email }}</div>
                                    </div>
                                    @if ($roleLabel)
                                        <span class="shrink-0 rounded bg-amber-400/10 px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-amber-400">
                                            {{ $roleLabel }}
                                        </span>
                                    @endif
                                </div>
                            </li>
                            <li><div class="my-1 border-t border-slate-800"></div></li>

                            @if (Route::has('profile.edit'))
                                <li>
                                    <a href="{{ route('profile.edit') }}" class="text-sm text-slate-300 hover:bg-slate-800 hover:text-white">
                                        Profile
                                    </a>
                                </li>
                            @endif
                            @if (Route::has('settings.index'))
                                <li>
                                    <a href="{{ route('settings.index') }}" class="text-sm text-slate-300 hover:bg-slate-800 hover:text-white">
                                        Account Settings
                                    </a>
                                </li>
                            @endif
                            @if (Route::has('profile.edit') || Route::has('settings.index'))
                                <li><div class="my-1 border-t border-slate-800"></div></li>
                            @endif

                            <li>
                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <button type="submit" class="w-full text-left text-sm text-slate-300 hover:bg-slate-800 hover:text-rose-400">
                                        Sign out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="text-sm text-slate-300 transition-colors hover:text-amber-400">
                        Sign in
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>
