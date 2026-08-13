const m=`
attribute vec2 a_pos;
varying vec2 v_uv;
void main() {
  v_uv = a_pos * 0.5 + 0.5;
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`,T=`
precision mediump float;
uniform sampler2D u_frame;
varying vec2 v_uv;
void main() {
  // Colour lives in the top half, alpha in the bottom. Texture V runs bottom-up, so the top half of
  // the picture is the upper half of the range.
  vec2 colourUv = vec2(v_uv.x, v_uv.y * 0.5 + 0.5);
  vec2 alphaUv  = vec2(v_uv.x, v_uv.y * 0.5);
  vec3 colour = texture2D(u_frame, colourUv).rgb;
  float alpha = texture2D(u_frame, alphaUv).r;
  gl_FragColor = vec4(colour * alpha, alpha);
}`,E=(a,o,t)=>{const e=a.createShader(o);if(a.shaderSource(e,t),a.compileShader(e),!a.getShaderParameter(e,a.COMPILE_STATUS)){const r=a.getShaderInfoLog(e);throw a.deleteShader(e),new Error(`quality: unpack shader failed to compile — ${r}`)}return e},v=({container:a,video:o})=>{const t=a.ownerDocument.createElement("canvas");t.style.cssText="width:100%;height:100%;display:block",a.appendChild(t),o.style.display="none";const e=t.getContext("webgl",{premultipliedAlpha:!0,alpha:!0,antialias:!1});if(!e)throw new Error("quality: no WebGL context for the packed-alpha fallback");const r=e.createProgram(),u=E(e,e.VERTEX_SHADER,m),h=E(e,e.FRAGMENT_SHADER,T);if(e.attachShader(r,u),e.attachShader(r,h),e.linkProgram(r),!e.getProgramParameter(r,e.LINK_STATUS))throw new Error(`quality: unpack program failed to link — ${e.getProgramInfoLog(r)}`);const l=e.createBuffer();e.bindBuffer(e.ARRAY_BUFFER,l),e.bufferData(e.ARRAY_BUFFER,new Float32Array([-1,-1,3,-1,-1,3]),e.STATIC_DRAW);const c=e.createTexture();e.bindTexture(e.TEXTURE_2D,c);for(const[i,n]of[[e.TEXTURE_WRAP_S,e.CLAMP_TO_EDGE],[e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE],[e.TEXTURE_MIN_FILTER,e.LINEAR],[e.TEXTURE_MAG_FILTER,e.LINEAR]])e.texParameteri(e.TEXTURE_2D,i,n);const f=e.getAttribLocation(r,"a_pos"),d=e.getUniformLocation(r,"u_frame");let s=0,_=!1;const p=()=>{if(_||(s=requestAnimationFrame(p),o.readyState<2))return;const i=o.videoWidth,n=Math.floor(o.videoHeight/2);(t.width!==i||t.height!==n)&&(t.width=i,t.height=n),e.bindTexture(e.TEXTURE_2D,c),e.pixelStorei(e.UNPACK_FLIP_Y_WEBGL,!1),e.texImage2D(e.TEXTURE_2D,0,e.RGBA,e.RGBA,e.UNSIGNED_BYTE,o),e.viewport(0,0,t.width,t.height),e.clearColor(0,0,0,0),e.clear(e.COLOR_BUFFER_BIT),e.useProgram(r),e.bindBuffer(e.ARRAY_BUFFER,l),e.enableVertexAttribArray(f),e.vertexAttribPointer(f,2,e.FLOAT,!1,0,0),e.uniform1i(d,0),e.drawArrays(e.TRIANGLES,0,3)};return s=requestAnimationFrame(p),{canvas:t,dispose(){_=!0,cancelAnimationFrame(s),e.deleteTexture(c),e.deleteBuffer(l),e.deleteProgram(r),e.deleteShader(u),e.deleteShader(h),t.remove(),o.style.display=""}}};export{v as createPackedAlphaUnpacker};
