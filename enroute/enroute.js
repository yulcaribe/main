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

  $("#swap").addEventListener("click",()=>{
    const a=$("#from").value, b=$("#to").value;
    $("#from").value=b; $("#to").value=a;
  });

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

    for(const s of data.stations){
      L.marker([s.lat,s.lon],{icon:markerIcon(s)}).bindPopup(popupHtml(s),{maxWidth:360}).addTo(groups.stations);
    }

    let visibleSigmets=0;
    const routeBounds = route.getBounds().pad(.35);
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

    map.fitBounds(route.getBounds(),{padding:[50,50]});
    $("#sigmet-count").textContent=String(visibleSigmets);
    renderSigmetCards(features, routeBounds);
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

  function render(data){
    currentData=data;
    workspace.hidden=false;stationSection.hidden=false;sigmetSection.hidden=false;
    $("#route-title").textContent=`${data.from.icao} → ${data.to.icao}`;
    $("#summary-route").textContent=`${data.from.icao} → ${data.to.icao}`;
    $("#distance").textContent=`${Math.round(data.distanceNm)} NM`;
    $("#corridor-label").textContent=`±${data.corridorNm} NM`;
    $("#station-count").textContent=String(data.stations.length);
    $("#updated").textContent=new Date(data.fetchedAt).toLocaleTimeString("en-GB",{timeZone:"UTC",hour:"2-digit",minute:"2-digit",hour12:false})+"Z";
    renderStations(data.stations);
    renderMap(data);
    setTimeout(()=>map.invalidateSize(),50);
  }

  async function loadRoute(){
    const from=$("#from").value.trim().toUpperCase();
    const to=$("#to").value.trim().toUpperCase();
    const corridor=$("#corridor").value;
    if(!/^[A-Z0-9]{4}$/.test(from)||!/^[A-Z0-9]{4}$/.test(to)||from===to){setFeedback("Geçerli ve farklı iki ICAO kodu gir.","error");return;}

    if(controller) controller.abort();
    controller=new AbortController();
    submitButton.disabled=true;
    setFeedback(`${from} → ${to} canlı hava verisi hazırlanıyor…`,"loading");

    try{
      const res=await fetch(`/main/api/enroute.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&corridor=${encodeURIComponent(corridor)}`,{cache:"no-store",signal:controller.signal});
      const data=await res.json().catch(()=>null);
      if(!res.ok||!data?.ok) throw new Error(data?.error||`HTTP ${res.status}`);
      render(data);
      setFeedback(`${from} → ${to}: ${data.stations.length} meteoroloji istasyonu ve canlı AWC SIGMET verisi yüklendi.`);
    }catch(err){
      if(err?.name==="AbortError") return;
      console.error(err);
      setFeedback(err?.message||"Yolboyu hava verisi alınamadı.","error");
    }finally{submitButton.disabled=false;}
  }

  form.addEventListener("submit",e=>{e.preventDefault();loadRoute();});
  loadRoute();
})();