/**
 * equirect-texture.js — one equirectangular field, shared by every layer that needs it.
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
 * Every field routed through here is decoration — real cloud cover, real wind, city lights. A
 * failed load costs the effect and never the map, so nothing here throws.
 */

/** Per GL context, so a context loss or a second map never hands back a stale texture. */
const caches = new WeakMap()

const cacheFor = (gl) => {
  let cache = caches.get(gl)
  if (!cache) { cache = new Map(); caches.set(gl, cache) }
  return cache
}

const upload = (gl, image) => {
  const texture = gl.createTexture()
  gl.bindTexture(gl.TEXTURE_2D, texture)
  gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
  // Longitude wraps; latitude does not. REPEAT on T folds the Arctic onto the Antarctic.
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
  gl.generateMipmap(gl.TEXTURE_2D)
  return texture
}

/**
 * Take a share in an equirectangular texture, loading it if nobody has yet.
 *
 * @param {WebGLRenderingContext} gl
 * @param {string} url
 * @param {() => void} [onReady]  called once the texture is uploaded — immediately if it already is
 * @returns {{texture: WebGLTexture|null, ready: boolean, release: () => void}}
 */
export const acquireEquirectTexture = (gl, url, onReady = null) => {
  const cache = cacheFor(gl)
  let entry = cache.get(url)

  if (!entry) {
    entry = { texture: null, ready: false, refs: 0, waiters: [] }
    cache.set(url, entry)

    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      // Everyone may have let go while the image was in flight. Uploading now would create a
      // texture with no owner and no path to deletion.
      if (entry.refs === 0) return
      entry.texture = upload(gl, image)
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
      cache.delete(url)
    },
  }
}

/** Test seam: how many fields this context is holding. Not for production use. */
export const __equirectCacheSize = (gl) => cacheFor(gl).size
