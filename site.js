function createGatewayLoader(){
  if(document.getElementById("aviation-loader")) return;

  const loader=document.createElement("div");
  loader.id="aviation-loader";
  loader.className="aviation-loader";
  loader.setAttribute("aria-label","Gateway");
  loader.innerHTML='<div class="gateway-word">Gateway</div>';

  document.body.prepend(loader);
}

function delay(ms){
  return new Promise(resolve=>setTimeout(resolve,ms));
}

async function finishLoader(){
  const loader=document.getElementById("aviation-loader");
  if(!loader) return;

  loader.classList.add("is-leaving");
  await delay(560);
  loader.remove();
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
  const sourceEl=document.getElementById("weather-source-line");

  if(!results || !metarEl || !tafEl) return;

  const metar=data?.metar?.raw || "METAR bulunamadı.";
  const taf=data?.taf?.raw || "TAF bulunamadı.";

  metarEl.textContent=metar;
  tafEl.textContent=taf;
  metarEl.classList.toggle("is-empty",!data?.metar?.raw);
  tafEl.classList.toggle("is-empty",!data?.taf?.raw);

  results.hidden=false;

  if(sourceEl){
    const sourceParts=[];

    if(data?.metar?.available){
      sourceParts.push(
        "METAR: "+(data.metar.source || data.source || "—")+
        (data.metar.transport ? " · "+data.metar.transport : "")
      );
    }

    if(data?.taf?.available){
      sourceParts.push(
        "TAF: "+(data.taf.source || data.source || "—")+
        (data.taf.transport ? " · "+data.taf.transport : "")
      );
    }

    sourceEl.textContent=sourceParts.length
      ? "Kaynak · "+sourceParts.join("  /  ")
      : "";
  }

  const found=[];
  if(data?.metar?.raw) found.push("METAR");
  if(data?.taf?.raw) found.push("TAF");

  if(found.length){
    setWeatherFeedback((data?.icao || "")+" · "+found.join(" + "),"success");
  }else{
    setWeatherFeedback("Veri bulunamadı.","error");
  }
}

async function requestAirportWeather(icao){
  const code=String(icao || "").trim().toUpperCase();
  const form=document.getElementById("weather-search-form");
  const submit=form?.querySelector('button[type="submit"]');

  if(!/^[A-Z0-9]{4}$/.test(code)){
    setWeatherFeedback("4 karakterli ICAO kodu gir.","error");
    return;
  }

  if(weatherRequestController) weatherRequestController.abort();
  weatherRequestController=new AbortController();

  if(submit) submit.disabled=true;
  setWeatherFeedback(code+" · yükleniyor","loading");

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
      throw new Error(data?.error || "Veri alınamadı.");
    }

    renderWeatherResult(data);
  }catch(error){
    if(error?.name==="AbortError") return;

    console.error("METAR/TAF sorgusu başarısız:",error);
    setWeatherFeedback(error?.message || "Veri alınamadı.","error");
  }finally{
    if(submit) submit.disabled=false;
  }
}

function initWeatherConsole(){
  const form=document.getElementById("weather-search-form");
  const input=document.getElementById("weather-icao");

  if(!form || !input) return;

  input.addEventListener("input",()=>{
    const clean=input.value
      .toUpperCase()
      .replace(/[^A-Z0-9]/g,"")
      .slice(0,4);

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
      const target=document.getElementById(
        type==="metar" ? "weather-metar" : "weather-taf"
      );

      const value=target?.textContent?.trim();
      if(!value || target?.classList.contains("is-empty")) return;

      try{
        await navigator.clipboard.writeText(value);

        const old=button.textContent;
        button.textContent="Copied";
        setTimeout(()=>{button.textContent=old;},900);
      }catch(error){
        setWeatherFeedback("Kopyalanamadı.","error");
      }
    });
  });
}

async function loadHome(){
  const root=document.getElementById("site-root");

  createGatewayLoader();
  const minimumGatewayTime=delay(700);

  try{
    const response=await fetch("/main/home.html",{cache:"no-cache"});
    if(!response.ok) throw new Error("HTTP "+response.status);

    const html=await response.text();

    if(root){
      root.classList.remove("site-loading");
      root.innerHTML=html;
    }

    const year=document.getElementById("year-label");
    if(year) year.textContent=new Date().getFullYear();

    initWeatherConsole();

    await minimumGatewayTime;
    await finishLoader();
  }catch(error){
    console.error("Ana sayfa yüklenemedi:",error);

    if(root){
      root.classList.remove("site-loading");
      root.innerHTML='<div style="min-height:100vh;display:grid;place-items:center;padding:30px;background:#07090b;color:#7f8990;font-family:Manrope,system-ui,sans-serif;text-align:center">Sayfa yüklenemedi.</div>';
    }

    await minimumGatewayTime;
    await finishLoader();
  }
}

loadHome();
