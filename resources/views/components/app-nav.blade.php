@php
    $user = auth()->user();
    $isAdmin = $user && $user->role === 'admin';
    $isTeacher = $user && ($user->isTeacher() || $isAdmin);

    $roleLabel = $isAdmin ? 'Admin' : ($user && $user->isTeacher() ? 'Teacher' : null);

    $items = [];

    // Admin surfaces live in the account menu, not the main bar: an admin works in the
    // teacher workspace like everyone else, so the bar stays identical across roles
    // (and there is only ever one "Dashboard" in it).
    $adminItems = [];

    if ($isAdmin) {
        $adminItems[] = [
            'label' => 'Admin dashboard',
            'route' => 'admin.dashboard',
            'pattern' => 'admin.dashboard',
        ];
        $adminItems[] = [
            'label' => 'Avatar Studio',
            'route' => 'admin.avatars.index',
            'pattern' => 'admin.avatars.*',
        ];
        $adminItems[] = [
            'label' => 'Story review',
            'route' => 'admin.stories.review',
            'pattern' => 'admin.stories.*',
        ];
    }

    if ($isTeacher) {
        $items[] = [
            'label' => 'Dashboard',
            'route' => 'teacher.dashboard',
            'pattern' => 'teacher.dashboard',
        ];
        $items[] = [
            'label' => 'Explorer',
            'route' => 'teacher.timemap',
            'pattern' => 'teacher.timemap',
        ];
        // Lessons owns everything that MAKES a lesson. "New Lesson" used to sit beside it as a sixth
        // top-level item, which is a choice the teacher had to make before knowing what they wanted.
        // Five top-level destinations, and the ways to start one live under the thing they produce.
        $items[] = [
            'label' => 'Lessons',
            'route' => 'teacher.lessons.index',
            'pattern' => ['teacher.lessons.index', 'teacher.lessons.create', 'teacher.lessons.chat', 'teacher.lessons.wizard', 'teacher.lessons.show', 'teacher.lessons.composer'],
            'tour' => 'nav-lessons',
            'children' => [
                ['label' => 'All lessons', 'route' => 'teacher.lessons.index'],
                ['label' => 'New Lesson', 'route' => 'teacher.lessons.create'],
                ['label' => 'Create with AI', 'route' => 'teacher.lessons.chat'],
            ],
        ];
        $items[] = [
            'label' => 'Classes',
            'route' => 'teacher.classes.index',
            'pattern' => 'teacher.classes.*',
        ];
        $items[] = [
            'label' => 'Results',
            'route' => 'teacher.results.hub',
            'tour' => 'nav-results',
            // A lesson's report/answer-sheet are Results surfaces, though their route names live under lessons.*
            'pattern' => ['teacher.results.*', 'teacher.lessons.results', 'teacher.lessons.answer-sheet'],
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

<nav class="sticky top-0 z-50 border-none hover:border-b border-slate-800 hover:bg-slate-900/80 bg-slate-900/0 hover:backdrop-blur-sm transition-colors">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-logo />
            </a>

            <div class="flex items-center gap-3 sm:gap-6">
                @auth
                    {{-- Desktop: inline nav links. Collapses at lg, not sm — six labels plus the
                         help and account controls overflow well before 640px. --}}
                    {{-- One indicator follows the pointer across the links rather than each link
                         lighting its own background. It reads as a single object being moved, which
                         is quieter than six things switching on and off, and it costs one element.

                         x-data is an INLINE OBJECT, not a named function: Livewire morphs this nav
                         in, and a <script> arriving through a morph never executes — x-data would
                         then reference a global that does not exist and Alpine would throw on every
                         page. See the voyage-inspector fix for what that looks like in the wild.

                         Monochrome on purpose. Amber says "you are here"; the pointer is not a
                         place, so it gets white at 6%. --}}
                    <div class="relative hidden items-center gap-6 lg:flex"
                         x-data="{ x: 0, w: 0, on: false,
                                   move(el) { this.x = el.offsetLeft - 12; this.w = el.offsetWidth + 24; this.on = true },
                                   hide() { this.on = false } }"
                         @mouseleave="hide()">
                        <span aria-hidden="true"
                              class="pointer-events-none absolute left-0 top-1/2 -z-10 h-9 rounded-lg bg-white/[0.06]"
                              style="transition: transform 220ms cubic-bezier(0.645,0.045,0.355,1), width 220ms cubic-bezier(0.645,0.045,0.355,1), opacity 160ms linear"
                              :style="`transform: translate(${x}px, -50%); width: ${w}px; opacity: ${on ? 1 : 0}`"></span>

                        @foreach ($items as $item)
                            @php $active = request()->routeIs(...(array) $item['pattern']); @endphp
                            @if (!empty($item['children']))
                                {{-- DaisyUI dropdown, opened the same way the account menu is, so a
                                     menu behaves identically wherever it appears. --}}
                                <div class="dropdown" @mouseenter="move($el)">
                                    <div tabindex="0" role="button"
                                         @isset($item['tour']) data-tour="{{ $item['tour'] }}" @endisset
                                         class="text-sm flex cursor-pointer items-center gap-1.5 whitespace-nowrap transition-colors {{ $active ? 'text-amber-400' : 'text-slate-400 hover:text-white' }}">
                                        <span>{{ __($item['label']) }}</span>
                                        <x-icons.chevron-down class="h-3.5 w-3.5 opacity-60" />
                                    </div>
                                    <ul tabindex="0" class="dropdown-content menu z-50 mt-3 w-52 rounded-box bg-slate-900/95 p-2 shadow-lg ring-1 ring-white/10 backdrop-blur-sm">
                                        @foreach ($item['children'] as $child)
                                            <li>
                                                <a href="{{ route($child['route']) }}"
                                                   class="text-sm {{ request()->routeIs($child['route']) ? 'text-amber-400' : 'text-slate-300' }}">
                                                    {{ __($child['label']) }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @else
                                {{-- data-tour marks the elements the welcome tour spotlights. Only the
                                     desktop links carry it: on a narrow screen these are hidden, the
                                     tour finds no anchor, and the step falls back to a centred card. --}}
                                <a href="{{ route($item['route']) }}"
                                   @isset($item['tour']) data-tour="{{ $item['tour'] }}" @endisset
                                   @mouseenter="move($el)"
                                   class="text-sm flex items-center gap-1.5 whitespace-nowrap transition-colors {{ $active ? 'text-amber-400' : 'text-slate-400 hover:text-white' }}">
                                    <span>{{ __($item['label']) }}</span>
                                    @if (!empty($item['badge']))
                                        <span class="text-[0.55rem] bg-amber-400 text-slate-900 px-1.5 py-0.5 rounded font-semibold">
                                            {{ $item['badge'] }}
                                        </span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </div>

                    {{-- Help is one click away from every page, for every signed-in user. --}}
                    <a href="{{ route('help.index') }}" title="{{ __('Help') }}" aria-label="{{ __('Help') }}" data-tour="help"
                       class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors hover:bg-slate-800/60 {{ request()->routeIs('help.index') ? 'text-amber-400' : 'text-slate-400 hover:text-white' }}">
                        <x-icons.question-mark-circle class="h-5 w-5" />
                    </a>

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
                            class="dropdown-content menu menu-sm mt-3 w-64 rounded-lg border border-slate-800 bg-slate-900 p-2 shadow-lg z-60">
                            <li class="menu-title">
                                <div class="flex items-start justify-between gap-2 px-1 py-1">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-slate-100">{{ $user->name }}</div>
                                        <div class="truncate text-xs text-slate-400">{{ $user->email }}</div>
                                    </div>
                                    @if ($roleLabel)
                                        <span class="shrink-0 rounded bg-amber-400/10 px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-amber-400">
                                            {{ __($roleLabel) }}
                                        </span>
                                    @endif
                                </div>
                            </li>
                            <li><div class="my-1 border-t border-slate-800"></div></li>

                            @if ($adminItems)
                                <li class="menu-title px-3 py-1 text-[0.6rem] font-semibold uppercase tracking-wide text-slate-500">
                                    {{ __('Admin') }}
                                </li>
                                @foreach ($adminItems as $item)
                                    @php $active = request()->routeIs(...(array) $item['pattern']); @endphp
                                    <li>
                                        <a href="{{ route($item['route']) }}"
                                           class="text-sm {{ $active ? 'text-amber-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                            {{ __($item['label']) }}
                                        </a>
                                    </li>
                                @endforeach
                                <li><div class="my-1 border-t border-slate-800"></div></li>
                            @endif

                            @if (Route::has('profile.edit'))
                                <li>
                                    <a href="{{ route('profile.edit') }}" class="text-sm text-slate-300 hover:bg-slate-800 hover:text-white">
                                        {{ __('Profile') }}
                                    </a>
                                </li>
                            @endif
                            @if (Route::has('settings.index'))
                                <li>
                                    <a href="{{ route('settings.index') }}" class="text-sm text-slate-300 hover:bg-slate-800 hover:text-white">
                                        {{ __('Account Settings') }}
                                    </a>
                                </li>
                            @endif
                            <li>
                                <a href="{{ route('help.index') }}" class="text-sm text-slate-300 hover:bg-slate-800 hover:text-white">
                                    {{ __('Help') }}
                                </a>
                            </li>
                            @if (Route::has('profile.edit') || Route::has('settings.index'))
                                <li class="my-1 border-t border-slate-800"></li>
                            @endif

                            <li>
                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <button type="submit" class="w-full text-left text-sm text-slate-300 hover:bg-slate-800 hover:text-rose-400">
                                        {{ __('Sign out') }}
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>

                    {{-- Below lg the nav links collapse in here, and it sits last: the menu
                         button belongs in the far corner, past help and the account chip. --}}
                    <div class="dropdown dropdown-end lg:hidden">
                        <div tabindex="0" role="button" aria-label="{{ __('Menu') }}"
                             class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-slate-300 transition-colors hover:bg-slate-800/60 hover:text-white">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </div>
                        <ul tabindex="0"
                            class="dropdown-content menu menu-sm mt-3 w-56 rounded-lg border border-slate-800 bg-slate-900 p-2 shadow-lg z-60">
                            @foreach ($items as $item)
                                @php $active = request()->routeIs(...(array) $item['pattern']); @endphp
                                <li>
                                    <a href="{{ route($item['route']) }}"
                                       class="text-sm {{ $active ? 'text-amber-400' : 'text-slate-300 hover:text-white' }}">
                                        {{ __($item['label']) }}
                                        @if (!empty($item['badge']))
                                            <span class="text-[0.55rem] bg-amber-400 text-slate-900 px-1.5 py-0.5 rounded font-semibold">
                                                {{ $item['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="text-sm text-slate-300 transition-colors hover:text-amber-400">
                        {{ __('Sign in') }}
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>
