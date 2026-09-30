(() => {
  "use strict";

  window.YCNotam = {
    create(ctx) {
      const { map, api, esc, popup, setStatus, bbox, getTimeIso } = ctx;
      const SOURCE = "yc-notam";
      const color = [
        "match",["get","display_group"],
        "AERIAL_SPORT","#00c5b9",
        "RESTRICTED_AIRSPACE","#ff5f6d",
        "AERIAL_SURVEY","#ff9b4a",
        "TRAINING_MILITARY","#d9e1e5",
        "OTHER","#4f8cff",
        "#ffd35f"
      ];
      const approx = ["qline-coordinate","airport-location"];
      let active = false;
      let controller = null;
      let requestSeq = 0;
      const toggle = document.querySelector('[data-nav-layer="notam"]');
      const enabled = () => active && Boolean(toggle?.checked);
      const note = () => document.getElementById("notam-time-status");
      const statusDot = () => document.getElementById("status-dot");

      function selectedLabel() {
        return getTimeIso().slice(0,16).replace("T"," ") + "Z";
      }

      function setNote(text) {
        const el = note();
        if (el) el.textContent = text;
      }

      function setLoading(value) {
        statusDot()?.classList.toggle("loading", Boolean(value));
      }

      function visibility() { return enabled() ? "visible" : "none"; }
      function syncVisibility() {
        ["nav-notam-fill","nav-notam-line","nav-notam-approx-line","nav-notam-circle","nav-notam-label"].forEach(id=>{
          if(map.getLayer(id)) map.setLayoutProperty(id,"visibility",visibility());
        });
      }

      function addLayers() {
        if (map.getSource(SOURCE)) return;
        map.addSource(SOURCE,{type:"geojson",data:{type:"FeatureCollection",features:[]}});
        map.addLayer({
          id:"nav-notam-fill",type:"fill",source:SOURCE,
          filter:["all",["==",["get","layer"],"notam"],["==",["geometry-type"],"Polygon"]],
          paint:{"fill-color":color,"fill-opacity":["interpolate",["linear"],["zoom"],5,.05,8,.10,11,.17]},
          layout:{visibility:visibility()}
        });
        map.addLayer({
          id:"nav-notam-line",type:"line",source:SOURCE,
          filter:["all",["==",["get","layer"],"notam"],["!",["in",["get","geometry_source"],["literal",approx]]]],
          paint:{"line-color":color,"line-width":["interpolate",["linear"],["zoom"],5,1.2,8,1.8,11,2.7],"line-opacity":["interpolate",["linear"],["zoom"],5,.72,9,.96]},
          layout:{visibility:visibility()}
        });
        map.addLayer({
          id:"nav-notam-approx-line",type:"line",source:SOURCE,
          filter:["all",["==",["get","layer"],"notam"],["in",["get","geometry_source"],["literal",approx]]],
          paint:{"line-color":color,"line-width":["interpolate",["linear"],["zoom"],5,1,8,1.5,11,2.2],"line-opacity":.76,"line-dasharray":[2,1.8]},
          layout:{visibility:visibility()}
        });
        map.addLayer({
          id:"nav-notam-circle",type:"circle",source:SOURCE,
          filter:["all",["==",["get","layer"],"notam"],["==",["geometry-type"],"Point"]],
          paint:{"circle-radius":["interpolate",["linear"],["zoom"],5,4,8,5.5,11,7.5],"circle-color":color,"circle-opacity":.96,"circle-stroke-color":"#06111a","circle-stroke-width":1.4},
          layout:{visibility:visibility()}
        });
        map.addLayer({
          id:"nav-notam-label",type:"symbol",source:SOURCE,filter:["==",["get","layer"],"notam"],minzoom:8,
          layout:{visibility:visibility(),"text-field":["step",["zoom"],["coalesce",["get","semantic_class"],["get","category"],"NOTAM"],10,["coalesce",["get","ident"],"NOTAM"]],"text-size":["interpolate",["linear"],["zoom"],8,8.5,10,10,13,11.5],"text-font":["Noto Sans Regular"],"text-offset":[.75,.75],"text-optional":true},
          paint:{"text-color":color,"text-halo-color":"#06111a","text-halo-width":1.6}
        });

        const ids = ["nav-notam-fill","nav-notam-line","nav-notam-approx-line","nav-notam-circle","nav-notam-label"];
        const tolerance = matchMedia("(pointer: coarse)").matches ? 18 : 10;
        function pick(point) {
          if(!enabled()) return null;
          const area=[[point.x-tolerance,point.y-tolerance],[point.x+tolerance,point.y+tolerance]];
          return map.queryRenderedFeatures(area,{layers:ids.filter(id=>map.getLayer(id))})[0] || null;
        }
        map.on("mousemove",e=>{if(enabled())map.getCanvas().style.cursor=pick(e.point)?"pointer":"";});
        map.on("click",e=>{const feature=pick(e.point);if(feature)showCard(feature,e.lngLat);});
      }

      function infoRow(label,value){
        if(value===null||value===undefined||value==="")return "";
        return `<div><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
      }

      async function showCard(feature,lngLat){
        const p=feature.properties||{};
        let rows="";
        rows+=infoRow("Location",p.icao_location||p.location);
        rows+=infoRow("Class",p.classification);
        rows+=infoRow("Selected UTC",selectedLabel());
        rows+=infoRow("Valid from",p.effective_start);
        rows+=infoRow("Valid to",p.effective_end_raw||p.effective_end);
        rows+=infoRow("Schedule",p.schedule);
        rows+=infoRow("Schedule state",p.schedule_state ? String(p.schedule_state).toUpperCase() : null);
        rows+=infoRow("Lower",p.lower_limit);
        rows+=infoRow("Upper",p.upper_limit);
        rows+=infoRow("Type",p.semantic_class||p.category);
        rows+=infoRow("Map source",p.geometry_accuracy||p.geometry_source);
        const html=`<div class="popup-grid">${rows}</div><div class="notam-text" data-notam-detail>Loading NOTAM text…</div>`;
        const pop=popup(lngLat,p.ident||"NOTAM","FAA.GOV NOTAM SERVICE",html,{maxWidth:"420px",raw:true});
        if(!p.nms_id)return;
        try{
          const q=new URLSearchParams({action:"detail",id:String(p.nms_id),at:getTimeIso()});
          const r=await fetch(`${api.notam}?${q}`,{cache:"no-store"});
          const d=await r.json().catch(()=>null);
          const target=pop?.getElement()?.querySelector("[data-notam-detail]");
          if(!target)return;
          if(!r.ok||!d?.ok||!d?.notam){target.textContent="NOTAM text could not be loaded.";return;}
          target.textContent=d.notam.text||"NOTAM text is unavailable.";
        }catch{
          const target=pop?.getElement()?.querySelector("[data-notam-detail]");
          if(target)target.textContent="NOTAM text could not be loaded.";
        }
      }

      function renderCoverage(payload) {
        const unresolved = Number(payload?.referenceResolution?.unresolved) || 0;
        const note = (message) => setNote(message + (unresolved > 0
          ? ` ${unresolved} unresolved cancellation/replacement references across the dataset; related NOTAMs may still appear.` : ""));
        const selected = payload?.atUtc
          ? String(payload.atUtc).slice(0,16).replace("T"," ") + "Z"
          : selectedLabel();
        const coverage = payload?.coverage || {};
        if (coverage.mode === "future") {
          note(`Future view · ${selected} · currently published NOTAMs only.`);
          return;
        }
        if (coverage.mode === "historical") {
          if (coverage.complete === false) {
            const from = coverage.completeFromUtc
              ? String(coverage.completeFromUtc).slice(0,16).replace("T"," ") + "Z"
              : null;
            note(from
              ? `Historical view · ${selected} · coverage may be incomplete before ${from}.`
              : `Historical view · ${selected} · coverage may be incomplete.`);
          } else {
            note(`Historical view · ${selected} · validity, cancellation, replacement and schedule evaluated for this UTC.`);
          }
          return;
        }
        note(`${selected} · valid and schedule-active NOTAM geometries.`);
      }

      async function load(){
        if(!enabled()){
          controller?.abort();
          setLoading(false);
          map.getSource(SOURCE)?.setData({type:"FeatureCollection",features:[]});
          return;
        }
        if(map.getZoom()<5){
          setLoading(false);
          setNote(`${selectedLabel()} · zoom in to load NOTAM geometry.`);
          return;
        }
        controller?.abort();controller=new AbortController();
        const seq=++requestSeq;
        const selected=selectedLabel();
        setStatus("NOTAM");
        setLoading(true);
        setNote(`Updating NOTAM view for ${selected}…`);
        const b=bbox(.38);
        const q=new URLSearchParams({action:"map",z:String(Math.floor(map.getZoom())),at:getTimeIso(),...b});
        try{
          const r=await fetch(`${api.notam}?${q}`,{cache:"default",signal:controller.signal});
          const d=await r.json().catch(()=>null);
          if(seq!==requestSeq)return;
          if(!r.ok||!d?.ok||!d?.data)throw new Error(d?.error||`HTTP ${r.status}`);
          map.getSource(SOURCE)?.setData(d.data);
          setLoading(false);
          const skipped=Number(d.schedule?.outside||d.schedule?.outsideSchedule||0);
          const unknown=Number(d.schedule?.unknown||0);
          setStatus("NOTAM");
          renderCoverage(d);
          if(skipped>0||unknown>0){
            const base=note()?.textContent||"";
            const extra=[skipped>0?`${skipped} outside schedule`:null,unknown>0?`${unknown} schedule unparsed`:null].filter(Boolean).join(" · ");
            if(extra)setNote(`${base} ${extra}.`);
          }
        }catch(e){
          if(e?.name==="AbortError")return;
          if(seq!==requestSeq)return;
          setLoading(false);
          setStatus("NOTAM ERROR",true);
          setNote(`NOTAM view could not be updated for ${selected}.`);
        }
      }

      toggle?.addEventListener("change",()=>{syncVisibility();load();});
      return {
        init(){addLayers();},
        setActive(value){
          active=Boolean(value);
          if(active&&toggle)toggle.checked=true;
          syncVisibility();
          if(active)load();
          else{
            controller?.abort();
            setLoading(false);
            map.getCanvas().style.cursor="";
          }
        },
        refresh(){load();},
        searchLocal(){return[];}
      };
    }
  };
})();
