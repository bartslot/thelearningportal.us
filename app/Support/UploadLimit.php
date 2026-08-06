<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How large an upload this server will actually accept.
 *
 * A form that promises 50 MB and a PHP that accepts 2 MB do not fail politely. PHP discards the
 * body before Laravel runs, so no validation rule fires, no exception is thrown and nothing reaches
 * the log — the browser just gets an empty request back and Livewire says "failed to upload". That
 * is exactly how a 2.7 MB video was rejected by a field advertising 50 MB, with nothing anywhere to
 * explain it.
 *
 * So the limit a form states and the limit it validates against both come from here, and here asks
 * PHP. Two directives bind, and the smaller wins: `upload_max_filesize` caps one file,
 * `post_max_size` caps the whole request body (which also carries Livewire's own fields, so the
 * headroom below keeps a file that is exactly at the cap from pushing the body over it).
 */
final class UploadLimit
{
    /** Room left for the rest of the multipart body: field names, boundaries, Livewire's payload. */
    private const BODY_OVERHEAD_BYTES = 512 * 1024;

    /**
     * The largest single file this server accepts, in bytes.
     *
     * @param  int|null  $ceilingBytes  an application cap to apply on top, e.g. "we never want more
     *                                  than 50 MB of video". Omitted, PHP alone decides.
     */
    public static function bytes(?int $ceilingBytes = null): int
    {
        $perFile = self::toBytes((string) ini_get('upload_max_filesize'));
        $perBody = self::toBytes((string) ini_get('post_max_size'));

        // post_max_size = 0 means "no limit" in PHP. Anything else has to leave room for the fields
        // travelling alongside the file.
        $fromBody = $perBody > 0 ? max(0, $perBody - self::BODY_OVERHEAD_BYTES) : PHP_INT_MAX;

        $limit = min($perFile > 0 ? $perFile : PHP_INT_MAX, $fromBody);

        return $ceilingBytes !== null ? min($limit, $ceilingBytes) : $limit;
    }

    /** The same limit in kilobytes, which is the unit Laravel's `max:` file rule speaks. */
    public static function kilobytes(?int $ceilingBytes = null): int
    {
        return max(1, (int) floor(self::bytes($ceilingBytes) / 1024));
    }

    /** For a label a teacher reads: "50 MB", "2 MB". */
    public static function human(?int $ceilingBytes = null): string
    {
        $mb = self::bytes($ceilingBytes) / 1048576;

        return ($mb >= 10 ? (string) (int) round($mb) : rtrim(rtrim(number_format($mb, 1), '0'), '.')).' MB';
    }

    /**
     * Whether the server is the thing standing in the way, rather than our own ceiling.
     *
     * Worth saying out loud in an admin screen: "this box only accepts 2 MB" is a fixable
     * deployment fact, and silence about it is how it stays broken for months.
     */
    public static function isServerConstrained(int $ceilingBytes): bool
    {
        return self::bytes() < $ceilingBytes;
    }

    /** "64M" / "512K" / "2G" / "1048576" → bytes. */
    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
