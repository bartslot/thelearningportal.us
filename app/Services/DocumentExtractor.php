<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentExtractor
{
    public function extract(UploadedFile $file): string
    {
        return $this->extractFromPath(
            $file->getRealPath() ?: $file->getPathname(),
            $file->getClientOriginalExtension(),
        );
    }

    public function extractFromPath(string $path, ?string $extensionOverride = null): string
    {
        $ext = strtolower($extensionOverride ?: pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'   => $this->extractPdf($path),
            'docx'  => $this->extractDocx($path),
            default => throw new InvalidArgumentException("Unsupported document type: .{$ext}"),
        };
    }

    private function extractPdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf    = $parser->parseFile($path);
        return $this->normalize($pdf->getText());
    }

    private function extractDocx(string $path): string
    {
        $reader  = IOFactory::createReader('Word2007');
        $phpWord = $reader->load($path);

        $buffer = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $buffer[] = $this->extractElementText($element);
            }
        }

        return $this->normalize(implode("\n", $buffer));
    }

    private function extractElementText(mixed $element): string
    {
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            if (is_string($text)) {
                return $text;
            }
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractElementText($child);
            }
            return implode(' ', $parts);
        }

        return '';
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
