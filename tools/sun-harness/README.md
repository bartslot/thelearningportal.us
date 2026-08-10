# sun harness

A globe, the sun layer on it, and a way to **measure** what actually reached the canvas.

```bash
node tools/sun-harness/serve.mjs --port 5212
```

Then open `http://localhost:5212/` (optionally `?w=1280&h=720` to pin the canvas size) and drive it
from the console:

```js
await sunHarness.view({ beyondLimb: 0.9, radii: 5 })   // camera pose, in degrees past the earth's limb
await sunHarness.contribution()                        // what the sun layer added to the frame
await sunHarness.contribution({ layer: 'bloom', option: 'strength' })   // ...or any other layer
await sunHarness.shot('my-shot')                       // PNG into tools/sun-harness/shots/
sunHarness.expected()                                  // what the disc SHOULD measure, from geometry
sunHarness.geometry()                                  // where the camera ended up, in earth radii
sunHarness.run(async () => { ... })                    // long jobs -> window.sunHarnessReport
```

Layers are on `sunHarness.layers`: `starfield`, `daylight`, `atmosphere`, `sun`, `bloom`. The bloom
pass goes last, because it blooms whatever is underneath it, and the measurement probe goes after
even that so a reading is of the finished frame.

Note what an empty bloom reading means. In a night-side scene — the only kind in which the sun is on
screen at all — the sun is usually the only thing above the bloom threshold, so
`contribution({ layer: 'bloom', option: 'strength' })` with the sun switched off correctly reports
zero. That is the scene being dark, not the pass being broken. To see it working on something else,
make something else bright:

```js
sunHarness.map.setPaintProperty('ocean', 'background-color', '#ffffff')
```

esbuild, not Vite, and no Laravel: the point is to exercise the MapLibre custom layers on their own,
so a broken PHP install can never be the reason the sun did not appear. The app's `public/` is
served underneath, which is where the land tiles come from.

## Four things that will waste your day

Every one of these fails **silently** — no exception, no console message, no failed request. The
canvas is simply empty, which looks exactly like a layer that draws nothing.

**1. The page is hidden, so nothing runs at all.** The agent browser pane holds the document at
`visibilityState: "hidden"`, and Chrome does not fire `requestAnimationFrame` in a hidden document.
MapLibre puts *everything* on rAF — the deferred style load included — so `isStyleLoaded()` stays
false forever and the map never starts. `index.html` swaps in a substitute clock before MapLibre is
imported. Not `setTimeout`: Chrome clamps timers in a hidden page to about one a second, which turns
every frame into a whole second. `MessageChannel` is not throttled.

**2. You cannot screenshot your way to an answer.** The pane runs without
`preserveDrawingBuffer`, so the colour buffer is gone the moment the frame is composited.
`canvas.toDataURL()` and a post-frame `drawImage` both return solid black. The only reading you can
trust is `gl.readPixels` taken *inside* a render pass — hence the probe custom layer, added last so
everything else has drawn.

**3. A whole-frame reading measures the starfield.** The stars are thousands of fully saturated
single pixels, so "the brightest pixel" is a star, "pixels above 80% of peak" is a star count, and
the number does not budge when the sun is switched off. `contribution()` takes the difference
between a frame with the sun and one without: everything else subtracts to zero.

**4. CSS pixels are not device pixels.** `cameraToCenterDistance` and MapLibre's world size are in
CSS pixels; `canvas.width` and everything `readPixels` returns are device pixels — twice as many on
a retina display. Deriving the camera distance from the canvas put the camera at 4.5 earth radii
when it had been asked for 8, moved the limb from 7.2° out to 12.8°, and swallowed the sun whole.

And when you save a PNG: the framebuffer is **premultiplied**, and space on this map is
**transparent**. Flatten by hand as `src + background × (1 − alpha)`. Feeding premultiplied pixels
to `putImageData` and then compositing applies alpha twice, which squares the glow — plausible,
several times too faint, and invisible as an error.

## Where the sun can be seen at all

Only near antisolar. The view axis points at the planet and the sun has to be within half a field of
view of it, so the camera has to be almost exactly opposite the sun — and then the earth is in the
way unless you are far enough out for its silhouette to be smaller than that offset. `view()` solves
the pose for you: `beyondLimb` is how many degrees clear of the earth's edge the sun's centre sits,
and negative values put it behind the planet.
