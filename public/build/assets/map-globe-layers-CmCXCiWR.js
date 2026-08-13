import{m as j}from"./map-imagery-GrMwsaGI.js";import{p as Et}from"./tuner-xnBLRIk5.js";const ue=63710088e-1,K=(e,a,i=0)=>{const l=1+Math.max(0,i)/ue,u=a*Math.PI/180,f=e*Math.PI/180;return[Math.cos(u)*Math.cos(f)*l,Math.sin(u)*l,Math.cos(u)*Math.sin(f)*l]},Tt=e=>(2*Math.atan(Math.exp((1-2*e)*Math.PI))-Math.PI/2)*180/Math.PI,ke=e=>.5-Math.log(Math.tan(Math.PI/4+e*Math.PI/180/2))/(2*Math.PI),Se=85.0511287798066,At=89.999,we=6,de=(e=64,a=48)=>{const i=[],l=[],u=[],f=t=>Se+(At-Se)*(t/we),o=[];for(let t=we;t>=1;t--)o.push(ke(f(t)));for(let t=0;t<=a;t++)o.push(t/a);for(let t=1;t<=we;t++)o.push(ke(-f(t)));for(const t of o){const y=Tt(t)*Math.PI/180;for(let A=0;A<=e;A++){const x=A/e,g=(x*360-180)*Math.PI/180;i.push(x,t),l.push(Math.cos(y)*Math.cos(g),Math.sin(y),Math.cos(y)*Math.sin(g))}}const p=e+1;for(let t=0;t<o.length-1;t++)for(let y=0;y<e;y++){const A=t*p+y,x=A+p;u.push(A,x,A+1,x,x+1,A+1)}return{positions:new Float32Array(i),spheres:new Float32Array(l),indices:new Uint16Array(u),vertexCount:(e+1)*o.length,rowCount:o.length}},fe=`
  vec4 projectShell(vec2 pos, float elevationGlobe, float elevationMercator) {
    #ifdef GLOBE
      return projectTileFor3D(pos, elevationGlobe);
    #else
      return projectTileFor3D(clamp(pos, 0.0, 1.0), elevationMercator);
    #endif
  }
`,xe=`
  float daylightFraction(float sunAngle) {
    return smoothstep(-0.31, 0.09, sunAngle);
  }
`,Ye=`
  float hash(vec3 p) {
    return fract(sin(dot(p, vec3(12.9898, 78.233, 45.164))) * 43758.5453123);
  }
  float noise(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float n000 = hash(i);
    float n100 = hash(i + vec3(1.0, 0.0, 0.0));
    float n010 = hash(i + vec3(0.0, 1.0, 0.0));
    float n110 = hash(i + vec3(1.0, 1.0, 0.0));
    float n001 = hash(i + vec3(0.0, 0.0, 1.0));
    float n101 = hash(i + vec3(1.0, 0.0, 1.0));
    float n011 = hash(i + vec3(0.0, 1.0, 1.0));
    float n111 = hash(i + vec3(1.0, 1.0, 1.0));
    return mix(
      mix(mix(n000, n100, f.x), mix(n010, n110, f.x), f.y),
      mix(mix(n001, n101, f.x), mix(n011, n111, f.x), f.y),
      f.z);
  }
  float fbm(vec3 p) {
    float value = 0.0;
    float amplitude = 0.5;
    for (int i = 0; i < 5; i++) {
      value += amplitude * noise(p);
      p *= 2.0;
      amplitude *= 0.5;
    }
    return value;
  }
`,We=`
  vec2 sphereSpan(vec3 origin, vec3 dir, float radius) {
    float b = dot(origin, dir);
    float c = dot(origin, origin) - radius * radius;
    float disc = b * b - c;
    if (disc < 0.0) return vec2(1.0, -1.0);
    float sq = sqrt(disc);
    return vec2(-b - sq, -b + sq);
  }
`,Ke=`
  bool facesCamera(vec3 unitPos, vec3 camera) {
    return dot(unitPos, camera) > 1.0;
  }
`,Re=(e,a)=>{const i=e?.transform,l=typeof i?.getCameraAltitude=="function"?i.getCameraAltitude():null;if(Number.isFinite(l))return l;const u=i?.cameraToCenterDistance;if(!Number.isFinite(u))return 1e7;const f=512*Math.pow(2,e.getZoom()),o=1/a.MercatorCoordinate.fromLngLat(e.getCenter(),0).meterInMercatorCoordinateUnits();return u/f*o*Math.cos(e.getPitch()*Math.PI/180)},J=(e,a)=>{const i=e?.transform?.cameraPosition;if(i&&Number.isFinite(i[0])&&Number.isFinite(i[1])&&Number.isFinite(i[2]))return[i[2],i[1],i[0]];const l=e?.transform,u=typeof l?.getCameraLngLat=="function"?l.getCameraLngLat():e.getCenter();return K(u.lng,u.lat,Re(e,a))},Ve=e=>typeof e.texStorage2D=="function",xt=`#version 300 es
#define attribute in
#define varying out
#define texture2D texture
`,Rt=`#version 300 es
#define varying in
#define texture2D texture
out highp vec4 tm_fragColour;
#define gl_FragColor tm_fragColour
`,V=(e,a,i,l="layer",{es300:u=!1}={})=>(u&&Ve(e)&&(a=xt+a,i=Rt+i),Mt(e,a,i,l)),Mt=(e,a,i,l)=>{const u=(t,y)=>{const A=e.createShader(t);if(e.shaderSource(A,y),e.compileShader(A),!e.getShaderParameter(A,e.COMPILE_STATUS)){const x=e.getShaderInfoLog(A);throw e.deleteShader(A),new Error(`${l} shader: ${x}`)}return A},f=e.createProgram(),o=u(e.VERTEX_SHADER,a),p=u(e.FRAGMENT_SHADER,i);if(e.attachShader(f,o),e.attachShader(f,p),e.linkProgram(f),e.deleteShader(o),e.deleteShader(p),!e.getProgramParameter(f,e.LINK_STATUS)){const t=e.getProgramInfoLog(f);throw e.deleteProgram(f),new Error(`${l} link: ${t}`)}return f},me=`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }

  vec2 equirectUVInside(vec3 dir, float drift) {
    return vec2(-atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,se=9e4,Ze=`
  uniform float u_time;
  uniform float u_drift;        // slow west-to-east rotation of the whole field
  uniform sampler2D u_field;    // real cloud cover, equirectangular
  uniform float u_fieldAmount;  // 0 = no real field loaded
  uniform sampler2D u_wind;     // real wind field: R = eastward, G = southward, 0.5 = still
  uniform float u_windAmount;   // 0 = the field sits still, 1 = fully advected
  uniform float u_windScale;    // how far the flow carries per cycle, in UV
  uniform float u_windRate;     // cycles per second
  uniform sampler2D u_patches;  // harvested NASA cloud patches, 3x2 of 256px coverage tiles
  uniform float u_patchAmount;  // 0 = no atlas, fall back to the whole-planet field
  uniform float u_patchTiles;   // lattice cells around the equator
  uniform float u_patchMean;    // the atlas's own mean coverage; see the blend below
  uniform float u_patchDetail;  // how much patch variation rides on the global distribution

  vec2 windAt(vec2 uv, float lat) {
    vec2 wind = texture(u_wind, uv).rg * 2.0 - 1.0;
    // Degrees of longitude per metre grow toward the poles; without this correction the flow slows
    // to a crawl at high latitude and the polar cells stop turning.
    wind.x /= max(cos(lat), 0.25);
    return wind * u_windScale;
  }

  // ── The tiled source ──────────────────────────────────────────────────────────────────────────
  //
  // The whole-planet field is 2048x1024 — 19.5 km per texel — against ground running under a
  // kilometre, so it collapses to a grey wash on approach. The atlas is real MODIS cloud at 2446 m
  // per texel, eight times finer, but it is only six patches of open ocean: 626 km of sky each.
  // Laid down on a plain grid it would repeat every 626 km, and a visible repeat over open ocean is
  // a worse artefact than the blur it replaces.
  //
  // So it is laid down stochastically. Heitz & Neyret's triangle grid: every point falls inside one
  // triangle of a skewed lattice, and the three vertices of that triangle each choose a patch, a
  // window inside it and an angle, at random from their own coordinates. Three taps, blended by the
  // barycentric weights. Nothing about the result is periodic, because nothing about the choice is.
  //
  // WHY A WINDOW AND NOT A WRAPPED OFFSET, which is what the original technique uses. It assumes a
  // tileable exemplar. These are photographs of the Atlantic; wrapping one draws a hard straight
  // edge across the sky where its left meets its right. A window that stays inside the patch has no
  // seam to show, and the patch is twice the size of a cell, so there is room to move it.
  const vec2 CLOUD_PATCH_GRID = vec2(3.0, 2.0);
  const float CLOUD_PATCH_COUNT = 6.0;
  const float CLOUD_PATCH_TEXELS = 1024.0;
  // Patch widths around the equator: 40075 km of circumference over 626 km of patch. It is what
  // makes the atlas display at its native 2446 m per texel whatever u_patchTiles is set to.
  const float CLOUD_PATCH_WIDTHS = 64.0;

  uint cloudMix(uint h) {
    h ^= h >> 16; h *= 2246822519u;
    h ^= h >> 13; h *= 3266489917u;
    h ^= h >> 16;
    return h;
  }

  // An integer hash rather than the usual fract(sin(dot(...))): sin at large arguments is where
  // GPUs disagree, and the cheap end of the range this has to run on is exactly where it would.
  float cloudUnit(uint h) { return float(h & 0x00ffffffu) / 16777216.0; }

  /**
   * One lattice vertex's choice of exemplar, sampled at "local" (cell units, -0.5..0.5).
   *
   * ddx and ddy are the atlas-space derivatives of the CONTINUOUS coordinate, handed in rather than
   * left to the sampler. This is the artefact that would otherwise define the whole effect: the UV
   * jumps at every cell boundary — different patch, different window, different angle — so the
   * automatic derivative there is enormous, the sampler drops to its coarsest mip, and a soft dark
   * line is drawn along every edge of the lattice. The tiling would announce itself in exactly the
   * shape it exists to hide. Rotation does not change a derivative's magnitude, so one unrotated
   * pair is right for all three vertices.
   */
  float cloudPatchAt(vec2 vertex, vec2 local, vec2 ddx, vec2 ddy) {
    // The lattice repeats every u_patchTiles columns, and skewing leaves that period intact — the
    // skew's x depends on x alone. Folding the column index here is what makes the antimeridian
    // join: without it the last column and the first choose different patches and a seam runs down
    // the middle of the Pacific.
    float column = mod(vertex.x, u_patchTiles);
    uint h = cloudMix(uint(int(column)) * 374761393u + uint(int(vertex.y)) * 668265263u);
    float pick = cloudUnit(h);
    h = cloudMix(h);
    float angle = cloudUnit(h) * 6.2831853;
    h = cloudMix(h);
    float ox = cloudUnit(h);
    h = cloudMix(h);
    float oy = cloudUnit(h);

    // A random angle as well as a random window. Without it every cell lays its cloud down on the
    // same axis, and the eye finds that even when it cannot find the repeat itself.
    float s = sin(angle);
    float c = cos(angle);
    vec2 turned = mat2(c, s, -s, c) * local;

    // How much of a patch one cell covers, and how much room that leaves the window to move in.
    // The rotated cell needs its diagonal, not its width — 1.414, not 1 — or a corner swings past
    // the patch edge and the clamp below flattens it into a streak.
    float span = clamp(CLOUD_PATCH_WIDTHS / max(u_patchTiles, 1.0), 0.02, 0.7);
    float room = max(0.0, 1.0 - 1.41421356 * span);
    vec2 inPatch = vec2(ox, oy) * room + 0.70710678 * span + turned * span;

    // Half a texel in from the patch's own edge. The six sit in one atlas, so bilinear at the edge
    // of a patch reaches into its neighbour — a different sky, bled in along a straight line.
    const float INSET = 0.5 / CLOUD_PATCH_TEXELS;
    inPatch = clamp(inPatch, vec2(INSET), vec2(1.0 - INSET));

    float index = min(floor(pick * CLOUD_PATCH_COUNT), CLOUD_PATCH_COUNT - 1.0);
    vec2 grid = vec2(mod(index, CLOUD_PATCH_GRID.x), floor(index / CLOUD_PATCH_GRID.x));
    return textureGrad(u_patches, (grid + inPatch) / CLOUD_PATCH_GRID, ddx, ddy).r;
  }

  /**
   * The atlas, laid across the sphere with no repeat in it.
   *
   * The UV derivatives are handed IN rather than taken here, and that is not tidiness. This is
   * called three times per sample — once at the plain coordinate and twice at coordinates displaced
   * by the wind — and the displaced ones carry the flow's own variation in their derivatives. Where
   * the flow changes quickly, which is exactly where meridians converge, that estimate explodes,
   * the sampler drops to its coarsest level and the atlas smears along the meridians. It draws as a
   * starburst of fine streaks spiralling out of the pole.
   *
   * It needed BOTH to appear: switching off advection removed it with the tiling still on, and
   * switching off the tiling removed it with advection still on, which is what identified it. The
   * displacement is a smooth offset, so the undisplaced coordinate's derivatives describe the
   * footprint just as well and have nothing pathological in them.
   */
  float tiledPatchField(vec2 uv, float lat, vec2 duvdx, vec2 duvdy) {
    // The lattice lives in equirect UV, which is the space both readers of this field already work
    // in — so the ground's shadows keep sampling exactly what the deck draws, with no second
    // coordinate system to get out of step.
    //
    // Cells are UV-square, so on the ground they narrow toward the poles. What is corrected is the
    // LOCAL coordinate, by cos(lat): that keeps the cloud SHAPES ground-square, which is what the
    // eye reads. A stretched lattice underneath them is invisible, because the blend below never
    // lets a cell boundary show.
    vec2 lattice = vec2(uv.x * u_patchTiles, uv.y * u_patchTiles * 0.5);
    const mat2 SKEW = mat2(1.0, 0.0, -0.57735027, 1.15470054);
    vec2 skewed = SKEW * lattice;

    vec2 base = floor(skewed);
    vec2 f = skewed - base;
    vec3 weights;
    vec2 v1, v2, v3;
    if (f.x + f.y < 1.0) {
      weights = vec3(1.0 - f.x - f.y, f.y, f.x);
      v1 = base;
      v2 = base + vec2(0.0, 1.0);
      v3 = base + vec2(1.0, 0.0);
    } else {
      // 1-f.y ON v2 AND 1-f.x ON v3, and the pairing is the whole of it. Written the other way
      // round — which is what shipped — the two triangles of a square hand the SAME vertex
      // different weights along their shared diagonal: at f.x+f.y=1 the lower triangle gives
      // (1,0) a weight of f.x while the upper gives it f.y. The blend then steps discontinuously
      // across every diagonal in the lattice, and the deck comes out covered in hard-edged
      // triangles.
      //
      // The non-repetition measurement cannot see this and was right not to. Autocorrelation finds
      // PERIOD, and a grid whose every cell draws a different window at a different angle has none:
      // it read 0.028 on the build that was visibly ruined. Repeating and seamless are different
      // properties. Photographed by Map works, and now measured by cloudSeams in the harness.
      weights = vec3(f.x + f.y - 1.0, 1.0 - f.y, 1.0 - f.x);
      v1 = base + vec2(1.0, 1.0);
      v2 = base + vec2(1.0, 0.0);
      v3 = base + vec2(0.0, 1.0);
    }

    /**
     * SMOOTHED WEIGHTS, and this is the second half of making the lattice invisible.
     *
     * Fixing the transposed weights above made the blend continuous in VALUE. It is still not
     * continuous in SLOPE: crossing a diagonal swaps which exemplar is entering for which is
     * leaving, so each one's contribution starts growing exactly where another's stopped, and the
     * field gets a crease along every edge of the lattice. A crease is a straight line, and after
     * the coverage smoothstep the eye finds it as readily as a step would — the seam ratio only fell
     * from 1.21 to 1.06 on the value fix alone, and then rose again the moment the global
     * distribution put the field back on the steep part of that curve.
     *
     * Smoothstepping each weight before normalising sends it to zero with zero derivative, so an
     * exemplar fades in and out rather than switching on at an angle. The blend becomes C1 across
     * the boundaries and there is no crease left to see.
     */
    weights = weights * weights * (3.0 - 2.0 * weights);
    weights /= max(1.0e-5, weights.x + weights.y + weights.z);

    // Back out of the skew to get each vertex's own position, so "local" is measured in the same
    // undistorted cell units the window maths above expects.
    const mat2 UNSKEW = mat2(1.0, 0.0, 0.5, 0.86602540);
    vec2 aspect = vec2(max(cos(lat), 0.05), 1.0);

    // The footprint, from the caller's undisplaced coordinate. Clamped because the lattice steps a
    // whole revolution at the antimeridian, and one unbounded column of derivatives there would blur
    // a line down the Pacific — the same artefact as the cell edges, arriving by a different route.
    float span = clamp(CLOUD_PATCH_WIDTHS / max(u_patchTiles, 1.0), 0.02, 0.7);
    vec2 scale = span / CLOUD_PATCH_GRID;
    vec2 perCell = vec2(u_patchTiles, u_patchTiles * 0.5) * aspect * scale;
    vec2 ddx = clamp(duvdx * perCell, vec2(-0.25), vec2(0.25));
    vec2 ddy = clamp(duvdy * perCell, vec2(-0.25), vec2(0.25));

    float c1 = cloudPatchAt(v1, (lattice - UNSKEW * v1) * aspect, ddx, ddy);
    float c2 = cloudPatchAt(v2, (lattice - UNSKEW * v2) * aspect, ddx, ddy);
    float c3 = cloudPatchAt(v3, (lattice - UNSKEW * v3) * aspect, ddx, ddy);

    // VARIANCE-PRESERVING, not a plain average. Averaging three independent skies is how stochastic
    // tiling loses its contrast: where the weights are even it flattens by 1/sqrt(3), where one
    // vertex dominates it does not, and that difference is itself a visible pattern in the shape of
    // the lattice. Re-normalising against the atlas's own mean holds the contrast constant, so the
    // grid stays where it belongs, which is invisible.
    vec3 centred = vec3(c1, c2, c3) - u_patchMean;
    float spread = max(0.001, sqrt(dot(weights, weights)));
    return clamp(u_patchMean + dot(weights, centred) / spread, 0.0, 1.0);
  }

  /**
   * Cloud cover at an equirectangular UV — the two sources doing the two jobs neither can do alone.
   *
   * THE ATLAS ALONE MAKES THE WHOLE PLANET OVERCAST, and structurally rather than by a setting.
   * Every patch was harvested over open ocean in cloudy latitudes, because that is where cloud
   * separates on brightness — so tiled uniformly, the atlas's own cloud fraction becomes the
   * planet's. Measured across the disc: 74.3% covered against the real distribution's 26.5%, nearly
   * three times too much. The sea disappeared and the Sahara washed out.
   *
   * No amount of tiling can invent the missing thing, which is WHERE cloud is not. Earth is roughly
   * two-thirds covered but distributed — subtropical highs, deserts, continental interiors are
   * genuinely clear, and those clear regions are most of what makes a globe read as a globe.
   *
   * So each source does what it is actually good for. The whole-planet field is hopeless as texture
   * at 19.5 km per texel, but it is the REAL global distribution and nothing else here is. The
   * patches are hopeless as a distribution but carry real cloud shape at 2446 m. The field sets how
   * much cloud belongs at a place; the patches say what it looks like when it is there.
   *
   * Centred on the atlas's own mean before it is added, so the patches contribute VARIATION and not
   * a bias — otherwise the whole planet lifts by 0.47 and the overcast comes straight back.
   */
  float globalFieldAt(vec2 uv) {
    return u_fieldAmount > 0.0 ? texture(u_field, uv).r : u_patchMean;
  }

  float cloudSourceAt(vec2 uv, float lat, vec2 duvdx, vec2 duvdy, float global) {
    if (u_patchAmount <= 0.0) return global;

    /**
     * THE TILING LETS GO AT THE POLES, and it has to.
     *
     * The lattice lives in equirect UV, where u spans the whole world however small the circle of
     * latitude actually is. Approaching the pole, a few pixels of screen cover the entire u range:
     * cells collapse to nothing, all three taps land on the same texel, and every sample smears
     * along its meridian. It draws as a starburst of thin white streaks radiating from the pole —
     * photographed on the globe camera, and the same family of singularity the mesh's cap rows and
     * the sky layer's mirrored lookup already exist to handle.
     *
     * The whole-planet field has no such problem: it is one texture read at whatever UV, so it
     * degrades to a smear of its own texels and nothing more. Fading into it costs one smoothstep.
     *
     * The last eight degrees only. This was briefly widened to start at 58, chasing streaks that
     * were reported as a pole artefact and turned out — once the latitude was measured rather than
     * estimated from a picture — to sit at 58.8 and be caused by advection warping the lattice.
     * Fixing that where it lived made the wide fade unnecessary, and it was costing real cloud
     * structure across Iceland and Scandinavia for nothing. This is the genuine singularity guard.
     */
    float polar = smoothstep(1.3614, 1.5010, abs(lat));   // 78 to 86 degrees
    if (polar >= 1.0) return global;

    float detail = tiledPatchField(uv, lat, duvdx, duvdy);
    float tiled = clamp(global + (detail - u_patchMean) * u_patchDetail, 0.0, 1.0);
    return mix(tiled, global, polar);
  }

  /**
   * THE WIND CARRIES THE FIELD, NOT THE DETAIL — and that division is the fix for the streaks.
   *
   * Advection is a spatially varying warp. Applied to the smooth 19.5 km field it does what it is
   * meant to: weather turns. Applied to the tiled atlas it stretches the lattice cells along the
   * flow, and where the flow is strongest they draw as fine radiating streaks. Measured, the worst
   * of it sits at 58.8 N and runs from about 50 to 70 — the storm track, where the wind field has
   * its real circulation. NOT the pole, which is where it was first reported and where two rounds of
   * fixes were aimed before the latitude was actually measured rather than estimated from a picture.
   *
   * It needed both terms to appear: switching off advection removed it with the tiling on, and
   * switching off the tiling removed it with advection on. So advect the one that warps gracefully
   * and leave the one that does not. The detail is texture, and texture does not need to be carried
   * along a flow to be believable.
   *
   * It is also much cheaper. The tiled field is now sampled ONCE rather than three times: three
   * texture reads a pixel instead of nine, on the layer whose frame cost cannot be measured here.
   */
  float advectedField(vec2 uv, float lat) {
    // One footprint for the whole sample, measured on the coordinate the caller asked about.
    vec2 duvdx = dFdx(uv);
    vec2 duvdy = dFdy(uv);

    float global = globalFieldAt(uv);
    if (u_windAmount > 0.0) {
      vec2 flow = windAt(uv, lat);
      float cycle = u_time * u_windRate;
      float phase1 = fract(cycle);
      float phase2 = fract(cycle + 0.5);
      float blend = abs(1.0 - 2.0 * phase1);     // triangle wave: 1 at the resets, 0 mid-cycle
      float a = globalFieldAt(uv - flow * phase1);
      float b = globalFieldAt(uv - flow * phase2);
      global = mix(mix(a, b, blend), global, 1.0 - u_windAmount);
    }

    return cloudSourceAt(uv, lat, duvdx, duvdy, global);
  }
`,Qe=["time","drift","field","fieldAmount","wind","windAmount","windScale","windRate","patches","patchAmount","patchTiles","patchMean","patchDetail"],kt=.75,St=144,Lt=.4359,Je=(e,a,i,l,u,f,o)=>{const p=i.animate?l.seconds:0;a.time&&e.uniform1f(a.time,p),a.drift&&e.uniform1f(a.drift,p*i.driftRate),a.fieldAmount&&e.uniform1f(a.fieldAmount,l.field?.ready?1:0),a.patchAmount&&e.uniform1f(a.patchAmount,l.patches?.ready?1:0),a.patchTiles&&e.uniform1f(a.patchTiles,i.patchTiles??St),a.patchMean&&e.uniform1f(a.patchMean,i.patchMean??Lt),a.patchDetail&&e.uniform1f(a.patchDetail,i.patchDetail??kt),a.windAmount&&e.uniform1f(a.windAmount,l.wind?.ready?i.windAmount:0),a.windScale&&e.uniform1f(a.windScale,i.windScale),a.windRate&&e.uniform1f(a.windRate,i.animate?i.windRate:0),l.field?.ready&&a.field&&(e.activeTexture(e.TEXTURE0+u),e.bindTexture(e.TEXTURE_2D,l.field.texture),e.uniform1i(a.field,u)),l.wind?.ready&&a.wind&&(e.activeTexture(e.TEXTURE0+f),e.bindTexture(e.TEXTURE_2D,l.wind.texture),e.uniform1i(a.wind,f)),l.patches?.ready&&a.patches&&(e.activeTexture(e.TEXTURE0+o),e.bindTexture(e.TEXTURE_2D,l.patches.texture),e.uniform1i(a.patches,o))},Le=new WeakMap,Ut=e=>{let a=Le.get(e);return a||(a=new Map,Le.set(e,a)),a},Dt=(e,a,i)=>{const l=e.createTexture();return e.bindTexture(e.TEXTURE_2D,l),e.pixelStorei(e.UNPACK_FLIP_Y_WEBGL,!1),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,e.RGBA,e.UNSIGNED_BYTE,a),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.REPEAT),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,i?e.LINEAR_MIPMAP_LINEAR:e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR),i&&e.generateMipmap(e.TEXTURE_2D),l},W=(e,a,i=null,{mipmap:l=!0}={})=>{const u=Ut(e),f=`${a}|${l?"mip":"flat"}`;let o=u.get(f);if(!o){o={texture:null,ready:!1,refs:0,waiters:[],width:0},u.set(f,o);const t=new Image;t.crossOrigin="anonymous",t.onload=()=>{if(o.refs===0)return;const y=e.getParameter?.(e.MAX_TEXTURE_SIZE)??1/0;if(t.width>y){o.waiters=[];return}o.width=t.width,o.texture=Dt(e,t,l),o.ready=!0;const A=o.waiters;o.waiters=[],A.forEach(x=>x())},t.onerror=()=>{o.waiters=[]},t.src=a}o.refs++;let p=!0;return i&&(o.ready?i():o.waiters.push(i)),{get texture(){return o.texture},get ready(){return o.ready},get width(){return o.width},release(){p&&(p=!1,i&&(o.waiters=o.waiters.filter(t=>t!==i)),o.refs--,!(o.refs>0)&&(o.texture&&e.deleteTexture(o.texture),o.texture=null,o.ready=!1,u.delete(f)))}}},et=`
  mat3 equirectTangentFrame(vec3 unitPos) {
    vec3 up = normalize(unitPos);
    vec3 east = cross(up, vec3(0.0, 1.0, 0.0));
    float span = length(east);
    // Standing on a pole, every direction is south and no direction is east. Pick one rather than
    // dividing by zero and putting a NaN in the middle of Antarctica.
    east = span > 1.0e-4 ? east / span : vec3(0.0, 0.0, 1.0);
    return mat3(east, cross(up, east), up);
  }
`,tt=`
  /**
   * Unpack a baked normal, around the same centre encodeNormals packed it around.
   *
   * The usual texel*2-1 is very slightly wrong here, and wrong in a way that shows. Flat ground
   * bakes to byte 128, and 128/255*2-1 is 0.0039 rather than 0 — a constant tilt, applied to every
   * flat texel on earth, which is to say a constant brightness offset over every ocean. Measured
   * against a known grey it moved the Bay of Bengal by a full 8-bit level while the mountains it
   * was meant to be shading moved by sixty. Subtracting the encoder's own centre leaves water
   * EXACTLY untouched, which is the property the whole difference formulation is built on.
   */
  vec3 decodeTerrainNormal(vec3 texel) {
    return (texel - ${(128/255).toFixed(8)}) * 2.0;
  }

  float reliefLightFactor(vec3 up, vec3 perturbed, vec3 sunDir, float power) {
    return 1.0 + power * (dot(perturbed, sunDir) - dot(up, sunDir));
  }
`,Pt=`
  vec3 cloudShadowDirection(vec3 up, vec3 sunDir, float altitudeRadii) {
    float cosZenith = dot(up, sunDir);
    float reach = altitudeRadii * (2.0 + altitudeRadii);
    return normalize(up + sunDir * (-cosZenith + sqrt(max(0.0, cosZenith * cosZenith + reach))));
  }

  // Below a low sun the ground is already in twilight and a cast shadow means nothing; above it,
  // full strength. Without this the shadows stretch to the horizon as the sun sets.
  float cloudShadowFade(vec3 up, vec3 sunDir) {
    return smoothstep(0.05, 0.30, dot(up, sunDir));
  }
`,It=(e,a)=>2*Math.PI*ue*Math.cos(a*Math.PI/180)/e,Ft=(e,a,i)=>Math.min(i,Math.max(a,e)),Ct=(e,a,i)=>{const l=Ft((i-e)/(a-e),0,1);return l*l*(3-2*l)},Nt=(e,a,i)=>{const l=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e)/2,u=It(i,a)/l;return{pixelsPerTexel:u,strength:1-Ct(3,5,Math.log2(Math.max(u,1e-6)))}},at="tm-clouds",Ot=(e,a,i)=>{const l=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e),f=156543.03392/2**8,o=Math.min(1,l/f),t=ue/Math.max(1,l*110);return{frequency:Math.min(2600,Math.max(9,t)),amount:.14+(1-o)*.1,fade:Number.isFinite(i)?Bt(1.35,2.75,i/se):0}},Bt=(e,a,i)=>{const l=Math.min(1,Math.max(0,(i-e)/(a-e)));return l*l*(3-2*l)},Gt=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${fe}
void main() {
  v_sphere = a_sphere;
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ht=()=>`#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform float u_opacity;
uniform vec3 u_sun;           // direction TO the sun
uniform float u_detailFreq;   // noise cycles per unit sphere; rises as the camera descends
uniform float u_detailAmount; // how much of the structure the noise carries, vs the real field
uniform float u_deckFade;     // 0 once the camera is below the deck and clouds stop making sense
uniform vec3 u_camera;        // camera in planet space, earth = unit sphere
// The four lighting terms, each zero-is-off so the deck returns exactly to its old look and each
// can be measured on its own. See the lighting block below for what every one of them buys.
uniform float u_cloudRelief;    // how tall fully covered sky stands, in km; 0 disables the normal
uniform float u_cloudDepth;     // optical depth of fully covered sky, for Beer-Powder
uniform float u_powder;         // how much thin edges darken beyond the plain exponential
uniform float u_forward;        // the silver lining: one Henyey-Greenstein lobe
uniform float u_forwardG;       // that lobe's asymmetry, 0 isotropic, ->1 sharply forward
uniform float u_selfShadow;     // one extra mask read offset toward the sun
uniform float u_selfShadowStep; // how far that read walks, in sphere radii
${Ye}
${me}
${xe}
${et}
${tt}
// The field, the wind and the clock the ground's cloud shadows read from the same source.
${Ze}

/**
 * The cloud's SHAPE at a UV — the whole-planet field, thresholded the same way the deck is.
 *
 * Deliberately not the atlas and not the noise. Both carry detail far finer than a cloud's overall
 * form, and a gradient taken across either is dominated by that detail rather than by the shape it
 * is supposed to describe.
 */
float shapeFieldAt(vec2 uv) {
  return smoothstep(0.16, 0.62, texture(u_field, uv).r);
}

void main() {
  vec3 p = normalize(v_sphere);

  // ── Detail ────────────────────────────────────────────────────────────────────────────────
  // Sampled on the SPHERE, so cloud cells stay the same size at Iceland as at the equator and the
  // field wraps seamlessly across ±180°.
  //
  // The frequency is NOT fixed. A fixed one is what makes the deck fall apart on approach: the
  // real field is 2048x1024, about 19.5 km per pixel, so it is already magnified 16x by z7 and
  // 256x by z11. Past that it is a smooth gradient, the noise on top of it is far too coarse to
  // read, and the whole deck becomes a milky wash over sharp ground. u_detailFreq rises as the
  // camera descends to hold cloud cells at a roughly constant size ON SCREEN, so there is always
  // structure to look at.
  //
  // The warp is what makes it look like weather rather than blobs: displacing the sample point by
  // a lower-frequency noise stretches the cells into filaments and hooks, which is the shape air
  // actually leaves. One extra fbm buys most of the difference.
  float warp = fbm(p * u_detailFreq * 0.35 + vec3(0.0, 0.0, u_time * 0.003));
  float detail = fbm(p * u_detailFreq
                     + vec3(warp, warp * 0.7, -warp * 0.4) * 0.6
                     - vec3(0.0, u_time * 0.01, 0.0));

  float coverage;
  /**
   * Where to read the cloud's SHAPE, as opposed to the cloud that is drawn.
   *
   * Differentiation is a high-pass filter, so a normal computed from the drawn coverage is
   * dominated by whatever varies fastest in it. Lit by a normal, that came out as fine directional
   * hatching over the whole deck — brush strokes rather than cloud, unmissable over Ireland and
   * invisible at globe zoom. Turning this one term off was what identified it.
   *
   * IT TOOK THREE GOES, and the two failures are the useful part. Bounding the slope did almost
   * nothing: the trouble was never the size of the gradient. Removing the procedural noise from it
   * did nothing either, which ruled out the obvious culprit. What is left is the atlas itself — at
   * this range it is sampled at roughly one texel per pixel, and a screen-space derivative of a
   * field varying at the pixel scale is not a measurement of anything. Mipmapping does not save it,
   * because minification picks the level where one texel IS one pixel, so the estimate sits at
   * Nyquist at every zoom the deck is drawn at.
   *
   * So the shape comes from the whole-planet field alone: 19.5 km per texel, hopeless as texture and
   * ideal here. It is also the honest division of labour — light the weather SYSTEMS, whose tops
   * really do catch the sun, and leave the fine structure as what it is, which is texture.
   */
  vec2 shapeUV = equirectUV(p, u_drift);
  // Either real source will do here — the question is only whether there is real weather to draw at
  // all, or whether the deck falls back to latitude-banded noise. Asking about the global field
  // alone would put procedural cloud on a build where the atlas had loaded and it had not.
  if (u_fieldAmount > 0.0 || u_patchAmount > 0.0) {
    // Real weather. The texture decides WHERE cloud is; the noise decides what its edges look
    // like — and as the field runs out of resolution, u_detailAmount hands the noise more of the
    // job, until at close range it is carrying the structure on its own.
    float field = advectedField(shapeUV, asin(clamp(p.y, -1.0, 1.0)));
    coverage = smoothstep(0.16, 0.62, field + u_detailAmount * (detail - 0.5));
  } else {
    float cover = fbm(p * 3.2 + vec3(u_time * 0.006, 0.0, u_time * 0.004)) + 0.35 * detail;
    // Earth's cloud is banded, not evenly scattered: a wet belt at the equator, the clear
    // subtropical highs where the deserts are, then cloudy mid-latitudes again. Without this the
    // fallback reads as a snowball — every ocean and every desert under the same porridge.
    float sinLat = p.y;
    float band = 1.0
      + 0.35 * exp(-pow(sinLat / 0.16, 2.0))                    // ITCZ
      - 0.40 * exp(-pow((abs(sinLat) - 0.50) / 0.16, 2.0))      // subtropical highs
      + 0.20 * exp(-pow((abs(sinLat) - 0.82) / 0.18, 2.0));     // storm tracks
    coverage = smoothstep(0.70, 1.02, cover * band);
  }

  // ── Lighting ──────────────────────────────────────────────────────────────────────────────
  // A cloud emits nothing. It is only ever as bright as what is falling on it, so on the night
  // side it has to go dark along with the ground — a white deck glowing over a black planet is the
  // tell of clouds pasted on as decoration rather than lit as part of the scene.
  vec3 sunDir = normalize(u_sun);
  float sunAngle = dot(p, sunDir);
  float day = daylightFraction(sunAngle);

  // ── 1. Shape, from the mask's own gradient ────────────────────────────────────────────────
  //
  // CLOUD COVERAGE IS A HEIGHTFIELD — thicker cloud is taller cloud — so the deck can have a real
  // normal, and cyclone tops catch the light while their far sides fall into shade. It is the
  // single biggest thing separating cloud from a grey wash, and it is the relief pipeline the
  // ground already uses with a different input: the same tangent frame, the same difference
  // formulation, so a flat deck is left exactly as it was and the effect is naturally strongest at
  // the terminator.
  //
  // FOUR TAPS OF THE COARSE FIELD, which is not where this started. The gradient was taken from
  // screen-space derivatives, twice, because they are free — and both times the result was an
  // artefact rather than a slope. See below for why a fixed step in the field is the only version
  // of this that holds at every zoom.
  mat3 frame = equirectTangentFrame(p);
  vec3 cloudNormal = frame[2];
  if (u_cloudRelief > 0.0) {
    /**
     * The gradient over a FIXED STEP IN THE FIELD, not from screen-space derivatives.
     *
     * Screen derivatives were the third failure of this term and the subtlest. Bilinear
     * interpolation is bilinear WITHIN a texel, so its derivative is discontinuous ACROSS texel
     * boundaries — piecewise, with a jump at every edge. dFdx of it therefore returns a value that
     * is constant-ish inside a texel and steps at the border, and the deck came out in hard-edged
     * rectangles the size of the field's texels. They showed over bright land and hid over dark sea,
     * which is what made them look like a shadow problem; they survive with the shadow switched off.
     *
     * Both earlier attempts were the same mistake at opposite ends. Taking the gradient from the
     * atlas put it at Nyquist and gave hatching; taking it from the 19.5 km field put it below the
     * texel grid and gave blocks. A screen-space derivative is only meaningful where the field
     * varies smoothly across a PIXEL, and neither source does at every zoom.
     *
     * A fixed step in UV has no such dependence. It straddles more than a texel by construction, so
     * it is the same gradient at every zoom, and there is no screen scale left in it to alias.
     */
    const vec2 SHAPE_STEP = vec2(1.5 / 2048.0, 1.5 / 1024.0);
    float east = shapeFieldAt(shapeUV + vec2(SHAPE_STEP.x, 0.0))
               - shapeFieldAt(shapeUV - vec2(SHAPE_STEP.x, 0.0));
    float south = shapeFieldAt(shapeUV + vec2(0.0, SHAPE_STEP.y))
                - shapeFieldAt(shapeUV - vec2(0.0, SHAPE_STEP.y));

    // UV steps into distance along the sphere, in radii: u spans a full 2*pi*cos(lat) circle, v a
    // half-circle of pi. Without the cosine the slope runs away toward the poles.
    float coslat = max(cos(asin(clamp(p.y, -1.0, 1.0))), 0.05);
    vec2 slope = vec2(
      east / (2.0 * SHAPE_STEP.x * 6.28318531 * coslat),
      south / (2.0 * SHAPE_STEP.y * 3.14159265)
    );
    // u_cloudRelief is a HEIGHT IN KILOMETRES applied to a SMOOTHED heightfield, so it runs larger
    // than a real cloud — see the option's own note. The gradient is per sphere radius, so the
    // conversion is the earth's own.
    slope *= u_cloudRelief / 6371.0;

    // Bounded at 56 degrees. A real cloud top does not stand steeper than about this, so anything
    // past it is the estimate rather than the sky. Magnitude only; the direction is left alone.
    float steepness = length(slope);
    if (steepness > 1.5) slope *= 1.5 / steepness;
    cloudNormal = normalize(frame * normalize(vec3(-slope, 1.0)));
  }

  // Exactly reliefLightFactor, at power 1: the normal is already scaled above, and the strength
  // belongs there rather than here so that u_cloudRelief == 0 leaves the normal AS the sphere's and
  // this whole term collapses to 1.
  float shape = reliefLightFactor(frame[2], cloudNormal, sunDir, 1.0);

  // ── 2. Beer-Powder, NOT Lambert ───────────────────────────────────────────────────────────
  //
  // The counter-intuitive part, and where naive cloud lighting always fails. Cloud is dominated by
  // MULTIPLE scattering: light bounces its way back out, so thick cloud is BRIGHTER than thin —
  // the opposite of a solid, where thickness is irrelevant and only the angle matters. Lambert on
  // the normal above would give grey rock with weather-shaped bumps.
  //
  // Two exponentials. The first is the saturating rise of multiple scattering. The second is the
  // powder term, which takes MORE away from the thin edges than the first alone does, because at
  // low optical depth the in-scattering has not had the depth to build up. Without it a cloud's
  // rim fades out linearly and reads as painted-on fog.
  float depth = 1.0;
  if (u_cloudDepth > 0.0) {
    float tau = u_cloudDepth * coverage;
    depth = (1.0 - exp(-tau)) * mix(1.0, 1.0 - exp(-2.0 * tau), u_powder);
    // Against a fully covered pixel, so the term changes the RELATIONSHIP between thickness and
    // brightness rather than acting as a second density dial — turning the depth up must not simply
    // brighten the whole deck.
    float full = (1.0 - exp(-u_cloudDepth)) * mix(1.0, 1.0 - exp(-2.0 * u_cloudDepth), u_powder);
    depth /= max(full, 1.0e-3);

    /**
     * A FLOOR, and 0.65 rather than something near zero — because the term was double-counting.
     *
     * Thin cloud is more TRANSPARENT, and alpha already says so: coverage drives it directly. Using
     * the same thinness to make the cloud DARKER as well charges twice for one fact, and the second
     * charge is the wrong one. A deck at low brightness still writes alpha, so it subtracts from the
     * ground: wisps came out as a dark veil over the sea instead of white against it, and Map works
     * read the whole deck as grey rather than white.
     *
     * Measured, the thinnest quarter went to -0.07 of the brightness it should have had, and then to
     * -0.40 once the global distribution put more genuinely-thin cloud on screen. Not dim. INVERTED
     * — darker than the surface beneath, which no cloud is.
     *
     * Real thin cloud is grey-white, never black, because it scatters skylight from every direction
     * whether or not the sun reaches it. So the powder term keeps its job — thin edges read darker
     * than thick centres, which is what stops a cloud looking like painted-on fog — over a range
     * that stays above the sea rather than going through it.
     */
    depth = mix(0.65, 1.0, depth);
  }

  // ── 4. Self-shadowing ─────────────────────────────────────────────────────────────────────
  //
  // One extra read of the mask, offset toward the sun: whatever cloud is over there is what stands
  // between this cloud and the light, and that is what gives the underside real depth rather than
  // an evenly lit blanket. Gated on the uniform because it is the only term here that costs
  // another nine texture reads, so it is the first thing a slower machine should drop.
  float shadow = 1.0;
  if (u_selfShadow > 0.0) {
    vec3 toward = normalize(p + sunDir * u_selfShadowStep);
    float over = advectedField(equirectUV(toward, u_drift), asin(clamp(toward.y, -1.0, 1.0)));
    // Fades out as the sun drops: at grazing light the offset walks most of the way round the
    // planet and what it finds has nothing to do with this pixel.
    shadow = 1.0 - u_selfShadow * smoothstep(0.16, 0.62, over) * smoothstep(0.0, 0.35, sunAngle);
  }

  // The deck's own modelling: a lit face and a shaded face, like the real thing — but cloud is
  // WHITE, and its shaded face is white in shadow, not grey paint. The old pair (0.62,0.66,0.72
  // lifted from 0.35) left everything away from the subsolar point reading as blue-grey smoke,
  // which at high latitude is most of what is on screen. Bright, barely-tinted shadow instead.
  vec3 base = mix(vec3(0.86, 0.88, 0.92), vec3(1.0), 0.55 + 0.45 * max(sunAngle, 0.0));

  // Shape and depth multiply the light the top returns; the shadow takes some of it away. All three
  // are 1 at their off settings, so the deck is bit-for-bit its old self with the strengths at zero.
  base *= clamp(shape * depth * shadow, 0.0, 4.0);

  // At the terminator the light reaching them has crossed the most air and lost its blue, so the
  // tops go orange while the ground below is already dark. It is the best thing clouds do.
  float twilight = 1.0 - abs(day * 2.0 - 1.0);
  base = mix(base, base * vec3(1.30, 0.74, 0.44), twilight * twilight * 0.85);

  // ── 3. Forward scattering — the silver lining ─────────────────────────────────────────────
  //
  // Cloud droplets are far larger than the wavelength, so they throw light overwhelmingly FORWARD:
  // a cloud between you and the sun is brighter at its rim than anywhere on its lit face. One
  // Henyey-Greenstein lobe and a single dot product buys it, which is the best payoff per
  // instruction anywhere in this shader.
  //
  // It lands hardest at the terminator, because that is where the sun sits behind the cloud from
  // the camera's point of view — the same geometry that already makes this globe's best picture.
  //
  // ADDED, not multiplied. Forward scattering is light arriving by a path the diffuse term does not
  // model at all, so folding it into a multiplier would make a rim brighter only where the cloud
  // was already bright, which is precisely backwards.
  if (u_forward > 0.0) {
    vec3 toEye = normalize(u_camera - p);
    // Angle between where the photon was going and where it is going now. dot(toEye, -sunDir) is 1
    // when it carries straight on toward the camera, which is the forward peak.
    float phase = -dot(toEye, sunDir);
    float g = clamp(u_forwardG, 0.0, 0.95);
    float gg = g * g;
    float lobe = (1.0 - gg) / pow(max(1.0 + gg - 2.0 * g * phase, 1.0e-4), 1.5);
    // Normalised by its own peak, (1+g)/(1-g)^2, so u_forward is a strength in 0..1 rather than a
    // number whose meaning changes every time the asymmetry is dragged.
    float peak = (1.0 + g) / max((1.0 - g) * (1.0 - g), 1.0e-4);
    // Through the cloud. NOT gated on daylight here: the night floor below already crushes anything
    // on the dark side, and gating twice killed the effect exactly where it belongs. The deck sits
    // 90 km up and stays lit about ten degrees past the ground's terminator, which is the band this
    // term is for — a cloud edge glowing over ground that has already gone dark.
    base += vec3(1.0, 0.98, 0.94) * (u_forward * (lobe / peak) * coverage);
  }

  // Not quite zero at night: a deck at pure black reads as a hole punched in the planet rather
  // than as cloud. This is roughly what starlight and airglow leave on a real night-side deck.
  const float NIGHT_FLOOR = 0.05;
  base *= NIGHT_FLOOR + (1.0 - NIGHT_FLOOR) * day;

  // Below the deck there is no deck. A cloud shell drawn over a street is not weather, it is a
  // fog filter, and no amount of detail rescues it — so it fades out rather than being faked.
  float alpha = coverage * u_opacity * u_deckFade;
  if (alpha < 0.002) discard;
  // MapLibre blends custom layers with gl.ONE / gl.ONE_MINUS_SRC_ALPHA, i.e. PREMULTIPLIED alpha.
  // Returning straight alpha here is what makes a custom layer glow white over dark ground.
  fragColour = vec4(base * alpha, alpha);
}`,zt=({opacity:e=.5,animate:a=!0,fieldUrl:i=null,patchUrl:l=null,driftRate:u=4e-4,sun:f=[.4,.5,.75],windUrl:o=null,windAmount:p=1,windScale:t=.06,windRate:y=.05,cloudRelief:A=90,cloudDepth:x=4,powder:g=1,forward:T=.5,forwardG:h=.7,selfShadow:c=.18,selfShadowStep:_=.0015}={})=>{const w=de();let R=null,n=null,r=null;const C=new Map;let D={opacity:e,animate:a,fieldUrl:i,patchUrl:l,driftRate:u,sun:f,windUrl:o,windAmount:p,windScale:t,windRate:y,cloudRelief:A,cloudDepth:x,powder:g,forward:T,forwardG:h,selfShadow:c,selfShadowStep:_},M=null,O=null,s=null;const U=L=>{const E=L.variantName;if(C.has(E))return C.get(E);const m=V(n,Gt(L),Ht(),"tm-clouds"),S={program:m,attribs:{pos:n.getAttribLocation(m,"a_pos"),sphere:n.getAttribLocation(m,"a_sphere")},uniforms:{elevationGlobe:n.getUniformLocation(m,"a_elevation_globe"),elevationMercator:n.getUniformLocation(m,"a_elevation_mercator"),opacity:n.getUniformLocation(m,"u_opacity"),sun:n.getUniformLocation(m,"u_sun"),...Object.fromEntries(Qe.map(d=>[d,n.getUniformLocation(m,`u_${d}`)])),detailFreq:n.getUniformLocation(m,"u_detailFreq"),detailAmount:n.getUniformLocation(m,"u_detailAmount"),deckFade:n.getUniformLocation(m,"u_deckFade"),camera:n.getUniformLocation(m,"u_camera"),cloudRelief:n.getUniformLocation(m,"u_cloudRelief"),cloudDepth:n.getUniformLocation(m,"u_cloudDepth"),powder:n.getUniformLocation(m,"u_powder"),forward:n.getUniformLocation(m,"u_forward"),forwardG:n.getUniformLocation(m,"u_forwardG"),selfShadow:n.getUniformLocation(m,"u_selfShadow"),selfShadowStep:n.getUniformLocation(m,"u_selfShadowStep"),matrix:n.getUniformLocation(m,"u_projection_matrix"),tileMercatorCoords:n.getUniformLocation(m,"u_projection_tile_mercator_coords"),clippingPlane:n.getUniformLocation(m,"u_projection_clipping_plane"),transition:n.getUniformLocation(m,"u_projection_transition"),fallbackMatrix:n.getUniformLocation(m,"u_projection_fallback_matrix")}};return C.set(E,S),S},G=(L,E)=>{L.matrix&&n.uniformMatrix4fv(L.matrix,!1,E.mainMatrix),L.tileMercatorCoords&&n.uniform4f(L.tileMercatorCoords,...E.tileMercatorCoords),L.clippingPlane&&n.uniform4f(L.clippingPlane,...E.clippingPlane),L.transition&&n.uniform1f(L.transition,E.projectionTransition),L.fallbackMatrix&&n.uniformMatrix4fv(L.fallbackMatrix,!1,E.fallbackMatrix)},B=()=>R?.triggerRepaint();return{id:at,type:"custom",renderingMode:"3d",onAdd(L,E){R=L,n=E;const m=(S,d)=>{const F=n.createBuffer();return n.bindBuffer(S,F),n.bufferData(S,d,n.STATIC_DRAW),F};r={pos:m(n.ARRAY_BUFFER,w.positions),sphere:m(n.ARRAY_BUFFER,w.spheres),index:m(n.ELEMENT_ARRAY_BUFFER,w.indices)},D.fieldUrl&&(M=W(n,D.fieldUrl,B)),D.windUrl&&(O=W(n,D.windUrl,B)),D.patchUrl&&(s=W(n,D.patchUrl,B))},onRemove(){n&&(C.forEach(({program:L})=>n.deleteProgram(L)),C.clear(),M?.release(),M=null,O?.release(),O=null,s?.release(),s=null,r&&(n.deleteBuffer(r.pos),n.deleteBuffer(r.sphere),n.deleteBuffer(r.index),r=null),R=null,n=null)},render(L,E){if(!r||D.opacity<=0)return;const m=E&&E.shaderData,S=E&&E.defaultProjectionData;if(!m||!S)return;const{program:d,attribs:F,uniforms:P}=U(m);n.useProgram(d),G(P,S);const H=R.getCenter().lat;P.elevationGlobe&&n.uniform1f(P.elevationGlobe,se),P.elevationMercator&&n.uniform1f(P.elevationMercator,j.MercatorCoordinate.fromLngLat([0,H],se).z),P.opacity&&n.uniform1f(P.opacity,D.opacity),P.sun&&n.uniform3f(P.sun,...D.sun),Je(n,P,D,{seconds:performance.now()*.001,field:M,wind:O,patches:s},0,1,2);const v=Ot(R.getZoom(),R.getCenter().lat,Re(R,j));P.detailFreq&&n.uniform1f(P.detailFreq,v.frequency),P.detailAmount&&n.uniform1f(P.detailAmount,v.amount),P.deckFade&&n.uniform1f(P.deckFade,v.fade),P.camera&&n.uniform3f(P.camera,...J(R,j));const N=b=>{P[b]&&n.uniform1f(P[b],D[b])};N("cloudRelief"),N("cloudDepth"),N("powder"),N("forward"),N("forwardG"),N("selfShadow"),N("selfShadowStep"),n.bindBuffer(n.ARRAY_BUFFER,r.pos),n.enableVertexAttribArray(F.pos),n.vertexAttribPointer(F.pos,2,n.FLOAT,!1,0,0),n.bindBuffer(n.ARRAY_BUFFER,r.sphere),n.enableVertexAttribArray(F.sphere),n.vertexAttribPointer(F.sphere,3,n.FLOAT,!1,0,0),n.enable(n.DEPTH_TEST),n.depthFunc(n.LEQUAL),n.depthMask(!1),n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,r.index),n.drawElements(n.TRIANGLES,w.indices.length,n.UNSIGNED_SHORT,0),n.depthMask(!0),D.animate&&R.triggerRepaint()},setOptions(L={}){const E=D;if(D={...D,...L},n){const m=(S,d)=>L[d]===void 0||L[d]===E[d]?S:(S?.release(),D[d]?W(n,D[d],B):null);M=m(M,"fieldUrl"),s=m(s,"patchUrl")}R&&R.triggerRepaint()},getOptions:()=>({...D}),get hasField(){return!!(M?.ready||s?.ready)}}},$t=at,Y=Math.PI/180,qt=Date.UTC(2e3,0,1,12),_e=1/3600,ot=e=>(e.getTime()-qt)/864e5,Xt=(e=new Date)=>((18.697374558+24.06570982441908*ot(e))%24*15%360+360)%360,jt=e=>{const a=ot(e)/36525;return{zeta:(2306.2181*a+.30188*a*a+.017998*a*a*a)*_e,z:(2306.2181*a+1.09468*a*a+.018203*a*a*a)*_e,theta:(2004.3109*a-.42665*a*a-.041833*a*a*a)*_e}},Yt=(e,a,i)=>{const{zeta:l,z:u,theta:f}=jt(i),o=(e-u)*Y,p=a*Y,t=f*Y,y=Math.cos(p)*Math.sin(o),A=Math.cos(t)*Math.cos(p)*Math.cos(o)+Math.sin(t)*Math.sin(p),x=-Math.sin(t)*Math.cos(p)*Math.cos(o)+Math.cos(t)*Math.sin(p);return{ra:Math.atan2(y,A)/Y-l,dec:Math.asin(Math.min(1,Math.max(-1,x)))/Y}},Ue=e=>(e%360+360)%360,Wt=(e,a=new Date)=>{const i=Math.hypot(e[0],e[1],e[2])||1,l=Math.atan2(e[2]/i,e[0]/i)/Y,u=Math.asin(Math.min(1,Math.max(-1,e[1]/i)))/Y,f=Yt(Ue(l+Xt(a)),u,a);return{ra:Ue(f.ra),dec:f.dec}},Kt=`
  vec2 panoramaUV(vec3 skyDir) {
    return vec2(atan(skyDir.z, skyDir.x) / 6.28318530718 + 1.0,
                0.5 - asin(clamp(skyDir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,Vt=(e=new Date)=>{const a=new Float32Array(9);return[[1,0,0],[0,1,0],[0,0,1]].forEach((l,u)=>{const{ra:f,dec:o}=Wt(l,e);a[u*3]=Math.cos(o*Y)*Math.cos(f*Y),a[u*3+1]=Math.sin(o*Y),a[u*3+2]=Math.cos(o*Y)*Math.sin(f*Y)}),a},Zt=Math.PI/180,le=e=>{const a=Math.hypot(e[0],e[1],e[2])||1;return[e[0]/a,e[1]/a,e[2]/a]},Qt=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],Ee=(e,a)=>[e[0]-a[0],e[1]-a[1],e[2]-a[2]],De=(e,a)=>{const i=Qt(e,a);return le([e[0]-a[0]*i,e[1]-a[1]*i,e[2]-a[2]*i])},Jt=(e,a)=>{const l=a>89.98?-1:1,u=le(Ee(K(e,a+.01*l),K(e,a-.01*l))),f=le(Ee(K(e+.01,a),K(e-.01,a)));return{north:l===1?u:[-u[0],-u[1],-u[2]],east:f}},ea=(e,a,i,l)=>{const u=e.getCenter(),f=(e.getBearing?.()??0)*Zt,o=K(u.lng,u.lat,0),p=J(e,a),t=le(Ee(o,p)),{north:y,east:A}=Jt(u.lng,u.lat),x=[0,1,2].map(w=>y[w]*Math.cos(f)+A[w]*Math.sin(f)),g=[0,1,2].map(w=>-y[w]*Math.sin(f)+A[w]*Math.cos(f)),T=De(x,t),h=De(g,t),c=Math.tan(i/2),_=c*l;return{origin:p,forward:t,up:T.map(w=>w*c),right:h.map(w=>w*_),upUnit:T,rightUnit:h}},Te=(e,a)=>e*a*4,rt=[{name:"high",width:4096,height:2048,url:"/img/map/sky/milkyway-4k.webp",bytes:3680220,resident:Te(4096,2048),decodeMs:235},{name:"standard",width:2048,height:1024,url:"/img/map/sky/milkyway-2k.webp",bytes:764012,resident:Te(2048,1024),decodeMs:51}],it={name:"placeholder",width:1024,height:512,url:"/img/map/sky/milkyway-1k.webp",bytes:45160,resident:Te(1024,512),decodeMs:5},ta=4,aa=4,oa=({maxTextureSize:e=0,deviceMemory:a=null,hardwareConcurrency:i=null,saveData:l=!1,effectiveType:u=null}={})=>{const f=rt.filter(t=>t.width<=e);if(f.length===0)return{tier:it,reason:`MAX_TEXTURE_SIZE is ${e}, below every tier — falling back to the placeholder`};const o=f[f.length-1],p=f[0];return p===o?{tier:p,reason:`only ${p.name} fits MAX_TEXTURE_SIZE ${e}`}:l?{tier:o,reason:"the browser is in data-saver mode"}:u&&/^(slow-2g|2g|3g)$/.test(u)?{tier:o,reason:`the connection reports ${u}`}:Number.isFinite(a)&&a<ta?{tier:o,reason:`the device reports ${a} GB of memory`}:Number.isFinite(i)&&i<aa?{tier:o,reason:`the device reports ${i} cores`}:{tier:p,reason:`nothing says otherwise, and MAX_TEXTURE_SIZE is ${e}`}},ra=(e,a=typeof navigator>"u"?null:navigator)=>{const i=a?.connection??null;return{maxTextureSize:e?.getParameter?.(e.MAX_TEXTURE_SIZE)??0,deviceMemory:a?.deviceMemory??null,hardwareConcurrency:a?.hardwareConcurrency??null,saveData:i?.saveData??!1,effectiveType:i?.effectiveType??null}},nt="tm-starfield",ia=it.url,na="/data/sky/bright-stars.bin",sa=e=>{const a=Number.isFinite(e)?e:5800,i=Math.min(4e4,Math.max(1e3,a))/100,l=y=>Math.min(1,Math.max(0,y/255)),u=i<=66?255:329.698727446*Math.pow(i-60,-.1332047592),f=i<=66?99.4708025861*Math.log(i)-161.1195681661:288.1221695283*Math.pow(i-60,-.0755148492),o=i>=66?255:i<=19?0:138.5177312231*Math.log(i-10)-305.0447927307,p=[l(u),l(f),l(o)],t=Math.max(...p)||1;return p.map(y=>y/t)},la=(e,{limitMagnitude:a=6.5}={})=>{const i=new Float32Array(e),l=Math.floor(i.length/4),u=[];for(let o=0;o<l;o++){const p=i[o*4+2];if(p>a)continue;const t=Math.pow(10,-.4*p);u.push([i[o*4],i[o*4+1],Math.pow(t,.36),...sa(i[o*4+3])])}const f=new Float32Array(u.length*6);return u.forEach((o,p)=>f.set(o,p*6)),{vertices:f,count:u.length}},st=`
  uniform vec3 u_camera;      // camera in planet space, earth = unit sphere
  uniform vec3 u_forward;
  uniform vec3 u_right;
  uniform vec3 u_up;
  uniform vec2 u_halfExtent;  // tan(fov/2), times aspect for x

  vec3 rayThrough(vec2 ndc) {
    return normalize(u_forward + u_right * (ndc.x * u_halfExtent.x) + u_up * (ndc.y * u_halfExtent.y));
  }

  // Standard ray against the unit sphere. A hit in front of the camera means the planet is in the
  // way — exact at any distance, which the depth buffer is not at this one.
  bool earthBlocks(vec3 ray) {
    float b = dot(u_camera, ray);
    float c = dot(u_camera, u_camera) - 1.0;
    float disc = b * b - c;
    if (disc < 0.0) return false;
    return (-b - sqrt(disc)) > 0.0;
  }
`,ha=e=>`${e.define}
attribute vec2 a_pos;
varying vec2 v_ndc;
void main() {
  v_ndc = a_pos;
  // Straight to clip space. No projection, no elevation, no prelude — that is the whole point.
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`,ca=e=>`${e.define}
precision highp float;
varying vec2 v_ndc;
uniform sampler2D u_sky;
uniform mat3 u_skyFrame;     // planet space -> the J2000 grid the panorama is drawn in
uniform float u_globeness;   // 1 on the globe, 0 on the flat map
uniform float u_brightness;
uniform float u_nebula;      // 0 drops the panorama and leaves bare stars
uniform float u_nebulaContrast;
uniform float u_starDensity; // cells per radian: higher is more, smaller stars
uniform float u_starAmount;
uniform float u_time;
uniform float u_twinkle;
${st}
${Kt}

vec3 hash3(vec3 p) {
  p = vec3(dot(p, vec3(127.1, 311.7, 74.7)),
           dot(p, vec3(269.5, 183.3, 246.1)),
           dot(p, vec3(113.5, 271.9, 124.6)));
  return fract(sin(p) * 43758.5453123);
}

// One star per cell at most, placed by hash. Sampling the 3x3x3 neighbourhood would let stars sit
// near cell edges without clipping, at 27x the cost; at these densities a single cell is enough.
vec3 stars(vec3 skyDir) {
  vec3 p = skyDir * u_starDensity;
  vec3 cell = floor(p);
  vec3 h = hash3(cell);

  // Most of the sky is empty. This threshold is what keeps stars sparse rather than a dot screen.
  if (h.x > 0.05) return vec3(0.0);

  vec3 offset = hash3(cell + 17.0);
  float dist = length(fract(p) - offset);

  // A STAR HAS TO BE BIGGER THAN A PIXEL OR IT IS NOT THERE.
  //
  // The obvious way to draw a point source is a very steep falloff, and it silently produces an
  // empty sky. At this density a cell is about seven screen pixels, so a profile that has decayed
  // by a tenth of a cell is a third of a pixel wide — narrower than the sample grid. Nothing is
  // clipped and nothing is discarded; the pixel centres simply never land on a star, and the whole
  // layer measures as exactly zero across a hundred thousand pixels while looking, in code,
  // entirely reasonable.
  //
  // So the profile is a gaussian roughly a pixel and a half across, which always registers, and
  // which is also what a real point source looks like once optics have spread it.
  float core = exp(-dist * dist * 60.0);

  // These are the stars no naked-eye catalogue lists, so they sit BELOW the real ones: lopsided
  // toward the faint end, and capped well under the brightness of a catalogue star. The floor is
  // what stops the faint majority being crushed below one 255th and vanishing for the same reason.
  float magnitude = 0.10 + 0.55 * pow(h.y, 4.5);

  // Blue-white through to amber, weighted toward white as real naked-eye stars are.
  vec3 tint = mix(vec3(0.74, 0.83, 1.0), vec3(1.0, 0.84, 0.66), pow(h.z, 1.6));

  // Atmospheric scintillation, per star, at its own rate. Off by default: from orbit there is no
  // air to twinkle through, so it is a stylistic choice rather than a physical one.
  float flicker = 1.0 - u_twinkle * 0.5 * (0.5 + 0.5 * sin(u_time * (1.7 + h.z * 4.0) + h.y * 31.4));

  return tint * core * magnitude * flicker;
}

void main() {
  #ifdef GLOBE
    vec3 ray = rayThrough(v_ndc);
    if (earthBlocks(ray)) discard;

    vec3 skyDir = u_skyFrame * ray;

    // The panorama is stored with a gamma on it, which is what gets the faint structure into eight
    // bits at all. Read back flat it is a grey haze with the dust lanes floating in it, so a little
    // of that gamma is taken out again here — enough that empty sky reads as empty and the band
    // reads as a band, not enough to lose what the gamma was for.
    vec3 sky = pow(texture2D(u_sky, panoramaUV(skyDir)).rgb, vec3(u_nebulaContrast)) * u_nebula;
    sky += stars(skyDir) * u_starAmount;
    // Premultiplied, because MapLibre blends ONE / ONE_MINUS_SRC_ALPHA. The fade is what dissolves
    // the sky as the globe flattens into the mercator map, where there is no space to look into.
    gl_FragColor = vec4(sky * u_brightness * u_globeness, u_globeness);
  #else
    // A flat map has no sky around it. Drawing one would put stars over Kansas.
    discard;
  #endif
}`,ua=e=>`${e.define}
attribute vec3 a_star;       // right ascension and declination in degrees, then brightness
attribute vec3 a_colour;
uniform mat3 u_skyFrame;
uniform float u_pixelRatio;
uniform float u_starSize;
uniform float u_catalogueAmount;
varying vec3 v_colour;
${st}

void main() {
  float ra = radians(a_star.x);
  float dec = radians(a_star.y);
  vec3 celestial = vec3(cos(dec) * cos(ra), sin(dec), cos(dec) * sin(ra));

  // v * M is transpose(M) * v, and the transpose of the sky frame is the star frame. One uniform
  // does both directions, which is the only way to be sure they agree.
  vec3 dir = celestial * u_skyFrame;

  float depth = dot(dir, u_forward);
  if (depth <= 0.0 || earthBlocks(dir)) {
    // Behind the camera, or behind the earth. Park it off screen at zero size rather than
    // discarding in the fragment shader, which would still cost the rasterisation.
    gl_Position = vec4(2.0, 2.0, 2.0, 1.0);
    gl_PointSize = 0.0;
    return;
  }

  vec2 ndc = vec2(dot(dir, u_right) / u_halfExtent.x, dot(dir, u_up) / u_halfExtent.y) / depth;
  gl_Position = vec4(ndc, 0.0, 1.0);

  // Brighter stars are drawn bigger as well as brighter, which is how every star chart ever printed
  // conveys magnitude, and how a telescope-free eye perceives it too.
  gl_PointSize = u_pixelRatio * u_starSize * (0.85 + 1.9 * a_star.z);
  v_colour = a_colour * a_star.z * u_catalogueAmount;
}`,da=e=>`${e.define}
precision highp float;
varying vec3 v_colour;
void main() {
  // A gaussian across the point sprite. Same reasoning as the procedural field: a hard disc reads
  // as a dot of paint, and anything much tighter than a pixel measures as nothing at all.
  vec2 offset = gl_PointCoord - 0.5;
  float core = exp(-dot(offset, offset) * 22.0);
  // Additive against premultiplied blending: zero alpha adds the colour and takes nothing away, so
  // a star lies on top of the nebula behind it rather than punching a hole in it.
  gl_FragColor = vec4(v_colour * core, 0.0);
}`,fa=({textureUrl:e=null,placeholderUrl:a=ia,catalogueUrl:i=na,date:l=new Date,brightness:u=.55,nebula:f=.3,nebulaContrast:o=1.45,starDensity:p=210,starAmount:t=1.5,catalogueAmount:y=2.2,starSize:A=3,limitMagnitude:x=6.5,twinkle:g=0,animate:T=!1}={})=>{let h=null,c=null,_=null,w=null,R=null,n=null,r="not chosen yet",C=0;const D=new Map;let M={textureUrl:e,placeholderUrl:a,catalogueUrl:i,date:l,brightness:u,nebula:f,nebulaContrast:o,starDensity:p,starAmount:t,catalogueAmount:y,starSize:A,limitMagnitude:x,twinkle:g,animate:T};const O=L=>{const E=L.variantName;if(D.has(E))return D.get(E);const m=V(c,ha(L),ca(L),"tm-starfield"),S=V(c,ua(L),da(L),"tm-starfield-catalogue"),d=(P,H)=>c.getUniformLocation(P,H),F={sky:{program:m,pos:c.getAttribLocation(m,"a_pos"),uniforms:{camera:d(m,"u_camera"),forward:d(m,"u_forward"),right:d(m,"u_right"),up:d(m,"u_up"),halfExtent:d(m,"u_halfExtent"),skyFrame:d(m,"u_skyFrame"),sky:d(m,"u_sky"),globeness:d(m,"u_globeness"),brightness:d(m,"u_brightness"),nebula:d(m,"u_nebula"),nebulaContrast:d(m,"u_nebulaContrast"),starDensity:d(m,"u_starDensity"),starAmount:d(m,"u_starAmount"),twinkle:d(m,"u_twinkle"),time:d(m,"u_time")}},stars:{program:S,star:c.getAttribLocation(S,"a_star"),colour:c.getAttribLocation(S,"a_colour"),uniforms:{camera:d(S,"u_camera"),forward:d(S,"u_forward"),right:d(S,"u_right"),up:d(S,"u_up"),halfExtent:d(S,"u_halfExtent"),skyFrame:d(S,"u_skyFrame"),pixelRatio:d(S,"u_pixelRatio"),starSize:d(S,"u_starSize"),catalogueAmount:d(S,"u_catalogueAmount")}}};return D.set(E,F),F},s=()=>{if(!c)return;const L=(E,m)=>E?W(c,E,m,{mipmap:!1}):null;if(M.textureUrl===null){const E=oa(ra(c));n=E.tier,r=E.reason}else n=rt.find(E=>E.url===M.textureUrl)??{name:"explicit",url:M.textureUrl},r="the caller named a panorama";R=L(M.placeholderUrl,()=>{h?.triggerRepaint()}),w=L(n.url===M.placeholderUrl?null:n.url,()=>{R?.release(),R=null,h?.triggerRepaint()})},U=()=>w?.ready?w:R?.ready?R:null,G=()=>{w?.release(),R?.release(),w=null,R=null},B=L=>{!L||typeof fetch!="function"||fetch(L).then(E=>{if(!E.ok)throw new Error(`${E.status} ${E.statusText}`);return E.arrayBuffer()}).then(E=>{if(!c||!_)return;const{vertices:m,count:S}=la(E,{limitMagnitude:M.limitMagnitude});c.bindBuffer(c.ARRAY_BUFFER,_.stars),c.bufferData(c.ARRAY_BUFFER,m,c.STATIC_DRAW),C=S,h?.triggerRepaint()}).catch(E=>console.warn(`[starfield] bright star catalogue unavailable: ${E.message}`))};return{id:nt,type:"custom",renderingMode:"3d",onAdd(L,E){h=L,c=E;const m=(S,d)=>{const F=c.createBuffer();return c.bindBuffer(S,F),d&&c.bufferData(S,d,c.STATIC_DRAW),F};_={pos:m(c.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,1,1])),index:m(c.ELEMENT_ARRAY_BUFFER,new Uint16Array([0,1,2,2,1,3])),stars:m(c.ARRAY_BUFFER,null)},s(),B(M.catalogueUrl)},onRemove(){c&&(D.forEach(({sky:L,stars:E})=>{c.deleteProgram(L.program),c.deleteProgram(E.program)}),D.clear(),G(),_&&(c.deleteBuffer(_.pos),c.deleteBuffer(_.index),c.deleteBuffer(_.stars),_=null),h=null,c=null)},render(L,E){if(!_||M.brightness<=0)return;const m=E&&E.shaderData,S=E&&E.defaultProjectionData;if(!m||!S)return;const d=h.getCanvas(),F=d.width/Math.max(1,d.height),P=ea(h,j,E.fov||.6435,F),H=Math.tan((E.fov||.6435)/2),v=Vt(M.date),N=S.projectionTransition,{sky:b,stars:I}=O(m),z=({uniforms:k})=>{k.camera&&c.uniform3f(k.camera,...P.origin),k.forward&&c.uniform3f(k.forward,...P.forward),k.right&&c.uniform3f(k.right,...P.rightUnit),k.up&&c.uniform3f(k.up,...P.upUnit),k.halfExtent&&c.uniform2f(k.halfExtent,H*F,H),k.skyFrame&&c.uniformMatrix3fv(k.skyFrame,!1,v)};c.useProgram(b.program),z(b),b.uniforms.globeness&&c.uniform1f(b.uniforms.globeness,N),b.uniforms.brightness&&c.uniform1f(b.uniforms.brightness,M.brightness);const q=U();b.uniforms.nebula&&c.uniform1f(b.uniforms.nebula,q?M.nebula:0),b.uniforms.nebulaContrast&&c.uniform1f(b.uniforms.nebulaContrast,M.nebulaContrast),b.uniforms.starDensity&&c.uniform1f(b.uniforms.starDensity,M.starDensity),b.uniforms.starAmount&&c.uniform1f(b.uniforms.starAmount,M.starAmount),b.uniforms.twinkle&&c.uniform1f(b.uniforms.twinkle,M.twinkle),b.uniforms.time&&c.uniform1f(b.uniforms.time,M.animate?performance.now()*.001:0),q&&b.uniforms.sky&&(c.activeTexture(c.TEXTURE0),c.bindTexture(c.TEXTURE_2D,q.texture),c.uniform1i(b.uniforms.sky,0)),c.bindBuffer(c.ARRAY_BUFFER,_.pos),c.enableVertexAttribArray(b.pos),c.vertexAttribPointer(b.pos,2,c.FLOAT,!1,0,0),c.disable(c.DEPTH_TEST),c.depthMask(!1),c.bindBuffer(c.ELEMENT_ARRAY_BUFFER,_.index),c.drawElements(c.TRIANGLES,6,c.UNSIGNED_SHORT,0),C>0&&N>.5&&M.catalogueAmount>0&&(c.useProgram(I.program),z(I),I.uniforms.pixelRatio&&c.uniform1f(I.uniforms.pixelRatio,typeof devicePixelRatio=="number"?devicePixelRatio:1),I.uniforms.starSize&&c.uniform1f(I.uniforms.starSize,M.starSize),I.uniforms.catalogueAmount&&c.uniform1f(I.uniforms.catalogueAmount,M.catalogueAmount*M.brightness),c.bindBuffer(c.ARRAY_BUFFER,_.stars),c.enableVertexAttribArray(I.star),c.vertexAttribPointer(I.star,3,c.FLOAT,!1,24,0),c.enableVertexAttribArray(I.colour),c.vertexAttribPointer(I.colour,3,c.FLOAT,!1,24,12),c.drawArrays(c.POINTS,0,C),c.disableVertexAttribArray(I.star),c.disableVertexAttribArray(I.colour)),c.enable(c.DEPTH_TEST),c.depthMask(!0),M.animate&&M.twinkle>0&&h.triggerRepaint()},setOptions(L={}){const E=M.textureUrl,m=M.catalogueUrl;M={...M,...L},L.textureUrl!==void 0&&L.textureUrl!==E&&(G(),s()),L.catalogueUrl!==void 0&&L.catalogueUrl!==m&&(C=0,B(L.catalogueUrl)),h?.triggerRepaint()},getOptions:()=>({...M}),get hasSky(){return U()!==null},get starCount(){return C},get skyTier(){return n?{...n,reason:r}:null}}},ma=nt,Z=Math.PI/180,pa=Date.UTC(2e3,0,1,12),ga=149597870700,wa=6957e5,lt=e=>(e.getTime()-pa)/864e5,ht=(e=new Date)=>{const a=lt(e),i=(280.46+.9856474*a)%360,l=(357.528+.9856003*a)%360*Z,u=(i+1.915*Math.sin(l)+.02*Math.sin(2*l))*Z,f=(23.439-4e-7*a)*Z,o=Math.asin(Math.sin(f)*Math.sin(u))/Z;let p=Math.atan2(Math.cos(f)*Math.sin(u),Math.cos(u))/Z;p<0&&(p+=360);let t=i-p;t>180&&(t-=360),t<-180&&(t+=360),t*=4;const y=(1.00014-.01671*Math.cos(l)-14e-5*Math.cos(2*l))*ga;return{declination:o,equationOfTime:t,distance:y}},ct=(e=new Date)=>ht(e).distance,_a=(e=new Date)=>Math.atan(wa/ct(e)),va=(e=new Date)=>{const a=(357.528+.9856003*lt(e))%360*Z;return(1.00014-.01671*Math.cos(a)-14e-5*Math.cos(2*a))*149597870700},ba=(e=new Date)=>{const{declination:a,equationOfTime:i}=ht(e);let u=-15*(e.getUTCHours()+e.getUTCMinutes()/60+e.getUTCSeconds()/3600-12+i/60);return u=(u+540)%360-180,{lng:u,lat:a}},he=(e=new Date)=>{const{lng:a,lat:i}=ba(e),l=i*Z,u=a*Z;return[Math.cos(l)*Math.cos(u),Math.sin(l),Math.cos(l)*Math.sin(u)]},ut="tm-sun",Pe=63710088e-1,ya=18,Ea=(e,a,i)=>{const l=Math.abs(e),u=a,f=i;if(u<=0)return 0;if(l>=u+f)return 1;if(l<=f-u)return 0;if(l<=u-f)return 1-f*f/(u*u);const o=u*u*Math.acos(te((l*l+u*u-f*f)/(2*l*u),-1,1))+f*f*Math.acos(te((l*l+f*f-u*u)/(2*l*f),-1,1))-.5*Math.sqrt(Math.max(0,(-l+u+f)*(l+u-f)*(l-u+f)*(l+u+f)));return te(1-o/(Math.PI*u*u),0,1)},Ie={u1:.93,u2:-.23},Fe=e=>e<0?`(${e.toFixed(4)})`:e.toFixed(4),Ta=`
  float limbDarkening(float rho) {
    float mu = sqrt(max(1.0 - rho * rho, 0.0));
    float t = 1.0 - mu;
    return max(1.0 - ${Fe(Ie.u1)} * t - ${Fe(Ie.u2)} * t * t, 0.0);
  }
`,Aa=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Ce=new Uint16Array([0,1,2,1,3,2]),xa=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_corner;
uniform vec2 a_centre;              // where the sun lies, in mercator 0..1
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
uniform vec2 u_size;                // half-size of the glow quad in clip units, x and y
varying vec2 v_corner;
void main() {
  v_corner = a_corner;
  #ifdef GLOBE
    vec4 centre = projectTileFor3D(a_centre, a_elevation_globe);
  #else
    vec4 centre = projectTileFor3D(a_centre, a_elevation_mercator);
  #endif
  // Scaling by w cancels the perspective divide, so the glow keeps its angular size.
  gl_Position = centre + vec4(a_corner * u_size * centre.w, 0.0, 0.0);
}`,Ra=()=>`precision highp float;
varying vec2 v_corner;
uniform vec3 u_camera;              // planet space, earth as a unit sphere at the origin
uniform vec3 u_forward;             // from the camera toward the sun
uniform vec3 u_right;               // the billboard's basis, same space
uniform vec3 u_up;
uniform float u_glow_angle;         // angular radius of the whole quad, radians
uniform float u_disc_fraction;      // where the sun's limb falls, as a fraction of the quad
uniform float u_disc_gain;          // exposure: how far above clipping the disc's centre sits
uniform float u_visible;            // how much of the disc the planet leaves showing, 0..1
uniform float u_brightness;
uniform float u_halo_strength;
uniform vec3 u_core_colour;
uniform vec3 u_halo_colour;
${We}
${Ta}

void main() {
  float r = length(v_corner);
  if (r > 1.0) discard;                          // the quad's corners are not the glow

  // ── The disc ───────────────────────────────────────────────────────────────────────────────
  // Anti-aliased over a twelfth of its own radius. At eleven pixels across a hard cut is a
  // visible staircase, and one wide enough to hide it turns the sun into a smudge.
  float disc = 1.0 - smoothstep(u_disc_fraction * 0.94, u_disc_fraction * 1.06, r);

  // LIMB DARKENING. The sun is not a flat white circle: its edge is about 30% as bright as its
  // centre in visible light, because looking at the limb you see only the thin, cool top of the
  // photosphere while at the centre you see down into the hotter gas below. It is the reason a
  // filtered photograph of the sun has a soft grey rim, and at twenty pixels across it reads.
  //
  //   I(mu)/I(0) = 1 - u1*(1-mu) - u2*(1-mu)^2,  mu = cos(angle between sight line and normal)
  //
  // with the standard visible-light coefficients. On a disc of normalised radius rho, mu is just
  // sqrt(1 - rho^2), because the sphere is doing the geometry for us.
  //
  // The GAIN is what makes it look right rather than dirty. Radiance is computed first and clipped
  // afterwards, exactly as an over-exposed photograph does it, so the disc saturates to white
  // across most of its face and only the outer quarter of the radius visibly falls away. Turn the
  // gain up and the darkening disappears into the clip; turn it down and the sun goes grey. The
  // physics is in the law, not in the gain.
  float rho = clamp(r / max(u_disc_fraction, 1e-6), 0.0, 1.0);
  float limb = limbDarkening(rho);
  disc *= limb * u_disc_gain;

  // Bitten off by the planet, per pixel. The view ray for this fragment, then the oldest test
  // there is: does it meet the earth before it gets anywhere.
  if (disc > 0.0) {
    vec3 ray = normalize(u_forward + (u_right * v_corner.x + u_up * v_corner.y) * u_glow_angle);
    vec2 ground = sphereSpan(u_camera, ray, 1.0);
    if (ground.x <= ground.y && ground.y > 0.0) disc = 0.0;
  }

  // ── The glow ───────────────────────────────────────────────────────────────────────────────
  // In solar radii, so the shape is anchored to the limb rather than to the quad: rs = 1 is the
  // edge of the disc. Three terms, because glare has three scales and one gaussian has one.
  float rs = r / max(u_disc_fraction, 1e-6);
  float beyond = max(rs - 1.0, 0.0);
  // The weights and rates matter more than the choice of terms. Flatten the middle one and the sun
  // stops being a star and becomes a cotton-wool ball with a dot in it: half the core's brightness
  // was still there a solar radius out, which is a fat bright ring, not glare.
  float glow = 0.58 * exp(-beyond * 3.4)             // the flare clinging to the limb
             + 0.28 * exp(-beyond * 1.35)            // the aureole
             + 0.08 / (1.0 + 0.22 * rs * rs);        // the wide skirt, which is what sells the scale
  // The quad has to end somewhere, and against near-black space the eye finds that edge instantly:
  // a taper over the last fifth of the radius leaves a faint but perfectly visible disc outline
  // around the sun. Fading from a THIRD of the way out hides the boundary inside the falloff, at
  // the cost of thinning a part of the skirt that is already under a hundredth of the core.
  glow *= smoothstep(1.0, 0.32, r);
  // The halo is glare, so it spills over the planet's edge rather than stopping at it — but it
  // dims with however much of the sun is actually still showing.
  glow *= u_visible;

  float intensity = clamp(disc + glow * u_halo_strength, 0.0, 1.0) * u_brightness;
  if (intensity < 0.003) discard;

  // White at the core, amber everywhere else.
  //
  // The disc is far too bright for any eye or sensor to read as anything but white, and only the
  // wings keep a colour — so the question is how fast the white gives way, and the honest answer is
  // "almost at once". Blending on the glow's own value drags white out through the bright part of
  // the aureole and leaves the halo nearly neutral; a smoothstep with its foot near the limb's
  // brightness confines white to about a solar radius and hands the whole skirt to the amber.
  float toCore = clamp(disc + smoothstep(0.35, 0.95, glow), 0.0, 1.0);
  vec3 colour = mix(u_halo_colour, u_core_colour, toCore);

  // LIMB REDDENING, which is the same fact as the darkening seen in colour: the shallow gas at the
  // limb is cooler, so it is not merely dimmer but redder. Driven by the darkening term rather than
  // given a control of its own, because the two cannot disagree: one temperature gradient makes both.
  // (No backticks in here. This is a template literal, and one would end the shader.)
  colour = mix(colour, colour * vec3(1.0, 0.88, 0.7), (1.0 - limb) * step(rho, 1.0));

  // MapLibre blends custom layers ONE / ONE_MINUS_SRC_ALPHA, so colour goes out premultiplied.
  gl_FragColor = vec4(colour * intensity, intensity);
}`,Ma=({date:e=new Date,haloScale:a=ya,haloStrength:i=1,discGain:l=1.15,brightness:u=1,coreColour:f=[1,.985,.95],haloColour:o=[1,.65,.2]}={})=>{let p=null,t=null,y=null;const A=new Map;let x={date:e,haloScale:a,haloStrength:i,discGain:l,brightness:u,coreColour:f,haloColour:o};const g=T=>{const h=T.variantName;if(A.has(h))return A.get(h);const c=V(t,xa(T),Ra(),"tm-sun"),_={program:c,attribs:{corner:t.getAttribLocation(c,"a_corner")},uniforms:{centre:t.getUniformLocation(c,"a_centre"),elevationGlobe:t.getUniformLocation(c,"a_elevation_globe"),elevationMercator:t.getUniformLocation(c,"a_elevation_mercator"),size:t.getUniformLocation(c,"u_size"),camera:t.getUniformLocation(c,"u_camera"),forward:t.getUniformLocation(c,"u_forward"),right:t.getUniformLocation(c,"u_right"),up:t.getUniformLocation(c,"u_up"),glowAngle:t.getUniformLocation(c,"u_glow_angle"),discFraction:t.getUniformLocation(c,"u_disc_fraction"),discGain:t.getUniformLocation(c,"u_disc_gain"),visible:t.getUniformLocation(c,"u_visible"),brightness:t.getUniformLocation(c,"u_brightness"),haloStrength:t.getUniformLocation(c,"u_halo_strength"),coreColour:t.getUniformLocation(c,"u_core_colour"),haloColour:t.getUniformLocation(c,"u_halo_colour"),matrix:t.getUniformLocation(c,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(c,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(c,"u_projection_clipping_plane"),transition:t.getUniformLocation(c,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(c,"u_projection_fallback_matrix")}};return A.set(h,_),_};return{id:ut,type:"custom",renderingMode:"3d",onAdd(T,h){p=T,t=h;const c=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,c),t.bufferData(t.ARRAY_BUFFER,Aa,t.STATIC_DRAW);const _=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,_),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Ce,t.STATIC_DRAW),y={corner:c,index:_}},onRemove(){t&&(A.forEach(({program:T})=>t.deleteProgram(T)),A.clear(),y&&(t.deleteBuffer(y.corner),t.deleteBuffer(y.index),y=null),p=null,t=null)},render(T,h){if(!y||x.brightness<=0)return;const c=h&&h.shaderData,_=h&&h.defaultProjectionData;if(!c||!_)return;const{program:w,attribs:R,uniforms:n}=g(c);t.useProgram(w),n.matrix&&t.uniformMatrix4fv(n.matrix,!1,_.mainMatrix),n.tileMercatorCoords&&t.uniform4f(n.tileMercatorCoords,..._.tileMercatorCoords),n.clippingPlane&&t.uniform4f(n.clippingPlane,..._.clippingPlane),n.transition&&t.uniform1f(n.transition,_.projectionTransition),n.fallbackMatrix&&t.uniformMatrix4fv(n.fallbackMatrix,!1,_.fallbackMatrix);const r=J(p,j),C=ct(x.date),D=K(...Sa(he(x.date)),C-Pe),M=Ne([D[0]-r[0],D[1]-r[1],D[2]-r[2]]),O=Math.hypot(...r),s=Math.max(.25*(O-1),.002),U=[r[0]+M[0]*s,r[1]+M[1]*s,r[2]+M[2]*s],G=Math.hypot(...U),B=Math.asin(U[1]/G)*180/Math.PI,L=Math.atan2(U[2],U[0])*180/Math.PI,E=(G-1)*Pe,m=j.MercatorCoordinate.fromLngLat([L,B],0);n.centre&&t.uniform2f(n.centre,m.x,m.y),n.elevationGlobe&&t.uniform1f(n.elevationGlobe,E),n.elevationMercator&&t.uniform1f(n.elevationMercator,j.MercatorCoordinate.fromLngLat([L,B],E).z);const S=_a(x.date),d=S*x.haloScale,F=(h.fov||.6435)/2,P=p.getCanvas(),H=Math.tan(d)/Math.tan(F);n.size&&t.uniform2f(n.size,H*(P.height/P.width),H),n.glowAngle&&t.uniform1f(n.glowAngle,d),n.discFraction&&t.uniform1f(n.discFraction,1/x.haloScale),n.discGain&&t.uniform1f(n.discGain,x.discGain);const v=[-r[0]/O,-r[1]/O,-r[2]/O],N=Math.acos(te(ka(v,M),-1,1)),b=Math.asin(te(1/O,-1,1)),I=Ea(N,S,b),z=M,q=Ne(Oe([0,1,0],z)),k=Oe(z,q);n.forward&&t.uniform3f(n.forward,...z),n.right&&t.uniform3f(n.right,...q),n.up&&t.uniform3f(n.up,...k),n.camera&&t.uniform3f(n.camera,...r),n.visible&&t.uniform1f(n.visible,I),n.brightness&&t.uniform1f(n.brightness,x.brightness),n.haloStrength&&t.uniform1f(n.haloStrength,x.haloStrength),n.coreColour&&t.uniform3f(n.coreColour,...x.coreColour),n.haloColour&&t.uniform3f(n.haloColour,...x.haloColour),t.bindBuffer(t.ARRAY_BUFFER,y.corner),t.enableVertexAttribArray(R.corner),t.vertexAttribPointer(R.corner,2,t.FLOAT,!1,0,0),t.disable(t.DEPTH_TEST),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,y.index),t.drawElements(t.TRIANGLES,Ce.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(T={}){x={...x,...T},p?.triggerRepaint()},getOptions:()=>({...x})}},te=(e,a,i)=>Math.min(i,Math.max(a,e)),ka=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],Ne=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},Oe=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],Sa=([e,a,i])=>[Math.atan2(i,e)*180/Math.PI,Math.asin(te(a,-1,1))*180/Math.PI],La=ut,dt="tm-moon",ce=63710088e-1,Ua=1737400,Q=Math.PI/180,Da=Date.UTC(2e3,0,1,12),Pa=Date.UTC(1999,11,31,0),$=e=>Math.sin(e*Q),X=e=>Math.cos(e*Q),ft=(e=new Date)=>{const a=(e.getTime()-Da)/864e5,i=(e.getTime()-Pa)/864e5,l=125.1228-.0529538083*i,u=5.1454,f=318.0634+.1643573223*i,o=60.2666,p=.0549,t=115.3654+13.0649929509*i;let y=t+p*180/Math.PI*$(t)*(1+p*X(t));for(let d=0;d<3;d++)y-=(y-p*180/Math.PI*$(y)-t)/(1-p*X(y));const A=o*(X(y)-p),x=o*(Math.sqrt(1-p*p)*$(y)),g=Math.atan2(x,A)/Q,T=Math.sqrt(A*A+x*x);let h=T*(X(l)*X(g+f)-$(l)*$(g+f)*X(u)),c=T*($(l)*X(g+f)+X(l)*$(g+f)*X(u)),_=T*($(g+f)*$(u));const w=356.047+.9856002585*i,R=282.9404+470935e-10*i+w,n=l+f+t,r=n-R,C=n-l;let D=Math.atan2(c,h)/Q,M=Math.atan2(_,Math.hypot(h,c))/Q;D+=-1.274*$(t-2*r)+.658*$(2*r)-.186*$(w),M+=-.173*$(C-2*r);const O=(T-.58*X(t-2*r)-.46*X(2*r))*ce,s=23.4393-3563e-10*a,U=X(D)*X(M),G=$(D)*X(M)*X(s)-$(M)*$(s),B=$(D)*X(M)*$(s)+$(M)*X(s),L=Math.atan2(G,U)/Q,E=Math.atan2(B,Math.hypot(U,G))/Q,m=(18.697374558+24.06570982441908*a)%24;let S=L-m*15;return S=(S%360+540)%360-180,{lng:S,lat:E,distance:O}},mt=(e=new Date)=>{const{lng:a,lat:i,distance:l}=ft(e);return K(a,i,l-ce)},Ia=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Be=new Uint16Array([0,1,2,1,3,2]),Fa=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_corner;
uniform vec2 a_centre;          // sub-lunar point in mercator 0..1
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
uniform vec2 u_size;            // half-size in clip units, x and y
varying vec2 v_corner;
void main() {
  v_corner = a_corner;
  #ifdef GLOBE
    vec4 centre = projectTileFor3D(a_centre, a_elevation_globe);
  #else
    vec4 centre = projectTileFor3D(a_centre, a_elevation_mercator);
  #endif
  // Scale by w so the disc keeps its angular size through the perspective divide.
  gl_Position = centre + vec4(a_corner * u_size * centre.w, 0.0, 0.0);
}`,Ca=()=>`precision highp float;
varying vec2 v_corner;
uniform sampler2D u_albedo;
uniform vec3 u_sun;             // direction TO the sun, planet space
uniform vec3 u_right;           // the billboard's basis, in planet space
uniform vec3 u_up;
uniform vec3 u_forward;         // from the camera toward the moon
uniform float u_brightness;
uniform float u_hasAlbedo;
uniform float u_discEdge;       // where the moon's limb falls inside the quad, 0..1
uniform float u_glow;           // halo strength; 0 leaves the disc exactly as it was
uniform float u_glowExtent;     // how far the halo reaches, in disc radii
${me}

void main() {
  // THE QUAD IS BIGGER THAN THE MOON when there is a halo to draw, so v_corner is normalised
  // against the limb rather than against the quad. u_discEdge is 1.0 with the glow off, which
  // makes this identical to what it was.
  vec2 corner = v_corner / u_discEdge;
  float r2 = dot(corner, corner);

  if (r2 > 1.0) {
    // ── The halo ──────────────────────────────────────────────────────────────────────────
    //
    // The moon is half a degree wide and there is no honest way to make it bigger, so this makes
    // the pixels it does have carry further. It is not a lie about the moon's SIZE: the disc below
    // keeps its true angular radius and the glow is what a bright object does to an eye, a lens
    // and a camera sensor alike.
    //
    // Additive, via premultiplied alpha — vec4(colour, 0.0) adds without covering, so the halo
    // brightens the starfield behind it instead of stamping a grey ring over it.
    float t = clamp((sqrt(r2) - 1.0) / max(u_glowExtent, 1e-4), 0.0, 1.0);
    float falloff = pow(1.0 - t, 3.0);
    gl_FragColor = vec4(vec3(0.62, 0.62, 0.60) * u_glow * falloff * u_brightness, 0.0);
    return;
  }

  // Rebuild the sphere the quad stands in for: z out of the disc toward the viewer.
  float z = sqrt(1.0 - r2);
  vec3 normal = normalize(u_right * corner.x + u_up * corner.y - u_forward * z);

  // Phase. Not drawn — a consequence of lighting a sphere from where the sun actually is.
  float lambert = max(dot(normal, u_sun), 0.0);
  // A little wrap: the lunar terminator is softened by the roughness of the surface, and a hard
  // cosine cut makes a crescent look like a paper cutout.
  float light = pow(lambert, 0.72);

  // The moon keeps one face toward earth, so a fixed lookup is the correct one.
  vec3 albedo = u_hasAlbedo > 0.0
    ? texture2D(u_albedo, equirectUV(normal, 0.0)).rgb
    : vec3(0.62, 0.60, 0.57);

  // Earthshine: the dark limb is not black, it is lit by a nearly-full earth overhead.
  vec3 colour = albedo * (light + 0.045);
  float edge = smoothstep(1.0, 0.985, r2);     // one pixel of anti-aliasing on the limb
  float alpha = edge;
  gl_FragColor = vec4(colour * u_brightness * alpha, alpha);
}`,Na=({date:e=new Date,sun:a=[1,0,0],albedoUrl:i=null,sizeScale:l=1,brightness:u=1,glow:f=.4,glowExtent:o=1.8}={})=>{let p=null,t=null,y=null,A=null,x=!1;const g=new Map;let T={date:e,sun:a,albedoUrl:i,sizeScale:l,brightness:u,glow:f,glowExtent:o};const h=_=>{const w=_.variantName;if(g.has(w))return g.get(w);const R=V(t,Fa(_),Ca(),"tm-moon"),n={program:R,attribs:{corner:t.getAttribLocation(R,"a_corner")},uniforms:{centre:t.getUniformLocation(R,"a_centre"),elevationGlobe:t.getUniformLocation(R,"a_elevation_globe"),elevationMercator:t.getUniformLocation(R,"a_elevation_mercator"),size:t.getUniformLocation(R,"u_size"),albedo:t.getUniformLocation(R,"u_albedo"),hasAlbedo:t.getUniformLocation(R,"u_hasAlbedo"),sun:t.getUniformLocation(R,"u_sun"),right:t.getUniformLocation(R,"u_right"),up:t.getUniformLocation(R,"u_up"),forward:t.getUniformLocation(R,"u_forward"),brightness:t.getUniformLocation(R,"u_brightness"),discEdge:t.getUniformLocation(R,"u_discEdge"),glow:t.getUniformLocation(R,"u_glow"),glowExtent:t.getUniformLocation(R,"u_glowExtent"),matrix:t.getUniformLocation(R,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(R,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(R,"u_projection_clipping_plane"),transition:t.getUniformLocation(R,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(R,"u_projection_fallback_matrix")}};return g.set(w,n),n},c=_=>{const w=new Image;w.crossOrigin="anonymous",w.onload=()=>{t&&(A=A||t.createTexture(),t.bindTexture(t.TEXTURE_2D,A),t.texImage2D(t.TEXTURE_2D,0,t.RGBA,t.RGBA,t.UNSIGNED_BYTE,w),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_S,t.REPEAT),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_T,t.CLAMP_TO_EDGE),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MIN_FILTER,t.LINEAR),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MAG_FILTER,t.LINEAR),x=!0,p?.triggerRepaint())},w.src=_};return{id:dt,type:"custom",renderingMode:"3d",onAdd(_,w){p=_,t=w;const R=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,R),t.bufferData(t.ARRAY_BUFFER,Ia,t.STATIC_DRAW);const n=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,n),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Be,t.STATIC_DRAW),y={corner:R,index:n},T.albedoUrl&&c(T.albedoUrl)},onRemove(){t&&(g.forEach(({program:_})=>t.deleteProgram(_)),g.clear(),A&&(t.deleteTexture(A),A=null,x=!1),y&&(t.deleteBuffer(y.corner),t.deleteBuffer(y.index),y=null),p=null,t=null)},render(_,w){if(!y||T.brightness<=0)return;const R=w&&w.shaderData,n=w&&w.defaultProjectionData;if(!R||!n)return;const{lng:r,lat:C,distance:D}=ft(T.date),{program:M,attribs:O,uniforms:s}=h(R);t.useProgram(M),s.matrix&&t.uniformMatrix4fv(s.matrix,!1,n.mainMatrix),s.tileMercatorCoords&&t.uniform4f(s.tileMercatorCoords,...n.tileMercatorCoords),s.clippingPlane&&t.uniform4f(s.clippingPlane,...n.clippingPlane),s.transition&&t.uniform1f(s.transition,n.projectionTransition),s.fallbackMatrix&&t.uniformMatrix4fv(s.fallbackMatrix,!1,n.fallbackMatrix);const U=J(p,j),G=K(r,C,D-ce),B=Ge([G[0]-U[0],G[1]-U[1],G[2]-U[2]]),E=Math.hypot(...U)+1.2,m=[U[0]+B[0]*E,U[1]+B[1]*E,U[2]+B[2]*E],S=Math.hypot(...m),d=Math.asin(m[1]/S)*180/Math.PI,F=Math.atan2(m[2],m[0])*180/Math.PI,P=(S-1)*ce,H=j.MercatorCoordinate.fromLngLat([F,d],0);s.centre&&t.uniform2f(s.centre,H.x,H.y),s.elevationGlobe&&t.uniform1f(s.elevationGlobe,P),s.elevationMercator&&t.uniform1f(s.elevationMercator,j.MercatorCoordinate.fromLngLat([F,d],P).z);const v=Math.atan(Ua*T.sizeScale/D),N=(w.fov||.6435)/2,b=p.getCanvas(),I=Math.tan(v)/Math.tan(N),q=1+(T.glow>0?Math.max(0,T.glowExtent):0),k=I*q;s.size&&t.uniform2f(s.size,k*(b.height/b.width),k),s.discEdge&&t.uniform1f(s.discEdge,1/q);const ee=B,ae=Ge(He([0,1,0],ee)),pe=He(ee,ae);s.forward&&t.uniform3f(s.forward,...ee),s.right&&t.uniform3f(s.right,...ae),s.up&&t.uniform3f(s.up,...pe),s.sun&&t.uniform3f(s.sun,...T.sun),s.brightness&&t.uniform1f(s.brightness,T.brightness),s.glow&&t.uniform1f(s.glow,T.glow),s.glowExtent&&t.uniform1f(s.glowExtent,T.glowExtent),s.hasAlbedo&&t.uniform1f(s.hasAlbedo,x?1:0),x&&s.albedo&&(t.activeTexture(t.TEXTURE0),t.bindTexture(t.TEXTURE_2D,A),t.uniform1i(s.albedo,0)),t.bindBuffer(t.ARRAY_BUFFER,y.corner),t.enableVertexAttribArray(O.corner),t.vertexAttribPointer(O.corner,2,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,y.index),t.drawElements(t.TRIANGLES,Be.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(_={}){const w=T.albedoUrl;T={...T,..._},_.albedoUrl!==void 0&&_.albedoUrl!==w&&(x=!1,_.albedoUrl&&c(_.albedoUrl)),p?.triggerRepaint()},getOptions:()=>({...T}),get hasAlbedo(){return x}}},Ge=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},He=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],Oa=dt,Ba=6957e5,Ga=1737400,Ha=63710088e-1,za=Ga/Ha,$a=(e,a,i)=>Math.min(i,Math.max(a,e)),qa=(e=new Date)=>Math.asin(Ba/va(e)),Xa=.035,ja=(e=new Date,a=null,i=null)=>{const l=a||he(e),u=i||mt(e),f=Math.hypot(...u);return Math.acos($a((l[0]*u[0]+l[1]*u[1]+l[2]*u[2])/f,-1,1))<Xa},Ya=`
  float solarVisibleFraction(float sun, float moon, float separation) {
    if (separation >= sun + moon) return 1.0;
    if (separation <= moon - sun) return 0.0;
    float ratioSquared = (moon * moon) / (sun * sun);
    if (separation <= sun - moon) return 1.0 - ratioSquared;

    float x = (separation * separation + sun * sun - moon * moon) / (2.0 * separation);
    float ths = acos(clamp(x / sun, -1.0, 1.0));
    float thm = acos(clamp((separation - x) / moon, -1.0, 1.0));
    return (3.14159265359 - ths + 0.5 * sin(2.0 * ths)
            - thm * ratioSquared + 0.5 * ratioSquared * sin(2.0 * thm)) / 3.14159265359;
  }

  // moonPos is the moon's POSITION in earth radii, not a direction — the difference is the whole
  // reason totality is a narrow track rather than a hemisphere.
  float eclipseLight(vec3 up, vec3 sunDir, vec3 moonPos, float sunRadius, float moonRadiusEarths) {
    if (sunRadius <= 0.0) return 1.0;
    vec3 toMoon = moonPos - up;
    float distance = length(toMoon);
    float moonRadius = asin(clamp(moonRadiusEarths / distance, 0.0, 1.0));
    float separation = acos(clamp(dot(sunDir, toMoon / distance), -1.0, 1.0));
    return solarVisibleFraction(sunRadius, moonRadius, separation);
  }
`,pt="tm-daylight",oe={field:0,wind:1,relief:2,lights:3,patches:4},Wa=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${fe}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ka=()=>`#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform vec3 u_sun;            // direction TO the sun
uniform vec3 u_camera;         // camera in planet space, earth = unit sphere
uniform float u_globeness;     // 1 on the globe, 0 on the flat map
uniform float u_nightDarkness; // how black the unlit side goes
uniform vec3 u_nightColour;
uniform vec3 u_twilightColour;  // the warm edge, sunward of the line
uniform vec3 u_twilightCool;    // the blue hour, nightward of it
uniform float u_twilightStrength;
uniform sampler2D u_lights;    // NASA Black Marble, equirectangular
uniform float u_lightsAmount;  // 0 when the texture has not loaded
uniform sampler2D u_relief;    // baked terrain normals, equirectangular; see terrain-normals.js
uniform float u_reliefPower;   // 0 when there is no relief map, or the camera is too close for it
uniform float u_cloudShadow;   // how much of the light a full cloud takes away
uniform float u_shadowSoftness; // how wide the cloud-cover band that fades a shadow in is
uniform float u_cloudAltitude; // deck height in earth radii — sets how far a shadow is thrown
uniform vec3 u_moon;           // the moon's POSITION in earth radii, not its direction
uniform float u_sunRadius;     // the sun's angular radius; 0 on any date without an eclipse
${me}
${Ke}
${xe}
${et}
${tt}
${Pt}
${Ya}
// The same field, wind and clock the deck in clouds.js draws itself from.
${Ze}

void main() {
  vec3 normal = normalize(v_sphere);

  // The mesh is a closed sphere, so the far side covers the same pixels as the near side. On the
  // globe, drop it here rather than leaning on the depth buffer — see FACING_CAMERA_GLSL. On the
  // flat map there is no far side to drop.
  if (u_globeness > 0.5 && !facesCamera(normal, u_camera)) discard;

  vec3 sunDir = normalize(u_sun);

  // TWO DIFFERENT QUANTITIES, and conflating them is a bug that renders beautifully.
  //   sunlight  is where the sun is — pure geometry, shared with the deck so the two agree.
  //   day       is how much light actually lands here, after terrain and cloud have had their say.
  // Colour and the city-lights gate belong to the FIRST. Getting that wrong paints every cloud
  // shadow sunset-orange in the middle of the afternoon, because a shadow drops the light to about
  // half and half is exactly where the twilight band peaks. It looks like weather until you notice
  // the sun is overhead.
  float sunlight = daylightFraction(dot(normal, sunDir));
  float day = sunlight;

  // ── Relief ────────────────────────────────────────────────────────────────────────────────
  // Lee writes this as one multiply, day *= 1 + power*delta. That is the same thing as adding
  // day*power*delta, and splitting it that way is what lets the negative half become night and the
  // positive half become light. Both are scaled by the daylight already there, so nothing happens
  // on the night side, where there is no sun to rake.
  float lit = 0.0;
  if (u_reliefPower > 0.0) {
    vec3 tangentNormal = decodeTerrainNormal(texture(u_relief, equirectUV(normal, 0.0)).xyz);
    vec3 perturbed = normalize(equirectTangentFrame(normal) * tangentNormal);
    float change = day * (reliefLightFactor(normal, perturbed, sunDir, u_reliefPower) - 1.0);
    day = day + min(0.0, change);
    lit = max(0.0, change);
  }

  // ── Cloud shadow ──────────────────────────────────────────────────────────────────────────
  // Walk from this patch of ground toward the sun until the deck, and see what is up there. The
  // offset is what makes a shadow fall AWAY from the light rather than sitting under the cloud
  // like a decal — and it is why an afternoon deck lays its shadows out to the east.
  // Either source: the ground casts the shadow of whatever sky the deck is actually drawing.
  if (u_cloudShadow > 0.0 && (u_fieldAmount > 0.0 || u_patchAmount > 0.0)) {
    vec3 above = cloudShadowDirection(normal, sunDir, u_cloudAltitude);
    float cover = advectedField(equirectUV(above, u_drift), asin(clamp(above.y, -1.0, 1.0)));
    // The same threshold the deck opens its coverage with, so a shadow exists exactly where a
    // cloud does. No procedural detail: from orbit, a shadow thrown from the deck is a soft blob
    // and the filaments do not survive the trip down.
    // THE SHADOW'S EDGE, and it used to be the hardcoded pair (0.16, 0.62).
    //
    // A shadow is not drawn from a silhouette here; it is faded in across how much cloud is
    // overhead. So the WIDTH of that band is the edge: a narrow one steps from lit to shadowed
    // within a hair of cover and reads as a hard-edged stencil laid on the planet, which is what
    // Bart saw. A real cloud shadow has a penumbra several kilometres across, because the sun is
    // half a degree wide rather than a point.
    //
    // The band stays centred on 0.39 — the same midpoint the old pair had — so softening spreads
    // the edge without moving where the shadow sits or changing how much ground it covers.
    float halfBand = mix(0.05, 0.39, u_shadowSoftness);
    float shadow = smoothstep(0.39 - halfBand, 0.39 + halfBand, cover) * cloudShadowFade(normal, sunDir);
    day *= 1.0 - u_cloudShadow * shadow;
    lit *= 1.0 - shadow;
  }

  // ── Eclipse ───────────────────────────────────────────────────────────────────────────────
  // The moon covering some fraction of the sun's disc, worked out for THIS point rather than for
  // the planet: the moon is only sixty radii away, and that parallax is the whole reason totality
  // is a hundred-kilometre track instead of a hemisphere. Costs one comparison on the millions of
  // dates with no eclipse, because u_sunRadius is 0 on all of them.
  if (u_sunRadius > 0.0) {
    float remaining = eclipseLight(normal, sunDir, u_moon, u_sunRadius, ${za.toFixed(8)});
    day *= remaining;
    lit *= remaining;
  }

  day = clamp(day, 0.0, 1.0);
  float night = 1.0 - day;

  // Sunlit rock, added rather than blended: premultiplied alpha makes vec4(colour, 0.0) a pure
  // addition, which is the only way a darkening shell can put light back.
  vec3 emitted = lit * vec3(1.0, 0.97, 0.92);

  if (night < 0.004 && lit < 0.004) discard;   // full daylight, flat ground: leave the imagery alone

  // TWILIGHT IS TWO COLOURS ON OPPOSITE SIDES OF THE TERMINATOR, NOT ONE.
  //
  // Sunward of the line, the light still reaching the ground has crossed the most air and lost its
  // blue — that is the warm edge, and it is THIN. Nightward, the ground is already dark but the air
  // ABOVE it is still lit, and what comes back down has been Rayleigh-scattered, so it is BLUE.
  // That is the blue hour, and it is the half everyone forgets.
  //
  // Mixing the whole band to one saturated orange is what made it read as a stripe painted pole to
  // pole rather than as a sunset seen from orbit: there is no single orange anywhere in the real
  // thing, and the cool outer edge is what stops the warm one looking like a decal.
  //
  // It still follows the SUN's angle, so a cloud shadow at noon is grey rather than a private sunset.
  float band = 1.0 - abs(sunlight * 2.0 - 1.0);
  band = band * band * band;                          // narrower than the old square
  float warmSide = smoothstep(0.30, 0.70, sunlight);  // 1 sunward of the line, 0 nightward
  vec3 twilightTint = mix(u_twilightCool, u_twilightColour, warmSide);

  vec3 shade = mix(u_nightColour, twilightTint, band * u_twilightStrength);
  float alpha = night * u_nightDarkness;

  // City lights, but only where it is genuinely dark — they have no business showing at dusk, and
  // a passing cloud is not dusk.
  if (u_lightsAmount > 0.0) {
    float lights = texture(u_lights, equirectUV(normal, 0.0)).r;
    float visible = lights * smoothstep(0.25, 0.75, 1.0 - sunlight) * u_lightsAmount;
    // Added, not blended: lights EMIT. Blending them would make the cities darker than the sea.
    shade = shade + vec3(1.0, 0.86, 0.6) * visible * 1.6;
    alpha = min(1.0, alpha + visible * 0.5);
  }

  // Premultiplied, matching MapLibre's ONE / ONE_MINUS_SRC_ALPHA for custom layers.
  fragColour = vec4(shade * alpha + emitted, alpha);
}`,Va=({sun:e=[1,0,0],nightDarkness:a=.965,lightsUrl:i=null,lightsAmount:l=0,nightColour:u=[.02,.035,.07],twilightColour:f=[.62,.32,.16],twilightCool:o=[.1,.15,.28],twilightStrength:p=.55,reliefUrl:t=null,reliefWidth:y=8192,reliefPower:A=1.5,cloudShadow:x=.5,shadowSoftness:g=.75,eclipse:T=!0,date:h=null,fieldUrl:c=null,patchUrl:_=null,windUrl:w=null,windAmount:R=1,windScale:n=.06,windRate:r=.05,driftRate:C=4e-4,animate:D=!0}={})=>{const M=de();let O=null,s=null,U=null,G=null,B=null,L=null,E=null,m=null;const S=new Map;let d={sun:e,nightDarkness:a,lightsUrl:i,lightsAmount:l,nightColour:u,twilightColour:f,twilightCool:o,twilightStrength:p,reliefUrl:t,reliefWidth:y,reliefPower:A,cloudShadow:x,eclipse:T,date:h,shadowSoftness:g,fieldUrl:c,patchUrl:_,windUrl:w,windAmount:R,windScale:n,windRate:r,driftRate:C,animate:D};const F=v=>{const N=v.variantName;if(S.has(N))return S.get(N);const b=V(s,Wa(v),Ka(),"tm-daylight"),I={program:b,attribs:{pos:s.getAttribLocation(b,"a_pos"),sphere:s.getAttribLocation(b,"a_sphere")},uniforms:{elevationGlobe:s.getUniformLocation(b,"a_elevation_globe"),elevationMercator:s.getUniformLocation(b,"a_elevation_mercator"),sun:s.getUniformLocation(b,"u_sun"),camera:s.getUniformLocation(b,"u_camera"),globeness:s.getUniformLocation(b,"u_globeness"),nightDarkness:s.getUniformLocation(b,"u_nightDarkness"),nightColour:s.getUniformLocation(b,"u_nightColour"),twilightColour:s.getUniformLocation(b,"u_twilightColour"),twilightCool:s.getUniformLocation(b,"u_twilightCool"),twilightStrength:s.getUniformLocation(b,"u_twilightStrength"),lights:s.getUniformLocation(b,"u_lights"),lightsAmount:s.getUniformLocation(b,"u_lightsAmount"),relief:s.getUniformLocation(b,"u_relief"),reliefPower:s.getUniformLocation(b,"u_reliefPower"),cloudShadow:s.getUniformLocation(b,"u_cloudShadow"),shadowSoftness:s.getUniformLocation(b,"u_shadowSoftness"),cloudAltitude:s.getUniformLocation(b,"u_cloudAltitude"),moon:s.getUniformLocation(b,"u_moon"),sunRadius:s.getUniformLocation(b,"u_sunRadius"),...Object.fromEntries(Qe.map(z=>[z,s.getUniformLocation(b,`u_${z}`)])),matrix:s.getUniformLocation(b,"u_projection_matrix"),tileMercatorCoords:s.getUniformLocation(b,"u_projection_tile_mercator_coords"),clippingPlane:s.getUniformLocation(b,"u_projection_clipping_plane"),transition:s.getUniformLocation(b,"u_projection_transition"),fallbackMatrix:s.getUniformLocation(b,"u_projection_fallback_matrix")}};return S.set(N,I),I},P=()=>O?.triggerRepaint(),H=(v,N,b,I)=>{!N?.ready||!v[b]||(s.activeTexture(s.TEXTURE0+I),s.bindTexture(s.TEXTURE_2D,N.texture),s.uniform1i(v[b],I))};return{id:pt,type:"custom",renderingMode:"3d",onAdd(v,N){O=v,s=N;const b=(I,z)=>{const q=s.createBuffer();return s.bindBuffer(I,q),s.bufferData(I,z,s.STATIC_DRAW),q};U={pos:b(s.ARRAY_BUFFER,M.positions),sphere:b(s.ARRAY_BUFFER,M.spheres),index:b(s.ELEMENT_ARRAY_BUFFER,M.indices)},d.lightsUrl&&(G=W(s,d.lightsUrl,P)),d.reliefUrl&&(B=W(s,d.reliefUrl,P)),d.fieldUrl&&(L=W(s,d.fieldUrl,P)),d.windUrl&&(E=W(s,d.windUrl,P)),d.patchUrl&&(m=W(s,d.patchUrl,P))},onRemove(){s&&(S.forEach(({program:v})=>s.deleteProgram(v)),S.clear(),G?.release(),G=null,B?.release(),B=null,L?.release(),L=null,E?.release(),E=null,m?.release(),m=null,U&&(s.deleteBuffer(U.pos),s.deleteBuffer(U.sphere),s.deleteBuffer(U.index),U=null),O=null,s=null)},render(v,N){if(!U||d.nightDarkness<=0)return;const b=N&&N.shaderData,I=N&&N.defaultProjectionData;if(!b||!I)return;const{program:z,attribs:q,uniforms:k}=F(b);s.useProgram(z),k.matrix&&s.uniformMatrix4fv(k.matrix,!1,I.mainMatrix),k.tileMercatorCoords&&s.uniform4f(k.tileMercatorCoords,...I.tileMercatorCoords),k.clippingPlane&&s.uniform4f(k.clippingPlane,...I.clippingPlane),k.transition&&s.uniform1f(k.transition,I.projectionTransition),k.fallbackMatrix&&s.uniformMatrix4fv(k.fallbackMatrix,!1,I.fallbackMatrix);const ee=O.getCenter().lat,ae=0;k.elevationGlobe&&s.uniform1f(k.elevationGlobe,ae),k.elevationMercator&&s.uniform1f(k.elevationMercator,j.MercatorCoordinate.fromLngLat([0,ee],ae).z),k.sun&&s.uniform3f(k.sun,...d.sun),k.camera&&s.uniform3f(k.camera,...J(O,j)),k.globeness&&s.uniform1f(k.globeness,I.projectionTransition),k.nightDarkness&&s.uniform1f(k.nightDarkness,d.nightDarkness),k.nightColour&&s.uniform3f(k.nightColour,...d.nightColour),k.twilightColour&&s.uniform3f(k.twilightColour,...d.twilightColour),k.twilightCool&&s.uniform3f(k.twilightCool,...d.twilightCool),k.twilightStrength&&s.uniform1f(k.twilightStrength,d.twilightStrength),k.lightsAmount&&s.uniform1f(k.lightsAmount,G?.ready?d.lightsAmount:0);const pe=B?.ready?Nt(O.getZoom(),ee,d.reliefWidth).strength:0;k.reliefPower&&s.uniform1f(k.reliefPower,d.reliefPower*pe),k.cloudShadow&&s.uniform1f(k.cloudShadow,d.cloudShadow),k.shadowSoftness&&s.uniform1f(k.shadowSoftness,d.shadowSoftness),k.cloudAltitude&&s.uniform1f(k.cloudAltitude,se/ue);const re=d.eclipse?d.date:null,ge=re&&ja(re)?mt(re):null;k.sunRadius&&s.uniform1f(k.sunRadius,ge?qa(re):0),ge&&k.moon&&s.uniform3f(k.moon,...ge),Je(s,k,d,{seconds:performance.now()*.001,field:L,wind:E,patches:m},oe.field,oe.wind,oe.patches),H(k,B,"relief",oe.relief),H(k,G,"lights",oe.lights),s.bindBuffer(s.ARRAY_BUFFER,U.pos),s.enableVertexAttribArray(q.pos),s.vertexAttribPointer(q.pos,2,s.FLOAT,!1,0,0),s.bindBuffer(s.ARRAY_BUFFER,U.sphere),s.enableVertexAttribArray(q.sphere),s.vertexAttribPointer(q.sphere,3,s.FLOAT,!1,0,0),s.disable(s.DEPTH_TEST),s.depthMask(!1),s.bindBuffer(s.ELEMENT_ARRAY_BUFFER,U.index),s.drawElements(s.TRIANGLES,M.indices.length,s.UNSIGNED_SHORT,0),s.enable(s.DEPTH_TEST),s.depthMask(!0),d.animate&&(L?.ready||m?.ready)&&d.cloudShadow>0&&O.triggerRepaint()},setOptions(v={}){const N=d;if(d={...d,...v},!s){O?.triggerRepaint();return}const b=(I,z)=>v[z]===void 0||v[z]===N[z]?I:(I?.release(),d[z]?W(s,d[z],P):null);G=b(G,"lightsUrl"),B=b(B,"reliefUrl"),L=b(L,"fieldUrl"),E=b(E,"windUrl"),m=b(m,"patchUrl"),O?.triggerRepaint()},getOptions:()=>({...d}),get hasLights(){return!!G?.ready},get hasRelief(){return!!B?.ready}}},Za=pt,gt="tm-atmosphere",Qa=63710088e-1,ve=2e5,Ja=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${fe}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,eo=()=>`precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space (earth = unit sphere)
uniform vec3 u_sun;         // direction TO the sun
uniform float u_top;        // top of the atmosphere, in earth radii
uniform float u_strength;
uniform vec3 u_dayColour;
uniform vec3 u_duskColour;

const int SAMPLES = 6;

${We}

/**
 * Is this parcel of air in sunlight, or is the earth in the way?
 *
 * THE WHOLE POINT. Air only scatters light that reaches it, so the band must be gated on whether
 * the air itself is lit, NOT on which way it faces. This used to be a smoothstep from -0.35 to
 * 0.25 over dot(normalize(pos), u_sun), an ANGULAR test, and that is what ringed the planet: at
 * the terminator it returns 0.62, and it keeps returning something twenty degrees past it, so the
 * unlit limb was handed a fifth of full brightness and drew a rim all the way round. In the NASA
 * photograph the night limb is black — not dim, absent — and the reason is geometric, not
 * angular. Air behind the earth is in its shadow.
 *
 * NO BACKTICKS IN THIS COMMENT. It lives inside the fragment shader's template literal, so one
 * would end the string and the file would fail to parse a long way from here.
 *
 * So the test is the shadow itself: is the earth between this air and the sun.
 *
 * THE TEST IS TAKEN AT THE GROUND BENEATH THE SAMPLE, NOT AT THE SAMPLE. That looks like a
 * needless approximation and it is the entire difference between this working and not. The drawn
 * shell is EXAGGERATED — 200 km, about 3% of the radius, because the real 100 km is a couple of
 * pixels at globe zoom. Air at a made-up altitude clears the shadow cylinder at a made-up angle,
 * so testing the sample's own position lets the band survive a long way onto the dark side. That
 * was measured, not feared: the first version of this function tested the sample directly and made
 * the 90-120° sector BRIGHTER than the angular gate it replaced, 50.6 to 83.4. It fixed nothing and
 * would have shipped looking like a fix.
 *
 * Projecting to the unit sphere first asks the question the exaggeration cannot distort: is the
 * ground under this column in daylight. That is the terminator in the photograph.
 *
 * The window is deliberately tight — a sliver of twilight rather than an arc, dying within about
 * eight degrees of the terminator. A wider one is more physical and is exactly the halo being
 * removed; Bart's reference is a night side with no glow on the dark limb AT ALL.
 */
float sunlitFraction(vec3 pos) {
  vec3 ground = normalize(pos);                            // the surface below this parcel of air
  float alongSun = dot(ground, u_sun);
  if (alongSun > 0.0) return 1.0;                          // daylit hemisphere
  float axisDistance = length(ground - u_sun * alongSun);  // 1.0 exactly at the terminator
  return smoothstep(0.995, 1.0, axisDistance);
}

void main() {
  vec3 shell = normalize(v_sphere) * u_top;
  vec3 toCamera = u_camera - shell;
  // The mesh is a whole sphere, so the far hemisphere covers the same pixels as the near one and
  // would double the brightness. Keep the half facing the camera.
  if (dot(normalize(toCamera), normalize(shell)) < 0.0 && length(u_camera) > u_top) discard;

  vec3 ray = normalize(shell - u_camera);
  vec2 air = sphereSpan(u_camera, ray, u_top);
  if (air.x > air.y) discard;

  float near = max(air.x, 0.0);
  float far = air.y;
  // Air in FRONT of the ground only: past the surface the ray is underground, and counting it
  // would ring the planet in haze from the inside.
  vec2 ground = sphereSpan(u_camera, ray, 1.0);
  bool hitsGround = ground.x <= ground.y && ground.y > 0.0;
  if (hitsGround) far = min(far, max(ground.x, 0.0));
  if (far <= near) discard;

  // Walk the ray, weighting each sample by air density at that height. Density halves every ~5 km,
  // which on this scale is a very fast falloff — hence brightest just above the horizon.
  float stepLen = (far - near) / float(SAMPLES);
  float density = 0.0;
  float lit = 0.0;
  for (int i = 0; i < SAMPLES; i++) {
    vec3 pos = u_camera + ray * (near + stepLen * (float(i) + 0.5));
    float height = (length(pos) - 1.0) / (u_top - 1.0);       // 0 at the ground, 1 at the top
    float d = exp(-max(height, 0.0) * 4.5) * stepLen;
    density += d;
    lit += d * sunlitFraction(pos);
  }
  if (density <= 0.0) discard;
  lit /= density;

  // Sunset colour at the terminator: the low sun's light has crossed enough air to lose its blue,
  // which is why the band goes orange exactly where day meets night.
  float grazing = 1.0 - abs(dot(normalize(shell), normalize(u_camera - shell)));
  vec3 colour = mix(u_duskColour, u_dayColour, smoothstep(0.15, 0.65, lit));

  float alpha = (1.0 - exp(-density * 150.0 * u_strength)) * lit;
  // Face-on the ray crosses the shell almost perpendicular — a few hundred kilometres of thin air,
  // which from orbit is very nearly clear. At the limb it skims tangentially through the whole
  // depth of the atmosphere. Squaring the grazing term puts almost all the brightness in the band,
  // which is where a photograph puts it; a linear falloff leaves the disc under a blue wash and
  // buries the imagery it is supposed to sit on.
  alpha *= 0.04 + 0.96 * pow(grazing, 2.5);
  if (alpha < 0.002) discard;
  gl_FragColor = vec4(colour * alpha, alpha);
}`,to=({strength:e=1,sun:a=[.4,.5,.75],dayColour:i=[.32,.55,1],duskColour:l=[1,.45,.18]}={})=>{const u=de();let f=null,o=null,p=null;const t=new Map;let y={strength:e,sun:a,dayColour:i,duskColour:l};const A=x=>{const g=x.variantName;if(t.has(g))return t.get(g);const T=V(o,Ja(x),eo(),"tm-atmosphere"),h={program:T,attribs:{pos:o.getAttribLocation(T,"a_pos"),sphere:o.getAttribLocation(T,"a_sphere")},uniforms:{elevationGlobe:o.getUniformLocation(T,"a_elevation_globe"),elevationMercator:o.getUniformLocation(T,"a_elevation_mercator"),camera:o.getUniformLocation(T,"u_camera"),sun:o.getUniformLocation(T,"u_sun"),top:o.getUniformLocation(T,"u_top"),strength:o.getUniformLocation(T,"u_strength"),dayColour:o.getUniformLocation(T,"u_dayColour"),duskColour:o.getUniformLocation(T,"u_duskColour"),matrix:o.getUniformLocation(T,"u_projection_matrix"),tileMercatorCoords:o.getUniformLocation(T,"u_projection_tile_mercator_coords"),clippingPlane:o.getUniformLocation(T,"u_projection_clipping_plane"),transition:o.getUniformLocation(T,"u_projection_transition"),fallbackMatrix:o.getUniformLocation(T,"u_projection_fallback_matrix")}};return t.set(g,h),h};return{id:gt,type:"custom",renderingMode:"3d",onAdd(x,g){f=x,o=g;const T=(h,c)=>{const _=o.createBuffer();return o.bindBuffer(h,_),o.bufferData(h,c,o.STATIC_DRAW),_};p={pos:T(o.ARRAY_BUFFER,u.positions),sphere:T(o.ARRAY_BUFFER,u.spheres),index:T(o.ELEMENT_ARRAY_BUFFER,u.indices)}},onRemove(){o&&(t.forEach(({program:x})=>o.deleteProgram(x)),t.clear(),p&&(o.deleteBuffer(p.pos),o.deleteBuffer(p.sphere),o.deleteBuffer(p.index),p=null),f=null,o=null)},render(x,g){if(!p||y.strength<=0)return;const T=g&&g.shaderData,h=g&&g.defaultProjectionData;if(!T||!h)return;const{program:c,attribs:_,uniforms:w}=A(T);o.useProgram(c),w.matrix&&o.uniformMatrix4fv(w.matrix,!1,h.mainMatrix),w.tileMercatorCoords&&o.uniform4f(w.tileMercatorCoords,...h.tileMercatorCoords),w.clippingPlane&&o.uniform4f(w.clippingPlane,...h.clippingPlane),w.transition&&o.uniform1f(w.transition,h.projectionTransition),w.fallbackMatrix&&o.uniformMatrix4fv(w.fallbackMatrix,!1,h.fallbackMatrix);const R=f.getCenter().lat;w.elevationGlobe&&o.uniform1f(w.elevationGlobe,ve),w.elevationMercator&&o.uniform1f(w.elevationMercator,j.MercatorCoordinate.fromLngLat([0,R],ve).z);const n=J(f,j);w.camera&&o.uniform3f(w.camera,n[0],n[1],n[2]),w.sun&&o.uniform3f(w.sun,...y.sun),w.top&&o.uniform1f(w.top,1+ve/Qa),w.strength&&o.uniform1f(w.strength,y.strength),w.dayColour&&o.uniform3f(w.dayColour,...y.dayColour),w.duskColour&&o.uniform3f(w.duskColour,...y.duskColour),o.bindBuffer(o.ARRAY_BUFFER,p.pos),o.enableVertexAttribArray(_.pos),o.vertexAttribPointer(_.pos,2,o.FLOAT,!1,0,0),o.bindBuffer(o.ARRAY_BUFFER,p.sphere),o.enableVertexAttribArray(_.sphere),o.vertexAttribPointer(_.sphere,3,o.FLOAT,!1,0,0),o.enable(o.DEPTH_TEST),o.depthFunc(o.LEQUAL),o.depthMask(!1),o.bindBuffer(o.ELEMENT_ARRAY_BUFFER,p.index),o.drawElements(o.TRIANGLES,u.indices.length,o.UNSIGNED_SHORT,0),o.depthMask(!0)},setOptions(x={}){y={...y,...x},f?.triggerRepaint()},getOptions:()=>({...y})}},ao=gt,oo=32,wt=[{width:8192,height:4096,url:"/timemap/ocean-sdf-8192.webp"},{width:4096,height:2048,url:"/timemap/ocean-sdf-4096.webp"}],ro=`
  float oceanDistanceKm(float stored, float rangeKm) {
    return (stored * 2.0 - 1.0) * rangeKm;
  }
`,_t="tm-ocean",io=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${fe}
void main() {
  v_sphere = a_sphere;
  // Sea level: the glint belongs on the water, not above it.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,no=()=>`precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space, earth = unit sphere
uniform vec3 u_sun;         // direction TO the sun
uniform float u_globeness;  // 1 on the globe, 0 on the flat map
uniform sampler2D u_field;  // signed distance to the coast, equirectangular
uniform float u_rangeKm;    // kilometres at which that field saturates
uniform float u_shoreKm;    // half-width of the shoreline transition
uniform float u_shelfKm;    // how far out the shallow-water colour reaches
uniform float u_strength;   // glint intensity
uniform float u_roughness;  // 0 glassy, 1 whipped up — the sea's AVERAGE state
uniform float u_windPatch;  // how far the local sea state strays from that average
uniform float u_windScale;  // cycles of weather per unit sphere
uniform vec3 u_scatter;     // the water body's own colour — what deep water converges to
uniform vec3 u_bottom;      // sea-floor albedo, seen only where the water is shallow enough
uniform vec3 u_absorption;  // how fast each channel is absorbed, per metre of water
uniform float u_shelfDepthM; // depth the shelf proxy reaches at its outer edge
uniform vec3 u_sky;         // what the water mirrors at a glancing angle
uniform float u_water;      // how strongly the water colour is painted at all
uniform float u_fade;       // 1 from orbit, 0 close up where real imagery takes over
uniform float u_opacity;    // master
// Sangil Lee's reflectRatio remap, water -> 0.3 * water + 0.1, requested for evaluation. It stops a
// BINARY mask reading as a cut-out by giving land a little sheen and capping the sea below a full
// mirror. Measured here at 1: the Sahara gains 11.1 blue while losing 7.9 red, and the Taklamakan
// darkens by 12.8 luma — ocean colour on desert hundreds of kilometres from any coast, because a
// floor of 0.1 means no fragment ever discards. It softens an edge this layer no longer has, the
// distance field having put the coastline inside the texel. 0 by default; turn it up to see it.
uniform float u_edgeRemap;
${me}
${Ye}
${Ke}
${xe}
${ro}

/**
 * The local sea state — how rough the water is HERE, rather than everywhere at once.
 *
 * A single roughness makes the glitter path a smooth analytic blob, and that is the tell that it
 * was computed rather than photographed. In a real orbital picture the glint is mottled: streaks
 * and calm patches inside the bright area, where wind and slicks have left the surface rougher or
 * smoother than its average.
 *
 * The perturbation is on ROUGHNESS, not on the normal. Displacing the normal at wave scale is the
 * usual trick and it is meaningless here: one screen pixel covers twenty to forty kilometres from
 * orbit, so wave facets are millions to a pixel and any wave-scale noise aliases into static. What
 * genuinely survives at this scale is the weather, which varies over tens to hundreds of
 * kilometres — low frequency, so it neither aliases nor needs a screen-space derivative to tame.
 */
float seaState(vec3 p) {
  float weather = noise(p * u_windScale) * 0.65 + noise(p * u_windScale * 2.7) * 0.35;
  return clamp(u_roughness + u_windPatch * (weather - 0.5) * 2.0, 0.04, 1.0);
}

// ── The two source seams ──────────────────────────────────────────────────────────────────────
// Everything below this pair is lighting and knows nothing about where the data came from. These
// two functions are the ONLY places that touch a texture, so moving from one baked equirectangular
// field to streamed z/x/y tiles is a change to these and to nothing else. Keep it that way: a
// texture fetch that creeps into the lighting is a second place the source has to be swapped, and
// it will be found the hard way.

/** Kilometres to the nearest coast, negative inland. */
float coastDistanceKm(vec3 unitPos) {
  return oceanDistanceKm(texture2D(u_field, equirectUV(unitPos, 0.0)).r, u_rangeKm);
}

/**
 * How deep the water is, in metres.
 *
 * Today this is a shelf profile inferred from distance to the coast — a PROXY, and a defensible one
 * at this scale, because a real shelf does broadly track the coastline. Squared so it deepens slowly
 * inshore and then falls away, which is the shape of a real shelf and its break.
 *
 * THE SOURCE SWAPS HERE AND NOWHERE ELSE. The terrarium DEM this map already streams carries
 * bathymetry as negative elevation — measured: −32 m in the North Sea, −21 m on Dogger Bank,
 * −3,415 m mid-Atlantic — so real depth costs no new data pipeline, only the half of that tile the
 * relief pass throws away. When it arrives, this function body is the whole change.
 *
 * WHERE ZERO IS, IF THE DEPTH EVER ARRIVES BYTE-ENCODED. It does not from the tile pyramid, which
 * carries signed height in metres with no encode step — so there is nothing to undo there, and
 * applying a decode would introduce the very bias one exists to remove. The hazard is real for any
 * BAKED byte channel: a field encoded as x*0.5+0.5 lands zero on byte 127.5, which rounds to 128,
 * and a decoder reading texel*2-1 returns 0.0039 rather than 0. On terrain normals that is a
 * constant tilt worth one 8-bit level. Here it would be 0.784 m of false depth at the shoreline,
 * where the ramp is steepest at 92 levels per metre — 51.6 levels of colour error along every coast
 * on the planet, fifty times what it costs the normals.
 *
 * The coastline field above does not have this problem and must not be "fixed" to match: its
 * encode and decode are both centred on 127.5, so they agree, and recentring only the decode would
 * introduce the very bias this warns about. Test-pinned both ways.
 *
 * WHAT THE SOURCE HAS TO DELIVER: metres over 0 to −200 m, and nothing past that. The colour model
 * saturates — 200 m sits 0.015 of an 8-bit level from open ocean, 1,000 m sits 1e-16 — so range
 * beyond it is wasted, while the first 50 m carries over nine tenths of the signal at up to 92
 * levels per metre. Eight bits spread linearly across that range band by 72 levels at the surface;
 * sixteen band by 0.28. See docs/globe-overlay-resolution.md for the table and for why block
 * compression is not safe on this channel.
 *
 * IT RETURNS A VALUE, AND IT MUST STAY THAT WAY. Hillshade the sea floor and the Atlantic becomes a
 * bathymetric chart: ridges and abyssal plains are not visible through three kilometres of water,
 * and drawing them reads as a map rather than an ocean. Depth belongs in the colour, under a lit
 * surface. This function may sample depth; it may never differentiate it.
 */
const float OPEN_OCEAN_M = 400.0;   // past the colour model's saturation at ~200 m

float waterDepthMetres(vec3 unitPos, float coastKm) {
  // Linear, and fitted rather than chosen. Sampling terrarium against distance-to-coast over five
  // shelves gives a median of 3.7 m at 4 km out, 8.1 m at 10 km and 14.2 m at 22 km — near enough
  // a straight line, and nothing like the 90 m an earlier squared profile reached at the same
  // distance. That overshoot is what turned the shelf into a thin bright rim around every coast
  // instead of a band: the water went from sand to open ocean within a few kilometres.
  float t = clamp(max(coastKm, 0.0) / u_shelfKm, 0.0, 1.0);
  float depth = u_shelfDepthM * t;

  // Past the shelf band, fall away to open ocean over the rest of the field's range. Without this
  // every sea beyond 22 km sits at shelf depth and the Atlantic renders as a shallow bank, never
  // reaching the deep colour the whole model is calibrated to. The distance field saturates at 32
  // km, so this is the last thing it can still tell us apart.
  //
  // It is also where the proxy is most obviously a proxy: a 32 km field cannot tell the North Sea
  // from the Atlantic, and this resolves that ambiguity toward deep, which is right for the 95% of
  // the ocean that IS deep and wrong for the shallow shelf seas. Real bathymetry ends the guess.
  depth = mix(depth, OPEN_OCEAN_M, smoothstep(0.72, 1.0, max(coastKm, 0.0) / u_rangeKm));

  // ABOVE THE MERCATOR LIMIT, SATURATE RATHER THAN SAMPLE.
  //
  // A STAND-IN, to be deleted at integration. The pyramid ships tiledSphereCoverage(vec3) for
  // exactly this, and three layers each choosing their own fade latitude is how one globe ends up
  // with the same edge drawn in three places. Multiply the ramp by that instead of this, and take
  // the argument about where the fade belongs to them rather than settling it here.
  //
  // Mercator stops at 85.0511°, so a tiled source has nothing past it — every sample clamps to the
  // same edge row. Under a colour ramp a smeared row does not read as blur, it reads as a band of
  // sea changing shade along a latitude line, which is the one artefact this layer cannot afford.
  //
  // It costs nothing to refuse, because there is no shallow water up there to lose. Measured on the
  // baked field: north of 85° is 100% water and EVERY texel sits at the field's saturated distance,
  // with 0.0% inside the shelf band; south of 85° is 0% water, being Antarctica. The ramp is already
  // constant at both poles, so forcing it constant changes nothing today and makes the swap to
  // tiles safe. The sphere mesh reaches ±89.999°, so without this the smear would be drawn.
  //
  // THE ARCTIC CAP IS A CONSTANT, AND IT IS EXACT RATHER THAN A COMPROMISE.
  //
  // Past 85.0511° mercator has no tiles and never will, so a tiled depth source has nothing to say
  // there. The cap is 0.191% of the earth and 100% water — no coastline in it, no land/water call
  // to make — but that alone does NOT license a constant, and the tile session was right to say so:
  // the basin runs from the Lomonosov crest down to −4393 m, and flattening that would be the same
  // class of error as flattening Antarctica for a shading term.
  //
  // It is licensed by something else. This ramp saturates at 200 m, so the whole range up there is
  // already one colour: 700 m against 4393 m differs by 3e-11 of an 8-bit level, and every depth
  // past 250 m sits within 0.002 of a level of this fill. A constant is not an approximation of the
  // Arctic bathymetry here — it is indistinguishable from having all of it.
  //
  // THE CONDITION, since it is the thing that would quietly stop being true: this holds only while
  // nothing above 85°N is shallower than about 250 m. Measured, 0.0% of the water up there is
  // within the shelf band and the shallowest known feature is around 700 m, so the margin is wide.
  // A ramp that ever gained deep-water structure — a bathymetric mode, say — would break it.
  //
  // Only the north needs saying. The south is land, and the coastline field discards it before this
  // function is reached, which is what carrying extent separately buys.
  const float SIN_FADE_START = 0.994522;   // 84°, one degree of run-up; tiledSphereCoverage replaces this
  const float SIN_MERCATOR_EDGE = 0.996272;
  const float ARCTIC_BASIN_M = OPEN_OCEAN_M;
  float polar = smoothstep(SIN_FADE_START, SIN_MERCATOR_EDGE, unitPos.y);
  return mix(depth, max(depth, ARCTIC_BASIN_M), polar);
}

/**
 * The colour of the water column itself, from how far light gets through it.
 *
 * NOT a lerp between a shallow colour and a deep one. Water absorbs light at wildly different rates
 * by wavelength — red is gone within a couple of metres, blue penetrates tens — so the turquoise of
 * a shelf and the navy of open ocean are the SAME water at two path lengths, not two paints. Beer-
 * Lambert per channel gets that for free, and gets the intermediate greens right, which is what a
 * two-colour lerp can never do: it always passes through a muddy average on the way.
 *
 * Light goes down, bounces off the bottom, and comes back up, so the path is twice the depth,
 * lengthened again when the water is seen at a slant. What survives that trip is the bottom; what
 * does not is replaced by the water's own scattered colour, which is what deep water converges to.
 */
vec3 waterColumn(float depthM, float facing) {
  float path = 2.0 * depthM / max(facing, 0.25);
  vec3 transmitted = exp(-u_absorption * path);
  return mix(u_scatter, u_bottom, transmitted);
}

void main() {
  vec3 normal = normalize(v_sphere);

  // The mesh is a closed sphere, so the far side covers the same pixels as the near side. Reject it
  // here rather than with the depth buffer: a shell sitting on a 6371 km sphere cannot be separated
  // from the globe's own tiles by depth at orbital range, and testing anyway breaks the layer into
  // a flickering quilt of tile-shaped patches. See FACING_CAMERA_GLSL.
  if (u_globeness > 0.5 && !facesCamera(normal, u_camera)) discard;

  float km = coastDistanceKm(normal);
  // u_shoreKm tracks the screen pixel, so this transition is always about a pixel and a half wide:
  // crisp on approach, and self-antialiasing at globe zoom where the coast is far below a texel.
  float water = smoothstep(-u_shoreKm, u_shoreKm, km);
  // Requested remap, off by default — see the u_edgeRemap note above for what it measures.
  water = mix(water, 0.3 * water + 0.1, u_edgeRemap);
  if (water < 0.004) discard;                    // land: leave the imagery alone

  vec3 view = normalize(u_camera - normal);
  float facing = max(dot(normal, view), 0.0);
  float fresnel = 0.02 + 0.98 * pow(1.0 - facing, 5.0);

  float sunAngle = dot(normal, u_sun);
  float day = daylightFraction(sunAngle);        // shared with the night and the clouds
  float lambert = max(sunAngle, 0.0);

  float depthM = waterDepthMetres(normal, km);
  vec3 body = waterColumn(depthM, facing) * (0.08 + 0.92 * lambert);

  vec3 skyTerm = u_sky * fresnel * day;

  vec3 halfway = normalize(u_sun + view);
  float alignment = max(dot(normal, halfway), 0.0);
  float sea = seaState(normal);
  float sharp = pow(alignment, mix(2400.0, 260.0, sea));
  float broad = pow(alignment, mix(180.0, 26.0, sea));
  // The highlight outlives the water colour on the way down — a square root rather than the fade
  // itself — so it survives the handover to Sentinel-2 instead of blinking out mid-descent.
  float glint = (sharp + 0.35 * broad) * day * u_strength * sqrt(u_fade);
  vec3 sunTerm = mix(vec3(0.85, 0.90, 1.0), vec3(1.0, 0.96, 0.86), fresnel) * glint;

  // ONE fade, in the alpha, and NOT a second one in the colour.
  //
  // The colour used to be scaled by the fade as well as the alpha. Premultiplied
  // output is colour * alpha, so the emitted light fell as fade SQUARED while the coverage fell as
  // fade — mid-handover the layer replaced a third of the imagery with a ninth of its own colour.
  // Measured over the North Sea at z7: Sentinel-2's (25,44,77) came out as (19,34,59). The layer
  // was fading toward BLACK rather than toward the picture underneath, and it darkened the very
  // imagery it hands over to. Alpha alone fades a layer out; scaling the colour too is a hole.
  vec3 colour = body + skyTerm + sunTerm;

  float alpha = clamp(water * (u_water * u_fade + glint), 0.0, 1.0) * u_opacity;
  if (alpha < 0.003) discard;
  // MapLibre blends custom layers with gl.ONE / gl.ONE_MINUS_SRC_ALPHA, i.e. PREMULTIPLIED alpha.
  // Multiplying the colour by the water mask instead of by the alpha is what made an earlier
  // version of this layer glow over dark ground.
  gl_FragColor = vec4(colour * alpha, alpha);
}`,so=(e,{fadeInAbove:a=9e5,fadeOutBelow:i=18e4}={})=>{const l=Math.max(0,Math.min(1,(e-i)/(a-i)));return l*l*(3-2*l)},lo=(e,a,i=.8)=>{const l=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e);return Math.max(i,l*1.5/1e3)},ho=40075,co=e=>Number.isFinite(e)&&e>0?ho/e/2:.8,ze=(e,a=wt)=>a.find(i=>i.width<=e&&i.height<=e)||null,uo=({opacity:e=1,strength:a=.9,roughness:i=.55,windPatch:l=.3,windScale:u=14,edgeRemap:f=0,sun:o=[.4,.5,.75],water:p=1,absorption:t=[.45,.06,.02],scatter:y=[.12,.265,.43],bottom:A=[.42,.4,.33],sky:x=[.34,.5,.76],shelfKm:g=22,shelfDepthM:T=16,shoreSoftnessKm:h=.8,fadeInAbove:c=9e5,fadeOutBelow:_=18e4,sources:w=wt}={})=>{const R=de();let n=null,r=null,C=null,D=null,M=null,O=!1;const s=new Map;let U={opacity:e,strength:a,roughness:i,windPatch:l,windScale:u,edgeRemap:f,sun:o,water:p,absorption:t,scatter:y,bottom:A,sky:x,shelfKm:g,shelfDepthM:T,shoreSoftnessKm:h,fadeInAbove:c,fadeOutBelow:_,sources:w};const G=m=>{const S=m.variantName;if(s.has(S))return s.get(S);const d=V(r,io(m),no(),"tm-ocean",{es300:!0}),F={program:d,attribs:{pos:r.getAttribLocation(d,"a_pos"),sphere:r.getAttribLocation(d,"a_sphere")},uniforms:{elevationGlobe:r.getUniformLocation(d,"a_elevation_globe"),elevationMercator:r.getUniformLocation(d,"a_elevation_mercator"),camera:r.getUniformLocation(d,"u_camera"),sun:r.getUniformLocation(d,"u_sun"),globeness:r.getUniformLocation(d,"u_globeness"),field:r.getUniformLocation(d,"u_field"),rangeKm:r.getUniformLocation(d,"u_rangeKm"),shoreKm:r.getUniformLocation(d,"u_shoreKm"),shelfKm:r.getUniformLocation(d,"u_shelfKm"),strength:r.getUniformLocation(d,"u_strength"),roughness:r.getUniformLocation(d,"u_roughness"),windPatch:r.getUniformLocation(d,"u_windPatch"),windScale:r.getUniformLocation(d,"u_windScale"),water:r.getUniformLocation(d,"u_water"),scatter:r.getUniformLocation(d,"u_scatter"),bottom:r.getUniformLocation(d,"u_bottom"),absorption:r.getUniformLocation(d,"u_absorption"),shelfDepthM:r.getUniformLocation(d,"u_shelfDepthM"),sky:r.getUniformLocation(d,"u_sky"),fade:r.getUniformLocation(d,"u_fade"),opacity:r.getUniformLocation(d,"u_opacity"),edgeRemap:r.getUniformLocation(d,"u_edgeRemap"),matrix:r.getUniformLocation(d,"u_projection_matrix"),tileMercatorCoords:r.getUniformLocation(d,"u_projection_tile_mercator_coords"),clippingPlane:r.getUniformLocation(d,"u_projection_clipping_plane"),transition:r.getUniformLocation(d,"u_projection_transition"),fallbackMatrix:r.getUniformLocation(d,"u_projection_fallback_matrix")}};return s.set(S,F),F},B=()=>{M=r.createTexture(),r.bindTexture(r.TEXTURE_2D,M),r.texImage2D(r.TEXTURE_2D,0,r.LUMINANCE,1,1,0,r.LUMINANCE,r.UNSIGNED_BYTE,new Uint8Array([0])),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_MIN_FILTER,r.LINEAR),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_MAG_FILTER,r.LINEAR),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_WRAP_S,r.CLAMP_TO_EDGE),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_WRAP_T,r.CLAMP_TO_EDGE)},L=m=>{r&&(M&&r.deleteTexture(M),M=r.createTexture(),r.bindTexture(r.TEXTURE_2D,M),r.pixelStorei(r.UNPACK_FLIP_Y_WEBGL,!1),Ve(r)?r.texImage2D(r.TEXTURE_2D,0,r.R8,r.RED,r.UNSIGNED_BYTE,m):r.texImage2D(r.TEXTURE_2D,0,r.LUMINANCE,r.LUMINANCE,r.UNSIGNED_BYTE,m),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_WRAP_S,r.REPEAT),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_WRAP_T,r.CLAMP_TO_EDGE),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_MIN_FILTER,r.LINEAR_MIPMAP_LINEAR),r.texParameteri(r.TEXTURE_2D,r.TEXTURE_MAG_FILTER,r.LINEAR),r.generateMipmap(r.TEXTURE_2D),O=!0,n?.triggerRepaint())},E=()=>{const m=ze(r.getParameter(r.MAX_TEXTURE_SIZE),U.sources);if(!m)return;const S=m.url;C=m.width,fetch(S,{credentials:"omit"}).then(d=>d.ok?d.blob():Promise.reject(new Error(d.status))).then(d=>createImageBitmap(d,{colorSpaceConversion:"none",premultiplyAlpha:"none",imageOrientation:"none"})).then(d=>{if(ze(r?.getParameter(r.MAX_TEXTURE_SIZE)??0,U.sources)?.url!==S){d.close?.();return}L(d),d.close?.()}).catch(()=>{})};return{id:_t,type:"custom",renderingMode:"3d",onAdd(m,S){n=m,r=S;const d=(F,P)=>{const H=r.createBuffer();return r.bindBuffer(F,H),r.bufferData(F,P,r.STATIC_DRAW),H};D={pos:d(r.ARRAY_BUFFER,R.positions),sphere:d(r.ARRAY_BUFFER,R.spheres),index:d(r.ELEMENT_ARRAY_BUFFER,R.indices)},B(),E()},onRemove(){r&&(s.forEach(({program:m})=>r.deleteProgram(m)),s.clear(),M&&(r.deleteTexture(M),M=null,O=!1),D&&(r.deleteBuffer(D.pos),r.deleteBuffer(D.sphere),r.deleteBuffer(D.index),D=null),n=null,r=null)},render(m,S){if(!D||U.opacity<=0)return;const d=S&&S.shaderData,F=S&&S.defaultProjectionData;if(!d||!F)return;const{program:P,attribs:H,uniforms:v}=G(d);r.useProgram(P),v.matrix&&r.uniformMatrix4fv(v.matrix,!1,F.mainMatrix),v.tileMercatorCoords&&r.uniform4f(v.tileMercatorCoords,...F.tileMercatorCoords),v.clippingPlane&&r.uniform4f(v.clippingPlane,...F.clippingPlane),v.transition&&r.uniform1f(v.transition,F.projectionTransition),v.fallbackMatrix&&r.uniformMatrix4fv(v.fallbackMatrix,!1,F.fallbackMatrix);const N=n.getCenter(),b=0;v.elevationGlobe&&r.uniform1f(v.elevationGlobe,b),v.elevationMercator&&r.uniform1f(v.elevationMercator,j.MercatorCoordinate.fromLngLat([0,N.lat],b).z),v.camera&&r.uniform3f(v.camera,...J(n,j)),v.sun&&r.uniform3f(v.sun,...U.sun),v.globeness&&r.uniform1f(v.globeness,F.projectionTransition),v.field&&(r.activeTexture(r.TEXTURE0),r.bindTexture(r.TEXTURE_2D,M),r.uniform1i(v.field,0)),v.rangeKm&&r.uniform1f(v.rangeKm,oo),v.shoreKm&&r.uniform1f(v.shoreKm,lo(n.getZoom(),N.lat,Math.max(U.shoreSoftnessKm,co(C)))),v.shelfKm&&r.uniform1f(v.shelfKm,U.shelfKm),v.strength&&r.uniform1f(v.strength,U.strength),v.roughness&&r.uniform1f(v.roughness,U.roughness),v.windPatch&&r.uniform1f(v.windPatch,U.windPatch),v.windScale&&r.uniform1f(v.windScale,U.windScale),v.water&&r.uniform1f(v.water,U.water),v.scatter&&r.uniform3f(v.scatter,...U.scatter),v.bottom&&r.uniform3f(v.bottom,...U.bottom),v.absorption&&r.uniform3f(v.absorption,...U.absorption),v.shelfDepthM&&r.uniform1f(v.shelfDepthM,U.shelfDepthM),v.sky&&r.uniform3f(v.sky,...U.sky),v.opacity&&r.uniform1f(v.opacity,U.opacity),v.edgeRemap&&r.uniform1f(v.edgeRemap,U.edgeRemap),v.fade&&r.uniform1f(v.fade,so(Re(n,j),U)),r.bindBuffer(r.ARRAY_BUFFER,D.pos),r.enableVertexAttribArray(H.pos),r.vertexAttribPointer(H.pos,2,r.FLOAT,!1,0,0),r.bindBuffer(r.ARRAY_BUFFER,D.sphere),r.enableVertexAttribArray(H.sphere),r.vertexAttribPointer(H.sphere,3,r.FLOAT,!1,0,0),r.disable(r.DEPTH_TEST),r.depthMask(!1),r.bindBuffer(r.ELEMENT_ARRAY_BUFFER,D.index),r.drawElements(r.TRIANGLES,R.indices.length,r.UNSIGNED_SHORT,0),r.enable(r.DEPTH_TEST),r.depthMask(!0)},setOptions(m={}){const S=U.sources;U={...U,...m},m.sources!==void 0&&m.sources!==S&&(O=!1,r&&E()),n?.triggerRepaint()},getOptions:()=>({...U}),get hasField(){return O}}},fo=_t,vt=(e,a,i,l=!0)=>{const u=Math.max(1,Math.floor(a)),f=Math.max(1,Math.floor(i)),o=e.createTexture();e.bindTexture(e.TEXTURE_2D,o),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,u,f,0,e.RGBA,e.UNSIGNED_BYTE,null),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR);let p=null;return l&&(p=e.createFramebuffer(),e.bindFramebuffer(e.FRAMEBUFFER,p),e.framebufferTexture2D(e.FRAMEBUFFER,e.COLOR_ATTACHMENT0,e.TEXTURE_2D,o,0)),{texture:o,framebuffer:p,width:u,height:f}},$e=(e,a)=>{a&&(a.texture&&e.deleteTexture(a.texture),a.framebuffer&&e.deleteFramebuffer(a.framebuffer))},mo=(e,a,i)=>{const l=[];for(let u=1;u<=i;u++){const f=2**u;l.push({width:Math.max(1,Math.floor(e/f)),height:Math.max(1,Math.floor(a/f))})}return l},po=(e,a,i,l)=>mo(a,i,l).map(u=>vt(e,u.width,u.height)),go=new Float32Array([-1,-1,1,-1,-1,1,1,1]),wo=e=>{const a=e.createBuffer();return e.bindBuffer(e.ARRAY_BUFFER,a),e.bufferData(e.ARRAY_BUFFER,go,e.STATIC_DRAW),a},_o=`precision highp float;
attribute vec2 a_corner;
varying vec2 v_uv;
void main() {
  v_uv = a_corner * 0.5 + 0.5;
  gl_Position = vec4(a_corner, 0.0, 1.0);
}`,vo=e=>({framebuffer:e.getParameter(e.FRAMEBUFFER_BINDING),viewport:e.getParameter(e.VIEWPORT)}),bo=(e,a)=>{e.bindFramebuffer(e.FRAMEBUFFER,a.framebuffer),e.viewport(a.viewport[0],a.viewport[1],a.viewport[2],a.viewport[3]),e.activeTexture(e.TEXTURE0),e.blendFunc(e.ONE,e.ONE_MINUS_SRC_ALPHA),e.enable(e.BLEND),e.depthMask(!0)},be=(e,a,i)=>{e.bindFramebuffer(e.FRAMEBUFFER,a.framebuffer),e.viewport(0,0,a.width,a.height),e.useProgram(i)},ie=(e,a,i)=>{e.bindBuffer(e.ARRAY_BUFFER,a),e.enableVertexAttribArray(i),e.vertexAttribPointer(i,2,e.FLOAT,!1,0,0),e.drawArrays(e.TRIANGLE_STRIP,0,4)},ne=(e,a,i,l)=>{e.activeTexture(e.TEXTURE0+a),e.bindTexture(e.TEXTURE_2D,i),l&&e.uniform1i(l,a)},bt="tm-bloom",qe=5,yo=[[1.18,.92,.68],[1.12,.95,.78],[1.06,.98,.89],[1.02,1,.97]],Eo=[1,1,1],To=()=>`precision highp float;
varying vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_threshold;
uniform float u_knee;

void main() {
  vec3 colour = texture2D(u_scene, v_uv).rgb;
  float brightness = max(colour.r, max(colour.g, colour.b));
  float soft = smoothstep(u_threshold - u_knee, u_threshold + u_knee, brightness);
  float headroom = max(1.0 - u_threshold, 0.02);
  vec3 kept = max(colour - vec3(u_threshold), vec3(0.0)) / headroom;
  gl_FragColor = vec4(kept * soft, 1.0);
}`,Ao=()=>`precision highp float;
varying vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_texel;          // one texel of the SOURCE

void main() {
  vec3 a = texture2D(u_source, v_uv + u_texel * vec2(-2.0,  2.0)).rgb;
  vec3 b = texture2D(u_source, v_uv + u_texel * vec2( 0.0,  2.0)).rgb;
  vec3 c = texture2D(u_source, v_uv + u_texel * vec2( 2.0,  2.0)).rgb;
  vec3 d = texture2D(u_source, v_uv + u_texel * vec2(-2.0,  0.0)).rgb;
  vec3 e = texture2D(u_source, v_uv).rgb;
  vec3 f = texture2D(u_source, v_uv + u_texel * vec2( 2.0,  0.0)).rgb;
  vec3 g = texture2D(u_source, v_uv + u_texel * vec2(-2.0, -2.0)).rgb;
  vec3 h = texture2D(u_source, v_uv + u_texel * vec2( 0.0, -2.0)).rgb;
  vec3 i = texture2D(u_source, v_uv + u_texel * vec2( 2.0, -2.0)).rgb;
  vec3 j = texture2D(u_source, v_uv + u_texel * vec2(-1.0,  1.0)).rgb;
  vec3 k = texture2D(u_source, v_uv + u_texel * vec2( 1.0,  1.0)).rgb;
  vec3 l = texture2D(u_source, v_uv + u_texel * vec2(-1.0, -1.0)).rgb;
  vec3 m = texture2D(u_source, v_uv + u_texel * vec2( 1.0, -1.0)).rgb;

  // The inner quad carries half the weight; the outer ring shares the rest.
  vec3 sum = (j + k + l + m) * 0.125
           + (a + c + g + i) * 0.03125
           + (b + d + f + h) * 0.0625
           + e * 0.125;
  gl_FragColor = vec4(sum, 1.0);
}`,xo=()=>`precision highp float;
varying vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_texel;          // one texel of the TARGET
uniform float u_radius;
uniform vec3 u_tint;

void main() {
  vec2 o = u_texel * u_radius;
  vec3 sum = texture2D(u_source, v_uv + vec2(-o.x,  o.y)).rgb * 1.0
           + texture2D(u_source, v_uv + vec2( 0.0,  o.y)).rgb * 2.0
           + texture2D(u_source, v_uv + vec2( o.x,  o.y)).rgb * 1.0
           + texture2D(u_source, v_uv + vec2(-o.x,  0.0)).rgb * 2.0
           + texture2D(u_source, v_uv).rgb                    * 4.0
           + texture2D(u_source, v_uv + vec2( o.x,  0.0)).rgb * 2.0
           + texture2D(u_source, v_uv + vec2(-o.x, -o.y)).rgb * 1.0
           + texture2D(u_source, v_uv + vec2( 0.0, -o.y)).rgb * 2.0
           + texture2D(u_source, v_uv + vec2( o.x, -o.y)).rgb * 1.0;
  gl_FragColor = vec4(sum * (1.0 / 16.0) * u_tint, 1.0);
}`,Ro=()=>`precision highp float;
varying vec2 v_uv;
uniform sampler2D u_bloom;
uniform float u_strength;

void main() {
  gl_FragColor = vec4(texture2D(u_bloom, v_uv).rgb * u_strength, 0.0);
}`,Mo=({strength:e=.9,threshold:a=.6,knee:i=.12,radius:l=1,chromatic:u=!0}={})=>{let f=null,o=null,p=null,t=null,y=null,A=null,x=null,g={strength:e,threshold:a,knee:i,radius:l,chromatic:u};const T=()=>{const _=(w,R,n)=>{const r=V(o,_o,w,R),C={};return n.forEach(D=>{C[D]=o.getUniformLocation(r,`u_${D}`)}),{program:r,corner:o.getAttribLocation(r,"a_corner"),...C}};return{bright:_(To(),"tm-bloom-bright",["scene","threshold","knee"]),down:_(Ao(),"tm-bloom-down",["source","texel"]),up:_(xo(),"tm-bloom-up",["source","texel","radius","tint"]),composite:_(Ro(),"tm-bloom-composite",["bloom","strength"])}},h=()=>{$e(o,y),y=null,A&&A.forEach(_=>$e(o,_)),A=null,x=null},c=(_,w)=>{x&&x.width===_&&x.height===w||(h(),y=vt(o,_,w,!1),A=po(o,_,w,qe),x={width:_,height:w})};return{id:bt,type:"custom",renderingMode:"2d",onAdd(_,w){f=_,o=w,p=wo(o)},onRemove(){o&&(t&&(Object.values(t).forEach(({program:_})=>o.deleteProgram(_)),t=null),h(),p&&(o.deleteBuffer(p),p=null),f=null,o=null)},render(){if(!p||g.strength<=0)return;const _=f.getCanvas(),w=Math.max(1,_.width),R=Math.max(1,_.height),n=vo(o);t=t||T(),c(w,R),o.bindTexture(o.TEXTURE_2D,y.texture),o.copyTexImage2D(o.TEXTURE_2D,0,o.RGBA,0,0,w,R,0),o.disable(o.DEPTH_TEST),o.disable(o.BLEND),o.depthMask(!1),be(o,A[0],t.bright.program),ne(o,0,y.texture,t.bright.scene),t.bright.threshold&&o.uniform1f(t.bright.threshold,g.threshold),t.bright.knee&&o.uniform1f(t.bright.knee,g.knee),ie(o,p,t.bright.corner);for(let r=1;r<A.length;r++){const C=A[r-1];be(o,A[r],t.down.program),ne(o,0,C.texture,t.down.source),t.down.texel&&o.uniform2f(t.down.texel,1/C.width,1/C.height),ie(o,p,t.down.corner)}o.enable(o.BLEND),o.blendFunc(o.ONE,o.ONE);for(let r=A.length-1;r>0;r--){const C=A[r-1];if(be(o,C,t.up.program),ne(o,0,A[r].texture,t.up.source),t.up.texel&&o.uniform2f(t.up.texel,1/C.width,1/C.height),t.up.radius&&o.uniform1f(t.up.radius,g.radius),t.up.tint){const D=g.chromatic?yo[A.length-1-r]:Eo;o.uniform3f(t.up.tint,...D)}ie(o,p,t.up.corner)}o.bindFramebuffer(o.FRAMEBUFFER,n.framebuffer),o.viewport(n.viewport[0],n.viewport[1],n.viewport[2],n.viewport[3]),o.useProgram(t.composite.program),ne(o,0,A[0].texture,t.composite.bloom),t.composite.strength&&o.uniform1f(t.composite.strength,g.strength),ie(o,p,t.composite.corner),bo(o,n)},setOptions(_={}){g={...g,..._},f?.triggerRepaint()},getOptions:()=>({...g}),get levelCount(){return qe}}},ko=bt,Me=[{key:"starfield",option:"brightness",label:"Stars",default:.5,max:1},{key:"sun",option:"brightness",label:"Sun",default:1,max:1},{key:"moon",option:"brightness",label:"Moon",default:1,max:1},{key:"daylight",option:"nightDarkness",label:"Day and night",default:.965,max:1},{key:"atmosphere",option:"strength",label:"Atmosphere",default:1,max:1},{key:"clouds",option:"opacity",label:"Clouds",default:1,max:1},{key:"ocean",option:"opacity",label:"Ocean",default:1,max:1},{key:"relief",option:"reliefPower",label:"Relief",default:0,max:2,on:"daylight"}],Ae={key:"reference",default:0},So="tm-layers-v2",Lo=(e=globalThis.localStorage)=>{const a={};for(const i of Me)a[i.key]={visible:i.default>0,value:i.default};a[Ae.key]={visible:!1,value:Ae.default};try{const i=JSON.parse(e?.getItem(So)||"{}");for(const[l,u]of Object.entries(i)){if(!a[l]||!u||typeof u!="object")continue;const f=u.value;a[l]={visible:u.visible===!0,value:typeof f=="number"&&Number.isFinite(f)?f:a[l].value}}}catch{}return a},Uo=(e,a)=>{const i=a?.visible?Number(a.value):0;return{[e.option]:Number.isFinite(i)?i:0}},Do=(e,a)=>{const i=[];for(const l of Me){const u=e?.[l.on||l.key];!u||typeof u.setOptions!="function"||(u.setOptions(Uo(l,a?.[l.key])),i.push(l.key))}return i},Po=(e,{onReference:a=null,target:i=globalThis}={})=>{const l=new Map(Me.map(u=>[u.key,u]));return i.__tmSetLayer=(u,f)=>{const o=Number.isFinite(Number(f))?Number(f):0;if(u===Ae.key)return a?.(o);const p=l.get(u);if(!p)return;const t=e?.[p.on||p.key];if(!(!t||typeof t.setOptions!="function"))return t.setOptions({[p.option]:o})},()=>{i.__tmSetLayer&&delete i.__tmSetLayer}},Io="/img/map/clouds-field.webp",Fo="/img/map/wind-field.png",Xe="/img/map/cloud-patches.webp",ye=e=>Et(e).rgb.map(a=>a/255),je=[["starfield",ma,e=>fa(e)],["sun",La,e=>Ma(e)],["moon",Oa,e=>Na(e)],["ocean",fo,e=>uo(e)],["daylight",Za,e=>Va(e)],["clouds",$t,e=>zt(e)],["atmosphere",ao,e=>to(e)],["bloom",ko,e=>Mo(e)]],yt=(e,a)=>{if(a&&e.getLayer(a))return a;let i;try{i=(e.getStyle()?.layers??[]).find(l=>l.type==="symbol"&&l.layout?.["text-field"]!=null)?.id}catch{}return a&&console.warn(`[globe-layers] "${a}" is not on this map; ${i?`anchoring under "${i}" so the labels stay readable`:"and nothing on it draws text, so the stack goes on top"}`),i},Co=(e,{date:a=new Date,reduceMotion:i=!1,beforeId:l,permitted:u=null}={})=>{const f=he(a),o=!i,p={fieldUrl:Io,patchUrl:Xe,windUrl:Fo,windAmount:.2,animate:o},t={starfield:{date:a,animate:o},sun:{date:a},moon:{date:a,sun:f},ocean:{sun:f,roughness:.95,strength:.48,windPatch:.6,shoreSoftnessKm:19.5},clouds:{...p,sun:f},daylight:{...p,sun:f,date:a},atmosphere:{sun:f,strength:1.25},bloom:{threshold:.86,strength:.45,knee:.06}},y={},A=yt(e,l);for(const[h,c,_]of je){if(e.getLayer(c)&&e.removeLayer(c),u&&!u(h))continue;const w=_(t[h]);e.addLayer(w,A),y[h]=w}Do(y,Lo());const x=Po(y),g=(h,c)=>y[h]?.setOptions?.(c),T=[window.__tune?.register("Ocean",[{key:"shoreSoftnessKm",label:"Shore falloff",min:0,max:25,step:.25,value:19.5,apply:h=>g("ocean",{shoreSoftnessKm:h})},{key:"roughness",label:"Roughness",min:0,max:1,step:.01,value:.95,apply:h=>g("ocean",{roughness:h})},{key:"strength",label:"Sun glint",min:0,max:1,step:.01,value:.48,apply:h=>g("ocean",{strength:h})},{key:"windPatch",label:"Patchiness",min:0,max:1,step:.01,value:.6,apply:h=>g("ocean",{windPatch:h})},{key:"windScale",label:"Wave scale",min:1,max:60,step:1,value:14,apply:h=>g("ocean",{windScale:h})},{key:"water",label:"Tint depth",min:0,max:2,step:.05,value:1,apply:h=>g("ocean",{water:h})},{key:"scatter",label:"Tint colour",type:"color",value:"#1d5c8f",apply:h=>g("ocean",{scatter:ye(h)})},{key:"opacity",label:"Opacity",min:0,max:1,step:.01,value:1,apply:h=>g("ocean",{opacity:h})}],{tab:"Earth"}),window.__tune?.register("Clouds",[{key:"opacity",label:"Density",min:0,max:1,step:.01,value:1,apply:h=>g("clouds",{opacity:h})},{key:"windAmount",label:"Curl",min:0,max:1,step:.05,value:.2,apply:h=>g("clouds",{windAmount:h})},{key:"windScale",label:"Wind scale",min:.01,max:.5,step:.01,value:.06,apply:h=>g("clouds",{windScale:h})},{key:"windRate",label:"Wind speed",min:0,max:.3,step:.005,value:.05,apply:h=>g("clouds",{windRate:h})},{key:"cloudShadow",label:"Shadows",min:0,max:1,step:.01,value:.5,apply:h=>g("daylight",{cloudShadow:h})},{key:"shadowSoftness",label:"Shadow edge",min:0,max:1,step:.01,value:.75,apply:h=>g("daylight",{shadowSoftness:h})},{key:"patchTiles",label:"Cloud cells",min:96,max:320,step:8,value:144,apply:h=>{g("clouds",{patchTiles:h}),g("daylight",{patchTiles:h})}},{key:"patchMean",label:"Atlas mean",min:.2,max:.8,step:.005,value:.4359,apply:h=>{g("clouds",{patchMean:h}),g("daylight",{patchMean:h})}},{key:"patchDetail",label:"Detail amount",min:0,max:1.5,step:.05,value:.75,apply:h=>{g("clouds",{patchDetail:h}),g("daylight",{patchDetail:h})}},{key:"patchUrl",label:"Tiled source",type:"boolean",value:!0,apply:h=>{const c=h?Xe:null;g("clouds",{patchUrl:c}),g("daylight",{patchUrl:c})}}],{tab:"Earth"}),window.__tune?.register("Cloud light",[{key:"cloudRelief",label:"Cloud height km",min:0,max:120,step:2,value:90,apply:h=>g("clouds",{cloudRelief:h})},{key:"cloudDepth",label:"Optical depth",min:0,max:12,step:.25,value:4,apply:h=>g("clouds",{cloudDepth:h})},{key:"powder",label:"Powder",min:0,max:1,step:.05,value:1,apply:h=>g("clouds",{powder:h})},{key:"forward",label:"Silver lining",min:0,max:2,step:.05,value:.5,apply:h=>g("clouds",{forward:h})},{key:"forwardG",label:"Lobe sharpness",min:0,max:.95,step:.05,value:.7,apply:h=>g("clouds",{forwardG:h})},{key:"selfShadow",label:"Self shadow",min:0,max:1,step:.02,value:.18,apply:h=>g("clouds",{selfShadow:h})},{key:"selfShadowStep",label:"Shadow reach",min:2e-4,max:.006,step:2e-4,value:.0015,apply:h=>g("clouds",{selfShadowStep:h})}],{tab:"Earth"}),window.__tune?.register("Sky and light",[{key:"nightDarkness",label:"Night",min:0,max:1,step:.005,value:.965,apply:h=>g("daylight",{nightDarkness:h})},{key:"twilightColour",label:"Twilight warm",type:"color",value:"#9e5229",apply:h=>g("daylight",{twilightColour:ye(h)})},{key:"twilightCool",label:"Twilight blue",type:"color",value:"#1a2647",apply:h=>g("daylight",{twilightCool:ye(h)})},{key:"twilightStrength",label:"Twilight strength",min:0,max:1,step:.05,value:.55,apply:h=>g("daylight",{twilightStrength:h})},{key:"atmosphere",label:"Haze",min:0,max:2,step:.05,value:1.25,apply:h=>g("atmosphere",{strength:h})},{key:"starfield",label:"Stars",min:0,max:1,step:.05,value:.5,apply:h=>g("starfield",{brightness:h})},{key:"sun",label:"Sun",min:0,max:2,step:.05,value:1,apply:h=>g("sun",{brightness:h})},{key:"moon",label:"Moon",min:0,max:2,step:.05,value:1,apply:h=>g("moon",{brightness:h})},{key:"moonGlow",label:"Moon glow",min:0,max:1.5,step:.05,value:.4,apply:h=>g("moon",{glow:h})},{key:"moonGlowExtent",label:"Moon glow reach",min:0,max:4,step:.1,value:1.8,apply:h=>g("moon",{glowExtent:h})}],{tab:"Earth"}),window.__tune?.register("Glare",[{key:"strength",label:"Strength",min:0,max:3,step:.05,value:.45,apply:h=>g("bloom",{strength:h})},{key:"threshold",label:"Threshold",min:0,max:1,step:.01,value:.86,apply:h=>g("bloom",{threshold:h})},{key:"knee",label:"Knee",min:0,max:.5,step:.01,value:.06,apply:h=>g("bloom",{knee:h})},{key:"radius",label:"Radius",min:.2,max:2,step:.05,value:1,apply:h=>g("bloom",{radius:h})},{key:"chromatic",label:"Warm at the edges",type:"boolean",value:!0,apply:h=>g("bloom",{chromatic:h})}],{tab:"Earth"})];return{layers:y,setDate(h){const c=h instanceof Date?h:new Date(h);if(Number.isNaN(c.getTime()))return;const _=he(c);for(const w of Object.keys(y))y[w]?.setOptions?.({date:c,sun:_})},remove(){x?.();for(const h of T)h?.();for(const[,h]of je)e.getLayer(h)&&e.removeLayer(h)}}},Bo=Object.freeze(Object.defineProperty({__proto__:null,addGlobeLayers:Co,globeAnchorId:yt},Symbol.toStringTag,{value:"Module"}));export{$t as C,Co as a,Bo as m,K as p,he as s};
