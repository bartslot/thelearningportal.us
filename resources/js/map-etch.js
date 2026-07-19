/**
 * map-etch.js — engraved "water lines" along coastlines, horizontal strokes only,
 * after the copperplate convention (Van Braam's Tasman chart): short horizontal ink
 * lines hugging the shore, densest at the water's edge, dissolving toward open sea.
 *
 * Implementation: a DOM canvas over the map. Per (throttled) camera change we
 * rasterize the land silhouette from the vector `land` source into an offscreen
 * mask, erase whatever the voyage fog currently covers (undiscovered coasts get
 * no water lines), dilate the silhouette with two blur passes (near/far bands),
 * then scan world-anchored latitude rows and stroke the horizontal runs that fall
 * in water. Rows are anchored to a latitude grid so lines stick to the map while
 * the camera pans instead of swimming in screen space.
 */

const THROTTLE_MS = 180;
const INK = 'rgba(56, 78, 94, 0.5)';
const NEAR_BLUR = 4;   // px — tight band, double line density
const FAR_BLUR = 10;   // px — wide band, base density
const NEAR_A = 46;     // alpha thresholds into the blurred silhouette
const FAR_A = 26;
const TARGET_ROW_PX = 6.5; // desired on-screen spacing of the base rows

export function attachShoreEtch(map, { getFog = () => null } = {}) {
  const container = map.getContainer();
  const canvas = document.createElement('canvas');
  canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:5;mix-blend-mode:multiply;';
  container.appendChild(canvas);
  const ctx = canvas.getContext('2d');

  const scratch = document.createElement('canvas');
  const sctx = scratch.getContext('2d', { willReadFrequently: true });

  let timer = null;
  let destroyed = false;

  // Fill one polygon/multipolygon geometry into a 2D context in screen space.
  const fillGeom = (c, geom) => {
    const polys = geom.type === 'Polygon' ? [geom.coordinates]
      : geom.type === 'MultiPolygon' ? geom.coordinates : [];
    for (const poly of polys) {
      c.beginPath();
      for (const ring of poly) {
        ring.forEach(([lng, lat], i) => {
          const p = map.project([lng, lat]);
          if (i === 0) c.moveTo(p.x, p.y); else c.lineTo(p.x, p.y);
        });
        c.closePath();
      }
      c.fill();
    }
  };

  const render = () => {
    if (destroyed) return;
    const w = container.clientWidth;
    const h = container.clientHeight;
    if (!w || !h || w * h > 4_000_000) return;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    scratch.width = w;
    scratch.height = h;

    // ── 1. Land silhouette minus fog ──────────────────────────────────────
    sctx.clearRect(0, 0, w, h);
    sctx.fillStyle = '#000';
    const feats = map.querySourceFeatures('land', { sourceLayer: 'land' });
    if (!feats.length) return;
    for (const f of feats) fillGeom(sctx, f.geometry);
    const fog = getFog();
    if (fog?.features?.length) {
      sctx.globalCompositeOperation = 'destination-out';
      for (const f of fog.features) fillGeom(sctx, f.geometry);
      sctx.globalCompositeOperation = 'source-over';
    }
    const land = sctx.getImageData(0, 0, w, h).data;

    // ── 2. Dilated bands (blur the silhouette outward) ────────────────────
    const band = (blurPx) => {
      sctx.clearRect(0, 0, w, h);
      sctx.filter = `blur(${blurPx}px)`;
      sctx.drawImage(canvasFromMask(land, w, h), 0, 0);
      sctx.filter = 'none';
      return sctx.getImageData(0, 0, w, h).data;
    };
    const near = band(NEAR_BLUR);
    const far = band(FAR_BLUR);

    // ── 3. Horizontal strokes on a world-anchored latitude grid ───────────
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    ctx.strokeStyle = INK;
    ctx.lineWidth = 0.75;

    const latTop = map.unproject([0, 0]).lat;
    const latBot = map.unproject([0, h]).lat;
    const degPerPx = (latTop - latBot) / h;
    // Snap the step to a power-of-two degree grid: rows keep identical latitudes
    // across recomputes and zoom levels, so the etching never "reflows" mid-pan.
    const step = 2 ** Math.round(Math.log2(TARGET_ROW_PX * degPerPx));

    const rows = [];
    for (let lat = Math.floor(latBot / step) * step; lat <= latTop; lat += step) {
      rows.push([lat, false]);
      rows.push([lat + step / 2, true]); // half-rows render only in the near band
    }

    for (const [lat, nearOnly] of rows) {
      const y = Math.round(map.project([map.getCenter().lng, lat]).y);
      if (y < 0 || y >= h) continue;
      const rowSeed = Math.abs(Math.round(lat * 997)) % 89;
      let runStart = -1;
      for (let x = 0; x <= w; x++) {
        const i = (y * w + x) * 4 + 3;
        const inWater = x < w && land[i] < 8;
        const a = x < w ? (nearOnly ? near[i] : far[i]) : 0;
        const on = inWater && a > (nearOnly ? NEAR_A : FAR_A)
          && ((x + rowSeed) % 97) > 5; // ragged pen-lift gaps
        if (on && runStart < 0) runStart = x;
        if (!on && runStart >= 0) {
          if (x - runStart > 3) {
            const wob = Math.sin(runStart * 0.05 + rowSeed) * 0.35;
            ctx.beginPath();
            ctx.moveTo(runStart, y + wob);
            ctx.lineTo(x, y + wob);
            ctx.stroke();
          }
          runStart = -1;
        }
      }
    }
  };

  // ImageData alpha → drawable canvas (blur source for the band pass).
  const maskCanvas = document.createElement('canvas');
  const mctx = maskCanvas.getContext('2d');
  function canvasFromMask(data, w, h) {
    maskCanvas.width = w;
    maskCanvas.height = h;
    const img = mctx.createImageData(w, h);
    for (let i = 3; i < data.length; i += 4) img.data[i] = data[i];
    mctx.putImageData(img, 0, 0);
    return maskCanvas;
  }

  const schedule = () => {
    if (timer) return;
    timer = setTimeout(() => { timer = null; render(); }, THROTTLE_MS);
  };
  map.on('move', schedule);
  map.on('idle', schedule);
  render();

  return {
    refresh: schedule,
    destroy() {
      destroyed = true;
      if (timer) clearTimeout(timer);
      map.off('move', schedule);
      map.off('idle', schedule);
      canvas.remove();
    },
  };
}
