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
        'avatar_glb_url'        => config('avatars.use_2d') ? null : ($lesson->avatar?->glbUrl() ?? null),
        'avatar_gender'         => strtolower($lesson->avatar?->gender ?? 'male'),
        'game_duration_seconds' => $lesson->strategyGame ? $lesson->strategyGame->duration_minutes * 60 : 600,
        'game_title'            => $lesson->strategyGame?->title,
        'game_instructions'     => $lesson->strategyGame?->instructions,
        'era'                   => $lesson->era,
        'region'                => $lesson->region,
        'include_game'          => (bool) $lesson->include_game,
        'game_type'             => $lesson->game_type,
        'quiz_timing'           => $lesson->quiz_timing,
        'cover_image_url'       => $lesson->cardImageUrl(),
        'intro_text'            => $lesson->outline['scene_briefs'][0]['scenePurpose']
                                    ?? $lesson->details
                                    ?? $lesson->topic,
        'scene_images'          => $lesson->relationLoaded('scenes')
            ? $lesson->scenes->where('image_path', '!=', null)->map(fn($s) => ['url' => \Illuminate\Support\Facades\Storage::disk('public')->url($s->image_path)])->values()
            : [],
        'scenes'                => $lesson->relationLoaded('scenes')
            ? $lesson->scenes->map(fn($s) => [
                'order'       => $s->order,
                'kind'        => $s->kind,
                'game_type'   => $s->game_type,
                'audio_url'   => $s->audio_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($s->audio_path) : null,
                'script'      => $s->script_segment,
                'image_url'   => $s->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($s->image_path) : null,
                'alignment'   => $s->audio_alignment ?: null,
                'duration_seconds' => $s->duration_seconds,
              ])->values()
            : [],
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
    id="lesson-stage" x-cloak
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
    {{-- 2D avatar: small portrait badge in the bottom-right corner. --}}
    @if (config('avatars.use_2d') && $lesson->avatar && ($avatarImg = $lesson->avatar->thumbnailUrl() ?? $lesson->avatar->portraitUrl()))
        <img src="{{ $avatarImg }}" alt="{{ $lesson->avatar->name }}"
             class="pointer-events-none absolute bottom-6 right-6 z-30 h-[150px] w-[150px] rounded-xl object-cover shadow-2xl ring-1 ring-white/15">
    @endif

    {{-- ── LAYER 3: UI overlay ──────────────────────────────────────────── --}}
    <div class="absolute inset-0 z-30 pointer-events-none">

        {{-- Logo — always visible, top-left, same size as app navbar --}}
        <div class="absolute top-4 left-4 sm:left-6 lg:left-8 pointer-events-none" style="z-index:50">
            <img src="{{ asset('assets/logo.svg') }}" alt="The Learning Portal" class="h-28 w-auto opacity-90">
        </div>

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

        {{-- ── TITLE SCREEN — Netflix style ────────────────────────────── --}}
        {{-- Background Ken Burns runs on z-0 layer behind this. --}}
        <div
            x-show="phase === 'TITLE_SCREEN'"
            x-transition:enter="transition ease-out duration-1000"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-500"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 pointer-events-auto"
        >
            {{-- Cinematic gradients: heavy vignette bottom + sides --}}
            <div class="absolute inset-0 pointer-events-none"
                 style="background:
                     linear-gradient(to top,  rgba(2,6,23,1) 0%, rgba(2,6,23,0.7) 30%, transparent 60%),
                     linear-gradient(to right, rgba(2,6,23,0.7) 0%, transparent 40%),
                     linear-gradient(to bottom, rgba(2,6,23,0.4) 0%, transparent 25%)">
            </div>

            {{-- Animated film grain — heavier than normal (0.12 opacity) --}}
            <div class="skybox-grain-overlay" style="opacity: 0.22; z-index: 2;"></div>

            {{-- Narrator label — bottom right, above the 3D avatar --}}
            @if($lesson->avatar?->name || $lesson->historical_figure)
                <div class="absolute bottom-10 right-8 sm:right-12 hidden sm:flex flex-col items-end gap-0.5 text-right"
                     style="z-index:10">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-400/70">Your narrator</p>
                    <p class="font-history text-2xl font-semibold text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.9)] leading-tight">
                        {{ $lesson->avatar?->name ?? $lesson->historical_figure }}
                    </p>
                    @if($lesson->avatar?->era || $lesson->era)
                        <p class="text-xs text-slate-400">{{ $lesson->avatar?->era ?? $lesson->era }}</p>
                    @endif
                </div>
            @endif

            {{-- QR code — top right, clickable to open modal --}}
            <button onclick="document.getElementById('qr-modal').showModal()"
                    class="absolute top-6 right-6 hidden sm:flex flex-col items-center gap-2 cursor-pointer group"
                    style="z-index:10; background:none; border:none; padding:0">
                <canvas id="title-qr-canvas"
                        class="rounded-xl opacity-90 transition group-hover:opacity-100 group-hover:scale-105"
                        style="image-rendering: pixelated;"></canvas>
                <p class="text-base font-mono font-bold tracking-[0.25em] text-white/80 uppercase"
                   x-text="lesson.lesson_code"></p>
                <p class="text-[10px] text-white/40 tracking-widest uppercase group-hover:text-white/60 transition">Scan to join</p>
            </button>

            {{-- QR modal --}}
            <dialog id="qr-modal" class="modal">
                <div class="modal-box bg-black border border-white/10 flex flex-col items-center gap-6 py-10 px-12 max-w-sm">
                    <canvas id="qr-modal-canvas"
                            class="rounded-2xl"
                            style="image-rendering: pixelated;"></canvas>
                    <p class="font-mono font-black tracking-[0.35em] text-white uppercase"
                       style="font-size: 2.5rem;"
                       x-text="lesson.lesson_code"></p>
                    <p class="text-xs text-white/40 tracking-widest uppercase">Scan to join the lesson</p>
                </div>
                <form method="dialog" class="modal-backdrop"><button>close</button></form>
            </dialog>

            {{-- Full-width bottom bar: text left, QR right --}}
            <div class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-8 px-8 pb-10 sm:px-12 sm:pb-14"
                 style="z-index: 10;">

                {{-- ── Left: title + meta + CTA ── --}}
                <div class="min-w-0 flex-1 max-w-2xl">

                    {{-- Era / region --}}
                    <p x-show="lesson.era || lesson.region"
                       x-text="[lesson.era, lesson.region].filter(Boolean).join(' · ').toUpperCase()"
                       class="mb-4 border-l-2 border-amber-400 pl-3 text-xs font-bold tracking-[0.18em] text-amber-400
                              drop-shadow-[0_1px_8px_rgba(0,0,0,1)]"></p>

                    {{-- Title --}}
                    <h1
                        x-html="lesson.title.includes(': ')
                            ? lesson.title.replace(/^(.*?):\s*(.+)$/, '<span style=\'font-weight:300\'>$1:</span> <span style=\'font-weight:700\'>$2</span>')
                            : lesson.title"
                        class="font-history text-white leading-[0.95] tracking-tight
                               drop-shadow-[0_2px_40px_rgba(0,0,0,1)]"
                        style="font-size: clamp(2.2rem, 6vw, 7rem);"
                    ></h1>

                    {{-- Intro text — hidden on very small screens --}}
                    <p x-show="lesson.intro_text"
                       x-text="lesson.intro_text"
                       class="mt-4 text-sm leading-relaxed text-slate-300/75 font-light hidden sm:block
                              drop-shadow-[0_1px_8px_rgba(0,0,0,0.9)]"></p>

                    {{-- Meta row --}}
                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span x-show="lesson.grade_level" x-text="lesson.grade_level"
                              class="text-xs font-light text-slate-500"></span>
                        <span x-show="lesson.grade_level && lesson.subject" class="text-slate-700 text-xs">·</span>
                        <span x-show="lesson.subject" x-text="lesson.subject"
                              class="text-xs font-light capitalize text-slate-500"></span>

                        <template x-if="lesson.include_game && lesson.game_type === 'quiz'">
                            <span class="flex items-center gap-1.5 rounded-full border border-sky-500/40 bg-sky-950/50 px-3 py-0.5 text-xs font-semibold text-sky-300 backdrop-blur-sm">
                                <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Quiz</span>
                                <span x-show="lesson.quiz_timing" x-text="'· ' + lesson.quiz_timing" class="text-sky-400/60"></span>
                            </span>
                        </template>
                        <template x-if="lesson.include_game && lesson.game_type === 'debate'">
                            <span class="flex items-center gap-1.5 rounded-full border border-violet-500/40 bg-violet-950/50 px-3 py-0.5 text-xs font-semibold text-violet-300 backdrop-blur-sm">
                                <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                Debate
                            </span>
                        </template>
                    </div>

                    {{-- CTA --}}
                    <div class="mt-6 sm:mt-8">
                        <template x-if="lesson.audio_url || (lesson.scenes && lesson.scenes.some(s => s.audio_url))">
                            <button
                                @click="startLesson()"
                                class="group flex items-center gap-3 rounded-full bg-amber-500 px-6 py-3 sm:px-8 sm:py-3.5
                                       text-sm sm:text-base font-bold text-slate-950
                                       shadow-[0_0_48px_rgba(245,158,11,0.35)]
                                       transition duration-150 hover:bg-amber-400 hover:shadow-[0_0_64px_rgba(245,158,11,0.5)]
                                       active:scale-95"
                            >
                                <svg class="h-5 w-5 fill-slate-950 shrink-0" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                <span>Start lesson</span>
                            </button>
                        </template>
                        <template x-if="!lesson.audio_url && !(lesson.scenes && lesson.scenes.some(s => s.audio_url))">
                            <div class="flex items-center gap-2 rounded-full border border-slate-600 px-6 py-3 text-sm text-slate-400">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                <span>Audio generating…</span>
                            </div>
                        </template>
                    </div>
                </div>

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
