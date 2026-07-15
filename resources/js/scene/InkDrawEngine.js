/**
 * InkDrawEngine — draws SVG clipart STROKE-BY-STROKE as hand-drawn ink.
 *
 * Faithful port of Bart's "Ink Lab" pen engine (ink-lab.html, production preset). A canvas
 * ribbon renderer — NOT stroke-dashoffset (which can't taper/wobble). Each shape's outline is
 * sampled into strokes (split at pen jumps), and a virtual pen redraws them every frame with:
 *   • perpendicular seeded wobble (boiling line)   • taper start→end + width jitter
 *   • curvature-weighted timing (slows through curves), long strokes faster (lenFac)
 *   • pen-lift + hand-travel gaps, hesitation, retrace   • stroke overlap
 *   • filled shapes: an ink WASH bleeds in (Path2D fill)   • ink splatter
 *
 * Adapted from the Lab for scenes: clipart is placed into its x/y/scale box on the stage
 * (no Y-flip — clipart is standard top-down SVG, unlike potrace output), and stroke sizing
 * scales with the clipart's rendered height so ink reads right at any size.
 */

const INK = '#191410'

// Ink Lab presets (verbatim). `production` is Bart-approved (2026-07-14).
const PRESETS = {
  production: {
    speed: 620, longBoost: 60, curveSlow: 55, lift: 0.12, overlap: 30, order: 'doc', hesit: 12, retrace: 15,
    width: 2.2, startW: 90, endW: 55, wJitter: 35,
    wobble: 2.1, wFreq: 1.0, splats: 13,
    fillMode: 'wash', toneThr: 0.82, hatchGap: 4, hatchAngle: 35, hatchSpd: 2,
    fillDur: 4.6, fillDelay: 0.8,
  },
}

const smooth = t => t * t * (3 - 2 * t)
const clamp = (v, a, b) => Math.min(b, Math.max(a, v))
const rng = (s) => { let x = (s | 0) || 1; return () => (x = (x * 16807) % 2147483647) / 2147483647 }

export class InkDrawEngine {
  constructor(host) {
    this.host = host
    this.canvas = null
    this.ctx = null
    this._raf = 0
    this._destroyed = false
    this._seed = 11
  }

  /**
   * @param {string} svgUrl
   * @param {{xPct:number,yPct:number,scale:number,heightPct:number}} box centre-anchor placement
   * @param {{loop?:boolean, preset?:string, fillMode?:string, static?:boolean}} [opts]
   */
  async draw(svgUrl, box, opts = {}) {
    this.S = { ...(PRESETS[opts.preset] || PRESETS.production) }
    this._box = box
    this._opts = opts
    this._mountCanvas()
    const parsed = await this._parseSvg(svgUrl)
    if (this._destroyed || !parsed || !parsed.shapes.length) return
    this._vb = parsed.viewBox
    this._shapes = parsed.shapes
    // Auto fill mode: solid clipart (silhouettes) get an ink wash; pure line-art gets none.
    if (opts.fillMode) this.S.fillMode = opts.fillMode
    else this.S.fillMode = parsed.shapes.some(s => s.filled) ? 'wash' : 'none'
    this._sample()
    this._build()
    this._watchResize()
    this._start()
  }

  destroy() {
    this._destroyed = true
    if (this._raf) cancelAnimationFrame(this._raf)
    this._raf = 0
    if (this._ro) { try { this._ro.disconnect() } catch (_) {} this._ro = null }
    if (this.canvas) this.canvas.remove()
    this.canvas = null
  }

  // ── Canvas (device-px backing store, like the Lab; CSS-sized to the host) ──
  _mountCanvas() {
    const canvas = document.createElement('canvas')
    canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;'
    this.host.appendChild(canvas)
    let r = this.host.getBoundingClientRect()
    if (r.width < 2 || r.height < 2) {
      const root = document.getElementById('lesson-canvas-root')
      if (root) r = root.getBoundingClientRect()
    }
    this.DPR = Math.min(2, window.devicePixelRatio || 1)
    this._cssW = r.width || 800
    this._cssH = r.height || 450
    canvas.width = Math.max(1, Math.round(this._cssW * this.DPR))
    canvas.height = Math.max(1, Math.round(this._cssH * this.DPR))
    this.canvas = canvas
    this.ctx = canvas.getContext('2d')
    this._computeBox()
  }

  // Map the clipart's viewBox into its placement box on the stage (device px, no Y-flip).
  _computeBox() {
    if (!this._vb) return
    const { xPct = 50, yPct = 58, scale = 1, heightPct = 40 } = this._box
    const W = this.canvas.width, H = this.canvas.height
    const boxH = (heightPct / 100) * H * scale
    const boxW = boxH * (this._vb.w / this._vb.h)
    this._s = boxH / this._vb.h                    // device px per viewBox unit
    this._left = (xPct / 100) * W - boxW / 2
    this._top = (yPct / 100) * H - boxH / 2
    // DP scales stroke width / wobble / speed with the clipart's on-screen size (Lab used dpr).
    this.DP = this.DPR * clamp((boxH / this.DPR) / 500, 0.5, 2.5)
  }

  _toCanvas(x, y) { return [this._left + (x - this._vb.x) * this._s, this._top + (y - this._vb.y) * this._s] }

  // ── Parse the SVG → viewBox + drawable shapes as path-`d` strings (+ filled flag) ──
  async _parseSvg(url) {
    let text
    try { text = await (await fetch(url)).text() } catch (_) { return null }
    if (this._destroyed) return null
    const holder = document.createElement('div')
    holder.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:0;height:0;overflow:hidden;'
    holder.innerHTML = text
    document.body.appendChild(holder)
    const svg = holder.querySelector('svg')
    if (!svg) { holder.remove(); return null }
    const vbAttr = (svg.getAttribute('viewBox') || '').split(/[\s,]+/).map(Number)
    const viewBox = vbAttr.length === 4
      ? { x: vbAttr[0], y: vbAttr[1], w: vbAttr[2] || 1, h: vbAttr[3] || 1 }
      : { x: 0, y: 0, w: +svg.getAttribute('width') || 100, h: +svg.getAttribute('height') || 100 }

    const shapes = []
    for (const el of svg.querySelectorAll('path, polygon, polyline, line, circle, ellipse, rect')) {
      const d = this._toPathD(el)
      if (!d) continue
      const fill = (el.getAttribute('fill') || el.closest('[fill]')?.getAttribute('fill') || '').trim().toLowerCase()
      const stroked = (el.getAttribute('stroke') || el.closest('[stroke]')?.getAttribute('stroke') || '').trim().toLowerCase()
      const filled = fill !== 'none' && !(fill === '' && stroked && stroked !== 'none')
      shapes.push({ d, filled })
    }
    holder.remove()
    return shapes.length ? { viewBox, shapes } : null
  }

  // Convert any geometry element to a path `d` (so it can be sampled AND wash-filled).
  _toPathD(el) {
    const A = (n) => parseFloat(el.getAttribute(n)) || 0
    switch (el.tagName.toLowerCase()) {
      case 'path': return el.getAttribute('d') || ''
      case 'rect': { const x = A('x'), y = A('y'), w = A('width'), h = A('height'); return w && h ? `M${x},${y} H${x + w} V${y + h} H${x} Z` : '' }
      case 'polygon': { const p = (el.getAttribute('points') || '').trim(); return p ? 'M' + p + ' Z' : '' }
      case 'polyline': { const p = (el.getAttribute('points') || '').trim(); return p ? 'M' + p : '' }
      case 'line': return `M${A('x1')},${A('y1')} L${A('x2')},${A('y2')}`
      case 'circle': { const cx = A('cx'), cy = A('cy'), r = A('r'); return r ? `M${cx - r},${cy} a${r},${r} 0 1,0 ${r * 2},0 a${r},${r} 0 1,0 ${-r * 2},0 Z` : '' }
      case 'ellipse': { const cx = A('cx'), cy = A('cy'), rx = A('rx'), ry = A('ry'); return rx && ry ? `M${cx - rx},${cy} a${rx},${ry} 0 1,0 ${rx * 2},0 a${rx},${ry} 0 1,0 ${-rx * 2},0 Z` : '' }
      default: return ''
    }
  }

  // Sample every shape into segments (viewBox coords, split at pen jumps between subpaths).
  _sample() {
    const NS = 'http://www.w3.org/2000/svg'
    const sampler = document.createElementNS(NS, 'svg')
    sampler.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:0;height:0;'
    document.body.appendChild(sampler)
    const RAW = Math.max(this._vb.w, this._vb.h)
    const step = RAW / 550
    this._segs = []
    this._shapes.forEach((shape, pi) => {
      const p = document.createElementNS(NS, 'path')
      p.setAttribute('d', shape.d); sampler.appendChild(p)
      let L = 0; try { L = p.getTotalLength() } catch (_) { return }
      if (!L) return
      let seg = null, prev = null
      for (let l = 0; l <= L; l += step) {
        const pt = p.getPointAtLength(Math.min(l, L))
        if (prev && Math.hypot(pt.x - prev.x, pt.y - prev.y) > step * 3) seg = null
        if (!seg) { seg = { pathIdx: pi, pts: [] }; this._segs.push(seg); prev = null }
        const c = seg.pts.length ? seg.pts[seg.pts.length - 1].c + Math.hypot(pt.x - prev.x, pt.y - prev.y) : 0
        seg.pts.push({ x: pt.x, y: pt.y, c }); prev = pt
      }
    })
    sampler.remove()
  }

  // ── Stroke factory (verbatim from the Lab, using this.S / this.DP) ──
  _makeStroke(rawPts, opts, rand) {
    const S = this.S, DP = this.DP
    const ph = [...Array(6)].map(() => rand() * 6.283)
    const noise = u => (Math.sin(u * 1.7 + ph[0]) + .5 * Math.sin(u * 3.3 + ph[1]) + .25 * Math.sin(u * 6.1 + ph[2])) / 1.75
    const nW = u => (Math.sin(u * 2.3 + ph[3]) + .5 * Math.sin(u * 4.7 + ph[4])) / 1.5
    const pts = [], cum = [0], tw = [0]
    let cl = 0
    for (let i = 0; i < rawPts.length; i++) {
      const P = rawPts[i]
      const A = rawPts[Math.max(0, i - 1)], B = rawPts[Math.min(rawPts.length - 1, i + 1)]
      let nx = -(B.y - A.y), ny = B.x - A.x; const nl = Math.hypot(nx, ny) || 1; nx /= nl; ny /= nl
      if (i) cl += Math.hypot(P.x - rawPts[i - 1].x, P.y - rawPts[i - 1].y)
      const t = i / (rawPts.length - 1 || 1)
      const off = opts.wobble * noise(cl * 0.02 * S.wFreq / DP)
      const prof = (opts.sw) + (opts.ew - opts.sw) * smooth(t)
      const w = opts.width * prof * (1 + opts.jit * nW(cl * 0.03 / DP))
      pts.push({ x: P.x + nx * off, y: P.y + ny * off, w: Math.max(0.2, w) })
      if (i) {
        cum.push(cl)
        const C = rawPts[Math.min(rawPts.length - 1, i + 1)]
        const a1 = Math.atan2(P.y - rawPts[i - 1].y, P.x - rawPts[i - 1].x)
        const a2 = Math.atan2(C.y - P.y, C.x - P.x)
        let da = Math.abs(a2 - a1); if (da > Math.PI) da = 2 * Math.PI - da
        const wgt = 1 + opts.curve * da * 5
        tw.push(tw[i - 1] + (cum[i] - cum[i - 1]) * clamp(wgt, 1, 7))
      }
    }
    return { pts, cum, tw, len: cl, twTotal: tw[tw.length - 1] || 1 }
  }

  _strokeUpto(s, fr) {
    const target = smooth(fr) * s.twTotal
    let lo = 0, hi = s.tw.length - 1
    while (lo < hi) { const mid = (lo + hi) >> 1; s.tw[mid] < target ? lo = mid + 1 : hi = mid }
    return s.cum[lo]
  }

  // ── Build the timeline: outline strokes + wash + splatter (verbatim logic) ──
  _build() {
    const S = this.S, DP = this.DP, rand = rng(this._seed)
    this._strokes = []; this._washes = []; this._splats = []
    let segs = this._segs
    const avgRaw = segs.reduce((a, s) => a + (s.pts[s.pts.length - 1].c || 1), 0) / (segs.length || 1)
    let t0 = 0.2, prevTip = null; const pathEnd = {}

    segs.forEach(seg => {
      const rawLen = seg.pts[seg.pts.length - 1].c || 1
      const lenFac = clamp(Math.pow(rawLen / avgRaw, (S.longBoost / 100) * 0.55), 0.55, 2.6)
      const cpts = seg.pts.map(P => { const [x, y] = this._toCanvas(P.x, P.y); return { x, y } })
      const st = this._makeStroke(cpts, {
        wobble: S.wobble * DP / Math.pow(lenFac, 0.9),
        width: S.width * DP, sw: S.startW / 100, ew: S.endW / 100,
        jit: S.wJitter / 100, curve: S.curveSlow / 100,
      }, rand)
      st.start = t0
      st.dur = Math.max(0.08, st.len / (S.speed * DP * lenFac))
      this._strokes.push(st)
      let lastSt = st, endT = st.start + st.dur
      // retrace: the first line wasn't right — go over part of it again
      if (rand() < S.retrace / 100 && st.len > 40 * DP && cpts.length > 8) {
        const a = Math.floor(cpts.length * rand() * 0.3)
        const b = Math.min(cpts.length - 1, a + Math.ceil(cpts.length * (0.4 + rand() * 0.5)))
        const sub = cpts.slice(a, b + 1)
        if (sub.length > 3) {
          const st2 = this._makeStroke(sub, {
            wobble: S.wobble * DP * 1.15 / Math.pow(lenFac, 0.9),
            width: S.width * DP * 0.8, sw: S.startW / 100, ew: S.endW / 100,
            jit: S.wJitter / 100, curve: S.curveSlow / 100,
          }, rand)
          st2.start = endT + 0.1 + rand() * 0.25
          st2.dur = Math.max(0.08, st2.len / (S.speed * DP * lenFac * 0.85))
          st2.alpha = 0.72
          this._strokes.push(st2)
          lastSt = st2; endT = st2.start + st2.dur
        }
      }
      pathEnd[seg.pathIdx] = Math.max(pathEnd[seg.pathIdx] || 0, endT)
      const tip = lastSt.pts[lastSt.pts.length - 1]
      const jump = prevTip ? Math.hypot(tip.x - prevTip.x, tip.y - prevTip.y) : 0
      let gap = S.lift * (0.6 + rand() * 0.8) + jump / (4000 * DP)
      if (rand() < S.hesit / 100) gap += 0.25 + rand() * 0.8
      t0 = endT - lastSt.dur * (S.overlap / 100) + gap
      prevTip = tip
    })
    const outlineEnd = Math.max(...this._strokes.map(s => s.start + s.dur), 0.5)

    // Ink wash — filled shapes bleed in after their outline is drawn.
    if (S.fillMode === 'wash') {
      this._shapes.forEach((shape, pi) => {
        if (!shape.filled || !(pi in pathEnd)) return
        this._washes.push({ p2: new Path2D(shape.d), start: pathEnd[pi] + S.fillDelay, dur: S.fillDur })
      })
    }

    // Splatter near stroke points.
    const allPts = this._strokes.flatMap(s => s.pts)
    const drawEnd = Math.max(...this._strokes.map(s => s.start + s.dur), 1)
    for (let i = 0; i < S.splats && allPts.length; i++) {
      const a = allPts[Math.floor(rand() * allPts.length)]
      const r = (1 + rand() * 5) * DP * (rand() > 0.8 ? 2.5 : 1)
      const nB = 8 + Math.floor(rand() * 5), poly = []
      for (let j = 0; j <= nB; j++) { const th = j / nB * 6.283, rr = r * (0.5 + rand()); poly.push([a.x + Math.cos(th) * rr + (rand() - .5) * 8 * DP, a.y + Math.sin(th) * rr + (rand() - .5) * 8 * DP]) }
      this._splats.push({ poly, t: 0.3 + rand() * drawEnd, alpha: 0.2 + rand() * 0.5 })
    }

    this.T = Math.max(drawEnd, ...this._washes.map(w => w.start + w.dur), 1) + 0.4
    if (!Number.isFinite(this.T) || this.T <= 0) this.T = 6

    // Scenes need a bounded draw-on: the Lab's raw timeline can run 60–80s for a detailed
    // clipart. Compress the whole timeline (keeps the relative dynamics) to a target duration.
    const target = this._opts?.durationSec || 7
    if (this.T > target) {
      const k = target / this.T
      this._strokes.forEach(s => { s.start *= k; s.dur *= k })
      this._washes.forEach(w => { w.start *= k; w.dur *= k })
      this._splats.forEach(s => { s.t *= k })
      this.T = target
    }
  }

  // ── Render one frame (verbatim ribbon + draw order) ──
  _ribbon(s, uptoLen) {
    const ctx = this.ctx
    let n = s.cum.length - 1
    for (let i = 1; i < s.cum.length; i++) if (s.cum[i] > uptoLen) { n = i; break }
    if (n < 1) n = 1
    ctx.beginPath()
    for (let i = 0; i <= n; i++) {
      const P = s.pts[i], A = s.pts[Math.max(0, i - 1)], B = s.pts[Math.min(s.pts.length - 1, i + 1)]
      let nx = -(B.y - A.y), ny = B.x - A.x; const nl = Math.hypot(nx, ny) || 1; nx /= nl; ny /= nl
      const X = P.x + nx * P.w / 2, Y = P.y + ny * P.w / 2
      i ? ctx.lineTo(X, Y) : ctx.moveTo(X, Y)
    }
    for (let i = n; i >= 0; i--) {
      const P = s.pts[i], A = s.pts[Math.max(0, i - 1)], B = s.pts[Math.min(s.pts.length - 1, i + 1)]
      let nx = -(B.y - A.y), ny = B.x - A.x; const nl = Math.hypot(nx, ny) || 1; nx /= nl; ny /= nl
      ctx.lineTo(P.x - nx * P.w / 2, P.y - ny * P.w / 2)
    }
    ctx.closePath(); ctx.fill()
    const tip = s.pts[n], root = s.pts[0]
    ctx.beginPath(); ctx.arc(root.x, root.y, root.w / 2, 0, 6.283); ctx.fill()
    ctx.beginPath(); ctx.arc(tip.x, tip.y, tip.w / 2, 0, 6.283); ctx.fill()
    return tip
  }

  _render(t) {
    const ctx = this.ctx
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.clearRect(0, 0, this.canvas.width, this.canvas.height)
    // Washes (fill the shape in viewBox space → box transform, no Y-flip).
    this._washes.forEach(w => {
      if (t <= w.start) return
      const fr = Math.min(1, (t - w.start) / w.dur)
      ctx.save(); ctx.globalAlpha = smooth(fr) * 0.96; ctx.fillStyle = INK
      ctx.setTransform(this._s, 0, 0, this._s, this._left - this._vb.x * this._s, this._top - this._vb.y * this._s)
      ctx.fill(w.p2, 'evenodd'); ctx.restore()
    })
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.fillStyle = INK
    let penTip = null
    this._strokes.forEach(s => {
      if (t <= s.start) return
      ctx.globalAlpha = s.alpha || 0.94
      const fr = Math.min(1, (t - s.start) / s.dur)
      const tip = this._ribbon(s, fr >= 1 ? s.len : this._strokeUpto(s, fr))
      if (fr < 1) penTip = tip
    })
    if (penTip) { ctx.beginPath(); ctx.globalAlpha = 0.85; ctx.arc(penTip.x, penTip.y, Math.max(2, this.S.width * this.DP * 0.7), 0, 6.283); ctx.fill() }
    this._splats.forEach(sp => {
      if (t <= sp.t) return
      ctx.globalAlpha = sp.alpha; ctx.beginPath()
      sp.poly.forEach((p, i) => i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1]))
      ctx.closePath(); ctx.fill()
    })
    ctx.globalAlpha = 1
  }

  // ── Transport: rAF loop; loops the draw-on for the editor preview ──
  _start() {
    const reduce = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (reduce || this._opts?.static) { this._render(this.T); return }
    this._time = 0; this._last = 0
    const frame = (ts) => {
      if (this._destroyed) return
      if (this._last) this._time += (ts - this._last) / 1000
      this._last = ts
      if (this._time >= this.T) { if (this._opts?.loop) this._time = 0; else { this._render(this.T); return } }
      this._render(this._time)
      this._raf = requestAnimationFrame(frame)
    }
    this._raf = requestAnimationFrame(frame)
  }

  // Self-heal if the host was 0-sized at mount (mounted before layout).
  _watchResize() {
    if (this._ro || typeof ResizeObserver !== 'function') return
    this._ro = new ResizeObserver(() => {
      const r = this.host.getBoundingClientRect()
      if (r.width < 2 || r.height < 2) return
      if (Math.abs(r.width - this._cssW) < 1 && Math.abs(r.height - this._cssH) < 1) return
      if (this._raf) cancelAnimationFrame(this._raf)
      this._cssW = r.width; this._cssH = r.height
      this.canvas.width = Math.round(r.width * this.DPR)
      this.canvas.height = Math.round(r.height * this.DPR)
      this.ctx = this.canvas.getContext('2d')
      this._computeBox()
      this._build()
      this._start()
    })
    this._ro.observe(this.host)
  }
}
