/**
 * map-imagery.js — the raster sources behind the realistic "Satellite" map style.
 *
 * Shared by the Time-Map (resources/js/timemap/index.js) and the lesson map block
 * (resources/js/lesson-map.js) so both draw the same earth.
 *
 * Both services are keyless and free — no account, no token, no per-tile bill:
 *
 *  - Imagery: NASA GIBS "Blue Marble, shaded relief + bathymetry". Satellite-derived land cover with
 *    terrain relief AND ocean-floor depth, in the public domain (courtesy NASA EOSDIS GIBS). It is
 *    cloud-free, label-free and road-free, which is exactly what a history map wants: a 1642 voyage
 *    over modern motorways and city sprawl would be absurd. Levels 0–8 (~500 m/px), and our maps
 *    cap at zoom 6–7, so it never runs out of detail.
 *  - Elevation: the AWS Open Data terrain tiles (terrarium-encoded), used by the atlas styles'
 *    hillshade. The satellite style does NOT hillshade — Blue Marble already has the relief baked
 *    in, and a hillshade over it just lays a grey haze across land and sea alike.
 *
 * If we ever want street-level imagery, that is where a paid key (Amazon Location, MapTiler) comes
 * in — swap the tiles URL below and nothing else changes.
 */

/**
 * Satellite base imagery. Attribution is carried on the source so MapLibre credits it.
 *
 * `ShadedRelief`, NOT `ShadedRelief_Bathymetry`. TWO reasons, and each one alone looks like it
 * might be covered by the other, which is exactly why both are written here.
 *
 * MEASURED: the bathymetry variant paints the sea FLOOR — mid-ocean ridges, abyssal plains, the
 * continental shelf — and from orbit that reads as a bathymetric chart rather than water. Over open
 * Atlantic it carries twice the pixel variance of the plain variant, and every bit of that is
 * terrain nobody can actually see through three kilometres of water. Land keeps its relief shading;
 * the sea becomes near-uniform dark, and the water shader lights it instead.
 *
 * INSTRUCTED: Bart, looking at rendered sea-floor structure — "why is there a normal map for the
 * sea bed. i don't want that. it's not realistic if you look from space to the real earth." This is
 * a product decision, not an inference, and it does not expire when someone improves the renderer.
 *
 * The trap this is guarding: a Google Earth screenshot WITH visible bathymetry was once held up as
 * the target for this map, and that was read as wanting bathymetry. It was not — a reference image
 * is an endorsement of the whole, not of every element in it. If you find yourself about to switch
 * to `_Bathymetry` because a reference picture has sea-floor detail, this paragraph is why not.
 *
 * Depth still drives the WATER'S COLOUR — shallow turquoise through to deep blue. What is excluded
 * is any shading, normal or slope term derived from the sea floor. Values, not relief.
 */
export const SATELLITE_SOURCE = {
  type: 'raster',
  tiles: ['https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg'],
  tileSize: 256,
  maxzoom: 8,
  attribution: 'Imagery: NASA EOSDIS GIBS (Blue Marble)',
}

/**
 * Close-range imagery: Sentinel-2 cloudless, a cloud-free mosaic of the Copernicus Sentinel-2
 * archive, served keyless by EOX under CC-BY 4.0. Roughly 10 m per pixel — fifty times Blue
 * Marble's detail, which is what a coastline needs once the camera drops below a few hundred km.
 *
 * It replaces Blue Marble rather than joining it, because the two disagree: Sentinel-2 is a
 * true-colour surface mosaic with no bathymetry and no relief shading, so blending them at a fixed
 * ratio would show as a colour shift sweeping across the map. They cross-fade over one zoom level
 * instead (see satelliteLayers), where the ground is small enough that the change reads as detail
 * arriving rather than as the earth changing colour.
 */
export const SATELLITE_DETAIL_SOURCE = {
  type: 'raster',
  tiles: ['https://tiles.maps.eox.at/wmts/1.0.0/s2cloudless-2020_3857/default/g/{z}/{y}/{x}.jpg'],
  tileSize: 256,
  maxzoom: 14,
  attribution: 'Sentinel-2 cloudless (2020) by EOX IT Services GmbH — modified Copernicus Sentinel data',
}

/** Below this zoom the globe reads as a planet and Blue Marble is the better picture of it. */
export const DETAIL_FADE_START = 5
/** Above this zoom the ground fills the screen and Blue Marble has run out of pixels. */
export const DETAIL_FADE_END = 7

/**
 * The two raster layers that make up the satellite ground, base first.
 *
 * Both are always in the style; the detail layer's opacity is a zoom expression, so MapLibre only
 * requests Sentinel-2 tiles once they are actually visible. Nothing is fetched from EOX while a
 * lesson stays at globe zoom.
 *
 * @param {object} [opts]
 * @param {string} [opts.id]        base layer id, kept stable because styles anchor other layers to it
 * @param {string} [opts.detailId]  detail layer id
 * @param {boolean} [opts.visible]  false for styles that start on a drawn atlas — a hidden raster
 *                                  layer costs nothing, since MapLibre never requests its tiles
 */
export const satelliteLayers = ({ id = 'satellite', detailId = 'satellite-detail', visible = true } = {}) => [
  { id, type: 'raster', source: 'satellite', layout: { visibility: visible ? 'visible' : 'none' }, paint: { 'raster-fade-duration': 250 } },
  {
    id: detailId,
    type: 'raster',
    source: 'satellite-detail',
    layout: { visibility: visible ? 'visible' : 'none' },
    paint: {
      'raster-fade-duration': 250,
      'raster-opacity': ['interpolate', ['linear'], ['zoom'], DETAIL_FADE_START, 0, DETAIL_FADE_END, 1],
    },
  },
]

/**
 * How far the 3D terrain may be dramatised. At exaggeration 1 the height is true to life, which on
 * a map spanning a continent is almost invisible — the Alps are 4 km tall against 4,000 km of
 * width. Schoolbook relief maps have always overstated height for exactly this reason; this is the
 * ceiling on how far a teacher may push it before mountains turn into spikes.
 */
export const MAX_RELIEF = 6

/** Elevation behind the atlas styles' relief hillshade and the 3D terrain. Free AWS Open Data terrain tiles. */
export const DEM_SOURCE = {
  type: 'raster-dem',
  tiles: ['https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png'],
  encoding: 'terrarium',
  tileSize: 256,
  maxzoom: 12,
}
