@php
    $user = auth()->user();
    $isAuthenticated = (bool) $user;
    $isTeacher = $isAuthenticated && $user->isTeacher();
    $isAdmin = $isAuthenticated && $user->role === 'admin';

    // One action, whoever is looking: the place this person actually belongs.
    [$actionUrl, $actionLabel] = match (true) {
        $isTeacher => [route('teacher.dashboard'), __('Dashboard')],
        $isAdmin => [route('admin.dashboard'), __('Dashboard')],
        $isAuthenticated => [route('home'), __('Home')],
        default => ['/login', __('Login')],
    };
@endphp

<header data-site-header class="site-header fixed inset-x-0 top-0 z-50">
    <div class="site-header__row mx-auto flex h-20 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="/#home" class="site-header__logo shrink-0" aria-label="{{ __('History Portal') }}">
            <img
                src="{{ asset('assets/logo.svg') }}"
                alt="History Portal"
                width="182"
                height="156"
                class="h-20 w-auto sm:h-16"
            >
        </a>

        <nav class="site-nav flex items-center gap-5 sm:gap-8">
            <a href="{{ route('about') }}" class="site-nav__link">{{ __('About') }}</a>
            <a href="/#pricing" class="site-nav__link">{{ __('Pricing') }}</a>
            <a href="{{ $actionUrl }}" class="site-nav__action">{{ $actionLabel }}</a>
        </nav>
    </div>
</header>
