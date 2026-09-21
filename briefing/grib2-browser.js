(() => {
  "use strict";
  let wasmInstance = null;
  let wasmPromise = null;
  const DEFAULT_WASM = "https://cdn.jsdelivr.net/npm/@trkbt10/grib2-wasm@0.1.0/grib2.wasm";

  function bytesToLatin1(bytes) {
    const chunks=[]; const CHUNK=8192;
    for(let i=0;i<bytes.length;i+=CHUNK){
      const part=bytes.subarray(i,i+CHUNK);
      let s="";
      for(let j=0;j<part.length;j++) s+=String.fromCharCode(part[j]);
      chunks.push(s);
    }
    return chunks.join("");
  }
  function latin1ToFloat32Array(str) {
    const bytes=new Uint8Array(str.length);
    for(let i=0;i<str.length;i++) bytes[i]=str.charCodeAt(i)&255;
    return new Float32Array(bytes.buffer);
  }
  async function instantiate(url){
    const opts={builtins:["js-string"],importedStringConstants:"_"};
    const res=await fetch(url,{cache:"force-cache"});
    if(!res.ok) throw new Error(`GRIB decoder WASM indirilemedi: HTTP ${res.status}`);
    try{
      const result=await WebAssembly.instantiateStreaming(res.clone(),{},opts);
      return result.instance;
    }catch(first){
      const bytes=await res.arrayBuffer();
      try{
        const mod=await WebAssembly.compile(bytes,opts);
        return await WebAssembly.instantiate(mod,{});
      }catch(second){
        throw new Error(`Tarayıcı GRIB2 Wasm-GC decoder'ı başlatamadı: ${second?.message||first?.message||second}`);
      }
    }
  }
  async function init(url=DEFAULT_WASM){
    if(wasmInstance) return;
    if(!wasmPromise) wasmPromise=instantiate(url).then(x=>{wasmInstance=x;return x;});
    await wasmPromise;
  }
  function ex(){if(!wasmInstance)throw new Error("GRIB2 decoder başlatılmadı.");return wasmInstance.exports;}
  function jsonCall(name,...args){const s=ex()[name](...args);if(!s)throw new Error(`${name} boş döndü`);const p=JSON.parse(s);if(p?.error)throw new Error(p.error);return p;}
  function parse(bytes){
    const handle=ex().parseGrib2(bytesToLatin1(bytes));
    if(handle<0)throw new Error("GRIB2 parse başarısız.");
    return handle;
  }
  function recordCount(handle){return ex().getRecordCount(handle);}
  function section1(handle,i=0){return jsonCall("getSection1",handle,i);}
  function section3(handle,i){return jsonCall("getSection3",handle,i);}
  function section4(handle,i){return jsonCall("getSection4",handle,i);}
  function section5(handle,i){return jsonCall("getSection5",handle,i);}
  function values(handle,i){const s=ex().getGridData(handle,i);if(!s)throw new Error("GRIB grid boş");return latin1ToFloat32Array(s);}
  function lats(handle,i){const s=ex().getLatitudes(handle,i);if(!s)throw new Error("GRIB lat boş");return latin1ToFloat32Array(s);}
  function lons(handle,i){const s=ex().getLongitudes(handle,i);if(!s)throw new Error("GRIB lon boş");return latin1ToFloat32Array(s);}

  window.YCGrib2={init,parse,recordCount,section1,section3,section4,section5,values,lats,lons,decoder:"@trkbt10/grib2-wasm 0.1.0"};
})();