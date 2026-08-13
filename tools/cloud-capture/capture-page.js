/**
 * capture-page.js — the page the frame grabber drives.
 *
 * Renders ONLY the cloud deck, on transparency. No imagery, no background layer: what comes out is
 * an RGBA frame whose alpha IS the cloud, ready to be composited over the live map at playback.
 * That is the whole trick — the video is a foreground element, not a picture of the planet with a
 * hole cut in it. A hole would have to track where the globe sits on screen, and that moves with
 * viewport aspect, zoom and pitch; transparency has nothing to line up.
 *
 * Determinism matters more than speed here. Nothing may depend on the wall clock or on how long a
 * frame took, or a second capture will not match the first: the camera pose comes from the shared
 * camera track at an exact time, and the shader clock is set explicitly.
 */

import maplibregl from 'maplibre-gl'
import { createVolumetricCloudLayer } from './volumetric-clouds.js'
import { samplePose, trackDuration } from '../../resources/js/map/camera-track.js'
import { applyPose } from '../../resources/js/map/camera-director.js'

const params = new URLSearchParams(location.search)
const num = (key, fallback) => (params.has(key) ? Number(params.get(key)) : fallback)

// The dive: space to just under the cloud deck. Shared with the live map at playback, which is what
// keeps the pre-rendered element locked to the scene underneath it.
export const DIVE_TRACK = {
  keyframes: [
    { time: 0, lng: -28, lat: 24, altitude: 9000000, heading: 0, tilt: 0, easing: 'easeInCubic' },
    { time: 4.5, lng: -26, lat: 26, altitude: 1200000, heading: 18, tilt: 35, easing: 'easeInOutCubic' },
    { time: 8, lng: -24, lat: 28, altitude: 210000, heading: 38, tilt: 62, easing: 'easeInOutCubic' },
    { time: 10.5, lng: -23, lat: 29, altitude: 52000, heading: 52, tilt: 78, easing: 'easeOutCubic' },
  ],
}

const map = new maplibregl.Map({
  container: 'map',
  // preserveDrawingBuffer so the canvas can be read back after the frame is composited — without it
  // toDataURL returns an empty image, which is the classic way to capture a folder of black PNGs.
  preserveDrawingBuffer: true,
  antialias: true,
  style: {
    version: 8,
    projection: { type: 'globe' },
    sources: {},
    layers: [],          // deliberately empty: the alpha channel is the deliverable
  },
  center: [-28, 24],
  zoom: 2,
  maxPitch: 85,
  attributionControl: false,
  fadeDuration: 0,       // no cross-fades: every frame must be a finished frame
})

const clouds = createVolumetricCloudLayer({
  opacity: num('opacity', 0.95),
  fieldUrl: params.get('field') || 'clouds-field.webp',
  marchSteps: num('steps', 96),
  lightSteps: num('lightSteps', 8),
  windUrl: params.get('wind') || 'wind-field.png',
  windAmount: num('windAmount', 1),
  windScale: num('windScale', 0.06),
  windRate: num('windRate', 0.05),
  sun: [0.55, 0.38, -0.74],
})

const ready = new Promise((resolve) => {
  const attach = () => {
    if (!map.getStyle()) return false
    if (!map.getLayer('tm-clouds-volumetric')) map.addLayer(clouds)
    return true
  }
  const tick = setInterval(() => {
    if (attach() && clouds.hasField) { clearInterval(tick); resolve() }
  }, 100)
})

/**
 * Render one frame of the dive and resolve once it is actually on the canvas.
 *
 * `map.once('render')` is the handshake: jumpTo alone only schedules a repaint, so grabbing the
 * canvas immediately after would capture the PREVIOUS frame — an off-by-one that shows up as a
 * video lagging the camera by a frame and is nearly impossible to spot in stills.
 */
window.__renderFrame = (seconds) => new Promise((resolve) => {
  clouds.setTime(seconds)
  applyPose(map, samplePose(params.has('park') ? { keyframes: [{ time: 0, lng: -40, lat: 25, altitude: 11000000, heading: 0, tilt: 0 }] } : DIVE_TRACK, seconds))
  map.once('render', () => resolve(map.getCanvas().toDataURL('image/png')))
  map.triggerRepaint()
})

window.__captureReady = ready.then(() => ({
  duration: trackDuration(DIVE_TRACK),
  width: map.getCanvas().width,
  height: map.getCanvas().height,
}))
