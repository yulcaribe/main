<?php
declare(strict_types=1);
require_once __DIR__ . '/../nms/internal/auth.php';

if (isset($_POST['logout'])) {
    nmsHealthLogout();
    header('Location: /main/notam/health/');
    exit;
}

$loginError = false;
if (!nmsHealthAuthenticated() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $loginError = !nmsHealthLogin(trim((string)($_POST['admin_key'] ?? '')));
    if (!$loginError) {
        header('Location: /main/notam/health/');
        exit;
    }
}

if (!nmsHealthAuthenticated()) {
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#071017"><title>System Health Login | YulCaribe</title>
<style>
:root{color-scheme:dark;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;--bg:#071017;--panel:#0b1822;--line:#203746;--text:#edf7fb;--muted:#8ca5b4;--cyan:#45d9ed;--bad:#f18484}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--text);padding:22px}.card{width:min(440px,100%);border:1px solid var(--line);background:var(--panel);border-radius:18px;padding:28px;box-shadow:0 24px 70px rgba(0,0,0,.35)}.eyebrow{color:var(--cyan);font:600 10px ui-monospace,monospace;letter-spacing:.12em}.card h1{margin:8px 0 8px;font-size:34px}.card p{margin:0 0 22px;color:var(--muted);font-size:13px;line-height:1.55}.card input{width:100%;height:46px;border:1px solid var(--line);border-radius:10px;background:#071017;color:var(--text);padding:0 13px;font:inherit}.card button{width:100%;height:46px;margin-top:10px;border:1px solid #2d8290;border-radius:10px;background:#0d1b25;color:var(--text);font:700 14px inherit;cursor:pointer}.error{margin:0 0 14px;padding:10px 12px;border:1px solid rgba(241,132,132,.45);border-radius:10px;color:var(--bad);font-size:12px}
</style></head><body><form class="card" method="post" autocomplete="off"><div class="eyebrow">YULCARIBE · MAINTENANCE</div><h1>System Health</h1><p>Bakım ekranı korumalıdır. NMS admin anahtarıyla giriş yap.</p><?php if ($loginError): ?><div class="error">Anahtar geçersiz.</div><?php endif; ?><input name="admin_key" type="password" required autofocus autocomplete="current-password" placeholder="NMS admin key"><button type="submit">Giriş yap</button></form></body></html><?php
exit;
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#071017">
<title>System Health | YulCaribe</title>
<style>
:root{color-scheme:dark;--bg:#071017;--panel:#0b1822;--panel2:#0e1e2a;--line:#203746;--text:#edf7fb;--muted:#8ca5b4;--cyan:#45d9ed;--good:#70d59a;--warn:#efc46a;--bad:#f18484;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text)}a{color:inherit}
.top{height:64px;display:flex;align-items:center;gap:16px;padding:0 22px;border-bottom:1px solid var(--line);background:#08131b;position:sticky;top:0;z-index:5}.back{text-decoration:none;font-size:22px}.brand strong{display:block}.brand span{color:var(--muted);font-size:11px}.shell{width:min(1180px,calc(100% - 32px));margin:auto;padding:42px 0 60px}
.hero{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}.hero h1{margin:4px 0 8px;font-size:clamp(34px,5vw,56px)}.hero p{margin:0;color:var(--muted);max-width:680px}.eyebrow,.kicker{color:var(--cyan);font:600 10px ui-monospace,monospace;letter-spacing:.12em}.actions{display:flex;gap:8px;flex-wrap:wrap}
button,input{font:inherit}.btn{height:40px;padding:0 14px;border:1px solid var(--line);border-radius:9px;background:#0d1b25;color:var(--text);cursor:pointer;font-weight:700}.btn.primary{border-color:#2d8290}.btn:disabled{opacity:.45;cursor:wait}
.banner,.admin,.panel,.metric{border:1px solid var(--line);background:var(--panel);border-radius:14px}.banner{padding:14px 16px;margin-bottom:16px}.banner.good{border-color:rgba(112,213,154,.45)}.banner.warn{border-color:rgba(239,196,106,.45)}.banner.bad{border-color:rgba(241,132,132,.45)}.banner strong{display:block}.banner span{color:var(--muted);font-size:12px}
.admin{padding:18px;margin-bottom:16px;display:grid;grid-template-columns:1fr minmax(330px,520px);gap:18px;align-items:end}.admin h2{margin:4px 0 8px}.admin p{margin:0;color:var(--muted);font-size:12px;line-height:1.55}.admin-controls{display:grid;grid-template-columns:1fr 1fr;gap:8px}.admin input{height:40px;padding:0 12px;border:1px solid var(--line);border-radius:9px;background:#071017;color:var(--text);min-width:0}.result{grid-column:1/-1;color:var(--muted);font-size:11px;margin-top:6px}
.metrics{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:16px}.metric{padding:15px}.metric span{display:block;color:var(--muted);font-size:11px}.metric strong{display:block;margin-top:7px;font-size:26px}.metric.good strong{color:var(--good)}.metric.warn strong{color:var(--warn)}.metric.bad strong{color:var(--bad)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.panel{padding:17px}.panel h2{margin:4px 0 14px;font-size:18px}.rows{display:grid;gap:9px}.row{display:grid;grid-template-columns:1fr auto;gap:12px;padding-bottom:9px;border-bottom:1px solid rgba(255,255,255,.06)}.row span{color:var(--muted);font-size:12px}.row strong{font-size:12px;text-align:right;word-break:break-word}.ok{color:var(--good)!important}.warntext{color:var(--warn)!important}.badtext{color:var(--bad)!important}.classes{display:grid;gap:8px}.class-row{display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:8px}.class-row span{color:var(--muted);font-size:12px}.small{color:var(--muted);font-size:10px;margin-top:18px}
@media(max-width:900px){.metrics{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:1fr}.admin{grid-template-columns:1fr}.admin-controls{grid-template-columns:1fr 1fr}.admin input{grid-column:1/-1}.hero{align-items:flex-start;flex-direction:column}}
@media(max-width:560px){.shell{width:calc(100% - 20px);padding-top:26px}.metrics{grid-template-columns:repeat(2,1fr)}.admin-controls{grid-template-columns:1fr}.admin input{grid-column:auto}.btn{width:100%}.actions{width:100%}.actions .btn{flex:1}}
</style>
</head>
<body>
<header class="top"><a class="back" href="/">←</a><div class="brand"><strong>YulCaribe</strong><span>System Health</span></div></header>
<main class="shell">
<section class="hero">
<div><div class="eyebrow">FAA · NOTAM MANAGEMENT SERVICE</div><h1>NOTAM Health</h1><p>FAA bağlantısı, local MariaDB senkronizasyonu ve NOTAM yaşam döngüsü tek ekranda.</p></div>
<div class="actions"><button class="btn" id="refresh">Yenile</button><button class="btn primary" id="probe">FAA bağlantısını test et</button><form method="post" style="margin:0"><button class="btn" type="submit" name="logout" value="1">Çıkış</button></form></div>
</section>

<section class="banner" id="banner"><strong id="banner-title">Durum yükleniyor</strong><span id="banner-detail">Local veritabanı kontrol ediliyor…</span></section>

<section class="admin">
<div><div class="kicker">WEB ADMIN</div><h2>NOTAM Sync</h2><p>Bakım oturumu doğrulandı. Delta ve Initial Load işlemleri bu sunucu tarafı oturumla yetkilendirilir.</p></div>
<div class="admin-controls">
<button class="btn" id="delta">Delta Sync</button>
<button class="btn primary" id="full">Initial Load</button>
<div class="result" id="result">Hazır.</div>
</div>
</section>

<section class="metrics">
<article class="metric"><span>Toplam</span><strong id="total">—</strong></article>
<article class="metric good"><span>Aktif</span><strong id="active">—</strong></article>
<article class="metric"><span>Gelecek</span><strong id="future">—</strong></article>
<article class="metric warn"><span>Süresi dolan</span><strong id="expired">—</strong></article>
<article class="metric bad"><span>İptal</span><strong id="cancelled">—</strong></article>
<article class="metric"><span>PERM</span><strong id="perm">—</strong></article>
</section>

<div class="grid">
<section class="panel"><div class="kicker">CONNECTION</div><h2>FAA NMS</h2><div class="rows">
<div class="row"><span>Environment</span><strong id="env">—</strong></div>
<div class="row"><span>API Base</span><strong id="api-base">—</strong></div>
<div class="row"><span>Credentials</span><strong id="cred">—</strong></div>
<div class="row"><span>FAA canlı test</span><strong id="remote">Test edilmedi</strong></div>
<div class="row"><span>HTTP</span><strong id="http">—</strong></div>
<div class="row"><span>PHP extensions</span><strong id="ext">—</strong></div>
</div></section>

<section class="panel"><div class="kicker">SYNC STATE</div><h2>Database</h2><div class="rows">
<div class="row"><span>Otomatik cron</span><strong id="cron-state">Henüz çalışmadı</strong></div>
<div class="row"><span>Son başarılı sync</span><strong id="last-sync">—</strong></div>
<div class="row"><span>Sync yaşı</span><strong id="age">—</strong></div>
<div class="row"><span>Son full load</span><strong id="last-full">—</strong></div>
<div class="row"><span>Son NOTAM update</span><strong id="latest">—</strong></div>
<div class="row"><span>3 günlük temizlik</span><strong id="retention">—</strong></div>
<div class="row"><span>Geometry var / yok</span><strong id="geom">—</strong></div>
<div class="row"><span>Belirsiz durum</span><strong id="unknown">—</strong></div>
<div class="row"><span>DB staging / production</span><strong id="env-counts">—</strong></div>
<div class="row"><span>Son hata</span><strong id="error">—</strong></div>
</div></section>

<section class="panel"><div class="kicker">INITIAL LOAD CACHE</div><h2>Snapshot / Progress</h2><div class="rows">
<div class="row"><span>Cache klasörü</span><strong id="cache-dir">—</strong></div>
<div class="row"><span>Progress dosyası</span><strong id="progress-file">—</strong></div>
<div class="row"><span>Snapshot dosyası</span><strong id="snapshot-file">—</strong></div>
<div class="row"><span>Snapshot boyutu</span><strong id="snapshot-size">—</strong></div>
<div class="row"><span>Snapshot zamanı</span><strong id="snapshot-time">—</strong></div>
<div class="row"><span>Byte offset</span><strong id="snapshot-offset">—</strong></div>
<div class="row"><span>İşlenen / skipped / expected</span><strong id="snapshot-progress">—</strong></div>
<div class="row"><span>Kaynak</span><strong id="snapshot-source">—</strong></div>
</div></section>

<section class="panel"><div class="kicker">CLASSIFICATION</div><h2>Dağılım</h2><div class="classes" id="classes">—</div></section>
<section class="panel"><div class="kicker">NOTES</div><h2>Çalışma şekli</h2><div class="rows">
<div class="row"><span>Initial Load</span><strong>Cron/CLI · bir kez baseline</strong></div>
<div class="row"><span>Delta</span><strong>Cron · 5 dakikada bir</strong></div>
<div class="row"><span>Health refresh</span><strong>FAA request atmaz</strong></div>
<div class="row"><span>FAA test</span><strong>Butonla manuel</strong></div>
</div></section>
</div>
<div class="small" id="generated"></div>
</main>
<script>
(()=>{
const $=id=>document.getElementById(id),fmt=v=>new Intl.NumberFormat('tr-TR').format(Number(v||0));
const dt=v=>{if(!v)return'—';const s=String(v),d=new Date(s.includes('T')?s:s.replace(' ','T')+'Z');return Number.isNaN(d.getTime())?s:d.toLocaleString('tr-TR',{timeZone:'UTC'})+'Z'};
const age=s=>s==null?'—':s<60?s+' sn':s<3600?Math.floor(s/60)+' dk':Math.floor(s/3600)+' sa '+Math.floor((s%3600)/60)+' dk';
const bytes=v=>{if(v==null)return'—';let n=Number(v);if(!Number.isFinite(n))return String(v);const u=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++}return (i? n.toFixed(n>=100?0:n>=10?1:2):Math.round(n))+' '+u[i]};

function paint(d){
 const l=d.local||{},c=l.counts||{},s=l.state||{},r=d.remote||{};
 $('env').textContent=(d.environment||'—').toUpperCase();$('env').className=d.environment==='production'?'ok':'warntext';$('api-base').textContent=d.apiBase||'—';$('total').textContent=fmt(c.total);$('active').textContent=fmt(c.active);$('future').textContent=fmt(c.future);$('expired').textContent=fmt(c.expired);$('cancelled').textContent=fmt(c.cancelled);$('perm').textContent=fmt(c.permanent);$('unknown').textContent=fmt(c.unknown);
 $('cred').textContent=d.credentialsConfigured?'OK':'Eksik';$('cred').className=d.credentialsConfigured?'ok':'badtext';
 $('remote').textContent=r.checked?(r.ok?'Bağlantı başarılı':'Bağlantı başarısız'):'Test edilmedi';$('remote').className=r.checked?(r.ok?'ok':'badtext'):'';
 $('http').textContent=r.upstreamStatus??'—';const ec=l.environmentCounts||{},cr=l.cronState||null;$('env-counts').textContent=fmt(ec.staging)+' / '+fmt(ec.production);if(cr){const cp=cr.progressPercent!=null?' · %'+cr.progressPercent:'';$('cron-state').textContent=(cr.mode||'cron')+cp+' · '+dt(cr.updatedAt);$('cron-state').className=cr.ok===false?'badtext':(cr.running?'warntext':'ok')}else{$('cron-state').textContent='Henüz çalışmadı';$('cron-state').className=''}$('last-sync').textContent=dt(s.last_successful_sync);$('age').textContent=age(l.syncAgeSeconds);$('last-full').textContent=s.last_full_load?dt(s.last_full_load):'Henüz yapılmadı';$('latest').textContent=dt(l.latestNotamUpdate);const rt=l.retentionCleanup||null;$('retention').textContent=rt?(dt(rt.completedAt)+' · '+fmt(rt.deletedTotal||0)+' silindi'):'Henüz çalışmadı';$('retention').className=rt?'ok':'';$('geom').textContent=fmt(c.withGeometry)+' / '+fmt(c.withoutGeometry);$('error').textContent=s.last_error||'Yok';$('error').className=s.last_error?'badtext':'ok';
 const fd=l.fullLoadDiagnostics||{},snapshotPath=(fd.cacheDir&&fd.snapshotFile)?fd.cacheDir.replace(/\/$/,'')+'/'+fd.snapshotFile:'—';
 $('cache-dir').textContent=fd.cacheDir||'—';
 $('progress-file').textContent=fd.progressExists?(fd.progressFile||'Var'):'Yok';
 $('snapshot-file').textContent=snapshotPath;
 $('snapshot-size').textContent=bytes(fd.snapshotBytes);
 $('snapshot-time').textContent=dt(fd.snapshotModifiedAt);
 $('snapshot-offset').textContent=fd.byteOffset==null?'—':fmt(fd.byteOffset);
 $('snapshot-progress').textContent=(fd.processed==null?'—':fmt(fd.processed))+' / '+(fd.skipped==null?'—':fmt(fd.skipped))+' / '+(fd.expected==null?'—':fmt(fd.expected));
 $('snapshot-source').textContent=fd.source||'—';
 $('ext').textContent=Object.entries(l.extensions||{}).map(([k,v])=>k+':'+(v?'ok':'yok')).join(' · ')||'—';
 $('classes').innerHTML=(l.classifications||[]).map(x=>'<div class="class-row"><span>'+String(x.classification).replace(/[&<>"]/g,'')+'</span><strong>'+fmt(x.total)+'</strong></div>').join('')||'Henüz veri yok.';
 const b=$('banner');b.className='banner';
 if(!d.credentialsConfigured){b.classList.add('bad');$('banner-title').textContent='FAA credentials eksik';$('banner-detail').textContent='NMS config kontrol edilmeli.'}
 else if(s.last_error){b.classList.add('bad');$('banner-title').textContent='Son sync hata verdi';$('banner-detail').textContent=s.last_error}
 else if(d.environment!=='production'){b.classList.add('warn');$('banner-title').textContent='STAGING ortamındasın';$('banner-detail').textContent='Production credential geçişi henüz yapılmamış.'}
 else if(!s.last_full_load){b.classList.add('warn');$('banner-title').textContent='Production bağlı · baseline hazırlanıyor';const cr=l.cronState||{};const p=cr.progressPercent!=null?(' · %'+cr.progressPercent):'';$('banner-detail').textContent=(cr.mode==='initial-load'?'Cron Initial Load çalışıyor'+p:'Cron ilk çalışmasında Initial Load başlayacak')+' · DB’de '+fmt(c.total)+' production NOTAM var.'}
 else if(l.syncHealth==='stale'){b.classList.add('warn');$('banner-title').textContent='Delta sync eski';$('banner-detail').textContent='Son sync '+age(l.syncAgeSeconds)+' önce.'}
 else{b.classList.add('good');$('banner-title').textContent='NOTAM veritabanı sağlıklı';$('banner-detail').textContent=fmt(c.active)+' aktif NOTAM · son sync '+age(l.syncAgeSeconds)+' önce.'}
 $('generated').textContent='Health snapshot: '+dt(d.generatedAt);
}
async function load(probe=false){
 $('refresh').disabled=$('probe').disabled=true;if(probe)$('probe').textContent='Test ediliyor…';
 try{const res=await fetch('/main/notam/nms/admin.php?action=health'+(probe?'&probe=1':''),{cache:'no-store'}),d=await res.json();if(!res.ok||!d.ok)throw new Error(d.error||'HTTP '+res.status);paint(d)}
 catch(e){$('banner').className='banner bad';$('banner-title').textContent='Health alınamadı';$('banner-detail').textContent=e.message}
 finally{$('refresh').disabled=$('probe').disabled=false;$('probe').textContent='FAA bağlantısını test et'}
}
async function callAdmin(kind){
 const res=await fetch('/main/notam/nms/admin.php?action=admin-'+kind,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}',cache:'no-store'});
 const raw=await res.text();
 let d=null;
 try{d=JSON.parse(raw)}catch(_){throw new Error('HTTP '+res.status+' · sunucu JSON yerine hata sayfası döndürdü')}
 if(!res.ok||!d.ok)throw new Error(d.detail||d.error||'HTTP '+res.status);
 return d;
}
async function admin(kind){
 $('delta').disabled=$('full').disabled=true;
 try{
   if(kind==='delta'){
     $('result').textContent='Delta Sync çalışıyor…';
     const d=await callAdmin('delta');
     $('result').textContent='Delta tamamlandı: '+fmt(d.processed)+' işlendi.';
     await load(false);
     return;
   }

   const d=await callAdmin('full');
   if(d.managedByCron){
     $('result').textContent=d.complete?'Initial Load tamamlanmış. Delta cron tarafından sürdürülüyor.':'Initial Load cron tarafından otomatik yürütülüyor. Health ekranından ilerlemeyi izleyebilirsin.';
     await load(false);
     return;
   }
   $('result').textContent=d.complete?'Initial Load tamamlandı: '+fmt(d.processed)+' işlendi.':'Initial Load parçası işlendi: '+fmt(d.processed)+' kayıt.';
   await load(false);
 }catch(e){$('result').textContent='Hata: '+e.message}
 finally{$('delta').disabled=$('full').disabled=false}
}
$('refresh').onclick=()=>load(false);$('probe').onclick=()=>load(true);$('delta').onclick=()=>admin('delta');$('full').onclick=()=>admin('full');load(false);setInterval(()=>{if(!document.hidden)load(false)},30000);
})();
</script>
</body>
</html>

