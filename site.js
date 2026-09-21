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
let currentWeatherLanguage="tr";
let lastWeatherData=null;

let airportTimezoneData={exact:{},prefix:{}};

let weatherInterpretationData={locales:null,rules:null};

async function loadWeatherInterpretationData(){
  try{
    const [localesResponse,rulesResponse]=await Promise.all([
      fetch("/main/assets/data/weather-locales.json",{cache:"force-cache"}),
      fetch("/main/assets/data/weather-rules.json",{cache:"force-cache"})
    ]);

    weatherInterpretationData={
      locales:localesResponse.ok ? await localesResponse.json() : null,
      rules:rulesResponse.ok ? await rulesResponse.json() : null
    };
  }catch(error){
    console.warn("Weather interpretation verisi yüklenemedi:",error);
  }
}

function getWeatherLocale(language="tr"){
  const locales=weatherInterpretationData.locales;
  if(!locales) return null;

  const selected=locales.supportedLanguages?.includes(language)
    ? language
    : (locales.defaultLanguage || "tr");

  return locales[selected] || null;
}

function fillWeatherTemplate(template,values={}){
  return String(template || "").replace(/\{([a-zA-Z0-9_]+)\}/g,(match,key)=>{
    return values[key] ?? match;
  });
}

function getStoredWeatherLanguage(){
  const supported=weatherInterpretationData.locales?.supportedLanguages || ["tr","en"];
  let stored=null;

  try{ stored=localStorage.getItem("yulcaribe-weather-language"); }catch(error){}

  return supported.includes(stored)
    ? stored
    : (weatherInterpretationData.locales?.defaultLanguage || "tr");
}

function setWeatherLanguage(language){
  const supported=weatherInterpretationData.locales?.supportedLanguages || ["tr","en"];
  currentWeatherLanguage=supported.includes(language) ? language : "tr";

  try{ localStorage.setItem("yulcaribe-weather-language",currentWeatherLanguage); }catch(error){}

  document.querySelectorAll("[data-weather-language]").forEach(button=>{
    const active=button.dataset.weatherLanguage===currentWeatherLanguage;
    button.setAttribute("aria-pressed",active ? "true" : "false");
  });

  if(lastWeatherData) renderWeatherInterpretation(lastWeatherData);
}

function parseInterpretationWind(token){
  const match=String(token || "").toUpperCase().match(/^(\d{3}|VRB)(\d{2,3})(G(\d{2,3}))?KT$/);
  if(!match) return null;

  return {
    direction:match[1],
    speed:Number(match[2]),
    gust:match[4] ? Number(match[4]) : null
  };
}

function parseInterpretationVisibility(token){
  const code=String(token || "").toUpperCase();
  if(code==="CAVOK" || code==="9999") return 10000;
  if(/^\d{4}$/.test(code)) return Number(code);
  return null;
}

function weatherTermFromToken(token,locale){
  const code=String(token || "").toUpperCase()
    .replace(/^[+-]/,"")
    .replace(/^VC/,"");

  const ordered=["TSRA","SHRA","FZRA","TS","SN","FG","BR","RA","DZ"];
  const key=ordered.find(item=>code.includes(item));
  return key ? (locale?.terms?.[key] || key) : null;
}

function formatInterpretationClock(date,timeZone){
  if(!(date instanceof Date) || Number.isNaN(date.getTime())) return null;

  if(!timeZone){
    return String(date.getUTCDate()).padStart(2,"0")+" "+
      String(date.getUTCHours()).padStart(2,"0")+":"+
      String(date.getUTCMinutes()).padStart(2,"0")+" UTC";
  }

  try{
    const parts=new Intl.DateTimeFormat("en-GB",{
      timeZone,
      day:"2-digit",
      hour:"2-digit",
      minute:"2-digit",
      hour12:false
    }).formatToParts(date);
    const get=type=>parts.find(part=>part.type===type)?.value || "";
    return get("day")+" "+get("hour")+":"+get("minute");
  }catch(error){
    return null;
  }
}

function formatInterpretationDate(date,timeZone="UTC"){
  if(!(date instanceof Date) || Number.isNaN(date.getTime())) return "";

  try{
    const parts=new Intl.DateTimeFormat("en-GB",{
      timeZone:timeZone || "UTC",
      day:"2-digit",
      month:"2-digit",
      year:"numeric"
    }).formatToParts(date);

    const get=type=>parts.find(part=>part.type===type)?.value || "";
    return get("day")+"."+get("month")+"."+get("year");
  }catch(error){
    return "";
  }
}

function formatInterpretationTime(date,timeZone="UTC"){
  if(!(date instanceof Date) || Number.isNaN(date.getTime())) return "";

  try{
    const parts=new Intl.DateTimeFormat("en-GB",{
      timeZone:timeZone || "UTC",
      hour:"2-digit",
      minute:"2-digit",
      hour12:false
    }).formatToParts(date);

    const get=type=>parts.find(part=>part.type===type)?.value || "";
    return get("hour")+"."+get("minute");
  }catch(error){
    return "";
  }
}

function interpretationRangeParts(range,timeZone,reference){
  const match=String(range || "").match(/^(\d{2})(\d{2})\/(\d{2})(\d{2})$/);
  if(!match){
    return {
      utcStartDate:"",utcStartTime:"",
      utcEndDate:"",utcEndTime:"",
      localStartDate:"",localStartTime:"",
      localEndDate:"",localEndTime:""
    };
  }

  const startDate=resolveUtcDate(match[1],match[2],0,reference);
  const endDate=resolveUtcDate(match[3],match[4],0,startDate || reference);

  return {
    utcStartDate:formatInterpretationDate(startDate,"UTC"),
    utcStartTime:formatInterpretationTime(startDate,"UTC"),
    utcEndDate:formatInterpretationDate(endDate,"UTC"),
    utcEndTime:formatInterpretationTime(endDate,"UTC"),
    localStartDate:formatInterpretationDate(startDate,timeZone || "UTC"),
    localStartTime:formatInterpretationTime(startDate,timeZone || "UTC"),
    localEndDate:formatInterpretationDate(endDate,timeZone || "UTC"),
    localEndTime:formatInterpretationTime(endDate,timeZone || "UTC")
  };
}

function parseTafInterpretationGroups(raw){
  const clean=String(raw || "").replace(/\s+/g," ").trim();
  if(!clean) return {station:"",issueDate:null,groups:[]};

  const tokens=clean.split(" ");
  const station=tokens.find(token=>/^[A-Z]{4}$/.test(token)) || "";
  const issue=tokens.find(token=>/^\d{6}Z$/.test(token)) || "";
  const issueDate=issue
    ? resolveUtcDate(issue.slice(0,2),issue.slice(2,4),issue.slice(4,6))
    : new Date();

  const validityIndex=tokens.findIndex(token=>/^\d{4}\/\d{4}$/.test(token));
  const start=Math.max(validityIndex+1,0);
  const groups=[];
  let current={type:"INITIAL",range:"",probability:null,temporary:false,tokens:[]};

  const pushCurrent=()=>{
    if(current.tokens.length || !groups.length) groups.push(current);
  };

  for(let i=start;i<tokens.length;i++){
    const token=tokens[i];

    if(token==="BECMG" || token==="TEMPO" || /^FM\d{6}$/.test(token) || /^PROB(?:30|40)$/.test(token)){
      pushCurrent();

      if(token==="BECMG" || token==="TEMPO"){
        const range=/^\d{4}\/\d{4}$/.test(tokens[i+1] || "") ? tokens[++i] : "";
        current={
          type:token,
          range,
          probability:null,
          temporary:token==="TEMPO",
          tokens:[]
        };
      }else if(/^FM\d{6}$/.test(token)){
        const value=token.slice(2);
        current={
          type:"FM",
          range:value.slice(0,4)+"/"+value.slice(0,4),
          from:value,
          probability:null,
          temporary:false,
          tokens:[]
        };
      }else{
        const probability=Number(token.slice(4));
        let temporary=false;
        if(tokens[i+1]==="TEMPO"){
          temporary=true;
          i++;
        }
        const range=/^\d{4}\/\d{4}$/.test(tokens[i+1] || "") ? tokens[++i] : "";
        current={
          type:"PROB",
          range,
          probability,
          temporary,
          tokens:[]
        };
      }
    }else{
      current.tokens.push(token);
    }
  }

  pushCurrent();
  return {station,issueDate,groups};
}

function buildWeatherInterpretation(data,language){
  const locale=getWeatherLocale(language);
  if(!locale) return null;

  const rules=weatherInterpretationData.rules || {};
  const current=[];
  const forecast=[];

  const metarRaw=data?.metar?.raw || "";
  const metarTokens=metarRaw ? metarRaw.replace(/\s+/g," ").trim().split(" ") : [];

  if(metarTokens.includes("CAVOK")){
    current.push({text:locale.templates.currentCavok,attention:false});
  }

  const metarWind=metarTokens.map(parseInterpretationWind).find(Boolean);
  if(metarWind){
    const template=metarWind.direction==="VRB"
      ? locale.templates.currentVariableWind
      : locale.templates.currentWind;

    current.push({
      text:fillWeatherTemplate(template,{
        direction:metarWind.direction==="VRB" ? "VRB" : Number(metarWind.direction)+"°",
        speed:metarWind.speed
      }),
      attention:metarWind.speed >= (rules.wind?.cautionAtKt ?? 20)
    });

    if(metarWind.gust){
      current.push({
        text:fillWeatherTemplate(locale.templates.gust,{gust:metarWind.gust}),
        attention:metarWind.gust >= (rules.wind?.gustCautionAtKt ?? 25)
      });
    }
  }

  const metarVisibility=metarTokens.map(parseInterpretationVisibility).find(value=>value!==null);
  if(metarVisibility!==undefined && metarVisibility!==null && metarVisibility<10000){
    const low=metarVisibility < (rules.visibility?.cautionBelowMeters ?? 5000);
    current.push({
      text:fillWeatherTemplate(
        low ? locale.templates.currentLowVisibility : locale.templates.currentVisibility,
        {visibility:metarVisibility.toLocaleString(language==="tr" ? "tr-TR" : "en-US")+" m"}
      ),
      attention:low
    });
  }

  const metarWeatherToken=metarTokens.find(token=>weatherTermFromToken(token,locale));
  if(metarWeatherToken){
    const weather=weatherTermFromToken(metarWeatherToken,locale);
    const raw=metarWeatherToken.toUpperCase();
    current.push({
      text:fillWeatherTemplate(locale.templates.currentWeather,{weather}),
      attention:/TS|FZRA|FG|SN|SQ|FC/.test(raw)
    });
  }

  for(const token of metarTokens){
    const cloud=String(token).toUpperCase().match(/^(FEW|SCT|BKN|OVC)(\d{3})(CB|TCU)$/);
    if(!cloud) continue;
    const height=Number(cloud[2])*100;
    current.push({
      text:fillWeatherTemplate(
        cloud[3]==="CB" ? locale.templates.currentCb : locale.templates.currentTcu,
        {height:height.toLocaleString(language==="tr" ? "tr-TR" : "en-US")}
      ),
      attention:true
    });
  }

  const taf=parseTafInterpretationGroups(data?.taf?.raw || "");
  const timeZone=getAirportTimezone(taf.station || data?.icao);
  let prevailingWind=taf.groups[0]?.tokens?.map(parseInterpretationWind).find(Boolean) || null;

  for(let index=1;index<taf.groups.length;index++){
    const group=taf.groups[index];
    let range=interpretationRangeParts(group.range,timeZone,taf.issueDate);

    if(group.type==="FM" && group.from){
      const fromDate=resolveUtcDate(
        group.from.slice(0,2),
        group.from.slice(2,4),
        group.from.slice(4,6),
        taf.issueDate
      );
      range={
        utcStartDate:formatInterpretationDate(fromDate,"UTC"),
        utcStartTime:formatInterpretationTime(fromDate,"UTC"),
        utcEndDate:formatInterpretationDate(fromDate,"UTC"),
        utcEndTime:formatInterpretationTime(fromDate,"UTC"),
        localStartDate:formatInterpretationDate(fromDate,timeZone || "UTC"),
        localStartTime:formatInterpretationTime(fromDate,timeZone || "UTC"),
        localEndDate:formatInterpretationDate(fromDate,timeZone || "UTC"),
        localEndTime:formatInterpretationTime(fromDate,timeZone || "UTC")
      };
    }

    const wind=group.tokens.map(parseInterpretationWind).find(Boolean);
    if(wind){
      let template;
      const values={
        ...range,
        station:taf.station || data?.icao || "Airport",
        direction:wind.direction==="VRB" ? "VRB" : Number(wind.direction)+"°",
        speed:wind.speed,
        from:prevailingWind?.direction==="VRB" ? "VRB" : (prevailingWind ? Number(prevailingWind.direction)+"°" : ""),
        to:wind.direction==="VRB" ? "VRB" : Number(wind.direction)+"°"
      };

      if(wind.direction==="VRB"){
        template=locale.templates.variableWindChange;
      }else if(prevailingWind && prevailingWind.direction!=="VRB" && prevailingWind.direction!==wind.direction){
        template=locale.templates.windShift;
      }else{
        template=locale.templates.windChange;
      }

      forecast.push({
        text:fillWeatherTemplate(template,values),
        attention:wind.speed >= (rules.wind?.cautionAtKt ?? 20)
      });

      if(wind.gust){
        forecast.push({
          text:fillWeatherTemplate(locale.templates.forecastGust || locale.templates.gust,{
            ...range,
            station:taf.station || data?.icao || "Airport",
            gust:wind.gust
          }),
          attention:wind.gust >= (rules.wind?.gustCautionAtKt ?? 25)
        });
      }

      if(group.type==="BECMG" || group.type==="FM") prevailingWind=wind;
    }

    if(group.tokens.includes("CAVOK")){
      forecast.push({
        text:fillWeatherTemplate(locale.templates.forecastCavok,{
          ...range,
          station:taf.station || data?.icao || "Airport"
        }),
        attention:false
      });
    }

    const weatherToken=group.tokens.find(token=>weatherTermFromToken(token,locale));
    if(weatherToken){
      const weather=weatherTermFromToken(weatherToken,locale);
      const values={
        ...range,
        station:taf.station || data?.icao || "Airport",
        weather,
        probability:group.probability
      };

      let template=locale.templates.weather;
      if(group.probability) template=locale.templates.probabilityWeather;
      else if(group.temporary) template=locale.templates.temporaryWeather;

      forecast.push({
        text:fillWeatherTemplate(template,values),
        attention:/TS|FZRA|FG|SN|SQ|FC/.test(weatherToken.toUpperCase())
      });
    }

    for(const token of group.tokens){
      const cloud=String(token).toUpperCase().match(/^(FEW|SCT|BKN|OVC)(\d{3})(CB|TCU)?$/);
      if(!cloud) continue;

      const height=Number(cloud[2])*100;
      if(cloud[3]==="CB" || cloud[3]==="TCU"){
        forecast.push({
          text:fillWeatherTemplate(
            cloud[3]==="CB" ? locale.templates.cb : locale.templates.tcu,
            {...range,station:taf.station || data?.icao || "Airport",height:height.toLocaleString(language==="tr" ? "tr-TR" : "en-US")}
          ),
          attention:true
        });
      }else if((cloud[1]==="BKN" || cloud[1]==="OVC") && height < (rules.ceiling?.cautionBelowFeet ?? 3000)){
        forecast.push({
          text:fillWeatherTemplate(locale.templates.lowCeiling,{
            ...range,
            station:taf.station || data?.icao || "Airport",
            height:height.toLocaleString(language==="tr" ? "tr-TR" : "en-US")
          }),
          attention:true
        });
      }
    }

    const visibility=group.tokens.map(parseInterpretationVisibility).find(value=>value!==null);
    if(visibility!==undefined && visibility!==null && visibility < (rules.visibility?.cautionBelowMeters ?? 5000)){
      forecast.push({
        text:fillWeatherTemplate(locale.templates.lowVisibility,{
          ...range,
          station:taf.station || data?.icao || "Airport",
          visibility:visibility.toLocaleString(language==="tr" ? "tr-TR" : "en-US")+" m"
        }),
        attention:true
      });
    }
  }

  if(!current.length && !forecast.length){
    current.push({text:locale.ui.noSignificantHazard,attention:false});
  }

  return {locale,current,forecast};
}

function renderWeatherInterpretation(data){
  const panel=document.getElementById("weather-interpretation");
  const title=document.getElementById("weather-interpretation-title");
  const content=document.getElementById("weather-interpretation-content");
  if(!panel || !title || !content) return;

  const summary=buildWeatherInterpretation(data,currentWeatherLanguage);
  if(!summary){
    panel.hidden=true;
    content.innerHTML="";
    return;
  }

  title.textContent=summary.locale.ui.title;
  content.innerHTML="";

  const appendSection=(label,items)=>{
    if(!items.length) return;

    const section=document.createElement("div");
    section.className="weather-summary-section";

    const heading=document.createElement("div");
    heading.className="weather-summary-label";
    heading.textContent=label;
    section.appendChild(heading);

    const list=document.createElement("div");
    list.className="weather-summary-list";

    for(const item of items){
      const row=document.createElement("div");
      row.className="weather-summary-item"+(item.attention ? " is-attention" : "");
      row.textContent=item.text;
      list.appendChild(row);
    }

    section.appendChild(list);
    content.appendChild(section);
  };

  appendSection(summary.locale.ui.current,summary.current);
  appendSection(summary.locale.ui.forecast,summary.forecast);

  panel.hidden=false;
}


async function loadAirportTimezones(){
  try{
    const response=await fetch("/main/assets/data/airport-timezones.json",{cache:"force-cache"});
    if(!response.ok) return;

    const data=await response.json();
    airportTimezoneData={
      exact:data?.exact || {},
      prefix:data?.prefix || {}
    };
  }catch(error){
    console.warn("Airport timezone verisi yüklenemedi:",error);
  }
}

function getAirportTimezone(icao){
  const code=String(icao || "").trim().toUpperCase();
  if(!code) return null;

  return airportTimezoneData.exact?.[code]
    || airportTimezoneData.prefix?.[code.slice(0,2)]
    || null;
}

function resolveUtcDate(day,hour,minute=0,reference=new Date()){
  day=Number(day);
  hour=Number(hour);
  minute=Number(minute);

  if(!Number.isFinite(day) || !Number.isFinite(hour) || !Number.isFinite(minute)) return null;

  const ref=reference instanceof Date && !Number.isNaN(reference.getTime())
    ? reference
    : new Date();

  let best=null;
  let bestDistance=Infinity;

  for(const monthOffset of [-1,0,1]){
    const anchor=new Date(Date.UTC(ref.getUTCFullYear(),ref.getUTCMonth()+monthOffset,1));
    const candidate=new Date(Date.UTC(
      anchor.getUTCFullYear(),
      anchor.getUTCMonth(),
      day,
      hour,
      minute
    ));

    const distance=Math.abs(candidate.getTime()-ref.getTime());
    if(distance<bestDistance){
      best=candidate;
      bestDistance=distance;
    }
  }

  return best;
}

function formatAirportLocalTime(date,timeZone){
  if(!(date instanceof Date) || Number.isNaN(date.getTime()) || !timeZone) return null;

  try{
    const parts=new Intl.DateTimeFormat("en-GB",{
      timeZone,
      day:"2-digit",
      hour:"2-digit",
      minute:"2-digit",
      hour12:false
    }).formatToParts(date);

    const get=type=>parts.find(part=>part.type===type)?.value || "";
    return get("day")+" "+get("hour")+":"+get("minute")+" Local";
  }catch(error){
    return null;
  }
}

function formatAirportLocalPeriod(value,timeZone,reference=new Date()){
  const match=String(value || "").match(/^(\d{2})(\d{2})\/(\d{2})(\d{2})$/);
  if(!match || !timeZone) return null;

  const start=resolveUtcDate(match[1],match[2],0,reference);
  if(!start) return null;

  const end=resolveUtcDate(match[3],match[4],0,start);
  if(!end) return null;

  const startText=formatAirportLocalTime(start,timeZone);
  const endText=formatAirportLocalTime(end,timeZone);
  if(!startText || !endText) return null;

  return startText.replace(" Local","")+"–"+endText.replace(" Local","")+" Local";
}


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
  if(match[3]==="CB") text+=" · Cumulonimbus (CB)";
  if(match[3]==="TCU") text+=" · Towering cumulus (TCU)";
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
      key.textContent=label+":";

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
  const station=stationIndex>=0 ? tokens[stationIndex] : "";
  const timeZone=getAirportTimezone(station);

  if(station) rows.push(["Station",station]);

  const time=tokens.find(token=>/^\d{6}Z$/.test(token));
  if(time){
    rows.push([
      "Observation",
      time.slice(0,2)+" "+time.slice(2,4)+":"+time.slice(4,6)+" UTC"
    ]);

    const observationDate=resolveUtcDate(
      time.slice(0,2),
      time.slice(2,4),
      time.slice(4,6)
    );
    const local=formatAirportLocalTime(observationDate,timeZone);
    if(local) rows.push(["Local",local]);
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
  const station=stationIndex>=0 ? tokens[stationIndex] : "";
  const timeZone=getAirportTimezone(station);
  if(station) headerRows.push(["Station",station]);

  let issueDate=null;
  const issueIndex=tokens.findIndex(token=>/^\d{6}Z$/.test(token));
  if(issueIndex>=0){
    const issue=tokens[issueIndex];
    headerRows.push([
      "Issued",
      issue.slice(0,2)+" "+issue.slice(2,4)+":"+issue.slice(4,6)+" UTC"
    ]);

    issueDate=resolveUtcDate(
      issue.slice(0,2),
      issue.slice(2,4),
      issue.slice(4,6)
    );
    const issueLocal=formatAirportLocalTime(issueDate,timeZone);
    if(issueLocal) headerRows.push(["Local",issueLocal]);
  }

  const validityIndex=tokens.findIndex(token=>/^\d{4}\/\d{4}$/.test(token));
  if(validityIndex>=0){
    const validity=tokens[validityIndex];
    headerRows.push(["Validity",decodePeriod(validity)]);

    const validityLocal=formatAirportLocalPeriod(validity,timeZone,issueDate || new Date());
    if(validityLocal) headerRows.push(["Local validity",validityLocal]);
  }

  const start=Math.max(validityIndex+1,0);
  let current={title:"Initial conditions",tokens:[],localPeriod:null};
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
          tokens:[],
          localPeriod:range ? formatAirportLocalPeriod(range,timeZone,issueDate || new Date()) : null
        };
      }else if(/^FM\d{6}$/.test(token)){
        const time=token.slice(2);
        const fromDate=resolveUtcDate(
          time.slice(0,2),
          time.slice(2,4),
          time.slice(4,6),
          issueDate || new Date()
        );
        current={
          title:"FM · From "+time.slice(0,2)+" "+time.slice(2,4)+":"+time.slice(4,6)+" UTC",
          tokens:[],
          localPeriod:formatAirportLocalTime(fromDate,timeZone)
        };
      }else{
        let title=token.replace("PROB","")+"% probability";
        if(tokens[i+1]==="TEMPO"){
          title+=" · TEMPO";
          i++;
        }
        const range=/^\d{4}\/\d{4}$/.test(tokens[i+1] || "") ? tokens[++i] : "";
        if(range) title+=" · "+decodePeriod(range);
        current={
          title,
          tokens:[],
          localPeriod:range ? formatAirportLocalPeriod(range,timeZone,issueDate || new Date()) : null
        };
      }
    }else{
      current.tokens.push(token);
    }
  }

  pushCurrent();

  if(headerRows.length) sections.push({title:"TAF",rows:headerRows});

  for(const group of groups){
    const rows=decodeConditions(group.tokens);
    if(group.localPeriod) rows.unshift(["Local",group.localPeriod]);
    if(rows.length) sections.push({title:group.title,rows});
  }

  return sections;
}

function renderWeatherResult(data){
  lastWeatherData=data;
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

  renderWeatherInterpretation(data);
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

  currentWeatherLanguage=getStoredWeatherLanguage();
  document.querySelectorAll("[data-weather-language]").forEach(button=>{
    const active=button.dataset.weatherLanguage===currentWeatherLanguage;
    button.setAttribute("aria-pressed",active ? "true" : "false");
    button.addEventListener("click",()=>{
      setWeatherLanguage(button.dataset.weatherLanguage || "tr");
    });
  });

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
    const weatherDataPromise=Promise.all([
      loadAirportTimezones(),
      loadWeatherInterpretationData()
    ]);
    const response=await fetch("/main/home.html",{cache:"no-cache"});
    if(!response.ok) throw new Error("HTTP "+response.status);

    const html=await response.text();
    await weatherDataPromise;

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
