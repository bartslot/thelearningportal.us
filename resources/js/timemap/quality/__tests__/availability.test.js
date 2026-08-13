import { describe, it, expect } from 'vitest';
import { layerAvailability, isMetered, tierFellForNetwork, globeLayerPermitted, REASON } from '../availability.js';

/**
 * The rule these tests exist to hold: a greyed-out control must not blame the wrong thing.
 *
 * Three outcomes, and telling them apart is the whole job. "Needs a better GPU" on a layer this
 * surface never asked for would send a teacher on a new laptop looking for a fault that is not
 * there, and a better laptop would not change it.
 */
const layer = (enabled) => ({ enabled, source: 'global', textureSize: enabled ? 2048 : 0 });

describe('layerAvailability', () => {
    it('reports an enabled layer as available', () => {
        const out = layerAvailability({
            plan: { layers: { ocean: layer(true) }, reductions: [] },
            profile: {},
            keys: ['ocean'],
        });

        expect(out.ocean).toEqual({ reason: REASON.available, available: true });
    });

    it('blames the device when a capable connection still cannot render it', () => {
        const out = layerAvailability({
            plan: {
                layers: { clouds: layer(false) },
                reductions: [{ layer: 'clouds', action: 'dropped', why: 'over the surface budget' }],
            },
            profile: { effectiveType: '4g', maxTextureSize: 2048 },
            keys: ['clouds'],
        });

        expect(out.clouds).toEqual({ reason: REASON.device, available: false });
    });

    it('blames the connection when the user asked for less data', () => {
        const out = layerAvailability({
            plan: {
                layers: { clouds: layer(false) },
                reductions: [{ layer: 'clouds', action: 'dropped', why: 'over the surface budget' }],
            },
            profile: { saveData: true },
            keys: ['clouds'],
        });

        expect(out.clouds).toEqual({ reason: REASON.network, available: false });
    });

    it.each(['slow-2g', '2g', '3g'])('treats %s as a connection problem, not a device one', (effectiveType) => {
        const out = layerAvailability({
            plan: {
                layers: { clouds: layer(false) },
                reductions: [{ layer: 'clouds', action: 'dropped', why: 'over the surface budget' }],
            },
            profile: { effectiveType },
            keys: ['clouds'],
        });

        expect(out.clouds.reason).toBe(REASON.network);
    });

    /**
     * THE ONE THAT MATTERS. The lesson map's surface asks for sky, relief and ocean; clouds are
     * dropped by scope on every device including a workstation. Reporting that as a device limit is
     * the lie this separation exists to prevent.
     */
    it('does not blame the device for a layer the surface never asked for', () => {
        const out = layerAvailability({
            plan: {
                layers: { clouds: layer(false) },
                reductions: [{ layer: 'clouds', action: 'dropped', why: 'surface did not ask for it' }],
            },
            profile: { effectiveType: '4g', deviceMemoryGb: 32, maxTextureSize: 16384 },
            keys: ['clouds'],
        });

        expect(out.clouds).toEqual({ reason: REASON.scope, available: false });
    });

    it('still says scope, not network, on a metered connection', () => {
        // Otherwise a teacher on 3G is told wifi would bring back a layer that this surface will
        // never show them.
        const out = layerAvailability({
            plan: {
                layers: { clouds: layer(false) },
                reductions: [{ layer: 'clouds', action: 'dropped', why: 'surface did not ask for it' }],
            },
            profile: { saveData: true },
            keys: ['clouds'],
        });

        expect(out.clouds.reason).toBe(REASON.scope);
    });

    it('leaves a key the quality system does not model alone', () => {
        // The NASA reference row is a comparison aid, not a globe layer, and must never be greyed.
        const out = layerAvailability({
            plan: { layers: {}, reductions: [] },
            profile: { saveData: true },
            keys: ['reference'],
        });

        expect(out.reference).toEqual({ reason: REASON.available, available: true });
    });

    it('answers for every key it is asked about', () => {
        const out = layerAvailability({
            plan: { layers: { ocean: layer(true), clouds: layer(false) }, reductions: [] },
            profile: {},
            keys: ['ocean', 'clouds', 'reference'],
        });

        expect(Object.keys(out).sort()).toEqual(['clouds', 'ocean', 'reference']);
    });
});

describe('globeLayerPermitted', () => {
    const plan = { layers: { sky: layer(false), ocean: layer(true), clouds: layer(false) }, reductions: [] };

    it('translates the stack key to the plan key', () => {
        // Both draw from the `sky` budget, and neither is called that.
        expect(globeLayerPermitted(plan)('starfield')).toBe(false);
        expect(globeLayerPermitted(plan)('moon')).toBe(false);
        expect(globeLayerPermitted(plan)('ocean')).toBe(true);
        expect(globeLayerPermitted(plan)('clouds')).toBe(false);
    });

    it('permits shader-only layers the plan does not model', () => {
        // daylight and atmosphere carry no texture, so no budget can exclude them — and a globe
        // without its terminator is not a cheaper globe, it is a broken one.
        expect(globeLayerPermitted(plan)('daylight')).toBe(true);
        expect(globeLayerPermitted(plan)('atmosphere')).toBe(true);
    });

    it('permits everything when there is no plan', () => {
        // The Time-Map has its own budget and does not pass one; it must not lose layers by default.
        expect(globeLayerPermitted(null)('clouds')).toBe(true);
        expect(globeLayerPermitted(undefined)('starfield')).toBe(true);
    });
});

describe('isMetered', () => {
    it('is false on a good connection and on an unknown one', () => {
        expect(isMetered({ effectiveType: '4g' })).toBe(false);
        // Safari reports neither. Guessing "metered" there would grey the globe for every Safari
        // user on a desktop.
        expect(isMetered({})).toBe(false);
    });

    it('honours an explicit save-data request even on a fast connection', () => {
        expect(isMetered({ saveData: true, effectiveType: '4g' })).toBe(true);
    });
});

describe('tierFellForNetwork', () => {
    it('separates a connection-driven downgrade from a device-driven one', () => {
        expect(tierFellForNetwork({ reasons: [{ rule: 'saveData', from: 'high', to: 'minimal' }] })).toBe(true);
        expect(tierFellForNetwork({ reasons: [{ rule: 'tinyTextures', from: 'high', to: 'minimal' }] })).toBe(false);
        expect(tierFellForNetwork({})).toBe(false);
    });
});
