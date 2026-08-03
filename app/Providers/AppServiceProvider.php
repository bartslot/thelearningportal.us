<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\ElevenLabsService::class);

        // Built from config rather than autowired — its constructor takes the byte cap, stage size
        // and grain preset as plain scalars, which the container cannot guess. A singleton also
        // means the "does this avifenc have AOM?" probe runs once per process, not once per image.
        $this->app->singleton(
            \App\Services\BackgroundImageOptimizer::class,
            fn () => \App\Services\BackgroundImageOptimizer::fromConfig(),
        );

        // SiteGround's PHP ships serialize_precision=100, which makes json_encode() write the full
        // binary expansion of every float: a narration timing of 0.081 goes out as
        // 0.08100000000000000255351295663786004297435283660888671875. On the lesson player that is
        // ~445 KB of the payload (60% of the page) and it hits every JSON API response the Flutter
        // app reads too. -1 is PHP's own default: the shortest string that round-trips to the same
        // double, so nothing is lost.
        if (ini_get('serialize_precision') !== '-1') {
            ini_set('serialize_precision', '-1');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `artisan serve` hands the built-in server a scrubbed environment, keeping only the
        // variables on this list. PHP_INI_SCAN_DIR was not on it, so the dev server ran on the
        // compiled defaults — a 2 MB upload ceiling — and every image past that failed in the
        // picker with "failed to upload", under a modal that offers 8 MB. See build/php-ini/.
        if (app()->environment('local')) {
            ServeCommand::$passthroughVariables[] = 'PHP_INI_SCAN_DIR';
        }

        if (! app()->runningInConsole() && ! app()->environment('testing') && $this->narratesWithElevenLabs()) {
            // Warm the ElevenLabs voice cache once. The lock (atomic Cache::add) stops every web
            // request re-dispatching the job while it's still pending — otherwise, with no queue
            // worker running, the cache never fills and thousands of jobs pile up.
            //
            // FAILED_KEY is the other half of that: the lock lapses after 600s, so without a
            // backoff a dead API would keep queueing warm jobs for as long as it stayed dead.
            if (! \Illuminate\Support\Facades\Cache::has(\App\Services\ElevenLabsService::VOICES_KEY)
                && ! \Illuminate\Support\Facades\Cache::has(\App\Services\ElevenLabsService::FAILED_KEY)
                && \Illuminate\Support\Facades\Cache::add('elevenlabs_voices_warming', true, 600)) {
                \App\Jobs\WarmElevenLabsJob::dispatch();
            }
        }
    }

    /**
     * Is ElevenLabs actually doing the narrating?
     *
     * TTS_PROVIDER_OVERRIDE routes every scene's narration through one provider (currently Azure).
     * While it names anyone else, the ElevenLabs voice list is never read, so fetching it is a
     * standing API call for a roster nothing consults.
     */
    private function narratesWithElevenLabs(): bool
    {
        $override = strtolower(trim((string) config('services.tts.provider_override')));

        return $override === '' || $override === 'elevenlabs';
    }
}
