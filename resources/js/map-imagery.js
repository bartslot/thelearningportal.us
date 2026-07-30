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

/** Satellite base imagery. Attribution is carried on the source so MapLibre credits it. */
export const SATELLITE_SOURCE = {
  type: 'raster',
  tiles: ['https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief_Bathymetry/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg'],
  tileSize: 256,
  maxzoom: 8,
  attribution: 'Imagery: NASA EOSDIS GIBS (Blue Marble)',
}

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
