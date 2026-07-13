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
        // Founder-picked identity style (2026-07-10, validated on gpt-image-1: era-accurate, no
        // style→content bleed, upscales near-losslessly). Reference: Vagabond-like sumi-e manga.
        'ink' => 'Japanese ink brush illustration, sumi-e manga style: rough expressive dry-brush '
            .'strokes for clothing, hair and large masses; thin precise fine-line work for faces, '
            .'hands and details; monochrome black ink on warm off-white paper, high contrast, '
            .'subtle paper grain, no color',
        // Second founder identity candidate (2026-07-10, validated on gpt-image-1): copperplate
        // engraving with dense crosshatching — pairs with the ultrasharp-4x upscale model.
        'etching' => 'antique copperplate engraving, etching style: dense crosshatching, fine '
            .'parallel hatch lines that curve with the form, deep tonal build-up from layered '
            .'hatching, monochrome black ink on aged warm paper, no color',
        // Two-pass engraving: the model draws figures/tone in ink style; HatchEngraver then
        // draws the crosshatching deterministically (sharp lines, exact paper colour).
        'engraved' => 'Japanese ink brush illustration, sumi-e manga style: rough expressive '
            .'dry-brush strokes for clothing, hair and large masses; thin precise fine-line work '
            .'for faces, hands and details; monochrome black ink on warm off-white paper, high '
            .'contrast, subtle paper grain, no color',
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

    /**
     * Storyboard-grid prompt: ONE generated image containing rows×cols distinct panels of
     * the same scene, later sliced into per-shot cells by GridSlicer. Panel descriptions come
     * from the ShotListPrompt storyboard; the validated visual/avoid data keeps them accurate.
     *
     * @param  list<string>  $panelDescriptions  ordered left-to-right, top-to-bottom
     * @param  array<string, mixed>  $validation  HistoricalImageValidationPrompt output (or [])
     */
    public static function buildShotGrid(array $panelDescriptions, array $validation, string $style, int $rows, int $cols): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];
        $count = count($panelDescriptions);

        $panels = implode(' ', array_map(
            fn (string $description, int $index) => 'Panel '.($index + 1).': '.trim($description).'.',
            $panelDescriptions,
            array_keys($panelDescriptions),
        ));

        $avoidList = array_merge(
            $validation['anachronismsToAvoid'] ?? [],
            ['people in close-up', 'faces', 'readable text'],
        );
        $avoidStr = 'avoid: '.implode(', ', array_unique($avoidList));

        $gridSpec = "A strict {$rows}x{$cols} storyboard grid of {$count} panels, equal-size cells, "
            .'hard straight cell edges aligned to an exact grid, NO borders, NO gutters, NO frames, '
            .'NO text, NO numbers. Every panel depicts the SAME historical scene in the same '
            .'consistent art style, palette and lighting, each from a different composition. '
            // Founder direction 2026-07-10: every focal point on a thirds intersection.
            .'Compose EVERY panel on the rule of thirds: the focal subject sits on a third-line '
            .'intersection (never centered), horizons lie on a horizontal third line.';

        $parts = array_filter([
            $gridSpec,
            $panels,
            $validation['recommendedScene'] ?? null,
            $styleClause,
            self::SAFETY_GUARDRAIL,
            $avoidStr,
            self::NEGATIVE_PROMPT,
        ]);

        return implode('. ', $parts);
    }

    /** @return string[] */
    public static function styles(): array
    {
        return array_keys(self::STYLES);
    }

    /** Bare style clause for callers that compose their own prompt (e.g. hero cut-outs). */
    public static function styleClause(string $style): string
    {
        return self::STYLES[$style] ?? self::STYLES['realistic'];
    }
}
