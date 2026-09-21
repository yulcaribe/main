(() => {
  "use strict";
  const $=(s,r=document)=>r.querySelector(s);
  let generation=0;

  const paramNames=new Map([
    ["0:0","TMP"],["1:1","RH"],["2:2","UGRD"],["2:3","VGRD"],["3:3","ICAHT"],["3:5","HGT"],
    ["6:25","CBHE"],["19:28","MWTURB"],["19:29","CATEDR"],["19:30","EDPARM"],["19:37","ICESEV"],["19:234","ICSEV"]
  ]);

  function esc(v){return String(v??"").replace(/[&<>'"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[c]));}
  function fmt(n,d=0){return Number.isFinite(n)?Number(n).toFixed(d):"—";}
  function normLon(x){let v=x;while(v>180)v-=360;while(v<-180)v+=360;return v;}
  function lonDiff(a,b){return Math.abs(normLon(a-b));}
  function icingLabel(v){const n=Math.round(v);return ({0:"NONE",1:"TRACE",2:"LIGHT",3:"MODERATE",4:"SEVERE"})[n]??fmt(v,1);}

  function setSource(id,status,text,cls=""){
    const el=$(id); if(!el)return;
    el.innerHTML=`<div class="model-source-head"><strong>${esc(status)}</strong><span class="${esc(cls)}">${esc(text)}</span></div>`;
  }

  function routeBBox(route){
    const lats=route.map(p=>Number(p[0])).filter(Number.isFinite), lons=route.map(p=>normLon(Number(p[1]))).filter(Number.isFinite);
    let bottom=Math.min(...lats)-3, top=Math.max(...lats)+3, left=Math.min(...lons)-3, right=Math.max(...lons)+3;
    bottom=Math.max(-90,bottom); top=Math.min(90,top);
    if(right-left>170){left=-180;right=180;}else{left=Math.max(-180,left);right=Math.min(180,right);}
    return {left,right,bottom,top};
  }

  function routeSamples(route,count=5){
    if(!route?.length)return [];
    const out=[];
    for(let i=0;i<count;i++){
      const idx=Math.round((route.length-1)*(i/(count-1)));
      const p=route[idx]; out.push({lat:Number(p[0]),lon:normLon(Number(p[1])),progress:i/(count-1)});
    }
    return out;
  }

  function recordNamesFromHeader(text){
    if(!text)return [];
    return text.split(";").map(line=>{
      const m=line.match(/(?:^|:)(TMP|RH|UGRD|VGRD|HGT|ICESEV|ICSEV|EDPARM|CATEDR|MWTURB|CBHE|ICAHT)(?=:|$)/i);
      return m?m[1].toUpperCase():null;
    });
  }

  function recMeta(handle,recordsHeader=""){
    const out=[];
    const names=recordNamesFromHeader(recordsHeader);
    const n=window.YCGrib2.recordCount(handle);
    for(let i=1;i<=n;i++){
      const s4=window.YCGrib2.section4(handle,i);
      const key=`${s4.parameterCategory}:${s4.parameterNumber}`;
      out.push({i,key,name:names[i-1]||paramNames.get(key)||key,s4});
    }
    return out;
  }

  function nearestIndices(handle,recordIndex,points){
    return points.map(p=>window.YCGrib2.nearest(handle,recordIndex,p.lat,p.lon).index);
  }

  function samplesFor(handle,record,indices){
    if(!record)return null;
    const a=window.YCGrib2.values(handle,record.i);
    return indices.map(i=>i>=0&&i<a.length?Number(a[i]):NaN);
  }

  function wind(u,v){
    if(!Number.isFinite(u)||!Number.isFinite(v))return null;
    return {kt:Math.hypot(u,v)*1.943844,dir:(Math.atan2(-u,-v)*180/Math.PI+360)%360};
  }

  async function fetchGrib(action,data,bbox){
    const midEpoch=(new Date(data.flight.etdUtc).getTime()+new Date(data.flight.estimatedArrivalUtc).getTime())/2;
    const valid=new Date(midEpoch).toISOString().slice(0,16).replace("T"," ");
    const q=new URLSearchParams({action,fl:String(data.flight.cruiseFL),valid});
    if(bbox){for(const k of ["left","right","bottom","top"])q.set(k,String(bbox[k].toFixed(3)));}
    const r=await fetch(`/main/api/modelwx.php?${q}`,{cache:"no-store"});
    const type=r.headers.get("content-type")||"";
    if(!r.ok||type.includes("application/json")){
      let j=null;try{j=await r.json();}catch(e){}
      throw new Error(j?.error||`HTTP ${r.status}`);
    }
    const bytes=new Uint8Array(await r.arrayBuffer());
    return {bytes,meta:{source:r.headers.get("x-yc-model-source")||action,cycle:r.headers.get("x-yc-cycle")||"",fh:r.headers.get("x-yc-forecast-hour")||"",level:r.headers.get("x-yc-level")||"",records:r.headers.get("x-yc-records")||""}};
  }

  function decode(bytes,recordsHeader=""){const h=window.YCGrib2.parse(bytes);return {handle:h,records:recMeta(h,recordsHeader)};}
  function find(records,name){return records.find(r=>r.name===name);}

  function renderGfs(product,data,points){
    const d=decode(product.bytes,product.meta.records), first=d.records[0]; if(!first)throw new Error("GFS GRIB içinde kayıt yok.");
    const ix=nearestIndices(d.handle,first.i,points);
    const u=samplesFor(d.handle,find(d.records,"UGRD"),ix),v=samplesFor(d.handle,find(d.records,"VGRD"),ix),t=samplesFor(d.handle,find(d.records,"TMP"),ix),rh=samplesFor(d.handle,find(d.records,"RH"),ix),hgt=samplesFor(d.handle,find(d.records,"HGT"),ix);
    const rows=points.map((p,i)=>{const w=wind(u?.[i],v?.[i]);return `<tr><td>${Math.round(p.progress*100)}%</td><td>${w?`${String(Math.round(w.dir)).padStart(3,"0")}° / ${Math.round(w.kt)} kt`:"—"}</td><td>${Number.isFinite(t?.[i])?fmt(t[i]-273.15,0)+" °C":"—"}</td><td>${Number.isFinite(rh?.[i])?fmt(rh[i],0)+"%":"—"}</td><td>${Number.isFinite(hgt?.[i])?fmt(hgt[i],0)+" m":"—"}</td></tr>`;}).join("");
    $("#model-route-table").innerHTML=`<table><thead><tr><th>ROUTE</th><th>WIND</th><th>TEMP</th><th>RH</th><th>HGT</th></tr></thead><tbody>${rows}</tbody></table>`;
    $("#model-gfs-meta").textContent=`${product.meta.source} · ${product.meta.cycle} +${product.meta.fh}h · ${product.meta.level}`;
    const mid=Math.floor(points.length/2),mw=wind(u?.[mid],v?.[mid]);
    return {midWind:mw,midTemp:Number.isFinite(t?.[mid])?t[mid]-273.15:null};
  }

  function renderWafs025(product,points){
    const d=decode(product.bytes,product.meta.records), first=d.records[0]; if(!first)throw new Error("WAFS 0.25 kayıt yok.");
    const ix=nearestIndices(d.handle,first.i,points);
    const edrRec=find(d.records,"EDPARM")||find(d.records,"CATEDR")||find(d.records,"MWTURB");
    const iceRec=find(d.records,"ICESEV")||find(d.records,"ICSEV");
    const cbRec=find(d.records,"CBHE");
    const edr=samplesFor(d.handle,edrRec,ix)||[], ice=samplesFor(d.handle,iceRec,ix)||[], cb=samplesFor(d.handle,cbRec,ix)||[];
    const maxFinite=a=>a.filter(Number.isFinite).reduce((m,v)=>Math.max(m,v),-Infinity);
    const mxE=maxFinite(edr),mxI=maxFinite(ice),mxC=maxFinite(cb);
    const cards=[];
    cards.push(`<div><small>TURBULENCE</small><strong>${Number.isFinite(mxE)?fmt(mxE,3):"N/A"}</strong><span>${edrRec?esc(edrRec.name+" route max"):"public feed kaydı yok"}</span></div>`);
    cards.push(`<div><small>ICING</small><strong>${Number.isFinite(mxI)?icingLabel(mxI):"N/A"}</strong><span>${iceRec?"route sample max":"public feed kaydı yok"}</span></div>`);
    cards.push(`<div><small>CB EXTENT</small><strong>${Number.isFinite(mxC)?fmt(mxC,0)+"%":"N/A"}</strong><span>${cbRec?"route sample max":"public feed kaydı yok"}</span></div>`);
    $("#model-hazard-grid").innerHTML=cards.join("");
    $("#model-wafs025-meta").textContent=`${product.meta.source} · ${product.meta.cycle} +${product.meta.fh}h · ${product.meta.level}`;
  }

  function renderWafs125(product,points,gfs){
    const d=decode(product.bytes,product.meta.records), first=d.records[0]; if(!first)throw new Error("WAFS 1.25 kayıt yok.");
    const ix=nearestIndices(d.handle,first.i,[points[Math.floor(points.length/2)]]);
    const u=samplesFor(d.handle,find(d.records,"UGRD"),ix)?.[0],v=samplesFor(d.handle,find(d.records,"VGRD"),ix)?.[0],t=samplesFor(d.handle,find(d.records,"TMP"),ix)?.[0];
    const w=wind(u,v); const tc=Number.isFinite(t)?t-273.15:null;
    const deltaWind=w&&gfs?.midWind?Math.abs(w.kt-gfs.midWind.kt):null, deltaTemp=Number.isFinite(tc)&&Number.isFinite(gfs?.midTemp)?Math.abs(tc-gfs.midTemp):null;
    $("#model-wafs125").innerHTML=`<strong>${w?`${String(Math.round(w.dir)).padStart(3,"0")}° / ${Math.round(w.kt)} kt`:"—"}</strong><span>${Number.isFinite(tc)?fmt(tc,0)+" °C":"—"}${Number.isFinite(deltaWind)?` · GFS Δ ${fmt(deltaWind,0)} kt`:""}${Number.isFinite(deltaTemp)?` · ΔT ${fmt(deltaTemp,1)}°C`:""}</span>`;
    $("#model-wafs125-meta").textContent=`${product.meta.source} · ${product.meta.cycle} +${product.meta.fh}h · ${product.meta.level}`;
  }

  async function load(data){
    const my=++generation, section=$("#modelwx-section"); if(!section)return;
    section.hidden=false;
    $("#model-route-table").innerHTML='<div class="model-loading">NOAA model gridleri alınıyor…</div>';
    $("#model-hazard-grid").innerHTML='<div class="model-loading">Aviation hazard gridleri aranıyor…</div>';
    $("#model-wafs125").innerHTML='<strong>LOADING</strong><span>legacy WAFS karşılaştırması</span>';
    setSource("#model-source-gfs","GFS 0.25°","LOADING","info");
    setSource("#model-source-wafs025","WAFS 0.25°","LOADING","info");
    setSource("#model-source-wafs125","WAFS 1.25°","LOADING","info");
    try{await window.YCGrib2.init();}catch(e){
      if(my!==generation)return;
      const msg=e?.message||String(e);["#model-source-gfs","#model-source-wafs025","#model-source-wafs125"].forEach(id=>setSource(id,"DECODER","UNAVAILABLE","bad"));
      $("#model-route-table").innerHTML=`<div class="empty">${esc(msg)}</div>`;
      $("#model-hazard-grid").innerHTML='<div class="empty">Decoder yüklenemediği için hazard gridleri çözülemedi.</div>';
      $("#model-wafs125").innerHTML='<strong>N/A</strong><span>Decoder yüklenemedi.</span>';
      return;
    }
    if(my!==generation)return;
    const bbox=routeBBox(data.route),points=routeSamples(data.route,5);
    const [gfsR,w025R,w125R]=await Promise.allSettled([
      fetchGrib("gfs025",data,bbox),fetchGrib("wafs025",data),fetchGrib("wafs125",data)
    ]);
    if(my!==generation)return;
    let gfsSummary=null;
    if(gfsR.status==="fulfilled"){
      try{gfsSummary=renderGfs(gfsR.value,data,points);setSource("#model-source-gfs","GFS 0.25°","LIVE","ok");}catch(e){setSource("#model-source-gfs","GFS 0.25°","DECODE ERROR","bad");$("#model-route-table").innerHTML=`<div class="empty">${esc(e.message)}</div>`;}
    }else{setSource("#model-source-gfs","GFS 0.25°","UNAVAILABLE","bad");$("#model-route-table").innerHTML=`<div class="empty">${esc(gfsR.reason?.message||gfsR.reason)}</div>`;}
    if(w025R.status==="fulfilled"){
      try{renderWafs025(w025R.value,points);setSource("#model-source-wafs025","WAFS 0.25°","LIVE","ok");}catch(e){setSource("#model-source-wafs025","WAFS 0.25°","DECODE ERROR","bad");$("#model-hazard-grid").innerHTML=`<div class="empty">${esc(e.message)}</div>`;}
    }else{setSource("#model-source-wafs025","WAFS 0.25°","PUBLIC FEED UNAVAILABLE","warn");$("#model-hazard-grid").innerHTML=`<div class="empty">${esc(w025R.reason?.message||w025R.reason)}</div>`;}
    if(w125R.status==="fulfilled"){
      try{renderWafs125(w125R.value,points,gfsSummary);setSource("#model-source-wafs125","WAFS 1.25°","LIVE","ok");}catch(e){setSource("#model-source-wafs125","WAFS 1.25°","DECODE ERROR","bad");$("#model-wafs125").innerHTML=`<strong>ERROR</strong><span>${esc(e.message)}</span>`;}
    }else{setSource("#model-source-wafs125","WAFS 1.25°","PUBLIC FEED UNAVAILABLE","warn");$("#model-wafs125").innerHTML=`<strong>N/A</strong><span>${esc(w125R.reason?.message||w125R.reason)}</span>`;}
  }
  window.YCModelWX={load};
})();