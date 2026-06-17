# Timemap Mountain Assets

Assets in this folder are loaded by the Pen & Ink timemap style.

Current mountain placement is controlled by:

- `public/timemap/mountains.geojson`

Current icon loading is controlled by:

- `resources/js/timemap/index.js`

## Replacement Guide

- Replace `pen-ink-mountain.svg` with a hand-painted transparent SVG or PNG.
- Keep the artwork compact; the current icon is designed around a `26x18` canvas.
- If the replacement uses a different canvas size, update the `new Image(width, height)` call in `resources/js/timemap/index.js`.
- Use dark brown/ink tones so the asset fits the Pen & Ink palette.
