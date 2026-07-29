/**
 * Geometry for the voyage route editor's drag handles.
 *
 * Everything here works in SCREEN pixels on the polyline that is actually drawn on the map — not
 * on the underlying waypoints. That distinction is the whole point: the trail is smoothed and
 * (optionally) wobbled, so a handle placed from raw waypoint maths sits beside the line the teacher
 * can see, and a hit test against raw waypoints misses the line they are pointing at.
 *
 * Pure functions, no MapLibre and no DOM — the caller projects lng/lat to pixels and back.
 */

/** @typedef {{x: number, y: number}} Pt */

/**
 * Closest point to (px, py) on the polyline, measured against its SEGMENTS (not just its vertices).
 * Sampling vertices only is what made handles jump in coarse steps along a long ocean leg.
 *
 * @param {number} px
 * @param {number} py
 * @param {Pt[]} pts polyline in screen pixels
 * @returns {{x: number, y: number, d: number, index: number, t: number} | null}
 *          index = segment start vertex, t = 0..1 position along that segment
 */
export function nearestPointOnPolyline(px, py, pts) {
    if (!Array.isArray(pts) || pts.length === 0) return null;
    if (pts.length === 1) {
        return { x: pts[0].x, y: pts[0].y, d: Math.hypot(pts[0].x - px, pts[0].y - py), index: 0, t: 0 };
    }

    let best = null;
    for (let i = 0; i < pts.length - 1; i++) {
        const a = pts[i];
        const b = pts[i + 1];
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const lenSq = dx * dx + dy * dy;
        const t = lenSq === 0 ? 0 : Math.max(0, Math.min(1, ((px - a.x) * dx + (py - a.y) * dy) / lenSq));
        const x = a.x + dx * t;
        const y = a.y + dy * t;
        const d = Math.hypot(x - px, y - py);
        if (!best || d < best.d) best = { x, y, d, index: i, t };
    }
    return best;
}

/** Running arc length (screen px) at every vertex: [0, …, total]. */
export function cumulativeLengths(pts) {
    const out = [0];
    for (let i = 1; i < pts.length; i++) {
        out.push(out[i - 1] + Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y));
    }
    return out;
}

/**
 * The point half way ALONG the polyline (by arc length), so a midpoint handle lands on the curve
 * itself rather than on the straight chord between its ends — on a bowed ocean leg those are far
 * apart. Returns null for a degenerate (zero-length) run.
 *
 * @param {Pt[]} pts
 * @returns {{x: number, y: number, length: number} | null}
 */
export function midpointAlong(pts) {
    if (!Array.isArray(pts) || pts.length < 2) return null;
    const acc = cumulativeLengths(pts);
    const total = acc[acc.length - 1];
    if (total <= 0) return null;

    const half = total / 2;
    for (let i = 1; i < acc.length; i++) {
        if (acc[i] < half) continue;
        const span = acc[i] - acc[i - 1] || 1;
        const t = (half - acc[i - 1]) / span;
        return {
            x: pts[i - 1].x + (pts[i].x - pts[i - 1].x) * t,
            y: pts[i - 1].y + (pts[i].y - pts[i - 1].y) * t,
            length: total,
        };
    }
    return { x: pts[pts.length - 1].x, y: pts[pts.length - 1].y, length: total };
}

/**
 * Split a drawn polyline into one run per waypoint pair, by cutting it at the vertex nearest each
 * waypoint. The runs are what midpoint handles are placed on — one insert handle per real segment
 * of the route, which is the convention every map editor uses (Google My Maps, Mapbox Draw).
 *
 * @param {Pt[]} pts drawn polyline, screen px
 * @param {Pt[]} waypoints the leg's waypoints in the same space, in order
 * @returns {{from: number, to: number, pts: Pt[]}[]} from/to index into `waypoints`
 */
export function splitByWaypoints(pts, waypoints) {
    if (!Array.isArray(pts) || pts.length < 2 || !Array.isArray(waypoints) || waypoints.length < 2) return [];

    // Cut indices must ascend, otherwise a doubled-back route would produce empty or inverted runs.
    const cuts = [];
    let from = 0;
    waypoints.forEach((wp, i) => {
        if (i === 0) { cuts.push(0); return; }
        if (i === waypoints.length - 1) { cuts.push(pts.length - 1); return; }
        let bestIdx = from;
        let bestD = Infinity;
        for (let k = from; k < pts.length; k++) {
            const d = Math.hypot(pts[k].x - wp.x, pts[k].y - wp.y);
            if (d < bestD) { bestD = d; bestIdx = k; }
        }
        from = bestIdx;
        cuts.push(bestIdx);
    });

    const runs = [];
    for (let i = 0; i < cuts.length - 1; i++) {
        const a = cuts[i];
        const b = Math.max(cuts[i + 1], a + 1);
        runs.push({ from: i, to: i + 1, pts: pts.slice(a, b + 1) });
    }
    return runs;
}
