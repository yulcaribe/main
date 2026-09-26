(() => {
  "use strict";

  window.YCCharts = {
    create(ctx) {
      const { map, api, esc, popup, setStatus, bbox } = ctx;
      const SOURCE = "yc-charts";
      const LAYERS = ["airport","navaid","waypoint","airway","sid","star","airspace"];
      const palette = {
        airport:"#7ee7ff", navaid:"#ffc76b", waypoint:"#d6e1e7",
        airway:"#5fdbe8", sid:"#70e8a7", star:"#bc9cff", airspace:"#ff5f6d"
      };
      let active = false;
      let controller = null;
      const inputs = [...document.querySelectorAll("[data-nav-layer]")].filter(x => LAYERS.includes(x.dataset.navLayer));
      const selected = () => inputs.filter(x => x.checked).map(x => x.dataset.navLayer);
      const visible = name => active && selected().includes(name) ? "visible" : "none";

      function setVisibility(name) {
        ["fill","line","hit","circle","label"].forEach(suffix => {
          const id = `nav-${name}-${suffix}`;
          if (map.getLayer(id)) map.setLayoutProperty(id, "visibility", visible(name));
        });
      }

      function addLayers() {
        if (map.getSource(SOURCE)) return;
        map.addSource(SOURCE, { type:"geojson", data:{type:"FeatureCollection",features:[]} });

        map.addLayer({
          id:"nav-airspace-fill", type:"fill", source:SOURCE,
          filter:["==",["get","layer"],"airspace"],
          paint:{"fill-color":palette.airspace,"fill-opacity":["interpolate",["linear"],["zoom"],5,.08,8,.12,12,.18]},
          layout:{visibility:visible("airspace")}
        });
        map.addLayer({
          id:"nav-airspace-line", type:"line", source:SOURCE,
          filter:["==",["get","layer"],"airspace"],
          paint:{
            "line-color":palette.airspace,
            "line-width":["interpolate",["linear"],["zoom"],5,1.3,8,2,12,2.8],
            "line-opacity":["interpolate",["linear"],["zoom"],5,.82,10,.96]
          },
          layout:{visibility:visible("airspace")}
        });

        for (const type of ["airway","sid","star"]) {
          const minZoom = type === "airway" ? 5 : 8;
          map.addLayer({
            id:`nav-${type}-hit`, type:"line", source:SOURCE,
            filter:["==",["get","layer"],type], minzoom:minZoom,
            paint:{"line-color":palette[type],"line-width":type==="airway"?14:18,"line-opacity":.01},
            layout:{visibility:visible(type)}
          });
          map.addLayer({
            id:`nav-${type}-line`, type:"line", source:SOURCE,
            filter:["==",["get","layer"],type], minzoom:minZoom,
            paint:{
              "line-color":palette[type],
              "line-width":["interpolate",["linear"],["zoom"],minZoom,type==="airway"?1.2:2.2,10,type==="airway"?1.9:3.2,15,type==="airway"?3:5],
              "line-opacity":type==="airway"?.8:.92
            },
            layout:{visibility:visible(type),"line-cap":"round","line-join":"round"}
          });
          map.addLayer({
            id:`nav-${type}-label`, type:"symbol", source:SOURCE,
            filter:["==",["get","layer"],type], minzoom:type==="airway"?7:9,
            layout:{
              visibility:visible(type), "symbol-placement":"line", "symbol-spacing":type==="airway"?500:360,
              "text-field":["coalesce",["get","ident"],""], "text-font":["Noto Sans Regular"],
              "text-size":["interpolate",["linear"],["zoom"],7,10,12,12,15,14], "text-keep-upright":true, "text-optional":true
            },
            paint:{"text-color":palette[type],"text-halo-color":"#06111a","text-halo-width":1.5}
          });
        }

        for (const type of ["airport","navaid","waypoint"]) {
          const minZoom = type === "airport" ? 5 : type === "navaid" ? 6 : 8;
          const labelZoom = type === "airport" ? 6 : type === "navaid" ? 7 : 9;
          map.addLayer({
            id:`nav-${type}-hit`, type:"circle", source:SOURCE,
            filter:["==",["get","layer"],type], minzoom:minZoom,
            paint:{"circle-radius":["interpolate",["linear"],["zoom"],minZoom,13,10,16,15,21],"circle-color":palette[type],"circle-opacity":.01,"circle-stroke-opacity":0},
            layout:{visibility:visible(type)}
          });
          map.addLayer({
            id:`nav-${type}-circle`, type:"circle", source:SOURCE,
            filter:["==",["get","layer"],type], minzoom:minZoom,
            paint:{
              "circle-radius":["interpolate",["linear"],["zoom"],minZoom,type==="waypoint"?3.8:type==="navaid"?5:6,12,type==="waypoint"?7:type==="navaid"?8.5:10,15,type==="waypoint"?9:type==="navaid"?11:13],
              "circle-color":palette[type],"circle-opacity":.97,"circle-stroke-color":"#06111a","circle-stroke-width":1.4
            },
            layout:{visibility:visible(type)}
          });
          map.addLayer({
            id:`nav-${type}-label`, type:"symbol", source:SOURCE,
            filter:["==",["get","layer"],type], minzoom:labelZoom,
            layout:{
              visibility:visible(type), "text-field":["coalesce",["get","ident"],""], "text-font":["Noto Sans Regular"],
              "text-size":["interpolate",["linear"],["zoom"],labelZoom,type==="waypoint"?9.5:10.5,12,type==="waypoint"?12:13,15,type==="waypoint"?14:15],
              "text-offset":[.9,0], "text-anchor":"left", "text-optional":true
            },
            paint:{"text-color":palette[type],"text-halo-color":"#06111a","text-halo-width":1.6}
          });
        }

        const interactive = [
          "nav-airport-hit","nav-navaid-hit","nav-waypoint-hit",
          "nav-airway-hit","nav-sid-hit","nav-star-hit","nav-airspace-fill","nav-airspace-line"
        ];
        const tolerance = matchMedia("(pointer: coarse)").matches ? 18 : 10;

        function pick(point) {
          if (!active) return null;
          const box = [[point.x-tolerance,point.y-tolerance],[point.x+tolerance,point.y+tolerance]];
          const features = map.queryRenderedFeatures(box,{layers:interactive.filter(id=>map.getLayer(id))});
          if (!features.length) return null;
          const priority = {airport:0,navaid:1,waypoint:2,sid:3,star:4,airway:5,airspace:6};
          features.sort((a,b)=>(priority[a.properties?.layer]??99)-(priority[b.properties?.layer]??99));
          return features[0];
        }

        map.on("mousemove", e => { if (active) map.getCanvas().style.cursor = pick(e.point) ? "pointer" : ""; });
        map.on("click", e => {
          const f = pick(e.point); if (!f) return;
          const p = f.properties || {};
          const title = p.ident || p.name || p.layer || "Navdata";
          const rows = [];
          const push = (k,v) => { if (v!==null && v!==undefined && v!=="") rows.push(`<div><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`); };
          if (["airport","navaid","waypoint"].includes(p.layer)) {
            push("Name",p.name); push("IATA",p.iata); push("City",p.city); push("Frequency",p.frequency); push("Channel",p.channel); push("Elevation",p.elevation_ft!=null?`${p.elevation_ft} ft`:null); push("Status",p.status);
          } else if (["airway","sid","star"].includes(p.layer)) {
            push("From",p.from_ident); push("To",p.to_ident); push("Lower",p.lower_text); push("Upper",p.upper_unlimited?"UNL":p.upper_text);
          } else if (p.layer === "airspace") {
            push("Name",p.name); push("Type",p.type_code); push("Usage",p.usage_code); push("Control",p.control_type); push("Lower",p.lower_text); push("Upper",p.upper_unlimited?"UNL":p.upper_text);
          }
          popup(e.lngLat, title, p.layer || "CHART", rows.join(""));
        });
      }

      async function load() {
        if (!active) return;
        const layers = selected();
        if (map.getZoom() < 5 || !layers.length) {
          map.getSource(SOURCE)?.setData({type:"FeatureCollection",features:[]});
          return;
        }
        controller?.abort(); controller = new AbortController();
        const b = bbox(.32);
        const q = new URLSearchParams({action:"viewport",z:String(Math.floor(map.getZoom())),layers:layers.join(","),...b});
        try {
          const r = await fetch(`${api.navdata}?${q}`,{cache:"default",signal:controller.signal});
          const d = await r.json().catch(()=>null);
          if (!r.ok || !d?.ok || !d?.data) throw new Error(d?.error || `HTTP ${r.status}`);
          map.getSource(SOURCE)?.setData(d.data);
          setStatus(d.truncated ? "CHARTS · yoğun görünüm" : "CHARTS");
        } catch (e) {
          if (e?.name !== "AbortError") setStatus("CHARTS ERROR", true);
        }
      }

      inputs.forEach(input => input.addEventListener("change",()=>{setVisibility(input.dataset.navLayer);load();}));
      document.getElementById("charts-toggle-all")?.addEventListener("change",e=>{
        inputs.forEach(input=>{input.checked=e.target.checked;setVisibility(input.dataset.navLayer);}); load();
      });

      return {
        init(){ addLayers(); },
        setActive(value){ active=Boolean(value); LAYERS.forEach(setVisibility); if(active) load(); else map.getCanvas().style.cursor=""; },
        refresh(){ load(); },
        searchLocal(){ return []; }
      };
    }
  };
})();