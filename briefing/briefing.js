(() => {
  "use strict";
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
  const form=$("#brief-form"), submit=form.querySelector('button[type="submit"]');
  const feedback=$("#feedback"), feedbackText=$("#feedback-text");
  const workspace=$("#workspace"), hazardSection=$("#hazard-section"), stationSection=$("#station-section");
  const hazardList=$("#hazard-list"), stationList=$("#station-list");
  let controller=null, current=null;
  let modelMarkers=[],modelResult=null,modelZoom=null;

  const map=L.map("map",{zoomControl:false,worldCopyJump:true,preferCanvas:true}).setView([44,22],5);
  L.control.zoom({position:"bottomright"}).addTo(map);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:18,attribution:"&copy; OpenStreetMap contributors"}).addTo(map);

  const groups={
    route:L.layerGroup().addTo(map),
    stations:L.layerGroup().addTo(map),
    hazards:L.layerGroup().addTo(map),
    outside:L.layerGroup(),
    model:L.layerGroup().addTo(map)
  };
  if(window.YCWAFS?.attachMap)window.YCWAFS.attachMap(map);

  function esc(v){return String(v??"").replace(/[&<>'"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[c]));}
  function setFeedback(text,state=""){feedback.className="feedback"+(state?" "+state:"");feedbackText.textContent=text;}
  function tick(){ $("#utc-clock").textContent=new Date().toLocaleTimeString("en-GB",{timeZone:"UTC",hour12:false}); }
  tick(); setInterval(tick,1000);

  function defaultEtd(){
    const d=new Date(Date.now()+30*60000);
    d.setUTCMinutes(Math.ceil(d.getUTCMinutes()/10)*10,0,0);
    const p=n=>String(n).padStart(2,"0");
    $("#etd").value=`${d.getUTCFullYear()}-${p(d.getUTCMonth()+1)}-${p(d.getUTCDate())}T${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
  }
  defaultEtd();

  [$("#from"),$("#to")].forEach(i=>i.addEventListener("input",()=>i.value=i.value.toUpperCase().replace(/[^A-Z0-9]/g,"").slice(0,4)));
  $("#route-text").addEventListener("input",e=>e.target.value=e.target.value.toUpperCase());

  $("#swap").addEventListener("click",()=>{const a=$("#from").value;$("#from").value=$("#to").value;$("#to").value=a;});
  $("#clear-route").addEventListener("click",()=>{$("#route-text").value="";$("#route-text").focus();});
  $("#paste-route").addEventListener("click",async()=>{
    try{
      const t=await navigator.clipboard.readText();
      if(!t) throw new Error();
      $("#route-text").value=t.toUpperCase().slice(0,2000);
      setFeedback("Rota panodan alındı.");
    }catch(e){setFeedback("Pano izni yok. Alana uzun basıp Yapıştır kullan.");$("#route-text").focus();}
  });

  function panel(id,btn,open){$("#"+id).hidden=!open;$("#"+btn).setAttribute("aria-expanded",open?"true":"false");}
  function closePanels(){panel("route-panel","route-toggle",false);panel("layers-panel","layers-toggle",false);}
  $("#route-toggle").addEventListener("click",e=>{e.stopPropagation();const o=$("#route-panel").hidden;closePanels();panel("route-panel","route-toggle",o);});
  $("#layers-toggle").addEventListener("click",e=>{e.stopPropagation();const o=$("#layers-panel").hidden;closePanels();panel("layers-panel","layers-toggle",o);});
  $$("[data-close]").forEach(b=>b.addEventListener("click",()=>{const id=b.dataset.close;panel(id,id==="route-panel"?"route-toggle":"layers-toggle",false);}));

  // The layer panel has its own scroll area. Keep trackpad/mouse-wheel input
  // inside it instead of letting Leaflet or the page steal the gesture.
  const layersPanel=$("#layers-panel");
  if(layersPanel&&window.L?.DomEvent){
    L.DomEvent.disableClickPropagation(layersPanel);
    L.DomEvent.disableScrollPropagation(layersPanel);
  }
  layersPanel?.addEventListener("wheel",e=>e.stopPropagation(),{passive:true});
  layersPanel?.addEventListener("touchmove",e=>e.stopPropagation(),{passive:true});

  map.on("click",closePanels);

  $$("[data-layer]").forEach(cb=>cb.addEventListener("change",e=>{
    const g=groups[e.currentTarget.dataset.layer]; if(!g)return;
    e.currentTarget.checked?g.addTo(map):map.removeLayer(g);
  }));

  function stationIcon(s){
    const fc=s.metar?.flightCategory||"NA", endpoint=s.role!=="enroute";
    return L.divIcon({className:"",html:`<div class="station-marker ${esc(fc)}${endpoint?" endpoint":""}"></div>`,iconSize:endpoint?[18,18]:[15,15],iconAnchor:endpoint?[9,9]:[7,7]});
  }
  function fixIcon(){return L.divIcon({className:"",html:'<div class="fix-marker"></div>',iconSize:[7,7],iconAnchor:[3,3]});}

  function hazardColor(h){
    const t=(h.hazard||"").toUpperCase();
    if(t.includes("TURB")) return "#ffbd52";
    if(t.includes("ICE")) return "#66a8ff";
    if(t.includes("VA")) return "#b77cff";
    if(t.includes("TS")) return "#ff6472";
    return "#ff7a86";
  }

  const utc=value=>{
    const d=new Date(typeof value==="number"?value*1000:value);
    return value!=null&&Number.isFinite(d.getTime())?d.toISOString().slice(0,16).replace("T"," ")+"Z":"—";
  };
  const sigmetAvailable=data=>data.sourceStatus?.sigmet?.ok===true;
  function timeText(h){
    return ({
      overlaps_route_eta:"Tahmini rota geçiş zamanı ile örtüşüyor",
      outside_route_eta:"Uçuş sırasında geçerli, fakat bu bölgedeki tahmini geçiş saatine uymuyor",
      outside_flight_window:"Uçuş zaman aralığı dışında",
      unknown:"Geçerlilik / rota zamanı kesin eşleştirilemedi"
    })[h.timeRelation]||"Zaman bilinmiyor";
  }
  function updateModelMap(result){
    groups.model.clearLayers();modelMarkers=[];modelResult=result;modelZoom=map.getZoom();
    const status=$("#map-model-status");
    if(!["ready","partial"].includes(result.state)){
      status.textContent=result.state==="loading"?"MODEL WIND · yükleniyor…":"MODEL WIND · veri alınamadı";return;
    }
    const points=result.samples,meta=result.meta;
    let previousArrow=map.latLngToLayerPoint([points[0].lat,points[0].lon]);
    for(let i=0;i<points.length;i++){
      const p=points[i],w=p.wind;if(!w)continue;
      const pixel=map.latLngToLayerPoint([p.lat,p.lon]);
      const prominent=i>0 && i<points.length-1 && pixel.distanceTo(previousArrow)>=50;
      if(prominent)previousArrow=pixel;
      const flow=(w.dir+180)%360;
      const marker=prominent?L.marker([p.lat,p.lon],{icon:L.divIcon({className:"model-wind-icon",iconSize:[48,42],iconAnchor:[24,21],html:`<span class="wind-arrow" style="transform:rotate(${flow}deg)">↑</span><strong>${Math.round(w.kt)}<small>kt</small></strong>`})}):L.circleMarker([p.lat,p.lon],{radius:3,color:"#31e4ff",weight:1,fillColor:"#070c11",fillOpacity:1});
      marker.bindPopup(`<div class="model-popup"><strong>GFS · MODEL GUIDANCE</strong><br>${Math.round(p.progress*100)}% rota · ${Math.round(p.distanceNm)} NM<br>Rüzgâr <b>${String(Math.round(w.dir)%360).padStart(3,"0")}° / ${Math.round(w.kt)} kt</b> (geldiği yön, true)<br>${w.tailKt>=0?"Arka":"Karşı"} ${Math.round(Math.abs(w.tailKt))} kt · çapraz ${Math.round(w.crossKt)} kt<br>Sıcaklık ${Number.isFinite(p.tempC)?p.tempC.toFixed(1):"—"} °C · RH ${Number.isFinite(p.rh)?Math.round(p.rh):"—"}%<br>Geopotansiyel yükseklik ${Number.isFinite(p.heightM)?Math.round(p.heightM):"—"} gpm<br><br>${esc(meta.pressureMb)} hPa · FL${esc(current.flight.cruiseFL)} için yaklaşık seviye<br>Model geçerli: ${esc(utc(meta.validUtc))}<br>Geçiş tahmini: ${esc(utc(p.etaUtc))}<br><small>Grid: ${p.gridPoint.latitude.toFixed(2)}, ${p.gridPoint.longitude.toFixed(2)} · ok akış yönünü gösterir</small></div>`,{maxWidth:280,minWidth:200,maxHeight:190,autoPanPaddingTopLeft:[12,56],autoPanPaddingBottomRight:[12,86]});
      marker.addTo(groups.model);modelMarkers[i]=marker;
    }
    status.textContent=`MODEL WIND · ${meta.pressureMb} hPa · ${utc(meta.validUtc)} · ${points.length} nokta${result.state==="partial"?" · EKSİK ALAN":""}`;
  }
  map.on("zoomend",()=>{if(modelResult&&modelZoom!==map.getZoom())updateModelMap(modelResult);});
  function focusModelPoint(i){
    const marker=modelMarkers[i];if(!marker)return;
    if(!map.hasLayer(groups.model)){groups.model.addTo(map);$('[data-layer="model"]').checked=true;}
    map.panTo(marker.getLatLng(),{animate:false});marker.openPopup();$("#map").scrollIntoView({behavior:"instant",block:"center"});
  }

  function renderMap(data){
    Object.values(groups).forEach(g=>g.clearLayers());
    modelMarkers=[];modelResult=null;
    const line=L.polyline(data.route,{color:"#31e4ff",weight:3.5,opacity:.95}).addTo(groups.route);
    L.polyline(data.route,{color:"#31e4ff",weight:15,opacity:.05}).addTo(groups.route);

    for(const p of data.routeInput?.resolved||[]){
      if(p.type==="departure"||p.type==="arrival") continue;
      L.marker([p.lat,p.lon],{icon:fixIcon()}).bindTooltip(esc(p.id),{direction:"top",opacity:.85}).addTo(groups.route);
    }
    for(const s of data.stations||[]){
      L.marker([s.lat,s.lon],{icon:stationIcon(s)})
        .bindPopup(`<strong style="color:#31e4ff">${esc(s.icao)}</strong><br>${esc(s.name)}<br><span style="color:#8297a6">${esc(s.metar?.flightCategory||"N/A")} · ${esc(s.routeDistanceNm)} NM from route</span><br><br><code style="font-size:9px">${esc(s.metar?.raw||"METAR unavailable")}</code>`)
        .addTo(groups.stations);
    }
    for(const h of data.hazards||[]){
      try{
        const outside=["outside_flight_window","outside_route_eta"].includes(h.timeRelation),col=outside?"#8195a4":hazardColor(h);
        const l=L.geoJSON(h.feature,{style:()=>({color:col,weight:1.6,fillColor:col,fillOpacity:outside?.035:.12,dashArray:outside?"3 9":"6 5"})});
        const encounter=h.routeEncounter?`<br>Rota yakınından tahmini geçiş: ${esc(utc(h.routeEncounter.etaStart))} → ${esc(utc(h.routeEncounter.etaEnd))}`:"";
        l.bindPopup(`<strong>${esc(h.hazard)}</strong><br>${esc(h.proximity)} · ${esc(h.distanceNm)} NM<br><span style="color:#8297a6">${esc(relationText(h.cruiseRelation))}</span><br>${esc(timeText(h))}<br>SIGMET: ${esc(utc(h.validFrom))} → ${esc(utc(h.validTo))}${encounter}<br><br><code style="font-size:9px">${esc(h.raw||"SIGMET")}</code>`);
        l.addTo(outside?groups.outside:groups.hazards);
      }catch(e){}
    }
    const summary=data.hazardSummary||{};
    $("#map-sigmet-status").textContent=sigmetAvailable(data)?`SIGMET · ${summary.intersects||0} kesişim · ${summary.within100nm||0} rota-zaman ilgili/belirsiz · ${summary.outsideRouteEta||0} geçiş saatinde ilgisiz` : "SIGMET · kaynak alınamadı; durum bilinmiyor";
    map.fitBounds(line.getBounds(),{paddingTopLeft:[45,55],paddingBottomRight:[45,95],animate:false});
  }

  function roleLabel(r){return r==="departure"?"DEPARTURE":r==="arrival"?"ARRIVAL":"ENROUTE";}
  function renderStations(stations){
    stationList.innerHTML="";
    for(const s of stations){
      const fc=s.metar?.flightCategory||"NA";
      const meta=s.role==="enroute"?`${s.routeDistanceNm} NM from route · ${Math.round(s.progress*100)}%`:s.role==="departure"?"Route origin":"Route destination";
      const metarText=s.metar?.raw||(s.metarStale?"Eski METAR gösterilmedi.":"METAR mevcut değil");
      const tafText=s.taf?.raw||(s.tafStale?"Uçuş zamanını kapsamayan eski TAF gösterilmedi.":"TAF mevcut değil");
      const a=document.createElement("article"); a.className="station-card";
      a.innerHTML=`
        <div class="station-main">
          <div class="station-id"><strong>${esc(s.icao)}</strong><span>${roleLabel(s.role)}</span></div>
          <div class="station-name"><strong>${esc(s.name)}</strong><span>${esc(meta)}</span></div>
          <span class="fc fc-${esc(fc)}">${esc(fc)}</span>
        </div>
        <div class="wx-lines">
          <div class="wx-block"><small>OBSERVATION · METAR · ${esc(utc(s.metar?.obsTime))}</small><div class="wx-raw">${esc(metarText)}</div></div>
          <div class="wx-block"><small>TERMINAL FORECAST · TAF · ${esc(utc(s.taf?.validFrom))} → ${esc(utc(s.taf?.validTo))}</small><div class="wx-raw">${esc(tafText)}</div></div>
        </div>`;
      stationList.appendChild(a);
    }
  }

  function verticalText(h){
    const v=h.vertical||{}, lo=v.bottomFL, hi=v.topFL;
    if(v.topAboveFL!=null)return `TOP ABV FL${v.topAboveFL} · kesin üst sınır bilinmiyor`;
    if(lo===null&&hi===null) return "Vertical: unknown";
    if(lo===0&&hi!==null) return `SFC–FL${hi}`;
    if(lo!==null&&hi!==null) return `FL${lo}–FL${hi}`;
    if(lo!==null) return `Above FL${lo}`;
    return `Top FL${hi}`;
  }
  function relationText(r){
    return ({at_cruise_level:"Cruise seviyesi içinde",above_hazard_layer:"Hazard cruise altında",below_hazard_layer:"Hazard cruise üstünde",unknown:"Seviye bilgisi belirsiz"})[r]||r;
  }
  function renderHazards(hazards,data){
    hazardList.innerHTML="";
    if(!sigmetAvailable(data)){hazardList.innerHTML='<div class="empty">SIGMET kaynağı alınamadı. Uyarı bulunup bulunmadığı bilinmiyor.</div>';return;}
    if(!hazards.length){hazardList.innerHTML='<div class="empty">Alınan SIGMET verisinde rota/100 NM için kayıt bulunmadı. Bu, hava tehlikesi olmadığı anlamına gelmez.</div>';return;}
    for(const h of hazards){
      const a=document.createElement("article"); a.className="hazard-card";
      if(["outside_flight_window","outside_route_eta"].includes(h.timeRelation))a.classList.add("outside-window");
      const cls=h.proximity==="INTERSECTS"?"bad":h.proximity==="NEAR_ROUTE"?"warn":"info";
      a.innerHTML=`
        <div class="head"><strong>${esc(h.hazard)}</strong><span class="tag ${cls}">${esc(h.proximity)}</span></div>
        <div class="hazard-meta">
          <span>${h.distanceNm<0.5?"ROUTE HIT":esc(h.distanceNm+" NM")}</span>
          <span>${esc(verticalText(h))}</span>
          <span>${esc(relationText(h.cruiseRelation))}</span>
          <span>${esc(timeText(h))}</span>
          <span>SIGMET ${esc(utc(h.validFrom))} → ${esc(utc(h.validTo))}</span>
          ${h.routeEncounter?`<span>ROUTE ETA ${esc(utc(h.routeEncounter.etaStart))} → ${esc(utc(h.routeEncounter.etaEnd))}</span>`:""}
        </div>
        <pre>${esc(h.raw||"SIGMET")}</pre>`;
      hazardList.appendChild(a);
    }
  }

  function briefLine(title,status,cls,text){return `<div class="brief-line"><div class="top"><strong>${esc(title)}</strong><span class="${cls}">${esc(status)}</span></div><p>${text}</p></div>`;}
  function catClass(cat){return cat==="VFR"?"ok":cat==="MVFR"?"info":cat==="IFR"||cat==="LIFR"?"bad":"warn";}
  function renderSimpleBrief(data){
    const dep=data.stations.find(s=>s.role==="departure"), arr=data.stations.find(s=>s.role==="arrival");
    const depCat=dep?.metar?.flightCategory||"N/A", arrCat=arr?.metar?.flightCategory||"N/A";
    const hit=data.hazardSummary?.intersects||0, near=data.hazardSummary?.nearRoute||0, cruise=data.hazardSummary?.atCruiseLevel||0;
    const resolved=(data.routeInput?.resolved||[]).filter(p=>!["departure","arrival"].includes(p.type)).length;
    const unresolved=data.routeInput?.unresolved||[];
    const rows=[];
    rows.push(briefLine("KALKIŞ",depCat,catClass(depCat),`${esc(dep?.icao||"")} mevcut METAR kategorisi. TAF aşağıdaki kartta.`));
    rows.push(briefLine("VARIŞ",arrCat,catClass(arrCat),`${esc(arr?.icao||"")} mevcut METAR kategorisi. TAF aşağıdaki kartta.`));
    rows.push(briefLine("ROTA",data.routeMode==="user_route"?"OFP ROUTE":"ESTIMATED",data.routeMode==="user_route"?"ok":"warn",data.routeMode==="user_route"?`${resolved} fix/navaid çözüldü${unresolved.length?"; çözülemeyen: "+esc(unresolved.join(", ")):""}.`:"OFP girilmedi. Great-circle tahmini kullanılıyor."));
    const available=sigmetAvailable(data),summary=data.hazardSummary||{},relevant=summary.within100nm||0;
    rows.push(briefLine("SIGMET",!available?"VERİ YOK":hit?hit+" KESİŞİM":relevant?relevant+" İLGİLİ":"KAYIT YOK",!available?"warn":hit?"bad":"info",!available?"SIGMET servisi alınamadı; durum bilinmiyor.":`${relevant} rota/100 NM kaydı tahmini rota geçiş saatiyle ilgili veya zamanı belirsiz. ${summary.outsideRouteEta||0} kayıt uçuş sırasında geçerli olsa da ilgili bölgedeki tahmini geçiş saatine uymuyor. ${summary.outsideFlightWindow||0} kayıt tüm uçuş penceresi dışında. ${summary.unknownTime||0} kaydın zamanı kesin eşleştirilemedi. Kayıt yokluğu, tehlike olmadığı anlamına gelmez.`));
    rows.push(briefLine("CRUISE",`FL${data.flight.cruiseFL}`,cruise?"warn":"info",!available?"SIGMET kaynağı alınamadığı için seviye karşılaştırması yapılamadı.":cruise?`${cruise} SIGMET'in bildirilen dikey bandı cruise seviyesini kapsıyor.`:"Gösterilen SIGMET'lerde cruise seviyesini açıkça kapsayan dikey bant tespit edilmedi. Bilinmeyen seviye alanları ayrıca kontrol edilmeli."));
    $("#simple-brief").innerHTML=rows.join("");
  }

  function renderRouteMeta(data){
    const label=`${data.from.icao} → ${data.to.icao}`;
    $("#route-label").textContent=label; $("#brief-route").textContent=label;
    $("#distance").textContent=`${Math.round(data.distanceNm)} NM`;
    $("#fl-out").textContent=`FL${data.flight.cruiseFL}`;
    $("#route-type").textContent=data.routeMode==="user_route"?"USER ROUTE":"GREAT CIRCLE";
    $("#etd-out").textContent=new Date(data.flight.etdUtc).toLocaleTimeString("en-GB",{timeZone:"UTC",hour:"2-digit",minute:"2-digit"})+"Z";
    $("#station-count").textContent=data.stations.length;
    $("#hit-count").textContent=sigmetAvailable(data)?data.hazardSummary.intersects:"—";
    $("#near-count").textContent=sigmetAvailable(data)?data.hazardSummary.nearRoute:"—";
    const m=data.flight.estimatedEetMinutes; $("#eet").textContent=`${Math.floor(m/60)}h ${m%60}m*`;
    const resolved=(data.routeInput?.resolved||[]).filter(p=>!["departure","arrival"].includes(p.type)).map(p=>p.id);
    const unr=data.routeInput?.unresolved||[];
    $("#resolved-route").textContent=(resolved.length?"Resolved: "+resolved.join(" → "):"Great-circle route")+(unr.length?"\nUnresolved: "+unr.join(", "):"");
  }

  function render(data){
    current=data; $("#data-status").textContent=`AWC alındı: ${utc(data.fetchedAt)} · METAR ${data.sourceStatus?.metar?.ok?"OK":"VERİ YOK"} · TAF ${data.sourceStatus?.taf?.ok?"OK":"VERİ YOK"} · SIGMET ${sigmetAvailable(data)?"OK":"VERİ YOK"}`; workspace.hidden=false; hazardSection.hidden=false; stationSection.hidden=false;
    renderRouteMeta(data); renderSimpleBrief(data); renderHazards(data.hazards||[],data); renderStations(data.stations||[]);
    requestAnimationFrame(()=>{
      try{
        map.invalidateSize({pan:false});
        renderMap(data);
        if(window.YCModelWX?.load)window.YCModelWX.load(data,{onUpdate:updateModelMap,onFocus:focusModelPoint}).catch(e=>console.error("Model WX error",e));
        if(window.YCWAFS?.load)window.YCWAFS.load(data).catch(e=>console.error("WAFS visual forecast error",e));
        setTimeout(()=>map.invalidateSize({pan:false}),80);
      }catch(e){
        console.error("Map render error",e);
      }
    });

  }

  async function load(){
    const from=$("#from").value.trim().toUpperCase(), to=$("#to").value.trim().toUpperCase();
    const fl=$("#fl").value, etd=$("#etd").value, route=$("#route-text").value.trim().toUpperCase();
    if(!/^[A-Z0-9]{4}$/.test(from)||!/^[A-Z0-9]{4}$/.test(to)||from===to){setFeedback("Geçerli ve farklı iki ICAO kodu gir.","error");return;}
    if(!etd){setFeedback("ETD UTC gir.","error");return;}
    if(controller) controller.abort(); window.YCModelWX?.cancel(); window.YCWAFS?.cancel(); controller=new AbortController(); submit.disabled=true;
    setFeedback(`${from} → ${to} pilot briefing hazırlanıyor…`,"loading");
    try{
      const q=new URLSearchParams({from,to,fl,etd}); if(route) q.set("route",route);
      const res=await fetch(`/main/api/briefing.php?${q.toString()}`,{cache:"no-store",signal:controller.signal});
      const data=await res.json().catch(()=>null);
      if(!res.ok||!data?.ok) throw new Error(data?.error||`HTTP ${res.status}`);
      render(data);
      setFeedback(`${from} → ${to}: ${data.stations.length} temsilci istasyon. ${sigmetAvailable(data)?`${data.hazardSummary.within100nm||0} uçuş aralığıyla ilişkili / zamanı belirsiz SIGMET.`:"SIGMET verisi alınamadı."}`);
    }catch(err){
      if(err?.name==="AbortError") return;
      console.error(err); setFeedback(err?.message||"Briefing alınamadı.","error");
    }finally{submit.disabled=false;}
  }

  form.addEventListener("submit",e=>{e.preventDefault();load();});
  load();
})();