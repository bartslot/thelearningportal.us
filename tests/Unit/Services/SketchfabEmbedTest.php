<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EmbedParser;
use App\Services\SketchfabService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Picking a 3D model, and how it behaves once it is on the slide.
 *
 * A model used to arrive through a browser prompt asking for a pasted link, with its viewer
 * settings hard-coded. Both halves are now the teacher's: the model comes from a search in our own
 * modal, and interaction / spin / backdrop are switches.
 */
class SketchfabEmbedTest extends TestCase
{
    private const UID = '09bb5c6b25c6493ea63f0e4c5a4d0b53';

    private function query(string $src): array
    {
        parse_str((string) parse_url($src, PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_a_model_arrives_cut_out_of_its_studio_backdrop(): void
    {
        // The default matters: the point of the layer is the object, not the room it was lit in.
        $q = $this->query((new EmbedParser)->sketchfabSrc(self::UID));

        $this->assertSame('1', $q['transparent']);
        $this->assertSame('1', $q['ui_controls'], 'a model should be grabbable unless told otherwise');
        $this->assertSame('0.2', $q['autospin']);
    }

    public function test_interaction_off_disables_the_controls_and_the_wheel(): void
    {
        $q = $this->query((new EmbedParser)->sketchfabSrc(self::UID, ['interact' => false]));

        $this->assertSame('0', $q['ui_controls']);
        $this->assertSame('0', $q['scrollwheel'], 'the wheel belongs to the page when the model is inert');
    }

    public function test_turning_by_itself_can_be_stopped(): void
    {
        $this->assertSame('0', $this->query((new EmbedParser)->sketchfabSrc(self::UID, ['autospin' => false]))['autospin']);
    }

    public function test_glass_still_cuts_the_model_out(): void
    {
        // Glass is frosting on OUR side of the iframe, so the model itself stays transparent.
        $q = $this->query((new EmbedParser)->sketchfabSrc(self::UID, ['bg' => 'glass']));

        $this->assertSame('1', $q['transparent']);
        $this->assertArrayNotHasKey('ui_color', $q);
    }

    public function test_a_solid_colour_paints_the_viewer_instead_of_cutting_out(): void
    {
        $q = $this->query((new EmbedParser)->sketchfabSrc(self::UID, ['bg' => '#0f172a']));

        $this->assertArrayNotHasKey('transparent', $q);
        $this->assertSame('0f172a', $q['ui_color']);
    }

    public function test_the_chrome_is_always_off(): void
    {
        $q = $this->query((new EmbedParser)->sketchfabSrc(self::UID));

        foreach (['ui_infos', 'ui_hint', 'ui_help', 'ui_settings', 'ui_watermark', 'ui_annotations'] as $off) {
            $this->assertSame('0', $q[$off], "{$off} must not clutter a lesson slide");
        }
        $this->assertSame('1', $q['dnt'], 'do-not-track is on for a classroom');
    }

    public function test_search_maps_results_to_what_a_picker_tile_needs(): void
    {
        Http::fake(['api.sketchfab.com/*' => Http::response(['results' => [[
            'uid' => self::UID,
            'name' => 'Trireme',
            'user' => ['displayName' => 'ThomasBeerens'],
            'license' => ['label' => 'CC Attribution'],
            'thumbnails' => ['images' => [
                ['width' => 2048, 'url' => 'https://x/huge.jpg'],
                ['width' => 720, 'url' => 'https://x/right.jpg'],
                ['width' => 200, 'url' => 'https://x/small.jpg'],
            ]],
            'viewerUrl' => 'https://sketchfab.com/models/'.self::UID,
        ]]], 200)]);

        $rows = (new SketchfabService)->search('trireme');

        $this->assertCount(1, $rows);
        $this->assertSame('Trireme', $rows[0]['name']);
        $this->assertSame('ThomasBeerens', $rows[0]['author'], 'attribution travels with the tile');
        $this->assertSame('https://x/right.jpg', $rows[0]['thumb'], 'the widest thumb under 1000px');
    }

    public function test_a_result_without_an_embeddable_id_is_dropped(): void
    {
        Http::fake(['api.sketchfab.com/*' => Http::response(['results' => [
            ['uid' => 'not-a-model-id', 'name' => 'Broken', 'thumbnails' => ['images' => []]],
        ]], 200)]);

        $this->assertSame([], (new SketchfabService)->search('anything'));
    }

    /** The same trap the Commons cache fell into: a throttled minute must not become a lasting "none". */
    public function test_a_throttled_search_is_reported_and_never_cached(): void
    {
        Http::fake(['api.sketchfab.com/*' => Http::response('', 429)]);

        $service = new SketchfabService;

        $this->assertSame([], $service->search('trireme'));
        $this->assertSame('throttled', $service->lastError);
        $this->assertNull(Cache::get('sketchfab.search.v1.'.md5('trireme').'.24'));
    }
}
