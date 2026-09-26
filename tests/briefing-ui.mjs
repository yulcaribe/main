// Dependency-free regression checks; run: node tests/briefing-ui.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const root=new URL('../',import.meta.url);
let checks=0;
function check(value,name){assert.ok(value,name);checks++;console.log(`PASS ${name}`);}
const elements=new Map(),drawn=[];
function element(id=''){
  return {id,value:'',hidden:false,innerHTML:'',textContent:'',dataset:{},classList:{add(){},remove(){},toggle(){}},
    addEventListener(){},setAttribute(){},querySelectorAll(){return [];},querySelector(){return null;},after(e){elements.set('#'+e.id,e);},appendChild(e){elements.set('#'+e.id,e);},remove(){elements.delete('#'+id);}};
}
const document={querySelector(s){if(!elements.has(s))elements.set(s,element(s.slice(1)));return elements.get(s);},querySelectorAll(){return [];},getElementById(id){return elements.get('#'+id)||null;},createElement(){return element();}};
const chain=()=>new Proxy({}, {get(target,key){if(key==='getBounds')return ()=>[];if(key==='bindPopup')return text=>{drawn.push(text);return target.proxy;};return ()=>target.proxy;}});
function layer(){const p=chain();p.proxy=p;return p;}
const leaflet=new Proxy({control:{zoom:layer}}, {get(t,k){return t[k]||layer;}});
const context={window:{},document,L:leaflet,console,URLSearchParams,AbortController,setInterval(){},setTimeout(){},clearTimeout(){},requestAnimationFrame(){},navigator:{}};
vm.createContext(context);
let source=fs.readFileSync(new URL('briefing/briefing.js',root),'utf8');
source=source.replace(/  load\(\);\s*\}\)\(\);\s*$/, '  window.test={renderRouteMeta,renderSimpleBrief,renderMap};\n})();');
vm.runInContext(source,context);
const data={from:{icao:'LTAI'},to:{icao:'EDDB'},distanceNm:1362,stations:[],hazards:[],hazardSummary:{},flight:{cruiseFL:360,etdUtc:'2026-09-26T18:50:00Z',estimatedEetMinutes:202},route:[[36.9,30.8],[52.36,13.5]],routeInput:{},routeQuality:{state:'fallback',origin:'automatic',missingCount:8}};
context.window.test.renderRouteMeta(data);context.window.test.renderSimpleBrief(data);
check(elements.get('#route-type').textContent==='GREAT CIRCLE FALLBACK','automatic candidate cannot override fallback result');
check(elements.get('#simple-brief').innerHTML.includes('8 bölüm çözülemedi'),'unresolved scope is visible');
data.routeQuality={state:'resolved',origin:'automatic'};
data.navdataContext={terminals:[{airport:'LTAI',type:'sid',procedures:[{ident:'TEST1A'}]}]};
context.window.test.renderRouteMeta(data);
check(elements.get('#route-type').textContent==='ESTIMATED NAVDATA','automatic resolved route remains estimated');
check(elements.get('#terminal-navdata').innerHTML.includes('TEST1A')&&elements.get('#terminal-navdata').innerHTML.includes('otomatik seçilmiş prosedürler değildir'),'terminal source records shown without claiming selection');
data.routeInput={resolved:[{id:'AYT',type:'navaid',lat:36.9,lon:30.8,details:{components:[{type:'vor',frequencyText:'114.000 MHz'},{type:'dme',channel:'87X'}]}}]};
context.window.test.renderMap(data);
check(drawn.some(p=>p.includes('114.000 MHz')&&p.includes('87X')),'map popup renders VOR frequency and DME channel');
const calls=[];
class FakeImage{naturalWidth=1000;naturalHeight=600;set src(v){queueMicrotask(()=>this.onload());}}
const wafs={window:{},document:{...document,createElement(){return {getContext(){return {drawImage(){},getImageData(){return {data:[0,0,0,0]};}};}};}},console,URLSearchParams,Image:FakeImage,URL:{createObjectURL(){return 'blob:test';}},fetch:async url=>{
 calls.push(url);
 if(url.includes('action=status'))return {ok:true,json:async()=>({ok:true,products:[{id:'edr',withinCoverage:true,layerFL:340,levelMatch:'nearest'},{id:'icing',withinCoverage:false}]})};
 return {ok:true,blob:async()=>({}),headers:{get(key){return key==='x-yc-wafs-layer-fl'?'340':null;}}};
}};
vm.createContext(wafs);
vm.runInContext(fs.readFileSync(new URL('briefing/wafs.js',root),'utf8').replace('window.YCWAFS={','window.YCWAFS={productCapability,fetchFrame,samplePixel,summaryText,'),wafs);
const api=wafs.window.YCWAFS;
await assert.rejects(api.fetchFrame('icing',0,360),/kapsamı dışında/);
await assert.rejects(api.fetchFrame('icing',0,360),/kapsamı dışında/);
check(calls.length===1,'unsupported icing level issues no image request or repeat status request');
const frame=await api.fetchFrame('edr',0,360);
check(calls[1].includes('fl=340')&&frame.meta.requestedFL===360,'WAFS requests supported level and preserves requested cruise');
check(api.samplePixel(frame,90,0).hit===null,'outside PNG coverage is unknown rather than zero hazard');
check(api.summaryText({available:1,total:1,unknown:0,hits:0,frames:[frame]})[1].includes('istenen FL360, gösterilen FL340'),'nearest WAFS level is disclosed');
console.log(`OK ${checks} UI checks`);
