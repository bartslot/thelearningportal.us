/**
 * harness.js — the measuring rig for the tile pyramid. Development only; nothing imports it.
 *
 * It exists because the claim "the pyramid is sharper" has to be a number, and because the obvious
 * way to get that number is wrong: the browser pane forces `preserveDrawingBuffer` off, so reading
 * the canvas after a frame returns pure black and would report a working layer as a broken one.
 * The only honest place to read pixels is INSIDE the custom layer's own `render`, while the frame
 * is still on the GPU. That is what `capture` below does.
 *
 * THE COMPARISON IS THE POINT. The same view is drawn twice: once with the pyramid capped at z3,
 * and once uncapped. z3 is not an arbitrary handicap — eight 256-texel tiles around the equator is
 * 2048 texels, which is exactly the global equirectangular field the cloud deck samples today. So
 * the capped run IS the status quo, measured, and the difference between the two is the fix.
 */

import maplibregl from 'maplibre-gl'
import { buildSphereMesh, buildProgram, FACING_CAMERA_GLSL, SHELL_PROJECT_GLSL, cameraInPlanetSpace } from '../../planet-mesh.js'
import { TERRARIUM_TERRAIN, cameraFromMap, createTilePyramid } from '../index.js'

const LAYER_ID = 'tm-tiled-lod-harness'

/**
 * On the surface, not above it.
 *
 * The first version put the shell at 4 km, which is fine on a globe and wrong at z14 — the camera
 * there is itself only about 4 km up, so the shell sat at or behind the eye and was clipped away
 * entirely. The measurement then read MapLibre's background and reported a flat frame, which looks
 * exactly like a pyramid that has run out of detail. Depth testing is off, so zero is safe.
 */
const ALTITUDE_M = 0

/** The square of pixels read back from the middle of the canvas. */
const CAPTURE = 256

/**
 * The background is magenta so the measurement can tell our pixels from MapLibre's.
 *
 * The obvious test — alpha zero means nothing drew — is useless here: the framebuffer is opaque
 * everywhere, so it counted the background as drawn and averaged a flat fill into the result. A
 * colour nothing in this layer can produce is the reliable discriminator.
 */
const BACKGROUND = [255, 0, 255]

/**
 * A layer that draws the pyramid onto the globe and can read its own pixels back.
 *
 * GLSL ES 3.00 throughout, which is what `sampler2DArray` costs. The projection prelude is pasted
 * after the `#version` line and compiles unchanged, because it declares no `attribute`, `varying`
 * or `texture2D` of its own.
 */
const createHarnessLayer = (pyramid) => {
  const mesh = buildSphereMesh(96, 72)
  let map = null
  let gl = null
  let buffers = null
  const programs = new Map()
  let pending = null
  let failure = null

  const vertexSource = (shaderData) => `#version 300 es
${shaderData.define}
${shaderData.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
out vec2 v_merc;
out vec3 v_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
${SHELL_PROJECT_GLSL}
void main() {
  v_merc = a_pos;
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`

  const fragmentSource = () => `#version 300 es
precision highp float;
in vec2 v_merc;
in vec3 v_sphere;
out vec4 fragColor;
uniform vec3 u_camera;
uniform vec3 u_sun;
${FACING_CAMERA_GLSL}
${pyramid.glsl}
void main() {
  vec3 p = normalize(v_sphere);
  // Analytic horizon rather than the depth buffer: a shell a few km above a 6371 km sphere is
  // inside the depth buffer's precision at orbital distance.
  if (!facesCamera(p, u_camera)) discard;

  // Height signed, normal derived in the shader — the tile bakes neither clamp.
  float height = tiledHeight(v_merc);
  vec3 normal = tiledNormal(v_merc);
  float shade = clamp(dot(normal, normalize(u_sun)), 0.0, 1.0);
  float land = step(0.0, height);
  // Land shaded by its own relief, sea flat. The gradient of THIS is what gets measured.
  vec3 colour = mix(vec3(0.03, 0.09, 0.20), vec3(0.15 + 0.85 * shade), land);
  // MapLibre blends custom layers ONE / ONE_MINUS_SRC_ALPHA: premultiplied.
  fragColor = vec4(colour, 1.0);
}`

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    let program
    try {
      program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), LAYER_ID)
    } catch (error) {
      // Remembered so `capture` can report the real reason instead of timing out on a blank frame.
      failure = error.message
      throw error
    }
    const entry = {
      program,
      attribs: { pos: gl.getAttribLocation(program, 'a_pos'), sphere: gl.getAttribLocation(program, 'a_sphere') },
      uniforms: {
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        camera: gl.getUniformLocation(program, 'u_camera'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        tiles: gl.getUniformLocation(program, 'u_tm_tiles'),
        page: gl.getUniformLocation(program, 'u_tm_page'),
        ancestor: gl.getUniformLocation(program, 'u_tm_pageAncestor'),
        extent: gl.getUniformLocation(program, 'u_tm_pageExtent'),
        shortfall: gl.getUniformLocation(program, 'u_tm_shortfall'),
        heightRange: gl.getUniformLocation(program, 'u_tm_heightRange'),
        tileSize: gl.getUniformLocation(program, 'u_tm_tileSize'),
        circumference: gl.getUniformLocation(program, 'u_tm_circumference'),
        slopeStep: gl.getUniformLocation(program, 'u_tm_slopeStep'),
        matrix: gl.getUniformLocation(program, 'u_projection_matrix'),
        tileMercatorCoords: gl.getUniformLocation(program, 'u_projection_tile_mercator_coords'),
        clippingPlane: gl.getUniformLocation(program, 'u_projection_clipping_plane'),
        transition: gl.getUniformLocation(program, 'u_projection_transition'),
        fallbackMatrix: gl.getUniformLocation(program, 'u_projection_fallback_matrix'),
      },
    }
    programs.set(key, entry)
    return entry
  }

  return {
    id: LAYER_ID,
    type: 'custom',
    renderingMode: '3d',

    /**
     * Ask for the next frame's pixels. Resolves with the readback, from inside `render`.
     *
     * It rejects on a timeout rather than waiting forever, because the first version of this did
     * wait forever: the shader failed to compile, `render` threw before reaching the readback, and
     * the harness sat on "measuring z2" with the real error two levels down in the console.
     */
    capture(timeoutMs = 15000) {
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          pending = null
          reject(new Error(failure ? `render failed: ${failure}` : 'no frame within timeout'))
        }, timeoutMs)
        pending = (value) => { clearTimeout(timer); resolve(value) }
        map?.triggerRepaint()
      })
    },

    onAdd(mapInstance, glContext) {
      map = mapInstance
      gl = glContext
      pyramid.onAdd(gl)
      const buffer = (target, data) => {
        const b = gl.createBuffer()
        gl.bindBuffer(target, b)
        gl.bufferData(target, data, gl.STATIC_DRAW)
        return b
      }
      buffers = {
        pos: buffer(gl.ARRAY_BUFFER, mesh.positions),
        sphere: buffer(gl.ARRAY_BUFFER, mesh.spheres),
        index: buffer(gl.ELEMENT_ARRAY_BUFFER, mesh.indices),
      }
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      pyramid.onRemove()
      if (buffers) {
        gl.deleteBuffer(buffers.pos)
        gl.deleteBuffer(buffers.sphere)
        gl.deleteBuffer(buffers.index)
        buffers = null
      }
      map = null
      gl = null
    },

    render(glContext, args) {
      if (!buffers) return
      const shaderData = args?.shaderData
      const projection = args?.defaultProjectionData
      if (!shaderData || !projection) return

      pyramid.update(cameraFromMap(map, args))

      const { program, attribs, uniforms } = programFor(shaderData)
      gl.useProgram(program)
      if (uniforms.matrix) gl.uniformMatrix4fv(uniforms.matrix, false, projection.mainMatrix)
      if (uniforms.tileMercatorCoords) gl.uniform4f(uniforms.tileMercatorCoords, ...projection.tileMercatorCoords)
      if (uniforms.clippingPlane) gl.uniform4f(uniforms.clippingPlane, ...projection.clippingPlane)
      if (uniforms.transition) gl.uniform1f(uniforms.transition, projection.projectionTransition)
      if (uniforms.fallbackMatrix) gl.uniformMatrix4fv(uniforms.fallbackMatrix, false, projection.fallbackMatrix)

      const lat = map.getCenter().lat
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, ALTITUDE_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], ALTITUDE_M).z)
      }
      if (uniforms.camera) gl.uniform3f(uniforms.camera, ...cameraInPlanetSpace(map, maplibregl))
      if (uniforms.sun) gl.uniform3f(uniforms.sun, 0.45, 0.55, 0.70)

      pyramid.bind(uniforms, 0)

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      gl.disable(gl.DEPTH_TEST)
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)

      if (pending) {
        // INSIDE render, on purpose. The pane forces preserveDrawingBuffer off, so a post-frame
        // read of the canvas comes back pure black and would look like a layer that drew nothing.
        const canvas = gl.canvas
        const size = Math.min(CAPTURE, canvas.width, canvas.height)
        const pixels = new Uint8Array(size * size * 4)
        gl.readPixels(
          Math.floor((canvas.width - size) / 2), Math.floor((canvas.height - size) / 2),
          size, size, gl.RGBA, gl.UNSIGNED_BYTE, pixels,
        )
        const resolve = pending
        pending = null
        resolve({ pixels, size })
      }
    },
  }
}

/**
 * How much detail is actually in the picture.
 *
 * Mean absolute neighbour difference in luminance. A texture magnified past its resolution is a
 * smooth gradient and scores near zero however bright it is; real relief at the right level scores
 * high. Non-drawn pixels are excluded so the starfield behind the globe cannot flatter the result.
 */
const detailEnergy = ({ pixels, size }) => {
  let sum = 0
  let count = 0
  let low = 255
  let high = 0
  const luma = (i) => 0.2126 * pixels[i] + 0.7152 * pixels[i + 1] + 0.0722 * pixels[i + 2]
  const isBackground = (i) =>
    Math.abs(pixels[i] - BACKGROUND[0]) < 6 &&
    Math.abs(pixels[i + 1] - BACKGROUND[1]) < 6 &&
    Math.abs(pixels[i + 2] - BACKGROUND[2]) < 6
  for (let y = 0; y < size - 1; y++) {
    for (let x = 0; x < size - 1; x++) {
      const i = (y * size + x) * 4
      if (isBackground(i)) continue
      const value = luma(i)
      low = Math.min(low, value)
      high = Math.max(high, value)
      sum += Math.abs(value - luma(i + 4)) + Math.abs(value - luma(i + size * 4))
      count++
    }
  }
  // The spread matters as much as the gradient: an energy of zero over a wide spread is a smooth
  // ramp (magnified detail), while zero over no spread at all is a flat fill, which would mean the
  // layer is not really sampling anything and the whole measurement is worthless.
  return { energy: count ? sum / count : 0, spread: high - low, drawn: count }
}

const readout = document.getElementById('readout')
const say = (text) => { readout.textContent = text }

const style = {
  version: 8,
  projection: { type: 'globe' },
  sources: {},
  layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#ff00ff' } }],
}

const map = new maplibregl.Map({
  container: 'map',
  style,
  center: [7.9, 46.0],   // the Alps: the case the brief calls the worst of the three
  zoom: 2,
  attributionControl: false,
})

/** Wait until the pyramid has nothing outstanding, then draw one more frame and read it. */
const settle = async (layer, pyramid) => {
  for (let attempt = 0; attempt < 40; attempt++) {
    map.triggerRepaint()
    await new Promise((resolve) => requestAnimationFrame(resolve))
    await pyramid.idle()
    // Every tile this frame WANTS, not merely a full atlas — the atlas is usually full of the last
    // view's tiles, so "slots used" is satisfied immediately and measures nothing.
    if (pyramid.stats().completeness >= 1) break
  }
  return layer.capture()
}

const runAt = async (zoom, layer, pyramid) => {
  map.jumpTo({ center: [7.9, 46.0], zoom })
  await new Promise((resolve) => map.once('idle', resolve))
  const capture = await settle(layer, pyramid)
  const { energy, spread, drawn } = detailEnergy(capture)
  const stats = pyramid.stats()
  return {
    zoom,
    energy: Number(energy.toFixed(4)),
    spread: Number(spread.toFixed(1)),
    drawn,
    maxLevel: stats.maxLevel,
    tileCount: stats.tileCount,
    worstPixelsPerTexel: Number(stats.worstPixelsPerTexel.toFixed(2)),
    starved: stats.starved,
    shortfall: Number(stats.shortfall.toFixed(3)),
    completeness: Number(stats.completeness.toFixed(3)),
    hitRate: Number(stats.hitRate.toFixed(3)),
    networkFetches: stats.networkFetches,
    requests: stats.requests,
    residentTiles: stats.residentTiles,
    slotsUsed: stats.slotsUsed,
    bytes: stats.bytes,
  }
}

/** One full sweep with a given source cap. z3 is the 2048-texel global field, measured. */
const sweep = async (label, maxzoom) => {
  const pyramid = createTilePyramid({
    source: { ...TERRARIUM_TERRAIN, maxzoom },
    maxTiles: 64,
    layers: 128,
  })
  const layer = createHarnessLayer(pyramid)
  map.addLayer(layer)
  const rows = []
  for (const zoom of [2, 8, 11, 14]) {
    say(`${label}: measuring z${zoom}`)
    rows.push(await runAt(zoom, layer, pyramid))
  }
  const cacheAfter = pyramid.stats()
  map.removeLayer(LAYER_ID)
  return { label, maxzoom, rows, cache: cacheAfter }
}

/**
 * Leave a live pyramid on screen at the end, so there is something to look at as well as numbers.
 * The sweeps remove their layers when they finish, which is correct for measuring and useless for
 * showing anyone.
 */
const showcase = async (zoom, source = TERRARIUM_TERRAIN) => {
  if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID)
  const pyramid = createTilePyramid({ source, maxTiles: 64, layers: 128 })
  const layer = createHarnessLayer(pyramid)
  map.addLayer(layer)
  map.jumpTo({ center: [7.9, 46.0], zoom })
  const capture = await settle(layer, pyramid)
  window.__showcase = { pyramid, levels: pyramid.lastLevels?.() ?? null, ...detailEnergy(capture) }
  return window.__showcase
}

/**
 * Pin the whole view to ONE level, to tell a level-boundary artefact from a tile-boundary one.
 * Only safe to call because selection walks down to a deep minzoom rather than enumerating it.
 */
window.__showLevel = (level) => showcase(9, { ...TERRARIUM_TERRAIN, minzoom: level, maxzoom: level })
window.__showMixed = () => showcase(9)

map.on('load', async () => {
  try {
    // Capped first, so the uncapped run cannot be flattered by a warm cache.
    const capped = await sweep('global-field equivalent (z3)', 3)
    const tiled = await sweep('full pyramid (z12)', 12)
    const results = { capped, tiled }
    window.__harness = results
    say(JSON.stringify(results, null, 1))
    await showcase(9)
  } catch (error) {
    window.__harness = { error: String(error), stack: error?.stack }
    say(`FAILED\n${error}\n${error?.stack ?? ''}`)
  }
})

window.__harnessReady = true
