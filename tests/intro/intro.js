const planeSvg = `
<svg viewBox="0 0 100 140" aria-hidden="true">
  <path d="M50 2c-5 0-8 9-9 17l-5 35L7 73v9l31-9 4 31-12 11v8l20-6 20 6v-8l-12-11 4-31 31 9v-9L64 54l-5-35C58 11 55 2 50 2Z"/>
  <path class="accent" d="M47 20h6l2 33-5 7-5-7 2-33Z"/>
</svg>`;

function injectPlanes(){
  document.querySelectorAll(".aircraft").forEach(el=>el.innerHTML=planeSvg);
  document.querySelectorAll(".gate-plane").forEach(el=>el.innerHTML=planeSvg);
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
    l.style.left=(50-spread)+"%"; r.style.left=(50+spread)+"%";
    l.style.top=y+"%"; r.style.top=y+"%";
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

function runScene(){
  const scene=document.querySelector(".scene");
  if(!scene) return;
  const type=scene.dataset.type;
  const seq=sequences[type];
  const fill=document.querySelector(".fill");
  const text=document.querySelector(".status-text");
  const pct=document.querySelector(".pct");
  const flash=document.querySelector(".flash");
  const boardState=document.querySelector(".board-row .state");

  scene.classList.remove("play");
  if(flash) flash.classList.remove("on");
  void scene.offsetWidth;
  scene.classList.add("play");

  seq.forEach((item,i)=>{
    setTimeout(()=>{
      const [p,label]=item;
      if(fill) fill.style.width=p+"%";
      if(text) text.textContent=label;
      if(pct) pct.textContent=String(p).padStart(2,"0")+"%";
      if(boardState) boardState.textContent=label;
      if(i===seq.length-1 && flash) setTimeout(()=>flash.classList.add("on"),450);
    },i*1050);
  });
}

document.addEventListener("DOMContentLoaded",()=>{
  injectPlanes();
  buildLights();
  runScene();
  document.querySelector(".replay")?.addEventListener("click",runScene);
});