<?php

declare(strict_types=1);

namespace App\Services\Support;

final class ImageStyleTemplate
{
    public const SAFETY_GUARDRAIL = 'no children, no real living people, no readable text in image';

    /**
     * Equirectangular panorama spec — drives gpt-image-1 toward something usable as a
     * VR skybox texture on an inverted sphere. The model can't natively output 2:1, but
     * we tell it the composition rules so the chosen landscape framing still wraps.
     */
    public const PANORAMIC_HINT = '360 degree panoramic scene, equirectangular projection, '
        . 'immersive VR panorama, framed inside a 2:1 ultra-wide aspect ratio. '
        . 'Render the scene in a wide 2:1 letterbox: the panorama fills the middle '
        . 'horizontal band edge-to-edge, with solid pure black bars above and below '
        . 'so the overall canvas remains the requested square or 3:2 frame. '
        . 'Horizon line level and centered vertically within the panorama band. '
        . 'Seamless continuity between the left and right edges, '
        . 'no subjects or hard edges crossing the seam, '
        . 'no fisheye or lens distortion, no cropped foreground objects, '
        . 'environmental establishing shot only, no close-up subjects, no people visible, '
        . 'consistent ambient lighting from all directions, '
        . 'sky visible above the horizon band, ground visible below, '
        . 'ultra detailed';

    public const GAME_HINT = 'battle/scene illustration, dim mid-tones suitable for overlaid UI, no clutter';

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
