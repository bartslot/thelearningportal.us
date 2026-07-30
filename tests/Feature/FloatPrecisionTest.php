<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the one-line fix in AppServiceProvider.
 *
 * SiteGround's PHP sets serialize_precision=100, which makes json_encode() emit the full binary
 * expansion of every float. On the lesson player that turned 0.081 into 57 digits and added ~445 KB
 * to a single page; it also bloats every JSON response the Flutter app reads. Local PHP happens to
 * ship -1, so this only ever showed up in production — hence a test rather than a note.
 */
class FloatPrecisionTest extends TestCase
{
    public function test_the_app_pins_serialize_precision_so_floats_stay_short(): void
    {
        $this->assertSame('-1', ini_get('serialize_precision'));
    }

    public function test_a_narration_timing_encodes_as_a_short_number(): void
    {
        // The exact value that appeared as 0.081000000000000002553512956637860042974352836608886719 live.
        $encoded = json_encode(['start_time' => 0.081, 'end_time' => 0.104]);

        $this->assertSame('{"start_time":0.081,"end_time":0.104}', $encoded);
    }

    public function test_it_survives_a_host_that_sets_a_wide_precision(): void
    {
        $original = ini_get('serialize_precision');

        try {
            ini_set('serialize_precision', '100');
            $this->assertNotSame('0.081', json_encode(0.081), 'sanity: a wide precision really does inflate');

            // Re-registering the provider is what a real request does on such a host.
            (new \App\Providers\AppServiceProvider($this->app))->register();

            $this->assertSame('-1', ini_get('serialize_precision'));
            $this->assertSame('0.081', json_encode(0.081));
        } finally {
            ini_set('serialize_precision', (string) $original);
        }
    }
}
