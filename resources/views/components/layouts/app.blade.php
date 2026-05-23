<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="learningportal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }} — The Learning Portal</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cinzel:700|inter:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head-scripts')
    @livewireStyles
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased">
    <x-app-nav />

    @if(session('success'))
        <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-emerald-700 bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-rose-700 bg-rose-900/40 px-4 py-3 text-sm text-rose-300">
                {{ session('error') }}
            </div>
        </div>
    @endif

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {{ $slot }}
    </main>

    @livewireScripts
    <script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js" data-wavesurfer-lib></script>
    <script>
        window.ensureWaveSurfer = (() => {
            let pending = null;

            return function ensureWaveSurfer() {
                if (window.WaveSurfer && typeof window.WaveSurfer.create === 'function') {
                    return Promise.resolve(window.WaveSurfer);
                }

                if (pending) {
                    return pending;
                }

                pending = new Promise((resolve, reject) => {
                    const existing = document.querySelector('script[data-wavesurfer-lib]');

                    const onLoad = () => {
                        if (window.WaveSurfer && typeof window.WaveSurfer.create === 'function') {
                            resolve(window.WaveSurfer);
                        } else {
                            reject(new Error('WaveSurfer loaded but global is unavailable'));
                        }
                    };

                    const onError = () => reject(new Error('Failed to load WaveSurfer'));

                    if (existing) {
                        existing.addEventListener('load', onLoad, { once: true });
                        existing.addEventListener('error', onError, { once: true });
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js';
                    script.dataset.wavesurferLib = 'true';
                    script.addEventListener('load', onLoad, { once: true });
                    script.addEventListener('error', onError, { once: true });
                    document.head.appendChild(script);
                }).finally(() => {
                    pending = null;
                });

                return pending;
            };
        })();

        /**
         * Alpine component for waveform audio player.
         * Usage: x-data="wavePlayer('https://...')"
         */
        function wavePlayer(src, transcript = '', wordTimings = []) {
            return {
                playing: false,
                ready:   false,
                loading: true,
                time:    '–:––',
                ws:      null,
                error:   false,
                errorMessage: '',
                resizeObserver: null,
                nativeAudioCleanup: null,
                progressRafId: null,
                progressPct: 0,
                targetProgressPct: 0,
                words: [],
                wordEnds: [],
                timedWords: [],
                activeWordIndex: -1,
                maxObservedTime: 0,

                async init() {
                    if (!src) { this.loading = false; return; }
                    this.words = String(transcript || '')
                        .split(/\s+/)
                        .map((word) => word.trim())
                        .filter(Boolean);
                    this.timedWords = Array.isArray(wordTimings)
                        ? wordTimings
                            .map((entry) => ({
                                text: String(entry?.text ?? '').trim(),
                                start: Number(entry?.start ?? 0),
                                end: Number(entry?.end ?? entry?.start ?? 0),
                            }))
                            .filter((entry) => entry.text.length > 0 && Number.isFinite(entry.start) && Number.isFinite(entry.end))
                        : [];

                    if (this.timedWords.length > 0) {
                        this.words = this.timedWords.map((entry) => entry.text);
                    }
                    this.wordEnds = this.buildWordTimeline(this.words);
                    this.error = false;
                    this.errorMessage = '';

                    try {
                        const WaveSurferLib = await window.ensureWaveSurfer();
                        this.ws = WaveSurferLib.create({
                            container:      this.$refs.waveform,
                            media:          this.$refs.nativeAudio || undefined,
                            waveColor:      '#475569',   // slate-600
                            progressColor:  '#f59e0b',   // amber-400
                            cursorColor:    'transparent', // we draw our own cursor overlay
                            barWidth:       2,
                            barGap:         1,
                            barRadius:      2,
                            height:         44,
                            normalize:      true,
                            url:            src,
                        });

                        this.observeResize();
                        this.bindNativeAudio();

                        this.ws.on('ready', (duration) => {
                            this.loading = false;
                            this.ready   = true;
                            this.time    = this.fmt(duration);
                            this.refreshWave();
                        });
                        this.ws.on('audioprocess', (t) => {
                            const d = this.ws?.getDuration?.() ?? 0;
                            this.time = this.fmt(d - t);
                        });
                        this.ws.on('play', () => {
                            this.playing = true;
                        });
                        this.ws.on('pause', () => {
                            this.playing = false;
                        });
                        this.ws.on('finish', () => {
                            this.playing = false;
                            this.time    = this.fmt(this.ws?.getDuration?.() ?? 0);
                        });
                        this.ws.on('error', (err) => {
                            const rawMessage = String((err && (err.message || err.name)) || '');
                            const isAbort = /aborted|aborterror|operation was aborted/i.test(rawMessage);

                            if (isAbort) {
                                // Expected during Livewire/Alpine teardown or rapid remounts.
                                this.error = false;
                                this.errorMessage = '';
                                return;
                            }

                            this.loading = false;
                            this.ready = false;
                            this.error = true;
                            const details = rawMessage ? ` (${rawMessage})` : '';
                            this.errorMessage = `Waveform failed to load${details}. You can still try native audio controls below.`;
                        });
                    } catch (e) {
                        this.loading = false;
                        this.ready = false;
                        this.error = true;
                        this.errorMessage = 'Could not initialise audio waveform player.';
                    }
                },

                bindNativeAudio() {
                    const nativeAudio = this.$refs.nativeAudio || null;
                    if (!nativeAudio) {
                        return;
                    }

                    const syncFromNative = () => {
                        if (!this.ws) {
                            return;
                        }

                        const rawDuration = Number(nativeAudio.duration || this.ws.getDuration() || 0);
                        const current = Number(nativeAudio.currentTime || 0);
                        const safeCurrent = Number.isFinite(current) && current >= 0 ? current : 0;
                        this.maxObservedTime = Math.max(this.maxObservedTime, safeCurrent);

                        // Some MP3s may report a tiny/incorrect duration initially.
                        // Keep timeline stable by growing an effective duration with observed playback time.
                        const effectiveDuration = Math.max(
                            Number.isFinite(rawDuration) && rawDuration > 0 ? rawDuration : 0,
                            this.maxObservedTime + 0.35,
                            1
                        );

                        if (effectiveDuration > 0) {
                            const progress = Math.max(0, Math.min(1, safeCurrent / effectiveDuration));
                            this.targetProgressPct = progress * 100;
                            if (this.words.length > 0) {
                                this.activeWordIndex = this.timedWords.length > 0
                                    ? this.wordIndexAtTime(safeCurrent)
                                    : this.wordIndexAtProgress(progress);
                            }
                            if (typeof this.ws.setTime === 'function') {
                                this.ws.setTime(safeCurrent);
                            } else {
                                this.ws.seekTo(progress);
                            }
                            this.time = this.fmt(Math.max(0, effectiveDuration - safeCurrent));
                            this.ready = true;
                            this.loading = false;
                        } else {
                            this.targetProgressPct = 0;
                        }
                    };

                    const onPlay = () => {
                        this.playing = true;
                        this.error = false;
                        this.errorMessage = '';
                        this.maxObservedTime = Number(nativeAudio.currentTime || 0);
                        this.startProgressLoop(nativeAudio, syncFromNative);
                    };

                    const onPause = () => {
                        this.playing = false;
                        this.stopProgressLoop();
                    };

                    const onEnded = () => {
                        this.playing = false;
                        this.stopProgressLoop();
                        this.targetProgressPct = 100;
                        this.progressPct = 100;
                        if (this.words.length > 0) {
                            this.activeWordIndex = this.words.length - 1;
                        }
                        syncFromNative();
                    };

                    nativeAudio.addEventListener('timeupdate', syncFromNative);
                    nativeAudio.addEventListener('loadedmetadata', syncFromNative);
                    nativeAudio.addEventListener('durationchange', syncFromNative);
                    nativeAudio.addEventListener('play', onPlay);
                    nativeAudio.addEventListener('pause', onPause);
                    nativeAudio.addEventListener('ended', onEnded);

                    this.nativeAudioCleanup = () => {
                        this.stopProgressLoop();
                        nativeAudio.removeEventListener('timeupdate', syncFromNative);
                        nativeAudio.removeEventListener('loadedmetadata', syncFromNative);
                        nativeAudio.removeEventListener('durationchange', syncFromNative);
                        nativeAudio.removeEventListener('play', onPlay);
                        nativeAudio.removeEventListener('pause', onPause);
                        nativeAudio.removeEventListener('ended', onEnded);
                    };
                },

                startProgressLoop(nativeAudio, syncFromNative) {
                    this.stopProgressLoop();

                    const tick = () => {
                        if (!nativeAudio.paused && !nativeAudio.ended) {
                            syncFromNative();
                            this.progressPct += (this.targetProgressPct - this.progressPct) * 0.24;
                            this.progressRafId = window.requestAnimationFrame(tick);
                        } else {
                            this.progressPct = this.targetProgressPct;
                            this.progressRafId = null;
                        }
                    };

                    this.progressRafId = window.requestAnimationFrame(tick);
                },

                stopProgressLoop() {
                    if (this.progressRafId !== null) {
                        window.cancelAnimationFrame(this.progressRafId);
                        this.progressRafId = null;
                    }
                },

                buildWordTimeline(words) {
                    if (!Array.isArray(words) || words.length === 0) {
                        return [];
                    }

                    // Weight by visible character count so longer words stay highlighted a bit longer.
                    const weights = words.map((word) => {
                        const visibleChars = word.replace(/[^a-z0-9]/gi, '').length;
                        return Math.max(1, visibleChars);
                    });
                    const total = weights.reduce((sum, w) => sum + w, 0) || 1;

                    let acc = 0;
                    return weights.map((w) => {
                        acc += w / total;
                        return acc;
                    });
                },

                wordIndexAtProgress(progress) {
                    if (!this.wordEnds.length) {
                        return -1;
                    }

                    const p = Math.max(0, Math.min(1, progress));
                    const idx = this.wordEnds.findIndex((end) => p <= end);
                    return idx === -1 ? this.wordEnds.length - 1 : idx;
                },

                wordIndexAtTime(currentSeconds) {
                    if (!this.timedWords.length) {
                        return -1;
                    }

                    const t = Math.max(0, Number(currentSeconds || 0));
                    const idx = this.timedWords.findIndex((entry) => t <= Math.max(entry.start, entry.end));
                    return idx === -1 ? this.timedWords.length - 1 : idx;
                },

                toggle() {
                    const nativeAudio = this.$refs.nativeAudio || null;

                    if (nativeAudio) {
                        const duration = Number(nativeAudio.duration || 0);
                        const current = Number(nativeAudio.currentTime || 0);

                        if (nativeAudio.paused) {
                            if (duration > 0 && current >= duration - 0.05) {
                                nativeAudio.currentTime = 0;
                            }

                            nativeAudio.play().then(() => {
                                this.playing = true;
                                this.ready = true;
                                this.error = false;
                                this.errorMessage = '';
                            }).catch((err) => {
                                this.playing = false;
                                this.error = true;
                                const msg = err && err.message ? err.message : 'Unable to start audio playback.';
                                this.errorMessage = msg;
                            });
                        } else {
                            nativeAudio.pause();
                            this.playing = false;
                        }
                        return;
                    }

                    if (!this.ws) {
                        return;
                    }

                    if (!this.ready && (this.ws?.getDuration?.() ?? 0) > 0) {
                        this.ready = true;
                    }

                    if (!this.ready) {
                        return;
                    }

                    const duration = this.ws?.getDuration?.() ?? 0;
                    const current = this.ws.getCurrentTime();
                    if (duration > 0 && current >= duration - 0.05) {
                        this.ws.seekTo(0);
                    }

                    this.ws.playPause();
                },

                observeResize() {
                    if (!this.$refs.waveform || this.resizeObserver) {
                        return;
                    }

                    this.resizeObserver = new ResizeObserver(() => {
                        this.refreshWave();
                    });
                    this.resizeObserver.observe(this.$refs.waveform);
                },

                refreshWave() {
                    if (!this.ws || !this.$refs.waveform) {
                        return;
                    }

                    const width = this.$refs.waveform.clientWidth;
                    if (width > 0) {
                        this.ws.setOptions({ width });
                    } else {
                        // Container not laid out yet — retry after paint
                        requestAnimationFrame(() => this.refreshWave());
                    }
                },

                destroy() {
                    if (this.resizeObserver) {
                        this.resizeObserver.disconnect();
                        this.resizeObserver = null;
                    }
                    if (this.nativeAudioCleanup) {
                        this.nativeAudioCleanup();
                        this.nativeAudioCleanup = null;
                    }
                    this.stopProgressLoop();
                    if (this.ws) {
                        this.ws.destroy();
                        this.ws = null;
                    }
                    this.ready   = false;
                    this.loading = true;
                    this.playing = false;
                },

                fmt(s) {
                    if (!s || isNaN(s)) return '0:00';
                    const m   = Math.floor(s / 60);
                    const sec = Math.floor(s % 60);
                    return `${m}:${sec.toString().padStart(2, '0')}`;
                },
            };
        }
    </script>

    @stack('scripts')
</body>
</html>
