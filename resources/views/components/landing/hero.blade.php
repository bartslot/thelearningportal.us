@php
    $images = [
        '1history.jpg',
        '2history.jpg',
        '3history.jpg',
        '4history.jpg',
        '5history.jpg',
        '6history.jpg',
        '7history.jpg',
        '8history.jpg',
        '9history.jpg',
        '10history.jpg',
        '11history.jpg',
        '12history.jpg',
    ];

    $portalCards = [
        'historycards/history.jpg',
        'historycards/history12.jpg',
        'historycards/history16.jpg',
        'historycards/history17.jpg',
        'historycards/history18.jpg',
        'historycards/history19.jpg',
        'historycards/history26.jpg',
        'historycards/history115.jpg',
    ];

    $count = count($images);
    $isTeacher = auth()->check() && auth()->user()->isTeacher();
    $hasRegisterRoute = \Illuminate\Support\Facades\Route::has('register');
@endphp

<section
    id="home"
    class="relative isolate flex min-h-screen flex-col items-center justify-center overflow-hidden"
    data-portal-images='@json(array_map(fn ($image) => asset("assets/{$image}"), $portalCards))'
>
    {{-- Deep navy radial gradient background --}}
    <img src="{{ asset('assets/videocards.webp') }}" alt="wheel" class="h-7xl w-7xl pointer-events-none absolute wheel z-10" />
    <div class="hero-glow pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(ellipse_80%_60%_at_50%_100%,#0d2a4a_0%,#020b24_55%,#010510_100%)] opacity-60"></div>
    {{-- Subtle center glow --}}
    <div class="hero-spotlight pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(ellipse_50%_40%_at_50%_60%,rgba(30,80,140,0.45)_0%,transparent_70%)] bg-blend-overlay"></div>
    <div class="hero-orb pointer-events-none absolute left-1/2 top-[16%] z-0 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-[radial-gradient(circle,rgba(56,189,248,0.14)_0%,rgba(56,189,248,0.05)_35%,transparent_72%)] blur-3xl"></div>

    {{-- Centered text content --}}
    <div class="hero-copy relative z-30 mt-20 max-w-4xl px-4 text-center">
        <h1 class="text-4xl leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
            <span class="text-white">Effective History Teaching</span>
        </h1>

        <p class="mx-auto mt-6 max-w-2xl text-sm leading-relaxed text-white/60 sm:text-base">
            We use storytelling to engage learners and make history come alive.</p>
        <p class="mx-auto mt-4 max-w-2xl text-sm leading-relaxed text-white/60 sm:text-base">
            Our platform is currently in beta and invite-only.<br>
            Request an invite now to receive a link to create your account.
        </p>

        <div class="hero-cta mt-10 relative z-30">
            @if($isTeacher)
                <a
                    href="{{ route('teacher.lessons.create') }}"
                    class="inline-flex items-center justify-center rounded-full bg-white px-8 py-3.5 text-sm font-semibold text-slate-900 shadow-lg transition hover:bg-white/90"
                >
                    Create a lesson
                </a>
            @else
                <a
                    href="{{ route('login') }}"
                    data-portal-launch
                    class="inline-flex items-center justify-center rounded-full bg-white px-8 py-3.5 text-sm font-semibold text-slate-900 shadow-lg transition hover:bg-white/90"
                >
                    Join the History portal
                </a>
            @endif
        </div>
    </div>

    @unless($isTeacher)
        <div
            data-portal-signup
            class="pointer-events-none absolute left-1/2 top-1/2 z-40 w-full max-w-md -translate-x-1/2 -translate-y-1/2 px-4 opacity-0"
            aria-hidden="true"
        >
            <div class="rounded-3xl border border-white/15 bg-slate-950/70 p-6 shadow-2xl backdrop-blur-xl sm:p-8">
                <h2 class="text-center text-2xl font-semibold text-white">Create your account</h2>
                <p class="mt-2 text-center text-sm text-slate-300">Temporary form: using register flow for now.</p>

                <form method="{{ $hasRegisterRoute ? 'POST' : 'GET' }}" action="{{ $hasRegisterRoute ? route('register') : route('login') }}" class="mt-6 space-y-4">
                    @if($hasRegisterRoute)
                        @csrf
                    @endif
                    <div>
                        <label for="portal_name" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-300">Name</label>
                        <input
                            id="portal_name"
                            name="name"
                            type="text"
                            required
                            class="w-full rounded-xl border border-white/15 bg-slate-900/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-sky-300/70"
                        >
                    </div>

                    <div>
                        <label for="portal_email" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-300">Email</label>
                        <input
                            id="portal_email"
                            name="email"
                            type="email"
                            required
                            class="w-full rounded-xl border border-white/15 bg-slate-900/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-sky-300/70"
                        >
                    </div>

                    <div>
                        <label for="portal_password" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-300">Password</label>
                        <input
                            id="portal_password"
                            name="password"
                            type="password"
                            required
                            class="w-full rounded-xl border border-white/15 bg-slate-900/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-sky-300/70"
                        >
                    </div>

                    <div>
                        <label for="portal_password_confirmation" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-300">Confirm password</label>
                        <input
                            id="portal_password_confirmation"
                            name="password_confirmation"
                            type="password"
                            required
                            class="w-full rounded-xl border border-white/15 bg-slate-900/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-sky-300/70"
                        >
                    </div>

                    <button
                        type="submit"
                        class="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-white/90"
                    >
                        {{ $hasRegisterRoute ? 'Register' : 'Continue to login' }}
                    </button>
                </form>
            </div>
        </div>
    @endunless

    {{-- Semicircular arc of images --}}
    <!-- <div class="pointer-events-none relative z-10 -mt-8 h-144 w-full sm:-mt-16 sm:h-176">
        @foreach ($images as $i => $image)
            @php
                // Spread images across a 180° arc (from 180° to 360°/0°)
                $angle = 180 + ($i * 180 / ($count - 1));
                $radiusX = 42; // vw units via inline style
                $radiusY = 38;
                $cx = 50;
                $cy = 0; // top of the container = center of the arc
                $rad = deg2rad($angle);
                $x = $cx + $radiusX * cos($rad);
                $y = $cy + $radiusY * sin($rad);

                // Size decreases toward the sides
                $centerIndex = ($count - 1) / 2;
                $distFromCenter = abs($i - $centerIndex) / $centerIndex;
                $sizeRem = 8 - $distFromCenter * 3; // 8rem center → 5rem edges
            @endphp
            <div
                class="absolute overflow-hidden rounded-2xl shadow-2xl"
                style="
                    left: {{ $x }}%;
                    top: {{ $y }}%;
                    width: {{ round($sizeRem, 2) }}rem;
                    height: {{ round($sizeRem, 2) }}rem;
                    transform: translate(-50%, 0);
                "
            >
                <img
                    src="{{ asset('history/' . $image) }}"
                    alt=""
                    class="h-full w-full object-cover"
                    draggable="false"
                >
            </div>
        @endforeach
    </div> -->
    
    
</section>
