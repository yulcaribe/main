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

function decodeSignedTemp(value){
  if(!/^M?\d{2}$/.test(value || "")) return null;
  return (value.startsWith("M") ? -1 : 1) * Number(value.replace("M",""));
}

function decodePeriod(value){
  const match=String(value || "").match(/^(\d{2})(\d{2})\/(\d{2})(\d{2})$/);
  if(!match) return value || "";
  return match[1]+" "+match[2]+":00–"+match[3]+" "+match[4]+":00 UTC";
}

function decodeWeatherCode(token){
  let code=String(token || "").toUpperCase();
  if(!code) return null;

  let intensity="";
  if(code.startsWith("-")){ intensity="Light "; code=code.slice(1); }
  else if(code.startsWith("+")){ intensity="Heavy "; code=code.slice(1); }

  let vicinity="";
  if(code.startsWith("VC")){ vicinity="In the vicinity: "; code=code.slice(2); }

  const descriptors={
    MI:"shallow",BC:"patches",PR:"partial",DR:"low drifting",
    BL:"blowing",SH:"showers",TS:"thunderstorm",FZ:"freezing"
  };
  const phenomena={
    DZ:"drizzle",RA:"rain",SN:"snow",SG:"snow grains",IC:"ice crystals",
    PL:"ice pellets",GR:"hail",GS:"small hail",UP:"unknown precipitation",
    BR:"mist",FG:"fog",FU:"smoke",VA:"volcanic ash",DU:"dust",SA:"sand",
    HZ:"haze",PY:"spray",PO:"dust/sand whirls",SQ:"squalls",FC:"funnel cloud",
    SS:"sandstorm",DS:"duststorm"
  };

  let descriptor="";
  const first2=code.slice(0,2);
  if(descriptors[first2]){
    descriptor=descriptors[first2]+" ";
    code=code.slice(2);
  }

  const parts=[];
  for(let i=0;i<code.length;i+=2){
    const p=phenomena[code.slice(i,i+2)];
    if(p) parts.push(p);
  }

  if(!parts.length) return null;
  return intensity+vicinity+descriptor+parts.join(" + ");
}

function decodeCloud(token){
  const code=String(token || "").toUpperCase();
  if(code==="NSC") return "No significant cloud";
  if(code==="NCD") return "No cloud detected";
  if(code==="SKC" || code==="CLR") return "Sky clear";

  const match=code.match(/^(FEW|SCT|BKN|OVC|VV)(\d{3}|\/\/\/)(CB|TCU)?$/);
  if(!match) return null;

  const names={
    FEW:"Few clouds",SCT:"Scattered clouds",BKN:"Broken clouds",
    OVC:"Overcast",VV:"Vertical visibility"
  };

  let text=names[match[1]];
  if(match[2]!== "///"){
    text+=" · "+(Number(match[2])*100).toLocaleString("en-US")+" ft";
  }
  if(match[3]==="CB") text+=" · CB";
  if(match[3]==="TCU") text+=" · TCU";
  return text;
}

function decodeConditions(tokens){
  const rows=[];
  const clouds=[];
  const weather=[];
  let windVariation=null;

  for(const rawToken of tokens){
    const token=String(rawToken || "").toUpperCase();

    let m=token.match(/^(\d{3}|VRB)(\d{2,3})(G\d{2,3})?KT$/);
    if(m){
      const speed=Number(m[2]);
      if(m[1]==="000" && speed===0){
        rows.push(["Wind","Calm"]);
      }else{
        let value=(m[1]==="VRB" ? "Variable direction" : Number(m[1])+"°")+" · "+speed+" kt";
        if(m[3]) value+=" · gust "+Number(m[3].slice(1))+" kt";
        rows.push(["Wind",value]);
      }
      continue;
    }

    m=token.match(/^(\d{3})V(\d{3})$/);
    if(m){
      windVariation=Number(m[1])+"°–"+Number(m[2])+"°";
      continue;
    }

    if(token==="CAVOK"){
      rows.push(["Visibility","10 km or more · no significant weather/cloud"]);
      continue;
    }

    if(token==="9999"){
      rows.push(["Visibility","10 km or more"]);
      continue;
    }

    if(/^\d{4}$/.test(token)){
      rows.push(["Visibility",Number(token).toLocaleString("en-US")+" m"]);
      continue;
    }

    m=token.match(/^(P?)(\d+(?:\/\d+)?)SM$/);
    if(m){
      rows.push(["Visibility",(m[1] ? "More than " : "")+m[2]+" statute miles"]);
      continue;
    }

    const cloud=decodeCloud(token);
    if(cloud){
      clouds.push(cloud);
      continue;
    }

    if(token==="NSW"){
      weather.push("No significant weather");
      continue;
    }

    const wx=decodeWeatherCode(token);
    if(wx){
      weather.push(wx);
      continue;
    }

    m=token.match(/^(M?\d{2})\/(M?\d{2})$/);
    if(m){
      const temp=decodeSignedTemp(m[1]);
      const dew=decodeSignedTemp(m[2]);
      rows.push(["Temperature",temp+" °C"]);
      rows.push(["Dew point",dew+" °C"]);
      continue;
    }

    m=token.match(/^Q(\d{4})$/);
    if(m){
      rows.push(["QNH",Number(m[1])+" hPa"]);
      continue;
    }

    m=token.match(/^A(\d{4})$/);
    if(m){
      rows.push(["Altimeter",(Number(m[1])/100).toFixed(2)+" inHg"]);
      continue;
    }

    if(token==="NOSIG"){
      rows.push(["Trend","No significant change expected"]);
    }
  }

  if(windVariation){
    const wind=rows.find(row=>row[0]==="Wind");
    if(wind) wind[1]+=" · varying "+windVariation;
    else rows.push(["Wind direction",windVariation]);
  }

  if(weather.length) rows.push(["Weather",weather.join(" · ")]);
  if(clouds.length) rows.push(["Cloud",clouds.join(" / ")]);

  return rows;
}

function renderDecoded(container,sections){
  if(!container) return;

  if(!sections?.length){
    container.hidden=true;
    container.innerHTML="";
    return;
  }

  container.hidden=false;
  container.innerHTML="";

  const title=document.createElement("div");
  title.className="weather-decode-title";
  title.textContent="Decoded";
  container.appendChild(title);

  for(const section of sections){
    const block=document.createElement("div");
    block.className="weather-decode-block";

    if(section.title){
      const heading=document.createElement("div");
      heading.className="weather-decode-heading";
      heading.textContent=section.title;
      block.appendChild(heading);
    }

    for(const [label,value] of section.rows || []){
      const row=document.createElement("div");
      row.className="weather-decode-row";

      const key=document.createElement("span");
      key.textContent=label;

      const val=document.createElement("strong");
      val.textContent=value;

      row.append(key,val);
      block.appendChild(row);
    }

    if((section.rows || []).length) container.appendChild(block);
  }

  if(container.children.length===1){
    container.hidden=true;
  }
}

function decodeMetar(raw){
  const clean=String(raw || "").replace(/\s+/g," ").trim();
  if(!clean) return [];

  const tokens=clean.split(" ");
  const rows=[];

  const stationIndex=tokens.findIndex(token=>/^[A-Z]{4}$/.test(token));
  if(stationIndex>=0) rows.push(["Station",tokens[stationIndex]]);

  const time=tokens.find(token=>/^\d{6}Z$/.test(token));
  if(time){
    rows.push([
      "Observation",
      time.slice(0,2)+" "+time.slice(2,4)+":"+time.slice(4,6)+" UTC"
    ]);
  }

  rows.push(...decodeConditions(tokens));

  return [{title:"METAR",rows}];
}

function decodeTaf(raw){
  const clean=String(raw || "").replace(/\s+/g," ").trim();
  if(!clean) return [];

  const tokens=clean.split(" ");
  const sections=[];
  const headerRows=[];

  const stationIndex=tokens.findIndex(token=>/^[A-Z]{4}$/.test(token));
  if(stationIndex>=0) headerRows.push(["Station",tokens[stationIndex]]);

  const issueIndex=tokens.findIndex(token=>/^\d{6}Z$/.test(token));
  if(issueIndex>=0){
    const issue=tokens[issueIndex];
    headerRows.push([
      "Issued",
      issue.slice(0,2)+" "+issue.slice(2,4)+":"+issue.slice(4,6)+" UTC"
    ]);
  }

  const validityIndex=tokens.findIndex(token=>/^\d{4}\/\d{4}$/.test(token));
  if(validityIndex>=0){
    headerRows.push(["Validity",decodePeriod(tokens[validityIndex])]);
  }

  const start=Math.max(validityIndex+1,0);
  let current={title:"Initial conditions",tokens:[]};
  const groups=[];

  function pushCurrent(){
    if(current.tokens.length || !groups.length) groups.push(current);
  }

  for(let i=start;i<tokens.length;i++){
    const token=tokens[i];

    if(token==="BECMG" || token==="TEMPO" || /^FM\d{6}$/.test(token) || /^PROB(?:30|40)$/.test(token)){
      pushCurrent();

      if(token==="BECMG" || token==="TEMPO"){
        const range=/^\d{4}\/\d{4}$/.test(tokens[i+1] || "") ? tokens[++i] : "";
        current={
          title:(token==="BECMG" ? "BECMG · Becoming" : "TEMPO · Temporary")+
            (range ? " · "+decodePeriod(range) : ""),
          tokens:[]
        };
      }else if(/^FM\d{6}$/.test(token)){
        const time=token.slice(2);
        current={
          title:"FM · From "+time.slice(0,2)+" "+time.slice(2,4)+":"+time.slice(4,6)+" UTC",
          tokens:[]
        };
      }else{
        let title=token.replace("PROB","")+"% probability";
        if(tokens[i+1]==="TEMPO"){
          title+=" · TEMPO";
          i++;
        }
        const range=/^\d{4}\/\d{4}$/.test(tokens[i+1] || "") ? tokens[++i] : "";
        if(range) title+=" · "+decodePeriod(range);
        current={title,tokens:[]};
      }
    }else{
      current.tokens.push(token);
    }
  }

  pushCurrent();

  if(headerRows.length) sections.push({title:"TAF",rows:headerRows});

  for(const group of groups){
    const rows=decodeConditions(group.tokens);
    if(rows.length) sections.push({title:group.title,rows});
  }

  return sections;
}

function renderWeatherResult(data){
  const results=document.getElementById("weather-results");
  const metarEl=document.getElementById("weather-metar");
  const tafEl=document.getElementById("weather-taf");
  const sourceEl=document.getElementById("weather-source-line");
  const metarDecodeEl=document.getElementById("weather-metar-decode");
  const tafDecodeEl=document.getElementById("weather-taf-decode");

  if(!results || !metarEl || !tafEl) return;

  const metar=data?.metar?.raw || "METAR bulunamadı.";
  const taf=data?.taf?.raw || "TAF bulunamadı.";

  metarEl.textContent=metar;
  tafEl.textContent=taf;
  metarEl.classList.toggle("is-empty",!data?.metar?.raw);
  tafEl.classList.toggle("is-empty",!data?.taf?.raw);

  renderDecoded(
    metarDecodeEl,
    data?.metar?.raw ? decodeMetar(data.metar.raw) : []
  );
  renderDecoded(
    tafDecodeEl,
    data?.taf?.raw ? decodeTaf(data.taf.raw) : []
  );

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
      const details=[];

      const addAttempts=(label, attempts)=>{
        if(!Array.isArray(attempts)) return;
        attempts.forEach(item=>{
          details.push(
            label+" "+(item?.transport || "?")+" "+
            (item?.status ? "HTTP "+item.status : "NO STATUS")+
            (item?.error ? " · "+item.error : "")
          );
        });
      };

      addAttempts("METAR", data?.metarAttempts);
      addAttempts("TAF", data?.tafAttempts);

      throw new Error(
        (data?.error || "Veri alınamadı.")+
        (details.length ? " | "+details.join(" | ") : "")
      );
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
