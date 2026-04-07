@php
    $user = auth()->user();
    $isAuthenticated = (bool) $user;
    $isTeacher = $isAuthenticated && $user->isTeacher();
    $isAdmin = $isAuthenticated && $user->role === 'admin';
@endphp

<header class="fixed inset-x-0 top-0 z-50">
    <div class="mx-auto flex h-20 max-w-7xl items-center justify-between px-6 lg:px-8">
        <a href="#home" class="shrink-0">
            <img src="{{ asset('assets/logo.svg') }}" alt="History Portal" class="h-16 w-auto">
        </a>

        <nav class="flex items-center gap-8">
            <a href="#about" class="text-sm text-white/80 transition hover:text-white">About</a>
            <a href="#pricing" class="text-sm text-white/80 transition hover:text-white">Pricing</a>
            @if($isTeacher)
                <a
                    href="{{ route('teacher.dashboard') }}"
                    class="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-slate-900 shadow transition hover:bg-white/90"
                >
                    Dashboard
                </a>
            @elseif($isAdmin)
                <a
                    href="{{ route('admin.dashboard') }}"
                    class="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-slate-900 shadow transition hover:bg-white/90"
                >
                    Dashboard
                </a>
            @elseif($isAuthenticated)
                <a
                    href="{{ route('home') }}"
                    class="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-slate-900 shadow transition hover:bg-white/90"
                >
                    Home
                </a>
            @else
                <a
                    href="/login"
                    class="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-slate-900 shadow transition hover:bg-white/90"
                >
                    Login
                </a>
            @endif
        </nav>
    </div>
</header>
