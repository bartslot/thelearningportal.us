<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class ExportSignificantTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_export_includes_significant_and_excludes_insignificant(): void
    {
        $this->seedBoundary(['polity_id' => 'rome', 'name' => 'Roman Republic', 'valid_from' => -509, 'valid_to' => -27], 11, 41, 13, 43);
        $this->seedBoundary(['polity_id' => 'steppe', 'name' => 'Steppe cultures', 'valid_from' => -509, 'valid_to' => -27], 40, 48, 60, 55);
        $this->seedPolity(['polity_id' => 'rome', 'label' => 'Roman Republic', 'significant' => true, 'inception' => -509, 'dissolution' => -27, 'flag_path' => '/flags/rome.png']);
        $this->seedPolity(['polity_id' => 'steppe', 'label' => 'Steppe cultures', 'significant' => false]);

        $this->artisan('timemap:export-boundaries')->assertExitCode(0);

        $boundaries = json_decode(File::get(public_path('geo/boundaries/-500.geojson')), true);
        $ids = array_column(array_column($boundaries['features'], 'properties'), 'polity_id');
        $this->assertContains('rome', $ids);
        $this->assertNotContains('steppe', $ids);

        $labels = json_decode(File::get(public_path('geo/labels/-500.geojson')), true);
        $romeLabel = collect($labels['features'])->firstWhere('properties.polity_id', 'rome');
        $this->assertSame('Roman Republic', $romeLabel['properties']['label']);
        $this->assertSame('509 BCE – 27 BCE', $romeLabel['properties']['date_label']);
        $this->assertSame('Point', $romeLabel['geometry']['type']);
    }
}
