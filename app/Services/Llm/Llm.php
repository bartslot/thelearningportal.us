<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

/**
 * Marks a language-model dependency with the KIND of work it does.
 *
 *     public function handle(#[Llm('script')] OpenAiLlmService $llm): void
 *
 * config/llm.php maps each role to a provider, so pointing the mechanical work at a
 * free Gemma while the narration stays on a strong model is a .env edit. Attributes
 * rather than `when()->needs()` because jobs receive the service through handle(),
 * and Container::call() honours contextual attributes but not contextual bindings.
 *
 * A value passed straight into handle() still wins — tests keep handing jobs a mock.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Llm implements ContextualAttribute
{
    public function __construct(public string $role = 'mechanical') {}

    public function resolve(self $attribute, Container $container): \App\Services\OpenAiLlmService
    {
        return $container->make(LlmRouter::class)->for($attribute->role);
    }
}
