const v="#0f172a",g="#f59e0b",M=["A","B","C","D"],T=["#e11d48","#0284c7","#d97706","#059669"];const $=["Nice!","Great!","Perfect!","Brilliant!","On fire!"],q=["Almost!","Good try!","Keep going!"];let C=!1;function L(){if(C)return;C=!0;const z=document.createElement("style");z.textContent=`
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
  `,document.head.appendChild(z)}class k{constructor(t){this.host=t,this._questions=[],this._index=0,this._answered=new Map,this._score=0,this._streak=0,this._onComplete=null,this._display=[],this._gateUntil=new Map,this._openedAt=new Map,this._responses=[],this._focusDrops=0,this._gateTimer=null,this._onVisibility=null}static _shuffledIndices(t){const e=Array.from({length:t},(s,n)=>n);for(let s=e.length-1;s>0;s--){const n=Math.floor(Math.random()*(s+1));[e[s],e[n]]=[e[n],e[s]]}return e}static _readGateMs(t){const e=String(t.question||"")+(t.options||[]).join("");return Math.min(7e3,2e3+Math.round(e.length*55/10))}get isVisible(){return this._questions.length>0}show({questions:t,onComplete:e=null,submitUrl:s=null,leaderboardUrl:n=null,hasClassroom:r=!1}){if(L(),this._questions=Array.isArray(t)?t.filter(c=>c?.question):[],this._index=0,this._answered=new Map,this._score=0,this._streak=0,this._onComplete=e,this._submitUrl=s,this._leaderboardUrl=n,this._hasClassroom=r,this._classCode=(()=>{try{return localStorage.getItem("lp_class_code")||""}catch{return""}})(),!this._questions.length){this.hide();return}this._display=this._questions.map(c=>k._shuffledIndices((c.options||[]).length||4)),this._gateUntil=new Map,this._openedAt=new Map,this._responses=[],this._focusDrops=0,this._onVisibility=()=>{document.hidden&&this.isVisible&&this._showFocusVeil()},document.addEventListener("visibilitychange",this._onVisibility),this.host.style.pointerEvents="auto",this._render()}hide(){this._questions=[],this.host.innerHTML="",this.host.style.pointerEvents="none",this._gateTimer&&(clearTimeout(this._gateTimer),this._gateTimer=null),this._onVisibility&&(document.removeEventListener("visibilitychange",this._onVisibility),this._onVisibility=null)}_showFocusVeil(){if(this._focusDrops++,this.host.querySelector("[data-focus-veil]"))return;const t=document.createElement("div");t.dataset.focusVeil="1",t.style.cssText=`position:absolute; inset:0; z-index:20; display:flex; flex-direction:column;
      align-items:center; justify-content:center; gap:14px; background:rgba(2,6,23,0.94); color:white; cursor:pointer;`,t.innerHTML=`
      <div style="font-size:42px;">&#128064;</div>
      <div style="font-size:22px; font-weight:800;">Quiz paused</div>
      <div style="font-size:15px; color:#94a3b8;">Stay with the story — tap to continue.</div>`,t.addEventListener("click",()=>t.remove()),this.host.firstElementChild?.appendChild(t)||this.host.appendChild(t)}_integritySummary(){const t=this._responses.map(i=>i.ms).filter(i=>i>=0),e=t.length?Math.round(t.reduce((i,a)=>i+a,0)/t.length):0,s=t.filter(i=>i<2e3).length;let n=0,r=0,c=null;for(const i of this._responses)r=i.displayIndex===c?r+1:1,c=i.displayIndex,n=Math.max(n,r);return{avg_ms:e,rapid_guesses:s,same_letter_streak:n,focus_drops:this._focusDrops}}_correctCount(){let t=0;return this._answered.forEach((e,s)=>{(this._display[s]||[])[e]===Number(this._questions[s]?.correct_index)&&t++}),t}_render(t=null){const e=this._questions[this._index];if(!e)return;const s=this._questions.length,n=this._answered.get(this._index),r=n!==void 0,c=this._display[this._index]||(e.options||[]).map((p,l)=>l),i=r&&c[n]===Number(e.correct_index);!r&&!this._gateUntil.has(this._index)&&this._gateUntil.set(this._index,performance.now()+k._readGateMs(e));const a=r?0:Math.max(0,(this._gateUntil.get(this._index)??0)-performance.now()),o=a>50;!o&&!r&&!this._openedAt.has(this._index)&&this._openedAt.set(this._index,performance.now()),o&&(this._gateTimer&&clearTimeout(this._gateTimer),this._gateTimer=setTimeout(()=>this._render(),Math.min(a+30,500)));const u=c.map(p=>(e.options||[])[p]).slice(0,4).map((p,l)=>{const h=c[l]===Number(e.correct_index);let f="rgba(255,255,255,0.06)",x="rgba(255,255,255,0.12)",m="";return r&&h?(f="rgba(16,185,129,0.28)",x="#10b981",(t?.kind==="correct"&&l===n||t?.kind==="wrong")&&(m="qz-correct")):r&&l===n&&!h&&(f="rgba(225,29,72,0.22)",x="#e11d48",t?.kind==="wrong"&&(m="qz-wrong")),`
        <button data-opt="${l}" ${r||o?"disabled":""} class="${m}"
                style="position:relative; display:flex; align-items:center; gap:12px; width:100%; text-align:left;
                       padding:12px 16px; border-radius:14px; cursor:${r?"default":o?"wait":"pointer"};
                       background:${f}; border:1.5px solid ${x}; color:#f1f5f9; font-size:17px;
                       opacity:${o?.45:1};
                       transition:background 0.15s, border-color 0.15s, transform 0.1s, opacity 0.3s;"
                onpointerdown="if(!this.disabled) this.style.transform='scale(0.985)'"
                onpointerup="this.style.transform=''" onpointerleave="this.style.transform=''">
          <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
                       border-radius:8px; font-weight:700; font-size:13px; color:white; flex-shrink:0;
                       background:${T[l]};">${M[l]}</span>
          <span>${this._escape(p)}</span>
        </button>`}).join("");let _="";if(r){const p=t?.word??(i?e.asks_ahead?"You already knew this!":$[this._index%$.length]:e.asks_ahead?"No worries — you'll hear this later in the story!":q[this._index%q.length]);_=`
        <div style="margin-top:14px; animation: qz-slide-in 0.25s ease-out;">
          <span style="font-size:16px; font-weight:800; color:${i?"#34d399":"#fbbf24"};">${p}</span>
          ${e.explanation?`<span style="font-size:14px; color:#94a3b8; line-height:1.5; margin-left:8px;">${this._escape(e.explanation)}</span>`:""}
        </div>`}const y=this._streak>=3?`<span class="qz-streak" title="${this._streak} in a row" style="margin-right:10px; font-weight:800; color:#fb923c; font-size:14px;">▲ ${this._streak} streak</span>`:"",w=this._index===s-1?"Finish":"Next ›";this.host.innerHTML=`
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="position:relative; width:min(680px, calc(100vw - 32px)); background:${v};
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
              <span data-pager>${this._index+1} / ${s}</span>
            </span>
          </div>
          ${e.asks_ahead?`<div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:10px; padding:4px 12px;
                border-radius:999px; background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4);
                color:#fbbf24; font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">
                Sneak peek — this comes later in the story</div>`:""}
          <div style="font-size:22px; font-weight:600; line-height:1.35; margin-bottom:20px;">${this._escape(e.question)}</div>
          ${o?`<div style="display:flex; align-items:center; gap:8px; margin-bottom:10px; color:#94a3b8; font-size:13px;">
              <span class="loading loading-ring loading-xs" style="color:#f59e0b;"></span>
              Read the question&hellip; answers unlock in ${Math.ceil(a/1e3)}s</div>`:""}
          <div style="display:flex; flex-direction:column; gap:10px;">${u}</div>
          ${_}
          <div style="display:flex; align-items:center; justify-content:space-between; margin-top:24px;">
            <button data-prev ${this._index===0?"disabled":""}
                    style="background:none; border:none; color:${this._index===0?"#334155":"#cbd5e1"};
                           font-size:15px; cursor:${this._index===0?"default":"pointer"}; padding:8px 12px;">‹ Previous</button>
            <div style="display:flex; gap:6px;">
              ${this._questions.map((p,l)=>{const h=this._answered.has(l),f=h&&this._answered.get(l)===Number(this._questions[l].correct_index);return`<span style="width:8px; height:8px; border-radius:99px; background:${l===this._index?g:h?f?"#10b981":"#64748b":"rgba(255,255,255,0.15)"};"></span>`}).join("")}
            </div>
            <button data-next
                    style="background:${g}; border:none; color:#0f172a; font-weight:700; font-size:15px;
                           padding:8px 20px; border-radius:12px; cursor:pointer; transition:transform 0.1s;"
                    onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">${w}</button>
          </div>
        </div>
      </div>`,this.host.querySelectorAll("[data-opt]").forEach(p=>{p.addEventListener("click",()=>this._answer(Number(p.dataset.opt),p))}),this.host.querySelector("[data-prev]")?.addEventListener("click",()=>{this._index>0&&(this._index--,this._render())}),this.host.querySelector("[data-next]")?.addEventListener("click",()=>{this._index<s-1?(this._index++,this._render()):this._renderScoreScreen()}),t?.kind==="correct"&&this._playCorrectEffects(t)}_answer(t,e){if(this._answered.has(this._index)||(this._gateUntil.get(this._index)??0)>performance.now()+50)return;const s=this._questions[this._index],n=this._display[this._index]||(s.options||[]).map((i,a)=>a),r=n[t]===Number(s.correct_index);this._answered.set(this._index,t);const c=this._openedAt.get(this._index);if(this._responses.push({ms:c!==void 0?Math.round(performance.now()-c):-1,displayIndex:t,snapshot:{question_order:this._index+1,question_text:String(s.question||""),chosen_text:String((s.options||[])[n[t]]??""),correct_text:String((s.options||[])[Number(s.correct_index)]??""),was_correct:r,response_ms:c!==void 0?Math.round(performance.now()-c):null,asks_ahead:!!s.asks_ahead}}),r){this._streak++;const a=10+(this._streak>=3?5:0),o=this._score;this._score+=a;const d=e.getBoundingClientRect();this._render({kind:"correct",gained:a,from:o,at:{x:d.left+d.width/2,y:d.top}})}else s.asks_ahead||(this._streak=0),this._render({kind:"wrong"})}_playCorrectEffects({gained:t,from:e,at:s}){const n=this.host.querySelector("[data-score]"),r=this.host.querySelector("[data-score-value]");if(n&&r){n.classList.add("qz-score-bump");const i=this._score,a=performance.now(),o=d=>{const u=Math.min(1,(d-a)/500);r.textContent=Math.round(e+(i-e)*(1-Math.pow(1-u,3))),u<1&&requestAnimationFrame(o)};requestAnimationFrame(o)}const c=document.createElement("div");c.textContent=`+${t}`,c.style.cssText=`position:fixed; left:${s.x}px; top:${s.y}px; z-index:90; pointer-events:none;
      transform:translateX(-50%); font-weight:900; font-size:26px; color:#fbbf24;
      text-shadow:0 2px 10px rgba(0,0,0,0.6); animation: qz-float-up 0.9s ease-out forwards;`,document.body.appendChild(c),setTimeout(()=>c.remove(),950);for(let i=0;i<10;i++){const a=document.createElement("div"),o=Math.PI*2*i/10+Math.random()*.5,d=46+Math.random()*34,u=["#fbbf24","#34d399","#38bdf8","#f472b6"];a.style.cssText=`position:fixed; left:${s.x}px; top:${s.y}px; z-index:89; pointer-events:none;
        width:8px; height:8px; border-radius:99px; background:${u[i%u.length]};
        --dx:${Math.cos(o)*d}px; --dy:${Math.sin(o)*d-20}px;
        animation: qz-burst 0.65s ease-out forwards;`,document.body.appendChild(a),setTimeout(()=>a.remove(),700)}}_renderScoreScreen(){const t=this._questions.length,e=this._correctCount(),s=t?e/t:0,n=s>=.9?3:s>=.6?2:1,r=[0,1,2].map(l=>`
      <svg viewBox="0 0 24 24" style="width:56px; height:56px;
           fill:${l<n?"#fbbf24":"rgba(255,255,255,0.12)"};
           animation: qz-star-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) ${.15+l*.22}s backwards;">
        <path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
      </svg>`).join(""),c=(()=>{try{return localStorage.getItem("lp_quiz_nickname")||""}catch{return""}})(),i=this._submitUrl?`
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
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); background:${v};
                    border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:40px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="display:flex; justify-content:center; gap:10px; margin-bottom:18px;">${r}</div>
          <div style="font-size:15px; letter-spacing:0.15em; text-transform:uppercase; color:#94a3b8; margin-bottom:6px;">
            ${e} / ${t} correct
          </div>
          <div data-final-score style="font-size:56px; font-weight:900; color:#fbbf24; margin-bottom:22px;">0</div>
          ${i}
          <button data-done
                  style="background:${this._submitUrl?"none":g}; border:${this._submitUrl?"1px solid rgba(255,255,255,0.25)":"none"};
                         color:${this._submitUrl?"#cbd5e1":"#0f172a"}; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            ${this._submitUrl?"Skip ›":"Continue ›"}
          </button>
        </div>
      </div>`;const a=this.host.querySelector("[data-nickname]");a&&(a.value=c);const o=this.host.querySelector("[data-class-code]");o&&(o.value=this._classCode);const d=this.host.querySelector("[data-final-score]"),u=performance.now(),_=this._score,y=l=>{const h=Math.min(1,(l-u)/900);d.textContent=Math.round(_*(1-Math.pow(1-h,3))),h<1&&requestAnimationFrame(y)};requestAnimationFrame(y),this.host.querySelector("[data-done]").addEventListener("click",()=>{const l=this._onComplete;this.hide(),l?.()});const b=this.host.querySelector("[data-submit]"),w=this.host.querySelector("[data-nickname]"),p=async()=>{const l=this.host.querySelector("[data-class-code]");this._classCode=(l?.value||"").trim().toUpperCase();try{this._classCode&&localStorage.setItem("lp_class_code",this._classCode)}catch{}const h=(w?.value||"").trim(),f=this.host.querySelector("[data-join-error]");if(h.length<2){f&&(f.textContent="Pick a name (at least 2 letters).");return}try{localStorage.setItem("lp_quiz_nickname",h)}catch{}b.disabled=!0,b.textContent="…";try{const x=document.querySelector('meta[name="csrf-token"]')?.content||"",m=await fetch(this._submitUrl,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":x,Accept:"application/json"},body:JSON.stringify({nickname:h,score:this._score,correct:e,total:t,integrity:this._integritySummary(),answers:this._responses.map(E=>E.snapshot).filter(Boolean),class_code:this._classCode||null,member_name:this._classCode?h:null})});if(!m.ok)throw new Error(`HTTP ${m.status}`);const S=await m.json();this._renderLeaderboard(S,h)}catch(x){b.disabled=!1,b.textContent="Submit",f&&(f.textContent=x?.message==="HTTP 422"?"Check the class code — ask your teacher.":"Could not submit — try again.")}};b?.addEventListener("click",p),w?.addEventListener("keydown",l=>{l.key==="Enter"&&p()})}_renderLeaderboard({top:t=[],players:e=0,rank:s=null},n=""){const r=["#fbbf24","#cbd5e1","#d97706"],c=t.map((a,o)=>{const d=s!==null&&o===s-1&&a.nickname===n,u=o<3?`<span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px;
                        border-radius:99px; background:${r[o]}; color:#0f172a; font-weight:900; font-size:13px;">${o+1}</span>`:`<span style="width:26px; text-align:center; color:#64748b; font-weight:700; font-size:13px;">${o+1}</span>`;return`
        <div style="display:flex; align-items:center; gap:12px; padding:9px 14px; border-radius:12px;
                    background:${d?"rgba(245,158,11,0.16)":o%2?"rgba(255,255,255,0.03)":"transparent"};
                    border:1px solid ${d?"rgba(245,158,11,0.55)":"transparent"};
                    animation: qz-slide-in 0.3s ease-out ${.08*o}s backwards;">
          ${u}
          <span style="flex:1; text-align:left; font-weight:${o<3||d?700:500}; font-size:15px;
                       overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            ${this._escape(a.nickname)}${d?" · you":""}
          </span>
          <span style="font-weight:800; color:#fbbf24; font-size:15px;">${a.score}</span>
        </div>`}).join(""),i=s!==null&&s>t.length?`<div style="margin-top:10px; font-size:14px; color:#fbbf24; font-weight:700;">You're #${s} of ${e} — keep climbing!</div>`:"";this.host.innerHTML=`
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); max-height:calc(100vh - 60px); overflow-y:auto;
                    background:${v}; border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:32px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="font-size:13px; letter-spacing:0.2em; text-transform:uppercase; color:${g}; margin-bottom:4px;">Leaderboard</div>
          <div style="font-size:13px; color:#64748b; margin-bottom:18px;">${e} player${e===1?"":"s"}</div>
          <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">${c||'<span style="color:#64748b;">No scores yet — you could be first!</span>'}</div>
          ${i}
          <button data-done
                  style="margin-top:22px; background:${g}; border:none; color:#0f172a; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            Continue ›
          </button>
        </div>
      </div>`,this.host.querySelector("[data-done]").addEventListener("click",()=>{const a=this._onComplete;this.hide(),a?.()})}_escape(t){const e=document.createElement("div");return e.textContent=String(t??""),e.innerHTML.replace(/"/g,"&quot;").replace(/'/g,"&#39;")}}export{k as QuizOverlay};
