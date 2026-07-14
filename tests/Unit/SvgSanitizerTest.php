<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Svg\SvgSanitizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new SvgSanitizer;
    }

    public function test_strips_script_elements(): void
    {
        $out = $this->sanitizer->sanitize(
            '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0L10 10"/></svg>'
        );

        $this->assertStringNotContainsString('script', $out['svg']);
        $this->assertStringContainsString('<path', $out['svg']);
    }

    public function test_strips_event_handler_attributes(): void
    {
        $out = $this->sanitizer->sanitize(
            '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" onload="steal()" onclick="x()"/></svg>'
        );

        $this->assertStringNotContainsString('onload', $out['svg']);
        $this->assertStringNotContainsString('onclick', $out['svg']);
    }

    public function test_strips_external_references(): void
    {
        $out = $this->sanitizer->sanitize(
            '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<image xlink:href="https://evil.example/x.png" width="10" height="10"/>'
            .'<use xlink:href="https://evil.example/inject.svg#a"/>'
            .'<path d="M0 0L10 10"/></svg>'
        );

        $this->assertStringNotContainsString('evil.example', $out['svg']);
        $this->assertStringNotContainsString('<image', $out['svg']);
        $this->assertStringNotContainsString('<use', $out['svg']);
    }

    public function test_rejects_doctype_entity_xxe(): void
    {
        $out = $this->sanitizer->sanitize(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 10"/></svg>'
        );

        $this->assertStringNotContainsString('ENTITY', $out['svg']);
        $this->assertStringNotContainsString('passwd', $out['svg']);
        $this->assertStringContainsString('<path', $out['svg']);
    }

    public function test_keeps_paint_colours_for_tone_map(): void
    {
        $out = $this->sanitizer->sanitize(
            '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 10" fill="#c0392b" stroke="#000"/></svg>'
        );

        $this->assertStringContainsString('#c0392b', $out['svg']);
        $this->assertStringContainsString('stroke="#000"', $out['svg']);
    }

    public function test_strips_url_and_javascript_from_style(): void
    {
        $out = $this->sanitizer->sanitize(
            '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L1 1" style="fill:#0f0;background:url(javascript:alert(1))"/></svg>'
        );

        $this->assertStringContainsString('fill:#0f0', $out['svg']);
        $this->assertStringNotContainsString('javascript', $out['svg']);
        $this->assertStringNotContainsString('url(', $out['svg']);
    }

    public function test_derives_viewbox_from_width_height(): void
    {
        $out = $this->sanitizer->sanitize(
            '<svg width="120" height="80" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L1 1"/></svg>'
        );

        $this->assertSame('0 0 120 80', $out['view_box']);
        $this->assertSame(120, $out['width']);
        $this->assertSame(80, $out['height']);
    }

    public function test_rejects_non_svg(): void
    {
        $this->expectException(RuntimeException::class);
        $this->sanitizer->sanitize('<html><body>hi</body></html>');
    }

    public function test_rejects_svg_without_coordinate_space(): void
    {
        $this->expectException(RuntimeException::class);
        $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0L1 1"/></svg>');
    }
}
