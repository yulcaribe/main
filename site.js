function createAviationLoader(){
  if(document.getElementById("aviation-loader")) return;

  const loader=document.createElement("div");
  loader.id="aviation-loader";
  loader.className="aviation-loader";
  loader.setAttribute("aria-label","YulCaribe yükleniyor");

  loader.innerHTML=`
    <div class="loader-bg-grid" aria-hidden="true"></div>

    <div class="loader-shell">
      <div class="loader-brand">
        YULCARIBE
        <small>RUNWAY SYSTEM / LIVE INTERFACE</small>
      </div>

      <div class="loader-scene" aria-hidden="true">
        <div class="loader-horizon"></div>

        <div class="loader-runway">
          <div class="loader-runway-surface"></div>
          <div class="loader-runway-edge edge-left"></div>
          <div class="loader-runway-edge edge-right"></div>
          <div class="loader-centerline" id="loader-centerline"></div>
          <div class="loader-threshold threshold-left"></div>
          <div class="loader-threshold threshold-right"></div>
        </div>

        <div class="loader-lights" id="loader-lights"></div>

        <div class="loader-plane" id="loader-plane">
          <img src="/main/assets/yulcaribe-aircraft.svg" alt="">
        </div>
      </div>

      <div class="loader-status">
        <div class="loader-status-line" id="loader-status-line">INITIALIZING RUNWAY SYSTEMS</div>
        <div class="loader-track"><div class="loader-progress" id="loader-progress"></div></div>
        <div class="loader-meta">
          <span id="loader-percent">00%</span>
          <span>RWY / NAV / LIGHTS</span>
          <span id="loader-state">BOOT</span>
        </div>
      </div>
    </div>

    <div class="loader-flash" id="loader-flash" aria-hidden="true"></div>
  `;

  document.body.prepend(loader);
  buildRunwayPerspective();
}

function buildRunwayPerspective(){
  const lights=document.getElementById("loader-lights");
  const centerline=document.getElementById("loader-centerline");
  if(!lights || !centerline) return;

  lights.innerHTML="";
  centerline.innerHTML="";

  for(let i=0;i<12;i++){
    const progress=i/11;
    const top=10 + progress*80;
    const spread=4.8 + Math.pow(progress,1.28)*42;

    const left=document.createElement("i");
    const right=document.createElement("i");

    left.className="runway-light";
    right.className="runway-light";

    left.style.top=top+"%";
    right.style.top=top+"%";
    left.style.left=(50-spread)+"%";
    right.style.left=(50+spread)+"%";

    const size=2.5 + progress*4.2;
    left.style.width=size+"px";
    left.style.height=size+"px";
    right.style.width=size+"px";
    right.style.height=size+"px";

    left.style.animationDelay=(i*45)+"ms";
    right.style.animationDelay=(i*45)+"ms";

    lights.append(left,right);
  }

  for(let i=0;i<10;i++){
    const progress=i/9;
    const dash=document.createElement("i");
    dash.style.top=(8 + progress*79)+"%";
    dash.style.width=(2.5 + progress*9)+"px";
    dash.style.height=(7 + progress*19)+"px";
    dash.style.opacity=String(.52 + progress*.45);
    centerline.appendChild(dash);
  }
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

function startPlaneRun(){
  const plane=document.getElementById("loader-plane");
  if(!plane || typeof plane.animate!=="function") return null;

  return plane.animate([
    {transform:"translate3d(-50%,0,0) scale(1.08)",opacity:1,offset:0},
    {transform:"translate3d(-50%,-5vh,0) scale(.98)",opacity:1,offset:.22},
    {transform:"translate3d(-50%,-17vh,0) scale(.72)",opacity:1,offset:.52},
    {transform:"translate3d(-50%,-30vh,0) scale(.44)",opacity:1,offset:.78},
    {transform:"translate3d(-50%,-43vh,0) scale(.22)",opacity:.12,offset:1}
  ],{
    duration:2450,
    easing:"cubic-bezier(.22,.66,.2,1)",
    fill:"forwards"
  });
}

async function finishLoader(planeAnimation){
  setLoaderState(100,"CLEARED FOR DEPARTURE","ONLINE");

  const flash=document.getElementById("loader-flash");
  const loader=document.getElementById("aviation-loader");

  if(planeAnimation?.finished){
    try{ await planeAnimation.finished; }catch(e){}
  }else{
    await delay(420);
  }

  if(flash) flash.classList.add("active");
  await delay(230);
  if(loader) loader.classList.add("is-leaving");
  await delay(720);
  if(loader) loader.remove();
}

let weatherRequestController=null;

function setWeatherFeedback(message,state="idle"){
  const feedback=document.getElementById("weather-feedback");
  if(!feedback) return;
  feedback.classList.remove("is-loading","is-success","is-error");
  if(state!=="idle") feedback.classList.add("is-"+state);
  const text=feedback.querySelector("span:last-child");
  if(text) text.textContent=message;
}

function formatWeatherUtc(value){
  if(!value) return "--:-- UTC";
  const date=new Date(value);
  if(Number.isNaN(date.getTime())) return "--:-- UTC";
  return String(date.getUTCHours()).padStart(2,"0")+":"+
    String(date.getUTCMinutes()).padStart(2,"0")+" UTC";
}

function renderWeatherResult(data){
  const results=document.getElementById("weather-results");
  const metarEl=document.getElementById("weather-metar");
  const tafEl=document.getElementById("weather-taf");
  const stationEl=document.getElementById("weather-station");
  const updatedEl=document.getElementById("weather-updated");
  if(!results || !metarEl || !tafEl) return;

  const metar=data?.metar?.raw || "Bu meydan için güncel METAR bulunamadı.";
  const taf=data?.taf?.raw || "Bu meydan için güncel TAF bulunamadı.";

  metarEl.textContent=metar;
  tafEl.textContent=taf;
  metarEl.classList.toggle("is-empty",!data?.metar?.raw);
  tafEl.classList.toggle("is-empty",!data?.taf?.raw);

  if(stationEl) stationEl.textContent=data?.icao || "----";
  if(updatedEl) updatedEl.textContent=formatWeatherUtc(data?.fetchedAt);

  results.hidden=false;

  const found=[];
  if(data?.metar?.raw) found.push("METAR");
  if(data?.taf?.raw) found.push("TAF");

  if(found.length){
    const cacheText=data?.cache?.hit ? " · CACHE "+data.cache.ageSeconds+"s" : "";
    setWeatherFeedback((data.icao || "MEYDAN")+" · "+found.join(" + ")+" ALINDI"+cacheText,"success");
  }else{
    setWeatherFeedback((data?.icao || "Bu meydan")+" için güncel METAR/TAF bulunamadı.","error");
  }
}

async function requestAirportWeather(icao){
  const code=String(icao || "").trim().toUpperCase();
  const form=document.getElementById("weather-search-form");
  const submit=form?.querySelector('button[type="submit"]');

  if(!/^[A-Z0-9]{4}$/.test(code)){
    setWeatherFeedback("4 karakterli ICAO kodu gir. Örnek: LTAI","error");
    return;
  }

  if(weatherRequestController) weatherRequestController.abort();
  weatherRequestController=new AbortController();

  if(submit) submit.disabled=true;
  setWeatherFeedback(code+" · METAR / TAF sorgulanıyor…","loading");

  try{
    const response=await fetch(
      "/main/api/weather.php?icao="+encodeURIComponent(code),
      {
        cache:"no-store",
        signal:weatherRequestController.signal,
        headers:{"Accept":"application/json"}
      }
    );

    let data=null;
    try{ data=await response.json(); }catch(e){}

    if(!response.ok || !data?.ok){
      throw new Error(data?.error || "Hava durumu verisi alınamadı.");
    }

    renderWeatherResult(data);
  }catch(error){
    if(error?.name==="AbortError") return;
    console.error("METAR/TAF sorgusu başarısız:",error);
    setWeatherFeedback(error?.message || "METAR/TAF sorgusu başarısız oldu.","error");
  }finally{
    if(submit) submit.disabled=false;
  }
}

function initWeatherConsole(){
  const form=document.getElementById("weather-search-form");
  const input=document.getElementById("weather-icao");
  if(!form || !input) return;

  input.addEventListener("input",()=>{
    const clean=input.value.toUpperCase().replace(/[^A-Z0-9]/g,"").slice(0,4);
    if(input.value!==clean) input.value=clean;
  });

  form.addEventListener("submit",event=>{
    event.preventDefault();
    requestAirportWeather(input.value);
  });

  document.querySelectorAll("[data-weather-icao]").forEach(button=>{
    button.addEventListener("click",()=>{
      input.value=button.dataset.weatherIcao || "";
      requestAirportWeather(input.value);
    });
  });

  document.querySelectorAll("[data-copy-weather]").forEach(button=>{
    button.addEventListener("click",async()=>{
      const type=button.dataset.copyWeather;
      const target=document.getElementById(type==="metar" ? "weather-metar" : "weather-taf");
      const value=target?.textContent?.trim();
      if(!value || target?.classList.contains("is-empty")) return;

      try{
        await navigator.clipboard.writeText(value);
        const old=button.textContent;
        button.textContent="COPIED";
        setTimeout(()=>{button.textContent=old;},900);
      }catch(error){
        setWeatherFeedback("Kopyalama izni alınamadı.","error");
      }
    });
  });
}

async function loadHome(){
  const root=document.getElementById("site-root");
  createAviationLoader();

  await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
  const planeAnimation=startPlaneRun();

  const introSequence=(async()=>{
    setLoaderState(12,"INITIALIZING RUNWAY SYSTEMS","BOOT");
    await delay(420);
    setLoaderState(34,"CENTERLINE LIGHTS ONLINE","RWY");
    await delay(430);
    setLoaderState(58,"SYNCING NAVIGATION DATA","NAV");
    await delay(430);
    setLoaderState(81,"RUNWAY CLEAR","READY");
  })();

  try{
    const response=await fetch("/main/home.html",{cache:"no-cache"});
    if(!response.ok) throw new Error("HTTP "+response.status);

    const html=await response.text();
    await introSequence;

    setLoaderState(94,"PREPARING INTERFACE","LIVE");
    await delay(260);

    if(root){
      root.classList.remove("site-loading");
      root.innerHTML=html;
    }

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

    initWeatherConsole();

    await finishLoader(planeAnimation);
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