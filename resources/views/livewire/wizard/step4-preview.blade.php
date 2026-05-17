<div class="contents" x-data="step4Preview">

    {{-- Fullscreen canvas wrapper (same as Step 3) — wire:ignore so playback
         survives Livewire morphs (e.g. Publish click). --}}
    <div class="fixed inset-0 z-0 bg-black" id="lesson-canvas-root"
         data-character-url="{{ $lesson->avatar?->glbUrl() }}"
         wire:ignore>
        <canvas id="lesson-canvas" class="w-full h-full block"></canvas>
        <div id="lesson-overlay" class="absolute inset-0 pointer-events-none"></div>
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
        {!! $this->scenes->map->only(['id','kind','year','location','image_path','audio_path','audio_alignment','duration_seconds','script_segment','animation_clip_id'])->toJson() !!}
    </script>
</div>

@push('head-scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('step4Preview', () => ({
            playing: false,
            readout: '0:00 / 0:00',
            stage:   null,
            total:   0,

            async init() {
                if (!window.LessonScene?.mountWizardScene) return;

                const dataEl       = document.getElementById('step4-scenes-data');
                const scenes       = dataEl ? JSON.parse(dataEl.textContent) : [];
                const overlayEl    = document.getElementById('lesson-overlay');
                const timerEl      = document.getElementById('lesson-game-overlay');
                const canvasEl     = document.getElementById('lesson-canvas');
                const rootEl       = document.getElementById('lesson-canvas-root');
                const characterUrl = rootEl?.dataset.characterUrl || null;

                this.stage = await window.LessonScene.mountWizardScene({
                    canvasEl, overlayEl, timerEl, scenes, characterUrl,
                });
                if (!this.stage) return;

                this.total   = this.stage.sequencer.totalSeconds();
                this.readout = `0:00 / ${this._fmt(this.total)}`;

                this.stage.sequencer.on('scenechange', s => {
                    document.documentElement.style.setProperty('--playhead-scene-id', s.id);
                    // Mirror to Livewire so the timeline thumb shows the amber ring.
                    this.$wire?.set('selectedSceneId', s.id, false);
                });
                this.stage.sequencer.on('timelineend', () => { this.playing = false; });
            },

            async togglePlay() {
                if (!this.stage) return;
                if (this.playing) {
                    this.stage.sequencer.pause();
                    this.playing = false;
                } else {
                    this.playing = true;
                    await this.stage.sequencer.playFrom(0);
                }
            },

            _fmt(s) {
                const m = Math.floor(s / 60), r = Math.floor(s % 60);
                return `${m}:${String(r).padStart(2, '0')}`;
            },
        }));
    });
</script>
@endpush
