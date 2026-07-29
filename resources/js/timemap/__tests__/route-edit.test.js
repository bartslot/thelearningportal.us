import { describe, expect, it } from 'vitest';

import { midpointAlong, nearestPointOnPolyline, splitByWaypoints } from '../route-edit.js';

const P = (x, y) => ({ x, y });

describe('nearestPointOnPolyline', () => {
    it('projects onto a segment, not onto the nearest vertex', () => {
        // Vertices are 100px apart; the cursor sits above the middle of the run.
        const pts = [P(0, 0), P(100, 0)];

        const hit = nearestPointOnPolyline(50, 20, pts);

        expect(hit.x).toBeCloseTo(50);
        expect(hit.y).toBeCloseTo(0);
        expect(hit.d).toBeCloseTo(20);
    });

    it('reports which segment was hit and how far along it', () => {
        const pts = [P(0, 0), P(100, 0), P(100, 100)];

        const hit = nearestPointOnPolyline(105, 75, pts);

        expect(hit.index).toBe(1);
        expect(hit.t).toBeCloseTo(0.75);
    });

    it('clamps to the ends instead of running past them', () => {
        const pts = [P(0, 0), P(100, 0)];

        expect(nearestPointOnPolyline(-40, 0, pts).x).toBeCloseTo(0);
        expect(nearestPointOnPolyline(400, 0, pts).x).toBeCloseTo(100);
    });

    it('returns null for an empty line', () => {
        expect(nearestPointOnPolyline(0, 0, [])).toBeNull();
    });
});

describe('midpointAlong', () => {
    it('sits half way by arc length, not on the straight chord', () => {
        // An L: 100px right, then 100px down. Half the length is the corner.
        const pts = [P(0, 0), P(100, 0), P(100, 100)];

        const mid = midpointAlong(pts);

        expect(mid.x).toBeCloseTo(100);
        expect(mid.y).toBeCloseTo(0);
        expect(mid.length).toBeCloseTo(200);
    });

    it('handles a plain straight run', () => {
        const mid = midpointAlong([P(0, 0), P(40, 0)]);

        expect(mid.x).toBeCloseTo(20);
        expect(mid.y).toBeCloseTo(0);
    });

    it('returns null when the run has no length', () => {
        expect(midpointAlong([P(5, 5), P(5, 5)])).toBeNull();
        expect(midpointAlong([P(5, 5)])).toBeNull();
    });
});

describe('splitByWaypoints', () => {
    it('produces one run per waypoint pair', () => {
        const pts = [P(0, 0), P(25, 0), P(50, 0), P(75, 0), P(100, 0)];
        const waypoints = [P(0, 0), P(50, 0), P(100, 0)];

        const runs = splitByWaypoints(pts, waypoints);

        expect(runs).toHaveLength(2);
        expect(runs[0].from).toBe(0);
        expect(runs[0].to).toBe(1);
        expect(runs[0].pts[runs[0].pts.length - 1].x).toBeCloseTo(50);
        expect(runs[1].pts[0].x).toBeCloseTo(50);
    });

    it('keeps every run non-empty even when waypoints crowd together', () => {
        const pts = [P(0, 0), P(1, 0), P(2, 0)];
        const waypoints = [P(0, 0), P(0, 0), P(2, 0)];

        const runs = splitByWaypoints(pts, waypoints);

        expect(runs).toHaveLength(2);
        runs.forEach((run) => expect(run.pts.length).toBeGreaterThanOrEqual(2));
    });

    it('returns nothing without at least two waypoints', () => {
        expect(splitByWaypoints([P(0, 0), P(1, 1)], [P(0, 0)])).toEqual([]);
    });
});
