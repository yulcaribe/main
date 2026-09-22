(() => {
  "use strict";
  const $=s=>document.querySelector(s);
  let generation=0,active=null;
  const notice="https://www.weather.gov/media/notification/pdf_2023_24/scn23-111_wafs_products_change.pdf";
  const esc=v=>String(v??"").replace(/[&<>'"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[c]));
  const fmt=(n,d=0)=>Number.isFinite(n)?n.toFixed(d):"—";
  const normLon=x=>((x+540)%360)-180;
  const rad=x=>x*Math.PI/180,deg=x=>x*180/Math.PI;
  const utc=s=>{const d=new Date(s);return Number.isFinite(d.getTime())?d.toISOString().slice(0,16).replace("T"," ")+"Z":"—";};
  function setSource(id,title,state,cls){$(id).innerHTML=`<div class="model-source-head"><strong>${esc(title)}</strong><span class="${cls}">${esc(state)}</span></div>`;}
  function distance(a,b){const dlat=rad(b[0]-a[0]),dlon=rad(normLon(b[1]-a[1]));return 6880.13*Math.asin(Math.min(1,Math.sqrt(Math.sin(dlat/2)**2+Math.cos(rad(a[0]))*Math.cos(rad(b[0]))*Math.sin(dlon/2)**2)));}
  function bearing(a,b){const x=rad(a[0]),y=rad(b[0]),dl=rad(normLon(b[1]-a[1]));return (deg(Math.atan2(Math.sin(dl)*Math.cos(y),Math.cos(x)*Math.sin(y)-Math.sin(x)*Math.cos(y)*Math.cos(dl)))+360)%360;}
  function interpolate(a,b,f){
    const angle=distance(a,b)/3440.065;
    if(angle<1e-10)return [a[0],a[1]];
    const aa=Math.sin((1-f)*angle)/Math.sin(angle),bb=Math.sin(f*angle)/Math.sin(angle);
    const x=aa*Math.cos(rad(a[0]))*Math.cos(rad(a[1]))+bb*Math.cos(rad(b[0]))*Math.cos(rad(b[1]));
    const y=aa*Math.cos(rad(a[0]))*Math.sin(rad(a[1]))+bb*Math.cos(rad(b[0]))*Math.sin(rad(b[1]));
    const z=aa*Math.sin(rad(a[0]))+bb*Math.sin(rad(b[0]));
    return [deg(Math.atan2(z,Math.hypot(x,y))),normLon(deg(Math.atan2(y,x)))];
  }
  function routeSamples(route,spacingNm=25){
    if(!Array.isArray(route)||route.length<2||route.some(p=>!Number.isFinite(p[0])||!Number.isFinite(p[1])))throw new Error("Model için geçerli rota yok.");
    const lengths=route.slice(1).map((p,i)=>distance(route[i],p)),total=lengths.reduce((a,b)=>a+b,0);
    if(!total)throw new Error("Rota uzunluğu sıfır.");
    const segments=Math.max(4,Math.min(240,Math.ceil(total/spacingNm))),out=[];
    let leg=0,passed=0;
    for(let i=0;i<=segments;i++){
      const target=total*i/segments;
      while(leg<lengths.length-1 && passed+lengths[leg]<target){passed+=lengths[leg];leg++;}
      const f=lengths[leg]?Math.max(0,Math.min(1,(target-passed)/lengths[leg])):0;
      const p=interpolate(route[leg],route[leg+1],f);
      out.push({lat:p[0],lon:p[1],progress:i/segments,distanceNm:target,track:bearing(f<.999999?p:route[leg],route[leg+1])});
    }
    return {points:out,total,spacingNm:total/segments};
  }
  function routeBBox(route){
    const lats=route.map(p=>p[0]),lons=route.map(p=>normLon(p[1]));
    let left=Math.min(...lons)-3,right=Math.max(...lons)+3;
    if(right-left>170){left=-180;right=180;}
    return {left:Math.max(-180,left),right:Math.min(180,right),bottom:Math.max(-90,Math.min(...lats)-3),top:Math.min(90,Math.max(...lats)+3)};
  }
  function wind(u,v,track){
    if(!Number.isFinite(u)||!Number.isFinite(v))return null;
    return {kt:Math.hypot(u,v)*1.943844,dir:(deg(Math.atan2(-u,-v))+360)%360,
      tailKt:(u*Math.sin(rad(track))+v*Math.cos(rad(track)))*1.943844,
      crossKt:Math.abs(u*Math.cos(rad(track))-v*Math.sin(rad(track)))*1.943844};
  }
  async function fetchGrib(data,bbox,signal){
    const mid=(Date.parse(data.flight.etdUtc)+Date.parse(data.flight.estimatedArrivalUtc))/2;
    if(!Number.isFinite(mid))throw new Error("Uçuş zamanı geçersiz.");
    const requested=new Date(mid).toISOString();
    const q=new URLSearchParams({action:"gfs025",fl:String(data.flight.cruiseFL),valid:requested.slice(0,16).replace("T"," ")});
    for(const [k,v] of Object.entries(bbox))q.set(k,v.toFixed(3));
    const r=await fetch(`/main/api/modelwx.php?${q}`,{cache:"no-store",signal});
    if(!r.ok||(r.headers.get("content-type")||"").includes("json")){
      const j=await r.json().catch(()=>null);throw new Error(j?.error||`HTTP ${r.status}`);
    }
    return {bytes:new Uint8Array(await r.arrayBuffer()),meta:{source:r.headers.get("x-yc-model-source")||"NOAA GFS 0.25",cycle:r.headers.get("x-yc-cycle")||"",fh:r.headers.get("x-yc-forecast-hour")||"",level:r.headers.get("x-yc-level")||"",validUtc:r.headers.get("x-yc-valid-utc")||null,requestedUtc:requested}};
  }
  function decodeProduct(product,points,data){
    const api=window.YCGrib2,h=api.parse(product.bytes);
    try{
      const records=Array.from({length:api.recordCount(h)},(_,i)=>({i:i+1,...api.section4(h,i+1),...api.section3(h,i+1)}));
      const fields={};
      for(const name of ["UGRD","VGRD","TMP","RH","HGT"]){
        const matching=records.filter(r=>r.ycName===name);
        if(matching.length>1)throw new Error(`${name}: birden fazla seviye/kayıt geldi.`);
        fields[name]=matching[0]||null;
      }
      if(!fields.UGRD||!fields.VGRD||!fields.TMP)throw new Error("GFS rüzgâr/sıcaklık kayıtları eksik.");
      const levels=new Set(Object.values(fields).filter(Boolean).map(r=>r.pressureMb));
      if(levels.size!==1||levels.has(null))throw new Error("GFS kayıtlarının basınç seviyeleri uyuşmuyor.");
      const sample=(record,p)=>{
        if(!record)return NaN;
        const ix=api.nearest(h,record.i,p.lat,p.lon).index,value=api.values(h,record.i)[ix];
        return typeof value==="number"&&Number.isFinite(value)&&Math.abs(value)<1e20?value:NaN;
      };
      const samples=points.map(p=>{
        const u=sample(fields.UGRD,p),v=sample(fields.VGRD,p),t=sample(fields.TMP,p),rh=sample(fields.RH,p);
        return {...p,wind:wind(u,v,p.track),tempC:Number.isFinite(t)?t-273.15:NaN,
          rh:Number.isFinite(rh)&&rh>=0&&rh<=100?rh:NaN,heightM:sample(fields.HGT,p),
          gridPoint:api.nearest(h,fields.TMP.i,p.lat,p.lon),
          etaUtc:new Date(Date.parse(data.flight.etdUtc)+p.progress*(Date.parse(data.flight.estimatedArrivalUtc)-Date.parse(data.flight.etdUtc))).toISOString()};
      });
      if(!samples.some(p=>p.wind&&Number.isFinite(p.tempC)))throw new Error("Rota model gridinin dışında veya değerler eksik.");
      return {samples,records,pressureMb:[...levels][0],missingFields:Object.entries(fields).filter(([,r])=>!r).map(([n])=>n)};
    }finally{api.release(h);}
  }
  function renderGfs(product,data,route,onUpdate,onFocus){
    const decoded=decodeProduct(product,route.points,data),samples=decoded.samples;
    const summaryIndices=[0,.25,.5,.75,1].map(f=>Math.round((samples.length-1)*f));
    const rows=summaryIndices.map(i=>{
      const p=samples[i],w=p.wind;
      return `<tr><td><button type="button" data-model-point="${i}" title="Haritada göster">${fmt(p.progress*100)}% ↗</button></td><td>${utc(p.etaUtc).slice(11)}</td><td>${w?`${String(Math.round(w.dir)%360).padStart(3,"0")}° / ${fmt(w.kt)} kt`:"—"}</td><td>${w?`${w.tailKt>=0?"Arka":"Karşı"} ${fmt(Math.abs(w.tailKt))} kt`:"—"}</td><td>${fmt(p.tempC)} °C</td><td>${fmt(p.rh)}%</td><td>${fmt(p.heightM)} gpm</td></tr>`;
    }).join("");
    $("#model-route-table").innerHTML=`<table><thead><tr><th>ROTA</th><th>TAHMİNİ GEÇİŞ*</th><th>RÜZGÂR (TRUE)</th><th>ROTA BİLEŞENİ</th><th>SICAKLIK</th><th>RH</th><th>GEOP. HGT</th></tr></thead><tbody>${rows}</tbody></table>`;
    $("#model-route-table").onclick=e=>{const button=e.target.closest("[data-model-point]");if(button)onFocus?.(Number(button.dataset.modelPoint));};
    const meta={...product.meta,pressureMb:decoded.pressureMb};
    $("#model-gfs-meta").textContent=`${meta.source} · run ${meta.cycle} +${meta.fh}h · ${decoded.pressureMb} hPa · geçerli ${utc(meta.validUtc)}`;
    const hasMissing=decoded.missingFields.length||samples.some(p=>!p.wind||![p.tempC,p.rh,p.heightM].every(Number.isFinite));
    $("#model-sampling-note").textContent=`${samples.length} nokta, yaklaşık ${fmt(route.spacingNm)} NM aralık. Tabloda 5 özet nokta. FL${data.flight.cruiseFL} için en yakın ${decoded.pressureMb} hPa kullanılıyor; tam uçuş seviyesine interpolasyon yapılmıyor. Bütün noktalar tek model zamanına aittir; geçiş saatleri 450 kt temelli kaba EET hesabıdır. Tırmanış/alçalma modellenmiyor.${hasMissing?" Bazı alanlarda veri eksik; — sıfır anlamına gelmez.":""}`;
    $("#model-diagnostics").textContent=JSON.stringify({source:meta,requestedFL:data.flight.cruiseFL,sampleCount:samples.length,spacingNm:route.spacingNm,records:decoded.records.map(r=>({index:r.i,discipline:r.discipline,category:r.parameterCategory,parameter:r.parameterNumber,name:r.ycName,pressureMb:r.pressureMb,grid:`${r.ni} × ${r.nj}`,values:r.numberOfPoints,scan:r.scanningMode})),firstSample:samples[0]},null,2);
    setSource("#model-source-gfs","GFS 0.25°",hasMissing?"PARTIAL":"MODEL READY",hasMissing?"warn":"ok");
    onUpdate?.({state:hasMissing?"partial":"ready",...decoded,meta});
  }
  function unavailableSources(){
    setSource("#model-source-wafs025","AWC WAFS VISUAL","LOADING","info");
    setSource("#model-source-wafs125","RAW WIFS NUMERIC","NOT CONNECTED","warn");
    $("#model-wafs025-meta").textContent="Public AWC WAFS forecast PNG'leri rota ve saate göre aşağıdaki WAFS ROUTE FORECAST bölümünde işleniyor.";
    $("#model-hazard-grid").innerHTML=[
      ["WAFS TURB / EDR","PUBLIC VISUAL","Haritadaki WAFS katmanı ve rota-zaman eşleştirmesi kullanılır."],
      ["WAFS ICING","PUBLIC VISUAL","En yakın mevcut public WAFS FL görseli kullanılır."],
      ["WAFS CB","PUBLIC VISUAL","CB extent ve CB tops görselleri kullanılır; CB base public viewer'da yoktur."]
    ].map(([name,state,desc])=>`<div><small>${name}</small><strong>${state}</strong><span>${desc}</span></div>`).join("");
    $("#model-wafs125-meta").textContent="Ham sayısal 0.25° WIFS/GRIB entegrasyonu ayrıca yetkili WIFS erişimi gerektirir; public PNG sistemi bundan bağımsızdır.";
    $("#model-wafs125").innerHTML="<strong>OPTIONAL</strong><span>Public görsel forecast için gerekli değil.</span>";
  }
  function cancel(){generation++;active?.abort();active=null;}
  async function load(data,{onUpdate,onFocus}={}){
    cancel();const my=generation,controller=new AbortController();active=controller;
    if(!$("#modelwx-section"))return;
    $("#modelwx-section").hidden=false;
    $("#model-route-table").innerHTML='<div class="model-loading">NOAA GFS rota verisi alınıyor…</div>';
    $("#model-gfs-meta").textContent="—";$("#model-sampling-note").textContent="";$("#model-diagnostics").textContent="";
    setSource("#model-source-gfs","GFS 0.25°","LOADING","info");unavailableSources();onUpdate?.({state:"loading",samples:[]});
    const timer=setTimeout(()=>controller.abort(),15000);
    try{
      await window.YCGrib2.init();
      const route=routeSamples(data.route),product=await fetchGrib(data,routeBBox(data.route),controller.signal);
      if(my!==generation)return;
      renderGfs(product,data,route,onUpdate,onFocus);
    }catch(e){
      if(my!==generation)return;
      const msg=e?.name==="AbortError"?"GFS 15 saniyede yanıt vermedi.":e.message||String(e);
      setSource("#model-source-gfs","GFS 0.25°","UNAVAILABLE","bad");
      $("#model-route-table").innerHTML=`<div class="empty">${esc(msg)}</div>`;
      onUpdate?.({state:"unavailable",samples:[],error:msg});
    }finally{clearTimeout(timer);if(active===controller)active=null;}
  }
  window.YCModelWX={load,cancel,routeSamples,routeBBox,wind,decodeProduct};
})();
