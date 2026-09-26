(() => {
  "use strict";

  window.YCAdsb = {
    create(ctx) {
      const { map, api, esc, popup, setStatus, bbox } = ctx;
      const REFRESH_MS = 2000;
      const MIN_ZOOM = 4.2;
      const RENDER_DELAY_MS = 4200;
      const SAMPLE_KEEP_MS = 20000;
      const FRAME_MS = 32;
      let active = false;
      let controller = null;
      let timer = null;
      let decoder = null;
      let decoderReady = false;
      let sourceClockOffsetMs = 0;
      let haveSourceClock = false;
      let animationStarted = false;
      let lastFrame = 0;
      let currentAircraft = [];
      const markers = new Map();
      const enabledInput = document.getElementById("flights-enabled");
      const health = document.getElementById("flights-status");

      function enabled(){ return active && Boolean(enabledInput?.checked); }
      function readAscii(u8,start,end){let out="";for(let i=start;i<end&&u8[i];i++)out+=String.fromCharCode(u8[i]);return out.trim();}
      function sourceType(code){return ["adsb_icao","adsb_icao_nt","adsr_icao","tisb_icao","adsc","mlat","other","mode_s","adsb_other","adsr_other","tisb_trackfile","tisb_other","mode_ac"][code]||"unknown";}

      function parseBinCraft(uint8){
        const buffer=uint8.buffer.slice(uint8.byteOffset,uint8.byteOffset+uint8.byteLength);
        if(buffer.byteLength<52)throw new Error("binCraft header too short");
        const header=new Uint32Array(buffer,0,13);
        const stride=header[2],version=header[10];
        if(!stride||stride<108||stride>256||buffer.byteLength<stride)throw new Error("Unexpected binCraft stride");
        const aircraft=[];
        for(let off=stride;off+stride<=buffer.byteLength;off+=stride){
          const s32=new Int32Array(buffer,off,stride/4),u16=new Uint16Array(buffer,off,stride/2),s16=new Int16Array(buffer,off,stride/2),u8=new Uint8Array(buffer,off,stride);
          let hex=(s32[0]&0xffffff).toString(16).padStart(6,"0"); if(s32[0]&(1<<24))hex="~"+hex;
          let seen,seenPos;
          if(version>=20240218){seen=s32[1]/10;seenPos=s32[27]/10;}else{seenPos=u16[2]/10;seen=u16[3]/10;}
          let lon=s32[2]/1e6,lat=s32[3]/1e6,alt=s16[10]*25,gs=s16[17]/10,track=s16[20]/90,magHeading=s16[22]/90,trueHeading=s16[23]/90;
          let baroRate=s16[8]*8,squawk=u16[16].toString(16).padStart(4,"0");
          const v1=u8[73],v2=u8[74],v3=u8[75],v4=u8[76];
          const flight=(v1&8)?readAscii(u8,78,86):"",typeCode=readAscii(u8,88,92),registration=readAscii(u8,92,104);
          if(!(v1&16))alt=null;if(!(v1&64)){lat=null;lon=null;seenPos=null;}if(!(v1&128))gs=null;
          if(!(v2&8))track=null;if(!(v2&64))magHeading=null;if(!(v2&128))trueHeading=null;if(!(v3&1))baroRate=null;if(!(v4&4))squawk=null;
          const airground=u8[68]&15;if(airground===1)alt="ground";
          const heading=track??trueHeading??magHeading??0;
          if(lat==null||lon==null||!Number.isFinite(lat)||!Number.isFinite(lon)||Math.abs(lat)>90||Math.abs(lon)>180)continue;
          aircraft.push({hex,flight,registration,typeCode,type:sourceType((u8[67]&240)>>4),lat,lon,alt,gs,track,heading,baroRate,squawk,seen,seenPos});
        }
        return {now:header[0]/1000+header[1]*4294967.296,aircraft};
      }

      async function initDecoder(){
        if(decoderReady)return;
        if(!window.zstddec?.ZSTDDecoder)throw new Error("zstd decoder yüklenemedi");
        decoder=new window.zstddec.ZSTDDecoder();await decoder.init();decoderReady=true;
      }

      function createAircraftElement(){
        const el=document.createElement("div");
        el.className="aircraft-marker";
        el.innerHTML='<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 3 C29.8 3 28.7 5.4 28.4 8.4 L26.8 25.2 L7 34.4 L7 39 L27.8 34.4 L28.2 49.5 L20.2 55.5 L20.2 59 L32 56 L43.8 59 L43.8 55.5 L35.8 49.5 L36.2 34.4 L57 39 L57 34.4 L37.2 25.2 L35.6 8.4 C35.3 5.4 34.2 3 32 3 Z" fill="#f4f8fb" stroke="#071019" stroke-width="1.6" stroke-linejoin="round"/></svg>';
        return el;
      }
      const shortestAngle=(a,b)=>a+(((b-a+540)%360)-180);
      const lerp=(a,b,t)=>a+(b-a)*t;
      function interpolate(a,b,t){
        const hb=shortestAngle(a.heading??0,b.heading??a.heading??0);
        return {...b,lon:lerp(a.lon,b.lon,t),lat:lerp(a.lat,b.lat,t),heading:((lerp(a.heading??0,hb,t)%360)+360)%360};
      }
      function extrapolate(sample,seconds){
        const data=sample.data||{},gs=Number(data.gs),track=Number.isFinite(data.track)?Number(data.track):Number(sample.heading);
        const s=Math.max(0,Math.min(7,Number(seconds)||0));
        if(!Number.isFinite(gs)||gs<15||!Number.isFinite(track)||s<=0)return {...data,lon:sample.lon,lat:sample.lat,heading:sample.heading};
        const distanceNm=gs*s/3600,rad=track*Math.PI/180,north=Math.cos(rad)*distanceNm,east=Math.sin(rad)*distanceNm;
        const lat=sample.lat+north/60,cosLat=Math.max(.15,Math.cos(lat*Math.PI/180));
        return {...data,lat,lon:sample.lon+east/(60*cosLat),heading:sample.heading};
      }

      function showAircraftCard(item){
        const ac=item.data||{},shown=item.rendered||ac;
        const rows=[];const push=(k,v)=>{if(v!==null&&v!==undefined&&v!=="")rows.push(`<div><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`);};
        push("Callsign",ac.flight||"—");push("Registration",ac.registration||"—");push("Type",ac.typeCode||"—");
        push("Altitude",ac.alt==="ground"?"GND":Number.isFinite(ac.alt)?`${Math.round(ac.alt).toLocaleString("en-US")} ft`:"—");
        push("Groundspeed",Number.isFinite(ac.gs)?`${Math.round(ac.gs)} kt`:"—");push("Track",Number.isFinite(shown.heading)?`${Math.round(shown.heading)}°`:"—");
        popup([shown.lon,shown.lat],ac.flight||ac.registration||ac.hex.toUpperCase(),[ac.registration,ac.typeCode].filter(Boolean).join(" · ")||"ADS-B",rows.join(""),{maxWidth:"300px"});
      }

      function ensureMarker(ac){
        let item=markers.get(ac.hex);if(item)return item;
        const el=createAircraftElement();el.style.display="none";
        const marker=new maplibregl.Marker({element:el,rotationAlignment:"map",pitchAlignment:"map"}).setLngLat([ac.lon,ac.lat]).setRotation(Number.isFinite(ac.heading)?ac.heading:0).addTo(map);
        item={marker,el,data:ac,rendered:ac,samples:[],lastSeenAt:Date.now()};
        el.addEventListener("click",e=>{e.stopPropagation();showAircraftCard(item);});
        markers.set(ac.hex,item);return item;
      }

      function ingest(aircraft,sourceNowMs){
        const seenNow=new Set();
        for(const ac of aircraft){
          seenNow.add(ac.hex);const item=ensureMarker(ac);item.data=ac;item.lastSeenAt=Date.now();
          const ageMs=Number.isFinite(ac.seenPos)?Math.max(0,ac.seenPos*1000):0,sampleTime=sourceNowMs-ageMs,last=item.samples[item.samples.length-1];
          const sameTime=last&&Math.abs(last.t-sampleTime)<50,samePos=last&&Math.abs(last.lon-ac.lon)<1e-9&&Math.abs(last.lat-ac.lat)<1e-9;
          if(!sameTime&&!samePos)item.samples.push({t:sampleTime,lon:ac.lon,lat:ac.lat,heading:Number.isFinite(ac.heading)?ac.heading:0,data:ac});
          else if(last){last.data=ac;last.heading=Number.isFinite(ac.heading)?ac.heading:last.heading;}
          const cutoff=sourceNowMs-SAMPLE_KEEP_MS;while(item.samples.length>2&&item.samples[1].t<cutoff)item.samples.shift();
        }
        const stale=Date.now()-15000;
        for(const [hex,item] of markers)if(!seenNow.has(hex)&&item.lastSeenAt<stale){item.marker.remove();markers.delete(hex);}
      }

      function renderBuffered(nowClientMs){
        if(!enabled()){for(const item of markers.values())item.el.style.display="none";return;}
        if(!haveSourceClock)return;
        const target=nowClientMs-sourceClockOffsetMs-RENDER_DELAY_MS;
        for(const item of markers.values()){
          const samples=item.samples;if(!samples.length){item.el.style.display="none";continue;}
          let before=null,after=null;for(const s of samples){if(s.t<=target)before=s;if(s.t>=target){after=s;break;}}
          if(!before){item.el.style.display="none";continue;}
          let shown;
          if(after&&after!==before&&after.t>before.t){const t=Math.max(0,Math.min(1,(target-before.t)/(after.t-before.t)));shown=interpolate({...before.data,lon:before.lon,lat:before.lat,heading:before.heading},{...after.data,lon:after.lon,lat:after.lat,heading:after.heading},t);}
          else shown=extrapolate(before,Math.max(0,(target-before.t)/1000));
          item.rendered=shown;item.el.style.display="";item.marker.setLngLat([shown.lon,shown.lat]);item.marker.setRotation(Number.isFinite(shown.heading)?shown.heading:0);
        }
      }
      function animation(ts){if(ts-lastFrame>=FRAME_MS){lastFrame=ts;renderBuffered(Date.now());}requestAnimationFrame(animation);}
      function startAnimation(){if(animationStarted)return;animationStarted=true;requestAnimationFrame(animation);}

      function setHealth(state){
        if(!health)return;const label=health.querySelector(".flight-health-label");health.classList.remove("is-loading","is-ok","is-error");
        if(state==="off"){health.hidden=true;return;}health.hidden=false;
        if(state==="ok"){health.classList.add("is-ok");if(label)label.textContent="Güncel";}
        else if(state==="error"){health.classList.add("is-error");if(label)label.textContent="Hata";}
        else{health.classList.add("is-loading");if(label)label.textContent="Yükleniyor";}
      }

      async function load(){
        clearTimeout(timer);if(!enabled())return;
        if(map.getZoom()<MIN_ZOOM){timer=setTimeout(load,1200);return;}
        try{await initDecoder();}catch{setHealth("error");return;}
        controller?.abort();controller=new AbortController();setHealth(haveSourceClock?"ok":"loading");
        const b=bbox(.35),box=[b.south,b.north,b.west,b.east].map(v=>Number(v).toFixed(6)).join(",");
        try{
          const r=await fetch(`${api.adsb}?action=feed&box=${encodeURIComponent(box)}`,{cache:"no-store",signal:controller.signal});
          if(!r.ok)throw new Error(`HTTP ${r.status}`);
          const compressed=new Uint8Array(await r.arrayBuffer()),decoded=decoder.decode(compressed),parsed=parseBinCraft(decoded);
          const sourceNowMs=parsed.now*1000,measured=Date.now()-sourceNowMs;
          sourceClockOffsetMs=haveSourceClock?sourceClockOffsetMs*.85+measured*.15:measured;haveSourceClock=true;
          currentAircraft=parsed.aircraft;ingest(currentAircraft,sourceNowMs);setStatus("LIVE");setHealth("ok");startAnimation();
        }catch(e){if(e?.name!=="AbortError"){setStatus("ADS-B ERROR",true);setHealth("error");}}
        finally{if(enabled())timer=setTimeout(load,REFRESH_MS);}
      }

      function setVisibility(){for(const item of markers.values())item.el.style.display=enabled()?"":"none";}
      function normalize(v){return String(v||"").toUpperCase().replace(/[^A-Z0-9]/g,"");}
      function searchLocal(q){
        const needle=normalize(q);if(needle.length<2)return[];
        return currentAircraft.map(ac=>{
          const fields=[ac.registration,ac.flight,ac.hex,ac.typeCode].map(normalize).filter(Boolean);let score=99;
          for(const field of fields){if(field===needle)score=Math.min(score,0);else if(field.startsWith(needle))score=Math.min(score,1);else if(field.includes(needle))score=Math.min(score,2);}
          if(score===99)return null;return{kind:"aircraft",ident:ac.registration||ac.flight||ac.hex,name:[ac.flight,ac.typeCode].filter(Boolean).join(" · "),hex:ac.hex,lon:ac.lon,lat:ac.lat,_score:score};
        }).filter(Boolean).sort((a,b)=>a._score-b._score).slice(0,12);
      }
      function select(hex){const item=markers.get(hex);if(!item)return;const shown=item.rendered||item.data;map.flyTo({center:[shown.lon,shown.lat],zoom:Math.max(map.getZoom(),9)});showAircraftCard(item);}

      enabledInput?.addEventListener("change",()=>{setVisibility();if(enabled())load();else{clearTimeout(timer);setHealth("off");}});
      return {
        init(){startAnimation();},
        setActive(value){active=Boolean(value);if(active&&enabledInput)enabledInput.checked=true;setVisibility();if(active)load();else{clearTimeout(timer);setHealth("off");}},
        refresh(){if(enabled())load();},
        searchLocal,
        select
      };
    }
  };
})();