/**
 * Re-home a media URL onto the page origin — but only when it is OUR media.
 *
 * The player used to rewrite the origin of every media URL it was handed, to paper over a local
 * dev mismatch: the page served from http://localhost:8000 while stored URLs said 127.0.0.1,
 * which blocks audio. That was harmless while every asset came off our own box.
 *
 * It stopped being harmless when lesson covers moved to a CDN: rewriting
 * https://res.cloudinary.com/<cloud>/image/upload/... to the page origin produces
 * http://localhost:8000/<cloud>/image/upload/... and a 404. A third-party host must be left
 * exactly as it is.
 */

/** Hosts that mean "this machine" — the only ones worth re-homing. */
const LOOPBACK = /^(localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]|::1)$/i

export function sameOriginMediaUrl(url, origin = globalThis.location?.origin ?? '') {
  if (!url || typeof url !== 'string' || origin === '') return url

  let parsed
  let here
  try {
    parsed = new URL(url, origin)
    here = new URL(origin)
  } catch {
    return url   // a relative path or something unparseable — the browser resolves it fine
  }

  if (parsed.origin === here.origin) return url

  // Only a loopback host gets re-homed, and only onto a loopback page: a lesson served from
  // the real domain must never have its media pointed back at a developer's laptop.
  if (!LOOPBACK.test(parsed.hostname) || !LOOPBACK.test(here.hostname)) return url

  return here.origin + parsed.pathname + parsed.search + parsed.hash
}
