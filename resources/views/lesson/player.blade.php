<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="learningportal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        'map_style'             => $lesson->map_style, // lesson-wide default palette for map blocks
        'map_relief'            => $lesson->map_relief, // 3D terrain exaggeration; 0 = flat

        // Does this lesson start with the narration written along the bottom? The teacher sets the
        // starting state; the student can still toggle it (C) while it plays.
        'subtitles'             => (bool) $lesson->subtitles,
        'include_game'          => (bool) $lesson->include_game,
        'game_type'             => $lesson->game_type,
        'game_config'           => $lesson->game_config,
        // DELIBERATE: correct_index ships to the client — grading is client-side by design
        // (low-stakes in-class quiz; mitigations = server score clamp + integrity telemetry
        // + teacher-eyes-only flags in QuizLeaderboardController). Server-side grading is
        // the v2 path if stakes rise.
        'quiz_questions'        => $lesson->quizQuestions->whereNull('scene_id')->map->only(['question', 'options', 'correct_index', 'asks_ahead', 'explanation'])->values(),
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
                'id'          => $s->id,   // lets the teacher-preview "Edit scene" deep-link to this exact scene
                'order'       => $s->order,
                'kind'        => $s->kind,
                'config'      => $s->config,
                'chapter_name' => $s->chapterName(),
                'year'        => $s->year,
                'location'    => $s->location,
                'branch_group'        => $s->branch_group,
                'branch_role'         => $s->branch_role,
                'branch_choice_label' => $s->branch_choice_label,
                'game_type'   => $s->game_type,
                'scene_view'  => $s->scene_view,
                'audio_url'   => $s->audio_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($s->audio_path) : null,
                'script'      => $s->script_segment,
                'image_url'   => $s->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($s->image_path) : null,
                'shots'       => collect($s->shots ?? [])->map(fn($shot) => [
                    'image_url'       => !empty($shot['image_path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($shot['image_path']) : null,
                    // bg_url/hero_url (E3b story-pack shots): the player renders these as
                    // parallax layers when bg_url is present, flat image_url otherwise.
                    'bg_url'          => !empty($shot['bg_path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($shot['bg_path']) : null,
                    'hero_url'        => !empty($shot['hero_path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($shot['hero_path']) : null,
                    // Multiplane layers (E3c): [{path|url, depth, kind, scale, height, sway}] back→front.
                    'layers'          => collect($shot['layers'] ?? [])->map(fn($l) => [
                        'url'    => !empty($l['path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($l['path']) : ($l['url'] ?? null),
                        // asset_id + x/y drive the free-positioned clipart overlay (voyage-map layers).
                        'asset_id' => isset($l['asset_id']) ? (int) $l['asset_id'] : null,
                        'x'      => isset($l['x']) ? (float) $l['x'] : null,
                        'y'      => isset($l['y']) ? (float) $l['y'] : null,
                        'depth'  => (float) ($l['depth'] ?? 1),
                        'kind'   => in_array($l['kind'] ?? 'cover', ['cover', 'figure', 'strip'], true) ? ($l['kind'] ?? 'cover') : 'cover',
                        'scale'  => (float) ($l['scale'] ?? 1),
                        'height' => isset($l['height']) ? (float) $l['height'] : null,
                        'sway'   => (bool) ($l['sway'] ?? false),
                        // Artistic controls (E3c-b): blur px, opacity 0-1, blend mode, wobble 0-2, z override.
                        'blur'    => isset($l['blur']) ? (float) $l['blur'] : null,
                        'opacity' => isset($l['opacity']) ? (float) $l['opacity'] : null,
                        'blend'   => in_array($l['blend'] ?? null, ['multiply', 'screen', 'overlay', 'darken', 'lighten'], true) ? $l['blend'] : null,
                        'wobble'  => isset($l['wobble']) ? (int) $l['wobble'] : null,
                        'z'       => isset($l['z']) ? (int) $l['z'] : null,
                        // 3D / video layers ride as an iframe embed instead of an image.
                        'embed'   => isset($l['embed']) && is_array($l['embed']) ? $l['embed'] : null,
                    ])->filter(fn($l) => $l['url'] || $l['embed'])->values()->all() ?: null,
                    'anchor_sentence' => $shot['anchor_sentence'] ?? null,
                    // Keep a shot with EITHER a background image OR clipart layers — a voyage scene's
                    // shot is layer-only (the map is the backdrop), so it has no image_url.
                ])->filter(fn($shot) => $shot['image_url'] || !empty($shot['layers']))->values()->all() ?: null,
                'alignment'   => $s->audio_alignment ?: null,
                'duration_seconds' => $s->duration_seconds,
                'background_color' => $s->background_color,
                // Same deliberate trade-off as lesson-level quiz_questions above:
                // correct_index is client-visible; grading stays client-side for v1.
                'quiz_questions' => $s->kind === 'game'
                    ? ($lesson->quizQuestions->where('scene_id', $s->id)->values()->whenEmpty(fn () => $lesson->quizQuestions->whereNull('scene_id')->values()))
                        ->map->only(['question', 'options', 'correct_index', 'asks_ahead', 'explanation'])->values()
                    : null,
                'quiz_shuffle' => $s->kind === 'game' ? (($s->config ?? [])['quiz_shuffle'] ?? 'per_player') : null,
                'kb_animated' => (bool) ($s->kb_animated ?? true),
                'kb_direction' => $s->kb_direction,
              ])->values()
            : [],
        'intel_drop_enabled'    => $lesson->intel_drop_enabled,
        'intel_drop_at_seconds' => $lesson->intel_drop_enabled ? ($lesson->intel_drop_at_minutes * 60) : null,
        'intel_drop_message'    => $lesson->intel_drop_script,
        'intel_drop_audio_url'  => $lesson->intel_drop_audio_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($lesson->intel_drop_audio_path)
            : null,
        'leaderboard_url'       => route('lesson.leaderboard', ['lessonCode' => $lesson->lesson_code]),
        'quiz_score_url'        => route('lesson.quiz-score', ['lessonCode' => $lesson->lesson_code]),
        'has_classroom'         => $lesson->classrooms()->exists(),
    ];
@endphp
{{-- The lesson payload is the heaviest thing on this page (mostly per-character narration timings:
     ~740 KB on a six-scene lesson). Blade's bare @json also escapes every quote, apostrophe and
     ampersand as \u00XX, which inflates it ~2.5x for nothing: inside a <script> block the only
     sequence that can break out is "</script", so escaping < and > (JSON_HEX_TAG) is the part that
     actually matters for safety. Keeping HEX_TAG and dropping the rest cuts the page by ~450 KB
     with no change in behaviour. --}}
<script>
    window.LESSON = @json($lessonData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    {{-- Teacher text annotations per scene (links open an iframe modal). z-31: above the map stage
         (z-20) so text a teacher placed on a voyage/history map is visible, below the quiz overlay. --}}
    <div id="lesson-text-overlay" class="absolute inset-0 z-31 pointer-events-none"></div>
    {{-- Teacher clipart layers a scene carries ON TOP of the voyage map. z-30 (below text) by default,
         raised to z-32 when the teacher stacked clipart above text — mirrors the editor's ordering.
         Read-only in playback (pointer-events:none) so it never steals a map pan. --}}
    <div id="lesson-voyage-art" class="absolute inset-0 z-30 pointer-events-none" style="display:none"></div>

    {{-- Quiz question cards (QuizOverlay mounts here during quiz segments). --}}
    <div id="lesson-game-overlay" class="absolute inset-0 z-30 pointer-events-none"></div>

    {{-- ── LAYER 0b: Title-screen background (Wikipedia lead image) ──────────
         Pinned during TITLE_SCREEN so the catalog topic's image is the hero backdrop,
         independent of the scene Ken Burns / 3D skybox engine. --}}
    <template x-if="lesson.title_bg_url">
        <div x-show="phase === 'TITLE_SCREEN'" x-transition.opacity
             class="absolute inset-0 z-1" aria-hidden="true">
            <div class="absolute inset-0 bg-cover bg-center"
                 :style="`background-image:url('${lesson.title_bg_url}')`"></div>
            {{-- Darken for title legibility --}}
            <div class="absolute inset-0 bg-black/55"></div>
            <span class="absolute bottom-2 right-3 text-[10px] text-white/40">{{ __('Image: Wikimedia Commons') }}</span>
        </div>
    </template>

    {{-- ── Map block slide — full-bleed historical atlas, shown while a map scene plays. --}}
    <div id="lesson-map-stage" class="absolute inset-0 z-20" style="display:none" aria-hidden="true"></div>
    {{-- Continue button for interactive map blocks (timed blocks auto-advance). Voyage lessons
         advance with the → / B keys (arrows-only nav), so the big button is hidden there. --}}
    <button x-show="showMapContinue && lesson.game_type !== 'voyage'" x-transition
            @click="advanceMap()"
            class="absolute bottom-10 left-1/2 z-40 -translate-x-1/2 flex items-center gap-2 rounded-full
                   bg-amber-500 px-7 py-3 text-base font-bold text-slate-950 shadow-[0_0_48px_rgba(245,158,11,0.4)]
                   transition hover:bg-amber-400 active:scale-95 pointer-events-auto">
        {{ __('Continue') }}
        <svg class="h-5 w-5 fill-slate-950" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
    </button>

    {{-- Voyage carousel: prev/next arrows on the sides + a minimal auto-advance progress line.
         Shown at the landfall (showMapContinue); the countdown auto-advances after 10s. --}}
    <template x-if="lesson.game_type === 'voyage'">
        <div>
            <button x-show="showMapContinue && _sceneIndex > 0" x-transition @click="previousSlide()"
                    aria-label="{{ __('Previous') }}"
                    class="fixed left-3 top-1/2 z-40 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white/80 backdrop-blur transition hover:bg-black/60 hover:text-white pointer-events-auto">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button x-show="showMapContinue" x-transition @click="advanceMap()"
                    aria-label="{{ __('Next') }}"
                    class="fixed right-3 top-1/2 z-40 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white/80 backdrop-blur transition hover:bg-black/60 hover:text-white pointer-events-auto">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
            <div x-show="showMapContinue" class="fixed inset-x-0 bottom-0 z-40 h-1 bg-white/10 pointer-events-none">
                <div class="h-full bg-amber-400/90" :style="`width: ${autoAdvanceProgress * 100}%`"></div>
            </div>
        </div>
    </template>

    {{-- ── Narrator welcome video ───────────────────────────────────────────
         Plays once, full-screen, right after "Start lesson" is clicked and before
         the first block. Dark-blue base + black vignette focuses the talking avatar;
         the player auto-advances to block 1 the moment it ends (see _endWelcomeVideo). --}}
    @if ($welcomeVideoUrl = $lesson->avatar?->welcomeVideoUrl())
        <div x-show="phase === 'WELCOME_VIDEO'" x-cloak
             class="absolute inset-0 z-60 flex items-center justify-center pointer-events-auto"
             style="background:#0f172a;">

            {{-- Black vignette --}}
            <div class="pointer-events-none absolute inset-0"
                 style="background: radial-gradient(ellipse at center, transparent 30%, rgba(0,0,0,0.55) 72%, rgba(0,0,0,0.92) 100%);"></div>

            {{-- src is chosen at runtime (full vs lite) by _welcomeSrc() based on connection/screen. --}}
            <video x-ref="welcomeVideo" playsinline preload="auto"
                   class="relative z-1 max-h-full max-w-full object-contain"></video>

            {{-- Skip — small, unobtrusive --}}
            <button @click="_endWelcomeVideo()"
                    class="absolute bottom-8 right-8 z-2 flex items-center gap-2 rounded-full border border-white/20
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
    <div class="lp-grain pointer-events-none absolute inset-0 z-11"></div>

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

        @php $canEdit = (bool) auth()->user()?->canManage($lesson); @endphp

        {{-- Top chrome (logo / editor escape hatch) lives in the player overlay wrapper below,
             so it appears and hides with the rest of the controls. --}}

        {{-- Source attribution (A4) — centred under the deck, unobtrusive, always visible. --}}
        @if ($attribution = $lesson->sourceAttribution())
            <p class="absolute bottom-1 left-1/2 -translate-x-1/2 text-[10px] text-white/35 pointer-events-none whitespace-nowrap"
               style="z-index:40">{{ $attribution }}</p>
        @endif

        {{-- ── Subtitles — the narration written along the bottom while it is spoken ─────────── --}}
        {{-- Sits above the chapter bar, never over it. x-text (never x-html): a script is teacher
             copy and must not be able to inject markup into the student's player. --}}
        <div
            {{-- Same reason as the menu: an x-transition here left the (empty) bubble on screen
                 after the captions were switched off, because the leave never applied. --}}
            x-show="captionsOn && captionText && (phase === 'INTRO' || phase === 'GAME_ACTIVE')"
            x-cloak
            class="pointer-events-none absolute bottom-28 left-1/2 w-[min(56rem,90vw)] -translate-x-1/2 text-center"
            style="z-index:47"
            aria-live="polite"
        >
            <p x-text="captionText"
               class="inline-block rounded-lg bg-black/70 px-4 py-2 text-balance text-lg leading-snug text-white shadow-lg backdrop-blur-sm sm:text-xl"></p>
        </div>

        {{-- The chapter name + year moved into the overlay wrapper below as one quiet line. --}}

        {{-- ── Player overlay (Figma: VideoPlayer playing/paused) — less is more, icons only.
             Every piece of chrome lives in this wrapper and is shown ONLY while paused, while a
             menu is open, or while the pointer sits over the top/bottom edge of the screen
             (plus a short flash after any tap or keypress so touch users can summon it).
             While narration plays and the pointer is elsewhere, the stage is completely clean.
             The visibility expression is repeated on each block because an x-data getter cannot
             reach the parent player's state through `this` — inline expressions can. --}}
        <div class="contents"
             x-data="{
                 zoneHover: false,
                 zoneFlash: false,
                 _zoneFlashT: null,
                 probeZones(e) {
                     const z = e.clientY <= 88 || e.clientY >= window.innerHeight - 176
                     if (z !== this.zoneHover) this.zoneHover = z
                 },
                 flashUi() {
                     this.zoneFlash = true
                     clearTimeout(this._zoneFlashT)
                     this._zoneFlashT = setTimeout(() => { this.zoneFlash = false }, 2600)
                 },
             }"
             @pointermove.window="probeZones($event)"
             @pointerdown.window="flashUi()"
             @keydown.window="flashUi()">

            {{-- Top edge: scrim + the only text chrome that exists (teacher escape hatch or logo).
                 No phase gate — on the title screen nothing is playing, so it is simply visible. --}}
            <div class="absolute inset-x-0 top-0 transition-opacity duration-300"
                 :class="(readingOverlay || (isPlaying && !zoneHover && !zoneFlash && !chaptersOpen)) ? 'opacity-0 pointer-events-none' : 'opacity-100'"
                 style="z-index:48">
                <div class="pointer-events-none absolute inset-x-0 top-0 h-36 bg-linear-to-b from-black/70 to-transparent"></div>
                <div class="relative flex items-center gap-2.5 p-4 sm:px-12">
                    @if ($canEdit)
                        <a href="{{ route('teacher.lessons.wizard', $lesson) }}" target="_top"
                           data-tooltip="{{ __('Back to editor') }}" aria-label="{{ __('Back to editor') }}"
                           class="pointer-events-auto flex h-9 w-9 items-center justify-center rounded-full border border-white/15 bg-slate-900/70 text-white/80 backdrop-blur transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                            </svg>
                        </a>
                        <button type="button"
                                @click="window.location.href = editSceneHref('{{ route('teacher.lessons.wizard', $lesson) }}')"
                                class="pointer-events-auto flex items-center gap-2 rounded-full border border-white/15 bg-slate-900/70 py-1.5 pl-3 pr-4 text-sm font-medium text-slate-300 backdrop-blur transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                            {{ __('Edit scene') }}
                        </button>
                    @else
                        <img src="{{ asset('assets/logo.svg') }}" alt="The Learning Portal" class="h-24 w-auto shadow-sm">
                    @endif
                </div>
            </div>

            {{-- Center play/pause — a bare solid white glyph, exactly as in the design (no disc,
                 no chrome). The button box is bigger than the glyph for a comfortable hit target.
                 Hidden on quiz scenes, where the centre belongs to the quiz card. Every other scene
                 has something to pause: narration, or a voyage's sailing ship. --}}
            <div x-show="(phase === 'INTRO' || phase === 'GAME_ACTIVE' || phase === 'GAME_BRIEF') && !currentIsGame"
                 x-cloak
                 class="pointer-events-none absolute inset-0 flex items-center justify-center transition-opacity duration-300"
                 :class="(readingOverlay || (isPlaying && !zoneHover && !zoneFlash && !chaptersOpen)) ? 'opacity-0' : 'opacity-100'"
                 style="z-index:45">
                <button type="button" @click="togglePlayback()"
                        :data-tooltip="playbackPaused ? @js(__('Play')) : @js(__('Pause'))" data-tooltip-key="K"
                        :aria-label="playbackPaused ? @js(__('Play')) : @js(__('Pause'))"
                        class="group flex h-24 w-24 items-center justify-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                        :class="(readingOverlay || (isPlaying && !zoneHover && !zoneFlash && !chaptersOpen)) ? 'pointer-events-none' : 'pointer-events-auto'">

                    <svg x-show="playbackPaused" class="h-14 w-14 text-white drop-shadow-[0_4px_14px_rgba(0,0,0,0.8)] transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95" viewBox="0 0 21 23" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M0 2.52873C0 0.608107 2.05916 -0.609418 3.74205 0.316169L19.2842 8.86436C21.0285 9.82373 21.0285 12.3301 19.2842 13.2895L3.74205 21.8377C2.05916 22.7633 0 21.5457 0 19.6251V2.52873Z" fill="white"/>
                    </svg>

                    <svg x-show="!playbackPaused" x-cloak class="h-14 w-14 text-white drop-shadow-[0_4px_14px_rgba(0,0,0,0.8)] transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                         viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3.40759 3.79631C3.40759 1.87569 5.46676 0.65816 7.14964 1.58375L22.6918 10.1319C24.4361 11.0913 24.4361 13.5977 22.6918 14.5571L7.14964 23.1053C5.46675 24.0308 3.40759 22.8133 3.40759 20.8927V3.79631Z"/>
                    </svg>
                </button>
            </div>

            {{-- Bottom scrim keeps the white icons and captions readable over bright scenes. --}}
            <div x-show="phase === 'INTRO' || phase === 'GAME_ACTIVE' || phase === 'GAME_BRIEF'"
                 x-cloak
                 class="pointer-events-none absolute inset-x-0 bottom-0 h-44 bg-linear-to-t from-black/80 via-black/40 to-transparent transition-opacity duration-300"
                 :class="(readingOverlay || (isPlaying && !zoneHover && !zoneFlash && !chaptersOpen)) && 'opacity-0'"
                 style="z-index:44"></div>

            <div x-show="phase === 'INTRO' || phase === 'GAME_ACTIVE' || phase === 'GAME_BRIEF'"
                 x-cloak
                 class="absolute inset-x-0 bottom-0 transition-all duration-300 ease-out"
                 :class="(readingOverlay || (isPlaying && !zoneHover && !zoneFlash && !chaptersOpen)) ? 'pointer-events-none translate-y-2 opacity-0' : 'opacity-100'"
                 style="z-index:46">
                <div class="pointer-events-auto mx-auto w-[min(720px,92vw)] pb-3">

                    {{-- Chapter list (opens above the deck) — numbered names, active highlighted. --}}
                    <div x-show="chaptersOpen" x-transition
                         @click.outside="chaptersOpen = false"
                         class="mb-3 max-h-[46vh] overflow-y-auto rounded-xl border border-white/10 bg-black/70 p-1.5 backdrop-blur-md">
                        <template x-for="(c, i) in chapters" :key="c.index">
                            <button type="button" @click="goToChapter(c.index)"
                                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition hover:bg-white/10"
                                    :class="c.index === _sceneIndex ? 'text-amber-300' : 'text-white/70'">
                                <span class="w-6 shrink-0 font-mono text-xs tabular-nums opacity-70" x-text="String(i + 1).padStart(2, '0')"></span>
                                <span class="text-sm" :class="c.index === _sceneIndex ? 'font-bold' : 'font-medium'" x-text="c.name"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Chapter line (Figma: "Bottom Info Chapter title") — one quiet line: location
                         pin, place in white, a dot, then the date in muted slate.

                         It lives INSIDE the deck so it has somewhere to go when the stage is narrow.
                         The design puts it in the bottom-left corner, but the deck grows to 92vw on a
                         small screen, and its play/pause button then lands on top of the title. So it
                         only floats down to the corner (.lp-chapter-line in app.css) when the stage is
                         wide enough for the two never to meet; below that it sits here, in flow, just
                         above the chapter bar.

                         This is the ONLY place the line is drawn. A voyage leg used to paint its own
                         copy inside the map HUD, which both doubled up with this one and stayed on
                         screen while the lesson played; the tour now reports its place + date
                         (lesson:chapter-info) and this renders them. Everything else falls back to
                         the scene's chapter name and year. Hidden entirely when the teacher chose the
                         top date chip instead. --}}
                    <div x-show="(phase === 'INTRO' || phase === 'GAME_ACTIVE') && !infoAtTop"
                         x-cloak
                         class="lp-chapter-line flex h-9 items-center gap-2">
                        <svg class="h-4 w-auto shrink-0 text-white drop-shadow-[0_2px_6px_rgba(0,0,0,0.9)]"
                             viewBox="0 0 21 26" fill="currentColor" aria-hidden="true">
                            <path d="M10.3329 0C4.63543 0 0 4.63543 0 10.3329C0 19.3812 9.58334 25.4792 9.9913 25.735L10.334 25.9493L10.6767 25.735C11.0848 25.4795 20.668 19.3812 20.668 10.3329C20.668 4.63543 16.0326 0 10.3351 0H10.3329ZM10.3329 15.5C7.47996 15.5 5.16584 13.1871 5.16584 10.3329C5.16584 7.47996 7.47872 5.16584 10.3329 5.16584C13.1859 5.16584 15.5 7.47872 15.5 10.3329C15.5 13.1859 13.1871 15.5 10.3329 15.5Z"/>
                        </svg>
                        <span class="truncate text-sm font-bold text-white drop-shadow-[0_2px_6px_rgba(0,0,0,0.9)]"
                              x-text="infoPlace || currentChapterName || lessonLocation"></span>
                        <span x-show="infoDate || lessonYear" class="h-1 w-1 shrink-0 rounded-full bg-slate-400"></span>
                        <span x-show="infoDate || lessonYear"
                              class="shrink-0 text-sm font-medium text-slate-400 drop-shadow-[0_2px_6px_rgba(0,0,0,0.9)]"
                              x-text="infoDate || lessonYear"></span>
                    </div>

                    {{-- Segmented progress — one bar per chapter, width ∝ duration; click to jump.
                         The track thickens slightly under the pointer, Netflix-style. --}}
                    <div x-show="chapters.length > 1 && !currentIsGame && phase !== 'GAME_BRIEF'"
                         class="flex items-center gap-1">
                        <template x-for="(c, i) in chapters" :key="c.index">
                            <button type="button" @click="goToChapter(c.index)" :data-tooltip="c.name"
                                    class="group relative flex items-center py-2" style="flex-grow: 1; flex-basis: 0"
                                    :style="`flex-grow:${c.dur || 1}`">
                                <span class="relative block h-1 w-full overflow-hidden rounded-full bg-white/25 transition-all duration-150 group-hover:h-1.5 group-hover:bg-white/40">
                                    {{-- White fill, not amber (Figma): the progress reads as light on the dark stage. --}}
                                    <span class="absolute inset-y-0 left-0 rounded-full bg-white/90 transition-all duration-150"
                                          :style="`width:${ c.index < _sceneIndex ? 100 : (c.index === _sceneIndex ? sceneProgress * 100 : 0) }%`"></span>
                                </span>
                            </button>
                        </template>
                    </div>

                    {{-- Transport row — plain white Heroicons (outline, 1.5), no chrome: playback on
                         the left, subtitles + chapters on the right. Icons grow a touch on hover. --}}
                    <div class="mt-0.5 flex items-center justify-between">
                        <div class="flex items-center gap-1 sm:gap-2">
                            {{-- Play / pause — the same control as the centre glyph and Space. --}}
                            <button type="button" @click="togglePlayback()"
                                    :data-tooltip="playbackPaused ? @js(__('Play')) : @js(__('Pause'))" data-tooltip-key="K"
                                    :aria-label="playbackPaused ? @js(__('Play')) : @js(__('Pause'))"
                                    class="group flex h-11 w-11 items-center justify-center rounded-lg text-white transition focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                                <svg x-show="playbackPaused" class="h-8 w-8 shadow-sm transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                                     viewBox="0 0 24 24" fill="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/>
                                </svg>
                                <svg x-show="!playbackPaused" x-cloak class="h-8 w-8 shadow-sm transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/>
                                </svg>
                            </button>

                        </div>

                        <div class="flex items-center gap-1 sm:gap-2">
                            {{-- Volume — speaker toggles mute, the thin slider sets the level
                                 (Figma: mute + line with a dot handle). Dragging to 0 mutes. --}}
                            <div class="mr-1 flex items-center gap-2">
                                <button type="button" @click="toggleMute()"
                                        :data-tooltip="audioMuted ? @js(__('Unmute')) : @js(__('Mute'))" data-tooltip-key="M"
                                        :aria-label="audioMuted ? @js(__('Unmute')) : @js(__('Mute'))"
                                        class="group flex h-10 w-10 items-center justify-center rounded-lg transition focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                                        :class="audioMuted ? 'text-white/60 hover:text-white' : 'text-white/85 hover:text-white'">
                                    <svg x-show="!audioMuted" class="h-6 w-6 shadow-sm transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z"/>
                                    </svg>
                                    <svg x-show="audioMuted" x-cloak class="h-6 w-6 shadow-sm transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z"/>
                                    </svg>
                                </button>
                                <input type="range" min="0" max="1" step="0.05"
                                       :value="audioMuted ? 0 : volumeLevel"
                                       @input="setVolume($event.target.value)"
                                       aria-label="{{ __('Volume') }}"
                                       class="lp-volume w-14"
                                       :style="`--lp-vol:${(audioMuted ? 0 : volumeLevel) * 100}%`">
                            </div>

                            {{-- Subtitles — a plain toggle: on/off is a single choice, so no menu.
                                 The plate + amber underline mark the on state; C toggles from the
                                 keyboard too. --}}
                            <button type="button"
                                    @click="toggleCaptions()"
                                    data-tooltip="{{ __('Subtitles') }}" data-tooltip-key="C"
                                    :aria-pressed="captionsOn"
                                    aria-label="{{ __('Subtitles') }}"
                                    class="group relative flex h-10 w-10 items-center justify-center rounded-lg transition focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                                    :class="captionsOn ? 'bg-white/10 text-white' : 'text-white/85 hover:text-white'">
                                <svg class="h-6 w-6 shadow-sm transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <rect x="2.75" y="5.25" width="18.5" height="13.5" rx="2.25"/>
                                    <path stroke-linecap="round" d="M10 10.4a2.4 2.4 0 1 0 0 3.2M18 10.4a2.4 2.4 0 1 0 0 3.2"/>
                                </svg>
                                <span x-show="captionsOn" class="absolute bottom-1 left-1/2 h-0.5 w-5 -translate-x-1/2 rounded-full bg-amber-400"></span>
                            </button>

                            {{-- Chapter-list toggle. Hidden on game scenes like the old chapter bar:
                                 the quiz card paints in a sibling layer above this deck, so the list
                                 would open underneath it. --}}
                            <button type="button" x-show="chapters.length > 1 && !currentIsGame" @click="chaptersOpen = !chaptersOpen"
                                    data-tooltip="{{ __('Chapters') }}" aria-label="{{ __('Chapters') }}"
                                    :aria-expanded="chaptersOpen"
                                    class="group flex h-10 w-10 items-center justify-center rounded-lg transition focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                                    :class="chaptersOpen ? 'text-white' : 'text-white/85 hover:text-white'">
                                <svg class="h-6 w-6 shadow-sm transition-transform duration-150 ease-out group-hover:scale-110 group-active:scale-95"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── TITLE SCREEN — Netflix style ────────────────────────────── --}}
        {{-- Background Ken Burns runs on z-0 layer behind this. --}}
        {{-- Deliberately NO leave transition: Alpine transitions advance on animation frames,
             and a hidden document (embedded preview in a background tab) never produces one —
             the title screen then never reached display:none and sat painted over the whole
             lesson. A plain x-show hides it synchronously on the phase flip, visible tab or not. --}}
        <div
            x-show="phase === 'TITLE_SCREEN'"
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
                <div class="min-w-0 flex-1 max-w-6xl">

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
                        <template x-if="lesson.audio_url || (lesson.scenes && lesson.scenes.some(s => s.audio_url || s.kind === 'map' || s.kind === 'voyage' || s.kind === 'gallery'))">
                            <button
                                @click="startLesson()"
                                class="group flex items-center gap-3 rounded-full bg-amber-500 px-6 py-3 sm:px-8 sm:py-3.5
                                       text-sm sm:text-base font-bold text-slate-950
                                       shadow-[0_0_48px_rgba(245,158,11,0.35)]
                                       transition duration-150 hover:bg-amber-400 hover:shadow-[0_0_64px_rgba(245,158,11,0.5)]
                                       active:scale-95"
                            >
                                <svg width="21" height="23" class="w-4 h-4 fill-current text-slate-950" viewBox="0 0 21 23" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M0 2.52873C0 0.608107 2.05916 -0.609418 3.74205 0.316169L19.2842 8.86436C21.0285 9.82373 21.0285 12.3301 19.2842 13.2895L3.74205 21.8377C2.05916 22.7633 0 21.5457 0 19.6251V2.52873Z"/>
                                </svg>


                                <span>Start lesson</span>
                            </button>
                        </template>
                        <template x-if="!lesson.audio_url && !(lesson.scenes && lesson.scenes.some(s => s.audio_url || s.kind === 'map' || s.kind === 'voyage' || s.kind === 'gallery'))">
                            <div class="flex items-center gap-2 rounded-full border border-slate-600 px-6 py-3 text-sm text-slate-400">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                <span>Audio generating…</span>
                            </div>
                        </template>
                    </div>
                </div>

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

            {{-- Timer HUD — bottom-right: the top-right corner belongs to the audio
                 controls (they overlapped there; founder annotation 2026-07-14). --}}
            <div class="absolute bottom-6 right-6 flex items-center gap-2">
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
