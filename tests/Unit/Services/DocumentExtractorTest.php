<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DocumentExtractor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DocumentExtractorTest extends TestCase
{
    public function test_extracts_text_from_a_pdf(): void
    {
        $path = base_path('tests/Fixtures/documents/sample.pdf');
        $text = app(DocumentExtractor::class)->extractFromPath($path);

        $this->assertStringContainsString('Napoleon', $text);
        $this->assertStringContainsString('1769', $text);
    }

    public function test_extracts_text_from_a_docx(): void
    {
        $path = base_path('tests/Fixtures/documents/sample.docx');
        $text = app(DocumentExtractor::class)->extractFromPath($path);

        $this->assertStringContainsString('Napoleon', $text);
    }

    public function test_extracts_text_from_an_uploaded_file(): void
    {
        $path   = base_path('tests/Fixtures/documents/sample.pdf');
        $upload = new UploadedFile($path, 'sample.pdf', 'application/pdf', null, true);

        $this->assertStringContainsString(
            'Napoleon',
            app(DocumentExtractor::class)->extract($upload),
        );
    }

    public function test_throws_for_unsupported_extensions(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc') . '.xls';
        file_put_contents($tmp, 'fake');

        $this->expectException(\InvalidArgumentException::class);
        app(DocumentExtractor::class)->extractFromPath($tmp);
    }
}
