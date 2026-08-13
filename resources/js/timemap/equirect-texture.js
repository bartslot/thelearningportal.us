/**
 * equirect-texture.js — one equirectangular field, shared by every layer that needs it.
 *
 * COPIED, NOT FORKED. This is 9a4ccbe from `realistic-earth` verbatim, plus one change: mipmapping
 * is an option and part of the cache key, because a field the camera is INSIDE must not have
 * mipmaps and a field on the ground must. When that branch merges this file will conflict, and the
 * resolution is to keep both — their file plus that one change. It is deliberately a small diff so
 * that stays a five-line decision rather than a rewrite.
 *
 * The cloud field used to belong to the cloud deck. Then the ground started shading itself with
 * the shadow of those same clouds, and a second copy would have been not just wasteful but wrong:
 * two Image loads land on different frames, so there is a window where the shadows are cast by a
 * sky that has not arrived, and two textures are two chances to disagree about wrap and filtering
 * — which shows up as a hairline seam down the antimeridian in one layer and not the other.
 *
 * So a field is ACQUIRED rather than loaded, and RELEASED rather than deleted. The last holder to
 * let go frees it, which keeps the old behaviour intact: a layer removed on a style reload still
 * cleans up after itself, it just no longer takes anyone else's texture with it.
 *
 * Every field routed through here is decoration — real cloud cover, real wind, city lights, the
 * Milky Way. A failed load costs the effect and never the map, so nothing here throws.
 *
 * MIPMAPS ARE OPTIONAL, AND THAT IS NOT A PREFERENCE. A field wrapped on the ground wants them:
 * the texture is minified hard at globe zoom and without them it crawls with aliasing. A field the
 * camera is INSIDE — the sky — must not have them. It fills the screen from a handful of triangles,
 * so the UV derivative per pixel is enormous, the sampler picks a 2x2 level over most of the frame,
 * and the galaxy renders as a few soft grey blocks. So the choice is part of the cache key: two
 * layers asking for the same image with different filtering get two textures, because they are
 * genuinely two different textures and sharing one would silently ruin whichever asked second.
 */

/** Per GL context, so a context loss or a second map never hands back a stale texture. */
const caches = new WeakMap()

const cacheFor = (gl) => {
  let cache = caches.get(gl)
  if (!cache) { cache = new Map(); caches.set(gl, cache) }
  return cache
}

const upload = (gl, image, mipmap) => {
  const texture = gl.createTexture()
  gl.bindTexture(gl.TEXTURE_2D, texture)
  gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
  // Longitude wraps; latitude does not. REPEAT on T folds the Arctic onto the Antarctic.
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, mipmap ? gl.LINEAR_MIPMAP_LINEAR : gl.LINEAR)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
  if (mipmap) gl.generateMipmap(gl.TEXTURE_2D)
  return texture
}

/**
 * Take a share in an equirectangular texture, loading it if nobody has yet.
 *
 * @param {WebGLRenderingContext} gl
 * @param {string} url
 * @param {() => void} [onReady]  called once the texture is uploaded — immediately if it already is
 * @param {{mipmap?: boolean}} [options]  mipmap false for a field seen from inside; see above
 * @returns {{texture: WebGLTexture|null, ready: boolean, width: number, release: () => void}}
 */
export const acquireEquirectTexture = (gl, url, onReady = null, { mipmap = true } = {}) => {
  const cache = cacheFor(gl)
  // Filtering is part of the identity, not a setting applied afterwards: the same image minified
  // for the ground and sampled flat for the sky are two textures on the card.
  const key = `${url}|${mipmap ? 'mip' : 'flat'}`
  let entry = cache.get(key)

  if (!entry) {
    entry = { texture: null, ready: false, refs: 0, waiters: [], width: 0 }
    cache.set(key, entry)

    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      // Everyone may have let go while the image was in flight. Uploading now would create a
      // texture with no owner and no path to deletion.
      if (entry.refs === 0) return
      // A texture wider than the driver allows fails silently, as a black sky rather than an error.
      const limit = gl.getParameter?.(gl.MAX_TEXTURE_SIZE) ?? Infinity
      if (image.width > limit) { entry.waiters = []; return }
      entry.width = image.width
      entry.texture = upload(gl, image, mipmap)
      entry.ready = true
      const waiters = entry.waiters
      entry.waiters = []
      waiters.forEach((fn) => fn())
    }
    image.onerror = () => { entry.waiters = [] }
    image.src = url
  }

  entry.refs++
  let held = true

  if (onReady) {
    if (entry.ready) onReady()
    else entry.waiters.push(onReady)
  }

  return {
    get texture() { return entry.texture },
    get ready() { return entry.ready },
    get width() { return entry.width },
    /** Idempotent: a layer that releases twice must not free the texture another layer is using. */
    release() {
      if (!held) return
      held = false
      if (onReady) entry.waiters = entry.waiters.filter((fn) => fn !== onReady)
      entry.refs--
      if (entry.refs > 0) return
      if (entry.texture) gl.deleteTexture(entry.texture)
      entry.texture = null
      entry.ready = false
      cache.delete(key)
    },
  }
}

/** Test seam: how many fields this context is holding. Not for production use. */
export const __equirectCacheSize = (gl) => cacheFor(gl).size
