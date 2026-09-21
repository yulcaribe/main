(() => {
  "use strict";

  const $ = (s, root=document) => root.querySelector(s);
  const $$ = (s, root=document) => [...root.querySelectorAll(s)];
  const form = $("#route-form");
  const feedback = $("#feedback");
  const feedbackText = $("#feedback-text");
  const workspace = $("#workspace");
  const stationSection = $("#station-section");
  const sigmetSection = $("#sigmet-section");
  const stationList = $("#station-list");
  const sigmetList = $("#sigmet-list");
  const submitButton = form.querySelector("button[type=submit]");

  let controller = null;
  let currentData = null;

  const map = L.map("map", { zoomControl:false, worldCopyJump:true, preferCanvas:true }).setView([44, 22], 5);
  L.control.zoom({position:"bottomright"}).addTo(map);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom:18,
    attribution:'&copy; OpenStreetMap contributors'
  }).addTo(map);

  const groups = {
    route:L.layerGroup().addTo(map),
    stations:L.layerGroup().addTo(map),
    sigmet:L.layerGroup().addTo(map)
  };

  function esc(value){
    return String(value ?? "").replace(/[&<>'"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[c]));
  }

  function setFeedback(text, state=""){
    feedback.className = "feedback" + (state ? " " + state : "");
    feedbackText.textContent = text;
  }

  function tickClock(){
    const now = new Date();
    $("#utc-clock").textContent = now.toLocaleTimeString("en-GB", {timeZone:"UTC", hour12:false});
  }
  tickClock();
  setInterval(tickClock,1000);

  function normalizeIcao(input){
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0,4);
  }
  [$("#from"),$("#to")].forEach(input => input.addEventListener("input",()=>normalizeIcao(input)));

  $("#route-text").addEventListener("input", e => {
    const start=e.target.selectionStart, end=e.target.selectionEnd;
    e.target.value=e.target.value.toUpperCase();
    try{e.target.setSelectionRange(start,end);}catch(err){}
  });

  $("#swap").addEventListener("click",()=>{
    const a=$("#from").value, b=$("#to").value;
    $("#from").value=b; $("#to").value=a;
  });

  $("#clear-route").addEventListener("click",()=>{
    $("#route-text").value="";
    $("#route-text").focus();
  });

  $("#paste-route").addEventListener("click",async()=>{
    try{
      if(!navigator.clipboard?.readText) throw new Error("clipboard unavailable");
      const text=await navigator.clipboard.readText();
      if(!text) throw new Error("clipboard empty");
      $("#route-text").value=text.toUpperCase().slice(0,2000);
      setFeedback("Rota panodan alındı. BRIEF ROUTE ile uygula.");
    }catch(error){
      $("#route-text").focus();
      setFeedback("Tarayıcı pano erişimine izin vermedi. Rota alanına uzun basıp Yapıştır kullan.");
    }
  });

  function setPanel(panelId, buttonId, open){
    const panel=$("#"+panelId);
    const button=$("#"+buttonId);
    if(!panel||!button) return;
    panel.hidden=!open;
    button.setAttribute("aria-expanded",open?"true":"false");
  }

  function closeMapPanels(except=""){
    if(except!=="route-info-panel") setPanel("route-info-panel","route-info-toggle",false);
    if(except!=="layers-panel") setPanel("layers-panel","layers-toggle",false);
  }

  $("#route-info-toggle").addEventListener("click",e=>{
    e.stopPropagation();
    const open=$("#route-info-panel").hidden;
    closeMapPanels(open?"route-info-panel":"");
    setPanel("route-info-panel","route-info-toggle",open);
  });

  $("#layers-toggle").addEventListener("click",e=>{
    e.stopPropagation();
    const open=$("#layers-panel").hidden;
    closeMapPanels(open?"layers-panel":"");
    setPanel("layers-panel","layers-toggle",open);
  });

  $$("[data-close-panel]").forEach(button=>button.addEventListener("click",e=>{
    e.stopPropagation();
    const id=button.dataset.closePanel;
    if(id==="route-info-panel") setPanel(id,"route-info-toggle",false);
    if(id==="layers-panel") setPanel(id,"layers-toggle",false);
  }));

  map.on("click",()=>closeMapPanels());

  $$('[data-layer]').forEach(cb => cb.addEventListener("change", e => {
    const group = groups[e.currentTarget.dataset.layer];
    if (!group) return;
    if (e.currentTarget.checked) group.addTo(map); else map.removeLayer(group);
  }));

  function markerIcon(station){
    const fc = station.metar?.flightCategory || "NA";
    const endpoint = station.role === "departure" || station.role === "arrival";
    return L.divIcon({
      className:"",
      html:`<div class="station-marker ${esc(fc)}${endpoint?" endpoint":""}"></div>`,
      iconSize:endpoint?[19,19]:[16,16],
      iconAnchor:endpoint?[9,9]:[8,8]
    });
  }

  function fixIcon(){
    return L.divIcon({
      className:"",
      html:'<div class="fix-marker"></div>',
      iconSize:[7,7],
      iconAnchor:[3,3]
    });
  }

  function popupHtml(station){
    const fc = station.metar?.flightCategory || "N/A";
    const role = station.role === "departure" ? "Departure" : station.role === "arrival" ? "Arrival" : "Enroute station";
    return `<strong style="color:#31e4ff">${esc(station.icao)}</strong> · ${esc(role)}<br>${esc(station.name)}<br><span style="color:#8297a6">${esc(fc)} · ${esc(station.routeDistanceNm)} NM from route</span><br><br><code style="font-size:9px">${esc(station.metar?.raw || "METAR unavailable")}</code>`;
  }

  function sigmetColor(feature){
    const p=feature?.properties||{};
    const text=JSON.stringify(p).toUpperCase();
    if(text.includes("TURB")) return "#ffbd52";
    if(text.includes("ICE")) return "#66a8ff";
    if(text.includes("VA ") || text.includes("VOLCAN")) return "#b77cff";
    return "#ff5d6b";
  }

  function sigmetName(feature){
    const p=feature?.properties||{};
    return p.hazard || p.hazardType || p.seriesId || p.firId || p.rawSigmet || p.rawText || "SIGMET";
  }

  function renderMap(data){
    Object.values(groups).forEach(g=>g.clearLayers());

    const route = L.polyline(data.route, {color:"#31e4ff",weight:3.5,opacity:.95}).addTo(groups.route);
    L.polyline(data.route, {color:"#31e4ff",weight:16,opacity:.055}).addTo(groups.route);

    for(const point of (data.routeInput?.resolved || [])){
      if(point.type==="departure"||point.type==="arrival") continue;
      L.marker([point.lat,point.lon],{icon:fixIcon(),interactive:true})
        .bindTooltip(esc(point.id),{direction:"top",offset:[0,-4],opacity:.85})
        .bindPopup(`<strong style="color:#31e4ff">${esc(point.id)}</strong><br>${esc(point.type || "route point")}<br><span style="color:#8297a6">User route</span>`)
        .addTo(groups.route);
    }

    for(const s of data.stations){
      L.marker([s.lat,s.lon],{icon:markerIcon(s)}).bindPopup(popupHtml(s),{maxWidth:360}).addTo(groups.stations);
    }

    let visibleSigmets=0;
    const routeBounds = route.getBounds().pad(.28);
    const features = Array.isArray(data.sigmets?.features) ? data.sigmets.features : [];
    for(const feature of features){
      try{
        const layer=L.geoJSON(feature,{
          style:()=>({color:sigmetColor(feature),weight:1.4,fillColor:sigmetColor(feature),fillOpacity:.11,dashArray:"6 5"})
        });
        const b=layer.getBounds?.();
        if(b && b.isValid() && !b.intersects(routeBounds)) continue;
        layer.bindPopup(`<strong>${esc(sigmetName(feature))}</strong><br><span style="color:#8297a6">Active international SIGMET · AWC</span>`);
        layer.addTo(groups.sigmet);
        visibleSigmets++;
      }catch(e){}
    }

    map.fitBounds(route.getBounds(),{padding:[48,48]});
    $("#sigmet-count").textContent=String(visibleSigmets);
    renderSigmetCards(features, routeBounds);
    return visibleSigmets;
  }

  function roleLabel(role){
    return role === "departure" ? "DEPARTURE" : role === "arrival" ? "ARRIVAL" : "ENROUTE";
  }

  function renderStations(stations){
    stationList.innerHTML="";
    for(const s of stations){
      const fc=s.metar?.flightCategory || "NA";
      const metar=s.metar?.raw || "METAR mevcut değil";
      const taf=s.taf?.raw || "TAF mevcut değil";
      const routeMeta=s.role === "enroute" ? `${s.routeDistanceNm} NM from route · ${Math.round(s.progress*100)}% route` : (s.role === "departure" ? "Route origin" : "Route destination");
      const article=document.createElement("article");
      article.className="station-card";
      article.innerHTML=`
        <div class="station-main">
          <div class="station-id"><strong>${esc(s.icao)}</strong><span>${roleLabel(s.role)}</span></div>
          <div class="station-name"><strong>${esc(s.name)}</strong><span>${esc(routeMeta)}</span></div>
          <span class="fc fc-${esc(fc)}">${esc(fc)}</span>
        </div>
        <div class="wx-lines">
          <div class="wx-block"><small>METAR</small><div class="wx-raw ${s.metar?.raw?"":"no-data"}">${esc(metar)}</div></div>
          <div class="wx-block"><small>TAF</small><div class="wx-raw ${s.taf?.raw?"":"no-data"}">${esc(taf)}</div></div>
        </div>`;
      stationList.appendChild(article);
    }
  }

  function renderSigmetCards(features, routeBounds){
    sigmetList.innerHTML="";
    let shown=0;
    for(const f of features){
      if(shown>=9) break;
      try{
        const layer=L.geoJSON(f); const b=layer.getBounds?.();
        if(b && b.isValid() && !b.intersects(routeBounds)) continue;
      }catch(e){continue;}
      const p=f.properties||{};
      const title=sigmetName(f);
      const raw=p.rawSigmet || p.rawText || p.rawSIGMET || p.text || "AWC GeoJSON advisory";
      const card=document.createElement("article");
      card.className="sigmet-card";
      card.innerHTML=`<strong>${esc(String(title).slice(0,80))}</strong><span>${esc(String(raw).slice(0,280))}</span>`;
      sigmetList.appendChild(card);shown++;
    }
    if(!shown){sigmetList.innerHTML='<div class="empty-card">Bu rota görünümünde çizilebilir aktif SIGMET bulunmadı.</div>';}
  }

  function renderRouteInputInfo(data){
    const meta=data.routeInput || {};
    const resolved=Array.isArray(meta.resolved)?meta.resolved:[];
    const unresolved=Array.isArray(meta.unresolved)?meta.unresolved:[];
    const ignored=Array.isArray(meta.ignored)?meta.ignored:[];
    const bits=[];
    if(resolved.length) bits.push("Çözülen: "+resolved.filter(p=>p.type!=="departure"&&p.type!=="arrival").map(p=>p.id).join(" → "));
    if(unresolved.length) bits.push("Çözülemeyen: "+unresolved.join(", "));
    if(ignored.length) bits.push("Airway/diğer: "+ignored.slice(0,12).join(", ")+(ignored.length>12?"…":""));
    $("#resolved-route").textContent=bits.join("\n") || "Great-circle route";
  }

  function categoryRank(cat){
    return ({LIFR:4,IFR:3,MVFR:2,VFR:1})[cat] || 0;
  }

  function renderRouteComment(data, visibleSigmets){
    const box=$("#route-comment-content");
    const stations=data.stations || [];
    const dep=stations.find(s=>s.role==="departure");
    const arr=stations.find(s=>s.role==="arrival");
    const enroute=stations.filter(s=>s.role==="enroute");
    const tafCount=stations.filter(s=>s.taf?.raw).length;
    const worst=enroute.slice().sort((a,b)=>categoryRank(b.metar?.flightCategory)-categoryRank(a.metar?.flightCategory))[0];
    const paragraphs=[];

    if(data.routeMode==="user_route"){
      const resolved=(data.routeInput?.resolved || []).filter(p=>p.type!=="departure"&&p.type!=="arrival");
      const unresolved=data.routeInput?.unresolved || [];
      let text=`<b>Kullanıcı rotası:</b> ${resolved.length} fix/navaid koordinata çözüldü`;
      if(unresolved.length) text+=`; ${unresolved.length} nokta çözülemedi (${esc(unresolved.slice(0,5).join(", "))}${unresolved.length>5?"…":""})`;
      text+=".";
      paragraphs.push(text);
    }else{
      paragraphs.push("<b>Rota kaynağı:</b> OFP girilmediği için great-circle tahmini kullanılıyor.");
    }

    const depCat=dep?.metar?.flightCategory || "N/A";
    const arrCat=arr?.metar?.flightCategory || "N/A";
    paragraphs.push(`<b>Meydanlar:</b> Kalkış ${esc(dep?.icao || "")} ${esc(depCat)}, varış ${esc(arr?.icao || "")} ${esc(arrCat)}.`);

    if(worst?.metar?.flightCategory){
      const affected=enroute.filter(s=>categoryRank(s.metar?.flightCategory)>=2);
      if(affected.length){
        paragraphs.push(`<b>Yolboyu METAR:</b> ${affected.length} temsilci istasyonda MVFR veya daha düşük kategori var; en kısıtlayıcı görünen ${esc(worst.icao)} ${esc(worst.metar.flightCategory)}.`);
      }else{
        paragraphs.push("<b>Yolboyu METAR:</b> Seçilen temsilci istasyonlarda VFR dışı kategori görünmüyor.");
      }
    }else{
      paragraphs.push("<b>Yolboyu METAR:</b> Ara istasyonlarda kategori bilgisi yetersiz.");
    }

    paragraphs.push(`<b>TAF kapsamı:</b> ${tafCount}/${stations.length} seçili istasyonda güncel TAF metni mevcut.`);
    paragraphs.push(visibleSigmets>0
      ? `<b>SIGMET:</b> Rota harita çevresinde ${visibleSigmets} aktif SIGMET geometrisi gösteriliyor; detay için haritadaki alanlara dokun.`
      : "<b>SIGMET:</b> Rota harita çevresinde çizilebilir aktif SIGMET görünmüyor.");

    box.innerHTML=paragraphs.map(p=>`<p>${p}</p>`).join("");
  }

  function render(data){
    currentData=data;
    workspace.hidden=false;stationSection.hidden=false;sigmetSection.hidden=false;
    const routeName=`${data.from.icao} → ${data.to.icao}`;
    $("#route-title").textContent=routeName;
    $("#route-title-full").textContent=routeName;
    $("#summary-route").textContent=routeName;
    $("#distance").textContent=`${Math.round(data.distanceNm)} NM`;
    $("#corridor-label").textContent=`±${data.corridorNm} NM`;
    $("#station-count").textContent=String(data.stations.length);
    $("#updated").textContent=new Date(data.fetchedAt).toLocaleTimeString("en-GB",{timeZone:"UTC",hour:"2-digit",minute:"2-digit",hour12:false})+"Z";
    const routeType=data.routeMode==="user_route"?"USER ROUTE":"GREAT CIRCLE";
    $("#route-type").textContent=routeType;
    $("#map-route-source").textContent=routeType;
    renderRouteInputInfo(data);
    renderStations(data.stations);
    const visibleSigmets=renderMap(data);
    renderRouteComment(data,visibleSigmets);
    setTimeout(()=>map.invalidateSize(),50);
  }

  async function loadRoute(){
    const from=$("#from").value.trim().toUpperCase();
    const to=$("#to").value.trim().toUpperCase();
    const corridor=$("#corridor").value;
    const routeText=$("#route-text").value.trim().toUpperCase();
    if(!/^[A-Z0-9]{4}$/.test(from)||!/^[A-Z0-9]{4}$/.test(to)||from===to){
      setFeedback("Geçerli ve farklı iki ICAO kodu gir.","error");return;
    }

    if(controller) controller.abort();
    controller=new AbortController();
    submitButton.disabled=true;
    setFeedback(`${from} → ${to} canlı hava verisi hazırlanıyor…`,"loading");

    try{
      const params=new URLSearchParams({from,to,corridor});
      if(routeText) params.set("route",routeText);
      const res=await fetch(`/main/api/enroute.php?${params.toString()}`,{cache:"no-store",signal:controller.signal});
      const data=await res.json().catch(()=>null);
      if(!res.ok||!data?.ok) throw new Error(data?.error||`HTTP ${res.status}`);
      render(data);
      const source=data.routeMode==="user_route"?"kullanıcı rotası":"great-circle";
      setFeedback(`${from} → ${to}: ${source}, ${data.stations.length} meteoroloji istasyonu ve canlı AWC SIGMET verisi yüklendi.`);
    }catch(err){
      if(err?.name==="AbortError") return;
      console.error(err);
      setFeedback(err?.message||"Yolboyu hava verisi alınamadı.","error");
    }finally{
      submitButton.disabled=false;
    }
  }

  form.addEventListener("submit",e=>{e.preventDefault();loadRoute();});
  loadRoute();
})();