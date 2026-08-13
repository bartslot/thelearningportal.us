# Taking a derivative of a sampled field

Read this before using `dFdx`/`dFdy` on anything that came out of a texture.

It cost one term on the cloud deck three separate attempts, each of which rendered perfectly, looked
plausible, and was wrong in a different way. None of the three was caught by a test. Two of them
shipped as far as a reviewer's screenshot before anyone noticed, and the second was found only
because someone cropped into a frame rather than looking at it whole.

The general fact is not about clouds. It is about what a screen-space derivative actually measures.

## The three failures

A cloud's normal was wanted, from the gradient of a coverage field. Coverage is a heightfield —
thicker cloud is taller cloud — so the gradient is the slope, and `dFdx` is free. Three sources were
tried:

| gradient taken from | what happens | how it looks |
|---|---|---|
| the tiled atlas, 2446 m/texel | at close range one texel is one pixel, so the estimate sits at Nyquist | fine directional hatching, brush strokes over the whole deck |
| the whole-planet field, 19.5 km/texel | bilinear is bilinear WITHIN a texel, so its derivative steps ACROSS texel boundaries | hard-edged rectangles the size of the field's texels |
| a fixed step in UV, 1.5 texels | straddles a texel by construction | correct, and the same at every zoom |

**A screen-space derivative is only meaningful where the field varies smoothly across a PIXEL.**
Neither texture does at every zoom, and there is no zoom at which both do.

Mipmapping does not rescue the first case, and that is the part worth keeping. Minification selects
the level at which one texel is approximately one pixel — that is what mip selection is *for* — so a
minified texture is always being sampled near Nyquist. The estimate is at its worst precisely where
you would expect filtering to have saved you.

## Why none of it was caught

Each failure was a plausible picture. Hatching reads as texture; blocks read as a shadow artefact;
neither throws, neither changes a layer count, and neither moves any measurement that was being
taken at the time. The blocks in particular showed over bright land and hid over dark sea, which is
exactly how a shadow behaves, and sent the first diagnosis to the wrong file entirely.

The tests that existed asserted that the term *did something* — that shading spread rose when the
term was switched on. All three versions passed that.

## What to do instead

Take the gradient over a **fixed step in the field's own space**, sized to straddle more than one
texel. Four taps of a cheap texture, no screen scale in it anywhere, identical at every zoom.

The cost is real and worth stating: four taps where `dFdx` was free. Pay it. A free number that is
wrong at most zoom levels is not a saving.

If the field is expensive to sample, take the gradient from a cheaper, smoother proxy rather than
from the drawn field. On the cloud deck the shape comes from the 19.5 km field while the drawn
coverage comes from the 2446 m atlas, and the division of labour is honest on its own terms: light
the weather systems, whose tops really do catch the sun, and leave the fine structure as texture.

## The other trap in the same family

Derivatives of a coordinate that is itself warped per-pixel — an advected UV, a flow-map offset,
anything with a spatially varying Jacobian — carry the warp's own variation. Where the warp changes
quickly the estimate explodes and the sampler drops to its coarsest level.

On the deck this drew a fan of streaks that was reported, reasonably, as a polar singularity. It was
at 58.8°N: the storm track, where the wind field has its real circulation. Two rounds of fixes were
aimed at the pole before anyone measured the latitude instead of estimating it from a picture of a
globe. `tests/playwright/where-capture.spec.ts` exists to answer that question in fifteen seconds.

If a sampler is called at a displaced coordinate, hand it the derivatives of the **undisplaced** one.
The displacement is usually a smooth offset, so the original footprint describes it just as well and
has nothing pathological in it.

See also [browser-pane-measurement.md](browser-pane-measurement.md), which is the same class of
problem one layer down: an instrument that fails silently and agrees with itself.
