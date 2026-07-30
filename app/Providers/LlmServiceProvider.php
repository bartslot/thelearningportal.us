<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Llm\LlmRouter;
use App\Services\OpenAiLlmService;
use Illuminate\Support\ServiceProvider;

/**
 * Which model the app talks to, decided in one place.
 *
 * Jobs ask for a plain OpenAiLlmService and tag it with #[Llm('script')] or
 * #[Llm('mechanical')]; config/llm.php maps those roles onto providers. Nothing in
 * the pipeline names a vendor, so trying a free Gemma on the mechanical work while
 * the narration stays on a strong model is a .env edit.
 *
 * Anything resolving the service untagged — a Livewire component, a command,
 * tinker — lands on the default role rather than a hardcoded provider.
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmRouter::class);

        $this->app->bind(
            OpenAiLlmService::class,
            fn ($app) => $app->make(LlmRouter::class)->for((string) config('llm.default_role', 'mechanical')),
        );
    }
}
