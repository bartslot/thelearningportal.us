<?php

declare(strict_types=1);

namespace App\Services\Support;

final class ImageStyleTemplate
{
    public const SAFETY_GUARDRAIL = 'no children, no real living people, no readable text in image';

    /**
     * Flat 2D image hint — used for the initial Slideshow image.
     * Wide establishing shot with depth; objects kept at mid-to-far distance.
     */
    public const FLAT_HINT = 'wide establishing shot, cinematic landscape framing, '
        .'subjects and objects at mid-ground to far distance, '
        .'nothing in extreme foreground filling the frame, '
        .'sense of depth and space, open vista';

    /**
     * Technical equirectangular panorama spec — appended to every skybox prompt.
     * Fixed suffix, never modified.
     */
    public const SKYBOX_TECHNICAL_SUFFIX = 'Technical skybox requirements: '
        .'true equirectangular latitude-longitude panorama (not perspective, not fisheye, not cubemap cross), '
        .'360-degree horizontal view, 180-degree vertical view, '
        .'strict 2:1 aspect ratio, seamless left-right edge continuity, level horizon at center, '
        .'camera at scene center with coherent geometry in all directions, no seam break artifacts, '
        .'no fisheye distortion, no cropped foreground objects, '
        .'no close objects at the panorama seam, consistent lighting in all directions, '
        .'realistic scale, suitable for use as a spherical VR skybox';

    /**
     * Universal negative prompt — appended to every image prompt (flat and skybox).
     */
    public const NEGATIVE_PROMPT = 'Avoid: people, faces, modern objects, modern buildings, '
        .'cars, bicycles, motorcycles, electric lamps, power lines, antennas, asphalt, '
        .'plastic, glass skyscrapers, modern road signs, readable text, logos, '
        .'fantasy elements, sci-fi elements, inaccurate monuments, '
        .'distorted perspective, duplicated architecture, warped buildings, '
        .'broken horizon, fisheye lens, black borders, frame, watermark';

    public const GAME_HINT = 'battle/scene illustration, dim mid-tones suitable for overlaid UI, no clutter';

    private const STYLES = [
        'realistic' => 'photographic, period-accurate, natural light, shallow DOF',
        'sketched' => 'pencil ink sketch, hatching, period dress, sepia tones',
        'painted' => 'oil painting, romantic era, visible brushstrokes, dramatic light',
        'cinematic' => 'film still, anamorphic, dusk lighting, color graded',
        'comic' => 'bold ink outlines, flat color panels, halftone shading',
        'animation' => 'stylized 3D animation, soft lighting, expressive characters',
    ];

    /**
     * Standard flat 2D image — Slideshow view.
     * Uses the raw seed prompt without historical validation.
     */
    public static function build(string $seedPrompt, string $style, bool $isGame = false): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];
        $parts = array_filter([
            trim($seedPrompt),
            $styleClause,
            self::FLAT_HINT,
            $isGame ? self::GAME_HINT : null,
            self::SAFETY_GUARDRAIL,
            self::NEGATIVE_PROMPT,
        ]);

        return implode('. ', $parts);
    }

    /**
     * Build a flat image prompt from a validated historical scene.
     * Uses only confirmed-accurate visuals from the validation step.
     *
     * @param array{
     *   recommendedScene: string,
     *   accurateVisuals: string[],
     *   anachronismsToAvoid: string[],
     *   historicalPeriod: string,
     *   location: string
     * } $validation
     */
    public static function buildFromValidated(array $validation, string $style, bool $isGame = false): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];

        $scene = $validation['recommendedScene'] ?? '';
        $visuals = implode(', ', $validation['accurateVisuals'] ?? []);
        $avoidList = array_merge(
            $validation['anachronismsToAvoid'] ?? [],
            ['people', 'faces', 'readable text'],
        );
        $avoidStr = 'avoid: '.implode(', ', array_unique($avoidList));

        $parts = array_filter([
            $scene,
            $visuals ?: null,
            $styleClause,
            self::FLAT_HINT,
            $isGame ? self::GAME_HINT : null,
            self::SAFETY_GUARDRAIL,
            $avoidStr,
            self::NEGATIVE_PROMPT,
        ]);

        return implode('. ', $parts);
    }

    /**
     * Equirectangular panorama — used by GenerateSkyboxImage.
     * Appends the technical skybox suffix and negative prompt.
     */
    public static function buildSkybox(string $seedPrompt, string $style): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];
        $parts = array_filter([
            trim($seedPrompt),
            $styleClause,
            self::SAFETY_GUARDRAIL,
            self::SKYBOX_TECHNICAL_SUFFIX,
            self::NEGATIVE_PROMPT,
        ]);

        return implode('. ', $parts);
    }

    /**
     * Build a skybox prompt from validated historical scene data.
     *
     * @param array{
     *   recommendedScene: string,
     *   accurateVisuals: string[],
     *   anachronismsToAvoid: string[],
     *   historicalPeriod: string,
     *   location: string
     * } $validation
     */
    public static function buildSkyboxFromValidated(array $validation, string $style): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];

        $scene = $validation['recommendedScene'] ?? '';
        $visuals = implode(', ', $validation['accurateVisuals'] ?? []);
        $avoidList = array_merge(
            $validation['anachronismsToAvoid'] ?? [],
            ['people', 'faces', 'readable text'],
        );
        $avoidStr = 'avoid: '.implode(', ', array_unique($avoidList));

        $parts = array_filter([
            $scene,
            $visuals ?: null,
            $styleClause,
            self::SAFETY_GUARDRAIL,
            $avoidStr,
            self::SKYBOX_TECHNICAL_SUFFIX,
            self::NEGATIVE_PROMPT,
        ]);

        return implode('. ', $parts);
    }

    /** @return string[] */
    public static function styles(): array
    {
        return array_keys(self::STYLES);
    }
}
