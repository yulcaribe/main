(() => {
  "use strict";

  let nextHandle=1;
  const contexts=new Map();

  function init(){
    if(!window.YCBlueGRIB?.decodeGRIB2File) {
      return Promise.reject(new Error("Yerel GRIB2 decoder yüklenmedi."));
    }
    return Promise.resolve();
  }

  function inferName(product){
    const wanted=["TMP","RH","UGRD","VGRD","HGT","ICESEV","ICSEV","EDPARM","CATEDR","MWTURB","CBHE","ICAHT"];
    const seen=[];
    const walk=v=>{
      if(v==null)return;
      if(typeof v==="string"||typeof v==="number"){seen.push(String(v));return;}
      if(typeof v==="object"){
        if(typeof v.abbreviation==="string")seen.unshift(v.abbreviation);
        for(const x of Object.values(v))walk(x);
      }
    };
    walk(product);
    const text=seen.join(" ").toUpperCase();
    return wanted.find(x=>text.includes(x))||null;
  }

  function parse(bytes){
    const arr=bytes instanceof Uint8Array?bytes:new Uint8Array(bytes);
    const buffer=arr.buffer.slice(arr.byteOffset,arr.byteOffset+arr.byteLength);
    const files=window.YCBlueGRIB.decodeGRIB2File(buffer);
    if(!Array.isArray(files)||!files.length)throw new Error("GRIB2 içinde çözülebilir kayıt bulunamadı.");
    const records=files.map(file=>({file,data:file.data,name:inferName(file.data?.product)}));
    const handle=nextHandle++;
    contexts.set(handle,{records});
    if(contexts.size>10){const oldest=contexts.keys().next().value;contexts.delete(oldest);}
    return handle;
  }

  function ctx(h){const c=contexts.get(h);if(!c)throw new Error("Geçersiz GRIB2 handle.");return c;}
  function rec(h,i){const r=ctx(h).records[i-1];if(!r)throw new Error("GRIB2 record bulunamadı.");return r;}
  function recordCount(h){return ctx(h).records.length;}
  function section4(h,i){
    const r=rec(h,i);
    return {parameterCategory:-1,parameterNumber:-1,ycName:r.name};
  }
  function section3(h,i){
    const g=rec(h,i).data?.grid||{};
    return {ni:g.numLongPoints||0,nj:g.numLatPoints||0,numberOfPoints:g.numPoints||0};
  }
  function values(h,i){return rec(h,i).data?.values||[];}

  function normalize360(v){return ((v%360)+360)%360;}
  function nearest(h,i,lat,lon){
    const g=rec(h,i).data?.grid;
    if(!g)throw new Error("GRIB grid bilgisi yok.");
    const ni=Number(g.numLongPoints)||0,nj=Number(g.numLatPoints)||0;
    const di=Math.abs(Number(g.incI)||0),dj=Math.abs(Number(g.incJ)||0);
    if(!ni||!nj||!di||!dj)throw new Error("GRIB grid geometrisi desteklenmiyor.");

    const north=Math.max(Number(g.latStart),Number(g.latEnd));
    let row=Math.round((north-lat)/dj);
    row=Math.max(0,Math.min(nj-1,row));

    const scanI=Number(g.scanningMode?.[0]?.[0]??0);
    const start=normalize360(Number(g.lonStart));
    const target=normalize360(lon);
    let delta=scanI===1?normalize360(start-target):normalize360(target-start);
    const globalish=ni*di>=359;
    if(!globalish){
      const span=(ni-1)*di;
      if(delta>span){
        const alt=Math.abs((target-start+540)%360-180);
        delta=Math.min(delta,alt);
      }
    }
    let col=Math.round(delta/di);
    col=Math.max(0,Math.min(ni-1,col));

    return {index:row*ni+col,latitude:north-row*dj,longitude:scanI===1?normalize360(start-col*di):normalize360(start+col*di)};
  }

  window.YCGrib2={init,parse,recordCount,section3,section4,values,nearest,decoder:"BlueNetCat/grib22json local"};
})();