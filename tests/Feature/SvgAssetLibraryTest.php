<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\SvgAssetLibrary;
use App\Models\SvgAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SvgAssetLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 10" fill="#111"/></svg>';

    public function test_search_lists_commons_results(): void
    {
        Http::fake(['commons.wikimedia.org/w/api.php*' => Http::response(['query' => ['pages' => [
            '1' => ['title' => 'File:Trireme.svg', 'imageinfo' => [[
                'thumburl' => 'https://upload.wikimedia.org/t.png',
                'extmetadata' => ['LicenseShortName' => ['value' => 'CC0'], 'ObjectName' => ['value' => 'Trireme']],
            ]]],
        ]]])]);

        Livewire::actingAs(User::factory()->create())
            ->test(SvgAssetLibrary::class)
            ->set('query', 'trireme')
            ->call('search')
            ->assertSet('searched', true)
            ->assertSee('Trireme');
    }

    public function test_import_adds_to_the_teacher_library(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(self::SAMPLE)]);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SvgAssetLibrary::class)
            ->set('results', [[
                'source' => 'commons', 'source_ref' => 'Trireme.svg',
                'source_url' => 'https://commons.wikimedia.org/wiki/File:Trireme.svg',
                'title' => 'Trireme', 'license' => 'CC0', 'attribution' => null,
                'download_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Trireme.svg',
            ]])
            ->call('import', 0)
            ->assertSee('Trireme');

        $this->assertSame(1, SvgAsset::ownedBy($user->id)->count());
    }

    public function test_remove_deletes_only_own_asset(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = SvgAsset::factory()->for($me, 'owner')->create();
        $theirs = SvgAsset::factory()->for($other, 'owner')->create();

        Livewire::actingAs($me)->test(SvgAssetLibrary::class)->call('remove', $theirs->id);
        $this->assertNotSoftDeleted($theirs);

        Livewire::actingAs($me)->test(SvgAssetLibrary::class)->call('remove', $mine->id);
        $this->assertSoftDeleted($mine);
    }
}
