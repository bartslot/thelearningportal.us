<?php

declare(strict_types=1);

/**
 * One-off: build sample.pdf + sample.docx fixtures for DocumentExtractorTest.
 *
 *   php scripts/build-doc-fixtures.php
 */

require __DIR__ . '/../vendor/autoload.php';

$outDir = __DIR__ . '/../tests/Fixtures/documents';
@mkdir($outDir, 0o777, true);

$text = 'Napoleon was born in 1769 on the island of Corsica.';

// ── DOCX via PhpWord ────────────────────────────────────────────────────────
$word = new \PhpOffice\PhpWord\PhpWord();
$section = $word->addSection();
$section->addText($text);
\PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')
    ->save($outDir . '/sample.docx');

// ── Minimal valid PDF (Type1 Helvetica, single page) ────────────────────────
$body = "BT /F1 12 Tf 50 700 Td ({$text}) Tj ET";
$contentObj = "<< /Length " . strlen($body) . " >>\nstream\n{$body}\nendstream";

$objects = [
    1 => "<< /Type /Catalog /Pages 2 0 R >>",
    2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
    4 => $contentObj,
    5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
];

$pdf      = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
$offsets  = [];
foreach ($objects as $id => $obj) {
    $offsets[$id] = strlen($pdf);
    $pdf .= "{$id} 0 obj\n{$obj}\nendobj\n";
}

$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
foreach ($objects as $id => $_obj) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
}
$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

file_put_contents($outDir . '/sample.pdf', $pdf);

echo "Wrote:\n  - {$outDir}/sample.pdf (" . filesize($outDir . '/sample.pdf') . " bytes)\n";
echo "  - {$outDir}/sample.docx (" . filesize($outDir . '/sample.docx') . " bytes)\n";
