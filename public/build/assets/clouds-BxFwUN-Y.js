import{m as P}from"./map-imagery-UjqdYnMS.js";const I=63710088e-1,G=(e,a,o=0)=>{const i=1+Math.max(0,o)/I,c=a*Math.PI/180,l=e*Math.PI/180;return[Math.cos(c)*Math.cos(l)*i,Math.sin(c)*i,Math.cos(c)*Math.sin(l)*i]},q=e=>(2*Math.atan(Math.exp((1-2*e)*Math.PI))-Math.PI/2)*180/Math.PI,U=e=>.5-Math.log(Math.tan(Math.PI/4+e*Math.PI/180/2))/(2*Math.PI),S=85.0511287798066,B=89.999,x=6,X=(e=64,a=48)=>{const o=[],i=[],c=[],l=n=>S+(B-S)*(n/x),r=[];for(let n=x;n>=1;n--)r.push(U(l(n)));for(let n=0;n<=a;n++)r.push(n/a);for(let n=1;n<=x;n++)r.push(U(-l(n)));for(const n of r){const h=q(n)*Math.PI/180;for(let s=0;s<=e;s++){const t=s/e,p=(t*360-180)*Math.PI/180;o.push(t,n),i.push(Math.cos(h)*Math.cos(p),Math.sin(h),Math.cos(h)*Math.sin(p))}}const w=e+1;for(let n=0;n<r.length-1;n++)for(let h=0;h<e;h++){const s=n*w+h,t=s+w;c.push(s,t,s+1,t,t+1,s+1)}return{positions:new Float32Array(o),spheres:new Float32Array(i),indices:new Uint16Array(c),vertexCount:(e+1)*r.length,rowCount:r.length}},j=`
  vec4 projectShell(vec2 pos, float elevationGlobe, float elevationMercator) {
    #ifdef GLOBE
      return projectTileFor3D(pos, elevationGlobe);
    #else
      return projectTileFor3D(clamp(pos, 0.0, 1.0), elevationMercator);
    #endif
  }
`,$=`
  float daylightFraction(float sunAngle) {
    return smoothstep(-0.31, 0.09, sunAngle);
  }
`,z=`
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
`,le=`
  vec2 sphereSpan(vec3 origin, vec3 dir, float radius) {
    float b = dot(origin, dir);
    float c = dot(origin, origin) - radius * radius;
    float disc = b * b - c;
    if (disc < 0.0) return vec2(1.0, -1.0);
    float sq = sqrt(disc);
    return vec2(-b - sq, -b + sq);
  }
`,ce=`
  bool facesCamera(vec3 unitPos, vec3 camera) {
    return dot(unitPos, camera) > 1.0;
  }
`,k=(e,a)=>{const o=e?.transform,i=typeof o?.getCameraAltitude=="function"?o.getCameraAltitude():null;if(Number.isFinite(i))return i;const c=o?.cameraToCenterDistance;if(!Number.isFinite(c))return 1e7;const l=512*Math.pow(2,e.getZoom()),r=1/a.MercatorCoordinate.fromLngLat(e.getCenter(),0).meterInMercatorCoordinateUnits();return c/l*r*Math.cos(e.getPitch()*Math.PI/180)},de=(e,a)=>{const o=e?.transform,i=typeof o?.getCameraLngLat=="function"?o.getCameraLngLat():e.getCenter();return G(i.lng,i.lat,k(e,a))},H=e=>typeof e.texStorage2D=="function",Y=`#version 300 es
#define attribute in
#define varying out
#define texture2D texture
`,W=`#version 300 es
#define varying in
#define texture2D texture
out highp vec4 tm_fragColour;
#define gl_FragColor tm_fragColour
`,V=(e,a,o,i="layer",{es300:c=!1}={})=>(c&&H(e)&&(a=Y+a,o=W+o),Z(e,a,o,i)),Z=(e,a,o,i)=>{const c=(n,h)=>{const s=e.createShader(n);if(e.shaderSource(s,h),e.compileShader(s),!e.getShaderParameter(s,e.COMPILE_STATUS)){const t=e.getShaderInfoLog(s);throw e.deleteShader(s),new Error(`${i} shader: ${t}`)}return s},l=e.createProgram(),r=c(e.VERTEX_SHADER,a),w=c(e.FRAGMENT_SHADER,o);if(e.attachShader(l,r),e.attachShader(l,w),e.linkProgram(l),e.deleteShader(r),e.deleteShader(w),!e.getProgramParameter(l,e.LINK_STATUS)){const n=e.getProgramInfoLog(l);throw e.deleteProgram(l),new Error(`${i} link: ${n}`)}return l},Q=`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }

  vec2 equirectUVInside(vec3 dir, float drift) {
    return vec2(-atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,y=9e4,K=`
  uniform float u_time;
  uniform float u_drift;        // slow west-to-east rotation of the whole field
  uniform sampler2D u_field;    // real cloud cover, equirectangular
  uniform float u_fieldAmount;  // 0 = no real field loaded
  uniform sampler2D u_wind;     // real wind field: R = eastward, G = southward, 0.5 = still
  uniform float u_windAmount;   // 0 = the field sits still, 1 = fully advected
  uniform float u_windScale;    // how far the flow carries per cycle, in UV
  uniform float u_windRate;     // cycles per second

  vec2 windAt(vec2 uv, float lat) {
    vec2 wind = texture(u_wind, uv).rg * 2.0 - 1.0;
    // Degrees of longitude per metre grow toward the poles; without this correction the flow slows
    // to a crawl at high latitude and the polar cells stop turning.
    wind.x /= max(cos(lat), 0.25);
    return wind * u_windScale;
  }

  float advectedField(vec2 uv, float lat) {
    if (u_windAmount <= 0.0) return texture(u_field, uv).r;
    vec2 flow = windAt(uv, lat);
    float cycle = u_time * u_windRate;
    float phase1 = fract(cycle);
    float phase2 = fract(cycle + 0.5);
    float blend = abs(1.0 - 2.0 * phase1);       // triangle wave: 1 at the resets, 0 mid-cycle
    float a = texture(u_field, uv - flow * phase1).r;
    float b = texture(u_field, uv - flow * phase2).r;
    return mix(mix(a, b, blend), texture(u_field, uv).r, 1.0 - u_windAmount);
  }
`,J=["time","drift","field","fieldAmount","wind","windAmount","windScale","windRate"],ee=(e,a,o,i,c,l)=>{const r=o.animate?i.seconds:0;a.time&&e.uniform1f(a.time,r),a.drift&&e.uniform1f(a.drift,r*o.driftRate),a.fieldAmount&&e.uniform1f(a.fieldAmount,i.field?.ready?1:0),a.windAmount&&e.uniform1f(a.windAmount,i.wind?.ready?o.windAmount:0),a.windScale&&e.uniform1f(a.windScale,o.windScale),a.windRate&&e.uniform1f(a.windRate,o.animate?o.windRate:0),i.field?.ready&&a.field&&(e.activeTexture(e.TEXTURE0+c),e.bindTexture(e.TEXTURE_2D,i.field.texture),e.uniform1i(a.field,c)),i.wind?.ready&&a.wind&&(e.activeTexture(e.TEXTURE0+l),e.bindTexture(e.TEXTURE_2D,i.wind.texture),e.uniform1i(a.wind,l))},F=new WeakMap,te=e=>{let a=F.get(e);return a||(a=new Map,F.set(e,a)),a},ae=(e,a,o)=>{const i=e.createTexture();return e.bindTexture(e.TEXTURE_2D,i),e.pixelStorei(e.UNPACK_FLIP_Y_WEBGL,!1),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,e.RGBA,e.UNSIGNED_BYTE,a),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.REPEAT),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,o?e.LINEAR_MIPMAP_LINEAR:e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR),o&&e.generateMipmap(e.TEXTURE_2D),i},M=(e,a,o=null,{mipmap:i=!0}={})=>{const c=te(e),l=`${a}|${i?"mip":"flat"}`;let r=c.get(l);if(!r){r={texture:null,ready:!1,refs:0,waiters:[],width:0},c.set(l,r);const n=new Image;n.crossOrigin="anonymous",n.onload=()=>{if(r.refs===0)return;const h=e.getParameter?.(e.MAX_TEXTURE_SIZE)??1/0;if(n.width>h){r.waiters=[];return}r.width=n.width,r.texture=ae(e,n,i),r.ready=!0;const s=r.waiters;r.waiters=[],s.forEach(t=>t())},n.onerror=()=>{r.waiters=[]},n.src=a}r.refs++;let w=!0;return o&&(r.ready?o():r.waiters.push(o)),{get texture(){return r.texture},get ready(){return r.ready},get width(){return r.width},release(){w&&(w=!1,o&&(r.waiters=r.waiters.filter(n=>n!==o)),r.refs--,!(r.refs>0)&&(r.texture&&e.deleteTexture(r.texture),r.texture=null,r.ready=!1,c.delete(l)))}}},C="tm-clouds",re=(e,a,o)=>{const i=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e),l=Math.min(1,i/19543),w=I/Math.max(1,i*110);return{frequency:Math.min(2600,Math.max(9,w)),amount:.14+(1-l)*.42,fade:Number.isFinite(o)?oe(1.35,2.75,o/y):0}},oe=(e,a,o)=>{const i=Math.min(1,Math.max(0,(o-e)/(a-e)));return i*i*(3-2*i)},ie=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${j}
void main() {
  v_sphere = a_sphere;
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,ne=()=>`#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform float u_opacity;
uniform vec3 u_sun;           // direction TO the sun
uniform float u_detailFreq;   // noise cycles per unit sphere; rises as the camera descends
uniform float u_detailAmount; // how much of the structure the noise carries, vs the real field
uniform float u_deckFade;     // 0 once the camera is below the deck and clouds stop making sense
${z}
${Q}
${$}
// The field, the wind and the clock the ground's cloud shadows read from the same source.
${K}

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
  if (u_fieldAmount > 0.0) {
    // Real weather. The texture decides WHERE cloud is; the noise decides what its edges look
    // like — and as the field runs out of resolution, u_detailAmount hands the noise more of the
    // job, until at close range it is carrying the structure on its own.
    float field = advectedField(equirectUV(p, u_drift), asin(clamp(p.y, -1.0, 1.0)));
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
  float sunAngle = dot(p, normalize(u_sun));
  float day = daylightFraction(sunAngle);

  // The deck's own modelling: a lit face and a grey shaded face, like the real thing.
  vec3 base = mix(vec3(0.62, 0.66, 0.72), vec3(1.0), 0.35 + 0.65 * max(sunAngle, 0.0));

  // At the terminator the light reaching them has crossed the most air and lost its blue, so the
  // tops go orange while the ground below is already dark. It is the best thing clouds do.
  float twilight = 1.0 - abs(day * 2.0 - 1.0);
  base = mix(base, base * vec3(1.30, 0.74, 0.44), twilight * twilight * 0.85);

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
}`,fe=({opacity:e=.5,animate:a=!0,fieldUrl:o=null,driftRate:i=4e-4,sun:c=[.4,.5,.75],windUrl:l=null,windAmount:r=1,windScale:w=.06,windRate:n=.05}={})=>{const h=X();let s=null,t=null,p=null;const A=new Map;let _={opacity:e,animate:a,fieldUrl:o,driftRate:i,sun:c,windUrl:l,windAmount:r,windScale:w,windRate:n},v=null,b=null;const D=d=>{const u=d.variantName;if(A.has(u))return A.get(u);const f=V(t,ie(d),ne(),"tm-clouds"),E={program:f,attribs:{pos:t.getAttribLocation(f,"a_pos"),sphere:t.getAttribLocation(f,"a_sphere")},uniforms:{elevationGlobe:t.getUniformLocation(f,"a_elevation_globe"),elevationMercator:t.getUniformLocation(f,"a_elevation_mercator"),opacity:t.getUniformLocation(f,"u_opacity"),sun:t.getUniformLocation(f,"u_sun"),...Object.fromEntries(J.map(g=>[g,t.getUniformLocation(f,`u_${g}`)])),detailFreq:t.getUniformLocation(f,"u_detailFreq"),detailAmount:t.getUniformLocation(f,"u_detailAmount"),deckFade:t.getUniformLocation(f,"u_deckFade"),matrix:t.getUniformLocation(f,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(f,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(f,"u_projection_clipping_plane"),transition:t.getUniformLocation(f,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(f,"u_projection_fallback_matrix")}};return A.set(u,E),E},N=(d,u)=>{d.matrix&&t.uniformMatrix4fv(d.matrix,!1,u.mainMatrix),d.tileMercatorCoords&&t.uniform4f(d.tileMercatorCoords,...u.tileMercatorCoords),d.clippingPlane&&t.uniform4f(d.clippingPlane,...u.clippingPlane),d.transition&&t.uniform1f(d.transition,u.projectionTransition),d.fallbackMatrix&&t.uniformMatrix4fv(d.fallbackMatrix,!1,u.fallbackMatrix)},R=()=>s?.triggerRepaint();return{id:C,type:"custom",renderingMode:"3d",onAdd(d,u){s=d,t=u;const f=(E,g)=>{const L=t.createBuffer();return t.bindBuffer(E,L),t.bufferData(E,g,t.STATIC_DRAW),L};p={pos:f(t.ARRAY_BUFFER,h.positions),sphere:f(t.ARRAY_BUFFER,h.spheres),index:f(t.ELEMENT_ARRAY_BUFFER,h.indices)},_.fieldUrl&&(v=M(t,_.fieldUrl,R)),_.windUrl&&(b=M(t,_.windUrl,R))},onRemove(){t&&(A.forEach(({program:d})=>t.deleteProgram(d)),A.clear(),v?.release(),v=null,b?.release(),b=null,p&&(t.deleteBuffer(p.pos),t.deleteBuffer(p.sphere),t.deleteBuffer(p.index),p=null),s=null,t=null)},render(d,u){if(!p||_.opacity<=0)return;const f=u&&u.shaderData,E=u&&u.defaultProjectionData;if(!f||!E)return;const{program:g,attribs:L,uniforms:m}=D(f);t.useProgram(g),N(m,E);const O=s.getCenter().lat;m.elevationGlobe&&t.uniform1f(m.elevationGlobe,y),m.elevationMercator&&t.uniform1f(m.elevationMercator,P.MercatorCoordinate.fromLngLat([0,O],y).z),m.opacity&&t.uniform1f(m.opacity,_.opacity),m.sun&&t.uniform3f(m.sun,..._.sun),ee(t,m,_,{seconds:performance.now()*.001,field:v,wind:b},0,1);const T=re(s.getZoom(),s.getCenter().lat,k(s,P));m.detailFreq&&t.uniform1f(m.detailFreq,T.frequency),m.detailAmount&&t.uniform1f(m.detailAmount,T.amount),m.deckFade&&t.uniform1f(m.deckFade,T.fade),t.bindBuffer(t.ARRAY_BUFFER,p.pos),t.enableVertexAttribArray(L.pos),t.vertexAttribPointer(L.pos,2,t.FLOAT,!1,0,0),t.bindBuffer(t.ARRAY_BUFFER,p.sphere),t.enableVertexAttribArray(L.sphere),t.vertexAttribPointer(L.sphere,3,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,p.index),t.drawElements(t.TRIANGLES,h.indices.length,t.UNSIGNED_SHORT,0),t.depthMask(!0),_.animate&&s.triggerRepaint()},setOptions(d={}){const u=_.fieldUrl;_={..._,...d},t&&d.fieldUrl!==void 0&&d.fieldUrl!==u&&(v?.release(),v=d.fieldUrl?M(t,d.fieldUrl,R):null),s&&s.triggerRepaint()},getOptions:()=>({..._}),get hasField(){return!!v?.ready}}},ue=C;export{ue as C,Q as E,ce as F,z as N,le as S,$ as T,k as a,M as b,fe as c,V as d,de as e,I as f,X as g,y as h,K as i,j,J as k,H as l,G as p,ee as s};
