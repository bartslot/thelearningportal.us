<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenAiImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiImageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai', [
            'api_key'           => 'test-key',
            'image_model'       => 'gpt-image-1',
            'image_size'        => '1536x1024',
            'image_format'      => 'webp',
            'image_compression' => 50,
            'base_url'          => 'https://api.openai.com/v1',
            'timeout'           => 60,
        ]);
        Storage::fake('public');
    }

    public function test_generates_an_image_downloads_it_and_stores_it_on_public_disk(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://example.com/dalle.png']],
            ], 200),
            'https://example.com/dalle.png' => Http::response('PNGDATA', 200),
        ]);

        $svc = app(OpenAiImageService::class);
        $path = $svc->generate(
            seedPrompt:  'Napoleonic Paris street at dusk',
            style:       'painted',
            destination: 'lessons/1/scenes/9/skybox.png',
        );

        $this->assertSame('lessons/1/scenes/9/skybox.png', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame('PNGDATA', Storage::disk('public')->get($path));
    }

    public function test_includes_the_safety_guardrail_and_style_clause_in_the_request(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://example.com/x.png']],
            ], 200),
            'https://example.com/x.png' => Http::response('X', 200),
        ]);

        app(OpenAiImageService::class)->generate(
            seedPrompt:  'Battle of Waterloo',
            style:       'cinematic',
            destination: 'lessons/1/scenes/2/skybox.png',
            isGame:      true,
        );

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/images/generations')) {
                return false;
            }
            $prompt = $request->data()['prompt'] ?? '';
            return str_contains($prompt, 'no children')
                && str_contains($prompt, 'film still')
                && str_contains($prompt, 'battle/scene illustration');
        });
    }

    public function test_sends_skybox_panorama_hint_and_webp_compression_flags(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://example.com/x.png']],
            ], 200),
            'https://example.com/x.png' => Http::response('X', 200),
        ]);

        app(OpenAiImageService::class)->generate(
            seedPrompt:  'Roman forum at dusk',
            style:       'painted',
            destination: 'lessons/1/scenes/3/skybox.webp',
        );

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/images/generations')) {
                return false;
            }
            $data   = $request->data();
            $prompt = $data['prompt'] ?? '';

            return str_contains($prompt, '360 degree panoramic scene')
                && str_contains($prompt, 'equirectangular projection')
                && str_contains($prompt, 'seamless continuity between the left and right edges')
                && str_contains($prompt, 'horizon line level and centered')
                && ($data['output_format'] ?? null) === 'webp'
                && ($data['output_compression'] ?? null) === 50;
        });
    }
}
