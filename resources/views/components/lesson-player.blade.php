{{--
    x-lesson-player — Composite lesson player
    ─────────────────────────────────────────
    Background: Ken Burns slideshow of historical images (Europeana / Wikimedia)
    Foreground: AI avatar video (or portrait + audio player when video not yet ready)

    Props:
      $lesson  — App\Models\Lesson
--}}
@props(['lesson'])

@php
    $images      = $lesson->slideshowImages();
    $hasImages   = count($images) > 0;
    $videoUrl    = $lesson->videoUrl();
    $audioUrl    = $lesson->audioUrl();
    $portraitUrl = $lesson->portraitUrl();
    $visemesUrl  = $lesson->visemesUrl();
    $figure      = $lesson->historical_figure ?? 'Narrator';
@endphp

<div
    x-data="lessonPlayer(@js($images), @js($videoUrl), @js($audioUrl), @js($visemesUrl))"
    x-init="init()"
    @destroy.window="destroy()"
    class="relative w-full overflow-hidden rounded-2xl border border-slate-800"
    style="aspect-ratio: 16/9; background: #020617;"
>
    {{-- ── Background image slideshow ───────────────────────────────────── --}}
    @if($hasImages)
        <div class="absolute inset-0 z-0" aria-hidden="true">
            {{-- Two layers for crossfade --}}
            <div
                x-ref="slideA"
                class="absolute inset-0 bg-center bg-cover transition-opacity duration-[1200ms] ease-in-out"
                :style="`background-image: url('${currentImageUrl}'); opacity: ${layerAOpacity};`"
            ></div>
            <div
                x-ref="slideB"
                class="absolute inset-0 bg-center bg-cover transition-opacity duration-[1200ms] ease-in-out"
                :style="`background-image: url('${nextImageUrl}'); opacity: ${layerBOpacity};`"
            ></div>

            {{-- Dark gradient overlay so the avatar stays legible --}}
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/30 to-slate-950/10"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/40 to-transparent"></div>
        </div>

        {{-- Attribution watermark --}}
        <div class="absolute bottom-3 left-4 z-20 flex items-center gap-1.5" aria-live="polite">
            <template x-if="currentAttribution">
                <span
                    x-text="currentAttribution"
                    class="rounded-md bg-slate-950/60 px-2 py-0.5 text-[10px] text-slate-400 backdrop-blur-sm transition-opacity duration-700"
                    :class="attributionVisible ? 'opacity-100' : 'opacity-0'"
                ></span>
            </template>

            {{-- Source badge --}}
            <template x-if="currentSource === 'europeana'">
                <a href="https://www.europeana.eu" target="_blank" rel="noopener noreferrer"
                   class="rounded-md bg-slate-950/60 px-2 py-0.5 text-[10px] text-indigo-400 hover:text-indigo-300 backdrop-blur-sm transition-colors">
                    Europeana
                </a>
            </template>
            <template x-if="currentSource === 'wikimedia'">
                <a href="https://commons.wikimedia.org" target="_blank" rel="noopener noreferrer"
                   class="rounded-md bg-slate-950/60 px-2 py-0.5 text-[10px] text-sky-400 hover:text-sky-300 backdrop-blur-sm transition-colors">
                    Wikimedia
                </a>
            </template>
        </div>

        {{-- Slide dots --}}
        <div class="absolute bottom-3 right-4 z-20 flex items-center gap-1" aria-hidden="true">
            @foreach($images as $i => $img)
                <button
                    @click="jumpTo({{ $i }})"
                    :class="currentIndex === {{ $i }} ? 'bg-amber-400 w-4' : 'bg-slate-600 w-1.5'"
                    class="h-1.5 rounded-full transition-all duration-300"
                    aria-label="Slide {{ $i + 1 }}"
                ></button>
            @endforeach
        </div>

    @else
        {{-- No images — plain dark backdrop --}}
        <div class="absolute inset-0 z-0 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950"></div>
    @endif

    {{-- ── Foreground: Avatar video or portrait + audio ──────────────────── --}}
    <div class="absolute inset-0 z-10 flex items-center justify-center">

        @if($videoUrl)
            {{-- Full avatar video — covers the center, slight transparency on edges shows slideshow --}}
            <video
                x-ref="avatarVideo"
                @play="isPlaying = true"
                @pause="isPlaying = false"
                @ended="isPlaying = false"
                controls
                preload="metadata"
                poster="{{ $portraitUrl }}"
                class="h-full max-h-full max-w-full object-contain drop-shadow-2xl"
                style="filter: drop-shadow(0 0 24px rgba(0,0,0,0.8));"
            >
                <source src="{{ $videoUrl }}" type="video/mp4">
            </video>

        @elseif($audioUrl)
            {{-- Lip-sync avatar player (portrait + mouth sprites driven by visemes) --}}
            <div
                x-data="lipSyncPlayer(@js($audioUrl), @js($portraitUrl), @js($visemesUrl))"
                x-init="init()"
                class="flex flex-col items-center gap-4 px-6 w-full max-w-sm"
            >
                {{-- Canvas: portrait + mouth overlay --}}
                <div class="relative">
                    <canvas
                        x-ref="canvas"
                        width="256"
                        height="256"
                        class="rounded-xl shadow-2xl border border-slate-700/50"
                    ></canvas>

                    {{-- Play/pause button overlay --}}
                    <button
                        @click="toggle()"
                        class="absolute inset-0 flex items-center justify-center rounded-xl
                               bg-slate-950/0 hover:bg-slate-950/30 transition-colors group"
                        :aria-label="playing ? 'Pause' : 'Play'"
                    >
                        <template x-if="!playing">
                            <svg class="h-12 w-12 text-white drop-shadow-lg opacity-80 group-hover:opacity-100 transition-opacity"
                                 fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </template>
                    </button>
                </div>

                {{-- Name + status --}}
                <div class="text-center">
                    <p class="text-sm font-semibold text-slate-100">{{ $figure }}</p>
                    <p class="text-xs text-slate-400" x-text="visemes ? 'Lip-sync narration' : 'Audio narration'"></p>
                </div>

                {{-- Progress bar --}}
                <div class="w-full h-1 bg-slate-800 rounded-full overflow-hidden">
                    <div class="h-full bg-amber-400 rounded-full transition-none"
                         :style="`width: ${progress}%`"></div>
                </div>

                {{-- Time --}}
                <div class="flex w-full justify-between text-xs text-slate-500">
                    <span x-text="formatTime(currentTime)">0:00</span>
                    <span x-text="formatTime(duration)">0:00</span>
                </div>

                <audio x-ref="audio" preload="metadata" @ended="playing = false"></audio>
            </div>

        @elseif($lesson->isGenerating() || $lesson->status === \App\Enums\LessonStatus::Pending)
            {{-- Generating placeholder --}}
            <div class="flex flex-col items-center gap-3 text-center">
                <div class="flex gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-amber-400 animate-bounce" style="animation-delay:0ms"></span>
                    <span class="h-2 w-2 rounded-full bg-amber-400 animate-bounce" style="animation-delay:150ms"></span>
                    <span class="h-2 w-2 rounded-full bg-amber-400 animate-bounce" style="animation-delay:300ms"></span>
                </div>
                <p class="text-sm text-slate-400">Generating your lesson…</p>
            </div>

        @else
            {{-- Portrait-only fallback --}}
            <div class="flex flex-col items-center gap-3">
                <img
                    src="{{ $portraitUrl }}"
                    alt="{{ $figure }}"
                    class="h-28 w-28 rounded-xl object-cover border border-slate-700 shadow-2xl"
                >
                <p class="text-xs text-slate-400">{{ $figure }}</p>
            </div>
        @endif

    </div>

    {{-- ── Pause / Play overlay button (when video is present) ──────────── --}}
    @if($videoUrl)
        {{-- The native video controls handle this; overlay is hidden --}}
    @elseif($hasImages)
        {{-- Slideshow pause/play --}}
        <button
            @click="toggleSlideshow()"
            class="absolute top-3 right-3 z-20 flex h-8 w-8 items-center justify-center rounded-full
                   bg-slate-950/60 text-slate-300 backdrop-blur-sm hover:bg-slate-900 hover:text-amber-400
                   transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500"
            :aria-label="slideshowPaused ? 'Play slideshow' : 'Pause slideshow'"
        >
            <template x-if="slideshowPaused">
                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            </template>
            <template x-if="!slideshowPaused">
                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                </svg>
            </template>
        </button>
    @endif

</div>

{{-- Lip-sync player logic --}}
<script>
    document.addEventListener('alpine:init', () => {
        if (window.__lipSyncPlayerRegistered) return;
        window.__lipSyncPlayerRegistered = true;

        Alpine.data('lipSyncPlayer', (audioUrl, portraitUrl, visemesUrl) => ({
            playing:     false,
            currentTime: 0,
            duration:    0,
            progress:    0,
            visemes:     null,   // parsed viseme cue list [{start, value}]

            // Canvas rendering
            _portrait:   null,  // HTMLImageElement
            _mouths:     {},    // { A: HTMLImageElement, B: ... }
            _rafId:      null,
            _ctx:        null,

            MOUTH_CODES: ['A','B','C','D','E','F','G','X'],
            MOUTH_BASE:  '/avatars/mouths/',

            async init() {
                this._ctx = this.$refs.canvas.getContext('2d');

                // Load portrait
                this._portrait = await this._loadImage(portraitUrl);

                // Load mouth sprites
                await Promise.all(this.MOUTH_CODES.map(async code => {
                    const img = await this._loadImage(`${this.MOUTH_BASE}${code}.png`).catch(() => null);
                    if (img) this._mouths[code] = img;
                }));

                // Load visemes JSON
                if (visemesUrl) {
                    try {
                        const res = await fetch(visemesUrl);
                        const data = await res.json();
                        // Rhubarb JSON: { "metadata": {...}, "mouthCues": [{"start":0.0,"end":0.1,"value":"X"},...] }
                        this.visemes = data.mouthCues ?? null;
                    } catch (e) {
                        this.visemes = null;
                    }
                }

                // Wire up audio element
                const audio = this.$refs.audio;
                audio.src = audioUrl;
                audio.addEventListener('timeupdate', () => {
                    this.currentTime = audio.currentTime;
                    this.duration    = audio.duration || 0;
                    this.progress    = this.duration ? (this.currentTime / this.duration) * 100 : 0;
                });
                audio.addEventListener('loadedmetadata', () => {
                    this.duration = audio.duration;
                });

                // Draw initial frame
                this._drawFrame();
            },

            toggle() {
                const audio = this.$refs.audio;
                if (this.playing) {
                    audio.pause();
                    this.playing = false;
                    cancelAnimationFrame(this._rafId);
                } else {
                    audio.play();
                    this.playing = true;
                    this._loop();
                }
            },

            _loop() {
                this._drawFrame();
                this._rafId = requestAnimationFrame(() => this._loop());
            },

            _drawFrame() {
                const ctx    = this._ctx;
                const canvas = this.$refs.canvas;
                const W = canvas.width, H = canvas.height;

                ctx.clearRect(0, 0, W, H);

                // Draw portrait filling canvas
                if (this._portrait) {
                    ctx.drawImage(this._portrait, 0, 0, W, H);
                }

                // Determine current viseme
                const code = this._currentViseme();
                const mouth = this._mouths[code] ?? this._mouths['X'];

                if (mouth) {
                    // Position mouth in lower-center third of portrait
                    const mW = Math.round(W * 0.38);
                    const mH = Math.round(mW * (mouth.naturalHeight / mouth.naturalWidth));
                    const mX = Math.round((W - mW) / 2);
                    const mY = Math.round(H * 0.63);
                    ctx.drawImage(mouth, mX, mY, mW, mH);
                }
            },

            _currentViseme() {
                if (! this.visemes || ! this.playing) return 'X';
                const t = this.$refs.audio.currentTime;
                // Find the cue whose range contains t
                for (let i = this.visemes.length - 1; i >= 0; i--) {
                    if (this.visemes[i].start <= t) {
                        return this.visemes[i].value ?? 'X';
                    }
                }
                return 'X';
            },

            _loadImage(src) {
                return new Promise((resolve, reject) => {
                    const img = new Image();
                    img.onload  = () => resolve(img);
                    img.onerror = reject;
                    img.src     = src;
                });
            },

            formatTime(secs) {
                if (!secs || isNaN(secs)) return '0:00';
                const m = Math.floor(secs / 60);
                const s = Math.floor(secs % 60).toString().padStart(2, '0');
                return `${m}:${s}`;
            },
        }));
    });
</script>

{{-- Alpine component logic --}}
<script>
    document.addEventListener('alpine:init', () => {
        if (window.__lessonPlayerRegistered) return;
        window.__lessonPlayerRegistered = true;

        Alpine.data('lessonPlayer', (images, videoUrl, audioUrl) => ({
            // ── Slideshow state ─────────────────────────────────────────
            images:              images || [],
            currentIndex:        0,
            nextIndex:           1,
            layerAOpacity:       1,
            layerBOpacity:       0,
            activeLayer:         'A',   // which layer is currently visible
            slideshowPaused:     false,
            attributionVisible:  true,

            // ── Computed props ──────────────────────────────────────────
            get currentImageUrl()    { return this.images[this.currentIndex]?.url    ?? ''; },
            get nextImageUrl()       { return this.images[this.nextIndex]?.url       ?? ''; },
            get currentAttribution() { return this.images[this.currentIndex]?.attribution ?? ''; },
            get currentSource()      { return this.images[this.currentIndex]?.source ?? ''; },

            // ── Internals ───────────────────────────────────────────────
            _intervalId: null,
            SLIDE_DURATION: 6000,   // ms between slides
            FADE_DURATION:  1200,   // ms — must match CSS transition-duration above

            init() {
                if (this.images.length > 1) {
                    this._startSlideshow();
                }
            },

            destroy() {
                this._stopSlideshow();
            },

            _startSlideshow() {
                this._intervalId = setInterval(() => {
                    if (! this.slideshowPaused) {
                        this._advance();
                    }
                }, this.SLIDE_DURATION);
            },

            _stopSlideshow() {
                if (this._intervalId) {
                    clearInterval(this._intervalId);
                    this._intervalId = null;
                }
            },

            _advance() {
                if (this.images.length < 2) return;

                const next = (this.currentIndex + 1) % this.images.length;
                this.nextIndex = next;

                // Flash attribution out before transition
                this.attributionVisible = false;

                // Short delay so the next image is loaded before we fade to it
                setTimeout(() => {
                    if (this.activeLayer === 'A') {
                        // B is now showing next image; fade B in, A out
                        this.layerBOpacity = 1;
                        this.layerAOpacity = 0;
                        this.activeLayer   = 'B';
                    } else {
                        this.layerAOpacity = 1;
                        this.layerBOpacity = 0;
                        this.activeLayer   = 'A';
                    }

                    // After fade completes, update currentIndex and swap layers
                    setTimeout(() => {
                        this.currentIndex = next;
                        // Pre-load next-next
                        this.nextIndex = (next + 1) % this.images.length;
                        this.attributionVisible = true;
                    }, this.FADE_DURATION);

                }, 80);
            },

            jumpTo(index) {
                if (index === this.currentIndex) return;
                this.currentIndex = index;
                this.nextIndex    = (index + 1) % this.images.length;

                // Hard-set opacity so the selected image shows instantly
                this.attributionVisible = true;
                if (this.activeLayer === 'A') {
                    this.layerAOpacity = 1;
                    this.layerBOpacity = 0;
                } else {
                    this.layerBOpacity = 1;
                    this.layerAOpacity = 0;
                }
            },

            toggleSlideshow() {
                this.slideshowPaused = ! this.slideshowPaused;
            },
        }));
    });
</script>
