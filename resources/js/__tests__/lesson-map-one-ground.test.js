import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The lesson map has ONE ground, and that ground is the Earth.
 *
 * This replaces MapStyleEarthTest, which asked whether the style picker offered Earth. That
 * question no longer has a subject: the picker and the five drawn atlases were removed, and what
 * took their place is a single photographed ground. The risk moved with it. Nobody can now pick the
 * wrong style, but somebody can quietly reintroduce a style gate, or drop the globe call, and the
 * map would still render — as plain imagery, with no sea lighting, no terminator and no clouds.
 * That failure looks close enough to right that it would not be reported.
 *
 * Asserted against the SOURCE rather than a running map because the globe is a lazy import of a
 * WebGL module: instantiating it needs a GL context jsdom does not have, and mocking the import
 * away would leave the test asserting the mock. What is worth pinning here is structural — that
 * the call exists and carries no condition — and that is legible in the source.
 */
const SOURCE = readFileSync(resolve(__dirname, '../lesson-map.js'), 'utf8');

describe('lesson-map: one ground, and it is the Earth', () => {
    it('loads the globe layers', () => {
        expect(SOURCE).toContain("import('./map-globe-layers.js')");
    });

    it('calls applyGlobe when the style is parsed', () => {
        // The styledata pass, not 'load': 'load' also waits on every source, and a slow or failing
        // tileset would leave the map sitting there without a lit planet.
        expect(SOURCE).toMatch(/map\.once\('styledata',[\s\S]{0,160}?applyGlobe\(\)/);
    });

    it('takes no argument, because there is no style left to gate it on', () => {
        // The guard that matters. map-integration's version was `applyGlobe(!!s.globe)`, driven by
        // the style table. Reintroducing a parameter here means a style gate came back, and the
        // very next thing to happen is a lesson silently rendering as flat imagery.
        expect(SOURCE).toMatch(/function applyGlobe\s*\(\s*\)/);
        expect(SOURCE).not.toMatch(/applyGlobe\(\s*(!!|true|false|s\.)/);
    });

    it('keeps no MAP_STYLES table', () => {
        // The five drawn atlases. A reappearance here is the picker coming back by the side door.
        expect(SOURCE).not.toContain('MAP_STYLES');
    });

    it('crossfades Sentinel-2 detail in over Blue Marble', () => {
        // Brought across in the merge and easy to lose again: without it the ground stays at Blue
        // Marble's ~500 m/px and a lesson that leans into a coastline shows a blur.
        expect(SOURCE).toContain("'satellite-detail'");
        expect(SOURCE).toMatch(/DETAIL_FADE_START[\s\S]{0,40}DETAIL_FADE_END/);
    });
});
