<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Models\User;
use App\Services\LessonComposer;
use App\Services\LessonExporter;
use App\Services\SceneImageSourcer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * `lessons:export` only earns its keep if what comes back out rebuilds into the same lesson —
 * otherwise deploying a lesson to production quietly degrades it. The round trip is the test.
 */
class LessonExportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        Storage::fake('public');
        Http::fake(['*' => Http::response('fake-image-bytes')]);
    }

    /** A spec covering every scene kind the composer can build, already in exported form. */
    private function spec(string $key = 'Round trip'): array
    {
        return [
            'key' => $key,
            'title' => 'Round trip',
            'subject' => 'history',
            'grade_level' => '6',
            'tone' => 'storytelling',
            'scenes' => [
                [
                    'type' => 'quiz',
                    'when' => 'pre',
                    'chapter' => 'What do you already know?',
                    'questions' => [
                        ['q' => 'When?', 'o' => ['1600', '1700'], 'c' => 1, 'e' => 'The later one.'],
                    ],
                ],
                [
                    'type' => 'story',
                    'chapter' => 'A beginning',
                    'location' => 'Delft',
                    'year' => 1652,
                    'image' => [
                        'url' => 'https://example.test/painting.jpg',
                        'credit' => 'Rijksmuseum — CC0',
                        'focus' => 'center',
                    ],
                    'script' => 'It started here.',
                ],
                [
                    'type' => 'gallery',
                    'chapter' => 'Pictures',
                    'location' => 'Zeeland',
                    'year' => 1953,
                    'title' => 'The night',
                    'date_label' => '1 February 1953',
                    'story' => 'The water came in.',
                    'fit' => 'cover',
                    'images' => [
                        ['url' => 'https://example.test/one.jpg', 'credit' => 'Anefo — CC0'],
                        ['url' => 'https://example.test/two.jpg', 'credit' => 'Nationaal Archief'],
                    ],
                    'script' => 'Look at these.',
                ],
                [
                    'type' => 'map',
                    'chapter' => 'Where',
                    'location' => 'The Netherlands',
                    'year' => 1953,
                    'qid' => 'Q55',
                    'projection' => 'mercator',
                    'script' => 'Look south west.',
                    'labels' => [
                        ['Zeeland', 3.8, 51.6],
                        ['Rotterdam', 4.15, 51.95],
                    ],
                ],
                [
                    'type' => '3d',
                    'sketchfab' => 'https://sketchfab.com/models/68e57a00049a4530a5c865762e33d327/embed',
                    'chapter' => 'The barrier',
                    'location' => 'Oosterscheldekering',
                    'script' => 'Nine kilometres long.',
                ],
                [
                    'type' => 'video',
                    'youtube' => 'https://www.youtube.com/watch?v=q2CGpSuFhMU',
                    'chapter' => 'In two minutes',
                    'script' => 'Watch this.',
                    'fit' => 'fit',
                    'controls' => true,
                    'autoplay' => true,
                ],
                [
                    'type' => 'quiz',
                    'when' => 'post',
                    'chapter' => 'What did you learn?',
                    'questions' => [
                        ['q' => 'Why?', 'o' => ['Storm', 'Earthquake'], 'c' => 0, 'e' => 'A storm at spring tide.'],
                    ],
                ],
            ],
        ];
    }

    public function test_a_composed_lesson_exports_back_to_the_spec_that_built_it(): void
    {
        $spec = $this->spec();

        $lesson = app(LessonComposer::class)->build($spec, $this->teacher, narrate: false);
        $exported = app(LessonExporter::class)->toSpec($lesson->fresh());

        $this->assertEquals($spec, $exported);
    }

    public function test_the_exported_spec_rebuilds_into_an_identical_lesson(): void
    {
        $composer = app(LessonComposer::class);
        $exporter = app(LessonExporter::class);

        $first = $composer->build($this->spec(), $this->teacher, narrate: false);
        $exported = $exporter->toSpec($first->fresh());

        // Rebuild under a different key so it lands as a second lesson, then export that.
        $rebuilt = $composer->build(array_merge($exported, ['key' => 'Copy']), $this->teacher, narrate: false);
        $roundTripped = $exporter->toSpec($rebuilt->fresh());

        $this->assertNotSame($first->id, $rebuilt->id);
        $this->assertEquals($exported['scenes'], $roundTripped['scenes']);
    }

    public function test_an_already_chosen_image_is_pinned_by_url_and_never_re_searched(): void
    {
        // Re-running the original search query would be a fresh roll of the dice against Commons
        // and could swap out a picture a teacher already approved.
        $sourcer = Mockery::mock(SceneImageSourcer::class);
        $sourcer->shouldNotReceive('find');
        $sourcer->shouldReceive('download')->andReturn('lessons/1/scenes/1/bg.jpg');
        $this->instance(SceneImageSourcer::class, $sourcer);

        $lesson = app(LessonComposer::class)->build($this->spec(), $this->teacher, narrate: false);

        $story = $lesson->scenes()->where('kind', 'narration')->whereNotNull('image_path')->first();

        $this->assertSame('https://example.test/painting.jpg', $story->config['image_source_url']);
        $this->assertSame('Rijksmuseum — CC0', $story->config['image_credit']);
    }

    public function test_wizard_config_the_composer_does_not_author_survives_a_rebuild(): void
    {
        $spec = $this->spec();
        $spec['scenes'][1]['extra_config'] = ['transition' => 'fade', 'parallax' => true];
        $spec['scenes'][1]['shots'] = [['art_1' => ['x' => 0.5, 'y' => 0.25]]];

        $lesson = app(LessonComposer::class)->build($spec, $this->teacher, narrate: false);
        $scene = $lesson->scenes()->where('order', 2)->first();

        $this->assertSame('fade', $scene->config['transition']);
        $this->assertTrue($scene->config['parallax']);
        $this->assertSame([['art_1' => ['x' => 0.5, 'y' => 0.25]]], $scene->shots);

        // …and comes back out again, so the next export does not drop it either.
        $exported = app(LessonExporter::class)->toSpec($lesson->fresh());

        $this->assertSame(['transition' => 'fade', 'parallax' => true], $exported['scenes'][1]['extra_config']);
        $this->assertSame($spec['scenes'][1]['shots'], $exported['scenes'][1]['shots']);
    }

    public function test_a_voyage_keeps_its_edited_route_across_a_rebuild(): void
    {
        // The voyage CMS edits a per-lesson copy of the route in game_config. A rebuild that
        // ignored it would snap the ship back to the catalogue's default track.
        $spec = [
            'key' => 'Voyage',
            'title' => 'Voyage',
            'subject' => 'history',
            'grade_level' => '6',
            'tone' => 'storytelling',
            'game_config' => [
                'voyage' => 'tasman-1642',
                'voyage_def' => ['id' => 'tasman-1642', 'waypoints' => [[106.9, -5.9], [105.85, -6.15]]],
            ],
            'scenes' => [[
                'type' => 'voyage',
                'chapter' => 'Departure',
                'location' => 'Batavia',
                'year' => 1642,
                'voyage' => 'tasman-1642',
                'leg' => 0,
                'view' => 'flat',
                'title' => 'Batavia',
                'images' => [['url' => 'https://example.test/ship.jpg', 'credit' => 'Rijksmuseum']],
                'script' => 'They set sail.',
            ]],
        ];

        $lesson = app(LessonComposer::class)->build($spec, $this->teacher, narrate: false);
        $exported = app(LessonExporter::class)->toSpec($lesson->fresh());

        $this->assertSame('voyage', $lesson->game_type);
        $this->assertEquals($spec['game_config'], $exported['game_config']);
        // The map has one look now (satellite, 3D ground, tilted camera), so a spec no longer
        // carries a palette or a relief number — there is nothing left for it to choose.
        $this->assertArrayNotHasKey('map_style', $exported);
        $this->assertArrayNotHasKey('map_relief', $exported);
    }

    public function test_editor_undo_history_is_left_out_of_the_spec(): void
    {
        $lesson = app(LessonComposer::class)->build([
            'key' => 'Scratch',
            'title' => 'Scratch',
            'game_config' => ['voyage' => 'tasman-1642', 'voyage_undo' => [['waypoints' => []]]],
            'scenes' => [['type' => 'story', 'chapter' => 'One', 'script' => 'Hello.']],
        ], $this->teacher, narrate: false);

        $exported = app(LessonExporter::class)->toSpec($lesson->fresh());

        $this->assertSame(['voyage' => 'tasman-1642'], $exported['game_config']);
    }

    public function test_a_background_with_no_source_url_is_reported_rather_than_silently_dropped(): void
    {
        $lesson = app(LessonComposer::class)->build([
            'key' => 'Uploaded',
            'title' => 'Uploaded',
            'scenes' => [['type' => 'story', 'chapter' => 'One', 'script' => 'Hello.']],
        ], $this->teacher, narrate: false);

        // Stand in for a wizard upload: a file in our own storage, with no public source.
        $lesson->scenes()->first()->update(['image_path' => 'lessons/9/scenes/9/upload.png', 'config' => []]);

        $exporter = app(LessonExporter::class);
        $exported = $exporter->toSpec($lesson->fresh());

        $this->assertArrayNotHasKey('image', $exported['scenes'][0]);
        $this->assertSame(['scene #1: lessons/9/scenes/9/upload.png'], $exporter->unresolvedImages());
    }
}
