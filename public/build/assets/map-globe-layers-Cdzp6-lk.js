import{m as Y}from"./map-imagery-BuJgd7K9.js";import{p as lt}from"./tuner-DOHQmmv5.js";const se=63710088e-1,W=(e,a,s=0)=>{const l=1+Math.max(0,s)/se,c=a*Math.PI/180,u=e*Math.PI/180;return[Math.cos(c)*Math.cos(u)*l,Math.sin(c)*l,Math.cos(c)*Math.sin(u)*l]},ct=e=>(2*Math.atan(Math.exp((1-2*e)*Math.PI))-Math.PI/2)*180/Math.PI,Ee=e=>.5-Math.log(Math.tan(Math.PI/4+e*Math.PI/180/2))/(2*Math.PI),Ae=85.0511287798066,ht=89.999,fe=6,le=(e=64,a=48)=>{const s=[],l=[],c=[],u=n=>Ae+(ht-Ae)*(n/fe),t=[];for(let n=fe;n>=1;n--)t.push(Ee(u(n)));for(let n=0;n<=a;n++)t.push(n/a);for(let n=1;n<=fe;n++)t.push(Ee(-u(n)));for(const n of t){const v=ct(n)*Math.PI/180;for(let p=0;p<=e;p++){const h=p/e,f=(h*360-180)*Math.PI/180;s.push(h,n),l.push(Math.cos(v)*Math.cos(f),Math.sin(v),Math.cos(v)*Math.sin(f))}}const m=e+1;for(let n=0;n<t.length-1;n++)for(let v=0;v<e;v++){const p=n*m+v,h=p+m;c.push(p,h,p+1,h,h+1,p+1)}return{positions:new Float32Array(s),spheres:new Float32Array(l),indices:new Uint16Array(c),vertexCount:(e+1)*t.length,rowCount:t.length}},ce=`
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
`,Oe=`
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
`,Be=`
  vec2 sphereSpan(vec3 origin, vec3 dir, float radius) {
    float b = dot(origin, dir);
    float c = dot(origin, origin) - radius * radius;
    float disc = b * b - c;
    if (disc < 0.0) return vec2(1.0, -1.0);
    float sq = sqrt(disc);
    return vec2(-b - sq, -b + sq);
  }
`,Ge=`
  bool facesCamera(vec3 unitPos, vec3 camera) {
    return dot(unitPos, camera) > 1.0;
  }
`,ye=(e,a)=>{const s=e?.transform,l=typeof s?.getCameraAltitude=="function"?s.getCameraAltitude():null;if(Number.isFinite(l))return l;const c=s?.cameraToCenterDistance;if(!Number.isFinite(c))return 1e7;const u=512*Math.pow(2,e.getZoom()),t=1/a.MercatorCoordinate.fromLngLat(e.getCenter(),0).meterInMercatorCoordinateUnits();return c/u*t*Math.cos(e.getPitch()*Math.PI/180)},ee=(e,a)=>{const s=e?.transform?.cameraPosition;if(s&&Number.isFinite(s[0])&&Number.isFinite(s[1])&&Number.isFinite(s[2]))return[s[2],s[1],s[0]];const l=e?.transform,c=typeof l?.getCameraLngLat=="function"?l.getCameraLngLat():e.getCenter();return W(c.lng,c.lat,ye(e,a))},$e=e=>typeof e.texStorage2D=="function",ut=`#version 300 es
#define attribute in
#define varying out
#define texture2D texture
`,ft=`#version 300 es
#define varying in
#define texture2D texture
out highp vec4 tm_fragColour;
#define gl_FragColor tm_fragColour
`,Z=(e,a,s,l="layer",{es300:c=!1}={})=>(c&&$e(e)&&(a=ut+a,s=ft+s),dt(e,a,s,l)),dt=(e,a,s,l)=>{const c=(n,v)=>{const p=e.createShader(n);if(e.shaderSource(p,v),e.compileShader(p),!e.getShaderParameter(p,e.COMPILE_STATUS)){const h=e.getShaderInfoLog(p);throw e.deleteShader(p),new Error(`${l} shader: ${h}`)}return p},u=e.createProgram(),t=c(e.VERTEX_SHADER,a),m=c(e.FRAGMENT_SHADER,s);if(e.attachShader(u,t),e.attachShader(u,m),e.linkProgram(u),e.deleteShader(t),e.deleteShader(m),!e.getProgramParameter(u,e.LINK_STATUS)){const n=e.getProgramInfoLog(u);throw e.deleteProgram(u),new Error(`${l} link: ${n}`)}return u},he=`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }

  vec2 equirectUVInside(vec3 dir, float drift) {
    return vec2(-atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,oe=9e4,He=`
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
`,ze=["time","drift","field","fieldAmount","wind","windAmount","windScale","windRate"],Ye=(e,a,s,l,c,u)=>{const t=s.animate?l.seconds:0;a.time&&e.uniform1f(a.time,t),a.drift&&e.uniform1f(a.drift,t*s.driftRate),a.fieldAmount&&e.uniform1f(a.fieldAmount,l.field?.ready?1:0),a.windAmount&&e.uniform1f(a.windAmount,l.wind?.ready?s.windAmount:0),a.windScale&&e.uniform1f(a.windScale,s.windScale),a.windRate&&e.uniform1f(a.windRate,s.animate?s.windRate:0),l.field?.ready&&a.field&&(e.activeTexture(e.TEXTURE0+c),e.bindTexture(e.TEXTURE_2D,l.field.texture),e.uniform1i(a.field,c)),l.wind?.ready&&a.wind&&(e.activeTexture(e.TEXTURE0+u),e.bindTexture(e.TEXTURE_2D,l.wind.texture),e.uniform1i(a.wind,u))},Te=new WeakMap,mt=e=>{let a=Te.get(e);return a||(a=new Map,Te.set(e,a)),a},gt=(e,a,s)=>{const l=e.createTexture();return e.bindTexture(e.TEXTURE_2D,l),e.pixelStorei(e.UNPACK_FLIP_Y_WEBGL,!1),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,e.RGBA,e.UNSIGNED_BYTE,a),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.REPEAT),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,s?e.LINEAR_MIPMAP_LINEAR:e.LINEAR),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.LINEAR),s&&e.generateMipmap(e.TEXTURE_2D),l},K=(e,a,s=null,{mipmap:l=!0}={})=>{const c=mt(e),u=`${a}|${l?"mip":"flat"}`;let t=c.get(u);if(!t){t={texture:null,ready:!1,refs:0,waiters:[],width:0},c.set(u,t);const n=new Image;n.crossOrigin="anonymous",n.onload=()=>{if(t.refs===0)return;const v=e.getParameter?.(e.MAX_TEXTURE_SIZE)??1/0;if(n.width>v){t.waiters=[];return}t.width=n.width,t.texture=gt(e,n,l),t.ready=!0;const p=t.waiters;t.waiters=[],p.forEach(h=>h())},n.onerror=()=>{t.waiters=[]},n.src=a}t.refs++;let m=!0;return s&&(t.ready?s():t.waiters.push(s)),{get texture(){return t.texture},get ready(){return t.ready},get width(){return t.width},release(){m&&(m=!1,s&&(t.waiters=t.waiters.filter(n=>n!==s)),t.refs--,!(t.refs>0)&&(t.texture&&e.deleteTexture(t.texture),t.texture=null,t.ready=!1,c.delete(u)))}}},je="tm-clouds",pt=(e,a,s)=>{const l=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e),u=Math.min(1,l/19543),m=se/Math.max(1,l*110);return{frequency:Math.min(2600,Math.max(9,m)),amount:.14+(1-u)*.1,fade:Number.isFinite(s)?_t(1.35,2.75,s/oe):0}},_t=(e,a,s)=>{const l=Math.min(1,Math.max(0,(s-e)/(a-e)));return l*l*(3-2*l)},wt=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${ce}
void main() {
  v_sphere = a_sphere;
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,bt=()=>`#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform float u_opacity;
uniform vec3 u_sun;           // direction TO the sun
uniform float u_detailFreq;   // noise cycles per unit sphere; rises as the camera descends
uniform float u_detailAmount; // how much of the structure the noise carries, vs the real field
uniform float u_deckFade;     // 0 once the camera is below the deck and clouds stop making sense
${Oe}
${he}
${be}
// The field, the wind and the clock the ground's cloud shadows read from the same source.
${He}

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

  // The deck's own modelling: a lit face and a shaded face, like the real thing — but cloud is
  // WHITE, and its shaded face is white in shadow, not grey paint. The old pair (0.62,0.66,0.72
  // lifted from 0.35) left everything away from the subsolar point reading as blue-grey smoke,
  // which at high latitude is most of what is on screen. Bright, barely-tinted shadow instead.
  vec3 base = mix(vec3(0.86, 0.88, 0.92), vec3(1.0), 0.55 + 0.45 * max(sunAngle, 0.0));

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
}`,yt=({opacity:e=.5,animate:a=!0,fieldUrl:s=null,driftRate:l=4e-4,sun:c=[.4,.5,.75],windUrl:u=null,windAmount:t=1,windScale:m=.06,windRate:n=.05}={})=>{const v=le();let p=null,h=null,f=null;const A=new Map;let _={opacity:e,animate:a,fieldUrl:s,driftRate:l,sun:c,windUrl:u,windAmount:t,windScale:m,windRate:n},i=null,y=null;const E=o=>{const I=o.variantName;if(A.has(I))return A.get(I);const M=Z(h,wt(o),bt(),"tm-clouds"),r={program:M,attribs:{pos:h.getAttribLocation(M,"a_pos"),sphere:h.getAttribLocation(M,"a_sphere")},uniforms:{elevationGlobe:h.getUniformLocation(M,"a_elevation_globe"),elevationMercator:h.getUniformLocation(M,"a_elevation_mercator"),opacity:h.getUniformLocation(M,"u_opacity"),sun:h.getUniformLocation(M,"u_sun"),...Object.fromEntries(ze.map(D=>[D,h.getUniformLocation(M,`u_${D}`)])),detailFreq:h.getUniformLocation(M,"u_detailFreq"),detailAmount:h.getUniformLocation(M,"u_detailAmount"),deckFade:h.getUniformLocation(M,"u_deckFade"),matrix:h.getUniformLocation(M,"u_projection_matrix"),tileMercatorCoords:h.getUniformLocation(M,"u_projection_tile_mercator_coords"),clippingPlane:h.getUniformLocation(M,"u_projection_clipping_plane"),transition:h.getUniformLocation(M,"u_projection_transition"),fallbackMatrix:h.getUniformLocation(M,"u_projection_fallback_matrix")}};return A.set(I,r),r},N=(o,I)=>{o.matrix&&h.uniformMatrix4fv(o.matrix,!1,I.mainMatrix),o.tileMercatorCoords&&h.uniform4f(o.tileMercatorCoords,...I.tileMercatorCoords),o.clippingPlane&&h.uniform4f(o.clippingPlane,...I.clippingPlane),o.transition&&h.uniform1f(o.transition,I.projectionTransition),o.fallbackMatrix&&h.uniformMatrix4fv(o.fallbackMatrix,!1,I.fallbackMatrix)},b=()=>p?.triggerRepaint();return{id:je,type:"custom",renderingMode:"3d",onAdd(o,I){p=o,h=I;const M=(r,D)=>{const F=h.createBuffer();return h.bindBuffer(r,F),h.bufferData(r,D,h.STATIC_DRAW),F};f={pos:M(h.ARRAY_BUFFER,v.positions),sphere:M(h.ARRAY_BUFFER,v.spheres),index:M(h.ELEMENT_ARRAY_BUFFER,v.indices)},_.fieldUrl&&(i=K(h,_.fieldUrl,b)),_.windUrl&&(y=K(h,_.windUrl,b))},onRemove(){h&&(A.forEach(({program:o})=>h.deleteProgram(o)),A.clear(),i?.release(),i=null,y?.release(),y=null,f&&(h.deleteBuffer(f.pos),h.deleteBuffer(f.sphere),h.deleteBuffer(f.index),f=null),p=null,h=null)},render(o,I){if(!f||_.opacity<=0)return;const M=I&&I.shaderData,r=I&&I.defaultProjectionData;if(!M||!r)return;const{program:D,attribs:F,uniforms:T}=E(M);h.useProgram(D),N(T,r);const G=p.getCenter().lat;T.elevationGlobe&&h.uniform1f(T.elevationGlobe,oe),T.elevationMercator&&h.uniform1f(T.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,G],oe).z),T.opacity&&h.uniform1f(T.opacity,_.opacity),T.sun&&h.uniform3f(T.sun,..._.sun),Ye(h,T,_,{seconds:performance.now()*.001,field:i,wind:y},0,1);const O=pt(p.getZoom(),p.getCenter().lat,ye(p,Y));T.detailFreq&&h.uniform1f(T.detailFreq,O.frequency),T.detailAmount&&h.uniform1f(T.detailAmount,O.amount),T.deckFade&&h.uniform1f(T.deckFade,O.fade),h.bindBuffer(h.ARRAY_BUFFER,f.pos),h.enableVertexAttribArray(F.pos),h.vertexAttribPointer(F.pos,2,h.FLOAT,!1,0,0),h.bindBuffer(h.ARRAY_BUFFER,f.sphere),h.enableVertexAttribArray(F.sphere),h.vertexAttribPointer(F.sphere,3,h.FLOAT,!1,0,0),h.enable(h.DEPTH_TEST),h.depthFunc(h.LEQUAL),h.depthMask(!1),h.bindBuffer(h.ELEMENT_ARRAY_BUFFER,f.index),h.drawElements(h.TRIANGLES,v.indices.length,h.UNSIGNED_SHORT,0),h.depthMask(!0),_.animate&&p.triggerRepaint()},setOptions(o={}){const I=_.fieldUrl;_={..._,...o},h&&o.fieldUrl!==void 0&&o.fieldUrl!==I&&(i?.release(),i=o.fieldUrl?K(h,o.fieldUrl,b):null),p&&p.triggerRepaint()},getOptions:()=>({..._}),get hasField(){return!!i?.ready}}},vt=je,j=Math.PI/180,Et=Date.UTC(2e3,0,1,12),de=1/3600,qe=e=>(e.getTime()-Et)/864e5,At=(e=new Date)=>((18.697374558+24.06570982441908*qe(e))%24*15%360+360)%360,Tt=e=>{const a=qe(e)/36525;return{zeta:(2306.2181*a+.30188*a*a+.017998*a*a*a)*de,z:(2306.2181*a+1.09468*a*a+.018203*a*a*a)*de,theta:(2004.3109*a-.42665*a*a-.041833*a*a*a)*de}},Rt=(e,a,s)=>{const{zeta:l,z:c,theta:u}=Tt(s),t=(e-c)*j,m=a*j,n=u*j,v=Math.cos(m)*Math.sin(t),p=Math.cos(n)*Math.cos(m)*Math.cos(t)+Math.sin(n)*Math.sin(m),h=-Math.sin(n)*Math.cos(m)*Math.cos(t)+Math.cos(n)*Math.sin(m);return{ra:Math.atan2(v,p)/j-l,dec:Math.asin(Math.min(1,Math.max(-1,h)))/j}},Re=e=>(e%360+360)%360,Mt=(e,a=new Date)=>{const s=Math.hypot(e[0],e[1],e[2])||1,l=Math.atan2(e[2]/s,e[0]/s)/j,c=Math.asin(Math.min(1,Math.max(-1,e[1]/s)))/j,u=Rt(Re(l+At(a)),c,a);return{ra:Re(u.ra),dec:u.dec}},xt=`
  vec2 panoramaUV(vec3 skyDir) {
    return vec2(atan(skyDir.z, skyDir.x) / 6.28318530718 + 1.0,
                0.5 - asin(clamp(skyDir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,Lt=(e=new Date)=>{const a=new Float32Array(9);return[[1,0,0],[0,1,0],[0,0,1]].forEach((l,c)=>{const{ra:u,dec:t}=Mt(l,e);a[c*3]=Math.cos(t*j)*Math.cos(u*j),a[c*3+1]=Math.sin(t*j),a[c*3+2]=Math.cos(t*j)*Math.sin(u*j)}),a},St=Math.PI/180,re=e=>{const a=Math.hypot(e[0],e[1],e[2])||1;return[e[0]/a,e[1]/a,e[2]/a]},Ut=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],pe=(e,a)=>[e[0]-a[0],e[1]-a[1],e[2]-a[2]],Me=(e,a)=>{const s=Ut(e,a);return re([e[0]-a[0]*s,e[1]-a[1]*s,e[2]-a[2]*s])},kt=(e,a)=>{const l=a>89.98?-1:1,c=re(pe(W(e,a+.01*l),W(e,a-.01*l))),u=re(pe(W(e+.01,a),W(e-.01,a)));return{north:l===1?c:[-c[0],-c[1],-c[2]],east:u}},Dt=(e,a,s,l)=>{const c=e.getCenter(),u=(e.getBearing?.()??0)*St,t=W(c.lng,c.lat,0),m=ee(e,a),n=re(pe(t,m)),{north:v,east:p}=kt(c.lng,c.lat),h=[0,1,2].map(E=>v[E]*Math.cos(u)+p[E]*Math.sin(u)),f=[0,1,2].map(E=>-v[E]*Math.sin(u)+p[E]*Math.cos(u)),A=Me(h,n),_=Me(f,n),i=Math.tan(s/2),y=i*l;return{origin:m,forward:n,up:A.map(E=>E*i),right:_.map(E=>E*y),upUnit:A,rightUnit:_}},_e=(e,a)=>e*a*4,Xe=[{name:"high",width:4096,height:2048,url:"/img/map/sky/milkyway-4k.webp",bytes:3680220,resident:_e(4096,2048),decodeMs:235},{name:"standard",width:2048,height:1024,url:"/img/map/sky/milkyway-2k.webp",bytes:764012,resident:_e(2048,1024),decodeMs:51}],Ke={name:"placeholder",width:1024,height:512,url:"/img/map/sky/milkyway-1k.webp",bytes:45160,resident:_e(1024,512),decodeMs:5},Pt=4,It=4,Ft=({maxTextureSize:e=0,deviceMemory:a=null,hardwareConcurrency:s=null,saveData:l=!1,effectiveType:c=null}={})=>{const u=Xe.filter(n=>n.width<=e);if(u.length===0)return{tier:Ke,reason:`MAX_TEXTURE_SIZE is ${e}, below every tier — falling back to the placeholder`};const t=u[u.length-1],m=u[0];return m===t?{tier:m,reason:`only ${m.name} fits MAX_TEXTURE_SIZE ${e}`}:l?{tier:t,reason:"the browser is in data-saver mode"}:c&&/^(slow-2g|2g|3g)$/.test(c)?{tier:t,reason:`the connection reports ${c}`}:Number.isFinite(a)&&a<Pt?{tier:t,reason:`the device reports ${a} GB of memory`}:Number.isFinite(s)&&s<It?{tier:t,reason:`the device reports ${s} cores`}:{tier:m,reason:`nothing says otherwise, and MAX_TEXTURE_SIZE is ${e}`}},Ct=(e,a=typeof navigator>"u"?null:navigator)=>{const s=a?.connection??null;return{maxTextureSize:e?.getParameter?.(e.MAX_TEXTURE_SIZE)??0,deviceMemory:a?.deviceMemory??null,hardwareConcurrency:a?.hardwareConcurrency??null,saveData:s?.saveData??!1,effectiveType:s?.effectiveType??null}},We="tm-starfield",Nt=Ke.url,Ot="/data/sky/bright-stars.bin",Bt=e=>{const a=Number.isFinite(e)?e:5800,s=Math.min(4e4,Math.max(1e3,a))/100,l=v=>Math.min(1,Math.max(0,v/255)),c=s<=66?255:329.698727446*Math.pow(s-60,-.1332047592),u=s<=66?99.4708025861*Math.log(s)-161.1195681661:288.1221695283*Math.pow(s-60,-.0755148492),t=s>=66?255:s<=19?0:138.5177312231*Math.log(s-10)-305.0447927307,m=[l(c),l(u),l(t)],n=Math.max(...m)||1;return m.map(v=>v/n)},Gt=(e,{limitMagnitude:a=6.5}={})=>{const s=new Float32Array(e),l=Math.floor(s.length/4),c=[];for(let t=0;t<l;t++){const m=s[t*4+2];if(m>a)continue;const n=Math.pow(10,-.4*m);c.push([s[t*4],s[t*4+1],Math.pow(n,.36),...Bt(s[t*4+3])])}const u=new Float32Array(c.length*6);return c.forEach((t,m)=>u.set(t,m*6)),{vertices:u,count:c.length}},Ve=`
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
`,$t=e=>`${e.define}
attribute vec2 a_pos;
varying vec2 v_ndc;
void main() {
  v_ndc = a_pos;
  // Straight to clip space. No projection, no elevation, no prelude — that is the whole point.
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`,Ht=e=>`${e.define}
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
${Ve}
${xt}

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
}`,zt=e=>`${e.define}
attribute vec3 a_star;       // right ascension and declination in degrees, then brightness
attribute vec3 a_colour;
uniform mat3 u_skyFrame;
uniform float u_pixelRatio;
uniform float u_starSize;
uniform float u_catalogueAmount;
varying vec3 v_colour;
${Ve}

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
}`,Yt=e=>`${e.define}
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
}`,jt=({textureUrl:e=null,placeholderUrl:a=Nt,catalogueUrl:s=Ot,date:l=new Date,brightness:c=.55,nebula:u=.3,nebulaContrast:t=1.45,starDensity:m=210,starAmount:n=1.5,catalogueAmount:v=2.2,starSize:p=3,limitMagnitude:h=6.5,twinkle:f=0,animate:A=!1}={})=>{let _=null,i=null,y=null,E=null,N=null,b=null,o="not chosen yet",I=0;const M=new Map;let r={textureUrl:e,placeholderUrl:a,catalogueUrl:s,date:l,brightness:c,nebula:u,nebulaContrast:t,starDensity:m,starAmount:n,catalogueAmount:v,starSize:p,limitMagnitude:h,twinkle:f,animate:A};const D=k=>{const w=k.variantName;if(M.has(w))return M.get(w);const L=Z(i,$t(k),Ht(k),"tm-starfield"),S=Z(i,zt(k),Yt(k),"tm-starfield-catalogue"),d=(C,x)=>i.getUniformLocation(C,x),U={sky:{program:L,pos:i.getAttribLocation(L,"a_pos"),uniforms:{camera:d(L,"u_camera"),forward:d(L,"u_forward"),right:d(L,"u_right"),up:d(L,"u_up"),halfExtent:d(L,"u_halfExtent"),skyFrame:d(L,"u_skyFrame"),sky:d(L,"u_sky"),globeness:d(L,"u_globeness"),brightness:d(L,"u_brightness"),nebula:d(L,"u_nebula"),nebulaContrast:d(L,"u_nebulaContrast"),starDensity:d(L,"u_starDensity"),starAmount:d(L,"u_starAmount"),twinkle:d(L,"u_twinkle"),time:d(L,"u_time")}},stars:{program:S,star:i.getAttribLocation(S,"a_star"),colour:i.getAttribLocation(S,"a_colour"),uniforms:{camera:d(S,"u_camera"),forward:d(S,"u_forward"),right:d(S,"u_right"),up:d(S,"u_up"),halfExtent:d(S,"u_halfExtent"),skyFrame:d(S,"u_skyFrame"),pixelRatio:d(S,"u_pixelRatio"),starSize:d(S,"u_starSize"),catalogueAmount:d(S,"u_catalogueAmount")}}};return M.set(w,U),U},F=()=>{if(!i)return;const k=(w,L)=>w?K(i,w,L,{mipmap:!1}):null;if(r.textureUrl===null){const w=Ft(Ct(i));b=w.tier,o=w.reason}else b=Xe.find(w=>w.url===r.textureUrl)??{name:"explicit",url:r.textureUrl},o="the caller named a panorama";N=k(r.placeholderUrl,()=>{_?.triggerRepaint()}),E=k(b.url===r.placeholderUrl?null:b.url,()=>{N?.release(),N=null,_?.triggerRepaint()})},T=()=>E?.ready?E:N?.ready?N:null,G=()=>{E?.release(),N?.release(),E=null,N=null},O=k=>{!k||typeof fetch!="function"||fetch(k).then(w=>{if(!w.ok)throw new Error(`${w.status} ${w.statusText}`);return w.arrayBuffer()}).then(w=>{if(!i||!y)return;const{vertices:L,count:S}=Gt(w,{limitMagnitude:r.limitMagnitude});i.bindBuffer(i.ARRAY_BUFFER,y.stars),i.bufferData(i.ARRAY_BUFFER,L,i.STATIC_DRAW),I=S,_?.triggerRepaint()}).catch(w=>console.warn(`[starfield] bright star catalogue unavailable: ${w.message}`))};return{id:We,type:"custom",renderingMode:"3d",onAdd(k,w){_=k,i=w;const L=(S,d)=>{const U=i.createBuffer();return i.bindBuffer(S,U),d&&i.bufferData(S,d,i.STATIC_DRAW),U};y={pos:L(i.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,1,1])),index:L(i.ELEMENT_ARRAY_BUFFER,new Uint16Array([0,1,2,2,1,3])),stars:L(i.ARRAY_BUFFER,null)},F(),O(r.catalogueUrl)},onRemove(){i&&(M.forEach(({sky:k,stars:w})=>{i.deleteProgram(k.program),i.deleteProgram(w.program)}),M.clear(),G(),y&&(i.deleteBuffer(y.pos),i.deleteBuffer(y.index),i.deleteBuffer(y.stars),y=null),_=null,i=null)},render(k,w){if(!y||r.brightness<=0)return;const L=w&&w.shaderData,S=w&&w.defaultProjectionData;if(!L||!S)return;const d=_.getCanvas(),U=d.width/Math.max(1,d.height),C=Dt(_,Y,w.fov||.6435,U),x=Math.tan((w.fov||.6435)/2),g=Lt(r.date),B=S.projectionTransition,{sky:P,stars:R}=D(L),q=({uniforms:z})=>{z.camera&&i.uniform3f(z.camera,...C.origin),z.forward&&i.uniform3f(z.forward,...C.forward),z.right&&i.uniform3f(z.right,...C.rightUnit),z.up&&i.uniform3f(z.up,...C.upUnit),z.halfExtent&&i.uniform2f(z.halfExtent,x*U,x),z.skyFrame&&i.uniformMatrix3fv(z.skyFrame,!1,g)};i.useProgram(P.program),q(P),P.uniforms.globeness&&i.uniform1f(P.uniforms.globeness,B),P.uniforms.brightness&&i.uniform1f(P.uniforms.brightness,r.brightness);const X=T();P.uniforms.nebula&&i.uniform1f(P.uniforms.nebula,X?r.nebula:0),P.uniforms.nebulaContrast&&i.uniform1f(P.uniforms.nebulaContrast,r.nebulaContrast),P.uniforms.starDensity&&i.uniform1f(P.uniforms.starDensity,r.starDensity),P.uniforms.starAmount&&i.uniform1f(P.uniforms.starAmount,r.starAmount),P.uniforms.twinkle&&i.uniform1f(P.uniforms.twinkle,r.twinkle),P.uniforms.time&&i.uniform1f(P.uniforms.time,r.animate?performance.now()*.001:0),X&&P.uniforms.sky&&(i.activeTexture(i.TEXTURE0),i.bindTexture(i.TEXTURE_2D,X.texture),i.uniform1i(P.uniforms.sky,0)),i.bindBuffer(i.ARRAY_BUFFER,y.pos),i.enableVertexAttribArray(P.pos),i.vertexAttribPointer(P.pos,2,i.FLOAT,!1,0,0),i.disable(i.DEPTH_TEST),i.depthMask(!1),i.bindBuffer(i.ELEMENT_ARRAY_BUFFER,y.index),i.drawElements(i.TRIANGLES,6,i.UNSIGNED_SHORT,0),I>0&&B>.5&&r.catalogueAmount>0&&(i.useProgram(R.program),q(R),R.uniforms.pixelRatio&&i.uniform1f(R.uniforms.pixelRatio,typeof devicePixelRatio=="number"?devicePixelRatio:1),R.uniforms.starSize&&i.uniform1f(R.uniforms.starSize,r.starSize),R.uniforms.catalogueAmount&&i.uniform1f(R.uniforms.catalogueAmount,r.catalogueAmount*r.brightness),i.bindBuffer(i.ARRAY_BUFFER,y.stars),i.enableVertexAttribArray(R.star),i.vertexAttribPointer(R.star,3,i.FLOAT,!1,24,0),i.enableVertexAttribArray(R.colour),i.vertexAttribPointer(R.colour,3,i.FLOAT,!1,24,12),i.drawArrays(i.POINTS,0,I),i.disableVertexAttribArray(R.star),i.disableVertexAttribArray(R.colour)),i.enable(i.DEPTH_TEST),i.depthMask(!0),r.animate&&r.twinkle>0&&_.triggerRepaint()},setOptions(k={}){const w=r.textureUrl,L=r.catalogueUrl;r={...r,...k},k.textureUrl!==void 0&&k.textureUrl!==w&&(G(),F()),k.catalogueUrl!==void 0&&k.catalogueUrl!==L&&(I=0,O(k.catalogueUrl)),_?.triggerRepaint()},getOptions:()=>({...r}),get hasSky(){return T()!==null},get starCount(){return I},get skyTier(){return b?{...b,reason:o}:null}}},qt=We,V=Math.PI/180,Xt=Date.UTC(2e3,0,1,12),Kt=149597870700,Wt=6957e5,Ze=e=>(e.getTime()-Xt)/864e5,Qe=(e=new Date)=>{const a=Ze(e),s=(280.46+.9856474*a)%360,l=(357.528+.9856003*a)%360*V,c=(s+1.915*Math.sin(l)+.02*Math.sin(2*l))*V,u=(23.439-4e-7*a)*V,t=Math.asin(Math.sin(u)*Math.sin(c))/V;let m=Math.atan2(Math.cos(u)*Math.sin(c),Math.cos(c))/V;m<0&&(m+=360);let n=s-m;n>180&&(n-=360),n<-180&&(n+=360),n*=4;const v=(1.00014-.01671*Math.cos(l)-14e-5*Math.cos(2*l))*Kt;return{declination:t,equationOfTime:n,distance:v}},Je=(e=new Date)=>Qe(e).distance,Vt=(e=new Date)=>Math.atan(Wt/Je(e)),Zt=(e=new Date)=>{const a=(357.528+.9856003*Ze(e))%360*V;return(1.00014-.01671*Math.cos(a)-14e-5*Math.cos(2*a))*149597870700},Qt=(e=new Date)=>{const{declination:a,equationOfTime:s}=Qe(e);let c=-15*(e.getUTCHours()+e.getUTCMinutes()/60+e.getUTCSeconds()/3600-12+s/60);return c=(c+540)%360-180,{lng:c,lat:a}},ne=(e=new Date)=>{const{lng:a,lat:s}=Qt(e),l=s*V,c=a*V;return[Math.cos(l)*Math.cos(c),Math.sin(l),Math.cos(l)*Math.sin(c)]},et="tm-sun",xe=63710088e-1,Jt=18,ea=(e,a,s)=>{const l=Math.abs(e),c=a,u=s;if(c<=0)return 0;if(l>=c+u)return 1;if(l<=u-c)return 0;if(l<=c-u)return 1-u*u/(c*c);const t=c*c*Math.acos(J((l*l+c*c-u*u)/(2*l*c),-1,1))+u*u*Math.acos(J((l*l+u*u-c*c)/(2*l*u),-1,1))-.5*Math.sqrt(Math.max(0,(-l+c+u)*(l+c-u)*(l-c+u)*(l+c+u)));return J(1-t/(Math.PI*c*c),0,1)},Le={u1:.93,u2:-.23},Se=e=>e<0?`(${e.toFixed(4)})`:e.toFixed(4),ta=`
  float limbDarkening(float rho) {
    float mu = sqrt(max(1.0 - rho * rho, 0.0));
    float t = 1.0 - mu;
    return max(1.0 - ${Se(Le.u1)} * t - ${Se(Le.u2)} * t * t, 0.0);
  }
`,aa=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Ue=new Uint16Array([0,1,2,1,3,2]),oa=e=>`${e.vertexShaderPrelude}
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
}`,ra=()=>`precision highp float;
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
${Be}
${ta}

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
}`,na=({date:e=new Date,haloScale:a=Jt,haloStrength:s=1,discGain:l=1.15,brightness:c=1,coreColour:u=[1,.985,.95],haloColour:t=[1,.65,.2]}={})=>{let m=null,n=null,v=null;const p=new Map;let h={date:e,haloScale:a,haloStrength:s,discGain:l,brightness:c,coreColour:u,haloColour:t};const f=A=>{const _=A.variantName;if(p.has(_))return p.get(_);const i=Z(n,oa(A),ra(),"tm-sun"),y={program:i,attribs:{corner:n.getAttribLocation(i,"a_corner")},uniforms:{centre:n.getUniformLocation(i,"a_centre"),elevationGlobe:n.getUniformLocation(i,"a_elevation_globe"),elevationMercator:n.getUniformLocation(i,"a_elevation_mercator"),size:n.getUniformLocation(i,"u_size"),camera:n.getUniformLocation(i,"u_camera"),forward:n.getUniformLocation(i,"u_forward"),right:n.getUniformLocation(i,"u_right"),up:n.getUniformLocation(i,"u_up"),glowAngle:n.getUniformLocation(i,"u_glow_angle"),discFraction:n.getUniformLocation(i,"u_disc_fraction"),discGain:n.getUniformLocation(i,"u_disc_gain"),visible:n.getUniformLocation(i,"u_visible"),brightness:n.getUniformLocation(i,"u_brightness"),haloStrength:n.getUniformLocation(i,"u_halo_strength"),coreColour:n.getUniformLocation(i,"u_core_colour"),haloColour:n.getUniformLocation(i,"u_halo_colour"),matrix:n.getUniformLocation(i,"u_projection_matrix"),tileMercatorCoords:n.getUniformLocation(i,"u_projection_tile_mercator_coords"),clippingPlane:n.getUniformLocation(i,"u_projection_clipping_plane"),transition:n.getUniformLocation(i,"u_projection_transition"),fallbackMatrix:n.getUniformLocation(i,"u_projection_fallback_matrix")}};return p.set(_,y),y};return{id:et,type:"custom",renderingMode:"3d",onAdd(A,_){m=A,n=_;const i=n.createBuffer();n.bindBuffer(n.ARRAY_BUFFER,i),n.bufferData(n.ARRAY_BUFFER,aa,n.STATIC_DRAW);const y=n.createBuffer();n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,y),n.bufferData(n.ELEMENT_ARRAY_BUFFER,Ue,n.STATIC_DRAW),v={corner:i,index:y}},onRemove(){n&&(p.forEach(({program:A})=>n.deleteProgram(A)),p.clear(),v&&(n.deleteBuffer(v.corner),n.deleteBuffer(v.index),v=null),m=null,n=null)},render(A,_){if(!v||h.brightness<=0)return;const i=_&&_.shaderData,y=_&&_.defaultProjectionData;if(!i||!y)return;const{program:E,attribs:N,uniforms:b}=f(i);n.useProgram(E),b.matrix&&n.uniformMatrix4fv(b.matrix,!1,y.mainMatrix),b.tileMercatorCoords&&n.uniform4f(b.tileMercatorCoords,...y.tileMercatorCoords),b.clippingPlane&&n.uniform4f(b.clippingPlane,...y.clippingPlane),b.transition&&n.uniform1f(b.transition,y.projectionTransition),b.fallbackMatrix&&n.uniformMatrix4fv(b.fallbackMatrix,!1,y.fallbackMatrix);const o=ee(m,Y),I=Je(h.date),M=W(...sa(ne(h.date)),I-xe),r=ke([M[0]-o[0],M[1]-o[1],M[2]-o[2]]),D=Math.hypot(...o),F=Math.max(.25*(D-1),.002),T=[o[0]+r[0]*F,o[1]+r[1]*F,o[2]+r[2]*F],G=Math.hypot(...T),O=Math.asin(T[1]/G)*180/Math.PI,k=Math.atan2(T[2],T[0])*180/Math.PI,w=(G-1)*xe,L=Y.MercatorCoordinate.fromLngLat([k,O],0);b.centre&&n.uniform2f(b.centre,L.x,L.y),b.elevationGlobe&&n.uniform1f(b.elevationGlobe,w),b.elevationMercator&&n.uniform1f(b.elevationMercator,Y.MercatorCoordinate.fromLngLat([k,O],w).z);const S=Vt(h.date),d=S*h.haloScale,U=(_.fov||.6435)/2,C=m.getCanvas(),x=Math.tan(d)/Math.tan(U);b.size&&n.uniform2f(b.size,x*(C.height/C.width),x),b.glowAngle&&n.uniform1f(b.glowAngle,d),b.discFraction&&n.uniform1f(b.discFraction,1/h.haloScale),b.discGain&&n.uniform1f(b.discGain,h.discGain);const g=[-o[0]/D,-o[1]/D,-o[2]/D],B=Math.acos(J(ia(g,r),-1,1)),P=Math.asin(J(1/D,-1,1)),R=ea(B,S,P),q=r,X=ke(De([0,1,0],q)),z=De(q,X);b.forward&&n.uniform3f(b.forward,...q),b.right&&n.uniform3f(b.right,...X),b.up&&n.uniform3f(b.up,...z),b.camera&&n.uniform3f(b.camera,...o),b.visible&&n.uniform1f(b.visible,R),b.brightness&&n.uniform1f(b.brightness,h.brightness),b.haloStrength&&n.uniform1f(b.haloStrength,h.haloStrength),b.coreColour&&n.uniform3f(b.coreColour,...h.coreColour),b.haloColour&&n.uniform3f(b.haloColour,...h.haloColour),n.bindBuffer(n.ARRAY_BUFFER,v.corner),n.enableVertexAttribArray(N.corner),n.vertexAttribPointer(N.corner,2,n.FLOAT,!1,0,0),n.disable(n.DEPTH_TEST),n.depthMask(!1),n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,v.index),n.drawElements(n.TRIANGLES,Ue.length,n.UNSIGNED_SHORT,0),n.depthMask(!0)},setOptions(A={}){h={...h,...A},m?.triggerRepaint()},getOptions:()=>({...h})}},J=(e,a,s)=>Math.min(s,Math.max(a,e)),ia=(e,a)=>e[0]*a[0]+e[1]*a[1]+e[2]*a[2],ke=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},De=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],sa=([e,a,s])=>[Math.atan2(s,e)*180/Math.PI,Math.asin(J(a,-1,1))*180/Math.PI],la=et,tt="tm-moon",ie=63710088e-1,ca=1737400,Q=Math.PI/180,ha=Date.UTC(2e3,0,1,12),ua=Date.UTC(1999,11,31,0),$=e=>Math.sin(e*Q),H=e=>Math.cos(e*Q),at=(e=new Date)=>{const a=(e.getTime()-ha)/864e5,s=(e.getTime()-ua)/864e5,l=125.1228-.0529538083*s,c=5.1454,u=318.0634+.1643573223*s,t=60.2666,m=.0549,n=115.3654+13.0649929509*s;let v=n+m*180/Math.PI*$(n)*(1+m*H(n));for(let d=0;d<3;d++)v-=(v-m*180/Math.PI*$(v)-n)/(1-m*H(v));const p=t*(H(v)-m),h=t*(Math.sqrt(1-m*m)*$(v)),f=Math.atan2(h,p)/Q,A=Math.sqrt(p*p+h*h);let _=A*(H(l)*H(f+u)-$(l)*$(f+u)*H(c)),i=A*($(l)*H(f+u)+H(l)*$(f+u)*H(c)),y=A*($(f+u)*$(c));const E=356.047+.9856002585*s,N=282.9404+470935e-10*s+E,b=l+u+n,o=b-N,I=b-l;let M=Math.atan2(i,_)/Q,r=Math.atan2(y,Math.hypot(_,i))/Q;M+=-1.274*$(n-2*o)+.658*$(2*o)-.186*$(E),r+=-.173*$(I-2*o);const D=(A-.58*H(n-2*o)-.46*H(2*o))*ie,F=23.4393-3563e-10*a,T=H(M)*H(r),G=$(M)*H(r)*H(F)-$(r)*$(F),O=$(M)*H(r)*$(F)+$(r)*H(F),k=Math.atan2(G,T)/Q,w=Math.atan2(O,Math.hypot(T,G))/Q,L=(18.697374558+24.06570982441908*a)%24;let S=k-L*15;return S=(S%360+540)%360-180,{lng:S,lat:w,distance:D}},ot=(e=new Date)=>{const{lng:a,lat:s,distance:l}=at(e);return W(a,s,l-ie)},fa=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Pe=new Uint16Array([0,1,2,1,3,2]),da=e=>`${e.vertexShaderPrelude}
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
}`,ma=()=>`precision highp float;
varying vec2 v_corner;
uniform sampler2D u_albedo;
uniform vec3 u_sun;             // direction TO the sun, planet space
uniform vec3 u_right;           // the billboard's basis, in planet space
uniform vec3 u_up;
uniform vec3 u_forward;         // from the camera toward the moon
uniform float u_brightness;
uniform float u_hasAlbedo;
${he}

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
}`,ga=({date:e=new Date,sun:a=[1,0,0],albedoUrl:s=null,sizeScale:l=1,brightness:c=1}={})=>{let u=null,t=null,m=null,n=null,v=!1;const p=new Map;let h={date:e,sun:a,albedoUrl:s,sizeScale:l,brightness:c};const f=_=>{const i=_.variantName;if(p.has(i))return p.get(i);const y=Z(t,da(_),ma(),"tm-moon"),E={program:y,attribs:{corner:t.getAttribLocation(y,"a_corner")},uniforms:{centre:t.getUniformLocation(y,"a_centre"),elevationGlobe:t.getUniformLocation(y,"a_elevation_globe"),elevationMercator:t.getUniformLocation(y,"a_elevation_mercator"),size:t.getUniformLocation(y,"u_size"),albedo:t.getUniformLocation(y,"u_albedo"),hasAlbedo:t.getUniformLocation(y,"u_hasAlbedo"),sun:t.getUniformLocation(y,"u_sun"),right:t.getUniformLocation(y,"u_right"),up:t.getUniformLocation(y,"u_up"),forward:t.getUniformLocation(y,"u_forward"),brightness:t.getUniformLocation(y,"u_brightness"),matrix:t.getUniformLocation(y,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(y,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(y,"u_projection_clipping_plane"),transition:t.getUniformLocation(y,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(y,"u_projection_fallback_matrix")}};return p.set(i,E),E},A=_=>{const i=new Image;i.crossOrigin="anonymous",i.onload=()=>{t&&(n=n||t.createTexture(),t.bindTexture(t.TEXTURE_2D,n),t.texImage2D(t.TEXTURE_2D,0,t.RGBA,t.RGBA,t.UNSIGNED_BYTE,i),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_S,t.REPEAT),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_T,t.CLAMP_TO_EDGE),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MIN_FILTER,t.LINEAR),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MAG_FILTER,t.LINEAR),v=!0,u?.triggerRepaint())},i.src=_};return{id:tt,type:"custom",renderingMode:"3d",onAdd(_,i){u=_,t=i;const y=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,y),t.bufferData(t.ARRAY_BUFFER,fa,t.STATIC_DRAW);const E=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,E),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Pe,t.STATIC_DRAW),m={corner:y,index:E},h.albedoUrl&&A(h.albedoUrl)},onRemove(){t&&(p.forEach(({program:_})=>t.deleteProgram(_)),p.clear(),n&&(t.deleteTexture(n),n=null,v=!1),m&&(t.deleteBuffer(m.corner),t.deleteBuffer(m.index),m=null),u=null,t=null)},render(_,i){if(!m||h.brightness<=0)return;const y=i&&i.shaderData,E=i&&i.defaultProjectionData;if(!y||!E)return;const{lng:N,lat:b,distance:o}=at(h.date),{program:I,attribs:M,uniforms:r}=f(y);t.useProgram(I),r.matrix&&t.uniformMatrix4fv(r.matrix,!1,E.mainMatrix),r.tileMercatorCoords&&t.uniform4f(r.tileMercatorCoords,...E.tileMercatorCoords),r.clippingPlane&&t.uniform4f(r.clippingPlane,...E.clippingPlane),r.transition&&t.uniform1f(r.transition,E.projectionTransition),r.fallbackMatrix&&t.uniformMatrix4fv(r.fallbackMatrix,!1,E.fallbackMatrix);const D=ee(u,Y),F=W(N,b,o-ie),T=Ie([F[0]-D[0],F[1]-D[1],F[2]-D[2]]),O=Math.hypot(...D)+1.2,k=[D[0]+T[0]*O,D[1]+T[1]*O,D[2]+T[2]*O],w=Math.hypot(...k),L=Math.asin(k[1]/w)*180/Math.PI,S=Math.atan2(k[2],k[0])*180/Math.PI,d=(w-1)*ie,U=Y.MercatorCoordinate.fromLngLat([S,L],0);r.centre&&t.uniform2f(r.centre,U.x,U.y),r.elevationGlobe&&t.uniform1f(r.elevationGlobe,d),r.elevationMercator&&t.uniform1f(r.elevationMercator,Y.MercatorCoordinate.fromLngLat([S,L],d).z);const C=Math.atan(ca*h.sizeScale/o),x=(i.fov||.6435)/2,g=u.getCanvas(),B=Math.tan(C)/Math.tan(x);r.size&&t.uniform2f(r.size,B*(g.height/g.width),B);const P=T,R=Ie(Fe([0,1,0],P)),q=Fe(P,R);r.forward&&t.uniform3f(r.forward,...P),r.right&&t.uniform3f(r.right,...R),r.up&&t.uniform3f(r.up,...q),r.sun&&t.uniform3f(r.sun,...h.sun),r.brightness&&t.uniform1f(r.brightness,h.brightness),r.hasAlbedo&&t.uniform1f(r.hasAlbedo,v?1:0),v&&r.albedo&&(t.activeTexture(t.TEXTURE0),t.bindTexture(t.TEXTURE_2D,n),t.uniform1i(r.albedo,0)),t.bindBuffer(t.ARRAY_BUFFER,m.corner),t.enableVertexAttribArray(M.corner),t.vertexAttribPointer(M.corner,2,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,m.index),t.drawElements(t.TRIANGLES,Pe.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(_={}){const i=h.albedoUrl;h={...h,..._},_.albedoUrl!==void 0&&_.albedoUrl!==i&&(v=!1,_.albedoUrl&&A(_.albedoUrl)),u?.triggerRepaint()},getOptions:()=>({...h}),get hasAlbedo(){return v}}},Ie=e=>{const a=Math.hypot(...e)||1;return[e[0]/a,e[1]/a,e[2]/a]},Fe=(e,a)=>[e[1]*a[2]-e[2]*a[1],e[2]*a[0]-e[0]*a[2],e[0]*a[1]-e[1]*a[0]],pa=tt,_a=`
  mat3 equirectTangentFrame(vec3 unitPos) {
    vec3 up = normalize(unitPos);
    vec3 east = cross(up, vec3(0.0, 1.0, 0.0));
    float span = length(east);
    // Standing on a pole, every direction is south and no direction is east. Pick one rather than
    // dividing by zero and putting a NaN in the middle of Antarctica.
    east = span > 1.0e-4 ? east / span : vec3(0.0, 0.0, 1.0);
    return mat3(east, cross(up, east), up);
  }
`,wa=`
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
`,ba=`
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
`,ya=(e,a)=>2*Math.PI*se*Math.cos(a*Math.PI/180)/e,va=(e,a,s)=>Math.min(s,Math.max(a,e)),Ea=(e,a,s)=>{const l=va((s-e)/(a-e),0,1);return l*l*(3-2*l)},Aa=(e,a,s)=>{const l=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e)/2,c=ya(s,a)/l;return{pixelsPerTexel:c,strength:1-Ea(3,5,Math.log2(Math.max(c,1e-6)))}},Ta=6957e5,Ra=1737400,Ma=63710088e-1,xa=Ra/Ma,La=(e,a,s)=>Math.min(s,Math.max(a,e)),Sa=(e=new Date)=>Math.asin(Ta/Zt(e)),Ua=.035,ka=(e=new Date,a=null,s=null)=>{const l=a||ne(e),c=s||ot(e),u=Math.hypot(...c);return Math.acos(La((l[0]*c[0]+l[1]*c[1]+l[2]*c[2])/u,-1,1))<Ua},Da=`
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
`,rt="tm-daylight",ae={field:0,wind:1,relief:2,lights:3},Pa=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${ce}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ia=()=>`#version 300 es
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
${he}
${Ge}
${be}
${_a}
${wa}
${ba}
${Da}
// The same field, wind and clock the deck in clouds.js draws itself from.
${He}

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
  if (u_cloudShadow > 0.0 && u_fieldAmount > 0.0) {
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
    float remaining = eclipseLight(normal, sunDir, u_moon, u_sunRadius, ${xa.toFixed(8)});
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
}`,Fa=({sun:e=[1,0,0],nightDarkness:a=.965,lightsUrl:s=null,lightsAmount:l=0,nightColour:c=[.02,.035,.07],twilightColour:u=[.62,.32,.16],twilightCool:t=[.1,.15,.28],twilightStrength:m=.55,reliefUrl:n=null,reliefWidth:v=8192,reliefPower:p=1.5,cloudShadow:h=.5,eclipse:f=!0,date:A=null,fieldUrl:_=null,windUrl:i=null,windAmount:y=1,windScale:E=.06,windRate:N=.05,driftRate:b=4e-4,animate:o=!0}={})=>{const I=le();let M=null,r=null,D=null,F=null,T=null,G=null,O=null;const k=new Map;let w={sun:e,nightDarkness:a,lightsUrl:s,lightsAmount:l,nightColour:c,twilightColour:u,twilightCool:t,twilightStrength:m,reliefUrl:n,reliefWidth:v,reliefPower:p,cloudShadow:h,eclipse:f,date:A,fieldUrl:_,windUrl:i,windAmount:y,windScale:E,windRate:N,driftRate:b,animate:o};const L=U=>{const C=U.variantName;if(k.has(C))return k.get(C);const x=Z(r,Pa(U),Ia(),"tm-daylight"),g={program:x,attribs:{pos:r.getAttribLocation(x,"a_pos"),sphere:r.getAttribLocation(x,"a_sphere")},uniforms:{elevationGlobe:r.getUniformLocation(x,"a_elevation_globe"),elevationMercator:r.getUniformLocation(x,"a_elevation_mercator"),sun:r.getUniformLocation(x,"u_sun"),camera:r.getUniformLocation(x,"u_camera"),globeness:r.getUniformLocation(x,"u_globeness"),nightDarkness:r.getUniformLocation(x,"u_nightDarkness"),nightColour:r.getUniformLocation(x,"u_nightColour"),twilightColour:r.getUniformLocation(x,"u_twilightColour"),twilightCool:r.getUniformLocation(x,"u_twilightCool"),twilightStrength:r.getUniformLocation(x,"u_twilightStrength"),lights:r.getUniformLocation(x,"u_lights"),lightsAmount:r.getUniformLocation(x,"u_lightsAmount"),relief:r.getUniformLocation(x,"u_relief"),reliefPower:r.getUniformLocation(x,"u_reliefPower"),cloudShadow:r.getUniformLocation(x,"u_cloudShadow"),cloudAltitude:r.getUniformLocation(x,"u_cloudAltitude"),moon:r.getUniformLocation(x,"u_moon"),sunRadius:r.getUniformLocation(x,"u_sunRadius"),...Object.fromEntries(ze.map(B=>[B,r.getUniformLocation(x,`u_${B}`)])),matrix:r.getUniformLocation(x,"u_projection_matrix"),tileMercatorCoords:r.getUniformLocation(x,"u_projection_tile_mercator_coords"),clippingPlane:r.getUniformLocation(x,"u_projection_clipping_plane"),transition:r.getUniformLocation(x,"u_projection_transition"),fallbackMatrix:r.getUniformLocation(x,"u_projection_fallback_matrix")}};return k.set(C,g),g},S=()=>M?.triggerRepaint(),d=(U,C,x,g)=>{!C?.ready||!U[x]||(r.activeTexture(r.TEXTURE0+g),r.bindTexture(r.TEXTURE_2D,C.texture),r.uniform1i(U[x],g))};return{id:rt,type:"custom",renderingMode:"3d",onAdd(U,C){M=U,r=C;const x=(g,B)=>{const P=r.createBuffer();return r.bindBuffer(g,P),r.bufferData(g,B,r.STATIC_DRAW),P};D={pos:x(r.ARRAY_BUFFER,I.positions),sphere:x(r.ARRAY_BUFFER,I.spheres),index:x(r.ELEMENT_ARRAY_BUFFER,I.indices)},w.lightsUrl&&(F=K(r,w.lightsUrl,S)),w.reliefUrl&&(T=K(r,w.reliefUrl,S)),w.fieldUrl&&(G=K(r,w.fieldUrl,S)),w.windUrl&&(O=K(r,w.windUrl,S))},onRemove(){r&&(k.forEach(({program:U})=>r.deleteProgram(U)),k.clear(),F?.release(),F=null,T?.release(),T=null,G?.release(),G=null,O?.release(),O=null,D&&(r.deleteBuffer(D.pos),r.deleteBuffer(D.sphere),r.deleteBuffer(D.index),D=null),M=null,r=null)},render(U,C){if(!D||w.nightDarkness<=0)return;const x=C&&C.shaderData,g=C&&C.defaultProjectionData;if(!x||!g)return;const{program:B,attribs:P,uniforms:R}=L(x);r.useProgram(B),R.matrix&&r.uniformMatrix4fv(R.matrix,!1,g.mainMatrix),R.tileMercatorCoords&&r.uniform4f(R.tileMercatorCoords,...g.tileMercatorCoords),R.clippingPlane&&r.uniform4f(R.clippingPlane,...g.clippingPlane),R.transition&&r.uniform1f(R.transition,g.projectionTransition),R.fallbackMatrix&&r.uniformMatrix4fv(R.fallbackMatrix,!1,g.fallbackMatrix);const q=M.getCenter().lat,X=0;R.elevationGlobe&&r.uniform1f(R.elevationGlobe,X),R.elevationMercator&&r.uniform1f(R.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,q],X).z),R.sun&&r.uniform3f(R.sun,...w.sun),R.camera&&r.uniform3f(R.camera,...ee(M,Y)),R.globeness&&r.uniform1f(R.globeness,g.projectionTransition),R.nightDarkness&&r.uniform1f(R.nightDarkness,w.nightDarkness),R.nightColour&&r.uniform3f(R.nightColour,...w.nightColour),R.twilightColour&&r.uniform3f(R.twilightColour,...w.twilightColour),R.twilightCool&&r.uniform3f(R.twilightCool,...w.twilightCool),R.twilightStrength&&r.uniform1f(R.twilightStrength,w.twilightStrength),R.lightsAmount&&r.uniform1f(R.lightsAmount,F?.ready?w.lightsAmount:0);const z=T?.ready?Aa(M.getZoom(),q,w.reliefWidth).strength:0;R.reliefPower&&r.uniform1f(R.reliefPower,w.reliefPower*z),R.cloudShadow&&r.uniform1f(R.cloudShadow,w.cloudShadow),R.cloudAltitude&&r.uniform1f(R.cloudAltitude,oe/se);const te=w.eclipse?w.date:null,ue=te&&ka(te)?ot(te):null;R.sunRadius&&r.uniform1f(R.sunRadius,ue?Sa(te):0),ue&&R.moon&&r.uniform3f(R.moon,...ue),Ye(r,R,w,{seconds:performance.now()*.001,field:G,wind:O},ae.field,ae.wind),d(R,T,"relief",ae.relief),d(R,F,"lights",ae.lights),r.bindBuffer(r.ARRAY_BUFFER,D.pos),r.enableVertexAttribArray(P.pos),r.vertexAttribPointer(P.pos,2,r.FLOAT,!1,0,0),r.bindBuffer(r.ARRAY_BUFFER,D.sphere),r.enableVertexAttribArray(P.sphere),r.vertexAttribPointer(P.sphere,3,r.FLOAT,!1,0,0),r.disable(r.DEPTH_TEST),r.depthMask(!1),r.bindBuffer(r.ELEMENT_ARRAY_BUFFER,D.index),r.drawElements(r.TRIANGLES,I.indices.length,r.UNSIGNED_SHORT,0),r.enable(r.DEPTH_TEST),r.depthMask(!0),w.animate&&G?.ready&&w.cloudShadow>0&&M.triggerRepaint()},setOptions(U={}){const C=w;if(w={...w,...U},!r){M?.triggerRepaint();return}const x=(g,B)=>U[B]===void 0||U[B]===C[B]?g:(g?.release(),w[B]?K(r,w[B],S):null);F=x(F,"lightsUrl"),T=x(T,"reliefUrl"),G=x(G,"fieldUrl"),O=x(O,"windUrl"),M?.triggerRepaint()},getOptions:()=>({...w}),get hasLights(){return!!F?.ready},get hasRelief(){return!!T?.ready}}},Ca=rt,nt="tm-atmosphere",Na=63710088e-1,me=2e5,Oa=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${ce}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ba=()=>`precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space (earth = unit sphere)
uniform vec3 u_sun;         // direction TO the sun
uniform float u_top;        // top of the atmosphere, in earth radii
uniform float u_strength;
uniform vec3 u_dayColour;
uniform vec3 u_duskColour;

const int SAMPLES = 6;

${Be}

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
}`,Ga=({strength:e=1,sun:a=[.4,.5,.75],dayColour:s=[.32,.55,1],duskColour:l=[1,.45,.18]}={})=>{const c=le();let u=null,t=null,m=null;const n=new Map;let v={strength:e,sun:a,dayColour:s,duskColour:l};const p=h=>{const f=h.variantName;if(n.has(f))return n.get(f);const A=Z(t,Oa(h),Ba(),"tm-atmosphere"),_={program:A,attribs:{pos:t.getAttribLocation(A,"a_pos"),sphere:t.getAttribLocation(A,"a_sphere")},uniforms:{elevationGlobe:t.getUniformLocation(A,"a_elevation_globe"),elevationMercator:t.getUniformLocation(A,"a_elevation_mercator"),camera:t.getUniformLocation(A,"u_camera"),sun:t.getUniformLocation(A,"u_sun"),top:t.getUniformLocation(A,"u_top"),strength:t.getUniformLocation(A,"u_strength"),dayColour:t.getUniformLocation(A,"u_dayColour"),duskColour:t.getUniformLocation(A,"u_duskColour"),matrix:t.getUniformLocation(A,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(A,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(A,"u_projection_clipping_plane"),transition:t.getUniformLocation(A,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(A,"u_projection_fallback_matrix")}};return n.set(f,_),_};return{id:nt,type:"custom",renderingMode:"3d",onAdd(h,f){u=h,t=f;const A=(_,i)=>{const y=t.createBuffer();return t.bindBuffer(_,y),t.bufferData(_,i,t.STATIC_DRAW),y};m={pos:A(t.ARRAY_BUFFER,c.positions),sphere:A(t.ARRAY_BUFFER,c.spheres),index:A(t.ELEMENT_ARRAY_BUFFER,c.indices)}},onRemove(){t&&(n.forEach(({program:h})=>t.deleteProgram(h)),n.clear(),m&&(t.deleteBuffer(m.pos),t.deleteBuffer(m.sphere),t.deleteBuffer(m.index),m=null),u=null,t=null)},render(h,f){if(!m||v.strength<=0)return;const A=f&&f.shaderData,_=f&&f.defaultProjectionData;if(!A||!_)return;const{program:i,attribs:y,uniforms:E}=p(A);t.useProgram(i),E.matrix&&t.uniformMatrix4fv(E.matrix,!1,_.mainMatrix),E.tileMercatorCoords&&t.uniform4f(E.tileMercatorCoords,..._.tileMercatorCoords),E.clippingPlane&&t.uniform4f(E.clippingPlane,..._.clippingPlane),E.transition&&t.uniform1f(E.transition,_.projectionTransition),E.fallbackMatrix&&t.uniformMatrix4fv(E.fallbackMatrix,!1,_.fallbackMatrix);const N=u.getCenter().lat;E.elevationGlobe&&t.uniform1f(E.elevationGlobe,me),E.elevationMercator&&t.uniform1f(E.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,N],me).z);const b=ee(u,Y);E.camera&&t.uniform3f(E.camera,b[0],b[1],b[2]),E.sun&&t.uniform3f(E.sun,...v.sun),E.top&&t.uniform1f(E.top,1+me/Na),E.strength&&t.uniform1f(E.strength,v.strength),E.dayColour&&t.uniform3f(E.dayColour,...v.dayColour),E.duskColour&&t.uniform3f(E.duskColour,...v.duskColour),t.bindBuffer(t.ARRAY_BUFFER,m.pos),t.enableVertexAttribArray(y.pos),t.vertexAttribPointer(y.pos,2,t.FLOAT,!1,0,0),t.bindBuffer(t.ARRAY_BUFFER,m.sphere),t.enableVertexAttribArray(y.sphere),t.vertexAttribPointer(y.sphere,3,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,m.index),t.drawElements(t.TRIANGLES,c.indices.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(h={}){v={...v,...h},u?.triggerRepaint()},getOptions:()=>({...v})}},$a=nt,Ha=32,it=[{width:8192,height:4096,url:"/timemap/ocean-sdf-8192.webp"},{width:4096,height:2048,url:"/timemap/ocean-sdf-4096.webp"}],za=`
  float oceanDistanceKm(float stored, float rangeKm) {
    return (stored * 2.0 - 1.0) * rangeKm;
  }
`,st="tm-ocean",Ya=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${ce}
void main() {
  v_sphere = a_sphere;
  // Sea level: the glint belongs on the water, not above it.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,ja=()=>`precision highp float;
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
${he}
${Oe}
${Ge}
${be}
${za}

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
}`,qa=(e,{fadeInAbove:a=9e5,fadeOutBelow:s=18e4}={})=>{const l=Math.max(0,Math.min(1,(e-s)/(a-s)));return l*l*(3-2*l)},Xa=(e,a,s=.8)=>{const l=156543.03392*Math.cos(a*Math.PI/180)/Math.pow(2,e);return Math.max(s,l*1.5/1e3)},Ka=40075,Wa=e=>Number.isFinite(e)&&e>0?Ka/e/2:.8,Ce=(e,a=it)=>a.find(s=>s.width<=e&&s.height<=e)||null,Va=({opacity:e=1,strength:a=.9,roughness:s=.55,windPatch:l=.3,windScale:c=14,edgeRemap:u=0,sun:t=[.4,.5,.75],water:m=1,absorption:n=[.45,.06,.02],scatter:v=[.12,.265,.43],bottom:p=[.42,.4,.33],sky:h=[.34,.5,.76],shelfKm:f=22,shelfDepthM:A=16,shoreSoftnessKm:_=.8,fadeInAbove:i=9e5,fadeOutBelow:y=18e4,sources:E=it}={})=>{const N=le();let b=null,o=null,I=null,M=null,r=null,D=!1;const F=new Map;let T={opacity:e,strength:a,roughness:s,windPatch:l,windScale:c,edgeRemap:u,sun:t,water:m,absorption:n,scatter:v,bottom:p,sky:h,shelfKm:f,shelfDepthM:A,shoreSoftnessKm:_,fadeInAbove:i,fadeOutBelow:y,sources:E};const G=L=>{const S=L.variantName;if(F.has(S))return F.get(S);const d=Z(o,Ya(L),ja(),"tm-ocean",{es300:!0}),U={program:d,attribs:{pos:o.getAttribLocation(d,"a_pos"),sphere:o.getAttribLocation(d,"a_sphere")},uniforms:{elevationGlobe:o.getUniformLocation(d,"a_elevation_globe"),elevationMercator:o.getUniformLocation(d,"a_elevation_mercator"),camera:o.getUniformLocation(d,"u_camera"),sun:o.getUniformLocation(d,"u_sun"),globeness:o.getUniformLocation(d,"u_globeness"),field:o.getUniformLocation(d,"u_field"),rangeKm:o.getUniformLocation(d,"u_rangeKm"),shoreKm:o.getUniformLocation(d,"u_shoreKm"),shelfKm:o.getUniformLocation(d,"u_shelfKm"),strength:o.getUniformLocation(d,"u_strength"),roughness:o.getUniformLocation(d,"u_roughness"),windPatch:o.getUniformLocation(d,"u_windPatch"),windScale:o.getUniformLocation(d,"u_windScale"),water:o.getUniformLocation(d,"u_water"),scatter:o.getUniformLocation(d,"u_scatter"),bottom:o.getUniformLocation(d,"u_bottom"),absorption:o.getUniformLocation(d,"u_absorption"),shelfDepthM:o.getUniformLocation(d,"u_shelfDepthM"),sky:o.getUniformLocation(d,"u_sky"),fade:o.getUniformLocation(d,"u_fade"),opacity:o.getUniformLocation(d,"u_opacity"),edgeRemap:o.getUniformLocation(d,"u_edgeRemap"),matrix:o.getUniformLocation(d,"u_projection_matrix"),tileMercatorCoords:o.getUniformLocation(d,"u_projection_tile_mercator_coords"),clippingPlane:o.getUniformLocation(d,"u_projection_clipping_plane"),transition:o.getUniformLocation(d,"u_projection_transition"),fallbackMatrix:o.getUniformLocation(d,"u_projection_fallback_matrix")}};return F.set(S,U),U},O=()=>{r=o.createTexture(),o.bindTexture(o.TEXTURE_2D,r),o.texImage2D(o.TEXTURE_2D,0,o.LUMINANCE,1,1,0,o.LUMINANCE,o.UNSIGNED_BYTE,new Uint8Array([0])),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MIN_FILTER,o.LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MAG_FILTER,o.LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_S,o.CLAMP_TO_EDGE),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_T,o.CLAMP_TO_EDGE)},k=L=>{o&&(r&&o.deleteTexture(r),r=o.createTexture(),o.bindTexture(o.TEXTURE_2D,r),o.pixelStorei(o.UNPACK_FLIP_Y_WEBGL,!1),$e(o)?o.texImage2D(o.TEXTURE_2D,0,o.R8,o.RED,o.UNSIGNED_BYTE,L):o.texImage2D(o.TEXTURE_2D,0,o.LUMINANCE,o.LUMINANCE,o.UNSIGNED_BYTE,L),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_S,o.REPEAT),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_WRAP_T,o.CLAMP_TO_EDGE),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MIN_FILTER,o.LINEAR_MIPMAP_LINEAR),o.texParameteri(o.TEXTURE_2D,o.TEXTURE_MAG_FILTER,o.LINEAR),o.generateMipmap(o.TEXTURE_2D),D=!0,b?.triggerRepaint())},w=()=>{const L=Ce(o.getParameter(o.MAX_TEXTURE_SIZE),T.sources);if(!L)return;const S=L.url;I=L.width,fetch(S,{credentials:"omit"}).then(d=>d.ok?d.blob():Promise.reject(new Error(d.status))).then(d=>createImageBitmap(d,{colorSpaceConversion:"none",premultiplyAlpha:"none",imageOrientation:"none"})).then(d=>{if(Ce(o?.getParameter(o.MAX_TEXTURE_SIZE)??0,T.sources)?.url!==S){d.close?.();return}k(d),d.close?.()}).catch(()=>{})};return{id:st,type:"custom",renderingMode:"3d",onAdd(L,S){b=L,o=S;const d=(U,C)=>{const x=o.createBuffer();return o.bindBuffer(U,x),o.bufferData(U,C,o.STATIC_DRAW),x};M={pos:d(o.ARRAY_BUFFER,N.positions),sphere:d(o.ARRAY_BUFFER,N.spheres),index:d(o.ELEMENT_ARRAY_BUFFER,N.indices)},O(),w()},onRemove(){o&&(F.forEach(({program:L})=>o.deleteProgram(L)),F.clear(),r&&(o.deleteTexture(r),r=null,D=!1),M&&(o.deleteBuffer(M.pos),o.deleteBuffer(M.sphere),o.deleteBuffer(M.index),M=null),b=null,o=null)},render(L,S){if(!M||T.opacity<=0)return;const d=S&&S.shaderData,U=S&&S.defaultProjectionData;if(!d||!U)return;const{program:C,attribs:x,uniforms:g}=G(d);o.useProgram(C),g.matrix&&o.uniformMatrix4fv(g.matrix,!1,U.mainMatrix),g.tileMercatorCoords&&o.uniform4f(g.tileMercatorCoords,...U.tileMercatorCoords),g.clippingPlane&&o.uniform4f(g.clippingPlane,...U.clippingPlane),g.transition&&o.uniform1f(g.transition,U.projectionTransition),g.fallbackMatrix&&o.uniformMatrix4fv(g.fallbackMatrix,!1,U.fallbackMatrix);const B=b.getCenter(),P=0;g.elevationGlobe&&o.uniform1f(g.elevationGlobe,P),g.elevationMercator&&o.uniform1f(g.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,B.lat],P).z),g.camera&&o.uniform3f(g.camera,...ee(b,Y)),g.sun&&o.uniform3f(g.sun,...T.sun),g.globeness&&o.uniform1f(g.globeness,U.projectionTransition),g.field&&(o.activeTexture(o.TEXTURE0),o.bindTexture(o.TEXTURE_2D,r),o.uniform1i(g.field,0)),g.rangeKm&&o.uniform1f(g.rangeKm,Ha),g.shoreKm&&o.uniform1f(g.shoreKm,Xa(b.getZoom(),B.lat,Math.max(T.shoreSoftnessKm,Wa(I)))),g.shelfKm&&o.uniform1f(g.shelfKm,T.shelfKm),g.strength&&o.uniform1f(g.strength,T.strength),g.roughness&&o.uniform1f(g.roughness,T.roughness),g.windPatch&&o.uniform1f(g.windPatch,T.windPatch),g.windScale&&o.uniform1f(g.windScale,T.windScale),g.water&&o.uniform1f(g.water,T.water),g.scatter&&o.uniform3f(g.scatter,...T.scatter),g.bottom&&o.uniform3f(g.bottom,...T.bottom),g.absorption&&o.uniform3f(g.absorption,...T.absorption),g.shelfDepthM&&o.uniform1f(g.shelfDepthM,T.shelfDepthM),g.sky&&o.uniform3f(g.sky,...T.sky),g.opacity&&o.uniform1f(g.opacity,T.opacity),g.edgeRemap&&o.uniform1f(g.edgeRemap,T.edgeRemap),g.fade&&o.uniform1f(g.fade,qa(ye(b,Y),T)),o.bindBuffer(o.ARRAY_BUFFER,M.pos),o.enableVertexAttribArray(x.pos),o.vertexAttribPointer(x.pos,2,o.FLOAT,!1,0,0),o.bindBuffer(o.ARRAY_BUFFER,M.sphere),o.enableVertexAttribArray(x.sphere),o.vertexAttribPointer(x.sphere,3,o.FLOAT,!1,0,0),o.disable(o.DEPTH_TEST),o.depthMask(!1),o.bindBuffer(o.ELEMENT_ARRAY_BUFFER,M.index),o.drawElements(o.TRIANGLES,N.indices.length,o.UNSIGNED_SHORT,0),o.enable(o.DEPTH_TEST),o.depthMask(!0)},setOptions(L={}){const S=T.sources;T={...T,...L},L.sources!==void 0&&L.sources!==S&&(D=!1,o&&w()),b?.triggerRepaint()},getOptions:()=>({...T}),get hasField(){return D}}},Za=st,ve=[{key:"starfield",option:"brightness",label:"Stars",default:.5,max:1},{key:"sun",option:"brightness",label:"Sun",default:1,max:1},{key:"moon",option:"brightness",label:"Moon",default:1,max:1},{key:"daylight",option:"nightDarkness",label:"Day and night",default:.965,max:1},{key:"atmosphere",option:"strength",label:"Atmosphere",default:1,max:1},{key:"clouds",option:"opacity",label:"Clouds",default:1,max:1},{key:"ocean",option:"opacity",label:"Ocean",default:1,max:1},{key:"relief",option:"reliefPower",label:"Relief",default:0,max:2,on:"daylight"}],we={key:"reference",default:0},Qa="tm-layers-v2",Ja=(e=globalThis.localStorage)=>{const a={};for(const s of ve)a[s.key]={visible:s.default>0,value:s.default};a[we.key]={visible:!1,value:we.default};try{const s=JSON.parse(e?.getItem(Qa)||"{}");for(const[l,c]of Object.entries(s)){if(!a[l]||!c||typeof c!="object")continue;const u=c.value;a[l]={visible:c.visible===!0,value:typeof u=="number"&&Number.isFinite(u)?u:a[l].value}}}catch{}return a},eo=(e,a)=>{const s=a?.visible?Number(a.value):0;return{[e.option]:Number.isFinite(s)?s:0}},to=(e,a)=>{const s=[];for(const l of ve){const c=e?.[l.on||l.key];!c||typeof c.setOptions!="function"||(c.setOptions(eo(l,a?.[l.key])),s.push(l.key))}return s},ao=(e,{onReference:a=null,target:s=globalThis}={})=>{const l=new Map(ve.map(c=>[c.key,c]));return s.__tmSetLayer=(c,u)=>{const t=Number.isFinite(Number(u))?Number(u):0;if(c===we.key)return a?.(t);const m=l.get(c);if(!m)return;const n=e?.[m.on||m.key];if(!(!n||typeof n.setOptions!="function"))return n.setOptions({[m.option]:t})},()=>{s.__tmSetLayer&&delete s.__tmSetLayer}},oo="/img/map/clouds-field.webp",ro="/img/map/wind-field.png",ge=e=>lt(e).rgb.map(a=>a/255),Ne=[["starfield",qt,e=>jt(e)],["sun",la,e=>na(e)],["moon",pa,e=>ga(e)],["ocean",Za,e=>Va(e)],["daylight",Ca,e=>Fa(e)],["clouds",vt,e=>yt(e)],["atmosphere",$a,e=>Ga(e)]],no=(e,{date:a=new Date,reduceMotion:s=!1,beforeId:l}={})=>{const c=ne(a),u=!s,t={fieldUrl:oo,windUrl:ro,windAmount:.2,animate:u},m={starfield:{date:a,animate:u},sun:{date:a},moon:{date:a,sun:c},ocean:{sun:c,roughness:.95,strength:.48,windPatch:.6,shoreSoftnessKm:19.5},clouds:{...t,sun:c},daylight:{...t,sun:c,date:a},atmosphere:{sun:c,strength:1.25}},n={};for(const[f,A,_]of Ne){e.getLayer(A)&&e.removeLayer(A);const i=_(m[f]);e.addLayer(i,l&&e.getLayer(l)?l:void 0),n[f]=i}to(n,Ja());const v=ao(n),p=(f,A)=>n[f]?.setOptions?.(A),h=[window.__tune?.register("Ocean",[{key:"shoreSoftnessKm",label:"Shore falloff",min:0,max:25,step:.25,value:19.5,apply:f=>p("ocean",{shoreSoftnessKm:f})},{key:"roughness",label:"Roughness",min:0,max:1,step:.01,value:.95,apply:f=>p("ocean",{roughness:f})},{key:"strength",label:"Sun glint",min:0,max:1,step:.01,value:.48,apply:f=>p("ocean",{strength:f})},{key:"windPatch",label:"Patchiness",min:0,max:1,step:.01,value:.6,apply:f=>p("ocean",{windPatch:f})},{key:"windScale",label:"Wave scale",min:1,max:60,step:1,value:14,apply:f=>p("ocean",{windScale:f})},{key:"water",label:"Tint depth",min:0,max:2,step:.05,value:1,apply:f=>p("ocean",{water:f})},{key:"scatter",label:"Tint colour",type:"color",value:"#1d5c8f",apply:f=>p("ocean",{scatter:ge(f)})},{key:"opacity",label:"Opacity",min:0,max:1,step:.01,value:1,apply:f=>p("ocean",{opacity:f})}],{tab:"Earth"}),window.__tune?.register("Clouds",[{key:"opacity",label:"Density",min:0,max:1,step:.01,value:1,apply:f=>p("clouds",{opacity:f})},{key:"windAmount",label:"Drift",min:0,max:1,step:.05,value:.2,apply:f=>p("clouds",{windAmount:f})},{key:"windScale",label:"Wind scale",min:.01,max:.5,step:.01,value:.06,apply:f=>p("clouds",{windScale:f})},{key:"windRate",label:"Wind speed",min:0,max:.3,step:.005,value:.05,apply:f=>p("clouds",{windRate:f})},{key:"cloudShadow",label:"Shadows",min:0,max:1,step:.05,value:.5,apply:f=>p("daylight",{cloudShadow:f})}],{tab:"Earth"}),window.__tune?.register("Sky and light",[{key:"nightDarkness",label:"Night",min:0,max:1,step:.005,value:.965,apply:f=>p("daylight",{nightDarkness:f})},{key:"twilightColour",label:"Twilight warm",type:"color",value:"#9e5229",apply:f=>p("daylight",{twilightColour:ge(f)})},{key:"twilightCool",label:"Twilight blue",type:"color",value:"#1a2647",apply:f=>p("daylight",{twilightCool:ge(f)})},{key:"twilightStrength",label:"Twilight strength",min:0,max:1,step:.05,value:.55,apply:f=>p("daylight",{twilightStrength:f})},{key:"atmosphere",label:"Haze",min:0,max:2,step:.05,value:1.25,apply:f=>p("atmosphere",{strength:f})},{key:"starfield",label:"Stars",min:0,max:1,step:.05,value:.5,apply:f=>p("starfield",{brightness:f})},{key:"sun",label:"Sun",min:0,max:2,step:.05,value:1,apply:f=>p("sun",{brightness:f})},{key:"moon",label:"Moon",min:0,max:2,step:.05,value:1,apply:f=>p("moon",{brightness:f})}],{tab:"Earth"})];return{layers:n,setDate(f){const A=f instanceof Date?f:new Date(f);if(Number.isNaN(A.getTime()))return;const _=ne(A);for(const i of Object.keys(n))n[i]?.setOptions?.({date:A,sun:_})},remove(){v?.();for(const f of h)f?.();for(const[,f]of Ne)e.getLayer(f)&&e.removeLayer(f)}}},lo=Object.freeze(Object.defineProperty({__proto__:null,addGlobeLayers:no},Symbol.toStringTag,{value:"Module"}));export{vt as C,no as a,lo as m,W as p,ne as s};
