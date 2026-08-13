import{m as j}from"./map-imagery-GrMwsaGI.js";import{p as mt}from"./tuner-xnBLRIk5.js";const se=63710088e-1,K=(e,a,r=0)=>{const h=1+Math.max(0,r)/se,c=a*Math.PI/180,u=e*Math.PI/180;return[Math.cos(c)*Math.cos(u)*h,Math.sin(c)*h,Math.cos(c)*Math.sin(u)*h]},pt=e=>(2*Math.atan(Math.exp((1-2*e)*Math.PI))-Math.PI/2)*180/Math.PI,Te=e=>.5-Math.log(Math.tan(Math.PI/4+e*Math.PI/180/2))/(2*Math.PI),Ae=85.0511287798066,gt=89.999,ue=6,le=(e=64,a=48)=>{const r=[],h=[],c=[],u=i=>Ae+(gt-Ae)*(i/ue),t=[];for(let i=ue;i>=1;i--)t.push(Te(u(i)));for(let i=0;i<=a;i++)t.push(i/a);for(let i=1;i<=ue;i++)t.push(Te(-u(i)));for(const i of t){const E=pt(i)*Math.PI/180;for(let U=0;U<=e;U++){const A=U/e,T=(A*360-180)*Math.PI/180;r.push(A,i),h.push(Math.cos(E)*Math.cos(T),Math.sin(E),Math.cos(E)*Math.sin(T))}}const w=e+1;for(let i=0;i<t.length-1;i++)for(let E=0;E<e;E++){const U=i*w+E,A=U+w;c.push(U,A,U+1,A,A+1,U+1)}return{positions:new Float32Array(r),spheres:new Float32Array(h),indices:new Uint16Array(c),vertexCount:(e+1)*t.length,rowCount:t.length}},he=`
  vec4 projectShell(vec2 pos, float elevationGlobe, float elevationMercator) {
    #ifdef GLOBE
      return projectTileFor3D(pos, elevationGlobe);
    #else
      return projectTileFor3D(clamp(pos, 0.0, 1.0), elevationMercator);
    #endif
  }
`,be=`
  float daylightFraction(float sunAngle) {
    return smoothstep(-0.31, 0.09, sunAngle);
  }
`,He=`
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
`,Ge=`
  vec2 sphereSpan(vec3 origin, vec3 dir, float radius) {
    float b = dot(origin, dir);
    float c = dot(origin, origin) - radius * radius;
    float disc = b * b - c;
    if (disc < 0.0) return vec2(1.0, -1.0);
    float sq = sqrt(disc);
    return vec2(-b - sq, -b + sq);
  }
`,ze=`
  bool facesCamera(vec3 unitPos, vec3 camera) {
    return dot(unitPos, camera) > 1.0;
  }
`,ve=(e,a)=>{const r=e?.transform,h=typeof r?.getCameraAltitude=="function"?r.getCameraAltitude():null;if(Number.isFinite(h))return h;const c=r?.cameraToCenterDistance;if(!Number.isFinite(c))return 1e7;const u=512*Math.pow(2,e.getZoom()),t=1/a.MercatorCoordinate.fromLngLat(e.getCenter(),0).meterInMercatorCoordinateUnits();return c/u*t*Math.cos(e.getPitch()*Math.PI/180)},J=(e,a)=>{const r=e?.transform?.cameraPosition;if(r&&Number.isFinite(r[0])&&Number.isFinite(r[1])&&Number.isFinite(r[2]))return[r[2],r[1],r[0]];const h=e?.transform,c=typeof h?.getCameraLngLat=="function"?h.getCameraLngLat():e.getCenter();return K(c.lng,c.lat,ve(e,a))},$e=e=>typeof e.texStorage2D=="function",wt=`#version 300 es
#define attribute in
#define varying out
#define texture2D texture
`,_t=`#version 300 es
#define varying in
#define texture2D texture
out highp vec4 tm_fragColour;
#define gl_FragColor tm_fragColour
`,Z=(e,a,r,h="layer",{es300:c=!1}={})=>(c&&$e(e)&&(a=wt+a,r=_t+r),bt(e,a,r,h)),bt=(e,a,r,h)=>{const c=(i,E)=>{const U=e.createShader(i);if(e.shaderSource(U,E),e.compileShader(U),!e.getShaderParameter(U,e.COMPILE_STATUS)){const A=e.getShaderInfoLog(U);throw e.deleteShader(U),new Error(`${h} shader: ${A}`)}return U},u=e.createProgram(),t=c(e.VERTEX_SHADER,a),w=c(e.FRAGMENT_SHADER,r);if(e.attachShader(u,t),e.attachShader(u,w),e.linkProgram(u),e.deleteShader(t),e.deleteShader(w),!e.getProgramParameter(u,e.LINK_STATUS)){const i=e.getProgramInfoLog(u);throw e.deleteProgram(u),new Error(`${h} link: ${i}`)}return u},ce=`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }

  vec2 equirectUVInside(vec3 dir, float drift) {
    return vec2(-atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,oe=9e4,qe=`
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
`,je=["time","drift","field","fieldAmount","wind","windAmount","windScale","windRate","patches","patchAmount","patchTiles","patchMean","patchDetail"],vt=.75,yt=144,Et=.4359,Ye=(e,a,r,h,c,u,t)=>{const w=r.animate?h.seconds:0;a.time&&e.uniform1f(a.time,w),a.drift&&e.uniform1f(a.drift,w*r.driftRate),a.fieldAmount&&e.uniform1f(a.fieldAmount,h.field?.ready?1:0),a.patchAmount&&e.uniform1f(a.patchAmount,h.patches?.ready?1:0),a.patchTiles&&e.uniform1f(a.patchTiles,r.patchTiles??yt),a.patchMean&&e.uniform1f(a.patchMean,r.patchMean??Et),a.patchDetail&&e.uniform1f(a.patchDetail,r.patchDetail??vt),a.windAmount&&e.uniform1f(a.windAmount,h.wind?.ready?r.windAmount:0),a.windScale&&e.uniform1f(a.windScale,r.windScale),a.windRate&&e.uniform1f(a.windRate,r.animate?r.windRate:0),h.field?.ready&&a.field&&(e.activeTexture(e.TEXTURE0+c),e.bindTexture(e.TEXTURE_2D,h.field.texture),e.uniform1i(a.field,c)),h.wind?.ready&&a.wind&&(e.activeTexture(e.TEXTURE0+u),e.bindTexture(e.TEXTURE_2D,h.wind.texture),e.uniform1i(a.wind,u)),h.patches?.ready&&a.patches&&(e.activeTexture(e.TEXTURE0+t),e.bindTexture(e.TEXTURE_2D,h.patches.texture),e.uniform1i(a.patches,t))},xe=new WeakMap,Tt=e=>{let a=xe.get(e);return a||(a=new Map,xe.set(e,a)),a},At=(e,a,r)=>{const h=e.createTexture();return e.bindTexture(e.TEXTURE_2D,h),e.pixelStorei(e.UNPACK_FLIP_Y_WEBGL,!1),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,e.RGBA,e.UNSIGNED_BYTE,a),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.REPEAT),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,r?e.LINEAR_MIPMAP_LINEAR:e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR),r&&e.generateMipmap(e.TEXTURE_2D),h},W=(e,a,r=null,{mipmap:h=!0}={})=>{const c=Tt(e),u=`${a}|${h?"mip":"flat"}`;let t=c.get(u);if(!t){t={texture:null,ready:!1,refs:0,waiters:[],width:0},c.set(u,t);const i=new Image;i.crossOrigin="anonymous",i.onload=()=>{if(t.refs===0)return;const E=e.getParameter?.(e.MAX_TEXTURE_SIZE)??1/0;if(i.width>E){t.waiters=[];return}t.width=i.width,t.texture=At(e,i,h),t.ready=!0;const U=t.waiters;t.waiters=[],U.forEach(A=>A())},i.onerror=()=>{t.waiters=[]},i.src=a}t.refs++;let w=!0;return r&&(t.ready?r():t.waiters.push(r)),{get texture(){return t.texture},get ready(){return t.ready},get width(){return t.width},release(){w&&(w=!1,r&&(t.waiters=t.waiters.filter(i=>i!==r)),t.refs--,!(t.refs>0)&&(t.texture&&e.deleteTexture(t.texture),t.texture=null,t.ready=!1,c.delete(u)))}}},Xe=`
  mat3 equirectTangentFrame(vec3 unitPos) {
    vec3 up = normalize(unitPos);
    vec3 east = cross(up, vec3(0.0, 1.0, 0.0));
    float span = length(east);
    // Standing on a pole, every direction is south and no direction is east. Pick one rather than
    // dividing by zero and putting a NaN in the middle of Antarctica.
    east = span > 1.0e-4 ? east / span : vec3(0.0, 0.0, 1.0);
    return mat3(east, cross(up, east), up);
  }
`,We=`
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
`,xt=`
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
`,Rt=(e,a)=>2*Math.PI*se*Math.cos(a*Math.PI/180)/e,Mt=(e,a,r)=>Math.min(r,Math.max(a,e)),kt=(e,a,r)=>{const h=Mt((r-e)/(a-e),0,1);return h*h*(3-2*h)},St=(e,a,r)=>{const h=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e)/2,c=Rt(r,a)/h;return{pixelsPerTexel:c,strength:1-kt(3,5,Math.log2(Math.max(c,1e-6)))}},Ke="tm-clouds",Lt=(e,a,r)=>{const h=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e),u=156543.03392/2**8,t=Math.min(1,h/u),i=se/Math.max(1,h*110);return{frequency:Math.min(2600,Math.max(9,i)),amount:.14+(1-t)*.1,fade:Number.isFinite(r)?Ut(1.35,2.75,r/oe):0}},Ut=(e,a,r)=>{const h=Math.min(1,Math.max(0,(r-e)/(a-e)));return h*h*(3-2*h)},Dt=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${he}
void main() {
  v_sphere = a_sphere;
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Pt=()=>`#version 300 es
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
${He}
${ce}
${be}
${Xe}
${We}
// The field, the wind and the clock the ground's cloud shadows read from the same source.
${qe}

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
}`,It=({opacity:e=.5,animate:a=!0,fieldUrl:r=null,patchUrl:h=null,driftRate:c=4e-4,sun:u=[.4,.5,.75],windUrl:t=null,windAmount:w=1,windScale:i=.06,windRate:E=.05,cloudRelief:U=90,cloudDepth:A=4,powder:T=1,forward:k=.5,forwardG:l=.7,selfShadow:n=.18,selfShadowStep:v=.0015}={})=>{const b=le();let F=null,s=null,o=null;const H=new Map;let S={opacity:e,animate:a,fieldUrl:r,patchUrl:h,driftRate:c,sun:u,windUrl:t,windAmount:w,windScale:i,windRate:E,cloudRelief:U,cloudDepth:A,powder:T,forward:k,forwardG:l,selfShadow:n,selfShadowStep:v},f=null,d=null,I=null;const D=x=>{const y=x.variantName;if(H.has(y))return H.get(y);const p=Z(s,Dt(x),Pt(),"tm-clouds"),g={program:p,attribs:{pos:s.getAttribLocation(p,"a_pos"),sphere:s.getAttribLocation(p,"a_sphere")},uniforms:{elevationGlobe:s.getUniformLocation(p,"a_elevation_globe"),elevationMercator:s.getUniformLocation(p,"a_elevation_mercator"),opacity:s.getUniformLocation(p,"u_opacity"),sun:s.getUniformLocation(p,"u_sun"),...Object.fromEntries(je.map(m=>[m,s.getUniformLocation(p,`u_${m}`)])),detailFreq:s.getUniformLocation(p,"u_detailFreq"),detailAmount:s.getUniformLocation(p,"u_detailAmount"),deckFade:s.getUniformLocation(p,"u_deckFade"),camera:s.getUniformLocation(p,"u_camera"),cloudRelief:s.getUniformLocation(p,"u_cloudRelief"),cloudDepth:s.getUniformLocation(p,"u_cloudDepth"),powder:s.getUniformLocation(p,"u_powder"),forward:s.getUniformLocation(p,"u_forward"),forwardG:s.getUniformLocation(p,"u_forwardG"),selfShadow:s.getUniformLocation(p,"u_selfShadow"),selfShadowStep:s.getUniformLocation(p,"u_selfShadowStep"),matrix:s.getUniformLocation(p,"u_projection_matrix"),tileMercatorCoords:s.getUniformLocation(p,"u_projection_tile_mercator_coords"),clippingPlane:s.getUniformLocation(p,"u_projection_clipping_plane"),transition:s.getUniformLocation(p,"u_projection_transition"),fallbackMatrix:s.getUniformLocation(p,"u_projection_fallback_matrix")}};return H.set(y,g),g},G=(x,y)=>{x.matrix&&s.uniformMatrix4fv(x.matrix,!1,y.mainMatrix),x.tileMercatorCoords&&s.uniform4f(x.tileMercatorCoords,...y.tileMercatorCoords),x.clippingPlane&&s.uniform4f(x.clippingPlane,...y.clippingPlane),x.transition&&s.uniform1f(x.transition,y.projectionTransition),x.fallbackMatrix&&s.uniformMatrix4fv(x.fallbackMatrix,!1,y.fallbackMatrix)},B=()=>F?.triggerRepaint();return{id:Ke,type:"custom",renderingMode:"3d",onAdd(x,y){F=x,s=y;const p=(g,m)=>{const P=s.createBuffer();return s.bindBuffer(g,P),s.bufferData(g,m,s.STATIC_DRAW),P};o={pos:p(s.ARRAY_BUFFER,b.positions),sphere:p(s.ARRAY_BUFFER,b.spheres),index:p(s.ELEMENT_ARRAY_BUFFER,b.indices)},S.fieldUrl&&(f=W(s,S.fieldUrl,B)),S.windUrl&&(d=W(s,S.windUrl,B)),S.patchUrl&&(I=W(s,S.patchUrl,B))},onRemove(){s&&(H.forEach(({program:x})=>s.deleteProgram(x)),H.clear(),f?.release(),f=null,d?.release(),d=null,I?.release(),I=null,o&&(s.deleteBuffer(o.pos),s.deleteBuffer(o.sphere),s.deleteBuffer(o.index),o=null),F=null,s=null)},render(x,y){if(!o||S.opacity<=0)return;const p=y&&y.shaderData,g=y&&y.defaultProjectionData;if(!p||!g)return;const{program:m,attribs:P,uniforms:C}=D(p);s.useProgram(m),G(C,g);const N=F.getCenter().lat;C.elevationGlobe&&s.uniform1f(C.elevationGlobe,oe),C.elevationMercator&&s.uniform1f(C.elevationMercator,j.MercatorCoordinate.fromLngLat([0,N],oe).z),C.opacity&&s.uniform1f(C.opacity,S.opacity),C.sun&&s.uniform3f(C.sun,...S.sun),Ye(s,C,S,{seconds:performance.now()*.001,field:f,wind:d,patches:I},0,1,2);const _=Lt(F.getZoom(),F.getCenter().lat,ve(F,j));C.detailFreq&&s.uniform1f(C.detailFreq,_.frequency),C.detailAmount&&s.uniform1f(C.detailAmount,_.amount),C.deckFade&&s.uniform1f(C.deckFade,_.fade),C.camera&&s.uniform3f(C.camera,...J(F,j));const R=M=>{C[M]&&s.uniform1f(C[M],S[M])};R("cloudRelief"),R("cloudDepth"),R("powder"),R("forward"),R("forwardG"),R("selfShadow"),R("selfShadowStep"),s.bindBuffer(s.ARRAY_BUFFER,o.pos),s.enableVertexAttribArray(P.pos),s.vertexAttribPointer(P.pos,2,s.FLOAT,!1,0,0),s.bindBuffer(s.ARRAY_BUFFER,o.sphere),s.enableVertexAttribArray(P.sphere),s.vertexAttribPointer(P.sphere,3,s.FLOAT,!1,0,0),s.enable(s.DEPTH_TEST),s.depthFunc(s.LEQUAL),s.depthMask(!1),s.bindBuffer(s.ELEMENT_ARRAY_BUFFER,o.index),s.drawElements(s.TRIANGLES,b.indices.length,s.UNSIGNED_SHORT,0),s.depthMask(!0),S.animate&&F.triggerRepaint()},setOptions(x={}){const y=S;if(S={...S,...x},s){const p=(g,m)=>x[m]===void 0||x[m]===y[m]?g:(g?.release(),S[m]?W(s,S[m],B):null);f=p(f,"fieldUrl"),I=p(I,"patchUrl")}F&&F.triggerRepaint()},getOptions:()=>({...S}),get hasField(){return!!(f?.ready||I?.ready)}}},Ct=Ke,X=Math.PI/180,Ft=Date.UTC(2e3,0,1,12),fe=1/3600,Ve=e=>(e.getTime()-Ft)/864e5,Nt=(e=new Date)=>((18.697374558+24.06570982441908*Ve(e))%24*15%360+360)%360,Ot=e=>{const a=Ve(e)/36525;return{zeta:(2306.2181*a+.30188*a*a+.017998*a*a*a)*fe,z:(2306.2181*a+1.09468*a*a+.018203*a*a*a)*fe,theta:(2004.3109*a-.42665*a*a-.041833*a*a*a)*fe}},Bt=(e,a,r)=>{const{zeta:h,z:c,theta:u}=Ot(r),t=(e-c)*X,w=a*X,i=u*X,E=Math.cos(w)*Math.sin(t),U=Math.cos(i)*Math.cos(w)*Math.cos(t)+Math.sin(i)*Math.sin(w),A=-Math.sin(i)*Math.cos(w)*Math.cos(t)+Math.cos(i)*Math.sin(w);return{ra:Math.atan2(E,U)/X-h,dec:Math.asin(Math.min(1,Math.max(-1,A)))/X}},Re=e=>(e%360+360)%360,Ht=(e,a=new Date)=>{const r=Math.hypot(e[0],e[1],e[2])||1,h=Math.atan2(e[2]/r,e[0]/r)/X,c=Math.asin(Math.min(1,Math.max(-1,e[1]/r)))/X,u=Bt(Re(h+Nt(a)),c,a);return{ra:Re(u.ra),dec:u.dec}},Gt=`
  vec2 panoramaUV(vec3 skyDir) {
    return vec2(atan(skyDir.z, skyDir.x) / 6.28318530718 + 1.0,
                0.5 - asin(clamp(skyDir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,zt=(e=new Date)=>{const a=new Float32Array(9);return[[1,0,0],[0,1,0],[0,0,1]].forEach((h,c)=>{const{ra:u,dec:t}=Ht(h,e);a[c*3]=Math.cos(t*X)*Math.cos(u*X),a[c*3+1]=Math.sin(t*X),a[c*3+2]=Math.cos(t*X)*Math.sin(u*X)}),a},$t=Math.PI/180,ie=e=>{const a=Math.hypot(e[0],e[1],e[2])||1;return[e[0]/a,e[1]/a,e[2]/a]},qt=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],ge=(e,a)=>[e[0]-a[0],e[1]-a[1],e[2]-a[2]],Me=(e,a)=>{const r=qt(e,a);return ie([e[0]-a[0]*r,e[1]-a[1]*r,e[2]-a[2]*r])},jt=(e,a)=>{const h=a>89.98?-1:1,c=ie(ge(K(e,a+.01*h),K(e,a-.01*h))),u=ie(ge(K(e+.01,a),K(e-.01,a)));return{north:h===1?c:[-c[0],-c[1],-c[2]],east:u}},Yt=(e,a,r,h)=>{const c=e.getCenter(),u=(e.getBearing?.()??0)*$t,t=K(c.lng,c.lat,0),w=J(e,a),i=ie(ge(t,w)),{north:E,east:U}=jt(c.lng,c.lat),A=[0,1,2].map(b=>E[b]*Math.cos(u)+U[b]*Math.sin(u)),T=[0,1,2].map(b=>-E[b]*Math.sin(u)+U[b]*Math.cos(u)),k=Me(A,i),l=Me(T,i),n=Math.tan(r/2),v=n*h;return{origin:w,forward:i,up:k.map(b=>b*n),right:l.map(b=>b*v),upUnit:k,rightUnit:l}},we=(e,a)=>e*a*4,Ze=[{name:"high",width:4096,height:2048,url:"/img/map/sky/milkyway-4k.webp",bytes:3680220,resident:we(4096,2048),decodeMs:235},{name:"standard",width:2048,height:1024,url:"/img/map/sky/milkyway-2k.webp",bytes:764012,resident:we(2048,1024),decodeMs:51}],Qe={name:"placeholder",width:1024,height:512,url:"/img/map/sky/milkyway-1k.webp",bytes:45160,resident:we(1024,512),decodeMs:5},Xt=4,Wt=4,Kt=({maxTextureSize:e=0,deviceMemory:a=null,hardwareConcurrency:r=null,saveData:h=!1,effectiveType:c=null}={})=>{const u=Ze.filter(i=>i.width<=e);if(u.length===0)return{tier:Qe,reason:`MAX_TEXTURE_SIZE is ${e}, below every tier — falling back to the placeholder`};const t=u[u.length-1],w=u[0];return w===t?{tier:w,reason:`only ${w.name} fits MAX_TEXTURE_SIZE ${e}`}:h?{tier:t,reason:"the browser is in data-saver mode"}:c&&/^(slow-2g|2g|3g)$/.test(c)?{tier:t,reason:`the connection reports ${c}`}:Number.isFinite(a)&&a<Xt?{tier:t,reason:`the device reports ${a} GB of memory`}:Number.isFinite(r)&&r<Wt?{tier:t,reason:`the device reports ${r} cores`}:{tier:w,reason:`nothing says otherwise, and MAX_TEXTURE_SIZE is ${e}`}},Vt=(e,a=typeof navigator>"u"?null:navigator)=>{const r=a?.connection??null;return{maxTextureSize:e?.getParameter?.(e.MAX_TEXTURE_SIZE)??0,deviceMemory:a?.deviceMemory??null,hardwareConcurrency:a?.hardwareConcurrency??null,saveData:r?.saveData??!1,effectiveType:r?.effectiveType??null}},Je="tm-starfield",Zt=Qe.url,Qt="/data/sky/bright-stars.bin",Jt=e=>{const a=Number.isFinite(e)?e:5800,r=Math.min(4e4,Math.max(1e3,a))/100,h=E=>Math.min(1,Math.max(0,E/255)),c=r<=66?255:329.698727446*Math.pow(r-60,-.1332047592),u=r<=66?99.4708025861*Math.log(r)-161.1195681661:288.1221695283*Math.pow(r-60,-.0755148492),t=r>=66?255:r<=19?0:138.5177312231*Math.log(r-10)-305.0447927307,w=[h(c),h(u),h(t)],i=Math.max(...w)||1;return w.map(E=>E/i)},ea=(e,{limitMagnitude:a=6.5}={})=>{const r=new Float32Array(e),h=Math.floor(r.length/4),c=[];for(let t=0;t<h;t++){const w=r[t*4+2];if(w>a)continue;const i=Math.pow(10,-.4*w);c.push([r[t*4],r[t*4+1],Math.pow(i,.36),...Jt(r[t*4+3])])}const u=new Float32Array(c.length*6);return c.forEach((t,w)=>u.set(t,w*6)),{vertices:u,count:c.length}},et=`
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
`,ta=e=>`${e.define}
attribute vec2 a_pos;
varying vec2 v_ndc;
void main() {
  v_ndc = a_pos;
  // Straight to clip space. No projection, no elevation, no prelude — that is the whole point.
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`,aa=e=>`${e.define}
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
${et}
${Gt}

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
}`,oa=e=>`${e.define}
attribute vec3 a_star;       // right ascension and declination in degrees, then brightness
attribute vec3 a_colour;
uniform mat3 u_skyFrame;
uniform float u_pixelRatio;
uniform float u_starSize;
uniform float u_catalogueAmount;
varying vec3 v_colour;
${et}

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
}`,ia=e=>`${e.define}
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
}`,ra=({textureUrl:e=null,placeholderUrl:a=Zt,catalogueUrl:r=Qt,date:h=new Date,brightness:c=.55,nebula:u=.3,nebulaContrast:t=1.45,starDensity:w=210,starAmount:i=1.5,catalogueAmount:E=2.2,starSize:U=3,limitMagnitude:A=6.5,twinkle:T=0,animate:k=!1}={})=>{let l=null,n=null,v=null,b=null,F=null,s=null,o="not chosen yet",H=0;const S=new Map;let f={textureUrl:e,placeholderUrl:a,catalogueUrl:r,date:h,brightness:c,nebula:u,nebulaContrast:t,starDensity:w,starAmount:i,catalogueAmount:E,starSize:U,limitMagnitude:A,twinkle:T,animate:k};const d=x=>{const y=x.variantName;if(S.has(y))return S.get(y);const p=Z(n,ta(x),aa(x),"tm-starfield"),g=Z(n,oa(x),ia(x),"tm-starfield-catalogue"),m=(C,N)=>n.getUniformLocation(C,N),P={sky:{program:p,pos:n.getAttribLocation(p,"a_pos"),uniforms:{camera:m(p,"u_camera"),forward:m(p,"u_forward"),right:m(p,"u_right"),up:m(p,"u_up"),halfExtent:m(p,"u_halfExtent"),skyFrame:m(p,"u_skyFrame"),sky:m(p,"u_sky"),globeness:m(p,"u_globeness"),brightness:m(p,"u_brightness"),nebula:m(p,"u_nebula"),nebulaContrast:m(p,"u_nebulaContrast"),starDensity:m(p,"u_starDensity"),starAmount:m(p,"u_starAmount"),twinkle:m(p,"u_twinkle"),time:m(p,"u_time")}},stars:{program:g,star:n.getAttribLocation(g,"a_star"),colour:n.getAttribLocation(g,"a_colour"),uniforms:{camera:m(g,"u_camera"),forward:m(g,"u_forward"),right:m(g,"u_right"),up:m(g,"u_up"),halfExtent:m(g,"u_halfExtent"),skyFrame:m(g,"u_skyFrame"),pixelRatio:m(g,"u_pixelRatio"),starSize:m(g,"u_starSize"),catalogueAmount:m(g,"u_catalogueAmount")}}};return S.set(y,P),P},I=()=>{if(!n)return;const x=(y,p)=>y?W(n,y,p,{mipmap:!1}):null;if(f.textureUrl===null){const y=Kt(Vt(n));s=y.tier,o=y.reason}else s=Ze.find(y=>y.url===f.textureUrl)??{name:"explicit",url:f.textureUrl},o="the caller named a panorama";F=x(f.placeholderUrl,()=>{l?.triggerRepaint()}),b=x(s.url===f.placeholderUrl?null:s.url,()=>{F?.release(),F=null,l?.triggerRepaint()})},D=()=>b?.ready?b:F?.ready?F:null,G=()=>{b?.release(),F?.release(),b=null,F=null},B=x=>{!x||typeof fetch!="function"||fetch(x).then(y=>{if(!y.ok)throw new Error(`${y.status} ${y.statusText}`);return y.arrayBuffer()}).then(y=>{if(!n||!v)return;const{vertices:p,count:g}=ea(y,{limitMagnitude:f.limitMagnitude});n.bindBuffer(n.ARRAY_BUFFER,v.stars),n.bufferData(n.ARRAY_BUFFER,p,n.STATIC_DRAW),H=g,l?.triggerRepaint()}).catch(y=>console.warn(`[starfield] bright star catalogue unavailable: ${y.message}`))};return{id:Je,type:"custom",renderingMode:"3d",onAdd(x,y){l=x,n=y;const p=(g,m)=>{const P=n.createBuffer();return n.bindBuffer(g,P),m&&n.bufferData(g,m,n.STATIC_DRAW),P};v={pos:p(n.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,1,1])),index:p(n.ELEMENT_ARRAY_BUFFER,new Uint16Array([0,1,2,2,1,3])),stars:p(n.ARRAY_BUFFER,null)},I(),B(f.catalogueUrl)},onRemove(){n&&(S.forEach(({sky:x,stars:y})=>{n.deleteProgram(x.program),n.deleteProgram(y.program)}),S.clear(),G(),v&&(n.deleteBuffer(v.pos),n.deleteBuffer(v.index),n.deleteBuffer(v.stars),v=null),l=null,n=null)},render(x,y){if(!v||f.brightness<=0)return;const p=y&&y.shaderData,g=y&&y.defaultProjectionData;if(!p||!g)return;const m=l.getCanvas(),P=m.width/Math.max(1,m.height),C=Yt(l,j,y.fov||.6435,P),N=Math.tan((y.fov||.6435)/2),_=zt(f.date),R=g.projectionTransition,{sky:M,stars:O}=d(p),Y=({uniforms:$})=>{$.camera&&n.uniform3f($.camera,...C.origin),$.forward&&n.uniform3f($.forward,...C.forward),$.right&&n.uniform3f($.right,...C.rightUnit),$.up&&n.uniform3f($.up,...C.upUnit),$.halfExtent&&n.uniform2f($.halfExtent,N*P,N),$.skyFrame&&n.uniformMatrix3fv($.skyFrame,!1,_)};n.useProgram(M.program),Y(M),M.uniforms.globeness&&n.uniform1f(M.uniforms.globeness,R),M.uniforms.brightness&&n.uniform1f(M.uniforms.brightness,f.brightness);const L=D();M.uniforms.nebula&&n.uniform1f(M.uniforms.nebula,L?f.nebula:0),M.uniforms.nebulaContrast&&n.uniform1f(M.uniforms.nebulaContrast,f.nebulaContrast),M.uniforms.starDensity&&n.uniform1f(M.uniforms.starDensity,f.starDensity),M.uniforms.starAmount&&n.uniform1f(M.uniforms.starAmount,f.starAmount),M.uniforms.twinkle&&n.uniform1f(M.uniforms.twinkle,f.twinkle),M.uniforms.time&&n.uniform1f(M.uniforms.time,f.animate?performance.now()*.001:0),L&&M.uniforms.sky&&(n.activeTexture(n.TEXTURE0),n.bindTexture(n.TEXTURE_2D,L.texture),n.uniform1i(M.uniforms.sky,0)),n.bindBuffer(n.ARRAY_BUFFER,v.pos),n.enableVertexAttribArray(M.pos),n.vertexAttribPointer(M.pos,2,n.FLOAT,!1,0,0),n.disable(n.DEPTH_TEST),n.depthMask(!1),n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,v.index),n.drawElements(n.TRIANGLES,6,n.UNSIGNED_SHORT,0),H>0&&R>.5&&f.catalogueAmount>0&&(n.useProgram(O.program),Y(O),O.uniforms.pixelRatio&&n.uniform1f(O.uniforms.pixelRatio,typeof devicePixelRatio=="number"?devicePixelRatio:1),O.uniforms.starSize&&n.uniform1f(O.uniforms.starSize,f.starSize),O.uniforms.catalogueAmount&&n.uniform1f(O.uniforms.catalogueAmount,f.catalogueAmount*f.brightness),n.bindBuffer(n.ARRAY_BUFFER,v.stars),n.enableVertexAttribArray(O.star),n.vertexAttribPointer(O.star,3,n.FLOAT,!1,24,0),n.enableVertexAttribArray(O.colour),n.vertexAttribPointer(O.colour,3,n.FLOAT,!1,24,12),n.drawArrays(n.POINTS,0,H),n.disableVertexAttribArray(O.star),n.disableVertexAttribArray(O.colour)),n.enable(n.DEPTH_TEST),n.depthMask(!0),f.animate&&f.twinkle>0&&l.triggerRepaint()},setOptions(x={}){const y=f.textureUrl,p=f.catalogueUrl;f={...f,...x},x.textureUrl!==void 0&&x.textureUrl!==y&&(G(),I()),x.catalogueUrl!==void 0&&x.catalogueUrl!==p&&(H=0,B(x.catalogueUrl)),l?.triggerRepaint()},getOptions:()=>({...f}),get hasSky(){return D()!==null},get starCount(){return H},get skyTier(){return s?{...s,reason:o}:null}}},na=Je,V=Math.PI/180,sa=Date.UTC(2e3,0,1,12),la=149597870700,ha=6957e5,tt=e=>(e.getTime()-sa)/864e5,at=(e=new Date)=>{const a=tt(e),r=(280.46+.9856474*a)%360,h=(357.528+.9856003*a)%360*V,c=(r+1.915*Math.sin(h)+.02*Math.sin(2*h))*V,u=(23.439-4e-7*a)*V,t=Math.asin(Math.sin(u)*Math.sin(c))/V;let w=Math.atan2(Math.cos(u)*Math.sin(c),Math.cos(c))/V;w<0&&(w+=360);let i=r-w;i>180&&(i-=360),i<-180&&(i+=360),i*=4;const E=(1.00014-.01671*Math.cos(h)-14e-5*Math.cos(2*h))*la;return{declination:t,equationOfTime:i,distance:E}},ot=(e=new Date)=>at(e).distance,ca=(e=new Date)=>Math.atan(ha/ot(e)),da=(e=new Date)=>{const a=(357.528+.9856003*tt(e))%360*V;return(1.00014-.01671*Math.cos(a)-14e-5*Math.cos(2*a))*149597870700},ua=(e=new Date)=>{const{declination:a,equationOfTime:r}=at(e);let c=-15*(e.getUTCHours()+e.getUTCMinutes()/60+e.getUTCSeconds()/3600-12+r/60);return c=(c+540)%360-180,{lng:c,lat:a}},re=(e=new Date)=>{const{lng:a,lat:r}=ua(e),h=r*V,c=a*V;return[Math.cos(h)*Math.cos(c),Math.sin(h),Math.cos(h)*Math.sin(c)]},it="tm-sun",ke=63710088e-1,fa=18,ma=(e,a,r)=>{const h=Math.abs(e),c=a,u=r;if(c<=0)return 0;if(h>=c+u)return 1;if(h<=u-c)return 0;if(h<=c-u)return 1-u*u/(c*c);const t=c*c*Math.acos(ee((h*h+c*c-u*u)/(2*h*c),-1,1))+u*u*Math.acos(ee((h*h+u*u-c*c)/(2*h*u),-1,1))-.5*Math.sqrt(Math.max(0,(-h+c+u)*(h+c-u)*(h-c+u)*(h+c+u)));return ee(1-t/(Math.PI*c*c),0,1)},Se={u1:.93,u2:-.23},Le=e=>e<0?`(${e.toFixed(4)})`:e.toFixed(4),pa=`
  float limbDarkening(float rho) {
    float mu = sqrt(max(1.0 - rho * rho, 0.0));
    float t = 1.0 - mu;
    return max(1.0 - ${Le(Se.u1)} * t - ${Le(Se.u2)} * t * t, 0.0);
  }
`,ga=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Ue=new Uint16Array([0,1,2,1,3,2]),wa=e=>`${e.vertexShaderPrelude}
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
}`,_a=()=>`precision highp float;
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
${Ge}
${pa}

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
}`,ba=({date:e=new Date,haloScale:a=fa,haloStrength:r=1,discGain:h=1.15,brightness:c=1,coreColour:u=[1,.985,.95],haloColour:t=[1,.65,.2]}={})=>{let w=null,i=null,E=null;const U=new Map;let A={date:e,haloScale:a,haloStrength:r,discGain:h,brightness:c,coreColour:u,haloColour:t};const T=k=>{const l=k.variantName;if(U.has(l))return U.get(l);const n=Z(i,wa(k),_a(),"tm-sun"),v={program:n,attribs:{corner:i.getAttribLocation(n,"a_corner")},uniforms:{centre:i.getUniformLocation(n,"a_centre"),elevationGlobe:i.getUniformLocation(n,"a_elevation_globe"),elevationMercator:i.getUniformLocation(n,"a_elevation_mercator"),size:i.getUniformLocation(n,"u_size"),camera:i.getUniformLocation(n,"u_camera"),forward:i.getUniformLocation(n,"u_forward"),right:i.getUniformLocation(n,"u_right"),up:i.getUniformLocation(n,"u_up"),glowAngle:i.getUniformLocation(n,"u_glow_angle"),discFraction:i.getUniformLocation(n,"u_disc_fraction"),discGain:i.getUniformLocation(n,"u_disc_gain"),visible:i.getUniformLocation(n,"u_visible"),brightness:i.getUniformLocation(n,"u_brightness"),haloStrength:i.getUniformLocation(n,"u_halo_strength"),coreColour:i.getUniformLocation(n,"u_core_colour"),haloColour:i.getUniformLocation(n,"u_halo_colour"),matrix:i.getUniformLocation(n,"u_projection_matrix"),tileMercatorCoords:i.getUniformLocation(n,"u_projection_tile_mercator_coords"),clippingPlane:i.getUniformLocation(n,"u_projection_clipping_plane"),transition:i.getUniformLocation(n,"u_projection_transition"),fallbackMatrix:i.getUniformLocation(n,"u_projection_fallback_matrix")}};return U.set(l,v),v};return{id:it,type:"custom",renderingMode:"3d",onAdd(k,l){w=k,i=l;const n=i.createBuffer();i.bindBuffer(i.ARRAY_BUFFER,n),i.bufferData(i.ARRAY_BUFFER,ga,i.STATIC_DRAW);const v=i.createBuffer();i.bindBuffer(i.ELEMENT_ARRAY_BUFFER,v),i.bufferData(i.ELEMENT_ARRAY_BUFFER,Ue,i.STATIC_DRAW),E={corner:n,index:v}},onRemove(){i&&(U.forEach(({program:k})=>i.deleteProgram(k)),U.clear(),E&&(i.deleteBuffer(E.corner),i.deleteBuffer(E.index),E=null),w=null,i=null)},render(k,l){if(!E||A.brightness<=0)return;const n=l&&l.shaderData,v=l&&l.defaultProjectionData;if(!n||!v)return;const{program:b,attribs:F,uniforms:s}=T(n);i.useProgram(b),s.matrix&&i.uniformMatrix4fv(s.matrix,!1,v.mainMatrix),s.tileMercatorCoords&&i.uniform4f(s.tileMercatorCoords,...v.tileMercatorCoords),s.clippingPlane&&i.uniform4f(s.clippingPlane,...v.clippingPlane),s.transition&&i.uniform1f(s.transition,v.projectionTransition),s.fallbackMatrix&&i.uniformMatrix4fv(s.fallbackMatrix,!1,v.fallbackMatrix);const o=J(w,j),H=ot(A.date),S=K(...ya(re(A.date)),H-ke),f=De([S[0]-o[0],S[1]-o[1],S[2]-o[2]]),d=Math.hypot(...o),I=Math.max(.25*(d-1),.002),D=[o[0]+f[0]*I,o[1]+f[1]*I,o[2]+f[2]*I],G=Math.hypot(...D),B=Math.asin(D[1]/G)*180/Math.PI,x=Math.atan2(D[2],D[0])*180/Math.PI,y=(G-1)*ke,p=j.MercatorCoordinate.fromLngLat([x,B],0);s.centre&&i.uniform2f(s.centre,p.x,p.y),s.elevationGlobe&&i.uniform1f(s.elevationGlobe,y),s.elevationMercator&&i.uniform1f(s.elevationMercator,j.MercatorCoordinate.fromLngLat([x,B],y).z);const g=ca(A.date),m=g*A.haloScale,P=(l.fov||.6435)/2,C=w.getCanvas(),N=Math.tan(m)/Math.tan(P);s.size&&i.uniform2f(s.size,N*(C.height/C.width),N),s.glowAngle&&i.uniform1f(s.glowAngle,m),s.discFraction&&i.uniform1f(s.discFraction,1/A.haloScale),s.discGain&&i.uniform1f(s.discGain,A.discGain);const _=[-o[0]/d,-o[1]/d,-o[2]/d],R=Math.acos(ee(va(_,f),-1,1)),M=Math.asin(ee(1/d,-1,1)),O=ma(R,g,M),Y=f,L=De(Pe([0,1,0],Y)),$=Pe(Y,L);s.forward&&i.uniform3f(s.forward,...Y),s.right&&i.uniform3f(s.right,...L),s.up&&i.uniform3f(s.up,...$),s.camera&&i.uniform3f(s.camera,...o),s.visible&&i.uniform1f(s.visible,O),s.brightness&&i.uniform1f(s.brightness,A.brightness),s.haloStrength&&i.uniform1f(s.haloStrength,A.haloStrength),s.coreColour&&i.uniform3f(s.coreColour,...A.coreColour),s.haloColour&&i.uniform3f(s.haloColour,...A.haloColour),i.bindBuffer(i.ARRAY_BUFFER,E.corner),i.enableVertexAttribArray(F.corner),i.vertexAttribPointer(F.corner,2,i.FLOAT,!1,0,0),i.disable(i.DEPTH_TEST),i.depthMask(!1),i.bindBuffer(i.ELEMENT_ARRAY_BUFFER,E.index),i.drawElements(i.TRIANGLES,Ue.length,i.UNSIGNED_SHORT,0),i.depthMask(!0)},setOptions(k={}){A={...A,...k},w?.triggerRepaint()},getOptions:()=>({...A})}},ee=(e,a,r)=>Math.min(r,Math.max(a,e)),va=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],De=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},Pe=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],ya=([e,a,r])=>[Math.atan2(r,e)*180/Math.PI,Math.asin(ee(a,-1,1))*180/Math.PI],Ea=it,rt="tm-moon",ne=63710088e-1,Ta=1737400,Q=Math.PI/180,Aa=Date.UTC(2e3,0,1,12),xa=Date.UTC(1999,11,31,0),z=e=>Math.sin(e*Q),q=e=>Math.cos(e*Q),nt=(e=new Date)=>{const a=(e.getTime()-Aa)/864e5,r=(e.getTime()-xa)/864e5,h=125.1228-.0529538083*r,c=5.1454,u=318.0634+.1643573223*r,t=60.2666,w=.0549,i=115.3654+13.0649929509*r;let E=i+w*180/Math.PI*z(i)*(1+w*q(i));for(let m=0;m<3;m++)E-=(E-w*180/Math.PI*z(E)-i)/(1-w*q(E));const U=t*(q(E)-w),A=t*(Math.sqrt(1-w*w)*z(E)),T=Math.atan2(A,U)/Q,k=Math.sqrt(U*U+A*A);let l=k*(q(h)*q(T+u)-z(h)*z(T+u)*q(c)),n=k*(z(h)*q(T+u)+q(h)*z(T+u)*q(c)),v=k*(z(T+u)*z(c));const b=356.047+.9856002585*r,F=282.9404+470935e-10*r+b,s=h+u+i,o=s-F,H=s-h;let S=Math.atan2(n,l)/Q,f=Math.atan2(v,Math.hypot(l,n))/Q;S+=-1.274*z(i-2*o)+.658*z(2*o)-.186*z(b),f+=-.173*z(H-2*o);const d=(k-.58*q(i-2*o)-.46*q(2*o))*ne,I=23.4393-3563e-10*a,D=q(S)*q(f),G=z(S)*q(f)*q(I)-z(f)*z(I),B=z(S)*q(f)*z(I)+z(f)*q(I),x=Math.atan2(G,D)/Q,y=Math.atan2(B,Math.hypot(D,G))/Q,p=(18.697374558+24.06570982441908*a)%24;let g=x-p*15;return g=(g%360+540)%360-180,{lng:g,lat:y,distance:d}},st=(e=new Date)=>{const{lng:a,lat:r,distance:h}=nt(e);return K(a,r,h-ne)},Ra=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Ie=new Uint16Array([0,1,2,1,3,2]),Ma=e=>`${e.vertexShaderPrelude}
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
}`,ka=()=>`precision highp float;
varying vec2 v_corner;
uniform sampler2D u_albedo;
uniform vec3 u_sun;             // direction TO the sun, planet space
uniform vec3 u_right;           // the billboard's basis, in planet space
uniform vec3 u_up;
uniform vec3 u_forward;         // from the camera toward the moon
uniform float u_brightness;
uniform float u_hasAlbedo;
${ce}

void main() {
  float r2 = dot(v_corner, v_corner);
  if (r2 > 1.0) discard;                       // the quad's corners are not the moon

  // Rebuild the sphere the quad stands in for: z out of the disc toward the viewer.
  float z = sqrt(1.0 - r2);
  vec3 normal = normalize(u_right * v_corner.x + u_up * v_corner.y - u_forward * z);

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
}`,Sa=({date:e=new Date,sun:a=[1,0,0],albedoUrl:r=null,sizeScale:h=1,brightness:c=1}={})=>{let u=null,t=null,w=null,i=null,E=!1;const U=new Map;let A={date:e,sun:a,albedoUrl:r,sizeScale:h,brightness:c};const T=l=>{const n=l.variantName;if(U.has(n))return U.get(n);const v=Z(t,Ma(l),ka(),"tm-moon"),b={program:v,attribs:{corner:t.getAttribLocation(v,"a_corner")},uniforms:{centre:t.getUniformLocation(v,"a_centre"),elevationGlobe:t.getUniformLocation(v,"a_elevation_globe"),elevationMercator:t.getUniformLocation(v,"a_elevation_mercator"),size:t.getUniformLocation(v,"u_size"),albedo:t.getUniformLocation(v,"u_albedo"),hasAlbedo:t.getUniformLocation(v,"u_hasAlbedo"),sun:t.getUniformLocation(v,"u_sun"),right:t.getUniformLocation(v,"u_right"),up:t.getUniformLocation(v,"u_up"),forward:t.getUniformLocation(v,"u_forward"),brightness:t.getUniformLocation(v,"u_brightness"),matrix:t.getUniformLocation(v,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(v,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(v,"u_projection_clipping_plane"),transition:t.getUniformLocation(v,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(v,"u_projection_fallback_matrix")}};return U.set(n,b),b},k=l=>{const n=new Image;n.crossOrigin="anonymous",n.onload=()=>{t&&(i=i||t.createTexture(),t.bindTexture(t.TEXTURE_2D,i),t.texImage2D(t.TEXTURE_2D,0,t.RGBA,t.RGBA,t.UNSIGNED_BYTE,n),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_S,t.REPEAT),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_T,t.CLAMP_TO_EDGE),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MIN_FILTER,t.LINEAR),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MAG_FILTER,t.LINEAR),E=!0,u?.triggerRepaint())},n.src=l};return{id:rt,type:"custom",renderingMode:"3d",onAdd(l,n){u=l,t=n;const v=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,v),t.bufferData(t.ARRAY_BUFFER,Ra,t.STATIC_DRAW);const b=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,b),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Ie,t.STATIC_DRAW),w={corner:v,index:b},A.albedoUrl&&k(A.albedoUrl)},onRemove(){t&&(U.forEach(({program:l})=>t.deleteProgram(l)),U.clear(),i&&(t.deleteTexture(i),i=null,E=!1),w&&(t.deleteBuffer(w.corner),t.deleteBuffer(w.index),w=null),u=null,t=null)},render(l,n){if(!w||A.brightness<=0)return;const v=n&&n.shaderData,b=n&&n.defaultProjectionData;if(!v||!b)return;const{lng:F,lat:s,distance:o}=nt(A.date),{program:H,attribs:S,uniforms:f}=T(v);t.useProgram(H),f.matrix&&t.uniformMatrix4fv(f.matrix,!1,b.mainMatrix),f.tileMercatorCoords&&t.uniform4f(f.tileMercatorCoords,...b.tileMercatorCoords),f.clippingPlane&&t.uniform4f(f.clippingPlane,...b.clippingPlane),f.transition&&t.uniform1f(f.transition,b.projectionTransition),f.fallbackMatrix&&t.uniformMatrix4fv(f.fallbackMatrix,!1,b.fallbackMatrix);const d=J(u,j),I=K(F,s,o-ne),D=Ce([I[0]-d[0],I[1]-d[1],I[2]-d[2]]),B=Math.hypot(...d)+1.2,x=[d[0]+D[0]*B,d[1]+D[1]*B,d[2]+D[2]*B],y=Math.hypot(...x),p=Math.asin(x[1]/y)*180/Math.PI,g=Math.atan2(x[2],x[0])*180/Math.PI,m=(y-1)*ne,P=j.MercatorCoordinate.fromLngLat([g,p],0);f.centre&&t.uniform2f(f.centre,P.x,P.y),f.elevationGlobe&&t.uniform1f(f.elevationGlobe,m),f.elevationMercator&&t.uniform1f(f.elevationMercator,j.MercatorCoordinate.fromLngLat([g,p],m).z);const C=Math.atan(Ta*A.sizeScale/o),N=(n.fov||.6435)/2,_=u.getCanvas(),R=Math.tan(C)/Math.tan(N);f.size&&t.uniform2f(f.size,R*(_.height/_.width),R);const M=D,O=Ce(Fe([0,1,0],M)),Y=Fe(M,O);f.forward&&t.uniform3f(f.forward,...M),f.right&&t.uniform3f(f.right,...O),f.up&&t.uniform3f(f.up,...Y),f.sun&&t.uniform3f(f.sun,...A.sun),f.brightness&&t.uniform1f(f.brightness,A.brightness),f.hasAlbedo&&t.uniform1f(f.hasAlbedo,E?1:0),E&&f.albedo&&(t.activeTexture(t.TEXTURE0),t.bindTexture(t.TEXTURE_2D,i),t.uniform1i(f.albedo,0)),t.bindBuffer(t.ARRAY_BUFFER,w.corner),t.enableVertexAttribArray(S.corner),t.vertexAttribPointer(S.corner,2,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,w.index),t.drawElements(t.TRIANGLES,Ie.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(l={}){const n=A.albedoUrl;A={...A,...l},l.albedoUrl!==void 0&&l.albedoUrl!==n&&(E=!1,l.albedoUrl&&k(l.albedoUrl)),u?.triggerRepaint()},getOptions:()=>({...A}),get hasAlbedo(){return E}}},Ce=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},Fe=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],La=rt,Ua=6957e5,Da=1737400,Pa=63710088e-1,Ia=Da/Pa,Ca=(e,a,r)=>Math.min(r,Math.max(a,e)),Fa=(e=new Date)=>Math.asin(Ua/da(e)),Na=.035,Oa=(e=new Date,a=null,r=null)=>{const h=a||re(e),c=r||st(e),u=Math.hypot(...c);return Math.acos(Ca((h[0]*c[0]+h[1]*c[1]+h[2]*c[2])/u,-1,1))<Na},Ba=`
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
`,lt="tm-daylight",te={field:0,wind:1,relief:2,lights:3,patches:4},Ha=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${he}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ga=()=>`#version 300 es
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
uniform float u_cloudAltitude; // deck height in earth radii — sets how far a shadow is thrown
uniform vec3 u_moon;           // the moon's POSITION in earth radii, not its direction
uniform float u_sunRadius;     // the sun's angular radius; 0 on any date without an eclipse
${ce}
${ze}
${be}
${Xe}
${We}
${xt}
${Ba}
// The same field, wind and clock the deck in clouds.js draws itself from.
${qe}

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
    float shadow = smoothstep(0.16, 0.62, cover) * cloudShadowFade(normal, sunDir);
    day *= 1.0 - u_cloudShadow * shadow;
    lit *= 1.0 - shadow;
  }

  // ── Eclipse ───────────────────────────────────────────────────────────────────────────────
  // The moon covering some fraction of the sun's disc, worked out for THIS point rather than for
  // the planet: the moon is only sixty radii away, and that parallax is the whole reason totality
  // is a hundred-kilometre track instead of a hemisphere. Costs one comparison on the millions of
  // dates with no eclipse, because u_sunRadius is 0 on all of them.
  if (u_sunRadius > 0.0) {
    float remaining = eclipseLight(normal, sunDir, u_moon, u_sunRadius, ${Ia.toFixed(8)});
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
}`,za=({sun:e=[1,0,0],nightDarkness:a=.965,lightsUrl:r=null,lightsAmount:h=0,nightColour:c=[.02,.035,.07],twilightColour:u=[.62,.32,.16],twilightCool:t=[.1,.15,.28],twilightStrength:w=.55,reliefUrl:i=null,reliefWidth:E=8192,reliefPower:U=1.5,cloudShadow:A=.5,eclipse:T=!0,date:k=null,fieldUrl:l=null,patchUrl:n=null,windUrl:v=null,windAmount:b=1,windScale:F=.06,windRate:s=.05,driftRate:o=4e-4,animate:H=!0}={})=>{const S=le();let f=null,d=null,I=null,D=null,G=null,B=null,x=null,y=null;const p=new Map;let g={sun:e,nightDarkness:a,lightsUrl:r,lightsAmount:h,nightColour:c,twilightColour:u,twilightCool:t,twilightStrength:w,reliefUrl:i,reliefWidth:E,reliefPower:U,cloudShadow:A,eclipse:T,date:k,fieldUrl:l,patchUrl:n,windUrl:v,windAmount:b,windScale:F,windRate:s,driftRate:o,animate:H};const m=N=>{const _=N.variantName;if(p.has(_))return p.get(_);const R=Z(d,Ha(N),Ga(),"tm-daylight"),M={program:R,attribs:{pos:d.getAttribLocation(R,"a_pos"),sphere:d.getAttribLocation(R,"a_sphere")},uniforms:{elevationGlobe:d.getUniformLocation(R,"a_elevation_globe"),elevationMercator:d.getUniformLocation(R,"a_elevation_mercator"),sun:d.getUniformLocation(R,"u_sun"),camera:d.getUniformLocation(R,"u_camera"),globeness:d.getUniformLocation(R,"u_globeness"),nightDarkness:d.getUniformLocation(R,"u_nightDarkness"),nightColour:d.getUniformLocation(R,"u_nightColour"),twilightColour:d.getUniformLocation(R,"u_twilightColour"),twilightCool:d.getUniformLocation(R,"u_twilightCool"),twilightStrength:d.getUniformLocation(R,"u_twilightStrength"),lights:d.getUniformLocation(R,"u_lights"),lightsAmount:d.getUniformLocation(R,"u_lightsAmount"),relief:d.getUniformLocation(R,"u_relief"),reliefPower:d.getUniformLocation(R,"u_reliefPower"),cloudShadow:d.getUniformLocation(R,"u_cloudShadow"),cloudAltitude:d.getUniformLocation(R,"u_cloudAltitude"),moon:d.getUniformLocation(R,"u_moon"),sunRadius:d.getUniformLocation(R,"u_sunRadius"),...Object.fromEntries(je.map(O=>[O,d.getUniformLocation(R,`u_${O}`)])),matrix:d.getUniformLocation(R,"u_projection_matrix"),tileMercatorCoords:d.getUniformLocation(R,"u_projection_tile_mercator_coords"),clippingPlane:d.getUniformLocation(R,"u_projection_clipping_plane"),transition:d.getUniformLocation(R,"u_projection_transition"),fallbackMatrix:d.getUniformLocation(R,"u_projection_fallback_matrix")}};return p.set(_,M),M},P=()=>f?.triggerRepaint(),C=(N,_,R,M)=>{!_?.ready||!N[R]||(d.activeTexture(d.TEXTURE0+M),d.bindTexture(d.TEXTURE_2D,_.texture),d.uniform1i(N[R],M))};return{id:lt,type:"custom",renderingMode:"3d",onAdd(N,_){f=N,d=_;const R=(M,O)=>{const Y=d.createBuffer();return d.bindBuffer(M,Y),d.bufferData(M,O,d.STATIC_DRAW),Y};I={pos:R(d.ARRAY_BUFFER,S.positions),sphere:R(d.ARRAY_BUFFER,S.spheres),index:R(d.ELEMENT_ARRAY_BUFFER,S.indices)},g.lightsUrl&&(D=W(d,g.lightsUrl,P)),g.reliefUrl&&(G=W(d,g.reliefUrl,P)),g.fieldUrl&&(B=W(d,g.fieldUrl,P)),g.windUrl&&(x=W(d,g.windUrl,P)),g.patchUrl&&(y=W(d,g.patchUrl,P))},onRemove(){d&&(p.forEach(({program:N})=>d.deleteProgram(N)),p.clear(),D?.release(),D=null,G?.release(),G=null,B?.release(),B=null,x?.release(),x=null,y?.release(),y=null,I&&(d.deleteBuffer(I.pos),d.deleteBuffer(I.sphere),d.deleteBuffer(I.index),I=null),f=null,d=null)},render(N,_){if(!I||g.nightDarkness<=0)return;const R=_&&_.shaderData,M=_&&_.defaultProjectionData;if(!R||!M)return;const{program:O,attribs:Y,uniforms:L}=m(R);d.useProgram(O),L.matrix&&d.uniformMatrix4fv(L.matrix,!1,M.mainMatrix),L.tileMercatorCoords&&d.uniform4f(L.tileMercatorCoords,...M.tileMercatorCoords),L.clippingPlane&&d.uniform4f(L.clippingPlane,...M.clippingPlane),L.transition&&d.uniform1f(L.transition,M.projectionTransition),L.fallbackMatrix&&d.uniformMatrix4fv(L.fallbackMatrix,!1,M.fallbackMatrix);const $=f.getCenter().lat,Ee=0;L.elevationGlobe&&d.uniform1f(L.elevationGlobe,Ee),L.elevationMercator&&d.uniform1f(L.elevationMercator,j.MercatorCoordinate.fromLngLat([0,$],Ee).z),L.sun&&d.uniform3f(L.sun,...g.sun),L.camera&&d.uniform3f(L.camera,...J(f,j)),L.globeness&&d.uniform1f(L.globeness,M.projectionTransition),L.nightDarkness&&d.uniform1f(L.nightDarkness,g.nightDarkness),L.nightColour&&d.uniform3f(L.nightColour,...g.nightColour),L.twilightColour&&d.uniform3f(L.twilightColour,...g.twilightColour),L.twilightCool&&d.uniform3f(L.twilightCool,...g.twilightCool),L.twilightStrength&&d.uniform1f(L.twilightStrength,g.twilightStrength),L.lightsAmount&&d.uniform1f(L.lightsAmount,D?.ready?g.lightsAmount:0);const ft=G?.ready?St(f.getZoom(),$,g.reliefWidth).strength:0;L.reliefPower&&d.uniform1f(L.reliefPower,g.reliefPower*ft),L.cloudShadow&&d.uniform1f(L.cloudShadow,g.cloudShadow),L.cloudAltitude&&d.uniform1f(L.cloudAltitude,oe/se);const ae=g.eclipse?g.date:null,de=ae&&Oa(ae)?st(ae):null;L.sunRadius&&d.uniform1f(L.sunRadius,de?Fa(ae):0),de&&L.moon&&d.uniform3f(L.moon,...de),Ye(d,L,g,{seconds:performance.now()*.001,field:B,wind:x,patches:y},te.field,te.wind,te.patches),C(L,G,"relief",te.relief),C(L,D,"lights",te.lights),d.bindBuffer(d.ARRAY_BUFFER,I.pos),d.enableVertexAttribArray(Y.pos),d.vertexAttribPointer(Y.pos,2,d.FLOAT,!1,0,0),d.bindBuffer(d.ARRAY_BUFFER,I.sphere),d.enableVertexAttribArray(Y.sphere),d.vertexAttribPointer(Y.sphere,3,d.FLOAT,!1,0,0),d.disable(d.DEPTH_TEST),d.depthMask(!1),d.bindBuffer(d.ELEMENT_ARRAY_BUFFER,I.index),d.drawElements(d.TRIANGLES,S.indices.length,d.UNSIGNED_SHORT,0),d.enable(d.DEPTH_TEST),d.depthMask(!0),g.animate&&(B?.ready||y?.ready)&&g.cloudShadow>0&&f.triggerRepaint()},setOptions(N={}){const _=g;if(g={...g,...N},!d){f?.triggerRepaint();return}const R=(M,O)=>N[O]===void 0||N[O]===_[O]?M:(M?.release(),g[O]?W(d,g[O],P):null);D=R(D,"lightsUrl"),G=R(G,"reliefUrl"),B=R(B,"fieldUrl"),x=R(x,"windUrl"),y=R(y,"patchUrl"),f?.triggerRepaint()},getOptions:()=>({...g}),get hasLights(){return!!D?.ready},get hasRelief(){return!!G?.ready}}},$a=lt,ht="tm-atmosphere",qa=63710088e-1,me=2e5,ja=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${he}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ya=()=>`precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space (earth = unit sphere)
uniform vec3 u_sun;         // direction TO the sun
uniform float u_top;        // top of the atmosphere, in earth radii
uniform float u_strength;
uniform vec3 u_dayColour;
uniform vec3 u_duskColour;

const int SAMPLES = 6;

${Ge}

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
}`,Xa=({strength:e=1,sun:a=[.4,.5,.75],dayColour:r=[.32,.55,1],duskColour:h=[1,.45,.18]}={})=>{const c=le();let u=null,t=null,w=null;const i=new Map;let E={strength:e,sun:a,dayColour:r,duskColour:h};const U=A=>{const T=A.variantName;if(i.has(T))return i.get(T);const k=Z(t,ja(A),Ya(),"tm-atmosphere"),l={program:k,attribs:{pos:t.getAttribLocation(k,"a_pos"),sphere:t.getAttribLocation(k,"a_sphere")},uniforms:{elevationGlobe:t.getUniformLocation(k,"a_elevation_globe"),elevationMercator:t.getUniformLocation(k,"a_elevation_mercator"),camera:t.getUniformLocation(k,"u_camera"),sun:t.getUniformLocation(k,"u_sun"),top:t.getUniformLocation(k,"u_top"),strength:t.getUniformLocation(k,"u_strength"),dayColour:t.getUniformLocation(k,"u_dayColour"),duskColour:t.getUniformLocation(k,"u_duskColour"),matrix:t.getUniformLocation(k,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(k,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(k,"u_projection_clipping_plane"),transition:t.getUniformLocation(k,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(k,"u_projection_fallback_matrix")}};return i.set(T,l),l};return{id:ht,type:"custom",renderingMode:"3d",onAdd(A,T){u=A,t=T;const k=(l,n)=>{const v=t.createBuffer();return t.bindBuffer(l,v),t.bufferData(l,n,t.STATIC_DRAW),v};w={pos:k(t.ARRAY_BUFFER,c.positions),sphere:k(t.ARRAY_BUFFER,c.spheres),index:k(t.ELEMENT_ARRAY_BUFFER,c.indices)}},onRemove(){t&&(i.forEach(({program:A})=>t.deleteProgram(A)),i.clear(),w&&(t.deleteBuffer(w.pos),t.deleteBuffer(w.sphere),t.deleteBuffer(w.index),w=null),u=null,t=null)},render(A,T){if(!w||E.strength<=0)return;const k=T&&T.shaderData,l=T&&T.defaultProjectionData;if(!k||!l)return;const{program:n,attribs:v,uniforms:b}=U(k);t.useProgram(n),b.matrix&&t.uniformMatrix4fv(b.matrix,!1,l.mainMatrix),b.tileMercatorCoords&&t.uniform4f(b.tileMercatorCoords,...l.tileMercatorCoords),b.clippingPlane&&t.uniform4f(b.clippingPlane,...l.clippingPlane),b.transition&&t.uniform1f(b.transition,l.projectionTransition),b.fallbackMatrix&&t.uniformMatrix4fv(b.fallbackMatrix,!1,l.fallbackMatrix);const F=u.getCenter().lat;b.elevationGlobe&&t.uniform1f(b.elevationGlobe,me),b.elevationMercator&&t.uniform1f(b.elevationMercator,j.MercatorCoordinate.fromLngLat([0,F],me).z);const s=J(u,j);b.camera&&t.uniform3f(b.camera,s[0],s[1],s[2]),b.sun&&t.uniform3f(b.sun,...E.sun),b.top&&t.uniform1f(b.top,1+me/qa),b.strength&&t.uniform1f(b.strength,E.strength),b.dayColour&&t.uniform3f(b.dayColour,...E.dayColour),b.duskColour&&t.uniform3f(b.duskColour,...E.duskColour),t.bindBuffer(t.ARRAY_BUFFER,w.pos),t.enableVertexAttribArray(v.pos),t.vertexAttribPointer(v.pos,2,t.FLOAT,!1,0,0),t.bindBuffer(t.ARRAY_BUFFER,w.sphere),t.enableVertexAttribArray(v.sphere),t.vertexAttribPointer(v.sphere,3,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,w.index),t.drawElements(t.TRIANGLES,c.indices.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(A={}){E={...E,...A},u?.triggerRepaint()},getOptions:()=>({...E})}},Wa=ht,Ka=32,ct=[{width:8192,height:4096,url:"/timemap/ocean-sdf-8192.webp"},{width:4096,height:2048,url:"/timemap/ocean-sdf-4096.webp"}],Va=`
  float oceanDistanceKm(float stored, float rangeKm) {
    return (stored * 2.0 - 1.0) * rangeKm;
  }
`,dt="tm-ocean",Za=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${he}
void main() {
  v_sphere = a_sphere;
  // Sea level: the glint belongs on the water, not above it.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Qa=()=>`precision highp float;
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
${ce}
${He}
${ze}
${be}
${Va}

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
}`,Ja=(e,{fadeInAbove:a=9e5,fadeOutBelow:r=18e4}={})=>{const h=Math.max(0,Math.min(1,(e-r)/(a-r)));return h*h*(3-2*h)},eo=(e,a,r=.8)=>{const h=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e);return Math.max(r,h*1.5/1e3)},to=40075,ao=e=>Number.isFinite(e)&&e>0?to/e/2:.8,Ne=(e,a=ct)=>a.find(r=>r.width<=e&&r.height<=e)||null,oo=({opacity:e=1,strength:a=.9,roughness:r=.55,windPatch:h=.3,windScale:c=14,edgeRemap:u=0,sun:t=[.4,.5,.75],water:w=1,absorption:i=[.45,.06,.02],scatter:E=[.12,.265,.43],bottom:U=[.42,.4,.33],sky:A=[.34,.5,.76],shelfKm:T=22,shelfDepthM:k=16,shoreSoftnessKm:l=.8,fadeInAbove:n=9e5,fadeOutBelow:v=18e4,sources:b=ct}={})=>{const F=le();let s=null,o=null,H=null,S=null,f=null,d=!1;const I=new Map;let D={opacity:e,strength:a,roughness:r,windPatch:h,windScale:c,edgeRemap:u,sun:t,water:w,absorption:i,scatter:E,bottom:U,sky:A,shelfKm:T,shelfDepthM:k,shoreSoftnessKm:l,fadeInAbove:n,fadeOutBelow:v,sources:b};const G=p=>{const g=p.variantName;if(I.has(g))return I.get(g);const m=Z(o,Za(p),Qa(),"tm-ocean",{es300:!0}),P={program:m,attribs:{pos:o.getAttribLocation(m,"a_pos"),sphere:o.getAttribLocation(m,"a_sphere")},uniforms:{elevationGlobe:o.getUniformLocation(m,"a_elevation_globe"),elevationMercator:o.getUniformLocation(m,"a_elevation_mercator"),camera:o.getUniformLocation(m,"u_camera"),sun:o.getUniformLocation(m,"u_sun"),globeness:o.getUniformLocation(m,"u_globeness"),field:o.getUniformLocation(m,"u_field"),rangeKm:o.getUniformLocation(m,"u_rangeKm"),shoreKm:o.getUniformLocation(m,"u_shoreKm"),shelfKm:o.getUniformLocation(m,"u_shelfKm"),strength:o.getUniformLocation(m,"u_strength"),roughness:o.getUniformLocation(m,"u_roughness"),windPatch:o.getUniformLocation(m,"u_windPatch"),windScale:o.getUniformLocation(m,"u_windScale"),water:o.getUniformLocation(m,"u_water"),scatter:o.getUniformLocation(m,"u_scatter"),bottom:o.getUniformLocation(m,"u_bottom"),absorption:o.getUniformLocation(m,"u_absorption"),shelfDepthM:o.getUniformLocation(m,"u_shelfDepthM"),sky:o.getUniformLocation(m,"u_sky"),fade:o.getUniformLocation(m,"u_fade"),opacity:o.getUniformLocation(m,"u_opacity"),edgeRemap:o.getUniformLocation(m,"u_edgeRemap"),matrix:o.getUniformLocation(m,"u_projection_matrix"),tileMercatorCoords:o.getUniformLocation(m,"u_projection_tile_mercator_coords"),clippingPlane:o.getUniformLocation(m,"u_projection_clipping_plane"),transition:o.getUniformLocation(m,"u_projection_transition"),fallbackMatrix:o.getUniformLocation(m,"u_projection_fallback_matrix")}};return I.set(g,P),P},B=()=>{f=o.createTexture(),o.bindTexture(o.TEXTURE_2D,f),o.texImage2D(o.TEXTURE_2D,0,o.LUMINANCE,1,1,0,o.LUMINANCE,o.UNSIGNED_BYTE,new Uint8Array([0])),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MIN_FILTER,o.LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MAG_FILTER,o.LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_S,o.CLAMP_TO_EDGE),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_T,o.CLAMP_TO_EDGE)},x=p=>{o&&(f&&o.deleteTexture(f),f=o.createTexture(),o.bindTexture(o.TEXTURE_2D,f),o.pixelStorei(o.UNPACK_FLIP_Y_WEBGL,!1),$e(o)?o.texImage2D(o.TEXTURE_2D,0,o.R8,o.RED,o.UNSIGNED_BYTE,p):o.texImage2D(o.TEXTURE_2D,0,o.LUMINANCE,o.LUMINANCE,o.UNSIGNED_BYTE,p),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_S,o.REPEAT),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_T,o.CLAMP_TO_EDGE),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MIN_FILTER,o.LINEAR_MIPMAP_LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MAG_FILTER,o.LINEAR),o.generateMipmap(o.TEXTURE_2D),d=!0,s?.triggerRepaint())},y=()=>{const p=Ne(o.getParameter(o.MAX_TEXTURE_SIZE),D.sources);if(!p)return;const g=p.url;H=p.width,fetch(g,{credentials:"omit"}).then(m=>m.ok?m.blob():Promise.reject(new Error(m.status))).then(m=>createImageBitmap(m,{colorSpaceConversion:"none",premultiplyAlpha:"none",imageOrientation:"none"})).then(m=>{if(Ne(o?.getParameter(o.MAX_TEXTURE_SIZE)??0,D.sources)?.url!==g){m.close?.();return}x(m),m.close?.()}).catch(()=>{})};return{id:dt,type:"custom",renderingMode:"3d",onAdd(p,g){s=p,o=g;const m=(P,C)=>{const N=o.createBuffer();return o.bindBuffer(P,N),o.bufferData(P,C,o.STATIC_DRAW),N};S={pos:m(o.ARRAY_BUFFER,F.positions),sphere:m(o.ARRAY_BUFFER,F.spheres),index:m(o.ELEMENT_ARRAY_BUFFER,F.indices)},B(),y()},onRemove(){o&&(I.forEach(({program:p})=>o.deleteProgram(p)),I.clear(),f&&(o.deleteTexture(f),f=null,d=!1),S&&(o.deleteBuffer(S.pos),o.deleteBuffer(S.sphere),o.deleteBuffer(S.index),S=null),s=null,o=null)},render(p,g){if(!S||D.opacity<=0)return;const m=g&&g.shaderData,P=g&&g.defaultProjectionData;if(!m||!P)return;const{program:C,attribs:N,uniforms:_}=G(m);o.useProgram(C),_.matrix&&o.uniformMatrix4fv(_.matrix,!1,P.mainMatrix),_.tileMercatorCoords&&o.uniform4f(_.tileMercatorCoords,...P.tileMercatorCoords),_.clippingPlane&&o.uniform4f(_.clippingPlane,...P.clippingPlane),_.transition&&o.uniform1f(_.transition,P.projectionTransition),_.fallbackMatrix&&o.uniformMatrix4fv(_.fallbackMatrix,!1,P.fallbackMatrix);const R=s.getCenter(),M=0;_.elevationGlobe&&o.uniform1f(_.elevationGlobe,M),_.elevationMercator&&o.uniform1f(_.elevationMercator,j.MercatorCoordinate.fromLngLat([0,R.lat],M).z),_.camera&&o.uniform3f(_.camera,...J(s,j)),_.sun&&o.uniform3f(_.sun,...D.sun),_.globeness&&o.uniform1f(_.globeness,P.projectionTransition),_.field&&(o.activeTexture(o.TEXTURE0),o.bindTexture(o.TEXTURE_2D,f),o.uniform1i(_.field,0)),_.rangeKm&&o.uniform1f(_.rangeKm,Ka),_.shoreKm&&o.uniform1f(_.shoreKm,eo(s.getZoom(),R.lat,Math.max(D.shoreSoftnessKm,ao(H)))),_.shelfKm&&o.uniform1f(_.shelfKm,D.shelfKm),_.strength&&o.uniform1f(_.strength,D.strength),_.roughness&&o.uniform1f(_.roughness,D.roughness),_.windPatch&&o.uniform1f(_.windPatch,D.windPatch),_.windScale&&o.uniform1f(_.windScale,D.windScale),_.water&&o.uniform1f(_.water,D.water),_.scatter&&o.uniform3f(_.scatter,...D.scatter),_.bottom&&o.uniform3f(_.bottom,...D.bottom),_.absorption&&o.uniform3f(_.absorption,...D.absorption),_.shelfDepthM&&o.uniform1f(_.shelfDepthM,D.shelfDepthM),_.sky&&o.uniform3f(_.sky,...D.sky),_.opacity&&o.uniform1f(_.opacity,D.opacity),_.edgeRemap&&o.uniform1f(_.edgeRemap,D.edgeRemap),_.fade&&o.uniform1f(_.fade,Ja(ve(s,j),D)),o.bindBuffer(o.ARRAY_BUFFER,S.pos),o.enableVertexAttribArray(N.pos),o.vertexAttribPointer(N.pos,2,o.FLOAT,!1,0,0),o.bindBuffer(o.ARRAY_BUFFER,S.sphere),o.enableVertexAttribArray(N.sphere),o.vertexAttribPointer(N.sphere,3,o.FLOAT,!1,0,0),o.disable(o.DEPTH_TEST),o.depthMask(!1),o.bindBuffer(o.ELEMENT_ARRAY_BUFFER,S.index),o.drawElements(o.TRIANGLES,F.indices.length,o.UNSIGNED_SHORT,0),o.enable(o.DEPTH_TEST),o.depthMask(!0)},setOptions(p={}){const g=D.sources;D={...D,...p},p.sources!==void 0&&p.sources!==g&&(d=!1,o&&y()),s?.triggerRepaint()},getOptions:()=>({...D}),get hasField(){return d}}},io=dt,ye=[{key:"starfield",option:"brightness",label:"Stars",default:.5,max:1},{key:"sun",option:"brightness",label:"Sun",default:1,max:1},{key:"moon",option:"brightness",label:"Moon",default:1,max:1},{key:"daylight",option:"nightDarkness",label:"Day and night",default:.965,max:1},{key:"atmosphere",option:"strength",label:"Atmosphere",default:1,max:1},{key:"clouds",option:"opacity",label:"Clouds",default:1,max:1},{key:"ocean",option:"opacity",label:"Ocean",default:1,max:1},{key:"relief",option:"reliefPower",label:"Relief",default:0,max:2,on:"daylight"}],_e={key:"reference",default:0},ro="tm-layers-v2",no=(e=globalThis.localStorage)=>{const a={};for(const r of ye)a[r.key]={visible:r.default>0,value:r.default};a[_e.key]={visible:!1,value:_e.default};try{const r=JSON.parse(e?.getItem(ro)||"{}");for(const[h,c]of Object.entries(r)){if(!a[h]||!c||typeof c!="object")continue;const u=c.value;a[h]={visible:c.visible===!0,value:typeof u=="number"&&Number.isFinite(u)?u:a[h].value}}}catch{}return a},so=(e,a)=>{const r=a?.visible?Number(a.value):0;return{[e.option]:Number.isFinite(r)?r:0}},lo=(e,a)=>{const r=[];for(const h of ye){const c=e?.[h.on||h.key];!c||typeof c.setOptions!="function"||(c.setOptions(so(h,a?.[h.key])),r.push(h.key))}return r},ho=(e,{onReference:a=null,target:r=globalThis}={})=>{const h=new Map(ye.map(c=>[c.key,c]));return r.__tmSetLayer=(c,u)=>{const t=Number.isFinite(Number(u))?Number(u):0;if(c===_e.key)return a?.(t);const w=h.get(c);if(!w)return;const i=e?.[w.on||w.key];if(!(!i||typeof i.setOptions!="function"))return i.setOptions({[w.option]:t})},()=>{r.__tmSetLayer&&delete r.__tmSetLayer}},co="/img/map/clouds-field.webp",uo="/img/map/wind-field.png",Oe="/img/map/cloud-patches.webp",pe=e=>mt(e).rgb.map(a=>a/255),Be=[["starfield",na,e=>ra(e)],["sun",Ea,e=>ba(e)],["moon",La,e=>Sa(e)],["ocean",io,e=>oo(e)],["daylight",$a,e=>za(e)],["clouds",Ct,e=>It(e)],["atmosphere",Wa,e=>Xa(e)]],ut=(e,a)=>{if(a&&e.getLayer(a))return a;let r;try{r=(e.getStyle()?.layers??[]).find(h=>h.type==="symbol"&&h.layout?.["text-field"]!=null)?.id}catch{}return a&&console.warn(`[globe-layers] "${a}" is not on this map; ${r?`anchoring under "${r}" so the labels stay readable`:"and nothing on it draws text, so the stack goes on top"}`),r},fo=(e,{date:a=new Date,reduceMotion:r=!1,beforeId:h,permitted:c=null}={})=>{const u=re(a),t=!r,w={fieldUrl:co,patchUrl:Oe,windUrl:uo,windAmount:.2,animate:t},i={starfield:{date:a,animate:t},sun:{date:a},moon:{date:a,sun:u},ocean:{sun:u,roughness:.95,strength:.48,windPatch:.6,shoreSoftnessKm:19.5},clouds:{...w,sun:u},daylight:{...w,sun:u,date:a},atmosphere:{sun:u,strength:1.25}},E={},U=ut(e,h);for(const[l,n,v]of Be){if(e.getLayer(n)&&e.removeLayer(n),c&&!c(l))continue;const b=v(i[l]);e.addLayer(b,U),E[l]=b}lo(E,no());const A=ho(E),T=(l,n)=>E[l]?.setOptions?.(n),k=[window.__tune?.register("Ocean",[{key:"shoreSoftnessKm",label:"Shore falloff",min:0,max:25,step:.25,value:19.5,apply:l=>T("ocean",{shoreSoftnessKm:l})},{key:"roughness",label:"Roughness",min:0,max:1,step:.01,value:.95,apply:l=>T("ocean",{roughness:l})},{key:"strength",label:"Sun glint",min:0,max:1,step:.01,value:.48,apply:l=>T("ocean",{strength:l})},{key:"windPatch",label:"Patchiness",min:0,max:1,step:.01,value:.6,apply:l=>T("ocean",{windPatch:l})},{key:"windScale",label:"Wave scale",min:1,max:60,step:1,value:14,apply:l=>T("ocean",{windScale:l})},{key:"water",label:"Tint depth",min:0,max:2,step:.05,value:1,apply:l=>T("ocean",{water:l})},{key:"scatter",label:"Tint colour",type:"color",value:"#1d5c8f",apply:l=>T("ocean",{scatter:pe(l)})},{key:"opacity",label:"Opacity",min:0,max:1,step:.01,value:1,apply:l=>T("ocean",{opacity:l})}],{tab:"Earth"}),window.__tune?.register("Clouds",[{key:"opacity",label:"Density",min:0,max:1,step:.01,value:1,apply:l=>T("clouds",{opacity:l})},{key:"windAmount",label:"Curl",min:0,max:1,step:.05,value:.2,apply:l=>T("clouds",{windAmount:l})},{key:"windScale",label:"Wind scale",min:.01,max:.5,step:.01,value:.06,apply:l=>T("clouds",{windScale:l})},{key:"windRate",label:"Wind speed",min:0,max:.3,step:.005,value:.05,apply:l=>T("clouds",{windRate:l})},{key:"cloudShadow",label:"Shadows",min:0,max:1,step:.01,value:.5,apply:l=>T("daylight",{cloudShadow:l})},{key:"patchTiles",label:"Cloud cells",min:96,max:320,step:8,value:144,apply:l=>{T("clouds",{patchTiles:l}),T("daylight",{patchTiles:l})}},{key:"patchMean",label:"Atlas mean",min:.2,max:.8,step:.005,value:.4359,apply:l=>{T("clouds",{patchMean:l}),T("daylight",{patchMean:l})}},{key:"patchDetail",label:"Detail amount",min:0,max:1.5,step:.05,value:.75,apply:l=>{T("clouds",{patchDetail:l}),T("daylight",{patchDetail:l})}},{key:"patchUrl",label:"Tiled source",type:"boolean",value:!0,apply:l=>{const n=l?Oe:null;T("clouds",{patchUrl:n}),T("daylight",{patchUrl:n})}}],{tab:"Earth"}),window.__tune?.register("Cloud light",[{key:"cloudRelief",label:"Cloud height km",min:0,max:120,step:2,value:90,apply:l=>T("clouds",{cloudRelief:l})},{key:"cloudDepth",label:"Optical depth",min:0,max:12,step:.25,value:4,apply:l=>T("clouds",{cloudDepth:l})},{key:"powder",label:"Powder",min:0,max:1,step:.05,value:1,apply:l=>T("clouds",{powder:l})},{key:"forward",label:"Silver lining",min:0,max:2,step:.05,value:.5,apply:l=>T("clouds",{forward:l})},{key:"forwardG",label:"Lobe sharpness",min:0,max:.95,step:.05,value:.7,apply:l=>T("clouds",{forwardG:l})},{key:"selfShadow",label:"Self shadow",min:0,max:1,step:.02,value:.18,apply:l=>T("clouds",{selfShadow:l})},{key:"selfShadowStep",label:"Shadow reach",min:2e-4,max:.006,step:2e-4,value:.0015,apply:l=>T("clouds",{selfShadowStep:l})}],{tab:"Earth"}),window.__tune?.register("Sky and light",[{key:"nightDarkness",label:"Night",min:0,max:1,step:.005,value:.965,apply:l=>T("daylight",{nightDarkness:l})},{key:"twilightColour",label:"Twilight warm",type:"color",value:"#9e5229",apply:l=>T("daylight",{twilightColour:pe(l)})},{key:"twilightCool",label:"Twilight blue",type:"color",value:"#1a2647",apply:l=>T("daylight",{twilightCool:pe(l)})},{key:"twilightStrength",label:"Twilight strength",min:0,max:1,step:.05,value:.55,apply:l=>T("daylight",{twilightStrength:l})},{key:"atmosphere",label:"Haze",min:0,max:2,step:.05,value:1.25,apply:l=>T("atmosphere",{strength:l})},{key:"starfield",label:"Stars",min:0,max:1,step:.05,value:.5,apply:l=>T("starfield",{brightness:l})},{key:"sun",label:"Sun",min:0,max:2,step:.05,value:1,apply:l=>T("sun",{brightness:l})},{key:"moon",label:"Moon",min:0,max:2,step:.05,value:1,apply:l=>T("moon",{brightness:l})}],{tab:"Earth"})];return{layers:E,setDate(l){const n=l instanceof Date?l:new Date(l);if(Number.isNaN(n.getTime()))return;const v=re(n);for(const b of Object.keys(E))E[b]?.setOptions?.({date:n,sun:v})},remove(){A?.();for(const l of k)l?.();for(const[,l]of Be)e.getLayer(l)&&e.removeLayer(l)}}},go=Object.freeze(Object.defineProperty({__proto__:null,addGlobeLayers:fo,globeAnchorId:ut},Symbol.toStringTag,{value:"Module"}));export{Ct as C,fo as a,go as m,K as p,re as s};
