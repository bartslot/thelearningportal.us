import{m as Y}from"./map-imagery-UjqdYnMS.js";import{p as q,a as Le,b as J,d as Z,e as ee,S as xe,E as he,f as Ue,g as ce,h as qe,s as We,F as Se,T as ke,i as Ve,j as ue,k as Ze,l as Je,N as Qe,C as et,c as tt}from"./clouds-BxFwUN-Y.js";import"./_commonjsHelpers-DqutJMZx.js";const j=Math.PI/180,at=Date.UTC(2e3,0,1,12),re=1/3600,De=e=>(e.getTime()-at)/864e5,ot=(e=new Date)=>((18.697374558+24.06570982441908*De(e))%24*15%360+360)%360,rt=e=>{const o=De(e)/36525;return{zeta:(2306.2181*o+.30188*o*o+.017998*o*o*o)*re,z:(2306.2181*o+1.09468*o*o+.018203*o*o*o)*re,theta:(2004.3109*o-.42665*o*o-.041833*o*o*o)*re}},nt=(e,o,s)=>{const{zeta:h,z:l,theta:f}=rt(s),t=(e-l)*j,m=o*j,n=f*j,M=Math.cos(m)*Math.sin(t),D=Math.cos(n)*Math.cos(m)*Math.cos(t)+Math.sin(n)*Math.sin(m),A=-Math.sin(n)*Math.cos(m)*Math.cos(t)+Math.cos(n)*Math.sin(m);return{ra:Math.atan2(M,D)/j-h,dec:Math.asin(Math.min(1,Math.max(-1,A)))/j}},me=e=>(e%360+360)%360,it=(e,o=new Date)=>{const s=Math.hypot(e[0],e[1],e[2])||1,h=Math.atan2(e[2]/s,e[0]/s)/j,l=Math.asin(Math.min(1,Math.max(-1,e[1]/s)))/j,f=nt(me(h+ot(o)),l,o);return{ra:me(f.ra),dec:f.dec}},st=`
  vec2 panoramaUV(vec3 skyDir) {
    return vec2(atan(skyDir.z, skyDir.x) / 6.28318530718 + 1.0,
                0.5 - asin(clamp(skyDir.y, -1.0, 1.0)) / 3.14159265359);
  }
`,lt=(e=new Date)=>{const o=new Float32Array(9);return[[1,0,0],[0,1,0],[0,0,1]].forEach((h,l)=>{const{ra:f,dec:t}=it(h,e);o[l*3]=Math.cos(t*j)*Math.cos(f*j),o[l*3+1]=Math.sin(t*j),o[l*3+2]=Math.cos(t*j)*Math.sin(f*j)}),o},ht=Math.PI/180,ae=e=>{const o=Math.hypot(e[0],e[1],e[2])||1;return[e[0]/o,e[1]/o,e[2]/o]},ct=(e,o)=>e[0]*o[0]+e[1]*o[1]+e[2]*o[2],ie=(e,o)=>[e[0]-o[0],e[1]-o[1],e[2]-o[2]],ge=(e,o)=>{const s=ct(e,o);return ae([e[0]-o[0]*s,e[1]-o[1]*s,e[2]-o[2]*s])},ut=(e,o)=>{const h=o>89.98?-1:1,l=ae(ie(q(e,o+.01*h),q(e,o-.01*h))),f=ae(ie(q(e+.01,o),q(e-.01,o)));return{north:h===1?l:[-l[0],-l[1],-l[2]],east:f}},ft=(e,o)=>{const s=e?.transform?.cameraPosition;if(s&&Number.isFinite(s[0])&&Number.isFinite(s[1])&&Number.isFinite(s[2]))return[s[2],s[1],s[0]];const h=e?.transform,l=typeof h?.getCameraLngLat=="function"?h.getCameraLngLat():e.getCenter();return q(l.lng,l.lat,Le(e,o))},dt=(e,o,s,h)=>{const l=e.getCenter(),f=(e.getBearing?.()??0)*ht,t=q(l.lng,l.lat,0),m=ft(e,o),n=ae(ie(t,m)),{north:M,east:D}=ut(l.lng,l.lat),A=[0,1,2].map(w=>M[w]*Math.cos(f)+D[w]*Math.sin(f)),I=[0,1,2].map(w=>-M[w]*Math.sin(f)+D[w]*Math.cos(f)),T=ge(A,n),y=ge(I,n),r=Math.tan(s/2),_=r*h;return{origin:m,forward:n,up:T.map(w=>w*r),right:y.map(w=>w*_),upUnit:T,rightUnit:y}},se=(e,o)=>e*o*4,Pe=[{name:"high",width:4096,height:2048,url:"/img/map/sky/milkyway-4k.webp",bytes:3680220,resident:se(4096,2048),decodeMs:235},{name:"standard",width:2048,height:1024,url:"/img/map/sky/milkyway-2k.webp",bytes:764012,resident:se(2048,1024),decodeMs:51}],Ce={name:"placeholder",width:1024,height:512,url:"/img/map/sky/milkyway-1k.webp",bytes:45160,resident:se(1024,512),decodeMs:5},mt=4,gt=4,pt=({maxTextureSize:e=0,deviceMemory:o=null,hardwareConcurrency:s=null,saveData:h=!1,effectiveType:l=null}={})=>{const f=Pe.filter(n=>n.width<=e);if(f.length===0)return{tier:Ce,reason:`MAX_TEXTURE_SIZE is ${e}, below every tier — falling back to the placeholder`};const t=f[f.length-1],m=f[0];return m===t?{tier:m,reason:`only ${m.name} fits MAX_TEXTURE_SIZE ${e}`}:h?{tier:t,reason:"the browser is in data-saver mode"}:l&&/^(slow-2g|2g|3g)$/.test(l)?{tier:t,reason:`the connection reports ${l}`}:Number.isFinite(o)&&o<mt?{tier:t,reason:`the device reports ${o} GB of memory`}:Number.isFinite(s)&&s<gt?{tier:t,reason:`the device reports ${s} cores`}:{tier:m,reason:`nothing says otherwise, and MAX_TEXTURE_SIZE is ${e}`}},_t=(e,o=typeof navigator>"u"?null:navigator)=>{const s=o?.connection??null;return{maxTextureSize:e?.getParameter?.(e.MAX_TEXTURE_SIZE)??0,deviceMemory:o?.deviceMemory??null,hardwareConcurrency:o?.hardwareConcurrency??null,saveData:s?.saveData??!1,effectiveType:s?.effectiveType??null}},Ie="tm-starfield",bt=Ce.url,wt="/data/sky/bright-stars.bin",yt=e=>{const o=Number.isFinite(e)?e:5800,s=Math.min(4e4,Math.max(1e3,o))/100,h=M=>Math.min(1,Math.max(0,M/255)),l=s<=66?255:329.698727446*Math.pow(s-60,-.1332047592),f=s<=66?99.4708025861*Math.log(s)-161.1195681661:288.1221695283*Math.pow(s-60,-.0755148492),t=s>=66?255:s<=19?0:138.5177312231*Math.log(s-10)-305.0447927307,m=[h(l),h(f),h(t)],n=Math.max(...m)||1;return m.map(M=>M/n)},vt=(e,{limitMagnitude:o=6.5}={})=>{const s=new Float32Array(e),h=Math.floor(s.length/4),l=[];for(let t=0;t<h;t++){const m=s[t*4+2];if(m>o)continue;const n=Math.pow(10,-.4*m);l.push([s[t*4],s[t*4+1],Math.pow(n,.36),...yt(s[t*4+3])])}const f=new Float32Array(l.length*6);return l.forEach((t,m)=>f.set(t,m*6)),{vertices:f,count:l.length}},Ne=`
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
`,Et=e=>`${e.define}
attribute vec2 a_pos;
varying vec2 v_ndc;
void main() {
  v_ndc = a_pos;
  // Straight to clip space. No projection, no elevation, no prelude — that is the whole point.
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`,At=e=>`${e.define}
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
${Ne}
${st}

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
}`,Tt=e=>`${e.define}
attribute vec3 a_star;       // right ascension and declination in degrees, then brightness
attribute vec3 a_colour;
uniform mat3 u_skyFrame;
uniform float u_pixelRatio;
uniform float u_starSize;
uniform float u_catalogueAmount;
varying vec3 v_colour;
${Ne}

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
}`,Rt=e=>`${e.define}
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
}`,Mt=({textureUrl:e=null,placeholderUrl:o=bt,catalogueUrl:s=wt,date:h=new Date,brightness:l=.55,nebula:f=.3,nebulaContrast:t=1.45,starDensity:m=210,starAmount:n=1.5,catalogueAmount:M=2.2,starSize:D=3,limitMagnitude:A=6.5,twinkle:I=0,animate:T=!1}={})=>{let y=null,r=null,_=null,w=null,F=null,g=null,a="not chosen yet",i=0;const U=new Map;let u={textureUrl:e,placeholderUrl:o,catalogueUrl:s,date:h,brightness:l,nebula:f,nebulaContrast:t,starDensity:m,starAmount:n,catalogueAmount:M,starSize:D,limitMagnitude:A,twinkle:I,animate:T};const P=k=>{const b=k.variantName;if(U.has(b))return U.get(b);const E=Z(r,Et(k),At(k),"tm-starfield"),c=Z(r,Tt(k),Rt(k),"tm-starfield-catalogue"),p=(S,d)=>r.getUniformLocation(S,d),v={sky:{program:E,pos:r.getAttribLocation(E,"a_pos"),uniforms:{camera:p(E,"u_camera"),forward:p(E,"u_forward"),right:p(E,"u_right"),up:p(E,"u_up"),halfExtent:p(E,"u_halfExtent"),skyFrame:p(E,"u_skyFrame"),sky:p(E,"u_sky"),globeness:p(E,"u_globeness"),brightness:p(E,"u_brightness"),nebula:p(E,"u_nebula"),nebulaContrast:p(E,"u_nebulaContrast"),starDensity:p(E,"u_starDensity"),starAmount:p(E,"u_starAmount"),twinkle:p(E,"u_twinkle"),time:p(E,"u_time")}},stars:{program:c,star:r.getAttribLocation(c,"a_star"),colour:r.getAttribLocation(c,"a_colour"),uniforms:{camera:p(c,"u_camera"),forward:p(c,"u_forward"),right:p(c,"u_right"),up:p(c,"u_up"),halfExtent:p(c,"u_halfExtent"),skyFrame:p(c,"u_skyFrame"),pixelRatio:p(c,"u_pixelRatio"),starSize:p(c,"u_starSize"),catalogueAmount:p(c,"u_catalogueAmount")}}};return U.set(b,v),v},L=()=>{if(!r)return;const k=(b,E)=>b?J(r,b,E,{mipmap:!1}):null;if(u.textureUrl===null){const b=pt(_t(r));g=b.tier,a=b.reason}else g=Pe.find(b=>b.url===u.textureUrl)??{name:"explicit",url:u.textureUrl},a="the caller named a panorama";F=k(u.placeholderUrl,()=>{y?.triggerRepaint()}),w=k(g.url===u.placeholderUrl?null:g.url,()=>{F?.release(),F=null,y?.triggerRepaint()})},N=()=>w?.ready?w:F?.ready?F:null,$=()=>{w?.release(),F?.release(),w=null,F=null},x=k=>{!k||typeof fetch!="function"||fetch(k).then(b=>{if(!b.ok)throw new Error(`${b.status} ${b.statusText}`);return b.arrayBuffer()}).then(b=>{if(!r||!_)return;const{vertices:E,count:c}=vt(b,{limitMagnitude:u.limitMagnitude});r.bindBuffer(r.ARRAY_BUFFER,_.stars),r.bufferData(r.ARRAY_BUFFER,E,r.STATIC_DRAW),i=c,y?.triggerRepaint()}).catch(b=>console.warn(`[starfield] bright star catalogue unavailable: ${b.message}`))};return{id:Ie,type:"custom",renderingMode:"3d",onAdd(k,b){y=k,r=b;const E=(c,p)=>{const v=r.createBuffer();return r.bindBuffer(c,v),p&&r.bufferData(c,p,r.STATIC_DRAW),v};_={pos:E(r.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,1,1])),index:E(r.ELEMENT_ARRAY_BUFFER,new Uint16Array([0,1,2,2,1,3])),stars:E(r.ARRAY_BUFFER,null)},L(),x(u.catalogueUrl)},onRemove(){r&&(U.forEach(({sky:k,stars:b})=>{r.deleteProgram(k.program),r.deleteProgram(b.program)}),U.clear(),$(),_&&(r.deleteBuffer(_.pos),r.deleteBuffer(_.index),r.deleteBuffer(_.stars),_=null),y=null,r=null)},render(k,b){if(!_||u.brightness<=0)return;const E=b&&b.shaderData,c=b&&b.defaultProjectionData;if(!E||!c)return;const p=y.getCanvas(),v=p.width/Math.max(1,p.height),S=dt(y,Y,b.fov||.6435,v),d=Math.tan((b.fov||.6435)/2),H=lt(u.date),R=c.projectionTransition,{sky:C,stars:O}=P(E),K=({uniforms:G})=>{G.camera&&r.uniform3f(G.camera,...S.origin),G.forward&&r.uniform3f(G.forward,...S.forward),G.right&&r.uniform3f(G.right,...S.rightUnit),G.up&&r.uniform3f(G.up,...S.upUnit),G.halfExtent&&r.uniform2f(G.halfExtent,d*v,d),G.skyFrame&&r.uniformMatrix3fv(G.skyFrame,!1,H)};r.useProgram(C.program),K(C),C.uniforms.globeness&&r.uniform1f(C.uniforms.globeness,R),C.uniforms.brightness&&r.uniform1f(C.uniforms.brightness,u.brightness);const X=N();C.uniforms.nebula&&r.uniform1f(C.uniforms.nebula,X?u.nebula:0),C.uniforms.nebulaContrast&&r.uniform1f(C.uniforms.nebulaContrast,u.nebulaContrast),C.uniforms.starDensity&&r.uniform1f(C.uniforms.starDensity,u.starDensity),C.uniforms.starAmount&&r.uniform1f(C.uniforms.starAmount,u.starAmount),C.uniforms.twinkle&&r.uniform1f(C.uniforms.twinkle,u.twinkle),C.uniforms.time&&r.uniform1f(C.uniforms.time,u.animate?performance.now()*.001:0),X&&C.uniforms.sky&&(r.activeTexture(r.TEXTURE0),r.bindTexture(r.TEXTURE_2D,X.texture),r.uniform1i(C.uniforms.sky,0)),r.bindBuffer(r.ARRAY_BUFFER,_.pos),r.enableVertexAttribArray(C.pos),r.vertexAttribPointer(C.pos,2,r.FLOAT,!1,0,0),r.disable(r.DEPTH_TEST),r.depthMask(!1),r.bindBuffer(r.ELEMENT_ARRAY_BUFFER,_.index),r.drawElements(r.TRIANGLES,6,r.UNSIGNED_SHORT,0),i>0&&R>.5&&u.catalogueAmount>0&&(r.useProgram(O.program),K(O),O.uniforms.pixelRatio&&r.uniform1f(O.uniforms.pixelRatio,typeof devicePixelRatio=="number"?devicePixelRatio:1),O.uniforms.starSize&&r.uniform1f(O.uniforms.starSize,u.starSize),O.uniforms.catalogueAmount&&r.uniform1f(O.uniforms.catalogueAmount,u.catalogueAmount*u.brightness),r.bindBuffer(r.ARRAY_BUFFER,_.stars),r.enableVertexAttribArray(O.star),r.vertexAttribPointer(O.star,3,r.FLOAT,!1,24,0),r.enableVertexAttribArray(O.colour),r.vertexAttribPointer(O.colour,3,r.FLOAT,!1,24,12),r.drawArrays(r.POINTS,0,i),r.disableVertexAttribArray(O.star),r.disableVertexAttribArray(O.colour)),r.enable(r.DEPTH_TEST),r.depthMask(!0),u.animate&&u.twinkle>0&&y.triggerRepaint()},setOptions(k={}){const b=u.textureUrl,E=u.catalogueUrl;u={...u,...k},k.textureUrl!==void 0&&k.textureUrl!==b&&($(),L()),k.catalogueUrl!==void 0&&k.catalogueUrl!==E&&(i=0,x(k.catalogueUrl)),y?.triggerRepaint()},getOptions:()=>({...u}),get hasSky(){return N()!==null},get starCount(){return i},get skyTier(){return g?{...g,reason:a}:null}}},Lt=Ie,W=Math.PI/180,xt=Date.UTC(2e3,0,1,12),Ut=149597870700,St=6957e5,Fe=e=>(e.getTime()-xt)/864e5,Oe=(e=new Date)=>{const o=Fe(e),s=(280.46+.9856474*o)%360,h=(357.528+.9856003*o)%360*W,l=(s+1.915*Math.sin(h)+.02*Math.sin(2*h))*W,f=(23.439-4e-7*o)*W,t=Math.asin(Math.sin(f)*Math.sin(l))/W;let m=Math.atan2(Math.cos(f)*Math.sin(l),Math.cos(l))/W;m<0&&(m+=360);let n=s-m;n>180&&(n-=360),n<-180&&(n+=360),n*=4;const M=(1.00014-.01671*Math.cos(h)-14e-5*Math.cos(2*h))*Ut;return{declination:t,equationOfTime:n,distance:M}},Be=(e=new Date)=>Oe(e).distance,kt=(e=new Date)=>Math.atan(St/Be(e)),Dt=(e=new Date)=>{const o=(357.528+.9856003*Fe(e))%360*W;return(1.00014-.01671*Math.cos(o)-14e-5*Math.cos(2*o))*149597870700},Pt=(e=new Date)=>{const{declination:o,equationOfTime:s}=Oe(e);let l=-15*(e.getUTCHours()+e.getUTCMinutes()/60+e.getUTCSeconds()/3600-12+s/60);return l=(l+540)%360-180,{lng:l,lat:o}},fe=(e=new Date)=>{const{lng:o,lat:s}=Pt(e),h=s*W,l=o*W;return[Math.cos(h)*Math.cos(l),Math.sin(h),Math.cos(h)*Math.sin(l)]},Ge="tm-sun",pe=63710088e-1,Ct=18,It=(e,o,s)=>{const h=Math.abs(e),l=o,f=s;if(l<=0)return 0;if(h>=l+f)return 1;if(h<=f-l)return 0;if(h<=l-f)return 1-f*f/(l*l);const t=l*l*Math.acos(Q((h*h+l*l-f*f)/(2*h*l),-1,1))+f*f*Math.acos(Q((h*h+f*f-l*l)/(2*h*f),-1,1))-.5*Math.sqrt(Math.max(0,(-h+l+f)*(h+l-f)*(h-l+f)*(h+l+f)));return Q(1-t/(Math.PI*l*l),0,1)},_e={u1:.93,u2:-.23},be=e=>e<0?`(${e.toFixed(4)})`:e.toFixed(4),Nt=`
  float limbDarkening(float rho) {
    float mu = sqrt(max(1.0 - rho * rho, 0.0));
    float t = 1.0 - mu;
    return max(1.0 - ${be(_e.u1)} * t - ${be(_e.u2)} * t * t, 0.0);
  }
`,Ft=new Float32Array([-1,-1,1,-1,-1,1,1,1]),we=new Uint16Array([0,1,2,1,3,2]),Ot=e=>`${e.vertexShaderPrelude}
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
}`,Bt=()=>`precision highp float;
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
${xe}
${Nt}

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
}`,Gt=({date:e=new Date,haloScale:o=Ct,haloStrength:s=1,discGain:h=1.15,brightness:l=1,coreColour:f=[1,.985,.95],haloColour:t=[1,.65,.2]}={})=>{let m=null,n=null,M=null;const D=new Map;let A={date:e,haloScale:o,haloStrength:s,discGain:h,brightness:l,coreColour:f,haloColour:t};const I=T=>{const y=T.variantName;if(D.has(y))return D.get(y);const r=Z(n,Ot(T),Bt(),"tm-sun"),_={program:r,attribs:{corner:n.getAttribLocation(r,"a_corner")},uniforms:{centre:n.getUniformLocation(r,"a_centre"),elevationGlobe:n.getUniformLocation(r,"a_elevation_globe"),elevationMercator:n.getUniformLocation(r,"a_elevation_mercator"),size:n.getUniformLocation(r,"u_size"),camera:n.getUniformLocation(r,"u_camera"),forward:n.getUniformLocation(r,"u_forward"),right:n.getUniformLocation(r,"u_right"),up:n.getUniformLocation(r,"u_up"),glowAngle:n.getUniformLocation(r,"u_glow_angle"),discFraction:n.getUniformLocation(r,"u_disc_fraction"),discGain:n.getUniformLocation(r,"u_disc_gain"),visible:n.getUniformLocation(r,"u_visible"),brightness:n.getUniformLocation(r,"u_brightness"),haloStrength:n.getUniformLocation(r,"u_halo_strength"),coreColour:n.getUniformLocation(r,"u_core_colour"),haloColour:n.getUniformLocation(r,"u_halo_colour"),matrix:n.getUniformLocation(r,"u_projection_matrix"),tileMercatorCoords:n.getUniformLocation(r,"u_projection_tile_mercator_coords"),clippingPlane:n.getUniformLocation(r,"u_projection_clipping_plane"),transition:n.getUniformLocation(r,"u_projection_transition"),fallbackMatrix:n.getUniformLocation(r,"u_projection_fallback_matrix")}};return D.set(y,_),_};return{id:Ge,type:"custom",renderingMode:"3d",onAdd(T,y){m=T,n=y;const r=n.createBuffer();n.bindBuffer(n.ARRAY_BUFFER,r),n.bufferData(n.ARRAY_BUFFER,Ft,n.STATIC_DRAW);const _=n.createBuffer();n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,_),n.bufferData(n.ELEMENT_ARRAY_BUFFER,we,n.STATIC_DRAW),M={corner:r,index:_}},onRemove(){n&&(D.forEach(({program:T})=>n.deleteProgram(T)),D.clear(),M&&(n.deleteBuffer(M.corner),n.deleteBuffer(M.index),M=null),m=null,n=null)},render(T,y){if(!M||A.brightness<=0)return;const r=y&&y.shaderData,_=y&&y.defaultProjectionData;if(!r||!_)return;const{program:w,attribs:F,uniforms:g}=I(r);n.useProgram(w),g.matrix&&n.uniformMatrix4fv(g.matrix,!1,_.mainMatrix),g.tileMercatorCoords&&n.uniform4f(g.tileMercatorCoords,..._.tileMercatorCoords),g.clippingPlane&&n.uniform4f(g.clippingPlane,..._.clippingPlane),g.transition&&n.uniform1f(g.transition,_.projectionTransition),g.fallbackMatrix&&n.uniformMatrix4fv(g.fallbackMatrix,!1,_.fallbackMatrix);const a=ee(m,Y),i=Be(A.date),U=q(...Ht(fe(A.date)),i-pe),u=ye([U[0]-a[0],U[1]-a[1],U[2]-a[2]]),P=Math.hypot(...a),L=Math.max(.25*(P-1),.002),N=[a[0]+u[0]*L,a[1]+u[1]*L,a[2]+u[2]*L],$=Math.hypot(...N),x=Math.asin(N[1]/$)*180/Math.PI,k=Math.atan2(N[2],N[0])*180/Math.PI,b=($-1)*pe,E=Y.MercatorCoordinate.fromLngLat([k,x],0);g.centre&&n.uniform2f(g.centre,E.x,E.y),g.elevationGlobe&&n.uniform1f(g.elevationGlobe,b),g.elevationMercator&&n.uniform1f(g.elevationMercator,Y.MercatorCoordinate.fromLngLat([k,x],b).z);const c=kt(A.date),p=c*A.haloScale,v=(y.fov||.6435)/2,S=m.getCanvas(),d=Math.tan(p)/Math.tan(v);g.size&&n.uniform2f(g.size,d*(S.height/S.width),d),g.glowAngle&&n.uniform1f(g.glowAngle,p),g.discFraction&&n.uniform1f(g.discFraction,1/A.haloScale),g.discGain&&n.uniform1f(g.discGain,A.discGain);const H=[-a[0]/P,-a[1]/P,-a[2]/P],R=Math.acos(Q($t(H,u),-1,1)),C=Math.asin(Q(1/P,-1,1)),O=It(R,c,C),K=u,X=ye(ve([0,1,0],K)),G=ve(K,X);g.forward&&n.uniform3f(g.forward,...K),g.right&&n.uniform3f(g.right,...X),g.up&&n.uniform3f(g.up,...G),g.camera&&n.uniform3f(g.camera,...a),g.visible&&n.uniform1f(g.visible,O),g.brightness&&n.uniform1f(g.brightness,A.brightness),g.haloStrength&&n.uniform1f(g.haloStrength,A.haloStrength),g.coreColour&&n.uniform3f(g.coreColour,...A.coreColour),g.haloColour&&n.uniform3f(g.haloColour,...A.haloColour),n.bindBuffer(n.ARRAY_BUFFER,M.corner),n.enableVertexAttribArray(F.corner),n.vertexAttribPointer(F.corner,2,n.FLOAT,!1,0,0),n.disable(n.DEPTH_TEST),n.depthMask(!1),n.bindBuffer(n.ELEMENT_ARRAY_BUFFER,M.index),n.drawElements(n.TRIANGLES,we.length,n.UNSIGNED_SHORT,0),n.depthMask(!0)},setOptions(T={}){A={...A,...T},m?.triggerRepaint()},getOptions:()=>({...A})}},Q=(e,o,s)=>Math.min(s,Math.max(o,e)),$t=(e,o)=>e[0]*o[0]+e[1]*o[1]+e[2]*o[2],ye=e=>{const o=Math.hypot(...e)||1;return[e[0]/o,e[1]/o,e[2]/o]},ve=(e,o)=>[e[1]*o[2]-e[2]*o[1],e[2]*o[0]-e[0]*o[2],e[0]*o[1]-e[1]*o[0]],Ht=([e,o,s])=>[Math.atan2(s,e)*180/Math.PI,Math.asin(Q(o,-1,1))*180/Math.PI],zt=Ge,$e="tm-moon",oe=63710088e-1,Yt=1737400,V=Math.PI/180,jt=Date.UTC(2e3,0,1,12),Xt=Date.UTC(1999,11,31,0),B=e=>Math.sin(e*V),z=e=>Math.cos(e*V),He=(e=new Date)=>{const o=(e.getTime()-jt)/864e5,s=(e.getTime()-Xt)/864e5,h=125.1228-.0529538083*s,l=5.1454,f=318.0634+.1643573223*s,t=60.2666,m=.0549,n=115.3654+13.0649929509*s;let M=n+m*180/Math.PI*B(n)*(1+m*z(n));for(let p=0;p<3;p++)M-=(M-m*180/Math.PI*B(M)-n)/(1-m*z(M));const D=t*(z(M)-m),A=t*(Math.sqrt(1-m*m)*B(M)),I=Math.atan2(A,D)/V,T=Math.sqrt(D*D+A*A);let y=T*(z(h)*z(I+f)-B(h)*B(I+f)*z(l)),r=T*(B(h)*z(I+f)+z(h)*B(I+f)*z(l)),_=T*(B(I+f)*B(l));const w=356.047+.9856002585*s,F=282.9404+470935e-10*s+w,g=h+f+n,a=g-F,i=g-h;let U=Math.atan2(r,y)/V,u=Math.atan2(_,Math.hypot(y,r))/V;U+=-1.274*B(n-2*a)+.658*B(2*a)-.186*B(w),u+=-.173*B(i-2*a);const P=(T-.58*z(n-2*a)-.46*z(2*a))*oe,L=23.4393-3563e-10*o,N=z(U)*z(u),$=B(U)*z(u)*z(L)-B(u)*B(L),x=B(U)*z(u)*B(L)+B(u)*z(L),k=Math.atan2($,N)/V,b=Math.atan2(x,Math.hypot(N,$))/V,E=(18.697374558+24.06570982441908*o)%24;let c=k-E*15;return c=(c%360+540)%360-180,{lng:c,lat:b,distance:P}},ze=(e=new Date)=>{const{lng:o,lat:s,distance:h}=He(e);return q(o,s,h-oe)},Kt=new Float32Array([-1,-1,1,-1,-1,1,1,1]),Ee=new Uint16Array([0,1,2,1,3,2]),qt=e=>`${e.vertexShaderPrelude}
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
}`,Wt=()=>`precision highp float;
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
}`,Vt=({date:e=new Date,sun:o=[1,0,0],albedoUrl:s=null,sizeScale:h=1,brightness:l=1}={})=>{let f=null,t=null,m=null,n=null,M=!1;const D=new Map;let A={date:e,sun:o,albedoUrl:s,sizeScale:h,brightness:l};const I=y=>{const r=y.variantName;if(D.has(r))return D.get(r);const _=Z(t,qt(y),Wt(),"tm-moon"),w={program:_,attribs:{corner:t.getAttribLocation(_,"a_corner")},uniforms:{centre:t.getUniformLocation(_,"a_centre"),elevationGlobe:t.getUniformLocation(_,"a_elevation_globe"),elevationMercator:t.getUniformLocation(_,"a_elevation_mercator"),size:t.getUniformLocation(_,"u_size"),albedo:t.getUniformLocation(_,"u_albedo"),hasAlbedo:t.getUniformLocation(_,"u_hasAlbedo"),sun:t.getUniformLocation(_,"u_sun"),right:t.getUniformLocation(_,"u_right"),up:t.getUniformLocation(_,"u_up"),forward:t.getUniformLocation(_,"u_forward"),brightness:t.getUniformLocation(_,"u_brightness"),matrix:t.getUniformLocation(_,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(_,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(_,"u_projection_clipping_plane"),transition:t.getUniformLocation(_,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(_,"u_projection_fallback_matrix")}};return D.set(r,w),w},T=y=>{const r=new Image;r.crossOrigin="anonymous",r.onload=()=>{t&&(n=n||t.createTexture(),t.bindTexture(t.TEXTURE_2D,n),t.texImage2D(t.TEXTURE_2D,0,t.RGBA,t.RGBA,t.UNSIGNED_BYTE,r),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_S,t.REPEAT),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_WRAP_T,t.CLAMP_TO_EDGE),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MIN_FILTER,t.LINEAR),t.texParameteri(t.TEXTURE_2D,t.TEXTURE_MAG_FILTER,t.LINEAR),M=!0,f?.triggerRepaint())},r.src=y};return{id:$e,type:"custom",renderingMode:"3d",onAdd(y,r){f=y,t=r;const _=t.createBuffer();t.bindBuffer(t.ARRAY_BUFFER,_),t.bufferData(t.ARRAY_BUFFER,Kt,t.STATIC_DRAW);const w=t.createBuffer();t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,w),t.bufferData(t.ELEMENT_ARRAY_BUFFER,Ee,t.STATIC_DRAW),m={corner:_,index:w},A.albedoUrl&&T(A.albedoUrl)},onRemove(){t&&(D.forEach(({program:y})=>t.deleteProgram(y)),D.clear(),n&&(t.deleteTexture(n),n=null,M=!1),m&&(t.deleteBuffer(m.corner),t.deleteBuffer(m.index),m=null),f=null,t=null)},render(y,r){if(!m||A.brightness<=0)return;const _=r&&r.shaderData,w=r&&r.defaultProjectionData;if(!_||!w)return;const{lng:F,lat:g,distance:a}=He(A.date),{program:i,attribs:U,uniforms:u}=I(_);t.useProgram(i),u.matrix&&t.uniformMatrix4fv(u.matrix,!1,w.mainMatrix),u.tileMercatorCoords&&t.uniform4f(u.tileMercatorCoords,...w.tileMercatorCoords),u.clippingPlane&&t.uniform4f(u.clippingPlane,...w.clippingPlane),u.transition&&t.uniform1f(u.transition,w.projectionTransition),u.fallbackMatrix&&t.uniformMatrix4fv(u.fallbackMatrix,!1,w.fallbackMatrix);const P=ee(f,Y),L=q(F,g,a-oe),N=Ae([L[0]-P[0],L[1]-P[1],L[2]-P[2]]),x=Math.hypot(...P)+1.2,k=[P[0]+N[0]*x,P[1]+N[1]*x,P[2]+N[2]*x],b=Math.hypot(...k),E=Math.asin(k[1]/b)*180/Math.PI,c=Math.atan2(k[2],k[0])*180/Math.PI,p=(b-1)*oe,v=Y.MercatorCoordinate.fromLngLat([c,E],0);u.centre&&t.uniform2f(u.centre,v.x,v.y),u.elevationGlobe&&t.uniform1f(u.elevationGlobe,p),u.elevationMercator&&t.uniform1f(u.elevationMercator,Y.MercatorCoordinate.fromLngLat([c,E],p).z);const S=Math.atan(Yt*A.sizeScale/a),d=(r.fov||.6435)/2,H=f.getCanvas(),R=Math.tan(S)/Math.tan(d);u.size&&t.uniform2f(u.size,R*(H.height/H.width),R);const C=N,O=Ae(Te([0,1,0],C)),K=Te(C,O);u.forward&&t.uniform3f(u.forward,...C),u.right&&t.uniform3f(u.right,...O),u.up&&t.uniform3f(u.up,...K),u.sun&&t.uniform3f(u.sun,...A.sun),u.brightness&&t.uniform1f(u.brightness,A.brightness),u.hasAlbedo&&t.uniform1f(u.hasAlbedo,M?1:0),M&&u.albedo&&(t.activeTexture(t.TEXTURE0),t.bindTexture(t.TEXTURE_2D,n),t.uniform1i(u.albedo,0)),t.bindBuffer(t.ARRAY_BUFFER,m.corner),t.enableVertexAttribArray(U.corner),t.vertexAttribPointer(U.corner,2,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,m.index),t.drawElements(t.TRIANGLES,Ee.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(y={}){const r=A.albedoUrl;A={...A,...y},y.albedoUrl!==void 0&&y.albedoUrl!==r&&(M=!1,y.albedoUrl&&T(y.albedoUrl)),f?.triggerRepaint()},getOptions:()=>({...A}),get hasAlbedo(){return M}}},Ae=e=>{const o=Math.hypot(...e)||1;return[e[0]/o,e[1]/o,e[2]/o]},Te=(e,o)=>[e[1]*o[2]-e[2]*o[1],e[2]*o[0]-e[0]*o[2],e[0]*o[1]-e[1]*o[0]],Zt=$e,Jt=`
  mat3 equirectTangentFrame(vec3 unitPos) {
    vec3 up = normalize(unitPos);
    vec3 east = cross(up, vec3(0.0, 1.0, 0.0));
    float span = length(east);
    // Standing on a pole, every direction is south and no direction is east. Pick one rather than
    // dividing by zero and putting a NaN in the middle of Antarctica.
    east = span > 1.0e-4 ? east / span : vec3(0.0, 0.0, 1.0);
    return mat3(east, cross(up, east), up);
  }
`,Qt=`
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
`,ea=`
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
`,ta=(e,o)=>2*Math.PI*Ue*Math.cos(o*Math.PI/180)/e,aa=(e,o,s)=>Math.min(s,Math.max(o,e)),oa=(e,o,s)=>{const h=aa((s-e)/(o-e),0,1);return h*h*(3-2*h)},ra=(e,o,s)=>{const h=156543.03392*Math.cos(o*Math.PI/180)/Math.pow(2,e)/2,l=ta(s,o)/h;return{pixelsPerTexel:l,strength:1-oa(3,5,Math.log2(Math.max(l,1e-6)))}},na=6957e5,ia=1737400,sa=63710088e-1,la=ia/sa,ha=(e,o,s)=>Math.min(s,Math.max(o,e)),ca=(e=new Date)=>Math.asin(na/Dt(e)),ua=.035,fa=(e=new Date,o=null,s=null)=>{const h=o||fe(e),l=s||ze(e),f=Math.hypot(...l);return Math.acos(ha((h[0]*l[0]+h[1]*l[1]+h[2]*l[2])/f,-1,1))<ua},da=`
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
`,Ye="tm-daylight",te={field:0,wind:1,relief:2,lights:3},ma=e=>`#version 300 es
${e.define}
${e.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${ue}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,ga=()=>`#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform vec3 u_sun;            // direction TO the sun
uniform vec3 u_camera;         // camera in planet space, earth = unit sphere
uniform float u_globeness;     // 1 on the globe, 0 on the flat map
uniform float u_nightDarkness; // how black the unlit side goes
uniform vec3 u_nightColour;
uniform vec3 u_twilightColour;
uniform sampler2D u_lights;    // NASA Black Marble, equirectangular
uniform float u_lightsAmount;  // 0 when the texture has not loaded
uniform sampler2D u_relief;    // baked terrain normals, equirectangular; see terrain-normals.js
uniform float u_reliefPower;   // 0 when there is no relief map, or the camera is too close for it
uniform float u_cloudShadow;   // how much of the light a full cloud takes away
uniform float u_cloudAltitude; // deck height in earth radii — sets how far a shadow is thrown
uniform vec3 u_moon;           // the moon's POSITION in earth radii, not its direction
uniform float u_sunRadius;     // the sun's angular radius; 0 on any date without an eclipse
${he}
${Se}
${ke}
${Jt}
${Qt}
${ea}
${da}
// The same field, wind and clock the deck in clouds.js draws itself from.
${Ve}

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
    float remaining = eclipseLight(normal, sunDir, u_moon, u_sunRadius, ${la.toFixed(8)});
    day *= remaining;
    lit *= remaining;
  }

  day = clamp(day, 0.0, 1.0);
  float night = 1.0 - day;

  // Sunlit rock, added rather than blended: premultiplied alpha makes vec4(colour, 0.0) a pure
  // addition, which is the only way a darkening shell can put light back.
  vec3 emitted = lit * vec3(1.0, 0.97, 0.92);

  if (night < 0.004 && lit < 0.004) discard;   // full daylight, flat ground: leave the imagery alone

  // The warm band peaks in the middle of the transition and vanishes at both ends — and it follows
  // the SUN's angle, so a cloud shadow at noon is grey rather than a private sunset.
  float twilight = 1.0 - abs(sunlight * 2.0 - 1.0);
  twilight *= twilight;

  vec3 shade = mix(u_nightColour, u_twilightColour, twilight * 0.85);
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
}`,pa=({sun:e=[1,0,0],nightDarkness:o=.965,lightsUrl:s=null,lightsAmount:h=0,nightColour:l=[.02,.035,.07],twilightColour:f=[.85,.35,.12],reliefUrl:t=null,reliefWidth:m=8192,reliefPower:n=1.5,cloudShadow:M=.5,eclipse:D=!0,date:A=null,fieldUrl:I=null,windUrl:T=null,windAmount:y=1,windScale:r=.06,windRate:_=.05,driftRate:w=4e-4,animate:F=!0}={})=>{const g=ce();let a=null,i=null,U=null,u=null,P=null,L=null,N=null;const $=new Map;let x={sun:e,nightDarkness:o,lightsUrl:s,lightsAmount:h,nightColour:l,twilightColour:f,reliefUrl:t,reliefWidth:m,reliefPower:n,cloudShadow:M,eclipse:D,date:A,fieldUrl:I,windUrl:T,windAmount:y,windScale:r,windRate:_,driftRate:w,animate:F};const k=c=>{const p=c.variantName;if($.has(p))return $.get(p);const v=Z(i,ma(c),ga(),"tm-daylight"),S={program:v,attribs:{pos:i.getAttribLocation(v,"a_pos"),sphere:i.getAttribLocation(v,"a_sphere")},uniforms:{elevationGlobe:i.getUniformLocation(v,"a_elevation_globe"),elevationMercator:i.getUniformLocation(v,"a_elevation_mercator"),sun:i.getUniformLocation(v,"u_sun"),camera:i.getUniformLocation(v,"u_camera"),globeness:i.getUniformLocation(v,"u_globeness"),nightDarkness:i.getUniformLocation(v,"u_nightDarkness"),nightColour:i.getUniformLocation(v,"u_nightColour"),twilightColour:i.getUniformLocation(v,"u_twilightColour"),lights:i.getUniformLocation(v,"u_lights"),lightsAmount:i.getUniformLocation(v,"u_lightsAmount"),relief:i.getUniformLocation(v,"u_relief"),reliefPower:i.getUniformLocation(v,"u_reliefPower"),cloudShadow:i.getUniformLocation(v,"u_cloudShadow"),cloudAltitude:i.getUniformLocation(v,"u_cloudAltitude"),moon:i.getUniformLocation(v,"u_moon"),sunRadius:i.getUniformLocation(v,"u_sunRadius"),...Object.fromEntries(Ze.map(d=>[d,i.getUniformLocation(v,`u_${d}`)])),matrix:i.getUniformLocation(v,"u_projection_matrix"),tileMercatorCoords:i.getUniformLocation(v,"u_projection_tile_mercator_coords"),clippingPlane:i.getUniformLocation(v,"u_projection_clipping_plane"),transition:i.getUniformLocation(v,"u_projection_transition"),fallbackMatrix:i.getUniformLocation(v,"u_projection_fallback_matrix")}};return $.set(p,S),S},b=()=>a?.triggerRepaint(),E=(c,p,v,S)=>{!p?.ready||!c[v]||(i.activeTexture(i.TEXTURE0+S),i.bindTexture(i.TEXTURE_2D,p.texture),i.uniform1i(c[v],S))};return{id:Ye,type:"custom",renderingMode:"3d",onAdd(c,p){a=c,i=p;const v=(S,d)=>{const H=i.createBuffer();return i.bindBuffer(S,H),i.bufferData(S,d,i.STATIC_DRAW),H};U={pos:v(i.ARRAY_BUFFER,g.positions),sphere:v(i.ARRAY_BUFFER,g.spheres),index:v(i.ELEMENT_ARRAY_BUFFER,g.indices)},x.lightsUrl&&(u=J(i,x.lightsUrl,b)),x.reliefUrl&&(P=J(i,x.reliefUrl,b)),x.fieldUrl&&(L=J(i,x.fieldUrl,b)),x.windUrl&&(N=J(i,x.windUrl,b))},onRemove(){i&&($.forEach(({program:c})=>i.deleteProgram(c)),$.clear(),u?.release(),u=null,P?.release(),P=null,L?.release(),L=null,N?.release(),N=null,U&&(i.deleteBuffer(U.pos),i.deleteBuffer(U.sphere),i.deleteBuffer(U.index),U=null),a=null,i=null)},render(c,p){if(!U||x.nightDarkness<=0)return;const v=p&&p.shaderData,S=p&&p.defaultProjectionData;if(!v||!S)return;const{program:d,attribs:H,uniforms:R}=k(v);i.useProgram(d),R.matrix&&i.uniformMatrix4fv(R.matrix,!1,S.mainMatrix),R.tileMercatorCoords&&i.uniform4f(R.tileMercatorCoords,...S.tileMercatorCoords),R.clippingPlane&&i.uniform4f(R.clippingPlane,...S.clippingPlane),R.transition&&i.uniform1f(R.transition,S.projectionTransition),R.fallbackMatrix&&i.uniformMatrix4fv(R.fallbackMatrix,!1,S.fallbackMatrix);const C=a.getCenter().lat,O=0;R.elevationGlobe&&i.uniform1f(R.elevationGlobe,O),R.elevationMercator&&i.uniform1f(R.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,C],O).z),R.sun&&i.uniform3f(R.sun,...x.sun),R.camera&&i.uniform3f(R.camera,...ee(a,Y)),R.globeness&&i.uniform1f(R.globeness,S.projectionTransition),R.nightDarkness&&i.uniform1f(R.nightDarkness,x.nightDarkness),R.nightColour&&i.uniform3f(R.nightColour,...x.nightColour),R.twilightColour&&i.uniform3f(R.twilightColour,...x.twilightColour),R.lightsAmount&&i.uniform1f(R.lightsAmount,u?.ready?x.lightsAmount:0);const K=P?.ready?ra(a.getZoom(),C,x.reliefWidth).strength:0;R.reliefPower&&i.uniform1f(R.reliefPower,x.reliefPower*K),R.cloudShadow&&i.uniform1f(R.cloudShadow,x.cloudShadow),R.cloudAltitude&&i.uniform1f(R.cloudAltitude,qe/Ue);const X=x.eclipse?x.date:null,G=X&&fa(X)?ze(X):null;R.sunRadius&&i.uniform1f(R.sunRadius,G?ca(X):0),G&&R.moon&&i.uniform3f(R.moon,...G),We(i,R,x,{seconds:performance.now()*.001,field:L,wind:N},te.field,te.wind),E(R,P,"relief",te.relief),E(R,u,"lights",te.lights),i.bindBuffer(i.ARRAY_BUFFER,U.pos),i.enableVertexAttribArray(H.pos),i.vertexAttribPointer(H.pos,2,i.FLOAT,!1,0,0),i.bindBuffer(i.ARRAY_BUFFER,U.sphere),i.enableVertexAttribArray(H.sphere),i.vertexAttribPointer(H.sphere,3,i.FLOAT,!1,0,0),i.disable(i.DEPTH_TEST),i.depthMask(!1),i.bindBuffer(i.ELEMENT_ARRAY_BUFFER,U.index),i.drawElements(i.TRIANGLES,g.indices.length,i.UNSIGNED_SHORT,0),i.enable(i.DEPTH_TEST),i.depthMask(!0),x.animate&&L?.ready&&x.cloudShadow>0&&a.triggerRepaint()},setOptions(c={}){const p=x;if(x={...x,...c},!i){a?.triggerRepaint();return}const v=(S,d)=>c[d]===void 0||c[d]===p[d]?S:(S?.release(),x[d]?J(i,x[d],b):null);u=v(u,"lightsUrl"),P=v(P,"reliefUrl"),L=v(L,"fieldUrl"),N=v(N,"windUrl"),a?.triggerRepaint()},getOptions:()=>({...x}),get hasLights(){return!!u?.ready},get hasRelief(){return!!P?.ready}}},_a=Ye,je="tm-atmosphere",ba=63710088e-1,ne=2e5,wa=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${ue}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,ya=()=>`precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space (earth = unit sphere)
uniform vec3 u_sun;         // direction TO the sun
uniform float u_top;        // top of the atmosphere, in earth radii
uniform float u_strength;
uniform vec3 u_dayColour;
uniform vec3 u_duskColour;

const int SAMPLES = 6;

${xe}

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
}`,va=({strength:e=1,sun:o=[.4,.5,.75],dayColour:s=[.32,.55,1],duskColour:h=[1,.45,.18]}={})=>{const l=ce();let f=null,t=null,m=null;const n=new Map;let M={strength:e,sun:o,dayColour:s,duskColour:h};const D=A=>{const I=A.variantName;if(n.has(I))return n.get(I);const T=Z(t,wa(A),ya(),"tm-atmosphere"),y={program:T,attribs:{pos:t.getAttribLocation(T,"a_pos"),sphere:t.getAttribLocation(T,"a_sphere")},uniforms:{elevationGlobe:t.getUniformLocation(T,"a_elevation_globe"),elevationMercator:t.getUniformLocation(T,"a_elevation_mercator"),camera:t.getUniformLocation(T,"u_camera"),sun:t.getUniformLocation(T,"u_sun"),top:t.getUniformLocation(T,"u_top"),strength:t.getUniformLocation(T,"u_strength"),dayColour:t.getUniformLocation(T,"u_dayColour"),duskColour:t.getUniformLocation(T,"u_duskColour"),matrix:t.getUniformLocation(T,"u_projection_matrix"),tileMercatorCoords:t.getUniformLocation(T,"u_projection_tile_mercator_coords"),clippingPlane:t.getUniformLocation(T,"u_projection_clipping_plane"),transition:t.getUniformLocation(T,"u_projection_transition"),fallbackMatrix:t.getUniformLocation(T,"u_projection_fallback_matrix")}};return n.set(I,y),y};return{id:je,type:"custom",renderingMode:"3d",onAdd(A,I){f=A,t=I;const T=(y,r)=>{const _=t.createBuffer();return t.bindBuffer(y,_),t.bufferData(y,r,t.STATIC_DRAW),_};m={pos:T(t.ARRAY_BUFFER,l.positions),sphere:T(t.ARRAY_BUFFER,l.spheres),index:T(t.ELEMENT_ARRAY_BUFFER,l.indices)}},onRemove(){t&&(n.forEach(({program:A})=>t.deleteProgram(A)),n.clear(),m&&(t.deleteBuffer(m.pos),t.deleteBuffer(m.sphere),t.deleteBuffer(m.index),m=null),f=null,t=null)},render(A,I){if(!m||M.strength<=0)return;const T=I&&I.shaderData,y=I&&I.defaultProjectionData;if(!T||!y)return;const{program:r,attribs:_,uniforms:w}=D(T);t.useProgram(r),w.matrix&&t.uniformMatrix4fv(w.matrix,!1,y.mainMatrix),w.tileMercatorCoords&&t.uniform4f(w.tileMercatorCoords,...y.tileMercatorCoords),w.clippingPlane&&t.uniform4f(w.clippingPlane,...y.clippingPlane),w.transition&&t.uniform1f(w.transition,y.projectionTransition),w.fallbackMatrix&&t.uniformMatrix4fv(w.fallbackMatrix,!1,y.fallbackMatrix);const F=f.getCenter().lat;w.elevationGlobe&&t.uniform1f(w.elevationGlobe,ne),w.elevationMercator&&t.uniform1f(w.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,F],ne).z);const g=ee(f,Y);w.camera&&t.uniform3f(w.camera,g[0],g[1],g[2]),w.sun&&t.uniform3f(w.sun,...M.sun),w.top&&t.uniform1f(w.top,1+ne/ba),w.strength&&t.uniform1f(w.strength,M.strength),w.dayColour&&t.uniform3f(w.dayColour,...M.dayColour),w.duskColour&&t.uniform3f(w.duskColour,...M.duskColour),t.bindBuffer(t.ARRAY_BUFFER,m.pos),t.enableVertexAttribArray(_.pos),t.vertexAttribPointer(_.pos,2,t.FLOAT,!1,0,0),t.bindBuffer(t.ARRAY_BUFFER,m.sphere),t.enableVertexAttribArray(_.sphere),t.vertexAttribPointer(_.sphere,3,t.FLOAT,!1,0,0),t.enable(t.DEPTH_TEST),t.depthFunc(t.LEQUAL),t.depthMask(!1),t.bindBuffer(t.ELEMENT_ARRAY_BUFFER,m.index),t.drawElements(t.TRIANGLES,l.indices.length,t.UNSIGNED_SHORT,0),t.depthMask(!0)},setOptions(A={}){M={...M,...A},f?.triggerRepaint()},getOptions:()=>({...M})}},Ea=je,Aa=32,Xe=[{width:8192,height:4096,url:"/timemap/ocean-sdf-8192.webp"},{width:4096,height:2048,url:"/timemap/ocean-sdf-4096.webp"}],Ta=`
  float oceanDistanceKm(float stored, float rangeKm) {
    return (stored * 2.0 - 1.0) * rangeKm;
  }
`,Ke="tm-ocean",Ra=e=>`${e.vertexShaderPrelude}
${e.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${ue}
void main() {
  v_sphere = a_sphere;
  // Sea level: the glint belongs on the water, not above it.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`,Ma=()=>`precision highp float;
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
${Qe}
${Se}
${ke}
${Ta}

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
}`,La=(e,{fadeInAbove:o=9e5,fadeOutBelow:s=18e4}={})=>{const h=Math.max(0,Math.min(1,(e-s)/(o-s)));return h*h*(3-2*h)},xa=(e,o,s=.8)=>{const h=156543.03392*Math.cos(o*Math.PI/180)/Math.pow(2,e);return Math.max(s,h*1.5/1e3)},Re=(e,o=Xe)=>o.find(s=>s.width<=e&&s.height<=e)||null,Ua=({opacity:e=1,strength:o=.9,roughness:s=.55,windPatch:h=.3,windScale:l=14,edgeRemap:f=0,sun:t=[.4,.5,.75],water:m=1,absorption:n=[.45,.06,.02],scatter:M=[.12,.265,.43],bottom:D=[.42,.4,.33],sky:A=[.34,.5,.76],shelfKm:I=22,shelfDepthM:T=16,shoreSoftnessKm:y=.8,fadeInAbove:r=9e5,fadeOutBelow:_=18e4,sources:w=Xe}={})=>{const F=ce();let g=null,a=null,i=null,U=null,u=!1;const P=new Map;let L={opacity:e,strength:o,roughness:s,windPatch:h,windScale:l,edgeRemap:f,sun:t,water:m,absorption:n,scatter:M,bottom:D,sky:A,shelfKm:I,shelfDepthM:T,shoreSoftnessKm:y,fadeInAbove:r,fadeOutBelow:_,sources:w};const N=b=>{const E=b.variantName;if(P.has(E))return P.get(E);const c=Z(a,Ra(b),Ma(),"tm-ocean",{es300:!0}),p={program:c,attribs:{pos:a.getAttribLocation(c,"a_pos"),sphere:a.getAttribLocation(c,"a_sphere")},uniforms:{elevationGlobe:a.getUniformLocation(c,"a_elevation_globe"),elevationMercator:a.getUniformLocation(c,"a_elevation_mercator"),camera:a.getUniformLocation(c,"u_camera"),sun:a.getUniformLocation(c,"u_sun"),globeness:a.getUniformLocation(c,"u_globeness"),field:a.getUniformLocation(c,"u_field"),rangeKm:a.getUniformLocation(c,"u_rangeKm"),shoreKm:a.getUniformLocation(c,"u_shoreKm"),shelfKm:a.getUniformLocation(c,"u_shelfKm"),strength:a.getUniformLocation(c,"u_strength"),roughness:a.getUniformLocation(c,"u_roughness"),windPatch:a.getUniformLocation(c,"u_windPatch"),windScale:a.getUniformLocation(c,"u_windScale"),water:a.getUniformLocation(c,"u_water"),scatter:a.getUniformLocation(c,"u_scatter"),bottom:a.getUniformLocation(c,"u_bottom"),absorption:a.getUniformLocation(c,"u_absorption"),shelfDepthM:a.getUniformLocation(c,"u_shelfDepthM"),sky:a.getUniformLocation(c,"u_sky"),fade:a.getUniformLocation(c,"u_fade"),opacity:a.getUniformLocation(c,"u_opacity"),edgeRemap:a.getUniformLocation(c,"u_edgeRemap"),matrix:a.getUniformLocation(c,"u_projection_matrix"),tileMercatorCoords:a.getUniformLocation(c,"u_projection_tile_mercator_coords"),clippingPlane:a.getUniformLocation(c,"u_projection_clipping_plane"),transition:a.getUniformLocation(c,"u_projection_transition"),fallbackMatrix:a.getUniformLocation(c,"u_projection_fallback_matrix")}};return P.set(E,p),p},$=()=>{U=a.createTexture(),a.bindTexture(a.TEXTURE_2D,U),a.texImage2D(a.TEXTURE_2D,0,a.LUMINANCE,1,1,0,a.LUMINANCE,a.UNSIGNED_BYTE,new Uint8Array([0])),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_MIN_FILTER,a.LINEAR),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_MAG_FILTER,a.LINEAR),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_WRAP_S,a.CLAMP_TO_EDGE),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_WRAP_T,a.CLAMP_TO_EDGE)},x=b=>{a&&(U&&a.deleteTexture(U),U=a.createTexture(),a.bindTexture(a.TEXTURE_2D,U),a.pixelStorei(a.UNPACK_FLIP_Y_WEBGL,!1),Je(a)?a.texImage2D(a.TEXTURE_2D,0,a.R8,a.RED,a.UNSIGNED_BYTE,b):a.texImage2D(a.TEXTURE_2D,0,a.LUMINANCE,a.LUMINANCE,a.UNSIGNED_BYTE,b),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_WRAP_S,a.REPEAT),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_WRAP_T,a.CLAMP_TO_EDGE),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_MIN_FILTER,a.LINEAR_MIPMAP_LINEAR),a.texParameteri(a.TEXTURE_2D,a.TEXTURE_MAG_FILTER,a.LINEAR),a.generateMipmap(a.TEXTURE_2D),u=!0,g?.triggerRepaint())},k=()=>{const b=Re(a.getParameter(a.MAX_TEXTURE_SIZE),L.sources);if(!b)return;const E=b.url;fetch(E,{credentials:"omit"}).then(c=>c.ok?c.blob():Promise.reject(new Error(c.status))).then(c=>createImageBitmap(c,{colorSpaceConversion:"none",premultiplyAlpha:"none",imageOrientation:"none"})).then(c=>{if(Re(a?.getParameter(a.MAX_TEXTURE_SIZE)??0,L.sources)?.url!==E){c.close?.();return}x(c),c.close?.()}).catch(()=>{})};return{id:Ke,type:"custom",renderingMode:"3d",onAdd(b,E){g=b,a=E;const c=(p,v)=>{const S=a.createBuffer();return a.bindBuffer(p,S),a.bufferData(p,v,a.STATIC_DRAW),S};i={pos:c(a.ARRAY_BUFFER,F.positions),sphere:c(a.ARRAY_BUFFER,F.spheres),index:c(a.ELEMENT_ARRAY_BUFFER,F.indices)},$(),k()},onRemove(){a&&(P.forEach(({program:b})=>a.deleteProgram(b)),P.clear(),U&&(a.deleteTexture(U),U=null,u=!1),i&&(a.deleteBuffer(i.pos),a.deleteBuffer(i.sphere),a.deleteBuffer(i.index),i=null),g=null,a=null)},render(b,E){if(!i||L.opacity<=0)return;const c=E&&E.shaderData,p=E&&E.defaultProjectionData;if(!c||!p)return;const{program:v,attribs:S,uniforms:d}=N(c);a.useProgram(v),d.matrix&&a.uniformMatrix4fv(d.matrix,!1,p.mainMatrix),d.tileMercatorCoords&&a.uniform4f(d.tileMercatorCoords,...p.tileMercatorCoords),d.clippingPlane&&a.uniform4f(d.clippingPlane,...p.clippingPlane),d.transition&&a.uniform1f(d.transition,p.projectionTransition),d.fallbackMatrix&&a.uniformMatrix4fv(d.fallbackMatrix,!1,p.fallbackMatrix);const H=g.getCenter(),R=0;d.elevationGlobe&&a.uniform1f(d.elevationGlobe,R),d.elevationMercator&&a.uniform1f(d.elevationMercator,Y.MercatorCoordinate.fromLngLat([0,H.lat],R).z),d.camera&&a.uniform3f(d.camera,...ee(g,Y)),d.sun&&a.uniform3f(d.sun,...L.sun),d.globeness&&a.uniform1f(d.globeness,p.projectionTransition),d.field&&(a.activeTexture(a.TEXTURE0),a.bindTexture(a.TEXTURE_2D,U),a.uniform1i(d.field,0)),d.rangeKm&&a.uniform1f(d.rangeKm,Aa),d.shoreKm&&a.uniform1f(d.shoreKm,xa(g.getZoom(),H.lat,L.shoreSoftnessKm)),d.shelfKm&&a.uniform1f(d.shelfKm,L.shelfKm),d.strength&&a.uniform1f(d.strength,L.strength),d.roughness&&a.uniform1f(d.roughness,L.roughness),d.windPatch&&a.uniform1f(d.windPatch,L.windPatch),d.windScale&&a.uniform1f(d.windScale,L.windScale),d.water&&a.uniform1f(d.water,L.water),d.scatter&&a.uniform3f(d.scatter,...L.scatter),d.bottom&&a.uniform3f(d.bottom,...L.bottom),d.absorption&&a.uniform3f(d.absorption,...L.absorption),d.shelfDepthM&&a.uniform1f(d.shelfDepthM,L.shelfDepthM),d.sky&&a.uniform3f(d.sky,...L.sky),d.opacity&&a.uniform1f(d.opacity,L.opacity),d.edgeRemap&&a.uniform1f(d.edgeRemap,L.edgeRemap),d.fade&&a.uniform1f(d.fade,La(Le(g,Y),L)),a.bindBuffer(a.ARRAY_BUFFER,i.pos),a.enableVertexAttribArray(S.pos),a.vertexAttribPointer(S.pos,2,a.FLOAT,!1,0,0),a.bindBuffer(a.ARRAY_BUFFER,i.sphere),a.enableVertexAttribArray(S.sphere),a.vertexAttribPointer(S.sphere,3,a.FLOAT,!1,0,0),a.disable(a.DEPTH_TEST),a.depthMask(!1),a.bindBuffer(a.ELEMENT_ARRAY_BUFFER,i.index),a.drawElements(a.TRIANGLES,F.indices.length,a.UNSIGNED_SHORT,0),a.enable(a.DEPTH_TEST),a.depthMask(!0)},setOptions(b={}){const E=L.sources;L={...L,...b},b.sources!==void 0&&b.sources!==E&&(u=!1,a&&k()),g?.triggerRepaint()},getOptions:()=>({...L}),get hasField(){return u}}},Sa=Ke,de=[{key:"starfield",option:"brightness",label:"Stars",default:.5,max:1},{key:"sun",option:"brightness",label:"Sun",default:1,max:1},{key:"moon",option:"brightness",label:"Moon",default:1,max:1},{key:"daylight",option:"nightDarkness",label:"Day and night",default:.85,max:1},{key:"atmosphere",option:"strength",label:"Atmosphere",default:1,max:1},{key:"clouds",option:"opacity",label:"Clouds",default:1,max:1},{key:"ocean",option:"opacity",label:"Ocean",default:1,max:1},{key:"relief",option:"reliefPower",label:"Relief",default:0,max:2,on:"daylight"}],le={key:"reference",default:0},ka="tm-layers",Da=(e=globalThis.localStorage)=>{const o={};for(const s of de)o[s.key]={visible:s.default>0,value:s.default};o[le.key]={visible:!1,value:le.default};try{const s=JSON.parse(e?.getItem(ka)||"{}");for(const[h,l]of Object.entries(s)){if(!o[h]||!l||typeof l!="object")continue;const f=l.value;o[h]={visible:l.visible===!0,value:typeof f=="number"&&Number.isFinite(f)?f:o[h].value}}}catch{}return o},Pa=(e,o)=>{const s=o?.visible?Number(o.value):0;return{[e.option]:Number.isFinite(s)?s:0}},Ca=(e,o)=>{const s=[];for(const h of de){const l=e?.[h.on||h.key];!l||typeof l.setOptions!="function"||(l.setOptions(Pa(h,o?.[h.key])),s.push(h.key))}return s},Ia=(e,{onReference:o=null,target:s=globalThis}={})=>{const h=new Map(de.map(l=>[l.key,l]));return s.__tmSetLayer=(l,f)=>{const t=Number.isFinite(Number(f))?Number(f):0;if(l===le.key)return o?.(t);const m=h.get(l);if(!m)return;const n=e?.[m.on||m.key];if(!(!n||typeof n.setOptions!="function"))return n.setOptions({[m.option]:t})},()=>{s.__tmSetLayer&&delete s.__tmSetLayer}},Na="/img/map/clouds-field.webp",Fa="/img/map/wind-field.png",Me=[["starfield",Lt,e=>Mt(e)],["sun",zt,e=>Gt(e)],["moon",Zt,e=>Vt(e)],["ocean",Sa,e=>Ua(e)],["clouds",et,e=>tt(e)],["daylight",_a,e=>pa(e)],["atmosphere",Ea,e=>va(e)]],$a=(e,{date:o=new Date,reduceMotion:s=!1,beforeId:h}={})=>{const l=fe(o),f=!s,t={fieldUrl:Na,windUrl:Fa,animate:f},m={starfield:{date:o,animate:f},sun:{date:o},moon:{date:o,sun:l},ocean:{sun:l},clouds:{...t,sun:l},daylight:{...t,sun:l,date:o},atmosphere:{sun:l}},n={};for(const[D,A,I]of Me){e.getLayer(A)&&e.removeLayer(A);const T=I(m[D]);e.addLayer(T,h&&e.getLayer(h)?h:void 0),n[D]=T}Ca(n,Da());const M=Ia(n);return{layers:n,remove(){M?.();for(const[,D]of Me)e.getLayer(D)&&e.removeLayer(D)}}};export{$a as addGlobeLayers};
