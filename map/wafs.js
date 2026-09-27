(() => {
  "use strict";

  window.YCWafs = {
    create(ctx) {
      const { map, api, setStatus, getTimeIso } = ctx;
      let active = false, loadVersion = 0;
      const overlays = new Map(), pending = new Map();
      const enabledInput = document.getElementById("wafs-enabled");
      const productInputs = [...document.querySelectorAll("[data-wafs2-product]")];
      const status = document.getElementById("wafs-status");
      const enabled = () => active && Boolean(enabledInput?.checked);
      const level = product => Number(document.querySelector(`[data-wafs2-level="${product}"]`)?.value || 300);
      const opacity = product => Math.max(.1,Math.min(.85,Number(document.querySelector(`[data-wafs2-opacity="${product}"]`)?.value || 40)/100));
      const sourceId = product => `yc-wafs-${product}`;
      const selected = product => enabled() && productInputs.some(x => x.checked && x.dataset.wafs2Product === product);

      function removeOverlay(product) {
        const id=sourceId(product), layer=`${id}-layer`, state=overlays.get(product);
        if(map.getLayer(layer)) map.removeLayer(layer);
        if(map.getSource(id)) map.removeSource(id);
        if(state?.url) URL.revokeObjectURL(state.url);
        overlays.delete(product);
      }
      function clearProduct(product) {
        pending.get(product)?.controller.abort();
        pending.delete(product);
        removeOverlay(product);
      }
      function clearAll() {
        for(const product of new Set([...overlays.keys(),...pending.keys()])) clearProduct(product);
      }
      function loadImage(url) {
        return new Promise((resolve,reject) => {
          const img=new Image(); img.onload=()=>resolve(img); img.onerror=()=>reject(new Error("Invalid WAFS image")); img.src=url;
        });
      }
      function loadOne(product) {
        const key=new URLSearchParams({action:"image",product,fl:String(level(product)),valid:getTimeIso().slice(0,16).replace("T"," ")}).toString();
        if(overlays.get(product)?.key===key) return Promise.resolve(overlays.get(product));
        if(pending.get(product)?.key===key) return pending.get(product).promise;
        clearProduct(product);
        const request={key,controller:new AbortController(),promise:null};
        pending.set(product,request);
        const current=()=>pending.get(product)===request && !request.controller.signal.aborted && selected(product);
        request.promise=(async()=>{
          let url=null;
          try {
            const response=await fetch(`${api.wafs}?${key}`,{cache:"no-store",signal:request.controller.signal});
            if(!response.ok) throw new Error(`HTTP ${response.status}`);
            const validUtc=response.headers.get("X-YC-WAFS-Valid-UTC");
            if(!validUtc || !Number.isFinite(Date.parse(validUtc))) throw new Error("Missing WAFS validity");
            const blob=await response.blob();
            if(!current()) return null;
            url=URL.createObjectURL(blob);
            const img=await loadImage(url);
            if(!current()) return null;
            if(!(img.naturalWidth>0 && img.naturalHeight>0)) throw new Error("Invalid WAFS dimensions");
            const maxLat=180/Math.PI*Math.atan(Math.sinh(Math.PI*img.naturalHeight/img.naturalWidth));
            const id=sourceId(product),layer=`${id}-layer`;
            map.addSource(id,{type:"image",url,coordinates:[[-180,maxLat],[180,maxLat],[180,-maxLat],[-180,-maxLat]]});
            const spec={id:layer,type:"raster",source:id,paint:{"raster-opacity":opacity(product),"raster-fade-duration":0}};
            try { map.addLayer(spec,map.getLayer("nav-airspace-fill")?"nav-airspace-fill":undefined); }
            catch(error) { if(map.getSource(id)) map.removeSource(id); throw error; }
            const state={url,key,validUtc,layerFL:response.headers.get("X-YC-WAFS-Layer-FL")};
            overlays.set(product,state); url=null;
            return state;
          } finally {
            if(url) URL.revokeObjectURL(url);
            if(pending.get(product)===request) pending.delete(product);
          }
        })();
        return request.promise;
      }
      async function load() {
        const version=++loadVersion;
        if(!enabled()) { clearAll(); if(status) status.textContent="WAFS kapalı."; return; }
        const products=productInputs.filter(x=>x.checked).map(x=>x.dataset.wafs2Product);
        const keep=new Set(products);
        for(const p of new Set([...overlays.keys(),...pending.keys()])) if(!keep.has(p)) clearProduct(p);
        if(!products.length) { if(status) status.textContent="WAFS açık ama ürün seçili değil."; return; }
        if(status) status.textContent=`${products.length} WAFS katmanı yükleniyor…`;
        const results=await Promise.all(products.map(p=>loadOne(p).catch(()=>null)));
        if(version!==loadVersion || !enabled()) return;
        const ok=results.filter(Boolean).length;
        const times=results.map((state,i)=>state ? `${products[i].toUpperCase()} ${new Date(state.validUtc).toISOString().slice(0,16).replace("T"," ")}Z${state.layerFL && state.layerFL!=="NA" ? ` FL${state.layerFL}` : ""}` : null).filter(Boolean);
        if(status) status.textContent=ok ? `${ok}/${products.length} WAFS · ${times.join(" · ")}` : "Seçilen UTC için WAFS frame bulunamadı.";
        setStatus(ok===products.length?"WAFS":"WAFS ERROR",ok!==products.length);
      }
      enabledInput?.addEventListener("change",load);
      productInputs.forEach(x=>x.addEventListener("change",load));
      document.querySelectorAll("[data-wafs2-level]").forEach(x=>x.addEventListener("change",load));
      document.querySelectorAll("[data-wafs2-opacity]").forEach(x=>x.addEventListener("input",()=>{
        const product=x.dataset.wafs2Opacity,layer=`${sourceId(product)}-layer`;
        if(map.getLayer(layer)) map.setPaintProperty(layer,"raster-opacity",opacity(product));
      }));
      return {
        init(){},
        setActive(value){active=Boolean(value);if(active && enabledInput) enabledInput.checked=true;load();},
        refresh(){load();},
        searchLocal(){return[];}
      };
    }
  };
})();
