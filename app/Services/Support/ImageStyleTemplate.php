<?php

declare(strict_types=1);

namespace App\Services\Support;

final class ImageStyleTemplate
{
    public const SAFETY_GUARDRAIL = 'no children, no real living people, no readable text in image';
    public const PANORAMIC_HINT   = '2:1 wide aspect, panoramic composition, ambient periphery';
    public const GAME_HINT        = 'battle/scene illustration, dim mid-tones suitable for overlaid UI, no clutter';

    private const STYLES = [
        'realistic' => 'photographic, period-accurate, natural light, shallow DOF, people in period clothing',
        'sketched'  => 'pencil ink sketch, hatching, period dress, sepia tones',
        'painted'   => 'oil painting, romantic era, visible brushstrokes, dramatic light',
        'cinematic' => 'film still, anamorphic, dusk lighting, color graded',
        'comic'     => 'bold ink outlines, flat color panels, halftone shading',
        'animation' => 'stylized 3D animation, soft lighting, expressive characters',
    ];

    public static function build(string $seedPrompt, string $style, bool $isGame = false): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];
        $parts       = [
            trim($seedPrompt),
            $styleClause,
            self::PANORAMIC_HINT,
            $isGame ? self::GAME_HINT : null,
            self::SAFETY_GUARDRAIL,
        ];

        return implode('. ', array_filter($parts));
    }

    /** @return string[] */
    public static function styles(): array
    {
        return array_keys(self::STYLES);
    }
}
