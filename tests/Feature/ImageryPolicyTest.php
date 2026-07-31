<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SceneImageSourcer;
use Illuminate\Support\Facades\Http;
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

    public function test_a_map_is_never_chosen_as_a_scene_backdrop(): void
    {
        // The real result that prompted this: searching the topic "The Roman Republic" returned a
        // tribal map ahead of every painting, because its title matches the words perfectly.
        Http::fake(['commons.wikimedia.org/*' => Http::response($this->commonsPages([
            $this->commonsFile('File:Roman_republic,_tribes_241_BC.png', 3000, 2000),
            $this->commonsFile('File:Roman_forum_by_Canaletto.jpg', 2400, 1500, 'Canaletto'),
        ]))]);

        $hit = (new SceneImageSourcer)->fromCommons('roman republic forum', -100);

        $this->assertNotNull($hit);
        $this->assertStringNotContainsString('tribes', $hit['title'], 'A map was chosen as a backdrop.');
        $this->assertStringContainsString('Canaletto', $hit['title']);
    }

    public function test_a_pre_photography_scene_prefers_a_painting_over_a_modern_photo(): void
    {
        // Both files are genuinely "the Roman Forum". Only one shows it as the lesson describes it.
        Http::fake(['commons.wikimedia.org/*' => Http::response($this->commonsPages([
            $this->commonsFile('File:Columns,_Roman_Forum_(45485188755).jpg', 3840, 2160, 'Sonse'),
            $this->commonsFile('File:Roman_Forum_painting_by_Pannini.jpg', 2000, 1400, 'Giovanni Paolo Pannini'),
        ]))]);

        $sourcer = new SceneImageSourcer;

        $ancient = $sourcer->fromCommons('roman forum', -100);
        $this->assertStringContainsString('painting', $ancient['title'], 'An ancient scene took a modern photo.');

        // With no era to go on, the bigger file still wins — the preference is era-driven, not a
        // blanket dislike of photographs.
        $undated = $sourcer->fromCommons('roman forum', null);
        $this->assertStringContainsString('Columns', $undated['title']);
    }

    /** @param  list<array<string,mixed>>  $files */
    private function commonsPages(array $files): array
    {
        return ['query' => ['pages' => array_combine(range(1, count($files)), $files)]];
    }

    /** @return array<string,mixed> */
    private function commonsFile(string $title, int $w, int $h, string $artist = 'Wikimedia Commons'): array
    {
        return [
            'title' => $title,
            'imageinfo' => [[
                'width' => $w,
                'height' => $h,
                'thumburl' => 'https://upload.wikimedia.org/'.rawurlencode($title).'.jpg',
                'url' => 'https://upload.wikimedia.org/'.rawurlencode($title),
                'extmetadata' => [
                    'Artist' => ['value' => $artist],
                    'LicenseShortName' => ['value' => 'public domain'],
                ],
            ]],
        ];
    }
}
