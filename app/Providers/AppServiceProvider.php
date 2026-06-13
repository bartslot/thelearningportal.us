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
