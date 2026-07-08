<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Development-only: renders a fake "photographed" paper answer sheet as a PNG so
 * the paper-import (vision) flow can be tested without a printer or camera.
 *
 * No TTF fonts ship with the app, so text uses GD's built-in bitmap font scaled
 * up with nearest-neighbour — blocky but crisp and reliably legible to the vision
 * model. Bubbles are drawn large; the chosen answer is a solid filled circle,
 * matching what PaperSheetExtractor tells the model to look for.
 */
final class DevPaperSheet
{
    private const WIDTH = 920;

    private const BUBBLE_XS = [300, 460, 620, 780];

    private const ROW_H = 78;

    /**
     * @param  list<array{name: string, answers: list<string>}>  $sheets  answers are 'A'..'D' per question
     */
    public function png(array $sheets, int $questionCount): string
    {
        $sheetH = 150 + $questionCount * self::ROW_H + 30;
        $height = count($sheets) * $sheetH + 20;

        $img = imagecreatetruecolor(self::WIDTH, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 25, 25, 30);
        $faint = imagecolorallocate($img, 150, 150, 155);
        imagefilledrectangle($img, 0, 0, self::WIDTH, $height, $white);

        $y = 20;
        foreach ($sheets as $sheet) {
            $this->drawSheet($img, $sheet, $questionCount, $y, $ink, $faint);
            $y += $sheetH;
            imageline($img, 20, $y - 15, self::WIDTH - 20, $y - 15, $faint);
        }

        ob_start();
        imagepng($img);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    /** @param array{name: string, answers: list<string>} $sheet */
    private function drawSheet($img, array $sheet, int $questionCount, int $top, int $ink, int $faint): void
    {
        $this->text($img, 40, $top + 10, 'QUIZ ANSWER SHEET', 2, $faint);
        $this->text($img, 40, $top + 45, 'Name: '.$sheet['name'], 4, $ink);

        for ($i = 0; $i < $questionCount; $i++) {
            $rowY = $top + 150 + $i * self::ROW_H;
            $this->text($img, 40, $rowY - 12, ($i + 1).'.', 3, $ink);

            $chosen = strtoupper((string) ($sheet['answers'][$i] ?? ''));
            foreach (self::BUBBLE_XS as $j => $cx) {
                $letter = chr(65 + $j);
                $isChosen = $letter === $chosen;
                if ($isChosen) {
                    imagefilledellipse($img, $cx, $rowY, 46, 46, $ink);
                } else {
                    imageellipse($img, $cx, $rowY, 46, 46, $ink);
                    imageellipse($img, $cx, $rowY, 45, 45, $ink);
                }
                $this->text($img, $cx - 8, $rowY + 30, $letter, 2, $faint);
            }
        }
    }

    /** Draw a string using the built-in font scaled up (no TTF dependency). */
    private function text($img, int $x, int $y, string $s, int $scale, int $color): void
    {
        $font = 5;
        $w = max(1, imagefontwidth($font) * strlen($s));
        $h = imagefontheight($font);

        $tmp = imagecreatetruecolor($w, $h);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $transparent);

        [$r, $g, $b] = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
        $tc = imagecolorallocate($tmp, $r, $g, $b);
        imagestring($tmp, $font, 0, 0, $s, $tc);

        $scaled = imagescale($tmp, $w * $scale, $h * $scale, IMG_NEAREST_NEIGHBOUR);
        imagecopy($img, $scaled, $x, $y, 0, 0, $w * $scale, $h * $scale);

        imagedestroy($tmp);
        imagedestroy($scaled);
    }
}
