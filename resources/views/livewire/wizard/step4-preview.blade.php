<div class="contents" x-data="step4Preview">

    {{-- Fullscreen canvas wrapper (same as Step 3) — wire:ignore so playback
         survives Livewire morphs (e.g. Publish click). --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url="{{ $lesson->avatar?->glbUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none py-32"></div>
        <div id="lesson-game-overlay" class="absolute inset-0 pointer-events-none"></div>
    </div>

    {{-- Publish button --}}
    <div class="fixed top-4 right-4 z-40 flex flex-col items-end gap-2">
        @if ($lesson->status === \App\Enums\LessonStatus::Published)
            <span class="px-3 py-2 rounded-xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 text-xs">Published ✓</span>
        @else
            <button wire:click="publish"
                    @disabled(! $this->allReady)
                    class="btn btn-sm bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 disabled:opacity-40">
                Publish
            </button>
            @if ($publishError)
                <span class="text-xs text-rose-300 max-w-xs text-right">{{ $publishError }}</span>
            @endif
        @endif
    </div>

    {{-- Play / Back floating --}}
    <div class="fixed bottom-28 inset-x-0 z-30 flex items-center justify-center gap-3">
        <a href="{{ route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 3]) }}"
           wire:navigate class="btn btn-sm btn-outline">← Configure</a>
        <button type="button" @click="togglePlay()"
                class="btn btn-circle bg-amber-500 text-slate-950 hover:bg-amber-400 border-0 w-14 h-14 flex items-center justify-center">
            <span x-show="!playing"><x-icons.play class="w-5 h-5" /></span>
            <span x-show="playing"  x-cloak><x-icons.pause class="w-5 h-5" /></span>
        </button>
        <span class="text-xs text-slate-300" x-text="readout"></span>
    </div>

    {{-- Read-only timeline — clickable to seek, highlights the playing scene --}}
    <x-lesson.timeline :scenes="$this->scenes" :selected-scene-id="$selectedSceneId" :editable="false" />

    {{-- Scenes payload as inert JSON --}}
    <script type="application/json" id="step4-scenes-data">
        {!! $this->scenes->map->only(['id','kind','year','location','image_path','world_pano_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id'])->toJson() !!}
    </script>
    {{-- Background music URL (empty string = none) --}}
    @php
        $musicFile = match($lesson->background_music) {
            'default','track2','track3','track4','track5','track6' => 'default.mp3',
            default => null,
        };
    @endphp
    <meta id="step4-bg-music" content="{{ $musicFile ? asset('sound/bg-music/' . $musicFile) : '' }}">
</div>

@script
<script>
    Alpine.data('step4Preview', () => ({
            playing: false,
            readout: '0:00 / 0:00',
            stage:   null,
            total:   0,
            _bgAudio: null,
            _fadingOut: false,

            async init() {
                if (!window.LessonScene?.mountWizardScene) return;

                const dataEl       = document.getElementById('step4-scenes-data');
                const scenes       = dataEl ? JSON.parse(dataEl.textContent) : [];
                const overlayEl    = document.getElementById('lesson-overlay');
                const timerEl      = document.getElementById('lesson-game-overlay');
                const canvasEl     = document.getElementById('lesson-canvas');
                const rootEl       = document.getElementById('lesson-canvas-root');
                const characterUrl = rootEl?.dataset.characterUrl || null;
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
                });
            },

            async togglePlay() {
                if (!this.stage) return;
                if (this.playing) {
                    this.stage.sequencer.pause();
                    this.playing = false;
                    if (this._bgAudio) this._bgAudio.pause();
                } else {
                    this.playing = true;
                    if (this._bgAudio) {
                        this._bgAudio.currentTime = 0;
                        this._bgAudio.volume = 0.26;
                        this._bgAudio.play().catch(() => {});
                    }
                    await this.stage.sequencer.playFrom(0);
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
