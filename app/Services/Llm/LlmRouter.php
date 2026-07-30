<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\OpenAiLlmService;
use InvalidArgumentException;

/**
 * Hands out a language-model client for a KIND of work rather than a named vendor.
 *
 * Call sites ask for `for('script')` or `for('mechanical')`; which provider and
 * model that means lives in config/llm.php and .env. Swapping the mechanical
 * calls onto a free Gemma to see how it copes is then one environment variable,
 * with the narration still on whatever writes best.
 */
class LlmRouter
{
    /** @var array<string, OpenAiLlmService> */
    private array $clients = [];

    /** The client for a role. Unknown roles fall back to the configured default. */
    public function for(string $role): OpenAiLlmService
    {
        return $this->clients[$role] ??= $this->build($this->profileFor($role));
    }

    /** Which provider and model a role currently resolves to. */
    public function profileFor(string $role): LlmProfile
    {
        $roles = (array) config('llm.roles', []);
        $reference = $roles[$role] ?? null;

        if (! $reference) {
            $fallback = (string) config('llm.default_role', 'mechanical');
            $reference = $roles[$fallback] ?? null;
            if (! $reference) {
                throw new InvalidArgumentException("No LLM configured for role [{$role}] and no usable default.");
            }
        }

        return $this->profile((string) $reference);
    }

    /**
     * Resolve "gemini" or "gemini:gemma-3-27b-it" to a profile. The optional model
     * suffix lets one provider serve several roles at different sizes.
     */
    public function profile(string $reference): LlmProfile
    {
        [$provider, $model] = array_pad(explode(':', $reference, 2), 2, null);

        $config = config("llm.providers.{$provider}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown LLM provider [{$provider}]. Add it to config/llm.php.");
        }

        return LlmProfile::fromConfig((string) $provider, $config)->withModel($model);
    }

    /** Every configured provider, for the probe command and the bake-off. */
    public function profiles(): array
    {
        return array_map(
            fn (string $name) => $this->profile($name),
            array_combine(array_keys((array) config('llm.providers', [])), array_keys((array) config('llm.providers', []))),
        );
    }

    public function build(LlmProfile $profile): OpenAiLlmService
    {
        return new OpenAiLlmService(
            apiKey: $profile->apiKey,
            model: $profile->model,
            baseUrl: $profile->baseUrl,
            timeout: $profile->timeout,
            jsonFormat: $profile->jsonFormat,
        );
    }
}
