<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="learningportal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $lesson->title }} — The Learning Portal</title>
    {{-- lesson-map.js (+ the ~1 MB MapLibre/volcanoes chunk) is loaded on demand by
         lesson-player.js only when a lesson actually contains a map scene. --}}
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
        'avatar_glb_url'        => null, // 3D avatar retired — avatars are a 2D image + ElevenLabs voice
        'avatar_gender'         => strtolower($lesson->avatar?->gender ?? 'male'),
        'narrator_welcome_url'      => $lesson->avatar?->welcomeVideoUrl(),
        'narrator_welcome_lite_url' => $lesson->avatar?->welcomeVideoLiteUrl(),
        'game_duration_seconds' => $lesson->strategyGame ? $lesson->strategyGame->duration_minutes * 60 : 600,
        'game_title'            => $lesson->strategyGame?->title,
        'game_instructions'     => $lesson->strategyGame?->instructions,
        'era'                   => $lesson->era,
        'region'                => $lesson->region,
        'include_game'          => (bool) $lesson->include_game,
        'game_type'             => $lesson->game_type,
        'quiz_timing'           => $lesson->quiz_timing,
        'cover_image_url'       => $lesson->titleBgUrl() ?? $lesson->cardImageUrl(),
        'title_bg_url'          => $lesson->titleBgUrl(),
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
                'config'      => $s->config,
                'game_type'   => $s->game_type,
                'scene_view'  => $s->scene_view,
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

    {{-- ── LAYER 0b: Title-screen background (Wikipedia lead image) ──────────
         Pinned during TITLE_SCREEN so the catalog topic's image is the hero backdrop,
         independent of the scene Ken Burns / 3D skybox engine. --}}
    <template x-if="lesson.title_bg_url">
        <div x-show="phase === 'TITLE_SCREEN'" x-transition.opacity
             class="absolute inset-0 z-[1]" aria-hidden="true">
            <div class="absolute inset-0 bg-cover bg-center"
                 :style="`background-image:url('${lesson.title_bg_url}')`"></div>
            {{-- Darken for title legibility --}}
            <div class="absolute inset-0 bg-black/55"></div>
            <span class="absolute bottom-2 right-3 text-[10px] text-white/40">{{ __('Image: Wikimedia Commons') }}</span>
        </div>
    </template>

    {{-- ── Map block slide — full-bleed historical atlas, shown while a map scene plays. --}}
    <div id="lesson-map-stage" class="absolute inset-0 z-20" style="display:none" aria-hidden="true"></div>
    {{-- Continue button for interactive map blocks (timed blocks auto-advance). --}}
    <button x-show="showMapContinue" x-transition
            @click="advanceMap()"
            class="absolute bottom-10 left-1/2 z-40 -translate-x-1/2 flex items-center gap-2 rounded-full
                   bg-amber-500 px-7 py-3 text-base font-bold text-slate-950 shadow-[0_0_48px_rgba(245,158,11,0.4)]
                   transition hover:bg-amber-400 active:scale-95 pointer-events-auto">
        {{ __('Continue') }}
        <svg class="h-5 w-5 fill-slate-950" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
    </button>

    {{-- ── Narrator welcome video ───────────────────────────────────────────
         Plays once, full-screen, right after "Start lesson" is clicked and before
         the first block. Dark-blue base + black vignette focuses the talking avatar;
         the player auto-advances to block 1 the moment it ends (see _endWelcomeVideo). --}}
    @if ($welcomeVideoUrl = $lesson->avatar?->welcomeVideoUrl())
        <div x-show="phase === 'WELCOME_VIDEO'" x-cloak
             class="absolute inset-0 z-[60] flex items-center justify-center pointer-events-auto"
             style="background:#0f172a;">

            {{-- Black vignette --}}
            <div class="pointer-events-none absolute inset-0"
                 style="background: radial-gradient(ellipse at center, transparent 30%, rgba(0,0,0,0.55) 72%, rgba(0,0,0,0.92) 100%);"></div>

            {{-- src is chosen at runtime (full vs lite) by _welcomeSrc() based on connection/screen. --}}
            <video x-ref="welcomeVideo" playsinline preload="auto"
                   class="relative z-[1] max-h-full max-w-full object-contain"></video>

            {{-- Skip — small, unobtrusive --}}
            <button @click="_endWelcomeVideo()"
                    class="absolute bottom-8 right-8 z-[2] flex items-center gap-2 rounded-full border border-white/20
                           bg-black/40 px-5 py-2 text-sm font-semibold text-white/80 backdrop-blur-sm
                           transition hover:bg-black/60 hover:text-white active:scale-95">
                {{ __('Skip') }}
                <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24"><path d="M4 5v14l8-7zM13 5v14l8-7z"/></svg>
            </button>
        </div>
    @endif

    {{-- ── LAYER 1: Shadow gradient overlay ────────────────────────────── --}}
    <div class="absolute inset-0 z-10 pointer-events-none bg-linear-to-b from-black/50 to-[#0C2033]/50"></div>

    {{-- Cinematic film-grain overlay (reuses the .lp-grain brand utility). --}}
    <div class="lp-grain pointer-events-none absolute inset-0 z-[11]"></div>

    {{-- ── LAYER 2: Three.js canvas (avatar) ───────────────────────────── --}}
    <canvas id="lesson-avatar-canvas" class="absolute inset-0 z-20 w-full h-full pointer-events-none"></canvas>
    {{-- 2D narrator portrait — small reminder badge during PLAYBACK only. The title screen has its
         own framed narrator card (below), so hide this there to avoid a dark, duplicate portrait. --}}
    @if ($lesson->avatar && ($avatarImg = $lesson->avatar->thumbnailUrl() ?? $lesson->avatar->portraitUrl()))
        <img src="{{ $avatarImg }}" alt="{{ $lesson->avatar->name }}"
             x-show="phase !== 'TITLE_SCREEN'" x-cloak
             class="pointer-events-none absolute bottom-6 right-6 z-30 h-[150px] w-[150px] rounded-xl object-cover shadow-2xl ring-1 ring-white/15">
    @endif

    {{-- ── LAYER 3: UI overlay ──────────────────────────────────────────── --}}
    <div class="absolute inset-0 z-30 pointer-events-none">

        {{-- Logo — always visible, top-left, same size as app navbar --}}
        <div class="absolute top-4 left-4 sm:left-6 lg:left-8 pointer-events-none" style="z-index:50">
            {{-- Dark halo keeps the light wordmark legible on bright map / Ken Burns slides. --}}
            <img src="{{ asset('assets/logo.svg') }}" alt="The Learning Portal" class="h-28 w-auto"
                 style="filter: drop-shadow(0 0 14px rgba(0,0,0,0.85)) drop-shadow(0 2px 4px rgba(0,0,0,0.65));">
        </div>

        {{-- Source attribution (A4) — bottom-left, unobtrusive --}}
        @if ($attribution = $lesson->sourceAttribution())
            <p class="absolute bottom-2 left-4 sm:left-6 lg:left-8 text-[10px] text-white/35 pointer-events-none"
               style="z-index:50">{{ $attribution }}</p>
        @endif

        {{-- ── Audio Controls (during playback) ──────────────────────── --}}
        <div
            x-show="phase === 'INTRO' || phase === 'GAME_ACTIVE' || phase === 'GAME_BRIEF'"
            x-transition
            class="absolute top-4 right-4 sm:right-6 lg:right-8 flex items-center gap-2 pointer-events-auto"
            style="z-index:50"
        >
            {{-- Play / Pause button --}}
            <button
                @click="toggleAudio()"
                :title="audioPlaying ? 'Pause (Space)' : 'Play (Space)'"
                class="flex h-9 w-9 items-center justify-center rounded-full border transition-all
                       focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 focus:ring-offset-slate-900
                       border-amber-500 bg-amber-500/10 hover:bg-amber-500/25 text-amber-400"
            >
                <svg x-show="!audioPlaying" class="h-4 w-4 fill-current" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                <svg x-show="audioPlaying" class="h-4 w-4 fill-current" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
            </button>

            {{-- Stop button --}}
            <button
                @click="stopAudio()"
                title="Stop (Esc)"
                class="flex h-9 w-9 items-center justify-center rounded-full border transition-all
                       focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 focus:ring-offset-slate-900
                       border-amber-500/60 bg-amber-500/5 hover:bg-amber-500/15 text-amber-400/80 hover:text-amber-400"
            >
                <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
            </button>

            {{-- Mute button --}}
            <button
                @click="toggleMute()"
                :title="audioMuted ? 'Unmute (M)' : 'Mute (M)'"
                class="flex h-9 w-9 items-center justify-center rounded-full border transition-all
                       focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 focus:ring-offset-slate-900"
                :class="audioMuted
                    ? 'border-slate-600/60 bg-slate-700/10 text-slate-400/80'
                    : 'border-amber-500/60 bg-amber-500/5 text-amber-400/80 hover:text-amber-400'"
            >
                <svg x-show="!audioMuted" class="h-4 w-4 fill-current" viewBox="0 0 24 24"><path d="M13.5 4.06c0-1.336-1.616-2.256-2.73-1.72l-5.24 2.97H5c-1.1 0-2 .9-2 2v6c0 1.1.9 2 2 2h.51l5.24 2.97c1.11.536 2.73-.384 2.73-1.72v-13zm3.67 3.88a1 1 0 1 0-1.33 1.49 6 6 0 0 1 0 7.06 1 1 0 1 0 1.33 1.49 8 8 0 0 0 0-9.54zm2.05-3.55a1 1 0 0 0-1.41 1.41A10 10 0 0 1 19.55 12a10 10 0 0 1-2.75 6.95 1 1 0 1 0 1.41 1.41A12 12 0 0 0 21.55 12a12 12 0 0 0-3.33-8.67z"/></svg>
                <svg x-show="audioMuted" class="h-4 w-4 fill-current" viewBox="0 0 24 24"><path d="M16.6915026,12.4744748 L21.5908951,7.5751461 L20.1876905,6.1719415 L15.2883018,11.0712702 L10.3909581,6.16346227 L8.9863711,7.5680493 L13.8837627,12.4744748 L9.01075265,17.338484 L10.4149653,18.7426997 L15.2883018,13.8693631 L20.1669881,18.7480694 L21.5711749,17.3438636 L16.6915026,12.4744748 Z"/></svg>
            </button>
        </div>

        {{-- Location + era — bottom-left, shown during INTRO / GAME_ACTIVE (no moon) --}}
        <div
            x-show="phase === 'INTRO' || phase === 'GAME_ACTIVE'"
            x-transition:enter="transition ease-out duration-700"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="absolute bottom-8 left-4 sm:left-6 lg:left-8 flex flex-col gap-1.5 pointer-events-none"
            style="z-index:45"
        >
            {{-- Location --}}
            <div class="flex items-center gap-2">
                <svg class="shrink-0 drop-shadow-[0_2px_6px_rgba(0,0,0,0.9)]" width="16" height="20" viewBox="0 0 21 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M10.3329 0C4.63543 0 0 4.63543 0 10.3329C0 19.3812 9.58334 25.4792 9.9913 25.735L10.334 25.9493L10.6767 25.735C11.0848 25.4795 20.668 19.3812 20.668 10.3329C20.668 4.63543 16.0326 0 10.3351 0H10.3329ZM10.3329 15.5C7.47996 15.5 5.16584 13.1871 5.16584 10.3329C5.16584 7.47996 7.47872 5.16584 10.3329 5.16584C13.1859 5.16584 15.5 7.47872 15.5 10.3329C15.5 13.1859 13.1871 15.5 10.3329 15.5Z" fill="white"/>
                </svg>
                <span x-text="lessonLocation" class="font-history font-bold text-xl text-[#E1EEF4] drop-shadow-[0_2px_6px_rgba(0,0,0,0.9)]"></span>
            </div>

            {{-- Era / year --}}
            <span x-show="lessonYear" x-text="lessonYear" class="pl-6 font-history font-semibold text-base text-[#E1EEF4]/90 drop-shadow-[0_2px_6px_rgba(0,0,0,0.9)]"></span>
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

            {{-- Narrator card — framed + brightened portrait beside the label/name/era. Avatar
                 thumbnails are often dark, so a warm ring + shadow + brightness lifts it off the
                 dark cover so it reads as "on the forefront" (not a dark blob). --}}
            @php $narratorImg = $lesson->avatar?->thumbnailUrl() ?? $lesson->avatar?->portraitUrl(); @endphp
            @if($lesson->avatar?->name || $lesson->historical_figure)
                <div class="absolute bottom-10 right-8 sm:right-12 hidden sm:flex items-center gap-4" style="z-index:20">
                    <div class="flex flex-col items-end gap-0.5 text-right">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-400/80">Narrated by</p>
                        <p class="font-history text-2xl font-semibold leading-tight text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.9)]">
                            {{ $lesson->avatar?->name ?? $lesson->historical_figure }}
                        </p>
                        {{-- The narrator's ROLE (e.g. "Historian"), not the lesson era — the narrator is a
                             timeless guide, not a figure from the lesson's period. Falls back to era. --}}
                        @php $narratorRole = $lesson->avatar?->avatar_title ?: ($lesson->avatar?->era ?? $lesson->era); @endphp
                        @if($narratorRole)
                            <p class="text-xs text-slate-300/80">{{ $narratorRole }}</p>
                        @endif
                    </div>
                    @if($narratorImg)
                        <img src="{{ $narratorImg }}" alt="{{ $lesson->avatar?->name }}"
                             class="h-28 w-28 shrink-0 rounded-2xl object-cover shadow-[0_10px_34px_rgba(0,0,0,0.6)] ring-2 ring-amber-400/50"
                             style="filter: brightness(1.18) contrast(1.06) saturate(1.05);">
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
                        <template x-if="lesson.audio_url || (lesson.scenes && lesson.scenes.some(s => s.audio_url || s.kind === 'map'))">
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
                        <template x-if="!lesson.audio_url && !(lesson.scenes && lesson.scenes.some(s => s.audio_url || s.kind === 'map'))">
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

        {{-- ── GAME BRIEF: the story-aligned challenge ─────────────────── --}}
        <div
            x-show="phase === 'GAME_BRIEF'"
            x-transition:enter="transition ease-out duration-500"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="absolute inset-0 flex items-center justify-center bg-slate-950/92 backdrop-blur-md pointer-events-auto px-5 py-8 overflow-y-auto"
        >
            <div class="w-full max-w-2xl rounded-3xl border border-amber-500/40 bg-slate-950/95 p-7 sm:p-9 shadow-2xl">
                <p class="text-amber-400 text-[11px] font-semibold uppercase tracking-[0.3em] mb-3">Your Challenge</p>
                <h2 x-text="lesson.game_title || 'Strategy Challenge'"
                    class="font-history text-2xl sm:text-4xl font-bold text-[#E1EEF4] leading-tight mb-5 drop-shadow-[0_2px_8px_rgba(0,0,0,0.9)]"></h2>
                <div x-text="lesson.game_instructions || 'Work in your teams to decide your strategy, then present it to the class.'"
                     class="whitespace-pre-line text-slate-300 text-sm sm:text-base leading-relaxed max-h-[42vh] overflow-y-auto pr-1"></div>
                <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-400">
                    <span x-show="lesson.team_count" class="flex items-center gap-1.5">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                        <span x-text="lesson.team_count + ' teams'"></span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-text="Math.round((lesson.game_duration_seconds || 600) / 60) + ' minutes'"></span>
                    </span>
                </div>
                <button @click="beginGame()"
                    class="mt-7 flex w-full items-center justify-center gap-3 rounded-full bg-amber-500 px-8 py-3.5 text-base font-bold text-slate-950 shadow-[0_0_48px_rgba(245,158,11,0.35)] transition hover:bg-amber-400 active:scale-95 sm:w-auto">
                    <svg class="h-5 w-5 fill-slate-950" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    <span>Begin the challenge</span>
                </button>
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
                <button @click="resumeAfterGame()" x-show="canResumeAfterGame"
                    class="mt-8 inline-flex items-center gap-2 rounded-full border border-amber-500/50 bg-amber-500/10 px-6 py-3 text-sm font-semibold text-amber-300 transition hover:bg-amber-500/20 active:scale-95">
                    <span>Continue the lesson</span>
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
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
