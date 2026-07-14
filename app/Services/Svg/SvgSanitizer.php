<?php

declare(strict_types=1);

namespace App\Services\Svg;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

/**
 * Hardens a third-party SVG for safe rendering in the browser.
 *
 * Untrusted SVGs are an XSS/XXE/SSRF surface: they can carry <script>, on* event
 * handlers, external <image>/<use> references, foreignObject HTML, and DOCTYPE
 * entity expansions. We parse with a whitelist and re-serialise — anything not on
 * the allow-list is dropped. Geometry and paint attributes are kept so the drawing
 * engine can sample paths and build a tone map from the colours.
 *
 * @see \App\Services\SvgAssetService which calls this on every import.
 */
class SvgSanitizer
{
    /** Hard cap — refuse anything larger before we even parse it. */
    private const MAX_BYTES = 3_000_000;

    /** Elements kept in the output. Everything else is removed. */
    private const ALLOWED_TAGS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline',
        'polygon', 'defs', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'title', 'desc',
    ];

    /** Attributes kept on any element. Presentation colours are safe (values, not code). */
    private const ALLOWED_ATTRS = [
        'd', 'points', 'x', 'y', 'cx', 'cy', 'r', 'rx', 'ry',
        'x1', 'y1', 'x2', 'y2', 'width', 'height', 'viewbox', 'preserveaspectratio',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'fill-rule', 'clip-rule',
        'fill-opacity', 'stroke-opacity', 'opacity', 'transform',
        'gradientunits', 'gradienttransform', 'offset', 'stop-color', 'stop-opacity',
        'clip-path', 'id', 'class', 'style', 'xmlns',
    ];

    /**
     * @return array{svg: string, width: ?int, height: ?int, view_box: ?string}
     *
     * @throws RuntimeException when the input is not a usable SVG
     */
    public function sanitize(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('SVG is empty or exceeds the size limit.');
        }
        if (stripos($raw, '<svg') === false) {
            throw new RuntimeException('Not an SVG document.');
        }
        // XXE guard: strip the DOCTYPE (and any internal ENTITY subset) before parsing.
        // The pattern consumes either non-bracket chars or a full [...] subset so a
        // `>` inside an <!ENTITY …> declaration can't end the match early.
        if (stripos($raw, '<!DOCTYPE') !== false) {
            $raw = preg_replace('/<!DOCTYPE(?:[^\[>]|\[[^\]]*\])*>/is', '', $raw) ?? $raw;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks network access; NOENT is intentionally NOT set so
        // entity references are never expanded.
        $ok = $doc->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok === false || ! $doc->documentElement) {
            throw new RuntimeException('SVG could not be parsed.');
        }

        $root = $doc->documentElement;
        if (strtolower($root->localName) !== 'svg') {
            throw new RuntimeException('Root element is not <svg>.');
        }

        $this->clean($root);

        $width = $this->intAttr($root, 'width');
        $height = $this->intAttr($root, 'height');
        $viewBox = $root->getAttribute('viewBox') ?: null;

        // Guarantee a viewBox so the drawing engine has a coordinate space.
        if ($viewBox === null && $width && $height) {
            $viewBox = "0 0 {$width} {$height}";
            $root->setAttribute('viewBox', $viewBox);
        }
        if ($viewBox === null) {
            throw new RuntimeException('SVG has no viewBox or intrinsic size.');
        }

        $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        $svg = $doc->saveXML($root);
        if ($svg === false) {
            throw new RuntimeException('SVG could not be serialised.');
        }

        return ['svg' => $svg, 'width' => $width, 'height' => $height, 'view_box' => $viewBox];
    }

    /** Recursively remove disallowed nodes and attributes, depth-first. */
    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            if ($child instanceof DOMElement) {
                if (! in_array(strtolower($child->localName), self::ALLOWED_TAGS, true)) {
                    $child->parentNode?->removeChild($child);

                    continue;
                }
                $this->scrubAttributes($child);
                $this->clean($child);

                continue;
            }
            // Drop comments and processing instructions; keep text/CDATA.
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    private function scrubAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $local = strtolower($attr->localName);

            // Namespaced hrefs (xlink:href / href) are the external-reference vector.
            if ($local === 'href') {
                $el->removeAttributeNode($attr);

                continue;
            }
            if (! in_array($local, self::ALLOWED_ATTRS, true)) {
                $el->removeAttributeNode($attr);

                continue;
            }
            if ($local === 'style') {
                $safe = $this->scrubStyle($attr->nodeValue ?? '');
                $safe === '' ? $el->removeAttribute($attr->nodeName) : $el->setAttribute($attr->nodeName, $safe);

                continue;
            }
            // Kill any value that smuggles a URL or script, even on allowed attrs.
            if (preg_match('/url\s*\(|javascript:|expression\s*\(|data:(?!image\/)/i', (string) $attr->nodeValue)) {
                $el->removeAttributeNode($attr);
            }
        }
    }

    /** Keep colour/paint declarations; drop anything referencing a URL or script. */
    private function scrubStyle(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            if (preg_match('/url\s*\(|javascript:|expression\s*\(|@import/i', $decl)) {
                continue;
            }
            $kept[] = trim($decl);
        }

        return implode('; ', array_filter($kept));
    }

    private function intAttr(DOMElement $el, string $name): ?int
    {
        $v = $el->getAttribute($name);
        if ($v === '' || ! preg_match('/^\d+(\.\d+)?/', $v, $m)) {
            return null;
        }

        return (int) round((float) $m[0]);
    }
}
