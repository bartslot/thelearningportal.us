# Parked work

Not production code. Nothing here is imported, built, or tested. It is kept because it is most of
a working implementation and throwing it away would mean paying for the same discoveries twice.

## ocean-water.js — sun glint and sky reflection on the sea

A MapLibre custom layer that lights the ocean: water colour, a Fresnel sky reflection that turns
the sea silver toward the limb, and a two-lobe Cox-Munk sun glint. It rendered, and the glint
itself looked right.

**Why it is parked.** Where water *is* comes from `ocean-mask.webp`, a baked equirectangular mask,
because a custom layer cannot sample MapLibre's raster tiles. The mask was derived from Blue
Marble's blue-dominance and it is too coarse: coastlines came out blocky, and at any zoom past the
globe the mask's own pixels are visible as steps along every shore. That is the unsolved problem.
The lighting is not the hard part; knowing where the sea ends is.

**Known traps, all paid for once already.**

- Premultiplied alpha. MapLibre blends custom layers `ONE / ONE_MINUS_SRC_ALPHA`. An early version
  multiplied the colour by the mask instead of by the alpha and the ocean glowed over dark ground.
- The base imagery's sea is deliberately near-black. `map-imagery.js` uses `BlueMarble_ShadedRelief`
  rather than the `_Bathymetry` variant, because the bathymetry variant paints the sea floor and
  reads as a chart. This layer is what was meant to paint the water back.
- Blue Marble's ocean is darker in a band hugging every coast — the relief shading treats the
  coastline as a cliff and casts a shadow onto the water. Real shallow water is *lighter*. Measured
  on the raw tile: coastal water 4.8 luma against 8.9 in open ocean, while Sentinel-2 runs the
  correct way round (46.6 coastal, 38.7 open). Any water layer sits on top of that inversion.

**Ideas not yet tried for the mask.** A vector coastline (Natural Earth `ne_10m_ocean`) rasterised
at higher resolution, or rendered into an offscreen texture by MapLibre itself so it matches the
imagery exactly at every zoom; or signed-distance encoding so the edge stays sharp under
magnification instead of stepping.
