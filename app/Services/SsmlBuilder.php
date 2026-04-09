<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Avatar;

class SsmlBuilder
{
    private const ALLOWED_EMOTIONS = [
        'serious', 'cheerful', 'excited', 'empathetic', 'whispering', 'narrative',
    ];

    public function build(string $script, Avatar $avatar): string
    {
        $voice = $this->resolveVoice($avatar);
        $rate  = number_format((float) $avatar->speaking_speed, 2);
        $inner = $avatar->emotion_style === 'auto'
            ? $this->parseEmotionTags($script, (float) $avatar->expressiveness)
            : $this->wrapEntire($script, $avatar->emotion_style, (float) $avatar->expressiveness);

        return implode('', [
            '<speak version="1.0"',
            ' xmlns="http://www.w3.org/2001/10/synthesis"',
            ' xmlns:mstts="https://www.w3.org/2001/mstts"',
            ' xml:lang="en-US">',
            "<voice name=\"{$voice}\">",
            '<mstts:viseme type="FacialExpression"/>',
            "<prosody rate=\"{$rate}\">",
            $inner,
            '</prosody>',
            '</voice>',
            '</speak>',
        ]);
    }

    private function wrapEntire(string $script, string $style, float $expressiveness): string
    {
        $degree = number_format($expressiveness, 2);
        $text   = htmlspecialchars($script, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<mstts:express-as style=\"{$style}\" styledegree=\"{$degree}\">{$text}</mstts:express-as>";
    }

    private function parseEmotionTags(string $script, float $expressiveness): string
    {
        $joined = implode('|', self::ALLOWED_EMOTIONS);
        $degree = number_format($expressiveness, 2);

        // Split on emotion tag boundaries, preserving the delimiters
        $pattern = '/(\[(?:' . $joined . ')\].*?\[\/(?:' . $joined . ')\])/s';
        $parts   = preg_split($pattern, $script, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tagRe   = '/^\[(' . $joined . ')\](.*?)\[\/\1\]$/s';
        $output  = '';

        foreach ($parts as $part) {
            if (preg_match($tagRe, $part, $m)) {
                $text    = htmlspecialchars(trim($m[2]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $output .= "<mstts:express-as style=\"{$m[1]}\" styledegree=\"{$degree}\">{$text}</mstts:express-as>";
            } else {
                $output .= htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        }

        return $output;
    }

    private function resolveVoice(Avatar $avatar): string
    {
        $age    = (int) $avatar->age;
        $gender = $avatar->gender;

        if ($gender === 'female') {
            return match (true) {
                $age <= 17 => 'en-US-AnaNeural',
                $age <= 60 => 'en-US-JennyNeural',
                default    => 'en-US-NancyNeural',
            };
        }

        return match (true) {
            $age <= 17 => 'en-US-BrandonNeural',
            $age <= 60 => 'en-US-GuyNeural',
            default    => 'en-US-RogerNeural',
        };
    }
}
