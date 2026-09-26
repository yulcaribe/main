(() => {
  "use strict";

  window.YCWafs = {
    create(ctx) {
      const { map, api, setStatus, getTimeIso } = ctx;
      let active = false;
      const overlays = new Map();
      const enabledInput = document.getElementById("wafs-enabled");
      const productInputs = [...document.querySelectorAll("[data-wafs2-product]")];
      const status = document.getElementById("wafs-status");

      function enabled(){ return active && Boolean(enabledInput?.checked); }
      function level(product){const el=document.querySelector(`[data-wafs2-level="${product}"]`);return el?Number(el.value):300;}
      function opacity(product){const el=document.querySelector(`[data-wafs2-opacity="${product}"]`);return Math.max(.1,Math.min(.85,Number(el?.value||40)/100));}
      function sourceId(product){return `yc-wafs-${product}`;}

      function clearProduct(product){
        const id=sourceId(product),layer=`${id}-layer`,state=overlays.get(product);
        if(map.getLayer(layer))map.removeLayer(layer);
        if(map.getSource(id))map.removeSource(id);
        if(state?.url)URL.revokeObjectURL(state.url);
        overlays.delete(product);
      }
      function clearAll(){for(const product of [...overlays.keys()])clearProduct(product);}
      function loadImage(url){return new Promise((resolve,reject)=>{const img=new Image();img.onload=()=>resolve(img);img.onerror=reject;img.src=url;});}

      async function loadOne(product){
        clearProduct(product);
        const q=new URLSearchParams({action:"image",product,fl:String(level(product)),valid:getTimeIso().slice(0,16).replace("T"," ")});
        const r=await fetch(`${api.wafs}?${q}`,{cache:"no-store"});
        if(!r.ok)throw new Error(`HTTP ${r.status}`);
        const url=URL.createObjectURL(await r.blob());
        try{
          const img=await loadImage(url),maxLat=180/Math.PI*Math.atan(Math.sinh(Math.PI*img.naturalHeight/img.naturalWidth));
          const id=sourceId(product),layer=`${id}-layer`;
          map.addSource(id,{type:"image",url,coordinates:[[-180,maxLat],[180,maxLat],[180,-maxLat],[-180,-maxLat]]});
          const spec={id:layer,type:"raster",source:id,paint:{"raster-opacity":opacity(product),"raster-fade-duration":0}};
          const before=map.getLayer("nav-airspace-fill")?"nav-airspace-fill":undefined;
          if(before)map.addLayer(spec,before);else map.addLayer(spec);
          overlays.set(product,{url});
          return true;
        }catch(e){URL.revokeObjectURL(url);throw e;}
      }

      async function load(){
        if(!enabled()){clearAll();if(status)status.textContent="WAFS kapalı.";return;}
        const products=productInputs.filter(x=>x.checked).map(x=>x.dataset.wafs2Product);
        if(!products.length){clearAll();if(status)status.textContent="WAFS açık ama ürün seçili değil.";return;}
        const keep=new Set(products);for(const p of [...overlays.keys()])if(!keep.has(p))clearProduct(p);
        if(status)status.textContent=`${products.length} WAFS katmanı yükleniyor…`;
        const results=await Promise.all(products.map(async p=>{try{return await loadOne(p);}catch{return false;}}));
        const ok=results.filter(Boolean).length;
        if(status)status.textContent=ok?`${ok}/${products.length} WAFS katmanı · ${getTimeIso().slice(0,16).replace("T"," ")}Z`:"Seçilen UTC için WAFS frame bulunamadı.";
        setStatus(ok?"WAFS":"WAFS ERROR",!ok);
      }

      enabledInput?.addEventListener("change",load);
      productInputs.forEach(x=>x.addEventListener("change",load));
      document.querySelectorAll("[data-wafs2-level],[data-wafs2-opacity]").forEach(x=>x.addEventListener("change",load));

      return {
        init(){},
        setActive(value){active=Boolean(value);if(active&&enabledInput)enabledInput.checked=true;if(active)load();else clearAll();},
        refresh(){load();},
        searchLocal(){return[];}
      };
    }
  };
})();