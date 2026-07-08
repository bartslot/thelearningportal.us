const v="#0f172a",g="#f59e0b",S=["A","B","C","D"],E=["#e11d48","#0284c7","#d97706","#059669"];const z=["Nice!","Great!","Perfect!","Brilliant!","On fire!"],q=["Almost!","Good try!","Keep going!"];let C=!1;function M(){if(C)return;C=!0;const $=document.createElement("style");$.textContent=`
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
  `,document.head.appendChild($)}class k{constructor(t){this.host=t,this._questions=[],this._index=0,this._answered=new Map,this._score=0,this._streak=0,this._onComplete=null,this._display=[],this._gateUntil=new Map,this._openedAt=new Map,this._responses=[],this._focusDrops=0,this._gateTimer=null,this._onVisibility=null}static _shuffledIndices(t){const e=Array.from({length:t},(s,i)=>i);for(let s=e.length-1;s>0;s--){const i=Math.floor(Math.random()*(s+1));[e[s],e[i]]=[e[i],e[s]]}return e}static _readGateMs(t){const e=String(t.question||"")+(t.options||[]).join("");return Math.min(7e3,2e3+Math.round(e.length*55/10))}get isVisible(){return this._questions.length>0}show({questions:t,onComplete:e=null,submitUrl:s=null,leaderboardUrl:i=null,hasClassroom:o=!1}){if(M(),this._questions=Array.isArray(t)?t.filter(a=>a?.question):[],this._index=0,this._answered=new Map,this._score=0,this._streak=0,this._onComplete=e,this._submitUrl=s,this._leaderboardUrl=i,this._hasClassroom=o,this._classCode=(()=>{try{return localStorage.getItem("lp_class_code")||""}catch{return""}})(),!this._questions.length){this.hide();return}this._display=this._questions.map(a=>k._shuffledIndices((a.options||[]).length||4)),this._gateUntil=new Map,this._openedAt=new Map,this._responses=[],this._focusDrops=0,this._onVisibility=()=>{document.hidden&&this.isVisible&&this._showFocusVeil()},document.addEventListener("visibilitychange",this._onVisibility),this.host.style.pointerEvents="auto",this._render()}hide(){this._questions=[],this.host.innerHTML="",this.host.style.pointerEvents="none",this._gateTimer&&(clearTimeout(this._gateTimer),this._gateTimer=null),this._onVisibility&&(document.removeEventListener("visibilitychange",this._onVisibility),this._onVisibility=null)}_showFocusVeil(){if(this._focusDrops++,this.host.querySelector("[data-focus-veil]"))return;const t=document.createElement("div");t.dataset.focusVeil="1",t.style.cssText=`position:absolute; inset:0; z-index:20; display:flex; flex-direction:column;
      align-items:center; justify-content:center; gap:14px; background:rgba(2,6,23,0.94); color:white; cursor:pointer;`,t.innerHTML=`
      <div style="font-size:42px;">&#128064;</div>
      <div style="font-size:22px; font-weight:800;">Quiz paused</div>
      <div style="font-size:15px; color:#94a3b8;">Stay with the story — tap to continue.</div>`,t.addEventListener("click",()=>t.remove()),this.host.firstElementChild?.appendChild(t)||this.host.appendChild(t)}_integritySummary(){const t=this._responses.map(n=>n.ms).filter(n=>n>=0),e=t.length?Math.round(t.reduce((n,l)=>n+l,0)/t.length):0,s=t.filter(n=>n<2e3).length;let i=0,o=0,a=null;for(const n of this._responses)o=n.displayIndex===a?o+1:1,a=n.displayIndex,i=Math.max(i,o);return{avg_ms:e,rapid_guesses:s,same_letter_streak:i,focus_drops:this._focusDrops}}_correctCount(){let t=0;return this._answered.forEach((e,s)=>{(this._display[s]||[])[e]===Number(this._questions[s]?.correct_index)&&t++}),t}_render(t=null){const e=this._questions[this._index];if(!e)return;const s=this._questions.length,i=this._answered.get(this._index),o=i!==void 0,a=this._display[this._index]||(e.options||[]).map((d,c)=>c),n=o&&a[i]===Number(e.correct_index);!o&&!this._gateUntil.has(this._index)&&this._gateUntil.set(this._index,performance.now()+k._readGateMs(e));const l=o?0:Math.max(0,(this._gateUntil.get(this._index)??0)-performance.now()),r=l>50;!r&&!o&&!this._openedAt.has(this._index)&&this._openedAt.set(this._index,performance.now()),r&&(this._gateTimer&&clearTimeout(this._gateTimer),this._gateTimer=setTimeout(()=>this._render(),Math.min(l+30,500)));const h=a.map(d=>(e.options||[])[d]).slice(0,4).map((d,c)=>{const f=a[c]===Number(e.correct_index);let x="rgba(255,255,255,0.06)",b="rgba(255,255,255,0.12)",_="";return o&&f?(x="rgba(16,185,129,0.28)",b="#10b981",(t?.kind==="correct"&&c===i||t?.kind==="wrong")&&(_="qz-correct")):o&&c===i&&!f&&(x="rgba(225,29,72,0.22)",b="#e11d48",t?.kind==="wrong"&&(_="qz-wrong")),`
        <button data-opt="${c}" ${o||r?"disabled":""} class="${_}"
                style="position:relative; display:flex; align-items:center; gap:12px; width:100%; text-align:left;
                       padding:12px 16px; border-radius:14px; cursor:${o?"default":r?"wait":"pointer"};
                       background:${x}; border:1.5px solid ${b}; color:#f1f5f9; font-size:17px;
                       opacity:${r?.45:1};
                       transition:background 0.15s, border-color 0.15s, transform 0.1s, opacity 0.3s;"
                onpointerdown="if(!this.disabled) this.style.transform='scale(0.985)'"
                onpointerup="this.style.transform=''" onpointerleave="this.style.transform=''">
          <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
                       border-radius:8px; font-weight:700; font-size:13px; color:white; flex-shrink:0;
                       background:${E[c]};">${S[c]}</span>
          <span>${this._escape(d)}</span>
        </button>`}).join("");let m="";if(o){const d=t?.word??(n?e.asks_ahead?"You already knew this!":z[this._index%z.length]:e.asks_ahead?"No worries — you'll hear this later in the story!":q[this._index%q.length]);m=`
        <div style="margin-top:14px; animation: qz-slide-in 0.25s ease-out;">
          <span style="font-size:16px; font-weight:800; color:${n?"#34d399":"#fbbf24"};">${d}</span>
          ${e.explanation?`<span style="font-size:14px; color:#94a3b8; line-height:1.5; margin-left:8px;">${this._escape(e.explanation)}</span>`:""}
        </div>`}const y=this._streak>=3?`<span class="qz-streak" title="${this._streak} in a row" style="margin-right:10px; font-weight:800; color:#fb923c; font-size:14px;">▲ ${this._streak} streak</span>`:"",u=this._index===s-1?"Finish":"Next ›";this.host.innerHTML=`
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
          ${r?`<div style="display:flex; align-items:center; gap:8px; margin-bottom:10px; color:#94a3b8; font-size:13px;">
              <span class="loading loading-ring loading-xs" style="color:#f59e0b;"></span>
              Read the question&hellip; answers unlock in ${Math.ceil(l/1e3)}s</div>`:""}
          <div style="display:flex; flex-direction:column; gap:10px;">${h}</div>
          ${m}
          <div style="display:flex; align-items:center; justify-content:space-between; margin-top:24px;">
            <button data-prev ${this._index===0?"disabled":""}
                    style="background:none; border:none; color:${this._index===0?"#334155":"#cbd5e1"};
                           font-size:15px; cursor:${this._index===0?"default":"pointer"}; padding:8px 12px;">‹ Previous</button>
            <div style="display:flex; gap:6px;">
              ${this._questions.map((d,c)=>{const f=this._answered.has(c),x=f&&this._answered.get(c)===Number(this._questions[c].correct_index);return`<span style="width:8px; height:8px; border-radius:99px; background:${c===this._index?g:f?x?"#10b981":"#64748b":"rgba(255,255,255,0.15)"};"></span>`}).join("")}
            </div>
            <button data-next
                    style="background:${g}; border:none; color:#0f172a; font-weight:700; font-size:15px;
                           padding:8px 20px; border-radius:12px; cursor:pointer; transition:transform 0.1s;"
                    onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">${u}</button>
          </div>
        </div>
      </div>`,this.host.querySelectorAll("[data-opt]").forEach(d=>{d.addEventListener("click",()=>this._answer(Number(d.dataset.opt),d))}),this.host.querySelector("[data-prev]")?.addEventListener("click",()=>{this._index>0&&(this._index--,this._render())}),this.host.querySelector("[data-next]")?.addEventListener("click",()=>{this._index<s-1?(this._index++,this._render()):this._renderScoreScreen()}),t?.kind==="correct"&&this._playCorrectEffects(t)}_answer(t,e){if(this._answered.has(this._index)||(this._gateUntil.get(this._index)??0)>performance.now()+50)return;const s=this._questions[this._index],i=this._display[this._index]||(s.options||[]).map((n,l)=>l),o=i[t]===Number(s.correct_index);this._answered.set(this._index,t);const a=this._openedAt.get(this._index);if(this._responses.push({ms:a!==void 0?Math.round(performance.now()-a):-1,displayIndex:t,snapshot:{question_order:this._index+1,question_text:String(s.question||""),chosen_text:String((s.options||[])[i[t]]??""),correct_text:String((s.options||[])[Number(s.correct_index)]??""),was_correct:o,response_ms:a!==void 0?Math.round(performance.now()-a):null,asks_ahead:!!s.asks_ahead}}),o){this._streak++;const l=10+(this._streak>=3?5:0),r=this._score;this._score+=l;const p=e.getBoundingClientRect();this._render({kind:"correct",gained:l,from:r,at:{x:p.left+p.width/2,y:p.top}})}else s.asks_ahead||(this._streak=0),this._render({kind:"wrong"})}_playCorrectEffects({gained:t,from:e,at:s}){const i=this.host.querySelector("[data-score]"),o=this.host.querySelector("[data-score-value]");if(i&&o){i.classList.add("qz-score-bump");const n=this._score,l=performance.now(),r=p=>{const h=Math.min(1,(p-l)/500);o.textContent=Math.round(e+(n-e)*(1-Math.pow(1-h,3))),h<1&&requestAnimationFrame(r)};requestAnimationFrame(r)}const a=document.createElement("div");a.textContent=`+${t}`,a.style.cssText=`position:fixed; left:${s.x}px; top:${s.y}px; z-index:90; pointer-events:none;
      transform:translateX(-50%); font-weight:900; font-size:26px; color:#fbbf24;
      text-shadow:0 2px 10px rgba(0,0,0,0.6); animation: qz-float-up 0.9s ease-out forwards;`,document.body.appendChild(a),setTimeout(()=>a.remove(),950);for(let n=0;n<10;n++){const l=document.createElement("div"),r=Math.PI*2*n/10+Math.random()*.5,p=46+Math.random()*34,h=["#fbbf24","#34d399","#38bdf8","#f472b6"];l.style.cssText=`position:fixed; left:${s.x}px; top:${s.y}px; z-index:89; pointer-events:none;
        width:8px; height:8px; border-radius:99px; background:${h[n%h.length]};
        --dx:${Math.cos(r)*p}px; --dy:${Math.sin(r)*p-20}px;
        animation: qz-burst 0.65s ease-out forwards;`,document.body.appendChild(l),setTimeout(()=>l.remove(),700)}}_renderScoreScreen(){const t=this._questions.length,e=this._correctCount(),s=t?e/t:0,i=s>=.9?3:s>=.6?2:1,o=[0,1,2].map(u=>`
      <svg viewBox="0 0 24 24" style="width:56px; height:56px;
           fill:${u<i?"#fbbf24":"rgba(255,255,255,0.12)"};
           animation: qz-star-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) ${.15+u*.22}s backwards;">
        <path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
      </svg>`).join(""),a=(()=>{try{return localStorage.getItem("lp_quiz_nickname")||""}catch{return""}})(),n=this._submitUrl?`
      <div data-join style="margin-bottom:22px; animation: qz-slide-in 0.3s ease-out 0.6s backwards;">
        <div style="font-size:13px; letter-spacing:0.12em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px;">
          Join the leaderboard
        </div>
        ${this._hasClassroom?`
        <div style="display:flex; gap:8px; justify-content:center; margin-bottom:8px;">
          <input data-class-code type="text" maxlength="8" placeholder="Class code…" value="${this._escape(this._classCode)}"
                 style="width:130px; padding:10px 14px; border-radius:12px; border:1.5px solid rgba(255,255,255,0.2);
                        background:rgba(255,255,255,0.06); color:white; font-size:15px; outline:none; text-transform:uppercase;" />
        </div>`:""}
        <div style="display:flex; gap:8px; justify-content:center;">
          <input data-nickname type="text" maxlength="24" placeholder="Your name…" value="${this._escape(a)}"
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
          <div style="display:flex; justify-content:center; gap:10px; margin-bottom:18px;">${o}</div>
          <div style="font-size:15px; letter-spacing:0.15em; text-transform:uppercase; color:#94a3b8; margin-bottom:6px;">
            ${e} / ${t} correct
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
      </div>`;const l=this.host.querySelector("[data-final-score]"),r=performance.now(),p=this._score,h=u=>{const d=Math.min(1,(u-r)/900);l.textContent=Math.round(p*(1-Math.pow(1-d,3))),d<1&&requestAnimationFrame(h)};requestAnimationFrame(h),this.host.querySelector("[data-done]").addEventListener("click",()=>{const u=this._onComplete;this.hide(),u?.()});const m=this.host.querySelector("[data-submit]"),y=this.host.querySelector("[data-nickname]"),w=async()=>{const u=this.host.querySelector("[data-class-code]");this._classCode=(u?.value||"").trim().toUpperCase();try{this._classCode&&localStorage.setItem("lp_class_code",this._classCode)}catch{}const d=(y?.value||"").trim(),c=this.host.querySelector("[data-join-error]");if(d.length<2){c&&(c.textContent="Pick a name (at least 2 letters).");return}try{localStorage.setItem("lp_quiz_nickname",d)}catch{}m.disabled=!0,m.textContent="…";try{const f=document.querySelector('meta[name="csrf-token"]')?.content||"",x=await fetch(this._submitUrl,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":f,Accept:"application/json"},body:JSON.stringify({nickname:d,score:this._score,correct:e,total:t,integrity:this._integritySummary(),answers:this._responses.map(_=>_.snapshot).filter(Boolean),class_code:this._classCode||null,member_name:this._classCode?d:null})});if(!x.ok)throw new Error(`HTTP ${x.status}`);const b=await x.json();this._renderLeaderboard(b,d)}catch(f){m.disabled=!1,m.textContent="Submit",c&&(c.textContent=f?.message==="HTTP 422"?"Check the class code — ask your teacher.":"Could not submit — try again.")}};m?.addEventListener("click",w),y?.addEventListener("keydown",u=>{u.key==="Enter"&&w()})}_renderLeaderboard({top:t=[],players:e=0,rank:s=null},i=""){const o=["#fbbf24","#cbd5e1","#d97706"],a=t.map((l,r)=>{const p=s!==null&&r===s-1&&l.nickname===i,h=r<3?`<span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px;
                        border-radius:99px; background:${o[r]}; color:#0f172a; font-weight:900; font-size:13px;">${r+1}</span>`:`<span style="width:26px; text-align:center; color:#64748b; font-weight:700; font-size:13px;">${r+1}</span>`;return`
        <div style="display:flex; align-items:center; gap:12px; padding:9px 14px; border-radius:12px;
                    background:${p?"rgba(245,158,11,0.16)":r%2?"rgba(255,255,255,0.03)":"transparent"};
                    border:1px solid ${p?"rgba(245,158,11,0.55)":"transparent"};
                    animation: qz-slide-in 0.3s ease-out ${.08*r}s backwards;">
          ${h}
          <span style="flex:1; text-align:left; font-weight:${r<3||p?700:500}; font-size:15px;
                       overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            ${this._escape(l.nickname)}${p?" · you":""}
          </span>
          <span style="font-weight:800; color:#fbbf24; font-size:15px;">${l.score}</span>
        </div>`}).join(""),n=s!==null&&s>t.length?`<div style="margin-top:10px; font-size:14px; color:#fbbf24; font-weight:700;">You're #${s} of ${e} — keep climbing!</div>`:"";this.host.innerHTML=`
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); max-height:calc(100vh - 60px); overflow-y:auto;
                    background:${v}; border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:32px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="font-size:13px; letter-spacing:0.2em; text-transform:uppercase; color:${g}; margin-bottom:4px;">Leaderboard</div>
          <div style="font-size:13px; color:#64748b; margin-bottom:18px;">${e} player${e===1?"":"s"}</div>
          <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">${a||'<span style="color:#64748b;">No scores yet — you could be first!</span>'}</div>
          ${n}
          <button data-done
                  style="margin-top:22px; background:${g}; border:none; color:#0f172a; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            Continue ›
          </button>
        </div>
      </div>`,this.host.querySelector("[data-done]").addEventListener("click",()=>{const l=this._onComplete;this.hide(),l?.()})}_escape(t){const e=document.createElement("div");return e.textContent=String(t??""),e.innerHTML}}export{k as QuizOverlay};
