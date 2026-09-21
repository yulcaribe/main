(() => {
  "use strict";
  let nextHandle=1;
  const contexts=new Map();
  const names={"0:0":"TMP","1:1":"RH","2:2":"UGRD","2:3":"VGRD","3:5":"HGT"};
  const init=()=>window.YCBlueGRIB?.decodeGRIB2File ? Promise.resolve() : Promise.reject(new Error("Yerel GRIB2 decoder yüklenmedi."));

  // Validate message framing before the vendor decoder (which assumes complete input).
  function validate(arr){
    const view=new DataView(arr.buffer,arr.byteOffset,arr.byteLength);
    let pos=0;
    while(pos<arr.length){
      if(pos+20>arr.length || String.fromCharCode(...arr.subarray(pos,pos+4))!=="GRIB" || arr[pos+7]!==2)throw new Error("Eksik veya geçersiz GRIB2 mesajı.");
      const length=view.getUint32(pos+8)*4294967296+view.getUint32(pos+12);
      if(!Number.isSafeInteger(length)||length<20||pos+length>arr.length)throw new Error("GRIB2 mesaj uzunluğu geçersiz.");
      const end=pos+length;
      if(String.fromCharCode(...arr.subarray(end-4,end))!=="7777")throw new Error("GRIB2 sonu eksik.");
      let section=pos+16; const seen=new Set();
      while(section<end-4){
        if(section+5>end-4)throw new Error("GRIB2 bölüm başlığı eksik.");
        const size=view.getUint32(section),id=arr[section+4];
        if(size<5||section+size>end-4||seen.has(id))throw new Error("Tekrarlanan veya geçersiz GRIB2 bölümü desteklenmiyor.");
        if(id===3&&(size<72||view.getUint16(section+12)!==0))throw new Error("Yalnızca düzenli enlem/boylam gridleri destekleniyor.");
        if(id===5&&(size<11||![0,2,3,4].includes(view.getUint16(section+9))))throw new Error("GRIB2 sıkıştırma tipi desteklenmiyor.");
        if(id===6&&(size<6||![0,255].includes(arr[section+5])))throw new Error("GRIB2 bitmap tipi desteklenmiyor.");
        seen.add(id); section+=size;
      }
      if(section!==end-4||![1,3,4,5,6,7].every(s=>seen.has(s)))throw new Error("GRIB2 bölümleri eksik.");
      pos=end;
    }
    if(!pos)throw new Error("Boş GRIB2 yanıtı.");
  }
  function parse(bytes){
    const arr=bytes instanceof Uint8Array?bytes:new Uint8Array(bytes); validate(arr);
    const files=window.YCBlueGRIB.decodeGRIB2File(arr.buffer.slice(arr.byteOffset,arr.byteOffset+arr.byteLength));
    if(!Array.isArray(files)||!files.length)throw new Error("GRIB2 içinde çözülebilir kayıt bulunamadı.");
    const records=files.map(file=>{
      const data=file.data,g=data?.grid;
      if(!g?.normalizedNorthWest||data.values?.length!==g.numLongPoints*g.numLatPoints)throw new Error("GRIB değer sayısı grid boyutuyla uyuşmuyor.");
      return {file,data};
    });
    const handle=nextHandle++;contexts.set(handle,{records});
    if(contexts.size>10)contexts.delete(contexts.keys().next().value);
    return handle;
  }
  function rec(h,i){const r=contexts.get(h)?.records[i-1];if(!r)throw new Error("GRIB2 record bulunamadı.");return r;}
  function section4(h,i){
    const dt=rec(h,i).file.dataTemplate,category=Number(dt[4][4].content),number=Number(dt[4][5].content);
    const get=label=>dt[4].find(p=>p.info===label)?.content;
    const type=get("Type of first fixed surface (see Code table 4.5)");
    const scale=get("Scale factor of first fixed surface"),value=get("Scaled value of first fixed surface");
    return {discipline:dt[0][2].content,parameterCategory:category,parameterNumber:number,
      ycName:dt[0][2].content===0?names[`${category}:${number}`]||null:null,
      pressureMb:type===100?value*Math.pow(10,-scale)/100:null};
  }
  function section3(h,i){const g=rec(h,i).data.grid;return {ni:g.numLongPoints,nj:g.numLatPoints,numberOfPoints:g.numPoints,scanningMode:g.rawScanningMode};}
  const values=(h,i)=>rec(h,i).data.values;
  const norm360=v=>((v%360)+360)%360;
  function nearest(h,i,lat,lon){
    const g=rec(h,i).data.grid,ni=g.numLongPoints,nj=g.numLatPoints,di=g.incI,dj=g.incJ;
    if(!Number.isFinite(lat)||!Number.isFinite(lon)||!ni||!nj||!di||!dj)throw new Error("GRIB grid geometrisi desteklenmiyor.");
    const y=(g.north-lat)/dj,global=ni*di>=360-1e-6;
    let delta=norm360(lon-g.west);
    if(!global&&delta>360-di/2)delta-=360;
    const x=delta/di;
    // Outside a subset is missing, never an unrelated value clamped to its edge.
    if(y<-.5||y>nj-.5||(!global&&(x<-.5||x>ni-.5)))return {index:-1,latitude:NaN,longitude:NaN};
    const row=Math.max(0,Math.min(nj-1,Math.round(y))),col=global?Math.round(x)%ni:Math.max(0,Math.min(ni-1,Math.round(x)));
    return {index:row*ni+col,latitude:g.north-row*dj,longitude:((g.west+col*di+540)%360)-180};
  }
  window.YCGrib2={init,parse,recordCount:h=>contexts.get(h)?.records.length||0,section3,section4,values,nearest,release:h=>contexts.delete(h),decoder:"BlueNetCat/grib22json local + validated scan normalization"};
})();
