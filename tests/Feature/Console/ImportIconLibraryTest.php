<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SvgAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `icons:import` publishes resources/icons into the shared library the Icons panel reads.
 * The directory tree IS the taxonomy, so these cover the mapping from folders to pills, the
 * filename-to-title cleanup, and the promise that re-running never duplicates an icon.
 */
class ImportIconLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->root = storage_path('framework/testing/icons-'.uniqid());
        $this->write('line-art/Civilisations/Egyptians/np_pharaoh_2239365_000000.svg');
        $this->write('line-art/Prehistoric/Dinosaurs/noun-t-rex-4114411.svg');
        $this->write('arrows/arrow-straight.svg');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** Publish the fixture tree rather than the app's real 148-icon set. */
    private function import(string $extra = ''): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan(trim("icons:import --path={$this->root} {$extra}"));
    }

    /** Writes a minimal valid SVG so the sanitiser has something real to chew on. */
    private function write(string $relative): void
    {
        $path = $this->root.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><path d="M10 10h80v80h-80z"/></svg>');
    }

    public function test_it_files_each_icon_under_its_folders_and_gives_it_a_readable_title(): void
    {
        $this->import()->assertSuccessful();

        $pharaoh = SvgAsset::bundled()->where('source_ref', 'line-art/Civilisations/Egyptians/np_pharaoh_2239365_000000.svg')->firstOrFail();

        $this->assertNull($pharaoh->user_id, 'a bundled icon belongs to nobody');
        $this->assertSame('line-art', $pharaoh->collection);
        $this->assertSame('Civilisations', $pharaoh->category);
        $this->assertSame('Egyptians', $pharaoh->subcategory);
        // np_ prefix and the trailing stock-library ids are noise, not a name.
        $this->assertSame('Pharaoh', $pharaoh->title);

        $rex = SvgAsset::bundled()->where('source_ref', 'line-art/Prehistoric/Dinosaurs/noun-t-rex-4114411.svg')->firstOrFail();
        $this->assertSame('T rex', $rex->title);
    }

    public function test_a_flat_collection_has_no_category_pills(): void
    {
        $this->import()->assertSuccessful();

        $arrow = SvgAsset::bundled()->where('collection', 'arrows')->firstOrFail();

        $this->assertNull($arrow->category);
        $this->assertNull($arrow->subcategory);
        $this->assertSame('Arrow straight', $arrow->title);
    }

    public function test_it_stores_a_sanitised_copy_on_the_public_disk(): void
    {
        $this->import()->assertSuccessful();

        $arrow = SvgAsset::bundled()->where('collection', 'arrows')->firstOrFail();

        Storage::disk('public')->assertExists($arrow->svg_path);
        // The stored path mirrors the source tree, slugified, so an icon is traceable back.
        $this->assertSame('svg-assets/library/arrows/arrow-straight.svg', $arrow->svg_path);
    }

    public function test_re_running_updates_in_place_so_scenes_keep_pointing_at_the_same_icon(): void
    {
        $this->import()->assertSuccessful();
        $before = SvgAsset::bundled()->pluck('id', 'source_ref');

        $this->import()->assertSuccessful();
        $after = SvgAsset::bundled()->pluck('id', 'source_ref');

        $this->assertSame($before->all(), $after->all(), 'a second run must not duplicate or renumber the library');
    }

    public function test_prune_drops_only_icons_whose_source_file_has_gone(): void
    {
        $this->import()->assertSuccessful();
        $keptRef = 'arrows/arrow-straight.svg';
        $goneRef = 'line-art/Prehistoric/Dinosaurs/noun-t-rex-4114411.svg';

        File::delete($this->root.'/'.$goneRef);
        $this->import('--prune')->assertSuccessful();

        $this->assertNull(SvgAsset::bundled()->where('source_ref', $goneRef)->first());
        $this->assertNotNull(SvgAsset::bundled()->where('source_ref', $keptRef)->first());
    }

    public function test_it_never_touches_a_teachers_own_imports(): void
    {
        $mine = SvgAsset::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'commons', 'source_ref' => 'File:Mine.svg', 'source_url' => 'https://example.test',
            'title' => 'Mine', 'license' => 'CC0', 'svg_path' => 'svg-assets/1/mine.svg',
        ]);

        $this->import('--prune')->assertSuccessful();

        $this->assertNotNull($mine->fresh(), 'pruning the bundled library must leave a teacher\'s library alone');
    }
}
