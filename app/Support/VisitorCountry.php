<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which country a visitor is most likely in, as an ISO-3166 alpha-2 code.
 *
 * Used to pick the hero's demo lesson: a German teacher should land on a lesson from their own
 * curriculum, not on a Dutch one. Getting it wrong costs nothing — the demo falls back — so this
 * deliberately avoids a GeoIP database, a paid lookup service and any per-request network call.
 *
 * Three sources, best first:
 *
 *  1. A country header, when the host or CDN sets one. Real geolocation, already computed.
 *  2. The REGION subtag of Accept-Language. A browser sending `nl-BE` is telling us Belgium
 *     directly, and it is the country the visitor configured rather than the one their VPN exit
 *     node is in.
 *  3. The default region of the negotiated locale (nl → NL), which is a guess, but a better one
 *     than showing everybody the same lesson.
 */
final class VisitorCountry
{
    /**
     * Headers that carry a country code when something upstream has done the lookup. Cloudflare
     * sets the first; SiteGround/Apache with mod_geoip sets the second.
     */
    private const HEADERS = [
        'CF-IPCountry',
        'GEOIP_COUNTRY_CODE',
        'X-AppEngine-Country',
        'X-Country-Code',
    ];

    /** Values a header can carry that are not a country. */
    private const NOT_A_COUNTRY = ['XX', 'T1', 'ZZ', ''];

    public static function code(?Request $request = null): ?string
    {
        $request ??= request();

        return self::fromHeaders($request)
            ?? self::fromAcceptLanguage($request)
            ?? self::fromLocale();
    }

    private static function fromHeaders(Request $request): ?string
    {
        foreach (self::HEADERS as $header) {
            $value = strtoupper(trim((string) $request->header($header)));

            if (strlen($value) === 2 && ! in_array($value, self::NOT_A_COUNTRY, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The first region subtag in Accept-Language, e.g. `de-AT,de;q=0.9` → AT.
     *
     * Read in the browser's own order of preference, so a visitor who lists `fr-CA` first is
     * treated as Canadian even though `fr` alone would say France.
     */
    private static function fromAcceptLanguage(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language');

        foreach (explode(',', $header) as $chunk) {
            $tag = trim(explode(';', $chunk)[0]);

            if (preg_match('/^[A-Za-z]{2,3}[-_]([A-Za-z]{2})$/', $tag, $match)) {
                return strtoupper($match[1]);
            }
        }

        return null;
    }

    /** Last resort: the country the negotiated UI language belongs to (nl → NL). */
    private static function fromLocale(): ?string
    {
        $region = Locales::region(app()->getLocale());
        $parts = explode('-', $region);

        return isset($parts[1]) ? strtoupper($parts[1]) : null;
    }
}
