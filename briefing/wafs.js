(() => {
  "use strict";

  const $=s=>document.querySelector(s);
  const $$=s=>[...document.querySelectorAll(s)];

  const PRODUCTS={
    edr:{
      label:"Turbulence / EDR",short:"TURB",threshold:"AWC yalnız EDR×100 > 15 alanlarını render eder.",
      color:"#ffb84a",paneZ:342,enhance:false
    },
    icing:{
      label:"Icing severity",short:"ICING",threshold:"AWC icing severity kategorilerinin görsel katmanı.",
      color:"#55b8ff",paneZ:344,enhance:false
    },
    cbextent:{
      label:"CB horizontal extent",short:"CB EXT",threshold:"AWC yalnız CB horizontal extent > 0.3 alanlarını render eder.",
      color:"#ff4a2f",paneZ:348,enhance:true,tint:.20,filter:"saturate(1.9) brightness(1.28) contrast(1.35)"
    },
    cbtop:{
      label:"CB tops",short:"CB TOP",threshold:"AWC yalnız CB tops > 30,000 ft alanlarını render eder.",
      color:"#ff4fd8",paneZ:350,enhance:true,tint:.14,filter:"saturate(2.0) brightness(1.35) contrast(1.28)"
    },
    wind:{
      label:"WAFS wind speed",short:"WAFS WIND",threshold:"AWC yalnız 60 kt üzerindeki wind-speed alanlarını render eder.",
      color:"#58efd0",paneZ:338,enhance:false
    }
  };
  const ANALYSIS_PRODUCTS=["edr","icing","cbextent","cbtop"];

  let map=null,briefing=null,controller=null,generation=0,overlayRenderSeq=0;
  let analysis=null,hitLayer=null,controlsBound=false;
  let overlays=new Map(),objectUrls=[],frameCache=new Map(),displayCache=new Map();

  const esc=v=>String(v??"").replace(/[&<>'"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[c]));
  const utc=v=>{const d=new Date(v);return Number.isFinite(d.getTime())?d.toISOString().slice(0,16).replace("T"," ")+"Z":"—";};
  const bucket3h=ms=>Math.round(ms/(3*3600000))*(3*3600000);

  function revokeUrls(){
    for(const url of objectUrls)URL.revokeObjectURL(url);
    objectUrls=[];
  }

  function removeOverlay(id){
    const layer=overlays.get(id);
    if(layer&&map)map.removeLayer(layer);
    overlays.delete(id);
  }

  function clearVisuals(){
    for(const id of [...overlays.keys()])removeOverlay(id);
    hitLayer?.clearLayers();
  }

  function cancel(){
    generation++;
    overlayRenderSeq++;
    controller?.abort();
    controller=null;
    clearVisuals();
    revokeUrls();
    frameCache=new Map();
    displayCache=new Map();
    analysis=null;
  }

  function ensurePanes(){
    if(!map)return;
    for(const [id,p] of Object.entries(PRODUCTS)){
      const name=`wafs-${id}`;
      let pane=map.getPane(name);
      if(!pane)pane=map.createPane(name);
      pane.style.zIndex=String(p.paneZ);
      pane.style.pointerEvents="none";
    }
  }

  function masterEnabled(){
    return $("#wafs-overlay-enabled")?.checked===true;
  }

  function productEnabled(id){
    return masterEnabled()&&$('[data-wafs-product="'+id+'"]')?.checked===true;
  }

  function activeProducts(){
    if(!masterEnabled())return [];
    return Object.keys(PRODUCTS).filter(productEnabled);
  }

  function opacityFor(id){
    const input=$('[data-wafs-opacity="'+id+'"]');
    const n=Number(input?.value);
    return Number.isFinite(n)?Math.max(.05,Math.min(.95,n/100)):.4;
  }

  function bindControls(){
    if(controlsBound)return;
    controlsBound=true;

    $("#wafs-overlay-enabled")?.addEventListener("change",()=>renderActiveOverlays().catch(console.error));

    $$("[data-wafs-product]").forEach(input=>{
      input.addEventListener("change",()=>{
        const row=input.closest(".wafs-layer-row");
        row?.classList.toggle("active",input.checked);
        renderActiveOverlays().catch(console.error);
      });
      input.closest(".wafs-layer-row")?.classList.toggle("active",input.checked);
    });

    $$("[data-wafs-opacity]").forEach(input=>{
      input.addEventListener("input",()=>{
        const id=input.dataset.wafsOpacity;
        overlays.get(id)?.setOpacity(opacityFor(id));
      });
    });
  }

  function attachMap(m){
    map=m;
    ensurePanes();
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
      const url=URL.createObjectURL(blob);
      objectUrls.push(url);
      const img=await loadImage(url,signal);
      if(img.naturalWidth<100||img.naturalHeight<100)throw new Error("WAFS PNG boyutu geçersiz.");

      const canvas=document.createElement("canvas");
      canvas.width=img.naturalWidth;
      canvas.height=img.naturalHeight;
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
    try{return await promise;}
    catch(e){frameCache.delete(key);throw e;}
  }

  async function displayUrl(frame,product){
    const p=PRODUCTS[product];
    if(!p?.enhance)return frame.url;

    const key=`${frame.url}|${product}`;
    if(displayCache.has(key))return displayCache.get(key);

    const promise=(async()=>{
      const canvas=document.createElement("canvas");
      canvas.width=frame.width;
      canvas.height=frame.height;
      const ctx=canvas.getContext("2d");

      // First pass: a restrained neon halo around rendered CB pixels.
      // Original WAFS palette remains visible on top, so height/extent color
      // differences are not flattened into one synthetic value.
      ctx.save();
      ctx.globalAlpha=.88;
      ctx.shadowColor=p.color;
      ctx.shadowBlur=7;
      ctx.drawImage(frame.img,0,0);
      ctx.restore();

      // Second pass: preserve the original product while increasing contrast
      // against the dark basemap.
      ctx.save();
      ctx.filter=p.filter||"none";
      ctx.drawImage(frame.img,0,0);
      ctx.restore();

      // Light product tint makes CB extent and CB tops visually distinct while
      // keeping the underlying AWC color ramp recognizable.
      ctx.save();
      ctx.globalCompositeOperation="source-atop";
      ctx.globalAlpha=p.tint||0;
      ctx.fillStyle=p.color;
      ctx.fillRect(0,0,canvas.width,canvas.height);
      ctx.restore();

      const blob=await new Promise(resolve=>canvas.toBlob(resolve,"image/png"));
      if(!blob)return frame.url;
      const url=URL.createObjectURL(blob);
      objectUrls.push(url);
      return url;
    })();

    displayCache.set(key,promise);
    return promise;
  }

  function mercatorExtent(frame){
    const yMax=Math.PI*(frame.height/frame.width);
    const maxLat=180/Math.PI*Math.atan(Math.sinh(yMax));
    return {yMax,maxLat};
  }

  function samplePixel(frame,lat,lon){
    const {yMax,maxLat}=mercatorExtent(frame);
    if(!Number.isFinite(lat)||!Number.isFinite(lon)||lat<=-maxLat||lat>=maxLat){
      return {hit:false,rgba:[0,0,0,0],outside:true};
    }
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
    return route.map((p,i)=>({
      lat:p[0],lon:p[1],
      progress:route.length>1?i/(route.length-1):0,
      distanceNm:0,
      etaUtc:new Date(start+(end-start)*(route.length>1?i/(route.length-1):0)).toISOString()
    }));
  }

  async function concurrent(items,limit,worker){
    const out=new Array(items.length);let next=0;
    async function run(){
      while(true){
        const i=next++;
        if(i>=items.length)return;
        try{out[i]=await worker(items[i],i);}
        catch(e){out[i]={error:e};}
      }
    }
    await Promise.all(Array.from({length:Math.min(limit,items.length)},run));
    return out;
  }

  async function analyzeRoute(data,my,signal){
    const samples=routeSamples(data,25);
    const buckets=[...new Set(samples.map(p=>bucket3h(Date.parse(p.etaUtc))))];
    const jobs=[];
    for(const product of ANALYSIS_PRODUCTS){
      for(const bucket of buckets)jobs.push({product,bucket});
    }
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
        const bucket=bucket3h(Date.parse(p.etaUtc));
        const frame=frames.get(bucket);
        if(!frame){
          unknown++;
          rows.push({...p,bucket,hit:null,frame:null});
          continue;
        }
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
    const first=item.rows.find(r=>r.hit);
    const last=[...item.rows].reverse().find(r=>r.hit);
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
      const id=el.dataset.product;
      const master=$("#wafs-overlay-enabled");
      const input=$('[data-wafs-product="'+id+'"]');
      if(master)master.checked=true;
      if(input){
        input.checked=true;
        input.closest(".wafs-layer-row")?.classList.add("active");
      }
      renderActiveOverlays().catch(console.error);
      document.querySelector("#map")?.scrollIntoView({behavior:"smooth",block:"center"});
    }));
  }

  function renderHitMarkers(active){
    if(!map||!hitLayer)return;
    hitLayer.clearLayers();
    if(!masterEnabled()||!analysis)return;

    for(const id of active){
      if(!ANALYSIS_PRODUCTS.includes(id))continue;
      const item=analysis.products[id],p=PRODUCTS[id];
      if(!item||!p)continue;

      for(const row of item.rows){
        if(row.hit!==true)continue;
        L.circleMarker([row.lat,row.lon],{
          radius:3,
          weight:1.25,
          color:p.color,
          fillColor:p.color,
          fillOpacity:.18,
          opacity:.82
        }).bindPopup(
          `<div class="model-popup"><strong>WAFS · ${esc(p.label)}</strong><br>`+
          `PNG görsel eşleşmesi · ${Math.round(row.progress*100)}% rota<br>`+
          `Tahmini geçiş: ${esc(utc(row.etaUtc))}<br>`+
          `WAFS frame: ${esc(utc(row.frame?.meta.validUtc))}<br>`+
          `<small>${esc(p.threshold)} Bu, ham GRIB sayısal değeri değildir.</small></div>`
        ).addTo(hitLayer);
      }
    }
  }

  function layerMetaLine(id,frame,error=null){
    const p=PRODUCTS[id];
    if(error)return `<div><i style="background:${p.color}"></i><b>${esc(p.short)}</b><span>veri alınamadı</span></div>`;
    const level=frame.meta.layerFL!=null?`FL${frame.meta.layerFL} / ${frame.meta.pressureMb} hPa`:"whole-atmosphere";
    return `<div><i style="background:${p.color}"></i><b>${esc(p.short)}</b><span>${esc(utc(frame.meta.validUtc))} · F${esc(frame.meta.forecastHour)} · ${esc(level)}</span></div>`;
  }

  async function renderActiveOverlays(){
    if(!map||!briefing)return;
    const seq=++overlayRenderSeq;
    const status=$("#map-wafs-status");
    const meta=$("#wafs-layer-meta");
    const active=activeProducts();
    const activeSet=new Set(active);

    for(const id of [...overlays.keys()]){
      if(!activeSet.has(id))removeOverlay(id);
    }

    if(!masterEnabled()){
      hitLayer?.clearLayers();
      if(status)status.textContent="WAFS · katmanlar kapalı";
      if(meta)meta.textContent="WAFS visual forecast kapalı.";
      return;
    }

    if(!active.length){
      hitLayer?.clearLayers();
      if(status)status.textContent="WAFS · aktif ürün yok";
      if(meta)meta.textContent="Gösterilecek en az bir WAFS ürünü seç.";
      return;
    }

    const mid=(Date.parse(briefing.flight.etdUtc)+Date.parse(briefing.flight.estimatedArrivalUtc))/2;
    const bucket=bucket3h(mid);
    if(status)status.textContent=`WAFS · ${active.map(id=>PRODUCTS[id].short).join(" + ")} yükleniyor…`;

    const results=await Promise.all(active.map(async id=>{
      try{
        const frame=await fetchFrame(id,bucket,briefing.flight.cruiseFL,controller?.signal);
        const url=await displayUrl(frame,id);
        return {id,frame,url,error:null};
      }catch(error){
        return {id,frame:null,url:null,error};
      }
    }));

    if(seq!==overlayRenderSeq)return;

    for(const r of results){
      removeOverlay(r.id);
      if(r.error||!r.frame||!r.url)continue;

      const {maxLat}=mercatorExtent(r.frame);
      const layer=L.imageOverlay(r.url,[[-maxLat,-180],[maxLat,180]],{
        opacity:opacityFor(r.id),
        interactive:false,
        pane:`wafs-${r.id}`,
        className:`wafs-map-image wafs-map-image-${r.id}`
      }).addTo(map);
      overlays.set(r.id,layer);
    }

    const ok=results.filter(r=>!r.error&&r.frame);
    const failed=results.filter(r=>r.error);
    renderHitMarkers(active);

    if(status){
      status.textContent=ok.length
        ?`WAFS · ${ok.map(r=>PRODUCTS[r.id].short).join(" + ")}${failed.length?` · ${failed.length} eksik`:""}`
        :"WAFS · public görseller alınamadı";
    }
    if(meta){
      meta.innerHTML=results.map(r=>layerMetaLine(r.id,r.frame,r.error)).join("");
    }
  }

  async function load(data){
    cancel();
    briefing=data;
    const my=generation;
    controller=new AbortController();

    if($("#wafs-section"))$("#wafs-section").hidden=false;
    if($("#wafs-route-summary")){
      $("#wafs-route-summary").innerHTML='<div class="model-loading">AWC WAFS forecast görüntüleri rota saatlerine göre eşleştiriliyor…</div>';
    }
    if($("#wafs-meta"))$("#wafs-meta").textContent="WAFS frame eşleştirmesi hazırlanıyor…";

    const status=$("#map-wafs-status");
    if(status)status.textContent="WAFS · rota analizi yükleniyor…";
    setPublicStatus("LOADING","info","Public AWC WAFS forecast PNG'leri rota ve saate göre yükleniyor.");

    try{
      analysis=await analyzeRoute(data,my,controller.signal);
      if(my!==generation)return;

      const available=ANALYSIS_PRODUCTS.some(id=>(analysis.products[id]?.available||0)>0);
      setPublicStatus(
        available?"VISUAL READY":"VISUAL UNAVAILABLE",
        available?"ok":"warn",
        available
          ?"Public AWC WAFS görsel forecast aktif. Katmanlar bağımsız açılıp üst üste gösterilebilir."
          :"Public AWC WAFS PNG alınamadı. Bu, hazard olmadığı anlamına gelmez."
      );

      renderAnalysis();
      await renderActiveOverlays();
    }catch(e){
      if(my!==generation||e?.name==="AbortError")return;
      console.error("WAFS visual forecast",e);
      setPublicStatus("VISUAL UNAVAILABLE","warn","Public AWC WAFS PNG alınamadı; ham WIFS yetkisiyle ilgili bir hata değildir.");
      if($("#wafs-route-summary")){
        $("#wafs-route-summary").innerHTML=`<div class="empty">${esc(e.message||"WAFS görselleri alınamadı.")}</div>`;
      }
      if(status)status.textContent="WAFS · public görsel alınamadı";
    }
  }

  window.YCWAFS={
    attachMap,
    load,
    cancel,
    renderActiveOverlays,
    renderSelectedOverlay:renderActiveOverlays
  };
})();