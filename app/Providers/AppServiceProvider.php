<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\ElevenLabsService::class);

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
        if (! app()->runningInConsole() && ! app()->environment('testing')) {
            // Warm the ElevenLabs voice cache once. The lock (atomic Cache::add) stops every web
            // request re-dispatching the job while it's still pending — otherwise, with no queue
            // worker running, the cache never fills and thousands of jobs pile up.
            if (! \Illuminate\Support\Facades\Cache::has('elevenlabs_voices')
                && \Illuminate\Support\Facades\Cache::add('elevenlabs_voices_warming', true, 600)) {
                \App\Jobs\WarmElevenLabsJob::dispatch();
            }
        }
    }
}
