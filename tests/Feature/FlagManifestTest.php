<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The flag manifest tells the Time-Map which polities have a flag PNG, so it never 404-probes.
 *
 * public/flags is gitignored, so the generated manifest is absent in a fresh clone or worktree and
 * the map's fetch used to 404 — a real console error, and the reason timemap-shell went red. The
 * fix must not be a committed empty [], which would report "no flags" in the environments that have
 * them, so these tests pin BOTH directions: empty when there is nothing, and the actual filenames
 * when there is.
 */
final class FlagManifestTest extends TestCase
{
    private string $flagsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flagsDir = public_path('flags');
    }

    protected function tearDown(): void
    {
        // Only remove what a test created. A developer with a real synced flag set keeps it.
        foreach (['Q9999001', 'Q9999002'] as $qid) {
            File::delete($this->flagsDir.'/'.$qid.'.png');
        }

        parent::tearDown();
    }

    public function test_it_returns_an_empty_list_when_no_flags_have_been_downloaded(): void
    {
        if (File::isDirectory($this->flagsDir)) {
            $this->markTestSkipped('public/flags exists here, so the empty case cannot be observed.');
        }

        $this->get('/flags/manifest.json')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_it_lists_the_qids_that_actually_have_a_png(): void
    {
        File::ensureDirectoryExists($this->flagsDir);
        File::put($this->flagsDir.'/Q9999001.png', "\x89PNG\r\n\x1a\n");
        File::put($this->flagsDir.'/Q9999002.png', "\x89PNG\r\n\x1a\n");

        $body = $this->get('/flags/manifest.json')->assertOk()->json();

        $this->assertContains('Q9999001', $body);
        $this->assertContains('Q9999002', $body);
    }

    /**
     * The whole point of the manifest: a QID with no file must not appear, or the map loads an
     * image that isn't there and we are back to the 404 this replaced.
     */
    public function test_a_qid_without_a_file_is_not_listed(): void
    {
        File::ensureDirectoryExists($this->flagsDir);
        File::put($this->flagsDir.'/Q9999001.png', "\x89PNG\r\n\x1a\n");

        $body = $this->get('/flags/manifest.json')->assertOk()->json();

        $this->assertContains('Q9999001', $body);
        $this->assertNotContains('Q9999002', $body);
    }
}
