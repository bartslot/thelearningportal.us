import { PNG } from 'pngjs';

export type Frame = { width: number; height: number; data: Buffer };

export type Patch = { x: number; y: number; w: number; h: number };

/** Decode a Playwright screenshot into raw RGBA. */
export const decode = (png: Buffer): Frame => {
  const img = PNG.sync.read(png);
  return { width: img.width, height: img.height, data: img.data };
}

const at = (f: Frame, x: number, y: number) => {
  const i = (y * f.width + x) * 4;
  return [f.data[i], f.data[i + 1], f.data[i + 2]];
}

/** Rec. 709 luma, 0..255. */
export const luma = ([r, g, b]: number[]) => 0.2126 * r + 0.7152 * g + 0.0722 * b;

const clampPatch = (f: Frame, p: Patch): Patch => ({
  x: Math.max(0, Math.min(f.width - 2, Math.round(p.x))),
  y: Math.max(0, Math.min(f.height - 2, Math.round(p.y))),
  w: Math.max(1, Math.min(f.width - Math.round(p.x) - 1, Math.round(p.w))),
  h: Math.max(1, Math.min(f.height - Math.round(p.y) - 1, Math.round(p.h))),
});

/** Mean colour of a patch. */
export const meanColour = (f: Frame, patch: Patch): number[] => {
  const p = clampPatch(f, patch);
  let r = 0, g = 0, b = 0, n = 0;
  for (let y = p.y; y < p.y + p.h; y++) {
    for (let x = p.x; x < p.x + p.w; x++) {
      const c = at(f, x, y);
      r += c[0]; g += c[1]; b += c[2]; n++;
    }
  }
  return [r / n, g / n, b / n];
}

/**
 * Standard deviation of luma across a patch — "how much is going on here".
 *
 * This is what separates photographed ground from a flat fill: Blue Marble over the Sahara is all
 * dune shadow and rock, a drawn atlas is one beige. No cloud needed, no exact pixels compared.
 */
export const detail = (f: Frame, patch: Patch): number => {
  const p = clampPatch(f, patch);
  const vals: number[] = [];
  for (let y = p.y; y < p.y + p.h; y++) {
    for (let x = p.x; x < p.x + p.w; x++) vals.push(luma(at(f, x, y)));
  }
  const mean = vals.reduce((a, b) => a + b, 0) / vals.length;
  return Math.sqrt(vals.reduce((a, v) => a + (v - mean) ** 2, 0) / vals.length);
}

/**
 * Mean absolute Laplacian — high-frequency energy, i.e. EDGE SHARPNESS.
 *
 * Over open ocean the only structure is cloud, so this number is a direct measure of how crisp the
 * cloud deck is. It is the one metric that would have put a figure on "the huge images are still
 * stretched, so we see low quality weird edges": blur pushes it down, real detail pushes it up.
 */
export const sharpness = (f: Frame, patch: Patch): number => {
  const p = clampPatch(f, patch);
  let sum = 0, n = 0;
  for (let y = p.y + 1; y < p.y + p.h - 1; y++) {
    for (let x = p.x + 1; x < p.x + p.w - 1; x++) {
      const c = luma(at(f, x, y));
      const lap = 4 * c - luma(at(f, x - 1, y)) - luma(at(f, x + 1, y))
        - luma(at(f, x, y - 1)) - luma(at(f, x, y + 1));
      sum += Math.abs(lap); n++;
    }
  }
  return sum / n;
}

/** Fraction of pixels brighter than `threshold` — used to find stars against space. */
export const brightFraction = (f: Frame, patch: Patch, threshold: number): number => {
  const p = clampPatch(f, patch);
  let hit = 0, n = 0;
  for (let y = p.y; y < p.y + p.h; y++) {
    for (let x = p.x; x < p.x + p.w; x++) {
      if (luma(at(f, x, y)) > threshold) hit++;
      n++;
    }
  }
  return hit / n;
}

/**
 * Fraction of pixels close to a target hue and strongly saturated.
 *
 * For the territory specks: the selected fill is a hard amber, and amber has no business appearing
 * over open ocean. A mean colour would never catch it — 94,000 specks of it still average away
 * against a whole sea.
 */
export const saturatedHueFraction = (f: Frame, patch: Patch, target: number[], tolerance = 46): number => {
  const p = clampPatch(f, patch);
  let hit = 0, n = 0;
  for (let y = p.y; y < p.y + p.h; y++) {
    for (let x = p.x; x < p.x + p.w; x++) {
      const [r, g, b] = at(f, x, y);
      const near = Math.abs(r - target[0]) < tolerance
        && Math.abs(g - target[1]) < tolerance
        && Math.abs(b - target[2]) < tolerance;
      const saturated = Math.max(r, g, b) - Math.min(r, g, b) > 40;
      if (near && saturated) hit++;
      n++;
    }
  }
  return hit / n;
}
