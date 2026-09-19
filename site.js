function createAviationLoader(){
  if(document.getElementById("aviation-loader")) return;

  const loader=document.createElement("div");
  loader.id="aviation-loader";
  loader.className="aviation-loader";
  loader.setAttribute("aria-label","Yulcaribe Aviation yükleniyor");
  loader.innerHTML=`
    <div class="loader-grid" aria-hidden="true"></div>
    <div class="loader-center">
      <div class="loader-brand">YULCARIBE <span>AVIATION</span><small>AIRSPACE INTERFACE</small></div>

      <div class="loader-radar" aria-hidden="true">
        <span class="loader-axis-x"></span>
        <span class="loader-axis-y"></span>
        <span class="loader-sweep"></span>
        <span class="loader-plane" id="loader-plane">✈</span>
      </div>

      <div class="loader-runway" aria-hidden="true"></div>

      <div class="loader-status">
        <div class="loader-status-line" id="loader-status-line">INITIALIZING FLIGHT SYSTEMS</div>
        <div class="loader-track"><div class="loader-progress" id="loader-progress"></div></div>
        <div class="loader-meta">
          <span id="loader-percent">00%</span>
          <span>SYS / NAV / ADS-B</span>
          <span id="loader-state">STANDBY</span>
        </div>
      </div>
    </div>
    <div class="loader-flash" id="loader-flash" aria-hidden="true"></div>
  `;

  document.body.prepend(loader);
}

function setLoaderState(percent,label,state){
  const progress=document.getElementById("loader-progress");
  const status=document.getElementById("loader-status-line");
  const percentEl=document.getElementById("loader-percent");
  const stateEl=document.getElementById("loader-state");

  if(progress) progress.style.width=percent+"%";
  if(status) status.textContent=label;
  if(percentEl) percentEl.textContent=String(percent).padStart(2,"0")+"%";
  if(stateEl) stateEl.textContent=state;
}

function delay(ms){
  return new Promise(resolve=>setTimeout(resolve,ms));
}

async function finishLoader(){
  setLoaderState(100,"CLEARED FOR TAKEOFF","ONLINE");

  const plane=document.getElementById("loader-plane");
  const flash=document.getElementById("loader-flash");
  const loader=document.getElementById("aviation-loader");

  await delay(260);
  if(plane) plane.classList.add("is-taking-off");
  await delay(330);
  if(flash) flash.classList.add("active");
  await delay(180);
  if(loader) loader.classList.add("is-leaving");
  await delay(720);
  if(loader) loader.remove();
}

async function loadHome(){
  const root=document.getElementById("site-root");
  createAviationLoader();

  const introSequence=(async()=>{
    setLoaderState(12,"INITIALIZING FLIGHT SYSTEMS","BOOT");
    await delay(360);
    setLoaderState(36,"SYNCING NAVIGATION DATA","NAV");
    await delay(390);
    setLoaderState(61,"LINKING AIRSPACE DATA","ADS-B");
    await delay(390);
    setLoaderState(82,"RADAR SYSTEM ONLINE","RADAR");
  })();

  try{
    const response=await fetch("/main/home.html",{cache:"no-cache"});
    if(!response.ok) throw new Error("HTTP "+response.status);

    const html=await response.text();
    await introSequence;

    setLoaderState(94,"PREPARING AIRSPACE INTERFACE","READY");
    await delay(220);

    root.classList.remove("site-loading");
    root.innerHTML=html;

    const now=new Date();
    const today=document.getElementById("today-label");
    const year=document.getElementById("year-label");

    if(today){
      today.textContent=new Intl.DateTimeFormat("tr-TR",{
        day:"2-digit",
        month:"short",
        year:"numeric"
      }).format(now).toUpperCase();
    }

    if(year) year.textContent=now.getFullYear();

    await finishLoader();
  }catch(error){
    console.error("Ana sayfa yüklenemedi:",error);

    if(root){
      root.classList.remove("site-loading");
      root.innerHTML='<div style="min-height:100vh;display:grid;place-items:center;padding:30px;background:#02050a;color:#8fa6b6;font-family:Manrope,system-ui,sans-serif;text-align:center">Ana sayfa yüklenemedi.</div>';
    }

    setLoaderState(100,"CONNECTION ERROR","OFFLINE");
    await delay(650);

    const loader=document.getElementById("aviation-loader");
    if(loader){
      loader.classList.add("is-leaving");
      setTimeout(()=>loader.remove(),750);
    }
  }
}

loadHome();