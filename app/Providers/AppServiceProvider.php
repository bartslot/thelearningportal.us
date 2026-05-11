<?php

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
            if (! \Illuminate\Support\Facades\Cache::has('elevenlabs_voices')) {
                \App\Jobs\WarmElevenLabsJob::dispatch();
            }
        }
    }
}
