import { describe, it, expect } from 'vitest'
import { projectedMpp } from '../voyage-ships.js'

/**
 * The ships are drawn as world-sized objects, so their apparent size is (metres / metres-per-pixel).
 * Get the second number wrong and the first is wrong by the same factor — which is how a caravel
 * came to span an ocean when the globe was viewed whole.
 */
describe('projectedMpp', () => {
  it('measures the real scale instead of assuming Mercator', () => {
    // A projection where a quarter degree of latitude is 5 screen pixels.
    const project = ([, lat]) => ({ x: 0, y: lat * (5 / 0.25) });
    expect(projectedMpp(project, 0, 40, 99999)).toBeCloseTo((0.25 * 111320) / 5, 3);
  });

  it('falls back when the two points collapse — the far side of the globe', () => {
    // Everything lands on the same pixel, which is what happens behind the planet.
    const collapsed = () => ({ x: 10, y: 10 });
    expect(projectedMpp(collapsed, 0, 40, 4242)).toBe(4242);
  });

  it('falls back when the projection throws rather than returning a wrong size', () => {
    const throws = () => { throw new Error('off globe'); };
    expect(projectedMpp(throws, 0, 40, 777)).toBe(777);
  });

  it('reports MORE metres per pixel when the map is zoomed out, which is the whole point', () => {
    const close = ([, lat]) => ({ x: 0, y: lat * (40 / 0.25) });   // 40px per 0.25deg
    const far = ([, lat]) => ({ x: 0, y: lat * (2 / 0.25) });      // 2px per 0.25deg
    expect(projectedMpp(far, 0, 0, 1)).toBeGreaterThan(projectedMpp(close, 0, 0, 1));
  });
});
