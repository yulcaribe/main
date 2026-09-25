const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
function load(){
  const window={},context=vm.createContext({window,document:{querySelector:()=>null},console:{log(){},debug(){},warn(){},error(){}},Uint8Array,DataView,ArrayBuffer,URLSearchParams,AbortController,setTimeout,clearTimeout});
  for(const file of ['briefing/vendor/grib2.js','briefing/vendor/grib2utils.js','briefing/grib2-browser.js','briefing/modelwx.js'])vm.runInContext(fs.readFileSync(path.join(root,file),'utf8'),context);
  return window;
}
const fixture=fs.readFileSync(path.join(__dirname,'fixtures/gfs250.grib2'));
const reference=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/gfs250-reference.json')));
const close=(actual,expected,tolerance=1e-8)=>assert.ok(Math.abs(actual-expected)<=tolerance,`${actual} != ${expected}`);

test('real NOAA subset: all five fields and five locations match independent ecCodes values and coordinates',()=>{
  const {YCGrib2:g}=load(),h=g.parse(fixture);
  assert.equal(g.recordCount(h),5);
  const expectedNames=['HGT','TMP','RH','UGRD','VGRD'];
  reference.records.forEach((expected,i)=>{
    const meta=g.section4(h,i+1);assert.equal(meta.parameterCategory,expected.category);assert.equal(meta.parameterNumber,expected.number);assert.equal(meta.ycName,expectedNames[i]);assert.equal(meta.pressureMb,250);
    assert.equal(g.values(h,i+1).length,g.section3(h,i+1).numberOfPoints);
    expected.points.forEach(p=>{const hit=g.nearest(h,i+1,...p.query);close(hit.latitude,p.latitude);close(hit.longitude,p.longitude);close(g.values(h,i+1)[hit.index],p.value);});
  });
  assert.equal(g.nearest(h,1,70,-50).index,-1);assert.equal(g.nearest(h,1,36,9).index,-1);
  g.release(h);assert.equal(g.recordCount(h),0);
});

test('bad framing, unsupported packing and repeated sections fail before vendor parsing',()=>{
  const {YCGrib2:g}=load();
  for(const bytes of [Buffer.alloc(0),fixture.subarray(0,13),fixture.subarray(0,fixture.length-1)])assert.throws(()=>g.parse(bytes));
  const corrupt=Buffer.from(fixture);corrupt.writeBigUInt64BE(0n,8);assert.throws(()=>g.parse(corrupt));
  const packed=Buffer.from(fixture);let pos=16;while(packed[pos+4]!==5)pos+=packed.readUInt32BE(pos);packed.writeUInt16BE(40,pos+9);assert.throws(()=>g.parse(packed),/sıkıştırma/);
});

test('route positions follow distance, not waypoint index; dateline takes the short path',()=>{
  const {YCModelWX:m}=load();
  const r=m.routeSamples([[0,0],[0,1],[0,10]],1000);assert.equal(r.points.length,5);close(r.points[2].lon,5);close(r.points[2].track,90);
  const cross=m.routeSamples([[10,179],[10,-179]],25);assert.ok(cross.total<120);assert.ok(Math.abs(cross.points[Math.floor(cross.points.length/2)].lon)>179);
  const bb=m.routeBBox([[10,179],[10,-179]]);assert.equal(bb.left,-180);assert.equal(bb.right,180);
  assert.throws(()=>m.routeSamples([[0,0],[0,0]]));
});

test('wind from direction, head/tail and crosswind use vector components',()=>{
  const {YCModelWX:m}=load();
  const north=m.wind(0,-10,0);close(north.dir,0);close(north.tailKt,-19.43844);close(north.crossKt,0);
  const east=m.wind(10,0,90);close(east.dir,270);close(east.tailKt,19.43844);close(east.crossKt,0);
  assert.equal(m.wind(null,2,90),null);
});

test('missing bitmap values remain missing instead of becoming calm wind or 0% RH',()=>{
  const {YCModelWX:m,YCGrib2:g}=load();
  const original=g.values;g.values=(h,i)=>new Array(original(h,i).length).fill(null);
  const flight={etdUtc:'2026-09-21T10:00:00Z',estimatedArrivalUtc:'2026-09-21T13:00:00Z'};
  assert.throws(()=>m.decodeProduct({bytes:fixture},[{lat:37,lon:30.75,progress:0,track:0}],{flight}),/değerler eksik/);
});

test('NOAA route summary exposes RH, height and approximate pressure without treating it as exact FL',()=>{
  const {YCModelWX:m}=load();
  const flight={etdUtc:'2026-09-21T10:00:00Z',estimatedArrivalUtc:'2026-09-21T13:00:00Z'};
  const d=m.decodeProduct({bytes:fixture},[{lat:36.8987,lon:30.8005,progress:0,track:320}],{flight});
  close(d.samples[0].rh,53.7);close(d.samples[0].heightM,10770.731875);assert.equal(d.pressureMb,250);assert.equal(d.missingFields.length,0);
});

test('ecCodes scan fixtures cover north/south and east/west ordering plus constant packed fields',()=>{
  const {YCGrib2:g}=load(),h=g.parse(fs.readFileSync(path.join(__dirname,'fixtures/scan-regression.grib2')));
  const refs=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/scan-reference.json')));
  refs.forEach((ref,i)=>ref.points.forEach(p=>{const hit=g.nearest(h,i+1,p.lat,p.lon);close(hit.latitude,p.lat);close(hit.longitude,p.lon);close(g.values(h,i+1)[hit.index],p.value);}));
  assert.ok(g.values(h,5).every(v=>v===285));
});
