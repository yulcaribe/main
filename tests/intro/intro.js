const planeSvg = `
<svg viewBox="0 0 100 140" aria-hidden="true">
  <path d="M50 2c-5 0-8 9-9 17l-5 35L7 73v9l31-9 4 31-12 11v8l20-6 20 6v-8l-12-11 4-31 31 9v-9L64 54l-5-35C58 11 55 2 50 2Z"/>
  <path class="accent" d="M47 20h6l2 33-5 7-5-7 2-33Z"/>
</svg>`;

let activeAnimation = null;
let timers = [];

function clearTimers(){
  timers.forEach(clearTimeout);
  timers = [];
}

function later(fn,ms){
  const id = setTimeout(fn,ms);
  timers.push(id);
  return id;
}

function injectPlanes(){
  document.querySelectorAll(".aircraft,.gate-plane").forEach(el=>{
    el.innerHTML=planeSvg;
  });
}

function buildLights(){
  const wrap=document.querySelector(".lights");
  if(!wrap) return;
  wrap.innerHTML="";
  for(let i=0;i<9;i++){
    const y=12+i*9.2;
    const spread=8+i*4.4;
    const l=document.createElement("i");
    const r=document.createElement("i");
    l.style.left=(50-spread)+"%";
    r.style.left=(50+spread)+"%";
    l.style.top=y+"%";
    r.style.top=y+"%";
    wrap.append(l,r);
  }
}

const sequences={
  a:[
    [0,"INITIALIZING RUNWAY SYSTEMS"],
    [24,"CENTERLINE LIGHTS ONLINE"],
    [51,"NAVIGATION LINKED"],
    [76,"RUNWAY CLEAR"],
    [100,"CLEARED FOR DEPARTURE"]
  ],
  b:[
    [0,"GROUND SYSTEMS ONLINE"],
    [22,"PUSHBACK"],
    [48,"TAXI"],
    [73,"LINE UP"],
    [100,"TAKEOFF CLEARANCE"]
  ],
  c:[
    [0,"BOARDING COMPLETE"],
    [22,"PUSHBACK"],
    [47,"TAXI"],
    [72,"LINE UP"],
    [100,"DEPARTED"]
  ]
};

function resetUi(){
  const fill=document.querySelector(".fill");
  const text=document.querySelector(".status-text");
  const pct=document.querySelector(".pct");
  const flash=document.querySelector(".flash");
  const boardState=document.querySelector(".board-row .state");
  const scene=document.querySelector(".scene");
  const type=scene?.dataset.type;
  const first=type ? sequences[type][0] : [0,"READY"];

  if(fill) fill.style.width="0%";
  if(text) text.textContent=first[1];
  if(pct) pct.textContent="00%";
  if(boardState && type==="c") boardState.textContent="BOARDING COMPLETE";
  if(flash) flash.classList.remove("on");
}

function playStatus(type){
  const seq=sequences[type];
  const fill=document.querySelector(".fill");
  const text=document.querySelector(".status-text");
  const pct=document.querySelector(".pct");
  const flash=document.querySelector(".flash");
  const boardState=document.querySelector(".board-row .state");

  seq.forEach((item,i)=>{
    later(()=>{
      const [p,label]=item;
      if(fill) fill.style.width=p+"%";
      if(text) text.textContent=label;
      if(pct) pct.textContent=String(p).padStart(2,"0")+"%";
      if(boardState && type==="c") boardState.textContent=label;
      if(i===seq.length-1 && flash){
        later(()=>flash.classList.add("on"),420);
      }
    },i*1050);
  });
}

function animateA(){
  const plane=document.querySelector(".scene-a .aircraft");
  if(!plane) return;
  activeAnimation=plane.animate([
    {transform:"translate3d(-50%,0,0) scale(1.12)",opacity:1,offset:0},
    {transform:"translate3d(-50%,-3vh,0) scale(1.06)",opacity:1,offset:.18},
    {transform:"translate3d(-50%,-17vh,0) scale(.78)",opacity:1,offset:.52},
    {transform:"translate3d(-50%,-31vh,0) scale(.48)",opacity:1,offset:.78},
    {transform:"translate3d(-50%,-46vh,0) scale(.22)",opacity:.1,offset:1}
  ],{
    duration:5000,
    easing:"cubic-bezier(.22,.62,.2,1)",
    fill:"forwards"
  });
}

function animateB(){
  const plane=document.querySelector(".scene-b .moving-plane");
  if(!plane) return;
  activeAnimation=plane.animate([
    {left:"23%",top:"63%",transform:"rotate(68deg) scale(.9)",opacity:1,offset:0},
    {left:"34%",top:"59%",transform:"rotate(68deg) scale(.88)",opacity:1,offset:.27},
    {left:"45%",top:"55%",transform:"rotate(76deg) scale(.84)",opacity:1,offset:.43},
    {left:"53%",top:"51%",transform:"rotate(90deg) scale(.76)",opacity:1,offset:.56},
    {left:"67%",top:"48%",transform:"rotate(90deg) scale(.58)",opacity:1,offset:.78},
    {left:"81%",top:"45%",transform:"rotate(90deg) scale(.38)",opacity:.12,offset:1}
  ],{
    duration:5450,
    easing:"cubic-bezier(.34,.04,.18,1)",
    fill:"forwards"
  });
}

function animateC(){
  const plane=document.querySelector(".scene-c .aircraft");
  if(!plane) return;
  activeAnimation=plane.animate([
    {transform:"translate3d(-50%,0,0) scale(1.08)",opacity:1,offset:0},
    {transform:"translate3d(-50%,0,0) scale(1.08)",opacity:1,offset:.2},
    {transform:"translate3d(-50%,-13vh,0) scale(.83)",opacity:1,offset:.48},
    {transform:"translate3d(-50%,-29vh,0) scale(.48)",opacity:1,offset:.78},
    {transform:"translate3d(-50%,-45vh,0) scale(.2)",opacity:.08,offset:1}
  ],{
    duration:5700,
    easing:"cubic-bezier(.22,.62,.2,1)",
    fill:"forwards"
  });
}

function runScene(){
  const scene=document.querySelector(".scene");
  if(!scene) return;

  clearTimers();
  if(activeAnimation){
    try{activeAnimation.cancel();}catch(e){}
    activeAnimation=null;
  }

  resetUi();

  // Let the browser paint the initial frame first, then start motion.
  requestAnimationFrame(()=>{
    requestAnimationFrame(()=>{
      const type=scene.dataset.type;
      if(type==="a") animateA();
      if(type==="b") animateB();
      if(type==="c") animateC();
      playStatus(type);
    });
  });
}

document.addEventListener("DOMContentLoaded",()=>{
  injectPlanes();
  buildLights();

  const badge=document.createElement("div");
  badge.className="js-motion-badge";
  badge.textContent="MOTION ENGINE V2";
  document.querySelector(".scene")?.appendChild(badge);

  runScene();
  document.querySelector(".replay")?.addEventListener("click",runScene);
});
