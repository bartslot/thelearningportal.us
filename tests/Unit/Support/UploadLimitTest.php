<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\UploadLimit;
use Tests\TestCase;

/**
 * A form that promises more than the server accepts does not fail politely.
 *
 * PHP discards an oversized request body before Laravel runs, so no validation rule fires and
 * nothing reaches the log. A 2.7 MB video was rejected by a field advertising 50 MB, and the only
 * evidence anywhere was "failed to upload" in the browser. So the number the form shows and the
 * number it validates against both have to come from PHP itself.
 */
class UploadLimitTest extends TestCase
{
    public function test_it_reads_php_shorthand_sizes(): void
    {
        $this->assertSame(64 * 1024 * 1024, UploadLimit::toBytes('64M'));
        $this->assertSame(2 * 1024 * 1024, UploadLimit::toBytes('2M'));
        $this->assertSame(512 * 1024, UploadLimit::toBytes('512K'));
        $this->assertSame(2 * 1024 * 1024 * 1024, UploadLimit::toBytes('2G'));
        $this->assertSame(1048576, UploadLimit::toBytes('1048576'), 'a plain byte count is already bytes');
        $this->assertSame(0, UploadLimit::toBytes(''));
    }

    public function test_the_application_ceiling_wins_when_it_is_the_smaller_of_the_two(): void
    {
        // Deliberately NOT asserting a fixed number: the runner's own php.ini is part of the
        // answer, and a test that assumes a generous one passes on a laptop and fails in CI. Ask
        // for a byte, which no php.ini is smaller than, and our ceiling must be what comes back.
        $this->assertSame(1024, UploadLimit::bytes(1024));
    }

    public function test_the_limit_is_never_larger_than_what_php_will_accept(): void
    {
        $perFile = UploadLimit::toBytes((string) ini_get('upload_max_filesize'));

        // Ask for a gigabyte; the answer must still respect the server.
        $this->assertLessThanOrEqual($perFile, UploadLimit::bytes(1024 * 1024 * 1024));
    }

    public function test_kilobytes_is_what_laravels_max_rule_speaks(): void
    {
        // `max:` on a file rule counts kilobytes, so a mismatch here silently changes the rule.
        $this->assertSame(64, UploadLimit::kilobytes(64 * 1024));
        $this->assertSame(
            (int) floor(UploadLimit::bytes() / 1024),
            UploadLimit::kilobytes(),
            'kilobytes must be bytes/1024 of the very same limit',
        );
    }

    public function test_it_says_so_when_the_server_is_the_constraint(): void
    {
        // A 100 GB ask cannot be satisfied by any sane php.ini, so this stands in for "PHP is
        // smaller than we asked for" without depending on the runner's own configuration.
        $this->assertTrue(UploadLimit::isServerConstrained(100 * 1024 * 1024 * 1024));
        $this->assertFalse(UploadLimit::isServerConstrained(1024), 'one kilobyte is under every limit');
    }

    public function test_it_reads_as_a_size_a_person_would_write(): void
    {
        // Sizes small enough that no php.ini can clamp them, so this tests the formatting only.
        $this->assertSame('1 MB', UploadLimit::human(1024 * 1024));
        $this->assertSame('1.5 MB', UploadLimit::human((int) (1.5 * 1024 * 1024)));
        $this->assertSame('0.5 MB', UploadLimit::human(512 * 1024));
    }
}
