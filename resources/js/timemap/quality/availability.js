/**
 * Why a globe layer is not on offer — in terms a teacher can act on.
 *
 * The quality module already decides what a device can afford (see resolveQuality). What it reports
 * is machine-shaped: rule names like `tinyTextures`, reductions saying "over the single-asset
 * ceiling". A greyed-out control has to say something a person can do something about, and there
 * are only two such things: get on wifi, or use a better machine.
 *
 * THE THIRD CASE IS THE ONE THAT MATTERS. A layer can also be off because this surface never asked
 * for it — the lesson map takes sky, relief and ocean and nothing else. That is scope, not a
 * limitation of the device in front of you, and labelling it "Needs a better GPU" would be a
 * straightforward lie: a teacher on a new laptop would be told their machine was the problem, and
 * a better machine would change nothing. Those rows report `scope` and the panel omits them.
 *
 * This file returns REASON CODES, not sentences. Blade owns the words, JavaScript owns the wiring —
 * the same contract $globeLayerRows already keeps with layer-controls.js, and the reason the words
 * exist in five languages without this module knowing that.
 */

/**
 * The globe stack's key → the quality plan's key.
 *
 * Two vocabularies, deliberately: the plan talks about what costs something (`sky` is one budget
 * covering the Milky Way and the moon), the stack talks about what draws (`starfield` and `moon`
 * are separate layers). Neither is wrong and neither should be renamed to match the other, so the
 * translation lives here, once, where both sides can see it.
 *
 * A key absent from this map is a layer the plan does not model — `daylight` and `atmosphere` are
 * shader-only, carry no texture of their own, and are therefore never the thing that breaks a
 * budget. They stay on at every tier that renders a globe at all.
 */
export const PLAN_KEY_FOR_LAYER = {
    starfield: 'sky',
    moon: 'sky',
    sun: 'sun',
    ocean: 'ocean',
    clouds: 'clouds',
    relief: 'relief',
};

/** Connection states that mean "not now, but yes on wifi". */
export const METERED_EFFECTIVE_TYPES = new Set(['slow-2g', '2g', '3g']);

/** Tier rules that are about the connection rather than the machine. */
export const NETWORK_RULES = new Set(['saveData']);

/** Reductions applySurface records when a layer was never in this surface's list. */
const SCOPE_WHY = 'surface did not ask for it';

export const REASON = {
    available: 'available',
    network: 'network',
    device: 'device',
    scope: 'scope',
};

/**
 * Is the connection the binding constraint?
 *
 * `saveData` is explicit — the user asked for less data and we honour it. effectiveType is a
 * measurement, and it is deliberately read as a hint rather than a verdict: it moves around on a
 * train, and a layer that flickers in and out of reach as the signal changes is worse than one that
 * is simply absent. The panel reads this once at mount for that reason.
 */
export const isMetered = (profile = {}) =>
    profile.saveData === true || METERED_EFFECTIVE_TYPES.has(profile.effectiveType);

/**
 * Per-layer availability, keyed the same way $globeLayerRows is.
 *
 * @param {object}   args
 * @param {object}   args.plan       resolveQuality's result (its `layers` and `reductions`)
 * @param {object}   args.profile    readCapabilities' result
 * @param {string[]} args.keys       the rows the panel wants an answer for
 * @returns {Object<string, {reason: string, available: boolean}>}
 */
export const layerAvailability = ({ plan, profile = {}, keys = [] }) => {
    const layers = plan?.layers ?? {};
    const reductions = plan?.reductions ?? [];
    const metered = isMetered(profile);
    const out = {};

    for (const key of keys) {
        const planKey = PLAN_KEY_FOR_LAYER[key] ?? key;
        const layer = layers[planKey];

        // A key this system does not model at all — the NASA reference row, for one, which is a
        // comparison aid rather than a globe layer. Not ours to gate.
        if (!layer) {
            out[key] = { reason: REASON.available, available: true };
            continue;
        }

        if (layer.enabled) {
            out[key] = { reason: REASON.available, available: true };
            continue;
        }

        // Scope beats capability. A layer this surface never asked for would otherwise be reported
        // as a device limit on every machine, including ones that could render it easily.
        const droppedForScope = reductions.some(
            (r) => r.layer === planKey && r.why === SCOPE_WHY,
        );
        if (droppedForScope) {
            out[key] = { reason: REASON.scope, available: false };
            continue;
        }

        out[key] = { reason: metered ? REASON.network : REASON.device, available: false };
    }

    return out;
};

/**
 * Which globe-stack layers a plan permits — the map's half of the same answer.
 *
 * Returns a predicate rather than a list because addGlobeLayers walks its own STACK and should not
 * have to know this module's shape. A layer the plan does not model returns true: `daylight` and
 * `atmosphere` are shader-only and are not what a budget is spent on.
 *
 * @param {object} plan  resolveQuality's result, or null to permit everything
 * @returns {(layerKey: string) => boolean}
 */
export const globeLayerPermitted = (plan) => (layerKey) => {
    if (!plan?.layers) return true;
    const planKey = PLAN_KEY_FOR_LAYER[layerKey];
    if (!planKey) return true;
    return plan.layers[planKey]?.enabled !== false;
};

/**
 * Did the tier itself come down for a network reason?
 *
 * Kept separate from isMetered because they answer different questions: isMetered asks about the
 * connection now, this asks whether the connection is what cost you the quality. A panel wants the
 * first; a diagnostic wants the second.
 */
export const tierFellForNetwork = (decision = {}) =>
    (decision.reasons ?? []).some((r) => NETWORK_RULES.has(r.rule));
