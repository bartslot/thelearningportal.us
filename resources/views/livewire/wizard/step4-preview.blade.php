@php
    // Voyage lessons are SAILED, not narrated — the wizard's audio sequencer can't play them.
    // Embed the real student player instead. Either way the preview starts from the scene the teacher
    // had selected (global UX: Play always plays from the current scene, never scene 0).
    $isVoyage = ($lesson->game_type ?? null) === 'voyage';
    $ordered = $this->scenes->values();
    $pos = $ordered->search(fn ($s) => $s->id === $selectedSceneId);
    $startIndex = $pos === false ? 0 : $pos;
@endphp

<div class="contents" @unless($isVoyage) x-data="step4Preview" @endunless>

    @if ($isVoyage)
        {{-- Real player, autoplaying from the selected leg (embed=1 skips the title screen). --}}
        <div class="fixed inset-0 z-0 bg-black" wire:ignore wire:key="voyage-play-{{ $selectedSceneId }}">
            <iframe
                src="{{ route('lesson.play', ['lessonCode' => $lesson->lesson_code]) }}?autoplay=1&embed=1&scene={{ $startIndex }}"
                class="h-full w-full border-0" allow="autoplay; fullscreen"
                title="{{ __('Lesson preview') }}"></iframe>
        </div>
    @else
    {{-- Fullscreen canvas wrapper (same as Step 3) — wire:ignore so playback
         survives Livewire morphs (e.g. Publish click). --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url=""
         data-start-index="{{ $startIndex }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        {{-- 2D avatar: small portrait badge in the bottom-right corner. --}}
        @if ($lesson->avatar && ($avatarImg = $lesson->avatar->thumbnailUrl() ?? $lesson->avatar->portraitUrl()))
            <img src="{{ $avatarImg }}" alt="{{ $lesson->avatar->name }}"
                 class="pointer-events-none absolute bottom-28 right-4 z-10 h-[150px] w-[150px] rounded-xl object-cover shadow-2xl ring-1 ring-white/15">
        @endif
        {{-- No vertical padding: SceneOverlay sets container-type:size here and padding forces a
             256px min-height that pushes the caption off a short stage; its cqh coefficients are
             tuned for the padding-free host. overflow-hidden clips the caption to the canvas. --}}
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none overflow-hidden"></div>
        <!-- <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div> -->
    </div>
    @endif

    {{-- Published state only — top-20 clears the fixed navbar. The PUBLISH ACTION lives on the
         Configure step's top toolbar; the preview must NOT duplicate it (that amber Publish here was
         a second, redundant Publish button). Once published, we still surface the View / Download links. --}}
    @if ($lesson->status === \App\Enums\LessonStatus::Published)
        <div class="fixed top-20 right-4 z-40 flex flex-col items-end gap-2">
            <div class="flex items-center gap-2">
                <span class="badge badge-success gap-1 py-3 px-3 font-semibold shadow-lg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ __('Published') }}
                </span>
                <a href="{{ route('lesson.play', ['lessonCode' => $lesson->lesson_code]) }}"
                   class="btn btn-sm bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 shadow-lg">
                    {{ __('View lesson') }} →
                </a>
            </div>
            @if ($lesson->game_pack_path)
                <a href="{{ route('teacher.lessons.print.game-pack', $lesson) }}" target="_blank"
                   class="btn btn-sm btn-outline shadow-lg">
                    <x-icons.cube class="w-4 h-4" />
                    {{ __('Download spelpakket (PDF)') }}
                </a>
            @endif
        </div>
    @endif

    {{-- The big moment: full-screen splash after publishing, with the rating widget in its
         natural place (right after the teacher finished creating). --}}
    @if ($showPublishSplash)
        <x-splash-screen :title="__('Your lesson is now live!')"
                         :subtitle="$lesson->title ?? $lesson->topic"
                         close="dismissPublishSplash">
            <x-slot:actions>
                <a href="{{ route('lesson.play', ['lessonCode' => $lesson->lesson_code]) }}"
                   class="btn btn-lg bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 px-8">
                    {{ __('View lesson') }} →
                </a>
                <button type="button" wire:click="dismissPublishSplash" class="btn btn-lg btn-ghost">
                    {{ __('Keep editing') }}
                </button>
            </x-slot:actions>

            <p class="mb-2 text-xs uppercase tracking-wider text-slate-400">{{ __('How was creating this lesson?') }}</p>
            <div class="flex justify-center">
                <livewire:lesson-feedback-widget :lesson="$lesson" />
            </div>
        </x-splash-screen>
    @endif

    @if ($isVoyage)
        {{-- Voyage autoplays in the embedded player. No back button here — the teacher returns to
             editing via the top-left dashboard arrow or the player's own "Edit scene" pill (top-left).
             Navigation lives in the top corners; the bottom stays clean. --}}
    @else
    {{-- Play control — bottom strip (scenes now live in the left rail). No "Configure" button: the
         teacher returns to editing via the top-left navigation, not a redundant bottom link. --}}
    <div class="fixed bottom-6 inset-x-0 z-30 flex items-center justify-center gap-3 pl-44">
        <button type="button" @click="togglePlay()"
                class="btn btn-circle bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 w-14 h-14 flex items-center justify-center">
            <span x-show="!playing"><x-icons.play class="w-5 h-5" /></span>
            <span x-show="playing"  x-cloak><x-icons.pause class="w-5 h-5" /></span>
        </button>
        <span class="text-xs text-slate-300" x-text="readout"></span>
    </div>

    {{-- Read-only timeline — clickable to seek, highlights the playing scene --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" :editable="false" />

    {{-- Scenes payload as inert JSON. Quiz scenes carry the lesson's questions so the
         preview sequencer can actually step through them. --}}
    <script type="application/json" id="step4-scenes-data">
        {!! $this->scenes->map(fn ($s) => array_merge(
            $s->only(['id','kind','game_type','quiz_question_count','quiz_timing','strategy_game_id','team_count','year','location','image_path','world_pano_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id','background_color','kb_animated','kb_direction','config']),
            {{-- Chronology-scoped: each quiz segment previews ITS question set (legacy pool fallback). --}}
            ['quiz_questions' => $s->kind === 'game' && ($s->game_type ?? null) === 'quiz'
                ? $lesson->quizQuestions->where('scene_id', $s->id)->values()
                    ->whenEmpty(fn () => $lesson->quizQuestions->whereNull('scene_id')->values())
                    ->map->only(['question', 'options', 'correct_index', 'asks_ahead', 'explanation'])->values()
                : null],
        ))->toJson() !!}
    </script>
    {{-- Background music URL (empty string = none) --}}
    @php
        $musicFile = match($lesson->background_music) {
            'default','track2','track3','track4','track5','track6' => 'default.mp3',
            default => null,
        };
    @endphp
    <meta id="step4-bg-music" content="{{ $musicFile ? asset('sound/bg-music/' . $musicFile) : '' }}">
    @endif
</div>

@script
<script>
    Alpine.data('step4Preview', () => ({
            playing: false,
            readout: '0:00 / 0:00',
            stage:   null,
            total:   0,
            _startIndex: 0,   // the scene the teacher opened Play on — playback starts here, not scene 0
            _bgAudio: null,
            _fadingOut: false,
            // Elapsed-time readout state — the display was static before (set once at init):
            // completed-scene seconds + wall-clock within the current scene, ticked every 500ms.
            _elapsedBase: 0,
            _sceneStart: null,
            _readoutTimer: null,

            _startReadout() {
                clearInterval(this._readoutTimer);
                this._readoutTimer = setInterval(() => {
                    if (!this.playing || this._sceneStart === null) return;
                    const now = this._elapsedBase + (Date.now() - this._sceneStart) / 1000;
                    this.readout = `${this._fmt(Math.min(now, this.total))} / ${this._fmt(this.total)}`;
                }, 500);
            },
            _stopReadout(reset = false) {
                clearInterval(this._readoutTimer);
                this._readoutTimer = null;
                if (reset) { this._elapsedBase = 0; this._sceneStart = null; this.readout = `0:00 / ${this._fmt(this.total)}`; }
            },

            async init() {
                await window.loadLessonScene?.();
                if (!window.LessonScene?.mountWizardScene) return;

                const dataEl       = document.getElementById('step4-scenes-data');
                const scenes       = dataEl ? JSON.parse(dataEl.textContent) : [];
                const overlayEl    = document.getElementById('lesson-overlay');
                const timerEl      = document.getElementById('lesson-game-overlay');
                const canvasEl     = document.getElementById('lesson-canvas');
                const rootEl       = document.getElementById('lesson-canvas-root');
                const characterUrl = rootEl?.dataset.characterUrl || null;
                this._startIndex   = Math.max(0, Number(rootEl?.dataset.startIndex) || 0);
                const musicUrl     = document.getElementById('step4-bg-music')?.content || '';

                if (musicUrl) {
                    const a = new Audio(musicUrl);
                    a.loop   = true;
                    a.volume = 0.26;
                    this._bgAudio = a;
                }

                this.stage = await window.LessonScene.mountWizardScene({
                    canvasEl, overlayEl, timerEl, scenes, characterUrl,
                });
                if (!this.stage) return;

                this.total   = this.stage.sequencer.totalSeconds();
                this.readout = `0:00 / ${this._fmt(this.total)}`;

                this.stage.sequencer.on('scenechange', s => {
                    document.documentElement.style.setProperty('--playhead-scene-id', s.id);
                    this.$wire?.set('selectedSceneId', s.id, false);

                    // Advance the elapsed readout: sum the durations of all scenes BEFORE this one.
                    const scenes = this.stage.sequencer.scenes || [];
                    const idx = scenes.findIndex(x => x.id === s.id);
                    this._elapsedBase = scenes.slice(0, Math.max(0, idx))
                        .reduce((acc, x) => acc + Math.max(0, x.duration_seconds || 0), 0);
                    this._sceneStart = Date.now();

                    // Fade out music when a game scene starts; fade in for narration.
                    if (this._bgAudio) {
                        if (s.kind === 'game') {
                            this._fadeVolume(this._bgAudio, this._bgAudio.volume, 0, 1200);
                        } else {
                            this._fadeVolume(this._bgAudio, this._bgAudio.volume, 0.26, 1200);
                        }
                    }
                });
                this.stage.sequencer.on('timelineend', () => {
                    this.playing = false;
                    this._stopBgMusic();
                    this._stopReadout(true);
                });
            },

            async togglePlay() {
                if (!this.stage) return;
                if (this.playing) {
                    this.stage.sequencer.pause();
                    this.playing = false;
                    this._stopReadout();
                    if (this._bgAudio) this._bgAudio.pause();
                } else {
                    this.playing = true;
                    this._elapsedBase = 0;
                    this._sceneStart = Date.now();
                    this._startReadout();
                    if (this._bgAudio) {
                        this._bgAudio.currentTime = 0;
                        this._bgAudio.volume = 0.26;
                        this._bgAudio.play().catch(() => {});
                    }
                    // Play from the scene the teacher was on (clamped), not always scene 0.
                    const from = Math.min(this._startIndex, Math.max(0, (this.stage.sequencer.scenes?.length || 1) - 1));
                    await this.stage.sequencer.playFrom(from);
                }
            },

            _stopBgMusic() {
                if (!this._bgAudio) return;
                this._fadeVolume(this._bgAudio, this._bgAudio.volume, 0, 800, () => {
                    this._bgAudio.pause();
                    this._bgAudio.currentTime = 0;
                });
            },

            _fadeVolume(audio, from, to, ms, done) {
                const steps = 20;
                const interval = ms / steps;
                const delta = (to - from) / steps;
                let step = 0;
                const tick = setInterval(() => {
                    step++;
                    audio.volume = Math.min(1, Math.max(0, from + delta * step));
                    if (step >= steps) {
                        clearInterval(tick);
                        done?.();
                    }
                }, interval);
            },

            _fmt(s) {
                const m = Math.floor(s / 60), r = Math.floor(s % 60);
                return `${m}:${String(r).padStart(2, '0')}`;
            },
        }));
</script>
@endscript
