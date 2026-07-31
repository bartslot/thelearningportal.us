<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Scene imagery comes from our paintings corpus and Wikimedia Commons, not from an image model.
 * Generating costs money per scene and, for history, is worse: a real painting carries an artist,
 * a date and a credit; an invented one carries none of that.
 */
class ImageryPolicyTest extends TestCase
{
    public function test_ai_image_generation_is_off_by_default(): void
    {
        $this->assertFalse(
            (bool) config('services.imagery.ai_generation'),
            'AI image generation must default to OFF — it is billed per image.',
        );
    }

    public function test_every_paid_image_job_is_gated_by_the_switch(): void
    {
        // A new job that calls an image model without checking this would silently start billing.
        $jobs = [
            'GenerateSceneImage.php',
            'GenerateSkyboxImage.php',
            'GenerateSkyboxCandidates.php',
        ];

        foreach ($jobs as $job) {
            $src = file_get_contents(app_path('Jobs/'.$job));
            $this->assertStringContainsString(
                'imagery.ai_generation',
                $src,
                "{$job} calls an image model without checking services.imagery.ai_generation.",
            );
        }
    }
}
