<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Is this URL safe for the SERVER to fetch? (SSRF guard.)
 *
 * The teacher-facing "paste an image URL" background makes the server issue an HTTP request to a
 * string the user chose, so the host has to be proven external before we connect. The guard this
 * replaced only range-checked hosts that were already valid dotted-quad IPs, which left three ways
 * straight through to loopback and internal space:
 *
 *   1. Alternate IP notations — 2130706433, 0x7f000001, 017700000001, 127.1 all fail
 *      FILTER_VALIDATE_IP, so the private-range branch was skipped and the URL was allowed, yet
 *      curl resolves every one of them to 127.0.0.1.
 *   2. Hostname indirection — any DNS name pointing at 10.0.0.0/8 or 169.254.169.254 was never
 *      checked at all, because it is not a literal IP.
 *   3. Redirects — the guard ran once, on the URL the user supplied; a public host answering 302
 *      moved the real request somewhere internal. (Call sites must re-check every hop; see
 *      redirectGuard().)
 *
 * So this works the other way round: a host must POSITIVELY look like either a public IP literal or
 * a real DNS hostname, and a hostname must resolve entirely to public addresses. Anything
 * unrecognised — including anything that does not resolve — is refused. Fail closed.
 */
final class SafeOutboundUrl
{
    /** An image is served over http/https and nothing else. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Ports an image is ever actually served on. Permitting arbitrary ports turns this fetcher into
     * an internal port scanner (Redis on 6379, Postgres on 5432, dev servers on 8000).
     */
    private const ALLOWED_PORTS = [80, 443];

    /**
     * A DNS hostname: dot-separated LDH labels with an alphabetic TLD. The alphabetic TLD is what
     * rejects every numeric IP encoding above — "2130706433" and "0x7f000001" are not hostnames,
     * and "127.1" has a numeric TLD.
     */
    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    /** @var Closure(string):list<string> */
    private Closure $resolver;

    /**
     * @param  null|callable(string):list<string>  $resolver  host → IP addresses; defaults to DNS.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null
            ? Closure::fromCallable($resolver)
            : static fn (string $host): array => self::resolveViaDns($host);
    }

    public function isAllowed(string $url): bool
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], self::ALLOWED_PORTS, true)) {
            return false;
        }

        // Strip IPv6 brackets and the optional FQDN trailing dot before inspecting the host.
        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');

        if ($host === '') {
            return false;
        }

        // An IP literal is judged directly — no DNS involved.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicAddress($host);
        }

        // Not an IP, so it must look like a real hostname. This is the allowlist that kills the
        // numeric encodings; without it they fall through as "some hostname" and get resolved.
        if (preg_match(self::HOSTNAME, $host) !== 1) {
            return false;
        }

        $addresses = ($this->resolver)($host);

        if ($addresses === []) {
            return false;   // unresolvable is not the same as safe
        }

        // EVERY answer must be public: a split-horizon or attacker-controlled record set that mixes
        // one public address with 169.254.169.254 must not pass on the strength of the public one.
        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A Guzzle on_redirect hook that re-runs this guard on every hop, so a public host cannot 302
     * the request into internal space after the initial check has already passed. Pass it as
     * ->withOptions(['allow_redirects' => $guard->redirectGuard()]).
     *
     * @return array<string,mixed>
     */
    public function redirectGuard(int $max = 3): array
    {
        return [
            'max' => $max,
            'strict' => true,
            'referer' => false,
            'protocols' => self::ALLOWED_SCHEMES,
            'track_redirects' => false,
            'on_redirect' => function ($request, $response, $uri): void {
                if (! $this->isAllowed((string) $uri)) {
                    throw new \RuntimeException('Redirect to a non-public address was blocked.');
                }
            },
        ];
    }

    /** Reject loopback, private, link-local, reserved and IPv4-mapped-IPv6 addresses. */
    private static function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** @return list<string> */
    private static function resolveViaDns(string $host): array
    {
        $v4 = gethostbynamel($host) ?: [];

        $v6 = array_column(
            @dns_get_record($host, DNS_AAAA) ?: [],
            'ipv6',
        );

        return array_values(array_filter(array_merge($v4, $v6)));
    }
}
