<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="learningportal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $lesson->title }} — The Learning Portal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/lesson-player.js'])
</head>
<body class="h-full overflow-hidden bg-[#020617]">

{{-- ── Lesson data passed to JS ────────────────────────────────────────────── --}}
@php
    $lessonData = [
        'id'                    => $lesson->id,
        'title'                 => $lesson->title,
        'topic'                 => $lesson->topic,
        'subject'               => $lesson->subject,
        'grade_level'           => $lesson->grade_level,
        'historical_figure'     => $lesson->historical_figure,
        'script'                => $lesson->script,
        'duration_seconds'      => $lesson->duration_seconds,
        'audio_url'             => $lesson->audioUrl(),
        'visemes_url'           => $lesson->visemesUrl(),
        'portrait_url'          => $lesson->portraitUrl(),
        'slideshow_images'      => $lesson->slideshowImages(),
        'lesson_code'           => $lesson->lesson_code,
        'avatar_glb_url'        => $lesson->avatar?->glb_url ?? null,
        'game_duration_seconds' => $lesson->strategyGame ? $lesson->strategyGame->duration_minutes * 60 : 600,
        'game_title'            => $lesson->strategyGame?->title,
        'game_instructions'     => $lesson->strategyGame?->instructions,
        'intel_drop_enabled'    => $lesson->intel_drop_enabled,
        'intel_drop_at_seconds' => $lesson->intel_drop_enabled ? ($lesson->intel_drop_at_minutes * 60) : null,
        'intel_drop_message'    => $lesson->intel_drop_script,
        'intel_drop_audio_url'  => $lesson->intel_drop_audio_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($lesson->intel_drop_audio_path)
            : null,
    ];
@endphp
<script>
    window.LESSON = @json($lessonData);
</script>

{{-- ── Full-screen game stage ──────────────────────────────────────────────── --}}
<div
    id="lesson-stage"
    x-data="lessonGame(window.LESSON)"
    x-init="init()"
    class="relative w-screen h-screen overflow-hidden"
>

    {{-- ── LAYER 0: Background images (Ken Burns) ───────────────────────── --}}
    <div id="background-layer" class="absolute inset-0 z-0" aria-hidden="true">
        {{-- Populated by lesson-player.js --}}
    </div>

    {{-- ── LAYER 1: Shadow gradient overlay ────────────────────────────── --}}
    <div class="absolute inset-0 z-10 pointer-events-none bg-linear-to-b from-black/50 to-[#0C2033]/50"></div>

    {{-- ── LAYER 2: Three.js canvas (avatar) ───────────────────────────── --}}
    <canvas id="lesson-avatar-canvas" class="absolute inset-0 z-20 w-full h-full pointer-events-none"></canvas>

    {{-- ── LAYER 3: UI overlay ──────────────────────────────────────────── --}}
    <div class="absolute inset-0 z-30 pointer-events-none">

        {{-- Location + Year (top-left) — shown during INTRO phase --}}
        <div
            x-show="phase === 'INTRO' || phase === 'GAME_ACTIVE'"
            x-transition:enter="transition ease-out duration-700"
            x-transition:enter-start="opacity-0 -translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="absolute top-8 left-8 flex items-center gap-5 pointer-events-none"
        >
            {{-- Year inside Moon SVG --}}
            <div class="relative flex items-center justify-center" style="width:120px;height:123px;">
                <svg class="absolute inset-0" width="120" height="123" viewBox="0 0 235 240" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M67.019 231.899C97.6638 240.641 132.178 237.544 162.462 220.608C220.148 188.346 240.644 118.109 208.241 63.7269C175.837 9.34474 102.802 -8.58925 45.1148 23.671C25.6292 34.5687 10.3932 49.8048 0 67.2943C10.4394 46.5488 27.1619 28.4934 49.3482 16.0853C108.551 -17.024 184.242 2.61848 218.409 59.958C252.576 117.298 232.279 190.621 173.076 223.73C139.498 242.508 100.62 244.312 67.019 231.899Z" fill="white"/>
                </svg>
                <span
                    x-text="lessonYear"
                    :class="lessonYear && lessonYear.length > 4 ? 'text-3xl' : 'text-4xl'"
                    class="relative font-history font-bold text-[#E1EEF4] drop-shadow-[0_4px_4px_rgba(0,0,0,0.8)] leading-none z-10"
                ></span>
            </div>

            {{-- Location --}}
            <div class="flex items-center gap-2">
                <svg class="shrink-0 drop-shadow-[0_2px_4px_rgba(0,0,0,0.8)]" width="16" height="20" viewBox="0 0 21 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M10.3329 0C4.63543 0 0 4.63543 0 10.3329C0 19.3812 9.58334 25.4792 9.9913 25.735L10.334 25.9493L10.6767 25.735C11.0848 25.4795 20.668 19.3812 20.668 10.3329C20.668 4.63543 16.0326 0 10.3351 0H10.3329ZM10.3329 15.5C7.47996 15.5 5.16584 13.1871 5.16584 10.3329C5.16584 7.47996 7.47872 5.16584 10.3329 5.16584C13.1859 5.16584 15.5 7.47872 15.5 10.3329C15.5 13.1859 13.1871 15.5 10.3329 15.5Z" fill="white"/>
                </svg>
                <span
                    x-text="lessonLocation"
                    class="font-history font-bold text-lg text-[#E1EEF4] drop-shadow-[0_2px_4px_rgba(0,0,0,0.8)]"
                ></span>
            </div>
        </div>

        {{-- ── TEAM REVEAL screen ──────────────────────────────────────── --}}
        <div
            x-show="phase === 'TEAM_REVEAL'"
            x-transition:enter="transition ease-out duration-500"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="absolute inset-0 flex items-center justify-center pointer-events-auto"
        >
            <div class="w-full max-w-4xl px-8">
                <h2 class="font-history text-4xl font-bold text-center text-[#E1EEF4] mb-10 drop-shadow-[0_4px_8px_rgba(0,0,0,0.9)]">
                    Your Teams
                </h2>
                <div class="grid grid-cols-2 gap-6 md:grid-cols-3" id="team-list-grid">
                    {{-- Populated by JS from /api/lesson/{code}/teams --}}
                </div>
                <p class="text-center text-slate-400 text-sm mt-8 animate-pulse">
                    Game starts in <span x-text="teamRevealCountdown" class="text-amber-400 font-bold"></span>s…
                </p>
            </div>
        </div>

        {{-- ── GAME ACTIVE: sidebar team list + timer HUD ──────────────── --}}
        <div x-show="phase === 'GAME_ACTIVE' || phase === 'TIME_UP'" class="absolute inset-0 pointer-events-none">

            {{-- Timer HUD (top-right corner) --}}
            <div class="absolute top-6 right-6 flex items-center gap-2">
                <span
                    x-text="timerDisplay"
                    :class="timerSeconds <= 120 ? 'text-red-400' : timerSeconds <= 300 ? 'text-amber-400' : 'text-[#E1EEF4]'"
                    class="font-history text-5xl font-bold drop-shadow-[0_4px_8px_rgba(0,0,0,0.9)] tabular-nums"
                ></span>
            </div>

            {{-- Big timer (centre, first 5s of GAME_ACTIVE) --}}
            <div
                x-show="showBigTimer"
                x-transition:leave="transition ease-in duration-500"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-75"
                class="absolute inset-0 flex items-center justify-center"
            >
                <span
                    x-text="timerDisplay"
                    :class="timerSeconds <= 120 ? 'text-red-400' : 'text-amber-400'"
                    class="font-history font-bold drop-shadow-[0_8px_16px_rgba(0,0,0,0.9)] tabular-nums"
                    style="font-size: 20vw; line-height: 1;"
                ></span>
            </div>
        </div>

        {{-- ── TIME'S UP overlay ───────────────────────────────────────── --}}
        <div
            x-show="phase === 'TIME_UP'"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-110"
            x-transition:enter-end="opacity-100 scale-100"
            class="absolute inset-0 flex items-center justify-center bg-slate-950/70 pointer-events-auto"
        >
            <div class="text-center">
                <p class="font-history text-8xl font-bold text-amber-400 drop-shadow-[0_8px_16px_rgba(0,0,0,0.9)] animate-pulse">
                    TIME'S UP
                </p>
                <p class="text-slate-300 text-xl mt-4">Present your strategy to the class.</p>
            </div>
        </div>

        {{-- ── INTEL DROP notification ─────────────────────────────────── --}}
        <div
            x-show="phase === 'INTEL_DROP'"
            x-transition:enter="transition ease-out duration-500"
            x-transition:enter-start="opacity-0 translate-y-8"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="absolute bottom-16 left-1/2 -translate-x-1/2 w-full max-w-lg px-4 pointer-events-none"
        >
            <div class="rounded-2xl border border-amber-500/40 bg-slate-900/90 backdrop-blur-sm px-6 py-4 shadow-2xl">
                <p class="text-amber-400 text-sm font-semibold uppercase tracking-widest mb-1">New Intelligence</p>
                <p x-text="intelDropMessage" class="text-[#E1EEF4] text-base leading-relaxed"></p>
            </div>
        </div>

    </div>{{-- /z-30 UI overlay --}}

</div>{{-- /lesson-stage --}}

</body>
</html>
