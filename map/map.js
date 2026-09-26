(() => {
  "use strict";

  const bootDetail = document.getElementById("boot-detail");
  if (!window.maplibregl) {
    if (bootDetail) bootDetail.textContent = "MapLibre yüklenemedi.";
    return;
  }

  const API = {
    navdata: "/main/api/navdata.php",
    notam: "/main/api/notam.php",
    wafs: "/main/api/wafs.php",
    adsb: "/main/api/adsb.php"
  };

  const COLORS = {
    airport: "#7ee7ff",
    navaid: "#ffc76b",
    waypoint: "#d6e1e7",
    airway: "#5fdbe8",
    sid: "#70e8a7",
    star: "#bc9cff",
    airspace: "#ff7f94"
  };

  const NOTAM_COLOR = [
    "match", ["get", "display_group"],
    "AERIAL_SPORT", "#00c5b9",
    "RESTRICTED_AIRSPACE", "#ff5f6d",
    "AERIAL_SURVEY", "#ff9b4a",
    "TRAINING_MILITARY", "#d9e1e5",
    "OTHER", "#4f8cff",
    "#ffd35f"
  ];

  const WAFS = {
    edr: {levels:[140,180,240,270,300,340,390,450]},
    icing: {levels:[60,100,140,180,240,300]},
    wind: {levels:[100,140,180,240,270,300,340,390,450]},
    cbextent: {levels:null},
    cbtop: {levels:null}
  };

  const chartNames = new Set(["airport","navaid","waypoint","airway","sid","star","airspace"]);
  const approximateNotamSources = ["qline-coordinate", "airport-location"];
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? "").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;");

  const boot = $("boot");
  const statusDot = $("status-dot");
  const statusText = $("status-text");
  const featureCount = $("feature-count");
  const zoomHint = $("zoom-hint");
  const searchInput = $("nav-search");
  const searchResults = $("search-results");
  const chartsToggleAll = $("charts-toggle-all");
  const timelineDock = $("timeline-dock");
  const timelineToggle = $("timeline-toggle");
  const timeInput = $("map-time");
  const timeSlider = $("map-time-slider");
  const timeLabel = $("selected-time-label");
  const timelineOffset = $("timeline-offset");
  const timelineScale = $("timeline-scale");
  const nowButton = $("time-now");
  const timeStepButtons = [...document.querySelectorAll("[data-time-step]")];
  const modeButtons = [...document.querySelectorAll("[data-panel-target]")];
  const panels = [...document.querySelectorAll(".tool-panel")];
  const closeButtons = [...document.querySelectorAll("[data-panel-close]")];
  const layerInputs = [...document.querySelectorAll("[data-nav-layer]")];
  const flightsEnabledInput = $("flights-enabled");
  const flightsStatus = $("flights-status");
  const notamTimeStatus = $("notam-time-status");
  const wafsEnabled = $("wafs-enabled");
  const wafsStatus = $("wafs-status");
  const wafsProducts = [...document.querySelectorAll("[data-wafs2-product]")];
  const wafsLevels = [...document.querySelectorAll("[data-wafs2-level]")];
  const wafsOpacities = [...document.querySelectorAll("[data-wafs2-opacity]")];

  const initialMode = new URLSearchParams(location.search).get("mode") || "charts";
  let activePanel = initialMode === "flights" ? "flights-panel" : initialMode === "notam" ? "notam-panel" : initialMode === "wafs" ? "wafs-panel" : "chart-panel";
  let chartController = null;
  let notamController = null;
  let searchController = null;
  let chartTimer = null;
  let notamTimer = null;
  let searchTimer = null;
  let chartCounts = {};
  let notamCounts = {};
  let chartTruncated = false;
  let notamTruncated = false;
  let timelineAnchor = null;
  let timelineRange = 24;
  const chartCache = new Map();
  const notamCache = new Map();

  const map = new maplibregl.Map({
    container: "map",
    center: [30.8, 36.9],
    zoom: 6,
    minZoom: 2,
    maxZoom: 15,
    hash: true,
    style: {
      version: 8,
      glyphs: "https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf",
      sources: {
        osm: {type:"raster",tiles:["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],tileSize:256,attribution:"© OpenStreetMap contributors"}
      },
      layers: [{id:"osm-base",type:"raster",source:"osm",paint:{"raster-saturation":-0.82,"raster-brightness-min":0.05,"raster-brightness-max":0.42,"raster-contrast":0.22}}]
    }
  });
  window.__YC_MAP__ = map;
  map.addControl(new maplibregl.NavigationControl({showCompass:true}), "bottom-left");
  map.addControl(new maplibregl.ScaleControl({maxWidth:120,unit:"nautical"}), "bottom-left");

  const empty = () => ({type:"FeatureCollection",features:[]});
  const selectedLayers = () => layerInputs.filter(i => i.checked).map(i => i.dataset.navLayer);
  const selectedCharts = () => selectedLayers().filter(x => chartNames.has(x));
  const notamEnabled = () => activePanel === "notam-panel" && selectedLayers().includes("notam");
  const flightsEnabled = () => activePanel === "flights-panel" && Boolean(flightsEnabledInput?.checked);

  function visibility(name) {
    if (name === "notam") return notamEnabled() ? "visible" : "none";
    if (chartNames.has(name)) return activePanel === "chart-panel" && selectedLayers().includes(name) ? "visible" : "none";
    return "visible";
  }

  function setLayerVisibility(name) {
    [`nav-${name}-fill`,`nav-${name}-line`,`nav-${name}-hit`,`nav-${name}-circle`,`nav-${name}-label`,`nav-${name}-approx-line`].forEach(id => {
      if (map.getLayer(id)) map.setLayoutProperty(id,"visibility",visibility(name));
    });
  }

  function addMapLayers() {
    map.addSource("charts", {type:"geojson",data:empty()});
    map.addSource("notams", {type:"geojson",data:empty()});

    map.addLayer({id:"nav-airspace-fill",type:"fill",source:"charts",filter:["all",["==",["get","layer"],"airspace"],["==",["geometry-type"],"Polygon"]],paint:{"fill-color":COLORS.airspace,"fill-opacity":0.055},layout:{visibility:visibility("airspace")}});
    map.addLayer({id:"nav-airspace-line",type:"line",source:"charts",filter:["==",["get","layer"],"airspace"],paint:{"line-color":COLORS.airspace,"line-width":["interpolate",["linear"],["zoom"],5,.8,10,1.5],"line-opacity":.66},layout:{visibility:visibility("airspace")}});

    for (const type of ["airway","sid","star"]) {
      const min = type === "airway" ? 5 : 8;
      map.addLayer({id:`nav-${type}-hit`,type:"line",source:"charts",filter:["==",["get","layer"],type],minzoom:min,paint:{"line-color":COLORS[type],"line-width":["interpolate",["linear"],["zoom"],min,12,12,18],"line-opacity":.01},layout:{visibility:visibility(type)}});
      map.addLayer({id:`nav-${type}-line`,type:"line",source:"charts",filter:["==",["get","layer"],type],minzoom:min,paint:{"line-color":COLORS[type],"line-width":["interpolate",["linear"],["zoom"],min,type==="airway"?1.1:2,12,type==="airway"?2.3:4.1,15,type==="airway"?3:5.4],"line-opacity":type==="airway"?.76:.9},layout:{visibility:visibility(type),"line-cap":"round","line-join":"round"}});
      map.addLayer({id:`nav-${type}-label`,type:"symbol",source:"charts",filter:["==",["get","layer"],type],minzoom:type==="airway"?7:9,layout:{visibility:visibility(type),"symbol-placement":"line","symbol-spacing":type==="airway"?520:380,"text-field":["coalesce",["get","ident"],""],"text-size":["interpolate",["linear"],["zoom"],7,10,12,12,15,14],"text-font":["Noto Sans Regular"],"text-keep-upright":true,"text-optional":true},paint:{"text-color":COLORS[type],"text-halo-color":"#000","text-halo-width":1.4}});
    }

    for (const type of ["airport","navaid","waypoint"]) {
      const min = type === "airport" ? 5 : type === "navaid" ? 6 : 8;
      map.addLayer({id:`nav-${type}-hit`,type:"circle",source:"charts",filter:["==",["get","layer"],type],minzoom:min,paint:{"circle-radius":14,"circle-color":COLORS[type],"circle-opacity":.01},layout:{visibility:visibility(type)}});
      map.addLayer({id:`nav-${type}-circle`,type:"circle",source:"charts",filter:["==",["get","layer"],type],minzoom:min,paint:{"circle-radius":["interpolate",["linear"],["zoom"],min,type==="waypoint"?4:5,12,type==="waypoint"?7:9,15,type==="waypoint"?9:12],"circle-color":COLORS[type],"circle-opacity":.95,"circle-stroke-color":"#000","circle-stroke-width":1.2},layout:{visibility:visibility(type)}});
      map.addLayer({id:`nav-${type}-label`,type:"symbol",source:"charts",filter:["==",["get","layer"],type],minzoom:type==="airport"?6:type==="navaid"?7:9,layout:{visibility:visibility(type),"text-field":["coalesce",["get","ident"],""],"text-size":["interpolate",["linear"],["zoom"],min,10,12,12,15,14],"text-font":["Noto Sans Regular"],"text-offset":[.9,0],"text-anchor":"left","text-optional":true},paint:{"text-color":COLORS[type],"text-halo-color":"#000","text-halo-width":1.4}});
    }

    map.addLayer({id:"nav-notam-fill",type:"fill",source:"notams",filter:["all",["==",["get","layer"],"notam"],["==",["geometry-type"],"Polygon"]],paint:{"fill-color":NOTAM_COLOR,"fill-opacity":["interpolate",["linear"],["zoom"],5,.025,8,.065,11,.14]},layout:{visibility:visibility("notam")}});
    map.addLayer({id:"nav-notam-line",type:"line",source:"notams",filter:["all",["==",["get","layer"],"notam"],["!",["in",["get","geometry_source"],["literal",approximateNotamSources]]]],paint:{"line-color":NOTAM_COLOR,"line-width":["interpolate",["linear"],["zoom"],5,1,8,1.5,11,2.4],"line-opacity":.85},layout:{visibility:visibility("notam")}});
    map.addLayer({id:"nav-notam-approx-line",type:"line",source:"notams",filter:["all",["==",["get","layer"],"notam"],["in",["get","geometry_source"],["literal",approximateNotamSources]]],paint:{"line-color":NOTAM_COLOR,"line-width":1.2,"line-opacity":.65,"line-dasharray":[2,2]},layout:{visibility:visibility("notam")}});
    map.addLayer({id:"nav-notam-circle",type:"circle",source:"notams",filter:["all",["==",["get","layer"],"notam"],["==",["geometry-type"],"Point"]],paint:{"circle-radius":["interpolate",["linear"],["zoom"],5,3.5,11,7],"circle-color":NOTAM_COLOR,"circle-stroke-color":"#000","circle-stroke-width":1},layout:{visibility:visibility("notam")}});
    map.addLayer({id:"nav-notam-label",type:"symbol",source:"notams",filter:["==",["get","layer"],"notam"],minzoom:8,layout:{visibility:visibility("notam"),"text-field":["coalesce",["get","ident"],["get","semantic_class"],"NOTAM"],"text-size":10,"text-font":["Noto Sans Regular"],"text-offset":[.7,.7],"text-optional":true},paint:{"text-color":NOTAM_COLOR,"text-halo-color":"#000","text-halo-width":1.4}});
  }

  const interactive = ["nav-airport-hit","nav-navaid-hit","nav-waypoint-hit","nav-airway-hit","nav-sid-hit","nav-star-hit","nav-airspace-fill","nav-airspace-line","nav-notam-fill","nav-notam-line","nav-notam-approx-line","nav-notam-circle","nav-notam-label"];
  const priority = {airport:0,navaid:1,waypoint:2,sid:3,star:4,airway:5,notam:6,airspace:7};

  function pickFeature(point) {
    const tol = matchMedia("(pointer:coarse)").matches ? 18 : 10;
    const features = map.queryRenderedFeatures([[point.x-tol,point.y-tol],[point.x+tol,point.y+tol]], {layers:interactive.filter(id=>map.getLayer(id))});
    features.sort((a,b)=>(priority[a.properties?.layer]??99)-(priority[b.properties?.layer]??99));
    return features[0] || null;
  }

  function infoRow(label,value) {
    if (value === null || value === undefined || value === "") return "";
    return `<div><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
  }

  async function loadNotamDetail(id,popup) {
    if (!id) return;
    const target=()=>popup?.getElement()?.querySelector("[data-notam-detail]");
    try {
      const response=await fetch(`${API.notam}?action=detail&id=${encodeURIComponent(id)}`,{cache:"no-store"});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok||!data.notam)throw new Error();
      if(target())target().textContent=data.notam.text||"NOTAM metni bulunamadı.";
    } catch (_) { if(target())target().textContent="NOTAM metni yüklenemedi."; }
  }

  function showPopup(feature,lngLat) {
    const p=feature.properties||{};
    const title=p.ident||p.name||p.layer||"Item";
    let rows="";
    let detailId="";
    if(["airport","navaid","waypoint"].includes(p.layer)){
      rows+=infoRow("IATA",p.iata)+infoRow("Şehir",p.city)+infoRow("Elev",p.elevation_ft!=null?`${p.elevation_ft} ft`:null)+infoRow("Frekans",p.frequency)+infoRow("Channel",p.channel)+infoRow("Status",p.status);
    }else if(["airway","sid","star"].includes(p.layer)){
      rows+=infoRow("From",p.from_ident)+infoRow("To",p.to_ident)+infoRow("Lower",p.lower_text)+infoRow("Upper",p.upper_unlimited?"UNL":p.upper_text);
    }else if(p.layer==="airspace"){
      rows+=infoRow("Lower",p.lower_text)+infoRow("Upper",p.upper_unlimited?"UNL":p.upper_text)+infoRow("Type",p.type_code)+infoRow("Usage",p.usage_code)+infoRow("Control",p.control_type);
    }else if(p.layer==="notam"){
      rows+=infoRow("Location",p.icao_location||p.location)+infoRow("Class",p.classification)+infoRow("Valid from",p.effective_start)+infoRow("Valid to",p.effective_end_raw||p.effective_end)+infoRow("Lower",p.lower_limit)+infoRow("Upper",p.upper_limit)+infoRow("Category",p.semantic_class||p.category)+infoRow("Geometry",p.geometry_accuracy);
      detailId=String(p.nms_id||"");
    }
    const html=`<div class="popup"><h3>${esc(title)}</h3><div class="sub">${esc([p.layer,p.name&&p.name!==title?p.name:null].filter(Boolean).join(" · "))}</div><div class="popup-grid">${rows||infoRow("Layer",p.layer)}</div>${detailId?'<div class="notam-text" data-notam-detail>NOTAM metni yükleniyor…</div>':""}</div>`;
    const popup=new maplibregl.Popup({closeButton:true,maxWidth:"360px"}).setLngLat(lngLat).setHTML(html).addTo(map);
    if(detailId)loadNotamDetail(detailId,popup);
  }

  function bounds(pad=0) {
    const b=map.getBounds();let west=b.getWest(),south=b.getSouth(),east=b.getEast(),north=b.getNorth();
    if(pad>0&&west<=east){const lp=(east-west)*pad,ap=(north-south)*pad;west=Math.max(-180,west-lp);east=Math.min(180,east+lp);south=Math.max(-85,south-ap);north=Math.min(85,north+ap);}
    return {west,south,east,north};
  }

  function cacheGet(cache,key,box,maxAge=Infinity){const v=cache.get(key);if(!v||Date.now()-v.at>maxAge)return null;const o=v.box,i=box;if(o.west>o.east||i.west>i.east||i.west<o.west||i.east>o.east||i.south<o.south||i.north>o.north)return null;return v;}
  function cachePut(cache,key,value){cache.set(key,{...value,at:Date.now()});while(cache.size>8)cache.delete(cache.keys().next().value);}

  function updateCounts(){
    const all={...chartCounts,...notamCounts,flight:flightFeatures.length};
    document.querySelectorAll("[data-layer-count]").forEach(el=>el.textContent=Number(all[el.dataset.layerCount]||0).toLocaleString("tr-TR"));
    let count=0,label=" obje";
    if(activePanel==="flights-panel"){count=flightFeatures.length;label=" aircraft";}
    else if(activePanel==="notam-panel"){count=Number(notamCounts.notam||0);label=" NOTAM";}
    else if(activePanel==="wafs-panel"){count=activeWafs().length;label=" layer";}
    else selectedCharts().forEach(name=>count+=Number(chartCounts[name]||0));
    if(featureCount)featureCount.textContent=count.toLocaleString("tr-TR")+label;
    if(notamTimeStatus&&notamEnabled())notamTimeStatus.textContent=`${count.toLocaleString("tr-TR")} NOTAM · ${formatUtc()}`;
  }

  function setStatus(text,state="ok"){
    if(statusText)statusText.textContent=text;
    statusDot?.classList.toggle("ok",state==="ok");statusDot?.classList.toggle("bad",state==="bad");
  }

  function updateZoomHint(){
    if(!zoomHint)return;const z=map.getZoom();
    if(z<5){zoomHint.textContent="Navdata için biraz yaklaş · z5+";zoomHint.style.display="block";}
    else if(chartTruncated||notamTruncated){zoomHint.textContent="Yoğun bölge · biraz daha yaklaş";zoomHint.style.display="block";}
    else if(z<8){zoomHint.textContent="Waypoint + SID/STAR z8+";zoomHint.style.display="block";}
    else zoomHint.style.display="none";
  }

  function scheduleCharts(delay=160,force=false){clearTimeout(chartTimer);chartTimer=setTimeout(()=>loadCharts(force),delay);}
  function scheduleNotams(delay=160,force=false){clearTimeout(notamTimer);notamTimer=setTimeout(()=>loadNotams(force),delay);}

  async function loadCharts(force=false){
    const z=Math.floor(map.getZoom()),layers=selectedCharts(),exact=bounds();
    if(z<5||activePanel!=="chart-panel"||!layers.length){map.getSource("charts")?.setData(empty());chartCounts={};chartTruncated=false;updateCounts();updateZoomHint();return;}
    const key=`${z}|${layers.slice().sort().join(",")}`;const cached=force?null:cacheGet(chartCache,key,exact);
    if(cached){map.getSource("charts")?.setData(cached.data);chartCounts=cached.counts;chartTruncated=cached.truncated;updateCounts();updateZoomHint();setStatus("CHARTS · cache");return;}
    chartController?.abort();chartController=new AbortController();const box=bounds(.3);
    const q=new URLSearchParams({action:"viewport",z:String(z),layers:layers.join(","),west:String(box.west),south:String(box.south),east:String(box.east),north:String(box.north)});
    try{
      const response=await fetch(`${API.navdata}?${q}`,{cache:"default",signal:chartController.signal});const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok||!data.data)throw new Error(data?.error||`HTTP ${response.status}`);
      map.getSource("charts")?.setData(data.data);chartCounts=data.counts||{};chartTruncated=Boolean(data.truncated);cachePut(chartCache,key,{box,data:data.data,counts:chartCounts,truncated:chartTruncated});updateCounts();updateZoomHint();setStatus(chartTruncated?"CHARTS · yoğun görünüm":"CHARTS");
    }catch(error){if(error?.name!=="AbortError"){console.error("[charts]",error);setStatus("CHARTS API hatası","bad");}}
  }

  async function loadNotams(force=false){
    const z=Math.floor(map.getZoom()),exact=bounds();
    if(z<5||!notamEnabled()){map.getSource("notams")?.setData(empty());notamCounts={};notamTruncated=false;updateCounts();updateZoomHint();return;}
    const key=`${z}|${selectedTime().toISOString().slice(0,16)}`;const cached=force?null:cacheGet(notamCache,key,exact,120000);
    if(cached){map.getSource("notams")?.setData(cached.data);notamCounts=cached.counts;notamTruncated=cached.truncated;updateCounts();updateZoomHint();setStatus("NOTAM · cache");return;}
    notamController?.abort();notamController=new AbortController();const box=bounds(.35);
    const q=new URLSearchParams({action:"map",z:String(z),west:String(box.west),south:String(box.south),east:String(box.east),north:String(box.north),at:selectedTime().toISOString()});
    try{
      const response=await fetch(`${API.notam}?${q}`,{cache:"default",signal:notamController.signal});const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok||!data.data)throw new Error(data?.error||`HTTP ${response.status}`);
      map.getSource("notams")?.setData(data.data);notamCounts={notam:Number(data.counts?.notam||0)};notamTruncated=Boolean(data.truncated);cachePut(notamCache,key,{box,data:data.data,counts:notamCounts,truncated:notamTruncated});updateCounts();updateZoomHint();setStatus(notamTruncated?"NOTAM · yoğun görünüm":"NOTAM");
    }catch(error){if(error?.name!=="AbortError"){console.error("[notam]",error);setStatus("NOTAM API hatası","bad");}}
  }

  function selectedTime(){const raw=timeInput?.value||"";const date=raw?new Date(raw+":00Z"):new Date();return Number.isFinite(date.getTime())?date:new Date();}
  function formatUtc(date=selectedTime()){return date.toISOString().slice(0,16).replace("T"," ")+"Z";}
  function setTime(date,reload=true){if(!timeInput)return;const h=Math.max(-timelineRange,Math.min(timelineRange,Math.round((date-timelineAnchor)/3600000)));const value=new Date(timelineAnchor.getTime()+h*3600000);timeInput.value=value.toISOString().slice(0,16);if(timeLabel)timeLabel.textContent=formatUtc(value);if(timelineOffset)timelineOffset.textContent=h>0?`+${h}h`:`${h}h`;if(timeSlider)timeSlider.value=String(h);if(reload){scheduleNotams(0);reloadWafs();}}
  function setTimelineRange(range){timelineRange=range;if(timeSlider){timeSlider.min=String(-range);timeSlider.max=String(range);}if(timelineScale){const vals=range===72?[-72,-48,-24,0,24,48,72]:[-24,-12,-6,0,6,12,24];timelineScale.innerHTML=vals.map(v=>`<span>${v===0?"NOW":(v>0?"+":"")+v+"h"}</span>`).join("");}setTime(selectedTime(),false);}
  function initTimeline(){const now=new Date();now.setUTCSeconds(0,0);timelineAnchor=now;setTime(now,false);timelineToggle?.addEventListener("click",()=>{const collapsed=timelineDock?.classList.toggle("is-collapsed");timelineToggle.setAttribute("aria-expanded",String(!collapsed));});timeSlider?.addEventListener("input",()=>setTime(new Date(timelineAnchor.getTime()+Number(timeSlider.value||0)*3600000),false));timeSlider?.addEventListener("change",()=>setTime(new Date(timelineAnchor.getTime()+Number(timeSlider.value||0)*3600000),true));timeInput?.addEventListener("change",()=>setTime(selectedTime(),true));nowButton?.addEventListener("click",()=>{const n=new Date();n.setUTCSeconds(0,0);timelineAnchor=n;setTime(n,true);});timeStepButtons.forEach(b=>b.addEventListener("click",()=>setTime(new Date(selectedTime().getTime()+Number(b.dataset.timeStep||0)*3600000),true)));}

  const wafsLayers=new Map();const wafsControllers=new Map();let wafsReloadTimer=null;
  const productInput=p=>document.querySelector(`[data-wafs2-product="${p}"]`);const levelInput=p=>document.querySelector(`[data-wafs2-level="${p}"]`);const opacityInput=p=>document.querySelector(`[data-wafs2-opacity="${p}"]`);const metaInput=p=>document.querySelector(`[data-wafs2-meta="${p}"]`);
  function activeWafs(){return activePanel==="wafs-panel"&&wafsEnabled?.checked?wafsProducts.filter(i=>i.checked).map(i=>i.dataset.wafs2Product):[];}
  function removeWafs(product){wafsControllers.get(product)?.abort();wafsControllers.delete(product);const id=`wafsx-${product}`;if(map.getLayer(id))map.removeLayer(id);if(map.getSource(id))map.removeSource(id);const old=wafsLayers.get(product);if(old?.url)URL.revokeObjectURL(old.url);wafsLayers.delete(product);}
  function clearWafs(){Object.keys(WAFS).forEach(removeWafs);}
  function wafsOpacity(product){return Math.max(.1,Math.min(.85,Number(opacityInput(product)?.value||40)/100));}
  function wafsLevel(product){const levels=WAFS[product]?.levels;if(!Array.isArray(levels))return null;const v=Number(levelInput(product)?.value);return levels.includes(v)?v:levels[0];}
  function imageReady(url,signal){return new Promise((resolve,reject)=>{const img=new Image();const abort=()=>{img.src="";reject(new DOMException("Aborted","AbortError"));};signal?.addEventListener("abort",abort,{once:true});img.onload=()=>{signal?.removeEventListener("abort",abort);resolve(img);};img.onerror=()=>reject(new Error("WAFS görseli açılamadı."));img.src=url;});}
  async function loadWafs(product){
    if(!activeWafs().includes(product)){removeWafs(product);return;}removeWafs(product);const controller=new AbortController();wafsControllers.set(product,controller);const meta=metaInput(product);if(meta)meta.textContent="yükleniyor…";
    const level=wafsLevel(product);const q=new URLSearchParams({action:"image",product,fl:String(level??300),valid:selectedTime().toISOString().slice(0,16).replace("T"," ")});
    try{
      const response=await fetch(`${API.wafs}?${q}`,{cache:"no-store",signal:controller.signal});if(!response.ok){const data=await response.json().catch(()=>null);throw new Error(data?.error||`HTTP ${response.status}`);}const blob=await response.blob();const url=URL.createObjectURL(blob);const img=await imageReady(url,controller.signal);const yMax=Math.PI*(img.naturalHeight/img.naturalWidth);const maxLat=180/Math.PI*Math.atan(Math.sinh(yMax));const id=`wafsx-${product}`;
      map.addSource(id,{type:"image",url,coordinates:[[-180,maxLat],[180,maxLat],[180,-maxLat],[-180,-maxLat]]});const paint={"raster-opacity":wafsOpacity(product),"raster-fade-duration":0};if(product==="cbextent")Object.assign(paint,{"raster-contrast":.45,"raster-saturation":-.35,"raster-brightness-min":.08,"raster-brightness-max":1});if(product==="cbtop")Object.assign(paint,{"raster-contrast":.34,"raster-saturation":.5,"raster-brightness-min":.05,"raster-brightness-max":1});const spec={id,type:"raster",source:id,paint};const before=map.getLayer("nav-airspace-fill")?"nav-airspace-fill":undefined;if(before)map.addLayer(spec,before);else map.addLayer(spec);wafsLayers.set(product,{url});if(meta)meta.textContent=`${response.headers.get("x-yc-wafs-valid-utc")?.slice(0,16).replace("T"," ")||formatUtc()} · ${response.headers.get("x-yc-wafs-layer-fl")==="NA"?"WHOLE":"FL"+(response.headers.get("x-yc-wafs-layer-fl")||level??"")}`;
    }catch(error){if(error?.name!=="AbortError"){console.error(`[wafs ${product}]`,error);if(meta)meta.textContent=error?.message||"yüklenemedi";}}
  }
  async function loadAllWafs(){const active=activeWafs();Object.keys(WAFS).forEach(p=>{if(!active.includes(p))removeWafs(p);});if(!active.length){if(wafsStatus)wafsStatus.textContent=wafsEnabled?.checked?"Ürün seçili değil.":"WAFS kapalı.";updateCounts();return;}if(wafsStatus)wafsStatus.textContent="WAFS yükleniyor…";await Promise.all(active.map(loadWafs));if(wafsStatus)wafsStatus.textContent=`${active.filter(p=>wafsLayers.has(p)).length}/${active.length} katman · ${formatUtc()}`;updateCounts();}
  function reloadWafs(){clearTimeout(wafsReloadTimer);wafsReloadTimer=setTimeout(()=>{if(activePanel==="wafs-panel")loadAllWafs();},80);}
  wafsEnabled?.addEventListener("change",loadAllWafs);wafsProducts.forEach(i=>i.addEventListener("change",loadAllWafs));wafsLevels.forEach(i=>i.addEventListener("change",()=>loadWafs(i.dataset.wafs2Level)));wafsOpacities.forEach(i=>i.addEventListener("input",()=>{const p=i.dataset.wafs2Opacity,id=`wafsx-${p}`;if(map.getLayer(id))map.setPaintProperty(id,"raster-opacity",wafsOpacity(p));}));

  const ADSB_REFRESH=2000,ADSB_RENDER_DELAY=4200,ADSB_KEEP=20000;let flightFeatures=[],flightController=null,flightTimer=null,decoder=null,decoderReady=false,sourceOffset=0,haveClock=false,fetchBox=null,animationStarted=false,lastFrame=0;const aircraftMarkers=new Map();
  function readAscii(u8,start,end){let out="";for(let i=start;i<end&&u8[i];i++)out+=String.fromCharCode(u8[i]);return out.trim();}
  function sourceType(code){return ["adsb_icao","adsb_icao_nt","adsr_icao","tisb_icao","adsc","mlat","other","mode_s","adsb_other","adsr_other","tisb_trackfile","tisb_other","mode_ac"][code]||"unknown";}
  function parseBinCraft(uint8){
    const buffer=uint8.buffer.slice(uint8.byteOffset,uint8.byteOffset+uint8.byteLength);if(buffer.byteLength<52)throw new Error("binCraft header too short");const header=new Uint32Array(buffer,0,13),stride=header[2],version=header[10];if(!stride||stride<108||stride>256)throw new Error("Unexpected binCraft stride");const aircraft=[];
    for(let off=stride;off+stride<=buffer.byteLength;off+=stride){const s32=new Int32Array(buffer,off,stride/4),u16=new Uint16Array(buffer,off,stride/2),s16=new Int16Array(buffer,off,stride/2),u8=new Uint8Array(buffer,off,stride);let hex=(s32[0]&((1<<24)-1)).toString(16).padStart(6,"0");if(s32[0]&(1<<24))hex="~"+hex;let seen,seenPos;if(version>=20240218){seen=s32[1]/10;seenPos=s32[27]/10}else{seenPos=u16[2]/10;seen=u16[3]/10}let lon=s32[2]/1e6,lat=s32[3]/1e6,baroRate=s16[8]*8,geomRate=s16[9]*8,alt=s16[10]*25,altGeom=s16[11]*25,navAltitudeMcp=u16[12]*4,navAltitudeFms=u16[13]*4,navQnh=s16[14]/10,navHeading=s16[15]/90,gs=s16[17]/10,mach=s16[18]/1000,roll=s16[19]/100,track=s16[20]/90,magHeading=s16[22]/90,trueHeading=s16[23]/90,windDir=s16[24],windSpeed=s16[25],oat=s16[26],tat=s16[27],tas=u16[28],ias=u16[29];const v1=u8[73],v2=u8[74],v3=u8[75],v4=u8[76],v5=u8[77];const flight=(v1&8)?readAscii(u8,78,86):"",typeCode=readAscii(u8,88,92),registration=readAscii(u8,92,104),category=u8[64]?u8[64].toString(16).toUpperCase():"";if(!(v1&16))alt=null;if(!(v1&32))altGeom=null;if(!(v1&64)){lat=null;lon=null;seenPos=null}if(!(v1&128))gs=null;if(!(v2&1))ias=null;if(!(v2&2))tas=null;if(!(v2&4))mach=null;if(!(v2&8))track=null;if(!(v2&32))roll=null;if(!(v2&64))magHeading=null;if(!(v2&128))trueHeading=null;if(!(v3&1))baroRate=null;if(!(v3&2))geomRate=null;let squawk=(v4&4)?u16[16].toString(16).padStart(4,"0"):null;if(!(v4&32))navQnh=null;if(!(v4&64))navAltitudeMcp=null;if(!(v4&128))navAltitudeFms=null;if(!(v5&2))navHeading=null;if(!(v5&16)){windDir=null;windSpeed=null}if(!(v5&32)){oat=null;tat=null}if((u8[68]&15)===1)alt="ground";if(lat==null||lon==null||Math.abs(lat)>90||Math.abs(lon)>180)continue;aircraft.push({hex,flight,registration,typeCode,category,lat,lon,alt,altGeom,gs,ias,tas,mach,roll,track,magHeading,trueHeading,heading:track??trueHeading??magHeading??0,baroRate,geomRate,squawk,navQnh,navAltitudeMcp,navAltitudeFms,navHeading,windDir,windSpeed,oat,tat,type:sourceType((u8[67]&240)>>4),seen,seenPos});}
    return {now:header[0]/1000+header[1]*4294967.296,aircraft};
  }
  function aircraftElement(){const el=document.createElement("div");el.className="aircraft-marker";el.innerHTML='<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 3 C29.8 3 28.7 5.4 28.4 8.4 L26.8 25.2 L7 34.4 L7 39 L27.8 34.4 L28.2 49.5 L20.2 55.5 L20.2 59 L32 56 L43.8 59 L43.8 55.5 L35.8 49.5 L36.2 34.4 L57 39 L57 34.4 L37.2 25.2 L35.6 8.4 C35.3 5.4 34.2 3 32 3 Z" fill="#fff" stroke="#000" stroke-width="1.6"/></svg>';return el;}
  function shortest(a,b){return a+(((b-a+540)%360)-180);}function lerp(a,b,t){return a+(b-a)*t;}
  function ensureMarker(ac){let item=aircraftMarkers.get(ac.hex);if(item)return item;const el=aircraftElement(),marker=new maplibregl.Marker({element:el,rotationAlignment:"map",pitchAlignment:"map"}).setLngLat([ac.lon,ac.lat]).setRotation(ac.heading||0).addTo(map);item={el,marker,data:ac,samples:[],lastSeen:Date.now()};aircraftMarkers.set(ac.hex,item);return item;}
  function clearAircraft(){aircraftMarkers.forEach(i=>i.marker.remove());aircraftMarkers.clear();flightFeatures=[];haveClock=false;updateCounts();}
  function ingest(list,sourceNow){const seen=new Set();list.forEach(ac=>{seen.add(ac.hex);const item=ensureMarker(ac);item.data=ac;item.lastSeen=Date.now();const t=sourceNow-(Number.isFinite(ac.seenPos)?Math.max(0,ac.seenPos*1000):0);const last=item.samples.at(-1);if(!last||Math.abs(last.t-t)>50||Math.abs(last.lon-ac.lon)>1e-9||Math.abs(last.lat-ac.lat)>1e-9)item.samples.push({t,lon:ac.lon,lat:ac.lat,heading:ac.heading||0,data:ac});while(item.samples.length>2&&item.samples[1].t<sourceNow-ADSB_KEEP)item.samples.shift();});for(const [hex,item]of aircraftMarkers){if(!seen.has(hex)&&Date.now()-item.lastSeen>15000){item.marker.remove();aircraftMarkers.delete(hex);}}}
  function renderAircraft(now){if(!flightsEnabled()){aircraftMarkers.forEach(i=>i.el.style.display="none");return;}if(!haveClock)return;const target=now-sourceOffset-ADSB_RENDER_DELAY;aircraftMarkers.forEach(item=>{let before=null,after=null;for(const s of item.samples){if(s.t<=target)before=s;if(s.t>=target){after=s;break;}}if(!before){item.el.style.display="none";return;}let lon=before.lon,lat=before.lat,heading=before.heading;if(after&&after!==before&&after.t>before.t){const t=Math.max(0,Math.min(1,(target-before.t)/(after.t-before.t)));lon=lerp(before.lon,after.lon,t);lat=lerp(before.lat,after.lat,t);heading=((lerp(before.heading,shortest(before.heading,after.heading),t)%360)+360)%360;}item.el.style.display="";item.marker.setLngLat([lon,lat]);item.marker.setRotation(heading||0);});}
  function animation(ts){if(ts-lastFrame>32){lastFrame=ts;renderAircraft(Date.now());}requestAnimationFrame(animation);}function startAnimation(){if(animationStarted)return;animationStarted=true;requestAnimationFrame(animation);}
  function currentBox(){const b=map.getBounds();const s=b.getSouth(),n=b.getNorth(),w=b.getWest(),e=b.getEast(),lp=(e-w)*.35,ap=(n-s)*.35;return {south:Math.max(-90,s-ap),north:Math.min(90,n+ap),west:Math.max(-180,w-lp),east:Math.min(180,e+lp)};}
  async function initDecoder(){if(decoderReady)return;if(!window.zstddec?.ZSTDDecoder)throw new Error("zstd decoder yüklenemedi");decoder=new window.zstddec.ZSTDDecoder();await decoder.init();decoderReady=true;}
  function flightHealth(state){if(!flightsStatus)return;const label=flightsStatus.querySelector(".flight-health-label");flightsStatus.className="flight-health"+(state?` is-${state}`:"");flightsStatus.hidden=state==="off";if(label)label.textContent=state==="ok"?"Güncel":state==="error"?"Hata":"Yükleniyor";}
  async function loadFlights(){clearTimeout(flightTimer);if(!flightsEnabled())return;if(map.getZoom()<4.2){flightTimer=setTimeout(loadFlights,1200);return;}try{await initDecoder();}catch(_){flightHealth("error");return;}flightController?.abort();flightController=new AbortController();const b=currentBox(),box=[b.south,b.north,b.west,b.east].map(v=>v.toFixed(6)).join(",");flightHealth(haveClock?"ok":"loading");try{const response=await fetch(`${API.adsb}?action=feed&box=${encodeURIComponent(box)}`,{cache:"no-store",signal:flightController.signal});if(!response.ok){const d=await response.json().catch(()=>null);throw new Error(d?.error||`HTTP ${response.status}`);}const parsed=parseBinCraft(decoder.decode(new Uint8Array(await response.arrayBuffer())));const sourceNow=parsed.now*1000,measured=Date.now()-sourceNow;sourceOffset=haveClock?sourceOffset*.85+measured*.15:measured;haveClock=true;ingest(parsed.aircraft,sourceNow);flightFeatures=parsed.aircraft;setStatus("LIVE");flightHealth("ok");updateCounts();}catch(error){if(error?.name!=="AbortError"){console.error("[adsb]",error);setStatus("ADS-B ERROR","bad");flightHealth("error");}}finally{if(flightsEnabled())flightTimer=setTimeout(loadFlights,ADSB_REFRESH);}}
  flightsEnabledInput?.addEventListener("change",()=>{if(!flightsEnabledInput.checked){flightController?.abort();clearTimeout(flightTimer);clearAircraft();flightHealth("off");}else if(activePanel==="flights-panel")loadFlights();});

  function localAircraftSearch(q){const n=String(q||"").toUpperCase().replace(/[^A-Z0-9]/g,"");if(n.length<2)return[];return flightFeatures.map(ac=>{const values=[ac.registration,ac.flight,ac.hex,ac.typeCode].map(v=>String(v||"").toUpperCase().replace(/[^A-Z0-9]/g,""));let score=99;values.forEach(v=>{if(v===n)score=Math.min(score,0);else if(v.startsWith(n))score=Math.min(score,1);else if(v.includes(n))score=Math.min(score,2);});return score===99?null:{kind:"aircraft",ident:ac.registration||ac.flight||ac.hex,name:[ac.flight,ac.typeCode].filter(Boolean).join(" · "),lon:ac.lon,lat:ac.lat,score};}).filter(Boolean).sort((a,b)=>a.score-b.score).slice(0,12);}
  function renderSearch(items){if(!searchResults)return;if(!items.length){searchResults.innerHTML='<div style="padding:9px;color:#999;font-size:10px">Sonuç bulunamadı.</div>';searchResults.classList.add("open");return;}searchResults.innerHTML=items.map((item,i)=>`<button class="search-result" type="button" data-i="${i}"><span><strong>${esc(item.ident||item.name||"—")}</strong><small>${esc(item.name||"")}</small></span><span class="badge">${esc(item.kind)}</span></button>`).join("");searchResults.classList.add("open");searchResults.querySelectorAll("[data-i]").forEach(button=>button.addEventListener("click",()=>{const item=items[Number(button.dataset.i)];searchResults.classList.remove("open");if(Number.isFinite(item?.lon)&&Number.isFinite(item?.lat))map.flyTo({center:[item.lon,item.lat],zoom:Math.max(map.getZoom(),item.kind==="waypoint"?10:8),essential:true});}));}
  async function search(q){const query=q.trim();if(query.length<2){searchResults?.classList.remove("open");return;}searchController?.abort();searchController=new AbortController();const local=localAircraftSearch(query);renderSearch(local);try{const response=await fetch(`${API.navdata}?action=search&q=${encodeURIComponent(query)}`,{cache:"no-store",signal:searchController.signal});const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)throw new Error();renderSearch([...local,...(data.results||[])]);}catch(error){if(error?.name!=="AbortError")renderSearch(local);}}
  searchInput?.addEventListener("input",()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>search(searchInput.value),220);});document.addEventListener("click",e=>{if(!e.target.closest(".map-search"))searchResults?.classList.remove("open");});

  function chartInputs(){return layerInputs.filter(i=>chartNames.has(i.dataset.navLayer));}
  function syncChartMaster(){const inputs=chartInputs(),on=inputs.filter(i=>i.checked).length;if(!chartsToggleAll)return;chartsToggleAll.checked=on===inputs.length;chartsToggleAll.indeterminate=on>0&&on<inputs.length;}
  chartsToggleAll?.addEventListener("change",()=>{chartInputs().forEach(i=>{i.checked=chartsToggleAll.checked;setLayerVisibility(i.dataset.navLayer);});syncChartMaster();scheduleCharts(0);updateCounts();});
  layerInputs.forEach(input=>input.addEventListener("change",()=>{setLayerVisibility(input.dataset.navLayer);syncChartMaster();if(input.dataset.navLayer==="notam")scheduleNotams(0);else scheduleCharts(0);updateCounts();}));

  function setMode(target,allowToggle=true){
    const panel=$(target),button=modeButtons.find(b=>b.dataset.panelTarget===target),wasOpen=panel?.classList.contains("open"),changed=activePanel!==target;
    panels.forEach(p=>p.classList.remove("open"));modeButtons.forEach(b=>b.classList.toggle("active",b===button));activePanel=target;
    if(changed&&target==="flights-panel"&&flightsEnabledInput)flightsEnabledInput.checked=true;
    if(changed&&target==="notam-panel"){const n=layerInputs.find(i=>i.dataset.navLayer==="notam");if(n)n.checked=true;}
    if(changed&&target==="wafs-panel"&&wafsEnabled)wafsEnabled.checked=true;
    const mode=target==="flights-panel"?"flights":target==="notam-panel"?"notam":target==="wafs-panel"?"wafs":"charts";const url=new URL(location.href);url.searchParams.set("mode",mode);history.replaceState(null,"",url);
    chartNames.forEach(setLayerVisibility);setLayerVisibility("notam");timelineDock?.classList.toggle("mode-hidden",!["notam-panel","wafs-panel"].includes(target));setTimelineRange(["notam-panel","wafs-panel"].includes(target)?72:24);
    if(target==="chart-panel")scheduleCharts(0);else{chartController?.abort();map.getSource("charts")?.setData(empty());chartCounts={};}
    if(target==="notam-panel")scheduleNotams(0);else{notamController?.abort();map.getSource("notams")?.setData(empty());notamCounts={};}
    if(target==="wafs-panel")loadAllWafs();else clearWafs();
    if(target==="flights-panel"&&flightsEnabled())loadFlights();else{flightController?.abort();clearTimeout(flightTimer);renderAircraft(Date.now());}
    setStatus(target==="flights-panel"?(haveClock?"LIVE":"FLIGHTS"):target==="notam-panel"?"NOTAM":target==="wafs-panel"?"WAFS":"CHARTS");updateCounts();updateZoomHint();
    if(!(allowToggle&&wasOpen&&!changed))panel?.classList.add("open");
  }
  modeButtons.forEach(button=>button.addEventListener("click",()=>setMode(button.dataset.panelTarget,true)));closeButtons.forEach(button=>button.addEventListener("click",()=>button.closest(".tool-panel")?.classList.remove("open")));

  initTimeline();
  map.on("load",async()=>{addMapLayers();syncChartMaster();boot?.classList.add("hidden");setMode(activePanel,false);startAnimation();try{await initDecoder();}catch(error){console.warn(error);}updateZoomHint();});
  map.on("moveend",()=>{if(activePanel==="chart-panel")scheduleCharts();if(activePanel==="notam-panel")scheduleNotams();if(activePanel==="flights-panel"&&flightsEnabled())loadFlights();});
  map.on("zoomend",updateZoomHint);
  map.on("mousemove",event=>{map.getCanvas().style.cursor=pickFeature(event.point)?"pointer":"";});
  map.on("click",event=>{const feature=pickFeature(event.point);if(feature)showPopup(feature,event.lngLat);});
  setInterval(()=>{if(map.loaded()&&notamEnabled())scheduleNotams(0,true);},300000);

  if (bootDetail) bootDetail.textContent = "Navdata hazırlanıyor…";
})();
