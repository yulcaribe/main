(() => {
  "use strict";

  const $=s=>document.querySelector(s);
  const PRODUCTS={
    edr:{label:"Turbulence / EDR",short:"EDR",threshold:"AWC yalnız EDR×100 > 15 alanlarını render eder.",color:"#ffb45c"},
    icing:{label:"Icing severity",short:"ICING",threshold:"AWC icing severity kategorilerinin görsel katmanı.",color:"#73b9ff"},
    cbextent:{label:"CB horizontal extent",short:"CB EXT",threshold:"AWC yalnız CB horizontal extent > 0.3 alanlarını render eder.",color:"#ff6b78"},
    cbtop:{label:"CB tops",short:"CB TOP",threshold:"AWC yalnız CB tops > 30,000 ft alanlarını render eder.",color:"#d58bff"},
    wind:{label:"Wind speed",short:"WIND",threshold:"AWC yalnız 60 kt üzerindeki wind-speed alanlarını render eder.",color:"#63e6d8"}
  };
  const ANALYSIS_PRODUCTS=["edr","icing","cbextent","cbtop"];

  let map=null,briefing=null,controller=null,generation=0;
  let overlay=null,hitLayer=null,selectedProduct="edr",analysis=null;
  let objectUrls=[],frameCache=new Map(),controlsBound=false;

  const esc=v=>String(v??"").replace(/[&<>'"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[c]));
  const utc=v=>{const d=new Date(v);return Number.isFinite(d.getTime())?d.toISOString().slice(0,16).replace("T"," ")+"Z":"—";};
  const bucket3h=ms=>Math.round(ms/(3*3600000))*(3*3600000);

  function revokeUrls(){
    for(const url of objectUrls)URL.revokeObjectURL(url);
    objectUrls=[];
  }

  function clearVisuals(){
    if(map&&overlay){map.removeLayer(overlay);overlay=null;}
    if(hitLayer)hitLayer.clearLayers();
  }

  function cancel(){
    generation++;
    controller?.abort();
    controller=null;
    clearVisuals();
    revokeUrls();
    frameCache=new Map();
    analysis=null;
  }

  function bindControls(){
    if(controlsBound)return;
    controlsBound=true;
    const enabled=$("#wafs-overlay-enabled"),select=$("#wafs-product");
    enabled?.addEventListener("change",()=>renderSelectedOverlay().catch(console.error));
    select?.addEventListener("change",()=>{
      selectedProduct=select.value in PRODUCTS?select.value:"edr";
      renderSelectedOverlay().catch(console.error);
      renderHitMarkers();
    });
  }

  function attachMap(m){
    map=m;
    if(!hitLayer)hitLayer=L.layerGroup().addTo(map);
    bindControls();
  }

  function loadImage(url,signal){
    return new Promise((resolve,reject)=>{
      const img=new Image();
      const cleanup=()=>signal?.removeEventListener("abort",onAbort);
      const onAbort=()=>{cleanup();img.src="";reject(new DOMException("Aborted","AbortError"));};
      signal?.addEventListener("abort",onAbort,{once:true});
      img.onload=()=>{cleanup();resolve(img);};
      img.onerror=()=>{cleanup();reject(new Error("WAFS PNG tarayıcıda açılamadı."));};
      img.src=url;
    });
  }

  async function fetchFrame(product,bucketMs,fl,signal){
    const key=`${product}|${bucketMs}|${fl}`;
    if(frameCache.has(key))return frameCache.get(key);
    const promise=(async()=>{
      const requested=new Date(bucketMs).toISOString();
      const q=new URLSearchParams({
        action:"image",
        product,
        fl:String(fl),
        valid:requested.slice(0,16).replace("T"," ")
      });
      const r=await fetch(`/main/api/wafs.php?${q}`,{cache:"no-store",signal});
      if(!r.ok){
        const j=await r.json().catch(()=>null);
        throw new Error(j?.error||`WAFS HTTP ${r.status}`);
      }
      const blob=await r.blob();
      const url=URL.createObjectURL(blob);objectUrls.push(url);
      const img=await loadImage(url,signal);
      if(img.naturalWidth<100||img.naturalHeight<100)throw new Error("WAFS PNG boyutu geçersiz.");

      const canvas=document.createElement("canvas");
      canvas.width=img.naturalWidth;canvas.height=img.naturalHeight;
      const ctx=canvas.getContext("2d",{willReadFrequently:true});
      ctx.drawImage(img,0,0);

      const layerFL=r.headers.get("x-yc-wafs-layer-fl");
      const pressure=r.headers.get("x-yc-wafs-pressure-mb");
      return {
        product,bucketMs,img,url,canvas,ctx,
        width:canvas.width,height:canvas.height,
        meta:{
          runUtc:r.headers.get("x-yc-wafs-run"),
          forecastHour:Number(r.headers.get("x-yc-wafs-forecast-hour")),
          validUtc:r.headers.get("x-yc-wafs-valid-utc"),
          requestedUtc:r.headers.get("x-yc-wafs-requested-utc"),
          layerFL:layerFL&&layerFL!=="NA"?Number(layerFL):null,
          pressureMb:pressure&&pressure!=="NA"?Number(pressure):null
        }
      };
    })();
    frameCache.set(key,promise);
    try{return await promise;}catch(e){frameCache.delete(key);throw e;}
  }

  function mercatorExtent(frame){
    // AWC public files use the "_m" web-map raster. The vertical geographic
    // extent follows from a full 360° Mercator raster with square projected pixels.
    const yMax=Math.PI*(frame.height/frame.width);
    const maxLat=180/Math.PI*Math.atan(Math.sinh(yMax));
    return {yMax,maxLat};
  }

  function samplePixel(frame,lat,lon){
    const {yMax,maxLat}=mercatorExtent(frame);
    if(!Number.isFinite(lat)||!Number.isFinite(lon)||lat<=-maxLat||lat>=maxLat)return {hit:false,rgba:[0,0,0,0],outside:true};
    const wrapped=((lon+540)%360)-180;
    const y=Math.log(Math.tan(Math.PI/4+(lat*Math.PI/180)/2));
    const x=Math.max(0,Math.min(frame.width-1,Math.round((wrapped+180)/360*(frame.width-1))));
    const py=Math.max(0,Math.min(frame.height-1,Math.round((yMax-y)/(2*yMax)*(frame.height-1))));
    const rgba=[...frame.ctx.getImageData(x,py,1,1).data];
    return {hit:rgba[3]>16,rgba,x,y:py,outside:false};
  }

  function routeSamples(data,spacingNm=25){
    if(window.YCModelWX?.routeSamples){
      const r=window.YCModelWX.routeSamples(data.route,spacingNm);
      const start=Date.parse(data.flight.etdUtc),end=Date.parse(data.flight.estimatedArrivalUtc);
      return r.points.map(p=>({...p,etaUtc:new Date(start+(end-start)*p.progress).toISOString()}));
    }
    const route=data.route||[];
    const start=Date.parse(data.flight.etdUtc),end=Date.parse(data.flight.estimatedArrivalUtc);
    return route.map((p,i)=>({lat:p[0],lon:p[1],progress:route.length>1?i/(route.length-1):0,distanceNm:0,etaUtc:new Date(start+(end-start)*(route.length>1?i/(route.length-1):0)).toISOString()}));
  }

  async function concurrent(items,limit,worker){
    const out=new Array(items.length);let next=0;
    async function run(){
      while(true){
        const i=next++;if(i>=items.length)return;
        try{out[i]=await worker(items[i],i);}catch(e){out[i]={error:e};}
      }
    }
    await Promise.all(Array.from({length:Math.min(limit,items.length)},run));
    return out;
  }

  async function analyzeRoute(data,my,signal){
    const samples=routeSamples(data,25);
    const buckets=[...new Set(samples.map(p=>bucket3h(Date.parse(p.etaUtc))))];
    const jobs=[];
    for(const product of ANALYSIS_PRODUCTS)for(const bucket of buckets)jobs.push({product,bucket});
    await concurrent(jobs,3,async job=>fetchFrame(job.product,job.bucket,data.flight.cruiseFL,signal));
    if(my!==generation)throw new DOMException("Aborted","AbortError");

    const byProduct={};
    for(const product of ANALYSIS_PRODUCTS){
      const rows=[];let available=0,hits=0,unknown=0;
      const frames=new Map();
      for(const bucket of buckets){
        try{frames.set(bucket,await fetchFrame(product,bucket,data.flight.cruiseFL,signal));}
        catch(e){frames.set(bucket,null);}
      }
      for(const p of samples){
        const bucket=bucket3h(Date.parse(p.etaUtc)),frame=frames.get(bucket);
        if(!frame){unknown++;rows.push({...p,bucket,hit:null,frame:null});continue;}
        available++;
        const px=samplePixel(frame,p.lat,p.lon);
        if(px.hit)hits++;
        rows.push({...p,bucket,hit:px.hit,rgba:px.rgba,frame});
      }
      const goodFrames=[...frames.values()].filter(Boolean);
      byProduct[product]={
        product,rows,hits,unknown,available,total:samples.length,
        frames:goodFrames,
        layerFL:goodFrames[0]?.meta.layerFL??null,
        pressureMb:goodFrames[0]?.meta.pressureMb??null
      };
    }
    return {samples,buckets,products:byProduct};
  }

  function summaryText(item){
    if(!item||item.available===0)return ["N/A","Public PNG alınamadı"];
    const known=item.total-item.unknown;
    if(item.hits===0)return ["0 hit",`${known}/${item.total} rota örneğinde render edilmiş piksel saptanmadı`];
    const first=item.rows.find(r=>r.hit),last=[...item.rows].reverse().find(r=>r.hit);
    const span=first&&last?`${utc(first.etaUtc).slice(11)}–${utc(last.etaUtc).slice(11)}`:"";
    return [`${item.hits} hit`,`${known}/${item.total} örnek değerlendirildi · ${span}`];
  }
  function setPublicStatus(state,cls="info",detail=""){
    const box=$("#model-source-wafs025");
    if(box)box.innerHTML=`<div class="model-source-head"><strong>AWC WAFS VISUAL</strong><span class="${cls}">${esc(state)}</span></div>`;
    const meta=$("#model-wafs025-meta");
    if(meta&&detail)meta.textContent=detail;
  }


  function renderAnalysis(){
    const grid=$("#wafs-route-summary"),meta=$("#wafs-meta");
    if(!grid||!analysis||!briefing)return;
    grid.innerHTML=ANALYSIS_PRODUCTS.map(id=>{
      const item=analysis.products[id],p=PRODUCTS[id],[strong,detail]=summaryText(item);
      const level=item?.layerFL!=null?` · FL${item.layerFL}/${item.pressureMb} hPa`:"";
      return `<div class="wafs-summary-card" data-product="${id}">
        <small>${esc(p.short)}${esc(level)}</small>
        <strong>${esc(strong)}</strong>
        <span>${esc(detail)}</span>
        <em>${esc(p.threshold)}</em>
      </div>`;
    }).join("");
    const mid=(Date.parse(briefing.flight.etdUtc)+Date.parse(briefing.flight.estimatedArrivalUtc))/2;
    meta.textContent=`Rota ~25 NM örneklenir. Her nokta tahmini geçiş saatine en yakın 3 saatlik public AWC WAFS frame'i ile eşleştirilir. Orta rota: ${utc(mid)}.`;
    grid.querySelectorAll("[data-product]").forEach(el=>el.addEventListener("click",()=>{
      selectedProduct=el.dataset.product;
      const select=$("#wafs-product");if(select)select.value=selectedProduct;
      const enabled=$("#wafs-overlay-enabled");if(enabled)enabled.checked=true;
      renderSelectedOverlay().catch(console.error);renderHitMarkers();
      document.querySelector("#map")?.scrollIntoView({behavior:"smooth",block:"center"});
    }));
  }

  function renderHitMarkers(){
    if(!map||!hitLayer){return;}
    hitLayer.clearLayers();
    const enabled=$("#wafs-overlay-enabled");
    if(!enabled?.checked||!analysis)return;
    const item=analysis.products[selectedProduct];
    if(!item)return;
    const p=PRODUCTS[selectedProduct];
    for(const row of item.rows){
      if(row.hit!==true)continue;
      L.circleMarker([row.lat,row.lon],{
        radius:4,weight:1.5,color:p.color,fillColor:p.color,fillOpacity:.28
      }).bindPopup(`<div class="model-popup"><strong>WAFS · ${esc(p.label)}</strong><br>PNG görsel eşleşmesi · ${Math.round(row.progress*100)}% rota<br>Tahmini geçiş: ${esc(utc(row.etaUtc))}<br>WAFS frame: ${esc(utc(row.frame?.meta.validUtc))}<br><small>${esc(p.threshold)} Bu, ham GRIB sayısal değeri değildir.</small></div>`).addTo(hitLayer);
    }
  }

  async function renderSelectedOverlay(){
    if(!map||!briefing)return;
    if(overlay){map.removeLayer(overlay);overlay=null;}
    const status=$("#map-wafs-status"),enabled=$("#wafs-overlay-enabled");
    if(!enabled?.checked){
      if(status)status.textContent="WAFS · overlay kapalı";
      hitLayer?.clearLayers();
      return;
    }

    const p=PRODUCTS[selectedProduct]||PRODUCTS.edr;
    const mid=(Date.parse(briefing.flight.etdUtc)+Date.parse(briefing.flight.estimatedArrivalUtc))/2;
    const bucket=bucket3h(mid);
    if(status)status.textContent=`WAFS ${p.short} · yükleniyor…`;
    try{
      const frame=await fetchFrame(selectedProduct,bucket,briefing.flight.cruiseFL,controller?.signal);
      const {maxLat}=mercatorExtent(frame);
      overlay=L.imageOverlay(frame.url,[[-maxLat,-180],[maxLat,180]],{
        opacity:.48,
        interactive:false,
        pane:"overlayPane"
      }).addTo(map);
      overlay.bringToBack();
      // Keep route and interactive markers above the image.
      const level=frame.meta.layerFL!=null?` · FL${frame.meta.layerFL}/${frame.meta.pressureMb} hPa`:"";
      const txt=`WAFS ${p.short}${level} · ${utc(frame.meta.validUtc)}`;
      if(status)status.textContent=txt;
      const layerMeta=$("#wafs-layer-meta");
      if(layerMeta)layerMeta.textContent=`${utc(frame.meta.validUtc)} · run ${utc(frame.meta.runUtc)} +${frame.meta.forecastHour}h${level}`;
      renderHitMarkers();
    }catch(e){
      if(e?.name==="AbortError")return;
      if(status)status.textContent=`WAFS ${p.short} · PNG alınamadı`;
      const layerMeta=$("#wafs-layer-meta");if(layerMeta)layerMeta.textContent=e.message||"WAFS PNG alınamadı";
      renderHitMarkers();
    }
  }

  async function load(data){
    cancel();
    briefing=data;
    const my=generation;
    controller=new AbortController();
    if($("#wafs-section"))$("#wafs-section").hidden=false;
    if($("#wafs-route-summary"))$("#wafs-route-summary").innerHTML='<div class="model-loading">AWC WAFS forecast görüntüleri rota saatlerine göre eşleştiriliyor…</div>';
    if($("#wafs-meta"))$("#wafs-meta").textContent="WAFS frame eşleştirmesi hazırlanıyor…";
    const status=$("#map-wafs-status");if(status)status.textContent="WAFS · rota analizi yükleniyor…";
    setPublicStatus("LOADING","info","Public AWC WAFS forecast PNG'leri rota ve saate göre yükleniyor.");
    try{
      analysis=await analyzeRoute(data,my,controller.signal);
      if(my!==generation)return;
      const available=ANALYSIS_PRODUCTS.some(id=>(analysis.products[id]?.available||0)>0);
      setPublicStatus(
        available?"VISUAL READY":"VISUAL UNAVAILABLE",
        available?"ok":"warn",
        available
          ?"Public AWC WAFS görsel forecast aktif. Turbulence, icing ve CB rota-zaman analizi aşağıda; harita overlay varsayılan açık."
          :"Public AWC WAFS PNG alınamadı. Bu, hazard olmadığı anlamına gelmez."
      );
      renderAnalysis();
      await renderSelectedOverlay();
    }catch(e){
      if(my!==generation||e?.name==="AbortError")return;
      console.error("WAFS visual forecast",e);
      setPublicStatus("VISUAL UNAVAILABLE","warn","Public AWC WAFS PNG alınamadı; ham WIFS yetkisiyle ilgili bir hata değildir.");
      if($("#wafs-route-summary"))$("#wafs-route-summary").innerHTML=`<div class="empty">${esc(e.message||"WAFS görselleri alınamadı.")}</div>`;
      if(status)status.textContent="WAFS · public görsel alınamadı";
    }
  }

  window.YCWAFS={attachMap,load,cancel,renderSelectedOverlay};
})();