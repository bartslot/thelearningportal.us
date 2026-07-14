<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SvgAsset;
use App\Models\User;
use App\Services\SvgAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SvgAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = '<svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0L10 10" fill="#c0392b"/></svg>';

    public function test_search_commons_returns_only_free_licensed_svgs(): void
    {
        Http::fake([
            'commons.wikimedia.org/w/api.php*' => Http::response(['query' => ['pages' => [
                '1' => ['title' => 'File:Roman soldier.svg', 'imageinfo' => [[
                    'thumburl' => 'https://upload.wikimedia.org/t.png',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Roman_soldier.svg',
                    'extmetadata' => ['LicenseShortName' => ['value' => 'Public domain'], 'ObjectName' => ['value' => 'Roman soldier']],
                ]]],
                '2' => ['title' => 'File:Paywalled.svg', 'imageinfo' => [[
                    'thumburl' => 'https://upload.wikimedia.org/p.png',
                    'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-NC 4.0']],
                ]]],
            ]]]),
        ]);

        $results = app(SvgAssetService::class)->searchCommons('roman soldier');

        $this->assertCount(1, $results);
        $this->assertSame('Roman soldier', $results[0]['title']);
        $this->assertSame('commons', $results[0]['source']);
    }

    public function test_import_sanitises_stores_and_records_licence(): void
    {
        Storage::fake('public');
        Http::fake(['commons.wikimedia.org/wiki/Special:FilePath/*' => Http::response(self::SAMPLE)]);
        $user = User::factory()->create();

        $asset = app(SvgAssetService::class)->import($user, [
            'source' => 'commons',
            'source_ref' => 'Roman soldier.svg',
            'source_url' => 'https://commons.wikimedia.org/wiki/File:Roman_soldier.svg',
            'title' => 'Roman soldier',
            'license' => 'Public domain',
            'attribution' => 'Jane Roe',
            'download_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Roman%20soldier.svg',
        ]);

        $this->assertDatabaseHas('svg_assets', ['id' => $asset->id, 'license' => 'Public domain', 'user_id' => $user->id]);
        Storage::disk('public')->assertExists($asset->svg_path);
        $stored = Storage::disk('public')->get($asset->svg_path);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringContainsString('#c0392b', $stored);
        $this->assertSame('Jane Roe — Public domain (Wikimedia Commons)', $asset->credit());
    }

    public function test_import_is_idempotent_per_owner_and_source(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(self::SAMPLE)]);
        $user = User::factory()->create();
        $candidate = [
            'source' => 'commons', 'source_ref' => 'X.svg',
            'source_url' => 'https://commons.wikimedia.org/wiki/File:X.svg',
            'title' => 'X', 'license' => 'CC0', 'attribution' => null,
            'download_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/X.svg',
        ];

        $svc = app(SvgAssetService::class);
        $a = $svc->import($user, $candidate);
        $b = $svc->import($user, $candidate);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, SvgAsset::count());
    }

    public function test_import_from_url_rejects_disallowed_host(): void
    {
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        app(SvgAssetService::class)->importFromUrl($user, 'https://evil.example/x.svg');
    }
}
