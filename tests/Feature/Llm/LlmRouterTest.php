<?php

declare(strict_types=1);

namespace Tests\Feature\Llm;

use App\Services\Llm\LlmRouter;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Routing calls by the KIND of work rather than by vendor.
 *
 * The narration script and a list of quiz distractors have very different value,
 * so they should be able to run on different models. These cover the resolution
 * rules and the two provider quirks that break free and local models: a model
 * named per role, and a provider that rejects response_format.
 */
class LlmRouterTest extends TestCase
{
    private function withProviders(): void
    {
        config()->set('llm.providers', [
            'openai' => [
                'label' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1',
                'api_key' => 'sk-test', 'model' => 'gpt-4o-mini',
                'json_format' => 'json_object', 'timeout' => 60,
            ],
            'gemini' => [
                'label' => 'Gemini', 'base_url' => 'https://gemini.test/v1beta/openai',
                'api_key' => 'g-test', 'model' => 'gemma-3-27b-it',
                'json_format' => 'json_object', 'timeout' => 90,
            ],
            'ollama' => [
                'label' => 'Ollama', 'base_url' => 'http://localhost:11434/v1',
                'api_key' => null, 'model' => 'gemma3:12b',
                'json_format' => '', 'timeout' => 600,
            ],
        ]);
    }

    public function test_each_role_resolves_to_its_own_provider(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['script' => 'openai', 'mechanical' => 'gemini']);

        $router = app(LlmRouter::class);

        $this->assertSame('gpt-4o-mini', $router->profileFor('script')->model);
        $this->assertSame('gemma-3-27b-it', $router->profileFor('mechanical')->model);
    }

    public function test_a_role_can_name_a_model_on_a_shared_provider(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['script' => 'gemini:gemini-2.5-pro', 'mechanical' => 'gemini']);

        $router = app(LlmRouter::class);

        $script = $router->profileFor('script');
        $this->assertSame('gemini-2.5-pro', $script->model);
        $this->assertSame('https://gemini.test/v1beta/openai', $script->baseUrl, 'the endpoint still comes from the provider');
        $this->assertSame('gemma-3-27b-it', $router->profileFor('mechanical')->model);
    }

    public function test_an_unconfigured_role_falls_back_to_the_default(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['mechanical' => 'gemini']);
        config()->set('llm.default_role', 'mechanical');

        $this->assertSame('gemma-3-27b-it', app(LlmRouter::class)->profileFor('vision')->model);
    }

    public function test_an_unknown_provider_is_a_configuration_error(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['script' => 'wishful-thinking']);

        $this->expectException(InvalidArgumentException::class);
        app(LlmRouter::class)->profileFor('script');
    }

    public function test_the_client_calls_the_provider_the_role_names(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['mechanical' => 'gemini']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        app(LlmRouter::class)->for('mechanical')->text('sys', 'user');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gemini.test/v1beta/openai/chat/completions')
            && $request['model'] === 'gemma-3-27b-it');
    }

    /**
     * Ollama, LM Studio and some Gemma builds 400 on response_format. Sending it anyway
     * is the single most common reason a local model "doesn't work" — so a provider that
     * declares an empty json_format must not receive the parameter at all.
     */
    public function test_a_provider_that_rejects_response_format_never_receives_it(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['mechanical' => 'ollama']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]])]);

        app(LlmRouter::class)->for('mechanical')->json('sys', 'user');

        Http::assertSent(fn ($request) => ! isset($request['response_format']));
    }

    public function test_a_provider_that_supports_json_mode_still_gets_it(): void
    {
        $this->withProviders();
        config()->set('llm.roles', ['mechanical' => 'openai']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]])]);

        app(LlmRouter::class)->for('mechanical')->json('sys', 'user');

        Http::assertSent(fn ($request) => ($request['response_format']['type'] ?? null) === 'json_object');
    }

    public function test_a_hosted_provider_without_a_key_is_flagged_unusable(): void
    {
        $this->withProviders();
        config()->set('llm.providers.gemini.api_key', null);

        $this->assertFalse(app(LlmRouter::class)->profile('gemini')->isUsable());
        $this->assertTrue(app(LlmRouter::class)->profile('ollama')->isUsable(), 'a local endpoint needs no key');
    }
}
