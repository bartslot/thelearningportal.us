<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeOutboundUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeOutboundUrlTest extends TestCase
{
    /** A guard built with a stub resolver, so no test ever touches real DNS. */
    private function guard(array $dns = []): SafeOutboundUrl
    {
        return new SafeOutboundUrl(fn (string $host): array => $dns[$host] ?? []);
    }

    #[DataProvider('blockedLiteralHosts')]
    public function test_loopback_private_and_link_local_literals_are_blocked(string $url): void
    {
        $this->assertFalse($this->guard()->isAllowed($url), $url.' must be rejected');
    }

    /** @return array<string, array{0: string}> */
    public static function blockedLiteralHosts(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/x.png'],
            'loopback other octet' => ['http://127.5.5.5/x.png'],
            'localhost' => ['http://localhost/x.png'],
            'all-zeros' => ['http://0.0.0.0/x.png'],
            'private 10/8' => ['http://10.0.0.5/x.png'],
            'private 172.16/12' => ['http://172.16.4.4/x.png'],
            'private 192.168/16' => ['http://192.168.1.1/x.png'],
            'aws/gcp metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/x.png'],
            'ipv6 link-local' => ['http://[fe80::1]/x.png'],
            'ipv6 unique-local' => ['http://[fc00::1]/x.png'],
            'ipv4-mapped ipv6' => ['http://[::ffff:127.0.0.1]/x.png'],
        ];
    }

    /**
     * The bypasses that made the original guard useless: none of these is a valid IP per
     * FILTER_VALIDATE_IP, so the private-range branch was skipped entirely and the URL was
     * allowed — yet curl happily resolves every one of them to 127.0.0.1.
     */
    #[DataProvider('encodedLoopbackForms')]
    public function test_non_dotted_ip_encodings_are_blocked(string $url): void
    {
        $this->assertFalse($this->guard()->isAllowed($url), $url.' must be rejected');
    }

    /** @return array<string, array{0: string}> */
    public static function encodedLoopbackForms(): array
    {
        return [
            'decimal' => ['http://2130706433:8000/x.png'],
            'hex' => ['http://0x7f000001/x.png'],
            'octal' => ['http://017700000001/x.png'],
            'short form' => ['http://127.1/x.png'],
            'bare zero' => ['http://0/x.png'],
            'octal dotted' => ['http://0177.0.0.1/x.png'],
            'decimal no port' => ['http://3232235777/x.png'],
        ];
    }

    #[DataProvider('malformedUrls')]
    public function test_non_http_schemes_and_junk_are_blocked(string $url): void
    {
        $this->assertFalse($this->guard()->isAllowed($url), $url.' must be rejected');
    }

    /** @return array<string, array{0: string}> */
    public static function malformedUrls(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com/'],
            'ftp' => ['ftp://example.com/x.png'],
            'no scheme' => ['example.com/x.png'],
            'empty' => [''],
            'no host' => ['http:///x.png'],
            'underscore host' => ['http://not_a_host.example.com/x.png'],
            'numeric tld' => ['http://example.123/x.png'],
        ];
    }

    /** Only the ports an image is ever served on — everything else is internal-service scanning. */
    #[DataProvider('ports')]
    public function test_only_standard_web_ports_are_allowed(string $url, bool $expected): void
    {
        $dns = ['cdn.example.com' => ['93.184.216.34']];

        $this->assertSame($expected, $this->guard($dns)->isAllowed($url));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ports(): array
    {
        return [
            'implicit 80' => ['http://cdn.example.com/x.png', true],
            'explicit 80' => ['http://cdn.example.com:80/x.png', true],
            'explicit 443' => ['https://cdn.example.com:443/x.png', true],
            'redis' => ['http://cdn.example.com:6379/x.png', false],
            'postgres' => ['http://cdn.example.com:5432/x.png', false],
            'dev server' => ['http://cdn.example.com:8000/x.png', false],
        ];
    }

    public function test_a_public_hostname_resolving_to_a_public_address_is_allowed(): void
    {
        $guard = $this->guard(['upload.wikimedia.org' => ['208.80.154.240']]);

        $this->assertTrue($guard->isAllowed('https://upload.wikimedia.org/a/b/Example.jpg'));
    }

    /** Hostname indirection: only literal IPs used to be range-checked. */
    public function test_a_hostname_resolving_into_private_space_is_blocked(): void
    {
        $guard = $this->guard(['evil.example.com' => ['10.0.0.5']]);

        $this->assertFalse($guard->isAllowed('http://evil.example.com/x.png'));
    }

    /** A split-horizon answer must not pass because one of its records happens to be public. */
    public function test_a_hostname_is_blocked_when_any_resolved_address_is_internal(): void
    {
        $guard = $this->guard(['mixed.example.com' => ['93.184.216.34', '169.254.169.254']]);

        $this->assertFalse($guard->isAllowed('http://mixed.example.com/x.png'));
    }

    /** Fail closed: a name that does not resolve is not proven safe. */
    public function test_an_unresolvable_hostname_is_blocked(): void
    {
        $this->assertFalse($this->guard()->isAllowed('http://nope.invalid/x.png'));
    }

    public function test_a_trailing_dot_fqdn_still_resolves_and_is_allowed(): void
    {
        $guard = $this->guard(['cdn.example.com' => ['93.184.216.34']]);

        $this->assertTrue($guard->isAllowed('http://cdn.example.com./x.png'));
    }
}
