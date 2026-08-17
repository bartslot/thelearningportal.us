import{m as Y}from"./map-imagery-GrMwsaGI.js";import{p as Tt}from"./tuner-BsNOnGoL.js";const ue=63710088e-1,K=(e,a,i=0)=>{const s=1+Math.max(0,i)/ue,u=a*Math.PI/180,m=e*Math.PI/180;return[Math.cos(u)*Math.cos(m)*s,Math.sin(u)*s,Math.cos(u)*Math.sin(m)*s]},xt=e=>(2*Math.atan(Math.exp((1-2*e)*Math.PI))-Math.PI/2)*180/Math.PI,ke=e=>.5-Math.log(Math.tan(Math.PI/4+e*Math.PI/180/2))/(2*Math.PI),Se=85.0511287798066,At=89.999,we=6,de=(e=64,a=48)=>{const i=[],s=[],u=[],m=t=>Se+(At-Se)*(t/we),w=[];for(let t=we;t>=1;t--)w.push(ke(m(t)));for(let t=0;t<=a;t++)w.push(t/a);for(let t=1;t<=we;t++)w.push(ke(-m(t)));for(const t of w){const f=xt(t)*Math.PI/180;for(let M=0;M<=e;M++){const x=M/e,g=(x*360-180)*Math.PI/180;i.push(x,t),s.push(Math.cos(f)*Math.cos(g),Math.sin(f),Math.cos(f)*Math.sin(g))}}const r=e+1;for(let t=0;t<w.length-1;t++)for(let f=0;f<e;f++){const M=t*r+f,x=M+r;u.push(M,x,M+1,x,x+1,M+1)}return{positions:new Float32Array(i),spheres:new Float32Array(s),indices:new Uint16Array(u),vertexCount:(e+1)*w.length,rowCount:w.length}},fe=`
  vec4 projectShell(vec2 pos, float elevationGlobe, float elevationMercator) {
    #ifdef GLOBE
      return projectTileFor3D(pos, elevationGlobe);
    #else
      return projectTileFor3D(clamp(pos, 0.0, 1.0), elevationMercator);
    #endif
  }
`,Ae=`
  float daylightFraction(float sunAngle) {
    return smoothstep(-0.31, 0.09, sunAngle);
  }
`,We=`
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
`,Ke=`
  vec2 sphereSpan(vec3 origin, vec3 dir, float radius) {
    float b = dot(origin, dir);
    float c = dot(origin, origin) - radius * radius;
    float disc = b * b - c;
    if (disc < 0.0) return vec2(1.0, -1.0);
    float sq = sqrt(disc);
    return vec2(-b - sq, -b + sq);
  }
`,Ve=`
  bool facesCamera(vec3 unitPos, vec3 camera) {
    return dot(unitPos, camera) > 1.0;
  }
`,Re=(e,a)=>{const i=e?.transform,s=typeof i?.getCameraAltitude=="function"?i.getCameraAltitude():null;if(Number.isFinite(s))return s;const u=i?.cameraToCenterDistance;if(!Number.isFinite(u))return 1e7;const m=512*Math.pow(2,e.getZoom()),w=1/a.MercatorCoordinate.fromLngLat(e.getCenter(),0).meterInMercatorCoordinateUnits();return u/m*w*Math.cos(e.getPitch()*Math.PI/180)},ee=(e,a)=>{const i=e?.transform?.cameraPosition;if(i&&Number.isFinite(i[0])&&Number.isFinite(i[1])&&Number.isFinite(i[2]))return[i[2],i[1],i[0]];const s=e?.transform,u=typeof s?.getCameraLngLat=="function"?s.getCameraLngLat():e.getCenter();return K(u.lng,u.lat,Re(e,a))},Ze=e=>typeof e.texStorage2D=="function",Rt=`#version 300 es
#define attribute in
#define varying out
#define texture2D texture
`,Mt=`#version 300 es
#define varying in
#define texture2D texture
out highp vec4 tm_fragColour;
#define gl_FragColor tm_fragColour
`,V=(e,a,i,s="layer",{es300:u=!1}={})=>(u&&Ze(e)&&(a=Rt+a,i=Mt+i),kt(e,a,i,s)),kt=(e,a,i,s)=>{const u=(t,f)=>{const M=e.createShader(t);if(e.shaderSource(M,f),e.compileShader(M),!e.getShaderParameter(M,e.COMPILE_STATUS)){const x=e.getShaderInfoLog(M);throw e.deleteShader(M),new Error(`${s} shader: ${x}`)}return M},m=e.createProgram(),w=u(e.VERTEX_SHADER,a),r=u(e.FRAGMENT_SHADER,i);if(e.attachShader(m,w),e.attachShader(m,r),e.linkProgram(m),e.deleteShader(w),e.deleteShader(r),!e.getProgramParameter(m,e.LINK_STATUS)){const t=e.getProgramInfoLog(m);throw e.deleteProgram(m),new Error(`${s} link: ${t}`)}return m},me=`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }

  vec2 equirectUVInside(vec3 dir, float drift) {
    return vec2(-atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,se=9e4,Qe=`
  uniform float u_time;
  uniform float u_drift;        // slow west-to-east rotation of the whole field
  uniform sampler2D u_field;    // real cloud cover, equirectangular
  uniform float u_fieldAmount;  // 0 = no real field loaded
  uniform sampler2D u_wind;     // real wind field: R = eastward, G = southward, 0.5 = still
  uniform float u_windAmount;   // 0 = the field sits still, 1 = fully advected
  uniform float u_windScale;    // how far the flow carries per cycle, in UV
  uniform float u_windRate;     // cycles per second
  uniform sampler2D u_patches;  // harvested NASA cloud patches, 3x2 of 2048px coverage tiles
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
  // kilometre, so it collapses to a grey wash on approach. The atlas is real MODIS cloud at 306 m
  // per texel, sixty-four times finer, but it is only six patches of open ocean: 626 km of sky each.
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
  const float CLOUD_PATCH_TEXELS = 2048.0;
  // Patch widths around the equator: 40075 km of circumference over 626 km of patch. It is what
  // makes the atlas display at its native resolution whatever u_patchTiles is set to — and it is why
  // the geometry has not moved once across three source zooms: the footprint never changed.
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
`,Je=["time","drift","field","fieldAmount","wind","windAmount","windScale","windRate","patches","patchAmount","patchTiles","patchMean","patchDetail"],St=.75,Lt=144,Ut=.4335,et=(e,a,i,s,u,m,w)=>{const r=i.animate?s.seconds:0;a.time&&e.uniform1f(a.time,r),a.drift&&e.uniform1f(a.drift,r*i.driftRate),a.fieldAmount&&e.uniform1f(a.fieldAmount,s.field?.ready?1:0),a.patchAmount&&e.uniform1f(a.patchAmount,s.patches?.ready?1:0),a.patchTiles&&e.uniform1f(a.patchTiles,i.patchTiles??Lt),a.patchMean&&e.uniform1f(a.patchMean,i.patchMean??Ut),a.patchDetail&&e.uniform1f(a.patchDetail,i.patchDetail??St),a.windAmount&&e.uniform1f(a.windAmount,s.wind?.ready?i.windAmount:0),a.windScale&&e.uniform1f(a.windScale,i.windScale),a.windRate&&e.uniform1f(a.windRate,i.animate?i.windRate:0),s.field?.ready&&a.field&&(e.activeTexture(e.TEXTURE0+u),e.bindTexture(e.TEXTURE_2D,s.field.texture),e.uniform1i(a.field,u)),s.wind?.ready&&a.wind&&(e.activeTexture(e.TEXTURE0+m),e.bindTexture(e.TEXTURE_2D,s.wind.texture),e.uniform1i(a.wind,m)),s.patches?.ready&&a.patches&&(e.activeTexture(e.TEXTURE0+w),e.bindTexture(e.TEXTURE_2D,s.patches.texture),e.uniform1i(a.patches,w))},Le=new WeakMap,Dt=e=>{let a=Le.get(e);return a||(a=new Map,Le.set(e,a)),a},Pt=(e,a)=>a!==1?{internal:e.RGBA,format:e.RGBA}:typeof e.texStorage2D=="function"?{internal:e.R8,format:e.RED}:{internal:e.LUMINANCE,format:e.LUMINANCE},It=(e,a,i,s)=>{const u=e.createTexture();e.bindTexture(e.TEXTURE_2D,u),e.pixelStorei(e.UNPACK_FLIP_Y_WEBGL,!1);const{internal:m,format:w}=Pt(e,s);return e.texImage2D(e.TEXTURE_2D,0,m,w,e.UNSIGNED_BYTE,a),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.REPEAT),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,i?e.LINEAR_MIPMAP_LINEAR:e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR),i&&e.generateMipmap(e.TEXTURE_2D),u},W=(e,a,i=null,{mipmap:s=!0,channels:u=4}={})=>{const m=Dt(e),w=`${a}|${s?"mip":"flat"}|c${u}`;let r=m.get(w);if(!r){r={texture:null,ready:!1,refs:0,waiters:[],width:0},m.set(w,r);const f=new Image;f.crossOrigin="anonymous",f.onload=()=>{if(r.refs===0)return;const M=e.getParameter?.(e.MAX_TEXTURE_SIZE)??1/0;if(f.width>M){r.waiters=[];return}r.width=f.width,r.texture=It(e,f,s,u),r.ready=!0;const x=r.waiters;r.waiters=[],x.forEach(g=>g())},f.onerror=()=>{r.waiters=[]},f.src=a}r.refs++;let t=!0;return i&&(r.ready?i():r.waiters.push(i)),{get texture(){return r.texture},get ready(){return r.ready},get width(){return r.width},release(){t&&(t=!1,i&&(r.waiters=r.waiters.filter(f=>f!==i)),r.refs--,!(r.refs>0)&&(r.texture&&e.deleteTexture(r.texture),r.texture=null,r.ready=!1,m.delete(w)))}}},tt=`
  mat3 equirectTangentFrame(vec3 unitPos) {
    vec3 up = normalize(unitPos);
    vec3 east = cross(up, vec3(0.0, 1.0, 0.0));
    float span = length(east);
    // Standing on a pole, every direction is south and no direction is east. Pick one rather than
    // dividing by zero and putting a NaN in the middle of Antarctica.
    east = span > 1.0e-4 ? east / span : vec3(0.0, 0.0, 1.0);
    return mat3(east, cross(up, east), up);
  }
`,at=`
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
`,Ft=`
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
`,Ct=(e,a)=>2*Math.PI*ue*Math.cos(a*Math.PI/180)/e,Nt=(e,a,i)=>Math.min(i,Math.max(a,e)),Ot=(e,a,i)=>{const s=Nt((i-e)/(a-e),0,1);return s*s*(3-2*s)},Bt=(e,a,i)=>{const s=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e)/2,u=Ct(i,a)/s;return{pixelsPerTexel:u,strength:1-Ot(3,5,Math.log2(Math.max(u,1e-6)))}},ot="tm-clouds",Gt=(e,a,i)=>{const s=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e),m=156543.03392/2**9,w=Math.min(1,s/m),t=ue/Math.max(1,s*110);return{frequency:Math.min(2600,Math.max(9,t)),amount:.14+(1-w)*.1,fade:Number.isFinite(i)?Ht(1.35,2.75,i/se):0}},Ht=(e,a,i)=>{const s=Math.min(1,Math.max(0,(i-e)/(a-e)));return s*s*(3-2*s)},zt=e=>`#version 300 es
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
}`,$t=()=>`#version 300 es
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
${We}
${me}
${Ae}
${tt}
${at}
// The field, the wind and the clock the ground's cloud shadows read from the same source.
${Qe}

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
}`,qt=({opacity:e=.5,animate:a=!0,fieldUrl:i=null,patchUrl:s=null,driftRate:u=4e-4,sun:m=[.4,.5,.75],windUrl:w=null,windAmount:r=1,windScale:t=.06,windRate:f=.05,cloudRelief:M=90,cloudDepth:x=4,powder:g=1,forward:L=.5,forwardG:h=.7,selfShadow:c=.18,selfShadowStep:v=.0015}={})=>{const _=de();let E=null,l=null,o=null;const P=new Map;let U={opacity:e,animate:a,fieldUrl:i,patchUrl:s,driftRate:u,sun:m,windUrl:w,windAmount:r,windScale:t,windRate:f,cloudRelief:M,cloudDepth:x,powder:g,forward:L,forwardG:h,selfShadow:c,selfShadowStep:v},T=null,N=null,n=null;const D=S=>{const A=S.variantName;if(P.has(A))return P.get(A);const p=V(l,zt(S),$t(),"tm-clouds"),k={program:p,attribs:{pos:l.getAttribLocation(p,"a_pos"),sphere:l.getAttribLocation(p,"a_sphere")},uniforms:{elevationGlobe:l.getUniformLocation(p,"a_elevation_globe"),elevationMercator:l.getUniformLocation(p,"a_elevation_mercator"),opacity:l.getUniformLocation(p,"u_opacity"),sun:l.getUniformLocation(p,"u_sun"),...Object.fromEntries(Je.map(d=>[d,l.getUniformLocation(p,`u_${d}`)])),detailFreq:l.getUniformLocation(p,"u_detailFreq"),detailAmount:l.getUniformLocation(p,"u_detailAmount"),deckFade:l.getUniformLocation(p,"u_deckFade"),camera:l.getUniformLocation(p,"u_camera"),cloudRelief:l.getUniformLocation(p,"u_cloudRelief"),cloudDepth:l.getUniformLocation(p,"u_cloudDepth"),powder:l.getUniformLocation(p,"u_powder"),forward:l.getUniformLocation(p,"u_forward"),forwardG:l.getUniformLocation(p,"u_forwardG"),selfShadow:l.getUniformLocation(p,"u_selfShadow"),selfShadowStep:l.getUniformLocation(p,"u_selfShadowStep"),matrix:l.getUniformLocation(p,"u_projection_matrix"),tileMercatorCoords:l.getUniformLocation(p,"u_projection_tile_mercator_coords"),clippingPlane:l.getUniformLocation(p,"u_projection_clipping_plane"),transition:l.getUniformLocation(p,"u_projection_transition"),fallbackMatrix:l.getUniformLocation(p,"u_projection_fallback_matrix")}};return P.set(A,k),k},G=(S,A)=>{S.matrix&&l.uniformMatrix4fv(S.matrix,!1,A.mainMatrix),S.tileMercatorCoords&&l.uniform4f(S.tileMercatorCoords,...A.tileMercatorCoords),S.clippingPlane&&l.uniform4f(S.clippingPlane,...A.clippingPlane),S.transition&&l.uniform1f(S.transition,A.projectionTransition),S.fallbackMatrix&&l.uniformMatrix4fv(S.fallbackMatrix,!1,A.fallbackMatrix)},B=()=>E?.triggerRepaint();return{id:ot,type:"custom",renderingMode:"3d",onAdd(S,A){E=S,l=A;const p=(k,d)=>{const F=l.createBuffer();return l.bindBuffer(k,F),l.bufferData(k,d,l.STATIC_DRAW),F};o={pos:p(l.ARRAY_BUFFER,_.positions),sphere:p(l.ARRAY_BUFFER,_.spheres),index:p(l.ELEMENT_ARRAY_BUFFER,_.indices)},U.fieldUrl&&(T=W(l,U.fieldUrl,B)),U.windUrl&&(N=W(l,U.windUrl,B)),U.patchUrl&&(n=W(l,U.patchUrl,B,{channels:1}))},onRemove(){l&&(P.forEach(({program:S})=>l.deleteProgram(S)),P.clear(),T?.release(),T=null,N?.release(),N=null,n?.release(),n=null,o&&(l.deleteBuffer(o.pos),l.deleteBuffer(o.sphere),l.deleteBuffer(o.index),o=null),E=null,l=null)},render(S,A){if(!o||U.opacity<=0)return;const p=A&&A.shaderData,k=A&&A.defaultProjectionData;if(!p||!k)return;const{program:d,attribs:F,uniforms:I}=D(p);l.useProgram(d),G(I,k);const H=E.getCenter().lat;I.elevationGlobe&&l.uniform1f(I.elevationGlobe,se),I.elevationMercator&&l.uniform1f(I.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,H],se).z),I.opacity&&l.uniform1f(I.opacity,U.opacity),I.sun&&l.uniform3f(I.sun,...U.sun),et(l,I,U,{seconds:performance.now()*.001,field:T,wind:N,patches:n},0,1,2);const b=Gt(E.getZoom(),E.getCenter().lat,Re(E,Y));I.detailFreq&&l.uniform1f(I.detailFreq,b.frequency),I.detailAmount&&l.uniform1f(I.detailAmount,b.amount),I.deckFade&&l.uniform1f(I.deckFade,b.fade),I.camera&&l.uniform3f(I.camera,...ee(E,Y));const O=y=>{I[y]&&l.uniform1f(I[y],U[y])};O("cloudRelief"),O("cloudDepth"),O("powder"),O("forward"),O("forwardG"),O("selfShadow"),O("selfShadowStep"),l.bindBuffer(l.ARRAY_BUFFER,o.pos),l.enableVertexAttribArray(F.pos),l.vertexAttribPointer(F.pos,2,l.FLOAT,!1,0,0),l.bindBuffer(l.ARRAY_BUFFER,o.sphere),l.enableVertexAttribArray(F.sphere),l.vertexAttribPointer(F.sphere,3,l.FLOAT,!1,0,0),l.enable(l.DEPTH_TEST),l.depthFunc(l.LEQUAL),l.depthMask(!1),l.bindBuffer(l.ELEMENT_ARRAY_BUFFER,o.index),l.drawElements(l.TRIANGLES,_.indices.length,l.UNSIGNED_SHORT,0),l.depthMask(!0),U.animate&&E.triggerRepaint()},setOptions(S={}){const A=U;if(U={...U,...S},l){const p=(k,d)=>{if(S[d]===void 0||S[d]===A[d])return k;k?.release();const F=d==="patchUrl"?{channels:1}:void 0;return U[d]?W(l,U[d],B,F):null};T=p(T,"fieldUrl"),n=p(n,"patchUrl")}E&&E.triggerRepaint()},getOptions:()=>({...U}),get hasField(){return!!(T?.ready||n?.ready)}}},Xt=ot,j=Math.PI/180,Yt=Date.UTC(2e3,0,1,12),_e=1/3600,rt=e=>(e.getTime()-Yt)/864e5,jt=(e=new Date)=>((18.697374558+24.06570982441908*rt(e))%24*15%360+360)%360,Wt=e=>{const a=rt(e)/36525;return{zeta:(2306.2181*a+.30188*a*a+.017998*a*a*a)*_e,z:(2306.2181*a+1.09468*a*a+.018203*a*a*a)*_e,theta:(2004.3109*a-.42665*a*a-.041833*a*a*a)*_e}},Kt=(e,a,i)=>{const{zeta:s,z:u,theta:m}=Wt(i),w=(e-u)*j,r=a*j,t=m*j,f=Math.cos(r)*Math.sin(w),M=Math.cos(t)*Math.cos(r)*Math.cos(w)+Math.sin(t)*Math.sin(r),x=-Math.sin(t)*Math.cos(r)*Math.cos(w)+Math.cos(t)*Math.sin(r);return{ra:Math.atan2(f,M)/j-s,dec:Math.asin(Math.min(1,Math.max(-1,x)))/j}},Ue=e=>(e%360+360)%360,Vt=(e,a=new Date)=>{const i=Math.hypot(e[0],e[1],e[2])||1,s=Math.atan2(e[2]/i,e[0]/i)/j,u=Math.asin(Math.min(1,Math.max(-1,e[1]/i)))/j,m=Kt(Ue(s+jt(a)),u,a);return{ra:Ue(m.ra),dec:m.dec}},Zt=`
  vec2 panoramaUV(vec3 skyDir) {
    return vec2(atan(skyDir.z, skyDir.x) / 6.28318530718 + 1.0,
                0.5 - asin(clamp(skyDir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,Qt=(e=new Date)=>{const a=new Float32Array(9);return[[1,0,0],[0,1,0],[0,0,1]].forEach((s,u)=>{const{ra:m,dec:w}=Vt(s,e);a[u*3]=Math.cos(w*j)*Math.cos(m*j),a[u*3+1]=Math.sin(w*j),a[u*3+2]=Math.cos(w*j)*Math.sin(m*j)}),a},Jt=Math.PI/180,le=e=>{const a=Math.hypot(e[0],e[1],e[2])||1;return[e[0]/a,e[1]/a,e[2]/a]},ea=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],Ee=(e,a)=>[e[0]-a[0],e[1]-a[1],e[2]-a[2]],De=(e,a)=>{const i=ea(e,a);return le([e[0]-a[0]*i,e[1]-a[1]*i,e[2]-a[2]*i])},ta=(e,a)=>{const s=a>89.98?-1:1,u=le(Ee(K(e,a+.01*s),K(e,a-.01*s))),m=le(Ee(K(e+.01,a),K(e-.01,a)));return{north:s===1?u:[-u[0],-u[1],-u[2]],east:m}},aa=(e,a,i,s)=>{const u=e.getCenter(),m=(e.getBearing?.()??0)*Jt,w=K(u.lng,u.lat,0),r=ee(e,a),t=le(Ee(w,r)),{north:f,east:M}=ta(u.lng,u.lat),x=[0,1,2].map(_=>f[_]*Math.cos(m)+M[_]*Math.sin(m)),g=[0,1,2].map(_=>-f[_]*Math.sin(m)+M[_]*Math.cos(m)),L=De(x,t),h=De(g,t),c=Math.tan(i/2),v=c*s;return{origin:r,forward:t,up:L.map(_=>_*c),right:h.map(_=>_*v),upUnit:L,rightUnit:h}},Te=(e,a)=>e*a*4,it=[{name:"high",width:4096,height:2048,url:"/img/map/sky/milkyway-4k.webp",bytes:3680220,resident:Te(4096,2048),decodeMs:235},{name:"standard",width:2048,height:1024,url:"/img/map/sky/milkyway-2k.webp",bytes:764012,resident:Te(2048,1024),decodeMs:51}],nt={name:"placeholder",width:1024,height:512,url:"/img/map/sky/milkyway-1k.webp",bytes:45160,resident:Te(1024,512),decodeMs:5},oa=4,ra=4,ia=({maxTextureSize:e=0,deviceMemory:a=null,hardwareConcurrency:i=null,saveData:s=!1,effectiveType:u=null}={})=>{const m=it.filter(t=>t.width<=e);if(m.length===0)return{tier:nt,reason:`MAX_TEXTURE_SIZE is ${e}, below every tier — falling back to the placeholder`};const w=m[m.length-1],r=m[0];return r===w?{tier:r,reason:`only ${r.name} fits MAX_TEXTURE_SIZE ${e}`}:s?{tier:w,reason:"the browser is in data-saver mode"}:u&&/^(slow-2g|2g|3g)$/.test(u)?{tier:w,reason:`the connection reports ${u}`}:Number.isFinite(a)&&a<oa?{tier:w,reason:`the device reports ${a} GB of memory`}:Number.isFinite(i)&&i<ra?{tier:w,reason:`the device reports ${i} cores`}:{tier:r,reason:`nothing says otherwise, and MAX_TEXTURE_SIZE is ${e}`}},na=(e,a=typeof navigator>"u"?null:navigator)=>{const i=a?.connection??null;return{maxTextureSize:e?.getParameter?.(e.MAX_TEXTURE_SIZE)??0,deviceMemory:a?.deviceMemory??null,hardwareConcurrency:a?.hardwareConcurrency??null,saveData:i?.saveData??!1,effectiveType:i?.effectiveType??null}},st="tm-starfield",sa=nt.url,la="/data/sky/bright-stars.bin",ha=e=>{const a=Number.isFinite(e)?e:5800,i=Math.min(4e4,Math.max(1e3,a))/100,s=f=>Math.min(1,Math.max(0,f/255)),u=i<=66?255:329.698727446*Math.pow(i-60,-.1332047592),m=i<=66?99.4708025861*Math.log(i)-161.1195681661:288.1221695283*Math.pow(i-60,-.0755148492),w=i>=66?255:i<=19?0:138.5177312231*Math.log(i-10)-305.0447927307,r=[s(u),s(m),s(w)],t=Math.max(...r)||1;return r.map(f=>f/t)},ca=(e,{limitMagnitude:a=6.5}={})=>{const i=new Float32Array(e),s=Math.floor(i.length/4),u=[];for(let w=0;w<s;w++){const r=i[w*4+2];if(r>a)continue;const t=Math.pow(10,-.4*r);u.push([i[w*4],i[w*4+1],Math.pow(t,.36),...ha(i[w*4+3])])}const m=new Float32Array(u.length*6);return u.forEach((w,r)=>m.set(w,r*6)),{vertices:m,count:u.length}},lt=`
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
`,ua=e=>`${e.define}
attribute vec2 a_pos;
varying vec2 v_ndc;
void main() {
  v_ndc = a_pos;
  // Straight to clip space. No projection, no elevation, no prelude — that is the whole point.
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`,da=e=>`${e.define}
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
${lt}
${Zt}

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
}`,fa=e=>`${e.define}
attribute vec3 a_star;       // right ascension and declination in degrees, then brightness
attribute vec3 a_colour;
uniform mat3 u_skyFrame;
uniform float u_pixelRatio;
uniform float u_starSize;
uniform float u_catalogueAmount;
varying vec3 v_colour;
${lt}

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
}`,ma=e=>`${e.define}
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
}`,pa=({textureUrl:e=null,placeholderUrl:a=sa,catalogueUrl:i=la,date:s=new Date,brightness:u=.55,nebula:m=.3,nebulaContrast:w=1.45,starDensity:r=210,starAmount:t=1.5,catalogueAmount:f=2.2,starSize:M=3,limitMagnitude:x=6.5,twinkle:g=0,animate:L=!1}={})=>{let h=null,c=null,v=null,_=null,E=null,l=null,o="not chosen yet",P=0;const U=new Map;let T={textureUrl:e,placeholderUrl:a,catalogueUrl:i,date:s,brightness:u,nebula:m,nebulaContrast:w,starDensity:r,starAmount:t,catalogueAmount:f,starSize:M,limitMagnitude:x,twinkle:g,animate:L};const N=S=>{const A=S.variantName;if(U.has(A))return U.get(A);const p=V(c,ua(S),da(S),"tm-starfield"),k=V(c,fa(S),ma(S),"tm-starfield-catalogue"),d=(I,H)=>c.getUniformLocation(I,H),F={sky:{program:p,pos:c.getAttribLocation(p,"a_pos"),uniforms:{camera:d(p,"u_camera"),forward:d(p,"u_forward"),right:d(p,"u_right"),up:d(p,"u_up"),halfExtent:d(p,"u_halfExtent"),skyFrame:d(p,"u_skyFrame"),sky:d(p,"u_sky"),globeness:d(p,"u_globeness"),brightness:d(p,"u_brightness"),nebula:d(p,"u_nebula"),nebulaContrast:d(p,"u_nebulaContrast"),starDensity:d(p,"u_starDensity"),starAmount:d(p,"u_starAmount"),twinkle:d(p,"u_twinkle"),time:d(p,"u_time")}},stars:{program:k,star:c.getAttribLocation(k,"a_star"),colour:c.getAttribLocation(k,"a_colour"),uniforms:{camera:d(k,"u_camera"),forward:d(k,"u_forward"),right:d(k,"u_right"),up:d(k,"u_up"),halfExtent:d(k,"u_halfExtent"),skyFrame:d(k,"u_skyFrame"),pixelRatio:d(k,"u_pixelRatio"),starSize:d(k,"u_starSize"),catalogueAmount:d(k,"u_catalogueAmount")}}};return U.set(A,F),F},n=()=>{if(!c)return;const S=(A,p)=>A?W(c,A,p,{mipmap:!1}):null;if(T.textureUrl===null){const A=ia(na(c));l=A.tier,o=A.reason}else l=it.find(A=>A.url===T.textureUrl)??{name:"explicit",url:T.textureUrl},o="the caller named a panorama";E=S(T.placeholderUrl,()=>{h?.triggerRepaint()}),_=S(l.url===T.placeholderUrl?null:l.url,()=>{E?.release(),E=null,h?.triggerRepaint()})},D=()=>_?.ready?_:E?.ready?E:null,G=()=>{_?.release(),E?.release(),_=null,E=null},B=S=>{!S||typeof fetch!="function"||fetch(S).then(A=>{if(!A.ok)throw new Error(`${A.status} ${A.statusText}`);return A.arrayBuffer()}).then(A=>{if(!c||!v)return;const{vertices:p,count:k}=ca(A,{limitMagnitude:T.limitMagnitude});c.bindBuffer(c.ARRAY_BUFFER,v.stars),c.bufferData(c.ARRAY_BUFFER,p,c.STATIC_DRAW),P=k,h?.triggerRepaint()}).catch(A=>console.warn(`[starfield] bright star catalogue unavailable: ${A.message}`))};return{id:st,type:"custom",renderingMode:"3d",onAdd(S,A){h=S,c=A;const p=(k,d)=>{const F=c.createBuffer();return c.bindBuffer(k,F),d&&c.bufferData(k,d,c.STATIC_DRAW),F};v={pos:p(c.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,1,1])),index:p(c.ELEMENT_ARRAY_BUFFER,new Uint16Array([0,1,2,2,1,3])),stars:p(c.ARRAY_BUFFER,null)},n(),B(T.catalogueUrl)},onRemove(){c&&(U.forEach(({sky:S,stars:A})=>{c.deleteProgram(S.program),c.deleteProgram(A.program)}),U.clear(),G(),v&&(c.deleteBuffer(v.pos),c.deleteBuffer(v.index),c.deleteBuffer(v.stars),v=null),h=null,c=null)},render(S,A){if(!v||T.brightness<=0)return;const p=A&&A.shaderData,k=A&&A.defaultProjectionData;if(!p||!k)return;const d=h.getCanvas(),F=d.width/Math.max(1,d.height),I=aa(h,Y,A.fov||.6435,F),H=Math.tan((A.fov||.6435)/2),b=Qt(T.date),O=k.projectionTransition,{sky:y,stars:C}=N(p),z=({uniforms:R})=>{R.camera&&c.uniform3f(R.camera,...I.origin),R.forward&&c.uniform3f(R.forward,...I.forward),R.right&&c.uniform3f(R.right,...I.rightUnit),R.up&&c.uniform3f(R.up,...I.upUnit),R.halfExtent&&c.uniform2f(R.halfExtent,H*F,H),R.skyFrame&&c.uniformMatrix3fv(R.skyFrame,!1,b)};c.useProgram(y.program),z(y),y.uniforms.globeness&&c.uniform1f(y.uniforms.globeness,O),y.uniforms.brightness&&c.uniform1f(y.uniforms.brightness,T.brightness);const $=D();y.uniforms.nebula&&c.uniform1f(y.uniforms.nebula,$?T.nebula:0),y.uniforms.nebulaContrast&&c.uniform1f(y.uniforms.nebulaContrast,T.nebulaContrast),y.uniforms.starDensity&&c.uniform1f(y.uniforms.starDensity,T.starDensity),y.uniforms.starAmount&&c.uniform1f(y.uniforms.starAmount,T.starAmount),y.uniforms.twinkle&&c.uniform1f(y.uniforms.twinkle,T.twinkle),y.uniforms.time&&c.uniform1f(y.uniforms.time,T.animate?performance.now()*.001:0),$&&y.uniforms.sky&&(c.activeTexture(c.TEXTURE0),c.bindTexture(c.TEXTURE_2D,$.texture),c.uniform1i(y.uniforms.sky,0)),c.bindBuffer(c.ARRAY_BUFFER,v.pos),c.enableVertexAttribArray(y.pos),c.vertexAttribPointer(y.pos,2,c.FLOAT,!1,0,0),c.disable(c.DEPTH_TEST),c.depthMask(!1),c.bindBuffer(c.ELEMENT_ARRAY_BUFFER,v.index),c.drawElements(c.TRIANGLES,6,c.UNSIGNED_SHORT,0),P>0&&O>.5&&T.catalogueAmount>0&&(c.useProgram(C.program),z(C),C.uniforms.pixelRatio&&c.uniform1f(C.uniforms.pixelRatio,typeof devicePixelRatio=="number"?devicePixelRatio:1),C.uniforms.starSize&&c.uniform1f(C.uniforms.starSize,T.starSize),C.uniforms.catalogueAmount&&c.uniform1f(C.uniforms.catalogueAmount,T.catalogueAmount*T.brightness),c.bindBuffer(c.ARRAY_BUFFER,v.stars),c.enableVertexAttribArray(C.star),c.vertexAttribPointer(C.star,3,c.FLOAT,!1,24,0),c.enableVertexAttribArray(C.colour),c.vertexAttribPointer(C.colour,3,c.FLOAT,!1,24,12),c.drawArrays(c.POINTS,0,P),c.disableVertexAttribArray(C.star),c.disableVertexAttribArray(C.colour)),c.enable(c.DEPTH_TEST),c.depthMask(!0),T.animate&&T.twinkle>0&&h.triggerRepaint()},setOptions(S={}){const A=T.textureUrl,p=T.catalogueUrl;T={...T,...S},S.textureUrl!==void 0&&S.textureUrl!==A&&(G(),n()),S.catalogueUrl!==void 0&&S.catalogueUrl!==p&&(P=0,B(S.catalogueUrl)),h?.triggerRepaint()},getOptions:()=>({...T}),get hasSky(){return D()!==null},get starCount(){return P},get skyTier(){return l?{...l,reason:o}:null}}},ga=st,Q=Math.PI/180,wa=Date.UTC(2e3,0,1,12),_a=149597870700,va=6957e5,ht=e=>(e.getTime()-wa)/864e5,ct=(e=new Date)=>{const a=ht(e),i=(280.46+.9856474*a)%360,s=(357.528+.9856003*a)%360*Q,u=(i+1.915*Math.sin(s)+.02*Math.sin(2*s))*Q,m=(23.439-4e-7*a)*Q,w=Math.asin(Math.sin(m)*Math.sin(u))/Q;let r=Math.atan2(Math.cos(m)*Math.sin(u),Math.cos(u))/Q;r<0&&(r+=360);let t=i-r;t>180&&(t-=360),t<-180&&(t+=360),t*=4;const f=(1.00014-.01671*Math.cos(s)-14e-5*Math.cos(2*s))*_a;return{declination:w,equationOfTime:t,distance:f}},ut=(e=new Date)=>ct(e).distance,ba=(e=new Date)=>Math.atan(va/ut(e)),ya=(e=new Date)=>{const a=(357.528+.9856003*ht(e))%360*Q;return(1.00014-.01671*Math.cos(a)-14e-5*Math.cos(2*a))*149597870700},Ea=(e=new Date)=>{const{declination:a,equationOfTime:i}=ct(e);let u=-15*(e.getUTCHours()+e.getUTCMinutes()/60+e.getUTCSeconds()/3600-12+i/60);return u=(u+540)%360-180,{lng:u,lat:a}},he=(e=new Date)=>{const{lng:a,lat:i}=Ea(e),s=i*Q,u=a*Q;return[Math.cos(s)*Math.cos(u),Math.sin(s),Math.cos(s)*Math.sin(u)]},dt="tm-sun",Pe=63710088e-1,Ta=18,xa=(e,a,i)=>{const s=Math.abs(e),u=a,m=i;if(u<=0)return 0;if(s>=u+m)return 1;if(s<=m-u)return 0;if(s<=u-m)return 1-m*m/(u*u);const w=u*u*Math.acos(te((s*s+u*u-m*m)/(2*s*u),-1,1))+m*m*Math.acos(te((s*s+m*m-u*u)/(2*s*m),-1,1))-.5*Math.sqrt(Math.max(0,(-s+u+m)*(s+u-m)*(s-u+m)*(s+u+m)));return te(1-w/(Math.PI*u*u),0,1)},Ie={u1:.93,u2:-.23},Fe=e=>e<0?`(${e.toFixed(4)})`:e.toFixed(4),Aa=`
  float limbDarkening(float rho) {
    float mu = sqrt(max(1.0 - rho * rho, 0.0));
    float t = 1.0 - mu;
    return max(1.0 - ${Fe(Ie.u1)} * t - ${Fe(Ie.u2)} * t * t, 0.0);
  }
`,Ra=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Ce=new Uint16Array([0,1,2,1,3,2]),Ma=e=>`${e.vertexShaderPrelude}
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
}`,ka=()=>`precision highp float;
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
${Ke}
${Aa}

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
}`,Sa=({date:e=new Date,haloScale:a=Ta,haloStrength:i=1,discGain:s=1.15,brightness:u=1,coreColour:m=[1,.985,.95],haloColour:w=[1,.65,.2]}={})=>{let r=null,t=null,f=null,M={u:.5,v:.5,onScreen:!1,visible:0};const x=new Map;let g={date:e,haloScale:a,haloStrength:i,discGain:s,brightness:u,coreColour:m,haloColour:w};const L=h=>{const c=h.variantName;if(x.has(c))return x.get(c);const v=V(t,Ma(h),ka(),"tm-sun"),_={program:v,attribs:{corner:t.getAttribLocation(v,"a_corner")},uniforms:{centre:t.getUniformLocation(v,"a_centre"),elevationGlobe:t.getUniformLocation(v,"a_elevation_globe"),elevationMercator:t.getUniformLocation(v,"a_elevation_mercator"),size:t.getUniformLocation(v,"u_size"),camera:t.getUniformLocation(v,"u_camera"),forward:t.getUniformLocation(v,"u_forward"),right:t.getUniformLocation(v,"u_right"),up:t.getUniformLocation(v,"u_up"),glowAngle:t.getUniformLocation(v,"u_glow_angle"),discFraction:t.getUniformLocation(v,"u_disc_fraction"),discGain:t.getUniformLocation(v,"u_disc_gain"),visible:t.getUniformLocation(v,"u_visible"),brightness:t.getUniformLocation(v,"u_brightness"),haloStrength:t.getUniformLocation(v,"u_halo_strength"),coreColour:t.getUniformLocation(v,"u_core_colour"),haloColour:t.getUniformLocation(v,"u_halo_colour"),matrix:t.getUniformLocation(v,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(v,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(v,"u_projection_clipping_plane"),transition:t.getUniformLocation(v,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(v,"u_projection_fallback_matrix")}};return x.set(c,_),_};return{id:dt,type:"custom",renderingMode:"3d",onAdd(h,c){r=h,t=c;const v=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,v),t.bufferData(t.ARRAY_BUFFER,Ra,t.STATIC_DRAW);const _=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,_),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Ce,t.STATIC_DRAW),f={corner:v,index:_}},onRemove(){t&&(x.forEach(({program:h})=>t.deleteProgram(h)),x.clear(),f&&(t.deleteBuffer(f.corner),t.deleteBuffer(f.index),f=null),r=null,t=null)},render(h,c){if(!f||g.brightness<=0)return;const v=c&&c.shaderData,_=c&&c.defaultProjectionData;if(!v||!_)return;const{program:E,attribs:l,uniforms:o}=L(v);t.useProgram(E),o.matrix&&t.uniformMatrix4fv(o.matrix,!1,_.mainMatrix),o.tileMercatorCoords&&t.uniform4f(o.tileMercatorCoords,..._.tileMercatorCoords),o.clippingPlane&&t.uniform4f(o.clippingPlane,..._.clippingPlane),o.transition&&t.uniform1f(o.transition,_.projectionTransition),o.fallbackMatrix&&t.uniformMatrix4fv(o.fallbackMatrix,!1,_.fallbackMatrix);const P=ee(r,Y),U=ut(g.date),T=K(...Da(he(g.date)),U-Pe),N=Ne([T[0]-P[0],T[1]-P[1],T[2]-P[2]]),n=Math.hypot(...P),D=Math.max(.25*(n-1),.002),G=[P[0]+N[0]*D,P[1]+N[1]*D,P[2]+N[2]*D],B=Math.hypot(...G),S=Math.asin(G[1]/B)*180/Math.PI,A=Math.atan2(G[2],G[0])*180/Math.PI,p=(B-1)*Pe,k=Y.MercatorCoordinate.fromLngLat([A,S],0);o.centre&&t.uniform2f(o.centre,k.x,k.y),o.elevationGlobe&&t.uniform1f(o.elevationGlobe,p),o.elevationMercator&&t.uniform1f(o.elevationMercator,Y.MercatorCoordinate.fromLngLat([A,S],p).z);const d=ba(g.date),F=d*g.haloScale,I=(c.fov||.6435)/2,H=r.getCanvas(),b=Math.tan(F)/Math.tan(I);o.size&&t.uniform2f(o.size,b*(H.height/H.width),b),o.glowAngle&&t.uniform1f(o.glowAngle,F),o.discFraction&&t.uniform1f(o.discFraction,1/g.haloScale),o.discGain&&t.uniform1f(o.discGain,g.discGain);const O=[-P[0]/n,-P[1]/n,-P[2]/n],y=Math.acos(te(Ua(O,N),-1,1)),C=Math.asin(te(1/n,-1,1)),z=xa(y,d,C),$=N,R=Ne(Oe([0,1,0],$)),Z=Oe($,R);o.forward&&t.uniform3f(o.forward,...$),o.right&&t.uniform3f(o.right,...R),o.up&&t.uniform3f(o.up,...Z),o.camera&&t.uniform3f(o.camera,...P),o.visible&&t.uniform1f(o.visible,z),o.brightness&&t.uniform1f(o.brightness,g.brightness),o.haloStrength&&t.uniform1f(o.haloStrength,g.haloStrength),o.coreColour&&t.uniform3f(o.coreColour,...g.coreColour),o.haloColour&&t.uniform3f(o.haloColour,...g.haloColour),t.bindBuffer(t.ARRAY_BUFFER,f.corner),t.enableVertexAttribArray(l.corner),t.vertexAttribPointer(l.corner,2,t.FLOAT,!1,0,0),t.disable(t.DEPTH_TEST),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,f.index),t.drawElements(t.TRIANGLES,Ce.length,t.UNSIGNED_SHORT,0),t.depthMask(!0),M=La(_.mainMatrix,k,B,z)},setOptions(h={}){g={...g,...h},r?.triggerRepaint()},getOptions:()=>({...g}),get screenPosition(){return M}}},La=(e,a,i,s)=>{const u=a.x*2*Math.PI+Math.PI,m=2*Math.atan(Math.exp(Math.PI-a.y*2*Math.PI))-Math.PI/2,w=Math.cos(m),r=[Math.sin(u)*w*i,Math.sin(m)*i,Math.cos(u)*w*i,1],t=[0,1,2,3].map(x=>e[x]*r[0]+e[4+x]*r[1]+e[8+x]*r[2]+e[12+x]*r[3]);if(!(t[3]>0))return{u:.5,v:.5,onScreen:!1,visible:0};const f=t[0]/t[3]*.5+.5,M=t[1]/t[3]*.5+.5;return{u:f,v:M,onScreen:f>-.5&&f<1.5&&M>-.5&&M<1.5,visible:s}},te=(e,a,i)=>Math.min(i,Math.max(a,e)),Ua=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],Ne=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},Oe=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],Da=([e,a,i])=>[Math.atan2(i,e)*180/Math.PI,Math.asin(te(a,-1,1))*180/Math.PI],Pa=dt,ft="tm-moon",ce=63710088e-1,Ia=1737400,J=Math.PI/180,Fa=Date.UTC(2e3,0,1,12),Ca=Date.UTC(1999,11,31,0),q=e=>Math.sin(e*J),X=e=>Math.cos(e*J),mt=(e=new Date)=>{const a=(e.getTime()-Fa)/864e5,i=(e.getTime()-Ca)/864e5,s=125.1228-.0529538083*i,u=5.1454,m=318.0634+.1643573223*i,w=60.2666,r=.0549,t=115.3654+13.0649929509*i;let f=t+r*180/Math.PI*q(t)*(1+r*X(t));for(let d=0;d<3;d++)f-=(f-r*180/Math.PI*q(f)-t)/(1-r*X(f));const M=w*(X(f)-r),x=w*(Math.sqrt(1-r*r)*q(f)),g=Math.atan2(x,M)/J,L=Math.sqrt(M*M+x*x);let h=L*(X(s)*X(g+m)-q(s)*q(g+m)*X(u)),c=L*(q(s)*X(g+m)+X(s)*q(g+m)*X(u)),v=L*(q(g+m)*q(u));const _=356.047+.9856002585*i,E=282.9404+470935e-10*i+_,l=s+m+t,o=l-E,P=l-s;let U=Math.atan2(c,h)/J,T=Math.atan2(v,Math.hypot(h,c))/J;U+=-1.274*q(t-2*o)+.658*q(2*o)-.186*q(_),T+=-.173*q(P-2*o);const N=(L-.58*X(t-2*o)-.46*X(2*o))*ce,n=23.4393-3563e-10*a,D=X(U)*X(T),G=q(U)*X(T)*X(n)-q(T)*q(n),B=q(U)*X(T)*q(n)+q(T)*X(n),S=Math.atan2(G,D)/J,A=Math.atan2(B,Math.hypot(D,G))/J,p=(18.697374558+24.06570982441908*a)%24;let k=S-p*15;return k=(k%360+540)%360-180,{lng:k,lat:A,distance:N}},pt=(e=new Date)=>{const{lng:a,lat:i,distance:s}=mt(e);return K(a,i,s-ce)},Na=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Be=new Uint16Array([0,1,2,1,3,2]),Oa=e=>`${e.vertexShaderPrelude}
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
}`,Ba=()=>`precision highp float;
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
}`,Ga=({date:e=new Date,sun:a=[1,0,0],albedoUrl:i=null,sizeScale:s=1,brightness:u=1,glow:m=.4,glowExtent:w=1.8}={})=>{let r=null,t=null,f=null,M=null,x=!1;const g=new Map;let L={date:e,sun:a,albedoUrl:i,sizeScale:s,brightness:u,glow:m,glowExtent:w};const h=v=>{const _=v.variantName;if(g.has(_))return g.get(_);const E=V(t,Oa(v),Ba(),"tm-moon"),l={program:E,attribs:{corner:t.getAttribLocation(E,"a_corner")},uniforms:{centre:t.getUniformLocation(E,"a_centre"),elevationGlobe:t.getUniformLocation(E,"a_elevation_globe"),elevationMercator:t.getUniformLocation(E,"a_elevation_mercator"),size:t.getUniformLocation(E,"u_size"),albedo:t.getUniformLocation(E,"u_albedo"),hasAlbedo:t.getUniformLocation(E,"u_hasAlbedo"),sun:t.getUniformLocation(E,"u_sun"),right:t.getUniformLocation(E,"u_right"),up:t.getUniformLocation(E,"u_up"),forward:t.getUniformLocation(E,"u_forward"),brightness:t.getUniformLocation(E,"u_brightness"),discEdge:t.getUniformLocation(E,"u_discEdge"),glow:t.getUniformLocation(E,"u_glow"),glowExtent:t.getUniformLocation(E,"u_glowExtent"),matrix:t.getUniformLocation(E,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(E,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(E,"u_projection_clipping_plane"),transition:t.getUniformLocation(E,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(E,"u_projection_fallback_matrix")}};return g.set(_,l),l},c=v=>{const _=new Image;_.crossOrigin="anonymous",_.onload=()=>{t&&(M=M||t.createTexture(),t.bindTexture(t.TEXTURE_2D,M),t.texImage2D(t.TEXTURE_2D,0,t.RGBA,t.RGBA,t.UNSIGNED_BYTE,_),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_S,t.REPEAT),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_T,t.CLAMP_TO_EDGE),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MIN_FILTER,t.LINEAR),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MAG_FILTER,t.LINEAR),x=!0,r?.triggerRepaint())},_.src=v};return{id:ft,type:"custom",renderingMode:"3d",onAdd(v,_){r=v,t=_;const E=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,E),t.bufferData(t.ARRAY_BUFFER,Na,t.STATIC_DRAW);const l=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,l),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Be,t.STATIC_DRAW),f={corner:E,index:l},L.albedoUrl&&c(L.albedoUrl)},onRemove(){t&&(g.forEach(({program:v})=>t.deleteProgram(v)),g.clear(),M&&(t.deleteTexture(M),M=null,x=!1),f&&(t.deleteBuffer(f.corner),t.deleteBuffer(f.index),f=null),r=null,t=null)},render(v,_){if(!f||L.brightness<=0)return;const E=_&&_.shaderData,l=_&&_.defaultProjectionData;if(!E||!l)return;const{lng:o,lat:P,distance:U}=mt(L.date),{program:T,attribs:N,uniforms:n}=h(E);t.useProgram(T),n.matrix&&t.uniformMatrix4fv(n.matrix,!1,l.mainMatrix),n.tileMercatorCoords&&t.uniform4f(n.tileMercatorCoords,...l.tileMercatorCoords),n.clippingPlane&&t.uniform4f(n.clippingPlane,...l.clippingPlane),n.transition&&t.uniform1f(n.transition,l.projectionTransition),n.fallbackMatrix&&t.uniformMatrix4fv(n.fallbackMatrix,!1,l.fallbackMatrix);const D=ee(r,Y),G=K(o,P,U-ce),B=Ge([G[0]-D[0],G[1]-D[1],G[2]-D[2]]),A=Math.hypot(...D)+1.2,p=[D[0]+B[0]*A,D[1]+B[1]*A,D[2]+B[2]*A],k=Math.hypot(...p),d=Math.asin(p[1]/k)*180/Math.PI,F=Math.atan2(p[2],p[0])*180/Math.PI,I=(k-1)*ce,H=Y.MercatorCoordinate.fromLngLat([F,d],0);n.centre&&t.uniform2f(n.centre,H.x,H.y),n.elevationGlobe&&t.uniform1f(n.elevationGlobe,I),n.elevationMercator&&t.uniform1f(n.elevationMercator,Y.MercatorCoordinate.fromLngLat([F,d],I).z);const b=Math.atan(Ia*L.sizeScale/U),O=(_.fov||.6435)/2,y=r.getCanvas(),C=Math.tan(b)/Math.tan(O),$=1+(L.glow>0?Math.max(0,L.glowExtent):0),R=C*$;n.size&&t.uniform2f(n.size,R*(y.height/y.width),R),n.discEdge&&t.uniform1f(n.discEdge,1/$);const Z=B,ae=Ge(He([0,1,0],Z)),pe=He(Z,ae);n.forward&&t.uniform3f(n.forward,...Z),n.right&&t.uniform3f(n.right,...ae),n.up&&t.uniform3f(n.up,...pe),n.sun&&t.uniform3f(n.sun,...L.sun),n.brightness&&t.uniform1f(n.brightness,L.brightness),n.glow&&t.uniform1f(n.glow,L.glow),n.glowExtent&&t.uniform1f(n.glowExtent,L.glowExtent),n.hasAlbedo&&t.uniform1f(n.hasAlbedo,x?1:0),x&&n.albedo&&(t.activeTexture(t.TEXTURE0),t.bindTexture(t.TEXTURE_2D,M),t.uniform1i(n.albedo,0)),t.bindBuffer(t.ARRAY_BUFFER,f.corner),t.enableVertexAttribArray(N.corner),t.vertexAttribPointer(N.corner,2,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,f.index),t.drawElements(t.TRIANGLES,Be.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(v={}){const _=L.albedoUrl;L={...L,...v},v.albedoUrl!==void 0&&v.albedoUrl!==_&&(x=!1,v.albedoUrl&&c(v.albedoUrl)),r?.triggerRepaint()},getOptions:()=>({...L}),get hasAlbedo(){return x}}},Ge=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},He=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],Ha=ft,za=6957e5,$a=1737400,qa=63710088e-1,Xa=$a/qa,Ya=(e,a,i)=>Math.min(i,Math.max(a,e)),ja=(e=new Date)=>Math.asin(za/ya(e)),Wa=.035,Ka=(e=new Date,a=null,i=null)=>{const s=a||he(e),u=i||pt(e),m=Math.hypot(...u);return Math.acos(Ya((s[0]*u[0]+s[1]*u[1]+s[2]*u[2])/m,-1,1))<Wa},Va=`
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
`,gt="tm-daylight",oe={field:0,wind:1,relief:2,lights:3,patches:4},Za=e=>`#version 300 es
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
}`,Qa=()=>`#version 300 es
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
${Ve}
${Ae}
${tt}
${at}
${Ft}
${Va}
// The same field, wind and clock the deck in clouds.js draws itself from.
${Qe}

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
    float remaining = eclipseLight(normal, sunDir, u_moon, u_sunRadius, ${Xa.toFixed(8)});
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
}`,Ja=({sun:e=[1,0,0],nightDarkness:a=.965,lightsUrl:i=null,lightsAmount:s=0,nightColour:u=[.02,.035,.07],twilightColour:m=[.62,.32,.16],twilightCool:w=[.1,.15,.28],twilightStrength:r=.55,reliefUrl:t=null,reliefWidth:f=8192,reliefPower:M=1.5,cloudShadow:x=.5,shadowSoftness:g=.75,eclipse:L=!0,date:h=null,fieldUrl:c=null,patchUrl:v=null,windUrl:_=null,windAmount:E=1,windScale:l=.06,windRate:o=.05,driftRate:P=4e-4,animate:U=!0}={})=>{const T=de();let N=null,n=null,D=null,G=null,B=null,S=null,A=null,p=null;const k=new Map;let d={sun:e,nightDarkness:a,lightsUrl:i,lightsAmount:s,nightColour:u,twilightColour:m,twilightCool:w,twilightStrength:r,reliefUrl:t,reliefWidth:f,reliefPower:M,cloudShadow:x,eclipse:L,date:h,shadowSoftness:g,fieldUrl:c,patchUrl:v,windUrl:_,windAmount:E,windScale:l,windRate:o,driftRate:P,animate:U};const F=b=>{const O=b.variantName;if(k.has(O))return k.get(O);const y=V(n,Za(b),Qa(),"tm-daylight"),C={program:y,attribs:{pos:n.getAttribLocation(y,"a_pos"),sphere:n.getAttribLocation(y,"a_sphere")},uniforms:{elevationGlobe:n.getUniformLocation(y,"a_elevation_globe"),elevationMercator:n.getUniformLocation(y,"a_elevation_mercator"),sun:n.getUniformLocation(y,"u_sun"),camera:n.getUniformLocation(y,"u_camera"),globeness:n.getUniformLocation(y,"u_globeness"),nightDarkness:n.getUniformLocation(y,"u_nightDarkness"),nightColour:n.getUniformLocation(y,"u_nightColour"),twilightColour:n.getUniformLocation(y,"u_twilightColour"),twilightCool:n.getUniformLocation(y,"u_twilightCool"),twilightStrength:n.getUniformLocation(y,"u_twilightStrength"),lights:n.getUniformLocation(y,"u_lights"),lightsAmount:n.getUniformLocation(y,"u_lightsAmount"),relief:n.getUniformLocation(y,"u_relief"),reliefPower:n.getUniformLocation(y,"u_reliefPower"),cloudShadow:n.getUniformLocation(y,"u_cloudShadow"),shadowSoftness:n.getUniformLocation(y,"u_shadowSoftness"),cloudAltitude:n.getUniformLocation(y,"u_cloudAltitude"),moon:n.getUniformLocation(y,"u_moon"),sunRadius:n.getUniformLocation(y,"u_sunRadius"),...Object.fromEntries(Je.map(z=>[z,n.getUniformLocation(y,`u_${z}`)])),matrix:n.getUniformLocation(y,"u_projection_matrix"),tileMercatorCoords:n.getUniformLocation(y,"u_projection_tile_mercator_coords"),clippingPlane:n.getUniformLocation(y,"u_projection_clipping_plane"),transition:n.getUniformLocation(y,"u_projection_transition"),fallbackMatrix:n.getUniformLocation(y,"u_projection_fallback_matrix")}};return k.set(O,C),C},I=()=>N?.triggerRepaint(),H=(b,O,y,C)=>{!O?.ready||!b[y]||(n.activeTexture(n.TEXTURE0+C),n.bindTexture(n.TEXTURE_2D,O.texture),n.uniform1i(b[y],C))};return{id:gt,type:"custom",renderingMode:"3d",onAdd(b,O){N=b,n=O;const y=(C,z)=>{const $=n.createBuffer();return n.bindBuffer(C,$),n.bufferData(C,z,n.STATIC_DRAW),$};D={pos:y(n.ARRAY_BUFFER,T.positions),sphere:y(n.ARRAY_BUFFER,T.spheres),index:y(n.ELEMENT_ARRAY_BUFFER,T.indices)},d.lightsUrl&&(G=W(n,d.lightsUrl,I)),d.reliefUrl&&(B=W(n,d.reliefUrl,I)),d.fieldUrl&&(S=W(n,d.fieldUrl,I)),d.windUrl&&(A=W(n,d.windUrl,I)),d.patchUrl&&(p=W(n,d.patchUrl,I,{channels:1}))},onRemove(){n&&(k.forEach(({program:b})=>n.deleteProgram(b)),k.clear(),G?.release(),G=null,B?.release(),B=null,S?.release(),S=null,A?.release(),A=null,p?.release(),p=null,D&&(n.deleteBuffer(D.pos),n.deleteBuffer(D.sphere),n.deleteBuffer(D.index),D=null),N=null,n=null)},render(b,O){if(!D||d.nightDarkness<=0)return;const y=O&&O.shaderData,C=O&&O.defaultProjectionData;if(!y||!C)return;const{program:z,attribs:$,uniforms:R}=F(y);n.useProgram(z),R.matrix&&n.uniformMatrix4fv(R.matrix,!1,C.mainMatrix),R.tileMercatorCoords&&n.uniform4f(R.tileMercatorCoords,...C.tileMercatorCoords),R.clippingPlane&&n.uniform4f(R.clippingPlane,...C.clippingPlane),R.transition&&n.uniform1f(R.transition,C.projectionTransition),R.fallbackMatrix&&n.uniformMatrix4fv(R.fallbackMatrix,!1,C.fallbackMatrix);const Z=N.getCenter().lat,ae=0;R.elevationGlobe&&n.uniform1f(R.elevationGlobe,ae),R.elevationMercator&&n.uniform1f(R.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,Z],ae).z),R.sun&&n.uniform3f(R.sun,...d.sun),R.camera&&n.uniform3f(R.camera,...ee(N,Y)),R.globeness&&n.uniform1f(R.globeness,C.projectionTransition),R.nightDarkness&&n.uniform1f(R.nightDarkness,d.nightDarkness),R.nightColour&&n.uniform3f(R.nightColour,...d.nightColour),R.twilightColour&&n.uniform3f(R.twilightColour,...d.twilightColour),R.twilightCool&&n.uniform3f(R.twilightCool,...d.twilightCool),R.twilightStrength&&n.uniform1f(R.twilightStrength,d.twilightStrength),R.lightsAmount&&n.uniform1f(R.lightsAmount,G?.ready?d.lightsAmount:0);const pe=B?.ready?Bt(N.getZoom(),Z,d.reliefWidth).strength:0;R.reliefPower&&n.uniform1f(R.reliefPower,d.reliefPower*pe),R.cloudShadow&&n.uniform1f(R.cloudShadow,d.cloudShadow),R.shadowSoftness&&n.uniform1f(R.shadowSoftness,d.shadowSoftness),R.cloudAltitude&&n.uniform1f(R.cloudAltitude,se/ue);const ne=d.eclipse?d.date:null,ge=ne&&Ka(ne)?pt(ne):null;R.sunRadius&&n.uniform1f(R.sunRadius,ge?ja(ne):0),ge&&R.moon&&n.uniform3f(R.moon,...ge),et(n,R,d,{seconds:performance.now()*.001,field:S,wind:A,patches:p},oe.field,oe.wind,oe.patches),H(R,B,"relief",oe.relief),H(R,G,"lights",oe.lights),n.bindBuffer(n.ARRAY_BUFFER,D.pos),n.enableVertexAttribArray($.pos),n.vertexAttribPointer($.pos,2,n.FLOAT,!1,0,0),n.bindBuffer(n.ARRAY_BUFFER,D.sphere),n.enableVertexAttribArray($.sphere),n.vertexAttribPointer($.sphere,3,n.FLOAT,!1,0,0),n.disable(n.DEPTH_TEST),n.depthMask(!1),n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,D.index),n.drawElements(n.TRIANGLES,T.indices.length,n.UNSIGNED_SHORT,0),n.enable(n.DEPTH_TEST),n.depthMask(!0),d.animate&&(S?.ready||p?.ready)&&d.cloudShadow>0&&N.triggerRepaint()},setOptions(b={}){const O=d;if(d={...d,...b},!n){N?.triggerRepaint();return}const y=(C,z)=>{if(b[z]===void 0||b[z]===O[z])return C;C?.release();const $=z==="patchUrl"?{channels:1}:void 0;return d[z]?W(n,d[z],I,$):null};G=y(G,"lightsUrl"),B=y(B,"reliefUrl"),S=y(S,"fieldUrl"),A=y(A,"windUrl"),p=y(p,"patchUrl"),N?.triggerRepaint()},getOptions:()=>({...d}),get hasLights(){return!!G?.ready},get hasRelief(){return!!B?.ready}}},eo=gt,wt="tm-atmosphere",to=63710088e-1,ve=2e5,ao=e=>`${e.vertexShaderPrelude}
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
}`,oo=()=>`precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space (earth = unit sphere)
uniform vec3 u_sun;         // direction TO the sun
uniform float u_top;        // top of the atmosphere, in earth radii
uniform float u_strength;
uniform vec3 u_dayColour;
uniform vec3 u_duskColour;
uniform float u_forward;      // how much of the forward-scattering lobe to keep, 0 = none
uniform float u_scatter_g;    // its narrowness; 0 is uniform, 0.9 is a searchlight

const int SAMPLES = 6;

${Ke}

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

  // FORWARD SCATTERING, which is why haze is not equally bright all the way round.
  //
  // Air does not scatter light evenly in all directions. Particles comparable to the wavelength
  // throw most of it very nearly FORWARD, so looking through haze toward the sun is many times
  // brighter than looking through the same haze away from it. It is why a windscreen you cannot see
  // through driving west is perfectly clear driving east, and on a globe it is why the limb beside
  // the sun blazes while the far limb stays a thin cold line.
  //
  // Henyey-Greenstein, the standard one-parameter phase function. Normalised at right angles so
  // u_forward = 0 is exactly the old look and the term only ever adds. Clamped because the true
  // lobe runs to 25x within a couple of degrees of the sun's centre, where the disc is drawn over
  // it anyway — uncapped it produces a white blob on the limb rather than a bright limb.
  float cosTheta = dot(ray, u_sun);
  float g = u_scatter_g;
  float phase = (1.0 - g * g) / pow(1.0 + g * g - 2.0 * g * cosTheta, 1.5);
  float sideways = (1.0 - g * g) / pow(1.0 + g * g, 1.5);
  float boost = u_forward * min(phase / sideways - 1.0, 5.0);

  // Bright haze is WHITE haze. Scattering that strong is light that has bounced several times, and
  // multiple scattering washes the colour out — which is why a hazy sun is a white glare and not a
  // saturated blue one. Without this the boost merely amplifies the existing day/dusk ramp, and
  // since that ramp runs from orange to blue the amplified overlap lands on magenta: a rainbow
  // fringe around the limb that looks exactly like chromatic aberration and is nothing of the sort.
  colour = mix(colour, vec3(1.0), clamp(boost * 0.2, 0.0, 0.7));
  alpha *= 1.0 + boost;

  // Alpha ABOVE ONE is the other half of that bug. The output is premultiplied, so an alpha of 1.6
  // writes a colour brighter than its own coverage; the channels then clip one at a time and the
  // hue shifts as it saturates rather than simply going white.
  alpha = min(alpha, 1.0);

  if (alpha < 0.002) discard;
  gl_FragColor = vec4(colour * alpha, alpha);
}`,ro=({strength:e=1,sun:a=[.4,.5,.75],dayColour:i=[.32,.55,1],duskColour:s=[1,.45,.18],forwardScatter:u=.35,scatterG:m=.6}={})=>{const w=de();let r=null,t=null,f=null;const M=new Map;let x={strength:e,sun:a,dayColour:i,duskColour:s,forwardScatter:u,scatterG:m};const g=L=>{const h=L.variantName;if(M.has(h))return M.get(h);const c=V(t,ao(L),oo(),"tm-atmosphere"),v={program:c,attribs:{pos:t.getAttribLocation(c,"a_pos"),sphere:t.getAttribLocation(c,"a_sphere")},uniforms:{elevationGlobe:t.getUniformLocation(c,"a_elevation_globe"),elevationMercator:t.getUniformLocation(c,"a_elevation_mercator"),camera:t.getUniformLocation(c,"u_camera"),sun:t.getUniformLocation(c,"u_sun"),top:t.getUniformLocation(c,"u_top"),strength:t.getUniformLocation(c,"u_strength"),dayColour:t.getUniformLocation(c,"u_dayColour"),duskColour:t.getUniformLocation(c,"u_duskColour"),forward:t.getUniformLocation(c,"u_forward"),scatterG:t.getUniformLocation(c,"u_scatter_g"),matrix:t.getUniformLocation(c,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(c,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(c,"u_projection_clipping_plane"),transition:t.getUniformLocation(c,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(c,"u_projection_fallback_matrix")}};return M.set(h,v),v};return{id:wt,type:"custom",renderingMode:"3d",onAdd(L,h){r=L,t=h;const c=(v,_)=>{const E=t.createBuffer();return t.bindBuffer(v,E),t.bufferData(v,_,t.STATIC_DRAW),E};f={pos:c(t.ARRAY_BUFFER,w.positions),sphere:c(t.ARRAY_BUFFER,w.spheres),index:c(t.ELEMENT_ARRAY_BUFFER,w.indices)}},onRemove(){t&&(M.forEach(({program:L})=>t.deleteProgram(L)),M.clear(),f&&(t.deleteBuffer(f.pos),t.deleteBuffer(f.sphere),t.deleteBuffer(f.index),f=null),r=null,t=null)},render(L,h){if(!f||x.strength<=0)return;const c=h&&h.shaderData,v=h&&h.defaultProjectionData;if(!c||!v)return;const{program:_,attribs:E,uniforms:l}=g(c);t.useProgram(_),l.matrix&&t.uniformMatrix4fv(l.matrix,!1,v.mainMatrix),l.tileMercatorCoords&&t.uniform4f(l.tileMercatorCoords,...v.tileMercatorCoords),l.clippingPlane&&t.uniform4f(l.clippingPlane,...v.clippingPlane),l.transition&&t.uniform1f(l.transition,v.projectionTransition),l.fallbackMatrix&&t.uniformMatrix4fv(l.fallbackMatrix,!1,v.fallbackMatrix);const o=r.getCenter().lat;l.elevationGlobe&&t.uniform1f(l.elevationGlobe,ve),l.elevationMercator&&t.uniform1f(l.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,o],ve).z);const P=ee(r,Y);l.camera&&t.uniform3f(l.camera,P[0],P[1],P[2]),l.sun&&t.uniform3f(l.sun,...x.sun),l.top&&t.uniform1f(l.top,1+ve/to),l.strength&&t.uniform1f(l.strength,x.strength),l.dayColour&&t.uniform3f(l.dayColour,...x.dayColour),l.duskColour&&t.uniform3f(l.duskColour,...x.duskColour),l.forward&&t.uniform1f(l.forward,x.forwardScatter),l.scatterG&&t.uniform1f(l.scatterG,x.scatterG),t.bindBuffer(t.ARRAY_BUFFER,f.pos),t.enableVertexAttribArray(E.pos),t.vertexAttribPointer(E.pos,2,t.FLOAT,!1,0,0),t.bindBuffer(t.ARRAY_BUFFER,f.sphere),t.enableVertexAttribArray(E.sphere),t.vertexAttribPointer(E.sphere,3,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,f.index),t.drawElements(t.TRIANGLES,w.indices.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(L={}){x={...x,...L},r?.triggerRepaint()},getOptions:()=>({...x})}},io=wt,no=32,_t=[{width:8192,height:4096,url:"/timemap/ocean-sdf-8192.webp"},{width:4096,height:2048,url:"/timemap/ocean-sdf-4096.webp"}],so=`
  float oceanDistanceKm(float stored, float rangeKm) {
    return (stored * 2.0 - 1.0) * rangeKm;
  }
`,vt="tm-ocean",lo=e=>`${e.vertexShaderPrelude}
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
}`,ho=()=>`precision highp float;
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
${We}
${Ve}
${Ae}
${so}

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
}`,co=(e,{fadeInAbove:a=9e5,fadeOutBelow:i=18e4}={})=>{const s=Math.max(0,Math.min(1,(e-i)/(a-i)));return s*s*(3-2*s)},uo=(e,a,i=.8)=>{const s=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e);return Math.max(i,s*1.5/1e3)},fo=40075,mo=e=>Number.isFinite(e)&&e>0?fo/e/2:.8,ze=(e,a=_t)=>a.find(i=>i.width<=e&&i.height<=e)||null,po=({opacity:e=1,strength:a=.9,roughness:i=.55,windPatch:s=.3,windScale:u=14,edgeRemap:m=0,sun:w=[.4,.5,.75],water:r=1,absorption:t=[.45,.06,.02],scatter:f=[.12,.265,.43],bottom:M=[.42,.4,.33],sky:x=[.34,.5,.76],shelfKm:g=22,shelfDepthM:L=16,shoreSoftnessKm:h=.8,fadeInAbove:c=9e5,fadeOutBelow:v=18e4,sources:_=_t}={})=>{const E=de();let l=null,o=null,P=null,U=null,T=null,N=!1;const n=new Map;let D={opacity:e,strength:a,roughness:i,windPatch:s,windScale:u,edgeRemap:m,sun:w,water:r,absorption:t,scatter:f,bottom:M,sky:x,shelfKm:g,shelfDepthM:L,shoreSoftnessKm:h,fadeInAbove:c,fadeOutBelow:v,sources:_};const G=p=>{const k=p.variantName;if(n.has(k))return n.get(k);const d=V(o,lo(p),ho(),"tm-ocean",{es300:!0}),F={program:d,attribs:{pos:o.getAttribLocation(d,"a_pos"),sphere:o.getAttribLocation(d,"a_sphere")},uniforms:{elevationGlobe:o.getUniformLocation(d,"a_elevation_globe"),elevationMercator:o.getUniformLocation(d,"a_elevation_mercator"),camera:o.getUniformLocation(d,"u_camera"),sun:o.getUniformLocation(d,"u_sun"),globeness:o.getUniformLocation(d,"u_globeness"),field:o.getUniformLocation(d,"u_field"),rangeKm:o.getUniformLocation(d,"u_rangeKm"),shoreKm:o.getUniformLocation(d,"u_shoreKm"),shelfKm:o.getUniformLocation(d,"u_shelfKm"),strength:o.getUniformLocation(d,"u_strength"),roughness:o.getUniformLocation(d,"u_roughness"),windPatch:o.getUniformLocation(d,"u_windPatch"),windScale:o.getUniformLocation(d,"u_windScale"),water:o.getUniformLocation(d,"u_water"),scatter:o.getUniformLocation(d,"u_scatter"),bottom:o.getUniformLocation(d,"u_bottom"),absorption:o.getUniformLocation(d,"u_absorption"),shelfDepthM:o.getUniformLocation(d,"u_shelfDepthM"),sky:o.getUniformLocation(d,"u_sky"),fade:o.getUniformLocation(d,"u_fade"),opacity:o.getUniformLocation(d,"u_opacity"),edgeRemap:o.getUniformLocation(d,"u_edgeRemap"),matrix:o.getUniformLocation(d,"u_projection_matrix"),tileMercatorCoords:o.getUniformLocation(d,"u_projection_tile_mercator_coords"),clippingPlane:o.getUniformLocation(d,"u_projection_clipping_plane"),transition:o.getUniformLocation(d,"u_projection_transition"),fallbackMatrix:o.getUniformLocation(d,"u_projection_fallback_matrix")}};return n.set(k,F),F},B=()=>{T=o.createTexture(),o.bindTexture(o.TEXTURE_2D,T),o.texImage2D(o.TEXTURE_2D,0,o.LUMINANCE,1,1,0,o.LUMINANCE,o.UNSIGNED_BYTE,new Uint8Array([0])),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MIN_FILTER,o.LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MAG_FILTER,o.LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_S,o.CLAMP_TO_EDGE),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_T,o.CLAMP_TO_EDGE)},S=p=>{o&&(T&&o.deleteTexture(T),T=o.createTexture(),o.bindTexture(o.TEXTURE_2D,T),o.pixelStorei(o.UNPACK_FLIP_Y_WEBGL,!1),Ze(o)?o.texImage2D(o.TEXTURE_2D,0,o.R8,o.RED,o.UNSIGNED_BYTE,p):o.texImage2D(o.TEXTURE_2D,0,o.LUMINANCE,o.LUMINANCE,o.UNSIGNED_BYTE,p),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_S,o.REPEAT),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_T,o.CLAMP_TO_EDGE),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MIN_FILTER,o.LINEAR_MIPMAP_LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MAG_FILTER,o.LINEAR),o.generateMipmap(o.TEXTURE_2D),N=!0,l?.triggerRepaint())},A=()=>{const p=ze(o.getParameter(o.MAX_TEXTURE_SIZE),D.sources);if(!p)return;const k=p.url;P=p.width,fetch(k,{credentials:"omit"}).then(d=>d.ok?d.blob():Promise.reject(new Error(d.status))).then(d=>createImageBitmap(d,{colorSpaceConversion:"none",premultiplyAlpha:"none",imageOrientation:"none"})).then(d=>{if(ze(o?.getParameter(o.MAX_TEXTURE_SIZE)??0,D.sources)?.url!==k){d.close?.();return}S(d),d.close?.()}).catch(()=>{})};return{id:vt,type:"custom",renderingMode:"3d",onAdd(p,k){l=p,o=k;const d=(F,I)=>{const H=o.createBuffer();return o.bindBuffer(F,H),o.bufferData(F,I,o.STATIC_DRAW),H};U={pos:d(o.ARRAY_BUFFER,E.positions),sphere:d(o.ARRAY_BUFFER,E.spheres),index:d(o.ELEMENT_ARRAY_BUFFER,E.indices)},B(),A()},onRemove(){o&&(n.forEach(({program:p})=>o.deleteProgram(p)),n.clear(),T&&(o.deleteTexture(T),T=null,N=!1),U&&(o.deleteBuffer(U.pos),o.deleteBuffer(U.sphere),o.deleteBuffer(U.index),U=null),l=null,o=null)},render(p,k){if(!U||D.opacity<=0)return;const d=k&&k.shaderData,F=k&&k.defaultProjectionData;if(!d||!F)return;const{program:I,attribs:H,uniforms:b}=G(d);o.useProgram(I),b.matrix&&o.uniformMatrix4fv(b.matrix,!1,F.mainMatrix),b.tileMercatorCoords&&o.uniform4f(b.tileMercatorCoords,...F.tileMercatorCoords),b.clippingPlane&&o.uniform4f(b.clippingPlane,...F.clippingPlane),b.transition&&o.uniform1f(b.transition,F.projectionTransition),b.fallbackMatrix&&o.uniformMatrix4fv(b.fallbackMatrix,!1,F.fallbackMatrix);const O=l.getCenter(),y=0;b.elevationGlobe&&o.uniform1f(b.elevationGlobe,y),b.elevationMercator&&o.uniform1f(b.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,O.lat],y).z),b.camera&&o.uniform3f(b.camera,...ee(l,Y)),b.sun&&o.uniform3f(b.sun,...D.sun),b.globeness&&o.uniform1f(b.globeness,F.projectionTransition),b.field&&(o.activeTexture(o.TEXTURE0),o.bindTexture(o.TEXTURE_2D,T),o.uniform1i(b.field,0)),b.rangeKm&&o.uniform1f(b.rangeKm,no),b.shoreKm&&o.uniform1f(b.shoreKm,uo(l.getZoom(),O.lat,Math.max(D.shoreSoftnessKm,mo(P)))),b.shelfKm&&o.uniform1f(b.shelfKm,D.shelfKm),b.strength&&o.uniform1f(b.strength,D.strength),b.roughness&&o.uniform1f(b.roughness,D.roughness),b.windPatch&&o.uniform1f(b.windPatch,D.windPatch),b.windScale&&o.uniform1f(b.windScale,D.windScale),b.water&&o.uniform1f(b.water,D.water),b.scatter&&o.uniform3f(b.scatter,...D.scatter),b.bottom&&o.uniform3f(b.bottom,...D.bottom),b.absorption&&o.uniform3f(b.absorption,...D.absorption),b.shelfDepthM&&o.uniform1f(b.shelfDepthM,D.shelfDepthM),b.sky&&o.uniform3f(b.sky,...D.sky),b.opacity&&o.uniform1f(b.opacity,D.opacity),b.edgeRemap&&o.uniform1f(b.edgeRemap,D.edgeRemap),b.fade&&o.uniform1f(b.fade,co(Re(l,Y),D)),o.bindBuffer(o.ARRAY_BUFFER,U.pos),o.enableVertexAttribArray(H.pos),o.vertexAttribPointer(H.pos,2,o.FLOAT,!1,0,0),o.bindBuffer(o.ARRAY_BUFFER,U.sphere),o.enableVertexAttribArray(H.sphere),o.vertexAttribPointer(H.sphere,3,o.FLOAT,!1,0,0),o.disable(o.DEPTH_TEST),o.depthMask(!1),o.bindBuffer(o.ELEMENT_ARRAY_BUFFER,U.index),o.drawElements(o.TRIANGLES,E.indices.length,o.UNSIGNED_SHORT,0),o.enable(o.DEPTH_TEST),o.depthMask(!0)},setOptions(p={}){const k=D.sources;D={...D,...p},p.sources!==void 0&&p.sources!==k&&(N=!1,o&&A()),l?.triggerRepaint()},getOptions:()=>({...D}),get hasField(){return N}}},go=vt,bt=(e,a,i,s=!0)=>{const u=Math.max(1,Math.floor(a)),m=Math.max(1,Math.floor(i)),w=e.createTexture();e.bindTexture(e.TEXTURE_2D,w),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,u,m,0,e.RGBA,e.UNSIGNED_BYTE,null),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR);let r=null;return s&&(r=e.createFramebuffer(),e.bindFramebuffer(e.FRAMEBUFFER,r),e.framebufferTexture2D(e.FRAMEBUFFER,e.COLOR_ATTACHMENT0,e.TEXTURE_2D,w,0)),{texture:w,framebuffer:r,width:u,height:m}},$e=(e,a)=>{a&&(a.texture&&e.deleteTexture(a.texture),a.framebuffer&&e.deleteFramebuffer(a.framebuffer))},wo=(e,a,i)=>{const s=[];for(let u=1;u<=i;u++){const m=2**u;s.push({width:Math.max(1,Math.floor(e/m)),height:Math.max(1,Math.floor(a/m))})}return s},_o=(e,a,i,s)=>wo(a,i,s).map(u=>bt(e,u.width,u.height)),vo=new Float32Array([-1,-1,1,-1,-1,1,1,1]),bo=e=>{const a=e.createBuffer();return e.bindBuffer(e.ARRAY_BUFFER,a),e.bufferData(e.ARRAY_BUFFER,vo,e.STATIC_DRAW),a},yo=`precision highp float;
attribute vec2 a_corner;
varying vec2 v_uv;
void main() {
  v_uv = a_corner * 0.5 + 0.5;
  gl_Position = vec4(a_corner, 0.0, 1.0);
}`,Eo=e=>({framebuffer:e.getParameter(e.FRAMEBUFFER_BINDING),viewport:e.getParameter(e.VIEWPORT)}),qe=(e,a)=>{e.bindFramebuffer(e.FRAMEBUFFER,a.framebuffer),e.viewport(a.viewport[0],a.viewport[1],a.viewport[2],a.viewport[3]),e.activeTexture(e.TEXTURE0),e.blendFunc(e.ONE,e.ONE_MINUS_SRC_ALPHA),e.enable(e.BLEND),e.depthMask(!0)},be=(e,a,i)=>{e.bindFramebuffer(e.FRAMEBUFFER,a.framebuffer),e.viewport(0,0,a.width,a.height),e.useProgram(i)},re=(e,a,i)=>{e.bindBuffer(e.ARRAY_BUFFER,a),e.enableVertexAttribArray(i),e.vertexAttribPointer(i,2,e.FLOAT,!1,0,0),e.drawArrays(e.TRIANGLE_STRIP,0,4)},ie=(e,a,i,s)=>{e.activeTexture(e.TEXTURE0+a),e.bindTexture(e.TEXTURE_2D,i),s&&e.uniform1i(s,a)},yt="tm-bloom",Xe=5,To=[[1.18,.92,.68],[1.12,.95,.78],[1.06,.98,.89],[1.02,1,.97]],xo=[1,1,1],Ao=()=>`precision highp float;
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
}`,Ro=()=>`precision highp float;
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
}`,Mo=()=>`precision highp float;
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
}`,ko=()=>`precision highp float;
varying vec2 v_uv;
uniform sampler2D u_bloom;
uniform float u_strength;

void main() {
  gl_FragColor = vec4(texture2D(u_bloom, v_uv).rgb * u_strength, 0.0);
}`,So=()=>`precision highp float;
varying vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_saturation;
uniform float u_hue;          // radians
uniform float u_exposure;
uniform float u_contrast;

const vec3 LUMA = vec3(0.2126, 0.7152, 0.0722);

void main() {
  vec4 texel = texture2D(u_scene, v_uv);
  vec3 colour = texel.rgb * u_exposure;

  if (abs(u_hue) > 0.0001) {
    float c = cos(u_hue);
    float s = sin(u_hue);
    // YIQ chroma rotation, folded into a single RGB matrix.
    mat3 rot = mat3(
      0.299 + 0.701 * c + 0.168 * s, 0.587 - 0.587 * c + 0.330 * s, 0.114 - 0.114 * c - 0.497 * s,
      0.299 - 0.299 * c - 0.328 * s, 0.587 + 0.413 * c + 0.035 * s, 0.114 - 0.114 * c + 0.292 * s,
      0.299 - 0.300 * c + 1.250 * s, 0.587 - 0.588 * c - 1.050 * s, 0.114 + 0.886 * c - 0.203 * s);
    colour = clamp(rot * colour, 0.0, 1.0);
  }

  float luma = dot(colour, LUMA);
  colour = mix(vec3(luma), colour, u_saturation);
  // Pivot on mid grey so contrast does not double as a brightness control.
  colour = clamp((colour - 0.5) * u_contrast + 0.5, 0.0, 1.0);

  gl_FragColor = vec4(colour, texel.a);
}`,Lo={saturation:1,hue:0,exposure:1,contrast:1},Uo=e=>Math.abs((e?.saturation??1)-1)>.001||Math.abs(e?.hue??0)>.001||Math.abs((e?.exposure??1)-1)>.001||Math.abs((e?.contrast??1)-1)>.001,Do=({strength:e=.9,threshold:a=.6,knee:i=.12,radius:s=1,chromatic:u=!0,...m}={})=>{let w=null,r=null,t=null,f=null,M=null,x=null,g=null,L={strength:e,threshold:a,knee:i,radius:s,chromatic:u,...Lo,...m};const h=()=>{const _=(E,l,o)=>{const P=V(r,yo,E,l),U={};return o.forEach(T=>{U[T]=r.getUniformLocation(P,`u_${T}`)}),{program:P,corner:r.getAttribLocation(P,"a_corner"),...U}};return{bright:_(Ao(),"tm-bloom-bright",["scene","threshold","knee"]),down:_(Ro(),"tm-bloom-down",["source","texel"]),up:_(Mo(),"tm-bloom-up",["source","texel","radius","tint"]),composite:_(ko(),"tm-bloom-composite",["bloom","strength"]),grade:_(So(),"tm-grade",["scene","saturation","hue","exposure","contrast"])}},c=()=>{$e(r,M),M=null,x&&x.forEach(_=>$e(r,_)),x=null,g=null},v=(_,E)=>{g&&g.width===_&&g.height===E||(c(),M=bt(r,_,E,!1),x=_o(r,_,E,Xe),g={width:_,height:E})};return{id:yt,type:"custom",renderingMode:"2d",onAdd(_,E){w=_,r=E,t=bo(r)},onRemove(){r&&(f&&(Object.values(f).forEach(({program:_})=>r.deleteProgram(_)),f=null),c(),t&&(r.deleteBuffer(t),t=null),w=null,r=null)},render(){const _=Uo(L);if(!t||L.strength<=0&&!_)return;const E=w.getCanvas(),l=Math.max(1,E.width),o=Math.max(1,E.height),P=Eo(r);if(f=f||h(),v(l,o),r.bindTexture(r.TEXTURE_2D,M.texture),r.copyTexImage2D(r.TEXTURE_2D,0,r.RGBA,0,0,l,o,0),r.disable(r.DEPTH_TEST),r.disable(r.BLEND),r.depthMask(!1),_&&(r.bindFramebuffer(r.FRAMEBUFFER,null),r.viewport(0,0,l,o),r.useProgram(f.grade.program),ie(r,0,M.texture,f.grade.scene),f.grade.saturation&&r.uniform1f(f.grade.saturation,L.saturation),f.grade.hue&&r.uniform1f(f.grade.hue,L.hue),f.grade.exposure&&r.uniform1f(f.grade.exposure,L.exposure),f.grade.contrast&&r.uniform1f(f.grade.contrast,L.contrast),re(r,t,f.grade.corner),r.bindTexture(r.TEXTURE_2D,M.texture),r.copyTexImage2D(r.TEXTURE_2D,0,r.RGBA,0,0,l,o,0)),L.strength<=0){qe(r,P);return}be(r,x[0],f.bright.program),ie(r,0,M.texture,f.bright.scene),f.bright.threshold&&r.uniform1f(f.bright.threshold,L.threshold),f.bright.knee&&r.uniform1f(f.bright.knee,L.knee),re(r,t,f.bright.corner);for(let U=1;U<x.length;U++){const T=x[U-1];be(r,x[U],f.down.program),ie(r,0,T.texture,f.down.source),f.down.texel&&r.uniform2f(f.down.texel,1/T.width,1/T.height),re(r,t,f.down.corner)}r.enable(r.BLEND),r.blendFunc(r.ONE,r.ONE);for(let U=x.length-1;U>0;U--){const T=x[U-1];if(be(r,T,f.up.program),ie(r,0,x[U].texture,f.up.source),f.up.texel&&r.uniform2f(f.up.texel,1/T.width,1/T.height),f.up.radius&&r.uniform1f(f.up.radius,L.radius),f.up.tint){const N=L.chromatic?To[x.length-1-U]:xo;r.uniform3f(f.up.tint,...N)}re(r,t,f.up.corner)}r.bindFramebuffer(r.FRAMEBUFFER,P.framebuffer),r.viewport(P.viewport[0],P.viewport[1],P.viewport[2],P.viewport[3]),r.useProgram(f.composite.program),ie(r,0,x[0].texture,f.composite.bloom),f.composite.strength&&r.uniform1f(f.composite.strength,L.strength),re(r,t,f.composite.corner),qe(r,P)},setOptions(_={}){L={...L,..._},w?.triggerRepaint()},getOptions:()=>({...L}),get levelCount(){return Xe}}},Po=yt,Me=[{key:"starfield",option:"brightness",label:"Stars",default:.5,max:1},{key:"sun",option:"brightness",label:"Sun",default:1,max:1},{key:"moon",option:"brightness",label:"Moon",default:1,max:1},{key:"daylight",option:"nightDarkness",label:"Day and night",default:.965,max:1},{key:"atmosphere",option:"strength",label:"Atmosphere",default:1,max:1},{key:"clouds",option:"opacity",label:"Clouds",default:1,max:1},{key:"ocean",option:"opacity",label:"Ocean",default:1,max:1},{key:"relief",option:"reliefPower",label:"Relief",default:0,max:2,on:"daylight"}],xe={key:"reference",default:0},Io="tm-layers-v2",Fo=(e=globalThis.localStorage)=>{const a={};for(const i of Me)a[i.key]={visible:i.default>0,value:i.default};a[xe.key]={visible:!1,value:xe.default};try{const i=JSON.parse(e?.getItem(Io)||"{}");for(const[s,u]of Object.entries(i)){if(!a[s]||!u||typeof u!="object")continue;const m=u.value;a[s]={visible:u.visible===!0,value:typeof m=="number"&&Number.isFinite(m)?m:a[s].value}}}catch{}return a},Co=(e,a)=>{const i=a?.visible?Number(a.value):0;return{[e.option]:Number.isFinite(i)?i:0}},No=(e,a)=>{const i=[];for(const s of Me){const u=e?.[s.on||s.key];!u||typeof u.setOptions!="function"||(u.setOptions(Co(s,a?.[s.key])),i.push(s.key))}return i},Oo=(e,{onReference:a=null,target:i=globalThis}={})=>{const s=new Map(Me.map(u=>[u.key,u]));return i.__tmSetLayer=(u,m)=>{const w=Number.isFinite(Number(m))?Number(m):0;if(u===xe.key)return a?.(w);const r=s.get(u);if(!r)return;const t=e?.[r.on||r.key];if(!(!t||typeof t.setOptions!="function"))return t.setOptions({[r.option]:w})},()=>{i.__tmSetLayer&&delete i.__tmSetLayer}},Bo="/img/map/clouds-field.webp",Go="/img/map/wind-field.png",Ye="/img/map/cloud-patches.webp",ye=e=>Tt(e).rgb.map(a=>a/255),je=[["starfield",ga,e=>pa(e)],["sun",Pa,e=>Sa(e)],["moon",Ha,e=>Ga(e)],["ocean",go,e=>po(e)],["daylight",eo,e=>Ja(e)],["clouds",Xt,e=>qt(e)],["atmosphere",io,e=>ro(e)],["bloom",Po,e=>Do(e)]],Et=(e,a)=>{if(a&&e.getLayer(a))return a;let i;try{i=(e.getStyle()?.layers??[]).find(s=>s.type==="symbol"&&s.layout?.["text-field"]!=null)?.id}catch{}return a&&console.warn(`[globe-layers] "${a}" is not on this map; ${i?`anchoring under "${i}" so the labels stay readable`:"and nothing on it draws text, so the stack goes on top"}`),i},Ho=(e,{date:a=new Date,reduceMotion:i=!1,beforeId:s,permitted:u=null}={})=>{const m=he(a),w=!i,r={fieldUrl:Bo,patchUrl:Ye,windUrl:Go,windAmount:.2,animate:w},t={starfield:{date:a,animate:w},sun:{date:a},moon:{date:a,sun:m},ocean:{sun:m,roughness:.95,strength:.48,windPatch:.6,shoreSoftnessKm:19.5},clouds:{...r,sun:m},daylight:{...r,sun:m,date:a},atmosphere:{sun:m,strength:1.25},bloom:{threshold:.86,strength:.45,knee:.06}},f={},M=Et(e,s);for(const[h,c,v]of je){if(e.getLayer(c)&&e.removeLayer(c),u&&!u(h))continue;const _=v(t[h]);e.addLayer(_,M),f[h]=_}No(f,Fo());const x=Oo(f),g=(h,c)=>f[h]?.setOptions?.(c),L=[window.__tune?.register("Ocean",[{key:"shoreSoftnessKm",label:"Shore falloff",min:0,max:25,step:.25,value:19.5,apply:h=>g("ocean",{shoreSoftnessKm:h})},{key:"roughness",label:"Roughness",min:0,max:1,step:.01,value:.95,apply:h=>g("ocean",{roughness:h})},{key:"strength",label:"Sun glint",min:0,max:1,step:.01,value:.48,apply:h=>g("ocean",{strength:h})},{key:"windPatch",label:"Patchiness",min:0,max:1,step:.01,value:.6,apply:h=>g("ocean",{windPatch:h})},{key:"windScale",label:"Wave scale",min:1,max:60,step:1,value:14,apply:h=>g("ocean",{windScale:h})},{key:"water",label:"Tint depth",min:0,max:2,step:.05,value:1,apply:h=>g("ocean",{water:h})},{key:"scatter",label:"Tint colour",type:"color",value:"#1d5c8f",apply:h=>g("ocean",{scatter:ye(h)})},{key:"opacity",label:"Opacity",min:0,max:1,step:.01,value:1,apply:h=>g("ocean",{opacity:h})}],{tab:"Earth"}),window.__tune?.register("Clouds",[{key:"opacity",label:"Density",min:0,max:1,step:.01,value:1,apply:h=>g("clouds",{opacity:h})},{key:"windAmount",label:"Curl",min:0,max:1,step:.05,value:.2,apply:h=>g("clouds",{windAmount:h})},{key:"windScale",label:"Wind scale",min:.01,max:.5,step:.01,value:.06,apply:h=>g("clouds",{windScale:h})},{key:"windRate",label:"Wind speed",min:0,max:.3,step:.005,value:.05,apply:h=>g("clouds",{windRate:h})},{key:"cloudShadow",label:"Shadows",min:0,max:1,step:.01,value:.5,apply:h=>g("daylight",{cloudShadow:h})},{key:"shadowSoftness",label:"Shadow edge",min:0,max:1,step:.01,value:.75,apply:h=>g("daylight",{shadowSoftness:h})},{key:"patchTiles",label:"Cloud cells",min:96,max:320,step:8,value:144,apply:h=>{g("clouds",{patchTiles:h}),g("daylight",{patchTiles:h})}},{key:"patchMean",label:"Atlas mean",min:.2,max:.8,step:.005,value:.4335,apply:h=>{g("clouds",{patchMean:h}),g("daylight",{patchMean:h})}},{key:"patchDetail",label:"Detail amount",min:0,max:1.5,step:.05,value:.75,apply:h=>{g("clouds",{patchDetail:h}),g("daylight",{patchDetail:h})}},{key:"patchUrl",label:"Tiled source",type:"boolean",value:!0,apply:h=>{const c=h?Ye:null;g("clouds",{patchUrl:c}),g("daylight",{patchUrl:c})}}],{tab:"Earth"}),window.__tune?.register("Cloud light",[{key:"cloudRelief",label:"Cloud height km",min:0,max:120,step:2,value:90,apply:h=>g("clouds",{cloudRelief:h})},{key:"cloudDepth",label:"Optical depth",min:0,max:12,step:.25,value:4,apply:h=>g("clouds",{cloudDepth:h})},{key:"powder",label:"Powder",min:0,max:1,step:.05,value:1,apply:h=>g("clouds",{powder:h})},{key:"forward",label:"Silver lining",min:0,max:2,step:.05,value:.5,apply:h=>g("clouds",{forward:h})},{key:"forwardG",label:"Lobe sharpness",min:0,max:.95,step:.05,value:.7,apply:h=>g("clouds",{forwardG:h})},{key:"selfShadow",label:"Self shadow",min:0,max:1,step:.02,value:.18,apply:h=>g("clouds",{selfShadow:h})},{key:"selfShadowStep",label:"Shadow reach",min:2e-4,max:.006,step:2e-4,value:.0015,apply:h=>g("clouds",{selfShadowStep:h})}],{tab:"Earth"}),window.__tune?.register("Sky and light",[{key:"nightDarkness",label:"Night",min:0,max:1,step:.005,value:.965,apply:h=>g("daylight",{nightDarkness:h})},{key:"twilightColour",label:"Twilight warm",type:"color",value:"#9e5229",apply:h=>g("daylight",{twilightColour:ye(h)})},{key:"twilightCool",label:"Twilight blue",type:"color",value:"#1a2647",apply:h=>g("daylight",{twilightCool:ye(h)})},{key:"twilightStrength",label:"Twilight strength",min:0,max:1,step:.05,value:.55,apply:h=>g("daylight",{twilightStrength:h})},{key:"atmosphere",label:"Haze",min:0,max:2,step:.05,value:1.25,apply:h=>g("atmosphere",{strength:h})},{key:"starfield",label:"Stars",min:0,max:1,step:.05,value:.5,apply:h=>g("starfield",{brightness:h})},{key:"sun",label:"Sun",min:0,max:2,step:.05,value:1,apply:h=>g("sun",{brightness:h})},{key:"moon",label:"Moon",min:0,max:2,step:.05,value:1,apply:h=>g("moon",{brightness:h})},{key:"moonGlow",label:"Moon glow",min:0,max:1.5,step:.05,value:.4,apply:h=>g("moon",{glow:h})},{key:"moonGlowExtent",label:"Moon glow reach",min:0,max:4,step:.1,value:1.8,apply:h=>g("moon",{glowExtent:h})}],{tab:"Earth"}),window.__tune?.register("Glare",[{key:"strength",label:"Strength",min:0,max:3,step:.05,value:.45,apply:h=>g("bloom",{strength:h})},{key:"threshold",label:"Threshold",min:0,max:1,step:.01,value:.86,apply:h=>g("bloom",{threshold:h})},{key:"knee",label:"Knee",min:0,max:.5,step:.01,value:.06,apply:h=>g("bloom",{knee:h})},{key:"radius",label:"Radius",min:.2,max:2,step:.05,value:1,apply:h=>g("bloom",{radius:h})},{key:"chromatic",label:"Warm at the edges",type:"boolean",value:!0,apply:h=>g("bloom",{chromatic:h})}],{tab:"Earth"}),window.__tune?.register("Colour grade",[{key:"saturation",label:"Saturation (0 = greyscale)",min:0,max:2,step:.01,value:1,apply:h=>g("bloom",{saturation:h})},{key:"hue",label:"Hue shift",min:-180,max:180,step:1,value:0,unit:"°",apply:h=>g("bloom",{hue:h*Math.PI/180})},{key:"exposure",label:"Exposure",min:.2,max:2.5,step:.01,value:1,apply:h=>g("bloom",{exposure:h})},{key:"contrast",label:"Contrast",min:.5,max:2,step:.01,value:1,apply:h=>g("bloom",{contrast:h})}],{tab:"Earth"})];return{layers:f,setDate(h){const c=h instanceof Date?h:new Date(h);if(Number.isNaN(c.getTime()))return;const v=he(c);for(const _ of Object.keys(f))f[_]?.setOptions?.({date:c,sun:v})},remove(){x?.();for(const h of L)h?.();for(const[,h]of je)e.getLayer(h)&&e.removeLayer(h)}}},qo=Object.freeze(Object.defineProperty({__proto__:null,addGlobeLayers:Ho,globeAnchorId:Et},Symbol.toStringTag,{value:"Module"}));export{Xt as C,Ho as a,qo as m,K as p,he as s};
