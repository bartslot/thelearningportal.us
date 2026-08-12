/**
 * controls.js — sliders for every knob, so the look can be decided by eye instead of by rebuild.
 *
 * This is the dat.GUI / lil-gui idea, hand-rolled. Not because rolling it is better, but because
 * the harness has to work with no network: the whole point of it is that nothing outside this
 * repository can be the reason the sun did not appear, and a CDN script tag reintroduces exactly
 * that. It is about a hundred lines and needs no build step.
 *
 * NOTE FOR ANYONE ARRIVING FROM THE THREE.JS REFERENCE: there is no three.js here and no dat.GUI.
 * The globe is MapLibre custom layers with hand-written GLSL. The panel behaves the same way; what
 * it is driving underneath is different.
 *
 * COPY SETTINGS is the point of the whole thing. Sliders that only change the running page are a
 * toy — the value is in landing on a look and getting it back out as the exact options object to
 * paste into the layer's defaults.
 */

const PANEL_CSS = `
  #controls {
    position: fixed; top: 12px; right: 12px; width: 268px; max-height: calc(100vh - 24px);
    overflow-y: auto; z-index: 10; font: 12px/1.4 ui-sans-serif, system-ui, sans-serif;
    background: rgba(10, 14, 26, 0.86); backdrop-filter: blur(12px); color: #e2e8f0;
    border: 1px solid rgba(148, 163, 184, 0.22); border-radius: 10px; padding: 4px 0 8px;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
  }
  #controls.hidden { display: none; }
  #controls h2 {
    margin: 10px 12px 6px; font-size: 10px; font-weight: 600; letter-spacing: 0.09em;
    text-transform: uppercase; color: #f59e0b;
  }
  #controls .row { display: grid; grid-template-columns: 1fr auto; gap: 2px 8px; padding: 3px 12px; }
  #controls .row label { color: #cbd5e1; }
  #controls .row output { color: #94a3b8; font-variant-numeric: tabular-nums; }
  #controls input[type=range] { grid-column: 1 / -1; width: 100%; height: 16px; accent-color: #f59e0b; }
  #controls input[type=color] { grid-column: 1 / -1; width: 100%; height: 22px; padding: 0; border: 0; background: none; }
  #controls .check { display: flex; align-items: center; gap: 8px; padding: 4px 12px; }
  #controls button {
    margin: 8px 12px 0; width: calc(100% - 24px); padding: 6px; cursor: pointer;
    background: #f59e0b; color: #0f172a; border: 0; border-radius: 6px; font: inherit; font-weight: 600;
  }
  #controls button.ghost { background: rgba(148, 163, 184, 0.16); color: #e2e8f0; }
  #controls .hint { padding: 6px 12px 0; color: #64748b; font-size: 11px; }
`

const hex = ([r, g, b]) => '#' + [r, g, b]
  .map((v) => Math.round(Math.min(1, Math.max(0, v)) * 255).toString(16).padStart(2, '0')).join('')
const fromHex = (value) => [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16) / 255)

/**
 * @param {object} deps
 * @param {object} deps.layers    the live layers, by name
 * @param {Function} deps.setPose re-solve the camera for { beyondLimb, radii }
 * @param {Function} deps.setDate move the whole scene to a moment
 */
export const mountControls = ({ layers, setPose, setDate, initialPose, initialDate }) => {
  const style = document.createElement('style')
  style.textContent = PANEL_CSS
  document.head.append(style)

  const panel = document.createElement('div')
  panel.id = 'controls'
  document.body.append(panel)

  const pose = { ...initialPose }
  let dayOfYear = Math.floor((initialDate - Date.UTC(initialDate.getUTCFullYear(), 0, 0)) / 86400000)
  let hourUtc = initialDate.getUTCHours()

  const section = (title) => {
    const heading = document.createElement('h2')
    heading.textContent = title
    panel.append(heading)
  }

  /** A labelled slider bound to a getter and a setter. */
  const slider = (label, { min, max, step, get, set, format = (v) => v.toFixed(2) }) => {
    const row = document.createElement('div')
    row.className = 'row'
    const name = document.createElement('label')
    name.textContent = label
    const readout = document.createElement('output')
    const input = document.createElement('input')
    Object.assign(input, { type: 'range', min, max, step, value: get() })
    const sync = () => { readout.textContent = format(Number(input.value)) }
    input.addEventListener('input', () => { set(Number(input.value)); sync() })
    sync()
    row.append(name, readout, input)
    panel.append(row)
  }

  const colour = (label, { get, set }) => {
    const row = document.createElement('div')
    row.className = 'row'
    const name = document.createElement('label')
    name.textContent = label
    const input = document.createElement('input')
    Object.assign(input, { type: 'color', value: hex(get()) })
    input.addEventListener('input', () => set(fromHex(input.value)))
    row.append(name, document.createElement('output'), input)
    panel.append(row)
  }

  const toggle = (label, { get, set }) => {
    const row = document.createElement('label')
    row.className = 'check'
    const input = document.createElement('input')
    Object.assign(input, { type: 'checkbox', checked: get() })
    input.addEventListener('change', () => set(input.checked))
    row.append(input, document.createTextNode(label))
    panel.append(row)
  }

  const sun = (key) => ({
    get: () => layers.sun.getOptions()[key],
    set: (value) => layers.sun.setOptions({ [key]: value }),
  })

  /** Bind a control to one option of one layer. Every layer here takes the same get/setOptions. */
  const bind = (layer) => (key) => ({
    get: () => layers[layer].getOptions()[key],
    set: (value) => layers[layer].setOptions({ [key]: value }),
  })
  const bloom = bind('bloom')
  const shafts = bind('shafts')
  const atmosphere = bind('atmosphere')

  // ── Where the camera is ──────────────────────────────────────────────────────────────────────
  // First, because the sun is only in frame at all when the camera is near antisolar: the view axis
  // points at the planet and the sun has to be within half a field of view of it. Drag the globe by
  // hand and it is gone in a second, which is why "find the sun again" is a button.
  section('Camera')
  slider('Degrees past the limb', {
    min: -1, max: 12, step: 0.05,
    get: () => pose.beyondLimb,
    set: (value) => { pose.beyondLimb = value; setPose(pose) },
    format: (v) => `${v.toFixed(2)}°`,
  })
  slider('Distance (earth radii)', {
    min: 1.6, max: 24, step: 0.1,
    get: () => pose.radii,
    set: (value) => { pose.radii = value; setPose(pose) },
    format: (v) => `${v.toFixed(1)}×`,
  })

  section('Date')
  /**
   * Moving the date moves the sun, so the camera has to follow it.
   *
   * Without the re-solve, the hour slider is a slider that makes the sun disappear: the pose was
   * solved against one subsolar point and three hours later the planet is in the way. The point of
   * the control is to watch the light change, not to lose the subject.
   */
  const applyDate = () => {
    setDate(new Date(Date.UTC(2026, 0, dayOfYear, hourUtc)))
    setPose({})
  }
  slider('Day of year', {
    min: 1, max: 365, step: 1,
    get: () => dayOfYear,
    set: (value) => { dayOfYear = value; applyDate() },
    format: (v) => new Date(Date.UTC(2026, 0, v)).toUTCString().slice(5, 11),
  })
  slider('Hour UTC', {
    min: 0, max: 23, step: 1,
    get: () => hourUtc,
    set: (value) => { hourUtc = value; applyDate() },
    format: (v) => `${String(v).padStart(2, '0')}:00`,
  })

  // ── The sun itself ───────────────────────────────────────────────────────────────────────────
  section('Sun disc')
  slider('Exposure (disc gain)', {
    min: 1, max: 3, step: 0.01, ...sun('discGain'),
  })
  slider('Brightness', { min: 0, max: 2, step: 0.01, ...sun('brightness') })
  colour('Core colour', sun('coreColour'))

  section('Analytic glare')
  slider('Reach (solar radii)', {
    min: 4, max: 48, step: 0.5, ...sun('haloScale'), format: (v) => `${v.toFixed(0)}×`,
  })
  slider('Strength', { min: 0, max: 2.5, step: 0.01, ...sun('haloStrength') })
  colour('Halo colour', sun('haloColour'))

  section('Bloom pass')
  slider('Strength', { min: 0, max: 3, step: 0.01, ...bloom('strength') })
  slider('Threshold', { min: 0, max: 1, step: 0.01, ...bloom('threshold') })
  slider('Knee', { min: 0, max: 0.5, step: 0.005, ...bloom('knee') })
  slider('Spread (radius)', { min: 0.25, max: 3, step: 0.05, ...bloom('radius') })
  toggle('Chromatic falloff', bloom('chromatic'))

  // ── The light doing something to what it passes through ──────────────────────────────────────
  section('Light shafts')
  slider('Strength', { min: 0, max: 2.5, step: 0.01, ...shafts('strength') })
  slider('Occluder threshold', { min: 0, max: 1, step: 0.01, ...shafts('threshold') })
  slider('Length (density)', { min: 0.1, max: 1, step: 0.01, ...shafts('density') })
  slider('Decay', { min: 0.85, max: 0.999, step: 0.001, ...shafts('decay'), format: (v) => v.toFixed(3) })
  slider('Exposure', { min: 0, max: 1, step: 0.01, ...shafts('exposure') })
  colour('Shaft colour', shafts('tint'))

  section('Clouds')
  slider('Opacity', { min: 0, max: 1, step: 0.01, ...bind('clouds')('opacity') })

  section('Haze')
  slider('Band strength', { min: 0, max: 3, step: 0.01, ...atmosphere('strength') })
  slider('Forward scatter', { min: 0, max: 1.5, step: 0.01, ...atmosphere('forwardScatter') })
  slider('Lobe narrowness', { min: 0, max: 0.92, step: 0.01, ...atmosphere('scatterG') })

  // ── Getting a decision back out ──────────────────────────────────────────────────────────────
  const copy = document.createElement('button')
  copy.textContent = 'Copy these settings'
  copy.addEventListener('click', async () => {
    const drop = new Set(['date', 'sun'])
    const asOptions = (options) => Object.fromEntries(
      Object.entries(options).filter(([key]) => !drop.has(key)),
    )
    const text = [
      `createSunLayer(${JSON.stringify(asOptions(layers.sun.getOptions()), null, 2)})`,
      `createBloomLayer(${JSON.stringify(asOptions(layers.bloom.getOptions()), null, 2)})`,
      `createLightShaftLayer(${JSON.stringify(asOptions(layers.shafts.getOptions()), null, 2)})`,
      `createAtmosphereLayer(${JSON.stringify(asOptions(layers.atmosphere.getOptions()), null, 2)})`,
      `// camera: ${JSON.stringify(pose)}  date: day ${dayOfYear}, ${hourUtc}:00 UTC`,
    ].join('\n\n')
    try {
      await navigator.clipboard.writeText(text)
      copy.textContent = 'Copied'
    } catch {
      // Clipboard access needs a secure context, and this harness is plain http on localhost in
      // some browsers. Falling back to the console beats a button that silently does nothing.
      console.log(text)
      copy.textContent = 'Logged to console'
    }
    setTimeout(() => { copy.textContent = 'Copy these settings' }, 1600)
  })
  panel.append(copy)

  const recentre = document.createElement('button')
  recentre.className = 'ghost'
  recentre.textContent = 'Find the sun again'
  recentre.addEventListener('click', () => setPose(pose))
  panel.append(recentre)

  const hint = document.createElement('p')
  hint.className = 'hint'
  hint.textContent = 'H hides this panel. Drag to orbit, and the sun will leave frame almost at once — it is only visible from near the night side.'
  panel.append(hint)

  addEventListener('keydown', (event) => {
    if (event.key.toLowerCase() === 'h' && !event.metaKey && !event.ctrlKey) panel.classList.toggle('hidden')
  })

  return panel
}
