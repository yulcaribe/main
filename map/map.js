(() => {
  "use strict";

  const $ = id => document.getElementById(id);
  if (!window.maplibregl) {
    if ($("boot-detail")) $("boot-detail").textContent = "MapLibre yüklenemedi";
    return;
  }

  const api = {
    navdata:"/main/api/navdata.php",
    notam:"/main/api/notam.php",
    wafs:"/main/api/wafs.php",
    adsb:"/main/api/adsb.php"
  };
  const esc = value => String(value ?? "")
    .replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;");

  const map = new maplibregl.Map({
    container:"map",
    center:[30.8,36.9],
    zoom:6,
    minZoom:2,
    maxZoom:15,
    hash:true,
    style:{
      version:8,
      glyphs:"https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf",
      sources:{osm:{type:"raster",tiles:["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],tileSize:256,attribution:"© OpenStreetMap contributors"}},
      layers:[{id:"osm-base",type:"raster",source:"osm",paint:{"raster-saturation":-.82,"raster-brightness-min":.05,"raster-brightness-max":.42,"raster-contrast":.22}}]
    }
  });
  window.__YC_MAP__ = map;
  map.addControl(new maplibregl.NavigationControl({showCompass:true}),"bottom-left");
  map.addControl(new maplibregl.ScaleControl({maxWidth:120,unit:"nautical"}),"bottom-left");

  function bbox(padding=0){
    const b=map.getBounds(),west=b.getWest(),east=b.getEast(),south=b.getSouth(),north=b.getNorth();
    const dx=(east-west)*padding,dy=(north-south)*padding;
    return {west:Math.max(-180,west-dx),east:Math.min(180,east+dx),south:Math.max(-85,south-dy),north:Math.min(85,north+dy)};
  }
  function setStatus(text,error=false){
    if($("status-text"))$("status-text").textContent=text;
    const dot=$("status-dot");if(dot){dot.classList.toggle("bad",error);dot.classList.toggle("ok",!error);}
  }
  function popup(lngLat,title,subtitle,body,options={}){
    return new maplibregl.Popup({closeButton:true,closeOnClick:true,maxWidth:options.maxWidth||"360px"})
      .setLngLat(lngLat)
      .setHTML(`<div class="popup"><h3>${esc(title)}</h3><div class="sub">${esc(subtitle||"")}</div><div class="popup-grid">${body||""}</div></div>`)
      .addTo(map);
  }

  const pad=n=>String(n).padStart(2,"0");
  function utcInputNow(){const d=new Date();return `${d.getUTCFullYear()}-${pad(d.getUTCMonth()+1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;}
  function getTimeIso(){const value=$("map-time")?.value;return value?new Date(value+":00Z").toISOString():new Date().toISOString();}
  function syncTimeLabel(){const iso=getTimeIso();if($("selected-time-label"))$("selected-time-label").textContent=iso.slice(0,16).replace("T"," ")+"Z";}

  const ctx={map,api,esc,popup,setStatus,bbox,getTimeIso};
  const engines={};
  let activeMode="charts";
  const panelFor={charts:"chart-panel",notam:"notam-panel",flights:"flights-panel",wafs:"wafs-panel"};
  const modeForPanel={"chart-panel":"charts","notam-panel":"notam","flights-panel":"flights","wafs-panel":"wafs"};

  function buildEngines(){
    engines.charts=window.YCCharts?.create(ctx);
    engines.notam=window.YCNotam?.create(ctx);
    engines.flights=window.YCAdsb?.create(ctx);
    engines.wafs=window.YCWafs?.create(ctx);
    Object.values(engines).forEach(engine=>engine?.init?.());
  }

  function updateZoomHint(){
    const hint=$("zoom-hint");if(!hint)return;
    if(activeMode!=="charts"||map.getZoom()>=5){hint.hidden=true;return;}
    hint.hidden=false;hint.textContent="Navdata için biraz yaklaş · z5+";
  }

  function setMode(mode){
    activeMode=engines[mode]?mode:"charts";
    document.querySelectorAll(".tool-panel").forEach(panel=>panel.classList.remove("open"));
    const target=$(panelFor[activeMode]);target?.classList.add("open");
    document.querySelectorAll("[data-panel-target]").forEach(button=>button.classList.toggle("active",modeForPanel[button.dataset.panelTarget]===activeMode));
    Object.entries(engines).forEach(([name,engine])=>engine?.setActive?.(name===activeMode));
    const timeline=$("timeline-dock");if(timeline)timeline.classList.toggle("mode-hidden",!["notam","wafs"].includes(activeMode));
    setStatus(activeMode==="flights"?"LIVE":activeMode.toUpperCase());
    updateZoomHint();
  }

  document.querySelectorAll("[data-panel-target]").forEach(button=>{
    button.addEventListener("click",()=>{
      const panel=button.dataset.panelTarget,mode=modeForPanel[panel];
      if(!mode)return;
      if(activeMode===mode&&$(panel)?.classList.contains("open")){ $(panel).classList.remove("open"); return; }
      setMode(mode);
    });
  });
  document.querySelectorAll("[data-panel-close]").forEach(button=>button.addEventListener("click",()=>button.closest(".tool-panel")?.classList.remove("open")));

  function refreshTimeEngines(){syncTimeLabel();engines.notam?.refresh?.();engines.wafs?.refresh?.();}
  function initTime(){
    if($("map-time"))$("map-time").value=utcInputNow();syncTimeLabel();
    $("timeline-toggle")?.addEventListener("click",()=>$("timeline-dock")?.classList.toggle("is-collapsed"));
    $("time-now")?.addEventListener("click",()=>{if($("map-time"))$("map-time").value=utcInputNow();refreshTimeEngines();});
    document.querySelectorAll("[data-time-step]").forEach(button=>button.addEventListener("click",()=>{
      const d=new Date(getTimeIso());d.setUTCHours(d.getUTCHours()+Number(button.dataset.timeStep||0));
      if($("map-time"))$("map-time").value=d.toISOString().slice(0,16);refreshTimeEngines();
    }));
    $("map-time")?.addEventListener("change",refreshTimeEngines);
  }

  let searchTimer=null,searchController=null,lastResults=[];
  function renderSearch(results){
    const box=$("search-results");if(!box)return;lastResults=results;
    if(!results.length){box.innerHTML='<div style="padding:12px;color:#999;font-size:10px">Sonuç bulunamadı.</div>';box.classList.add("open");return;}
    box.innerHTML=results.slice(0,24).map((item,i)=>`<button class="search-result" type="button" data-result-index="${i}"><span><strong>${esc(item.ident||item.name||"—")}</strong><small>${esc(item.name||"")}</small></span><span class="badge">${esc(item.kind||"")}</span></button>`).join("");
    box.classList.add("open");
    box.querySelectorAll("[data-result-index]").forEach(button=>button.addEventListener("click",()=>{
      const item=lastResults[Number(button.dataset.resultIndex)];if(!item)return;box.classList.remove("open");
      if(item.kind==="aircraft"&&item.hex){setMode("flights");engines.flights?.select?.(item.hex);return;}
      if(Number.isFinite(Number(item.lon))&&Number.isFinite(Number(item.lat)))map.flyTo({center:[Number(item.lon),Number(item.lat)],zoom:Math.max(map.getZoom(),9)});
    }));
  }
  async function search(query){
    const q=String(query||"").trim();if(q.length<2){$("search-results")?.classList.remove("open");return;}
    const local=engines.flights?.searchLocal?.(q)||[];renderSearch(local);
    searchController?.abort();searchController=new AbortController();
    try{
      const r=await fetch(`${api.navdata}?action=search&q=${encodeURIComponent(q)}`,{cache:"no-store",signal:searchController.signal});
      const d=await r.json().catch(()=>null);if(!r.ok||!d?.ok)throw 0;
      renderSearch([...local,...(d.results||[])]);
    }catch(e){if(e?.name!=="AbortError")renderSearch(local);}
  }
  $("nav-search")?.addEventListener("input",e=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>search(e.target.value),180);});
  document.addEventListener("click",e=>{if(!$("search-shell")?.contains(e.target))$("search-results")?.classList.remove("open");});

  initTime();
  map.on("load",()=>{
    buildEngines();
    $("boot")?.classList.add("hidden");
    const requested=new URLSearchParams(location.search).get("mode");
    setMode(["charts","notam","flights","wafs"].includes(requested)?requested:"charts");
  });
  map.on("moveend",()=>{engines[activeMode]?.refresh?.();updateZoomHint();});
  map.on("zoomend",updateZoomHint);
})();