<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TimeMapVoyagesTest extends TestCase
{
    public function test_voyages_data_is_well_formed(): void
    {
        $data = json_decode(File::get(resource_path('js/timemap/voyages.json')), true);
        $this->assertIsArray($data);
        $voyages = $data['voyages'] ?? null;
        $this->assertIsArray($voyages);
        $this->assertGreaterThanOrEqual(5, count($voyages));

        foreach ($voyages as $v) {
            $id = $v['id'] ?? '(missing id)';
            $this->assertMatchesRegularExpression('/^Q\d+$/', $v['qid'], $id);
            $this->assertNotSame('', trim($v['name']), $id);
            $this->assertNotSame('', trim($v['years']), $id);

            // Sane chronology, and the visibility window must cover the voyage itself.
            $this->assertLessThanOrEqual($v['year_end'], $v['year_start'], $id);
            $this->assertLessThanOrEqual($v['year_start'], $v['show_from'], $id);
            $this->assertGreaterThanOrEqual($v['year_end'], $v['show_to'], $id);

            $points = $v['waypoints'] ?? [];
            $this->assertGreaterThanOrEqual(4, count($points), $id);
            foreach ($points as $p) {
                $this->assertCount(2, $p, $id);
                $this->assertIsNumeric($p[0], $id);
                $this->assertIsNumeric($p[1], $id);
                // Longitudes may extend past +/-180 for antimeridian continuity; latitudes never.
                $this->assertGreaterThanOrEqual(-90, $p[1], $id);
                $this->assertLessThanOrEqual(90, $p[1], $id);
            }

            // The label anchor must point at a real waypoint.
            if (isset($v['label_at'])) {
                $this->assertArrayHasKey($v['label_at'], $points, $id);
            }
        }
    }
}
