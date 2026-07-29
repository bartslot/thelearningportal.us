const k="#0f172a",g="#f59e0b",T=["A","B","C","D"],M=["#e11d48","#0284c7","#d97706","#059669"];const $=["Nice!","Great!","Perfect!","Brilliant!","On fire!"],z=["Almost!","Good try!","Keep going!"];let S=!1;function A(){if(S)return;S=!0;const q=document.createElement("style");q.textContent=`
    @keyframes qz-pop { 0% { transform: scale(1); } 45% { transform: scale(1.08); } 100% { transform: scale(1); } }
    @keyframes qz-wobble { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-7px); } 55% { transform: translateX(6px); } 80% { transform: translateX(-3px); } }
    @keyframes qz-float-up { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 15% { opacity: 1; transform: translateY(-8px) scale(1.15); } 100% { transform: translateY(-64px) scale(1); opacity: 0; } }
    @keyframes qz-burst { 0% { transform: translate(0,0) scale(1); opacity: 1; } 100% { transform: translate(var(--dx), var(--dy)) scale(0.3); opacity: 0; } }
    @keyframes qz-score-pop { 0% { transform: scale(1); } 50% { transform: scale(1.35); } 100% { transform: scale(1); } }
    @keyframes qz-star-in { 0% { transform: scale(0) rotate(-30deg); opacity: 0; } 60% { transform: scale(1.25) rotate(8deg); opacity: 1; } 100% { transform: scale(1) rotate(0); opacity: 1; } }
    @keyframes qz-slide-in { 0% { transform: translateY(14px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
    @keyframes qz-flame { 0%,100% { transform: scale(1) rotate(-2deg); } 50% { transform: scale(1.12) rotate(2deg); } }
    .qz-card { animation: qz-slide-in 0.28s cubic-bezier(0.16, 1, 0.3, 1); }
    .qz-correct { animation: qz-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .qz-wrong { animation: qz-wobble 0.45s ease-in-out; }
    .qz-score-bump { animation: qz-score-pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .qz-streak { animation: qz-flame 0.9s ease-in-out infinite; display: inline-block; }
  `,document.head.appendChild(q)}class v{constructor(t){this.host=t,this._questions=[],this._index=0,this._answered=new Map,this._score=0,this._streak=0,this._onComplete=null,this._display=[],this._gateUntil=new Map,this._openedAt=new Map,this._responses=[],this._focusDrops=0,this._gateTimer=null,this._onVisibility=null}static _shuffledIndices(t){const s=Array.from({length:t},(e,l)=>l);for(let e=s.length-1;e>0;e--){const l=Math.floor(Math.random()*(e+1));[s[e],s[l]]=[s[l],s[e]]}return s}static _seededShuffle(t,s){let e=s>>>0;const l=()=>{e=e+1831565813>>>0;let i=Math.imul(e^e>>>15,1|e);return i=i+Math.imul(i^i>>>7,61|i)^i,((i^i>>>14)>>>0)/4294967296},o=Array.from({length:t},(i,n)=>n);for(let i=o.length-1;i>0;i--){const n=Math.floor(l()*(i+1));[o[i],o[n]]=[o[n],o[i]]}return o}static _readGateMs(t){const s=String(t.question||"")+(t.options||[]).join("");return Math.min(7e3,2e3+Math.round(s.length*55/10))}get isVisible(){return this._questions.length>0}show({questions:t,onComplete:s=null,submitUrl:e=null,leaderboardUrl:l=null,hasClassroom:o=!1,shuffleMode:i="per_player"}){if(A(),this._questions=Array.isArray(t)?t.filter(n=>n?.question):[],this._index=0,this._answered=new Map,this._score=0,this._streak=0,this._onComplete=s,this._submitUrl=e,this._leaderboardUrl=l,this._hasClassroom=o,this._classCode=(()=>{try{return localStorage.getItem("lp_class_code")||""}catch{return""}})(),!this._questions.length){this.hide();return}this._shuffleSalt=(t?.[0]?.question||"").split("").reduce((n,a)=>n*31+a.charCodeAt(0)>>>0,7),this._display=this._questions.map((n,a)=>{const r=(n.options||[]).length||4;return i==="off"?Array.from({length:r},(d,u)=>u):i==="once"?v._seededShuffle(r,a+1+this._shuffleSalt):v._shuffledIndices(r)}),this._gateUntil=new Map,this._openedAt=new Map,this._responses=[],this._focusDrops=0,this._onVisibility=()=>{document.hidden&&this.isVisible&&this._showFocusVeil()},document.addEventListener("visibilitychange",this._onVisibility),this.host.style.pointerEvents="auto",this._render()}hide(){this._questions=[],this.host.innerHTML="",this.host.style.pointerEvents="none",this._gateTimer&&(clearTimeout(this._gateTimer),this._gateTimer=null),this._onVisibility&&(document.removeEventListener("visibilitychange",this._onVisibility),this._onVisibility=null)}_showFocusVeil(){if(this._focusDrops++,this.host.querySelector("[data-focus-veil]"))return;const t=document.createElement("div");t.dataset.focusVeil="1",t.style.cssText=`position:absolute; inset:0; z-index:20; display:flex; flex-direction:column;
      align-items:center; justify-content:center; gap:14px; background:rgba(2,6,23,0.94); color:white; cursor:pointer;`,t.innerHTML=`
      <div style="font-size:42px;">&#128064;</div>
      <div style="font-size:22px; font-weight:800;">Quiz paused</div>
      <div style="font-size:15px; color:#94a3b8;">Stay with the story — tap to continue.</div>`,t.addEventListener("click",()=>t.remove()),this.host.firstElementChild?.appendChild(t)||this.host.appendChild(t)}_integritySummary(){const t=this._responses.map(n=>n.ms).filter(n=>n>=0),s=t.length?Math.round(t.reduce((n,a)=>n+a,0)/t.length):0,e=t.filter(n=>n<2e3).length;let l=0,o=0,i=null;for(const n of this._responses)o=n.displayIndex===i?o+1:1,i=n.displayIndex,l=Math.max(l,o);return{avg_ms:s,rapid_guesses:e,same_letter_streak:l,focus_drops:this._focusDrops}}_correctCount(){let t=0;return this._answered.forEach((s,e)=>{(this._display[e]||[])[s]===Number(this._questions[e]?.correct_index)&&t++}),t}_render(t=null){const s=this._questions[this._index];if(!s)return;const e=this._questions.length,l=this._answered.get(this._index),o=l!==void 0,i=this._display[this._index]||(s.options||[]).map((p,c)=>c),n=o&&i[l]===Number(s.correct_index);!o&&!this._gateUntil.has(this._index)&&this._gateUntil.set(this._index,performance.now()+v._readGateMs(s));const a=o?0:Math.max(0,(this._gateUntil.get(this._index)??0)-performance.now()),r=a>50;!r&&!o&&!this._openedAt.has(this._index)&&this._openedAt.set(this._index,performance.now()),r&&(this._gateTimer&&clearTimeout(this._gateTimer),this._gateTimer=setTimeout(()=>this._gateTick(),Math.min(a+30,250)));const u=i.map(p=>(s.options||[])[p]).slice(0,4).map((p,c)=>{const h=i[c]===Number(s.correct_index);let f="rgba(255,255,255,0.06)",m="rgba(255,255,255,0.12)",x="";return o&&h?(f="rgba(16,185,129,0.28)",m="#10b981",(t?.kind==="correct"&&c===l||t?.kind==="wrong")&&(x="qz-correct")):o&&c===l&&!h&&(f="rgba(225,29,72,0.22)",m="#e11d48",t?.kind==="wrong"&&(x="qz-wrong")),`
        <button data-opt="${c}" ${o||r?"disabled":""} class="${x}"
                style="position:relative; display:flex; align-items:center; gap:12px; width:100%; text-align:left;
                       padding:12px 16px; border-radius:14px; cursor:${o?"default":r?"wait":"pointer"};
                       background:${f}; border:1.5px solid ${m}; color:#f1f5f9; font-size:17px;
                       opacity:${r?.45:1};
                       transition:background 0.15s, border-color 0.15s, transform 0.1s, opacity 0.3s;"
                onpointerdown="if(!this.disabled) this.style.transform='scale(0.985)'"
                onpointerup="this.style.transform=''" onpointerleave="this.style.transform=''">
          <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
                       border-radius:8px; font-weight:700; font-size:13px; color:white; flex-shrink:0;
                       background:${M[c]};">${T[c]}</span>
          <span>${this._escape(p)}</span>
        </button>`}).join("");let _="";if(o){const p=t?.word??(n?s.asks_ahead?"You already knew this!":$[this._index%$.length]:s.asks_ahead?"No worries — you'll hear this later in the story!":z[this._index%z.length]);_=`
        <div style="margin-top:14px; animation: qz-slide-in 0.25s ease-out;">
          <span style="font-size:16px; font-weight:800; color:${n?"#34d399":"#fbbf24"};">${p}</span>
          ${s.explanation?`<span style="font-size:14px; color:#94a3b8; line-height:1.5; margin-left:8px;">${this._escape(s.explanation)}</span>`:""}
        </div>`}const y=this._streak>=3?`<span class="qz-streak" title="${this._streak} in a row" style="margin-right:10px; font-weight:800; color:#fb923c; font-size:14px;">▲ ${this._streak} streak</span>`:"",w=this._index===e-1?"Finish":"Next ›";this.host.innerHTML=`
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="position:relative; width:min(680px, calc(100vw - 32px)); background:${k};
                    border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:32px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
            <span style="font-size:12px; letter-spacing:0.2em; text-transform:uppercase; color:${g};">Quiz</span>
            <span style="display:flex; align-items:center; font-size:13px; color:#94a3b8;">
              ${y}
              <span data-score style="display:inline-flex; align-items:center; gap:5px; font-weight:800; color:#fbbf24; font-size:15px; margin-right:14px;">
                <svg viewBox="0 0 24 24" fill="currentColor" style="width:15px;height:15px;"><path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/></svg>
                <span data-score-value>${this._score}</span>
              </span>
              <span data-pager>${this._index+1} / ${e}</span>
            </span>
          </div>
          ${s.asks_ahead?`<div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:10px; padding:4px 12px;
                border-radius:999px; background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4);
                color:#fbbf24; font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">
                Sneak peek — this comes later in the story</div>`:""}
          <div style="font-size:22px; font-weight:600; line-height:1.35; margin-bottom:20px;">${this._escape(s.question)}</div>
          ${r?`<div data-gate-note style="display:flex; align-items:center; gap:8px; margin-bottom:10px; color:#94a3b8; font-size:13px;">
              <span class="loading loading-ring loading-xs" style="color:#f59e0b;"></span>
              Read the question&hellip; answers unlock in <span data-gate-secs>${Math.ceil(a/1e3)}</span>s</div>`:""}
          <div style="display:flex; flex-direction:column; gap:10px;">${u}</div>
          ${_}
          <div style="display:flex; align-items:center; justify-content:space-between; margin-top:24px;">
            <button data-prev ${this._index===0?"disabled":""}
                    style="background:none; border:none; color:${this._index===0?"#334155":"#cbd5e1"};
                           font-size:15px; cursor:${this._index===0?"default":"pointer"}; padding:8px 12px;">‹ Previous</button>
            <div style="display:flex; gap:6px;">
              ${this._questions.map((p,c)=>{const h=this._answered.has(c),f=this._display[c]||[],m=h&&f[this._answered.get(c)]===Number(this._questions[c].correct_index);return`<span style="width:8px; height:8px; border-radius:99px; background:${c===this._index?g:h?m?"#10b981":"#64748b":"rgba(255,255,255,0.15)"};"></span>`}).join("")}
            </div>
            <button data-next
                    style="background:${g}; border:none; color:#0f172a; font-weight:700; font-size:15px;
                           padding:8px 20px; border-radius:12px; cursor:pointer; transition:transform 0.1s;"
                    onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">${w}</button>
          </div>
        </div>
      </div>`,this.host.querySelectorAll("[data-opt]").forEach(p=>{p.addEventListener("click",()=>this._answer(Number(p.dataset.opt),p))}),this.host.querySelector("[data-prev]")?.addEventListener("click",()=>{this._index>0&&(this._index--,this._render())}),this.host.querySelector("[data-next]")?.addEventListener("click",()=>{this._index<e-1?(this._index++,this._render()):this._renderScoreScreen()}),t?.kind==="correct"&&this._playCorrectEffects(t)}_gateTick(){if(this._gateTimer=null,!this.isVisible||this._answered.has(this._index))return;const t=Math.max(0,(this._gateUntil.get(this._index)??0)-performance.now()),s=this.host.querySelector("[data-gate-note]");if(t>50){const e=s?.querySelector("[data-gate-secs]");e&&(e.textContent=Math.ceil(t/1e3)),this._gateTimer=setTimeout(()=>this._gateTick(),Math.min(t+30,250));return}this._openedAt.has(this._index)||this._openedAt.set(this._index,performance.now()),s?.remove(),this.host.querySelectorAll("[data-opt]").forEach(e=>{e.disabled=!1,e.style.opacity="1",e.style.cursor="pointer"})}_answer(t,s){if(this._answered.has(this._index)||(this._gateUntil.get(this._index)??0)>performance.now()+50)return;const e=this._questions[this._index],l=this._display[this._index]||(e.options||[]).map((n,a)=>a),o=l[t]===Number(e.correct_index);this._answered.set(this._index,t);const i=this._openedAt.get(this._index);if(this._responses.push({ms:i!==void 0?Math.round(performance.now()-i):-1,displayIndex:t,snapshot:{question_order:this._index+1,question_text:String(e.question||""),chosen_text:String((e.options||[])[l[t]]??""),correct_text:String((e.options||[])[Number(e.correct_index)]??""),was_correct:o,response_ms:i!==void 0?Math.round(performance.now()-i):null,asks_ahead:!!e.asks_ahead}}),o){this._streak++;const a=10+(this._streak>=3?5:0),r=this._score;this._score+=a;const d=s.getBoundingClientRect();this._render({kind:"correct",gained:a,from:r,at:{x:d.left+d.width/2,y:d.top}})}else e.asks_ahead||(this._streak=0),this._render({kind:"wrong"})}_playCorrectEffects({gained:t,from:s,at:e}){const l=this.host.querySelector("[data-score]"),o=this.host.querySelector("[data-score-value]");if(l&&o){l.classList.add("qz-score-bump");const n=this._score,a=performance.now(),r=d=>{const u=Math.min(1,(d-a)/500);o.textContent=Math.round(s+(n-s)*(1-Math.pow(1-u,3))),u<1&&requestAnimationFrame(r)};requestAnimationFrame(r)}const i=document.createElement("div");i.textContent=`+${t}`,i.style.cssText=`position:fixed; left:${e.x}px; top:${e.y}px; z-index:90; pointer-events:none;
      transform:translateX(-50%); font-weight:900; font-size:26px; color:#fbbf24;
      text-shadow:0 2px 10px rgba(0,0,0,0.6); animation: qz-float-up 0.9s ease-out forwards;`,document.body.appendChild(i),setTimeout(()=>i.remove(),950);for(let n=0;n<10;n++){const a=document.createElement("div"),r=Math.PI*2*n/10+Math.random()*.5,d=46+Math.random()*34,u=["#fbbf24","#34d399","#38bdf8","#f472b6"];a.style.cssText=`position:fixed; left:${e.x}px; top:${e.y}px; z-index:89; pointer-events:none;
        width:8px; height:8px; border-radius:99px; background:${u[n%u.length]};
        --dx:${Math.cos(r)*d}px; --dy:${Math.sin(r)*d-20}px;
        animation: qz-burst 0.65s ease-out forwards;`,document.body.appendChild(a),setTimeout(()=>a.remove(),700)}}_renderScoreScreen(){const t=this._questions.length,s=this._correctCount(),e=t?s/t:0,l=e>=.9?3:e>=.6?2:1,o=[0,1,2].map(c=>`
      <svg viewBox="0 0 24 24" style="width:56px; height:56px;
           fill:${c<l?"#fbbf24":"rgba(255,255,255,0.12)"};
           animation: qz-star-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) ${.15+c*.22}s backwards;">
        <path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
      </svg>`).join(""),i=(()=>{try{return localStorage.getItem("lp_quiz_nickname")||""}catch{return""}})(),n=this._submitUrl?`
      <div data-join style="margin-bottom:22px; animation: qz-slide-in 0.3s ease-out 0.6s backwards;">
        <div style="font-size:13px; letter-spacing:0.12em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px;">
          Join the leaderboard
        </div>
        ${this._hasClassroom?`
        <div style="display:flex; gap:8px; justify-content:center; margin-bottom:8px;">
          <input data-class-code type="text" maxlength="8" placeholder="Class code…"
                 style="width:130px; padding:10px 14px; border-radius:12px; border:1.5px solid rgba(255,255,255,0.2);
                        background:rgba(255,255,255,0.06); color:white; font-size:15px; outline:none; text-transform:uppercase;" />
        </div>`:""}
        <div style="display:flex; gap:8px; justify-content:center;">
          <input data-nickname type="text" maxlength="24" placeholder="Your name…"
                 style="width:200px; padding:10px 14px; border-radius:12px; border:1.5px solid rgba(245,158,11,0.4);
                        background:rgba(255,255,255,0.06); color:white; font-size:15px; outline:none;" />
          <button data-submit
                  style="background:${g}; border:none; color:#0f172a; font-weight:800; font-size:15px;
                         padding:10px 20px; border-radius:12px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            Submit
          </button>
        </div>
        <div data-join-error style="font-size:12px; color:#fda4af; margin-top:6px; min-height:16px;"></div>
      </div>`:"";this.host.innerHTML=`
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); background:${k};
                    border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:40px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="display:flex; justify-content:center; gap:10px; margin-bottom:18px;">${o}</div>
          <div style="font-size:15px; letter-spacing:0.15em; text-transform:uppercase; color:#94a3b8; margin-bottom:6px;">
            ${s} / ${t} correct
          </div>
          <div data-final-score style="font-size:56px; font-weight:900; color:#fbbf24; margin-bottom:22px;">0</div>
          ${n}
          <button data-done
                  style="background:${this._submitUrl?"none":g}; border:${this._submitUrl?"1px solid rgba(255,255,255,0.25)":"none"};
                         color:${this._submitUrl?"#cbd5e1":"#0f172a"}; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            ${this._submitUrl?"Skip ›":"Continue ›"}
          </button>
        </div>
      </div>`;const a=this.host.querySelector("[data-nickname]");a&&(a.value=i);const r=this.host.querySelector("[data-class-code]");r&&(r.value=this._classCode);const d=this.host.querySelector("[data-final-score]"),u=performance.now(),_=this._score,y=c=>{const h=Math.min(1,(c-u)/900);d.textContent=Math.round(_*(1-Math.pow(1-h,3))),h<1&&requestAnimationFrame(y)};requestAnimationFrame(y),this.host.querySelector("[data-done]").addEventListener("click",()=>{const c=this._onComplete;this.hide(),c?.()});const b=this.host.querySelector("[data-submit]"),w=this.host.querySelector("[data-nickname]"),p=async()=>{const c=this.host.querySelector("[data-class-code]");this._classCode=(c?.value||"").trim().toUpperCase();try{this._classCode&&localStorage.setItem("lp_class_code",this._classCode)}catch{}const h=(w?.value||"").trim(),f=this.host.querySelector("[data-join-error]");if(h.length<2){f&&(f.textContent="Pick a name (at least 2 letters).");return}try{localStorage.setItem("lp_quiz_nickname",h)}catch{}b.disabled=!0,b.textContent="…";try{const m=document.querySelector('meta[name="csrf-token"]')?.content||"",x=await fetch(this._submitUrl,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":m,Accept:"application/json"},body:JSON.stringify({nickname:h,score:this._score,correct:s,total:t,integrity:this._integritySummary(),answers:this._responses.map(E=>E.snapshot).filter(Boolean),class_code:this._classCode||null,member_name:this._classCode?h:null})});if(!x.ok)throw new Error(`HTTP ${x.status}`);const C=await x.json();this._renderLeaderboard(C,h)}catch(m){b.disabled=!1,b.textContent="Submit",f&&(f.textContent=m?.message==="HTTP 422"?"Check the class code — ask your teacher.":"Could not submit — try again.")}};b?.addEventListener("click",p),w?.addEventListener("keydown",c=>{c.key==="Enter"&&p()})}_renderLeaderboard({top:t=[],players:s=0,rank:e=null},l=""){const o=["#fbbf24","#cbd5e1","#d97706"],i=t.map((a,r)=>{const d=e!==null&&r===e-1&&a.nickname===l,u=r<3?`<span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px;
                        border-radius:99px; background:${o[r]}; color:#0f172a; font-weight:900; font-size:13px;">${r+1}</span>`:`<span style="width:26px; text-align:center; color:#64748b; font-weight:700; font-size:13px;">${r+1}</span>`;return`
        <div style="display:flex; align-items:center; gap:12px; padding:9px 14px; border-radius:12px;
                    background:${d?"rgba(245,158,11,0.16)":r%2?"rgba(255,255,255,0.03)":"transparent"};
                    border:1px solid ${d?"rgba(245,158,11,0.55)":"transparent"};
                    animation: qz-slide-in 0.3s ease-out ${.08*r}s backwards;">
          ${u}
          <span style="flex:1; text-align:left; font-weight:${r<3||d?700:500}; font-size:15px;
                       overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            ${this._escape(a.nickname)}${d?" · you":""}
          </span>
          <span style="font-weight:800; color:#fbbf24; font-size:15px;">${a.score}</span>
        </div>`}).join(""),n=e!==null&&e>t.length?`<div style="margin-top:10px; font-size:14px; color:#fbbf24; font-weight:700;">You're #${e} of ${s} — keep climbing!</div>`:"";this.host.innerHTML=`
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); max-height:calc(100vh - 60px); overflow-y:auto;
                    background:${k}; border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:32px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="font-size:13px; letter-spacing:0.2em; text-transform:uppercase; color:${g}; margin-bottom:4px;">Leaderboard</div>
          <div style="font-size:13px; color:#64748b; margin-bottom:18px;">${s} player${s===1?"":"s"}</div>
          <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">${i||'<span style="color:#64748b;">No scores yet — you could be first!</span>'}</div>
          ${n}
          <button data-done
                  style="margin-top:22px; background:${g}; border:none; color:#0f172a; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            Continue ›
          </button>
        </div>
      </div>`,this.host.querySelector("[data-done]").addEventListener("click",()=>{const a=this._onComplete;this.hide(),a?.()})}_escape(t){const s=document.createElement("div");return s.textContent=String(t??""),s.innerHTML.replace(/"/g,"&quot;").replace(/'/g,"&#39;")}}export{v as QuizOverlay};
