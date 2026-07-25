<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EmbedParser;
use PHPUnit\Framework\TestCase;

class EmbedParserTest extends TestCase
{
    private EmbedParser $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new EmbedParser;
    }

    public function test_sketchfab_from_model_url(): void
    {
        $r = $this->p->sketchfab('https://sketchfab.com/3d-models/santa-maria-ship-1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d');
        $this->assertSame('sketchfab', $r['kind']);
        $this->assertSame('1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d', $r['id']);
        $this->assertSame('https://sketchfab.com/models/1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d/embed', $r['src']);
    }

    public function test_sketchfab_from_iframe_snippet(): void
    {
        $snippet = '<iframe title="x" src="https://sketchfab.com/models/1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d/embed" allow="autoplay"></iframe>';
        $this->assertSame('1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d', $this->p->sketchfab($snippet)['id']);
    }

    public function test_sketchfab_rejects_garbage(): void
    {
        $this->assertNull($this->p->sketchfab('https://example.com/not-a-model'));
        $this->assertNull($this->p->sketchfab(''));
    }

    /** @dataProvider youtubeUrls */
    public function test_youtube_ids(string $url): void
    {
        $r = $this->p->video($url);
        $this->assertSame('youtube', $r['provider']);
        $this->assertSame('dQw4w9WgXcQ', $r['id']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $r['src']);
    }

    public static function youtubeUrls(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ'],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            ['https://www.youtube.com/shorts/dQw4w9WgXcQ'],
            ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ'],
        ];
    }

    public function test_vimeo(): void
    {
        $this->assertSame('76979871', $this->p->video('https://vimeo.com/76979871')['id']);
        $this->assertSame('https://player.vimeo.com/video/76979871', $this->p->video('https://player.vimeo.com/video/76979871')['src']);
    }

    public function test_generic_iframe_snippet_klokhuis(): void
    {
        $snippet = '<iframe src="https://embed.hetklokhuis.nl/player/12345" width="640" height="360"></iframe>';
        $r = $this->p->video($snippet);
        $this->assertSame('other', $r['provider']);
        $this->assertSame('https://embed.hetklokhuis.nl/player/12345', $r['src']);
    }

    public function test_video_rejects_bare_unknown_url(): void
    {
        $this->assertNull($this->p->video('https://example.com/some-page'));
    }

    public function test_youtube_settings_applied(): void
    {
        $embed = $this->p->video('https://youtu.be/dQw4w9WgXcQ');
        $src = $this->p->embedVideoSrc($embed, ['autoplay' => true, 'start' => 30, 'end' => 90, 'controls' => false]);
        $this->assertStringContainsString('autoplay=1', $src);
        $this->assertStringContainsString('mute=1', $src);
        $this->assertStringContainsString('controls=0', $src);
        $this->assertStringContainsString('start=30', $src);
        $this->assertStringContainsString('end=90', $src);
    }

    public function test_vimeo_start_uses_fragment(): void
    {
        $embed = $this->p->video('https://vimeo.com/76979871');
        $src = $this->p->embedVideoSrc($embed, ['autoplay' => false, 'start' => 45, 'controls' => true]);
        $this->assertStringContainsString('#t=45s', $src);
        $this->assertStringContainsString('controls=1', $src);
    }
}
