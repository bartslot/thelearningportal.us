<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenAiLlmService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiLlmServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai', [
            'api_key'  => 'test-key',
            'model'    => 'gpt-4o-mini',
            'base_url' => 'https://api.openai.com/v1',
            'timeout'  => 30,
        ]);
    }

    public function test_returns_parsed_json_when_called_with_json_mode(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'title'        => 'Napoleonic Campaigns',
                        'scene_briefs' => [
                            ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris', 'beat' => 'intro'],
                        ],
                    ])],
                ]],
            ], 200),
        ]);

        $result = app(OpenAiLlmService::class)->json(
            system: 'You are a curriculum writer.',
            user:   'Outline a lesson on the Napoleonic Campaigns.',
        );

        $this->assertIsArray($result);
        $this->assertSame('Napoleonic Campaigns', $result['title']);
        $this->assertSame('1810', $result['scene_briefs'][0]['year']);
    }

    public function test_returns_a_string_when_called_with_text_mode(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'In 1810, Napoleon ruled France.']]],
            ], 200),
        ]);

        $text = app(OpenAiLlmService::class)->text(
            system: 'You write tight, vivid history.',
            user:   'Write one paragraph for Scene 1.',
        );

        $this->assertSame('In 1810, Napoleon ruled France.', $text);
    }

    public function test_throws_when_the_response_status_is_5xx(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response('boom', 503),
        ]);

        $this->expectException(\RuntimeException::class);
        app(OpenAiLlmService::class)->text(system: 's', user: 'u');
    }
}
