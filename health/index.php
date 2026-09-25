<?php
declare(strict_types=1);

require_once __DIR__ . '/../notam/nms/internal/auth.php';
nmsHealthSessionStart();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['logout'])) {
    nmsHealthLogout();
    header('Location: /main/health/');
    exit;
}

$loginError = false;
if (!nmsHealthAuthenticated() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $loginError = !nmsHealthLogin(trim((string)($_POST['admin_key'] ?? '')));
    if (!$loginError) {
        header('Location: /main/health/');
        exit;
    }
}
$authed = nmsHealthAuthenticated();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#071017">
<title>System Health | YulCaribe</title>
<style>
:root{color-scheme:dark;--bg:#061019;--panel:#0a1822;--line:#173448;--text:#e8f6fb;--muted:#86a0ae;--ok:#6fe0a5;--bad:#ff8290;--warn:#ffc46b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 Inter,system-ui,sans-serif}
.shell{width:min(1180px,calc(100% - 28px));margin:auto;padding:24px 0 60px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}
h1{font-size:30px;margin:0}h2{font-size:17px;margin:0 0 12px}h3{font-size:13px;margin:14px 0 8px}.muted{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.card,.panel{border:1px solid var(--line);background:var(--panel);border-radius:15px;padding:14px}.panel{margin-top:12px}
.card small{display:block;color:var(--muted);font-size:10px}.card strong{font-size:18px}.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.actions{display:flex;gap:8px;flex-wrap:wrap}button,input,select{border:1px solid #24475d;background:#081620;color:var(--text);border-radius:9px;padding:9px 11px}button{cursor:pointer;font-weight:700}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}pre{margin:0;white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto;background:#050d13;border:1px solid #153044;border-radius:10px;padding:11px;font-size:11px}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:left;padding:8px;border-bottom:1px solid #163143}th{color:var(--muted)}
.settings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.settings label span{display:block;color:var(--muted);font-size:10px;margin-bottom:4px}.settings input,.settings select{width:100%}
.login{width:min(360px,calc(100% - 28px));margin:16vh auto;border:1px solid var(--line);background:var(--panel);border-radius:16px;padding:20px}.login input,.login button{width:100%;margin-top:10px}
@media(max-width:760px){.top,.two,.settings{display:block}.top>*{margin-bottom:10px}.settings label{display:block;margin-bottom:9px}}
</style>
</head>
<body>
<?php if (!$authed): ?>
<form class="login" method="post" autocomplete="off">
  <h1>System Health</h1>
  <p class="muted">YulCaribe bakım ve yönetim ekranı.</p>
  <input name="admin_key" type="password" required autofocus autocomplete="current-password" placeholder="Health password">
  <button type="submit">Giriş yap</button>
  <?php if ($loginError): ?><p class="bad">Şifre geçersiz.</p><?php endif; ?>
</form>
<?php else: ?>
<main class="shell">
  <header class="top">
    <div><h1>System Health</h1><div class="muted">API · SQL · FAA NMS · METAR/TAF · WAFS · ADS-B · MapLibre · Logs</div></div>
    <div class="actions">
      <button id="refresh">Yenile</button>
      <button id="probe">Bağlantıları test et</button>
      <form method="post"><button name="logout" value="1">Çıkış</button></form>
    </div>
  </header>

  <section id="overview" class="grid"></section>

  <section class="panel">
    <h2>API / MariaDB / Navdata</h2>
    <div class="two">
      <pre id="api-state">Yükleniyor…</pre>
      <pre id="db-state">Yükleniyor…</pre>
    </div>
  </section>

  <section class="panel">
    <h2>FAA NMS / NOTAM</h2>
    <div class="actions"><button id="delta">Delta Sync</button></div>
    <pre id="nms-state">Yükleniyor…</pre>
    <h3>Son çekilen NOTAM'lar</h3>
    <table>
      <thead><tr><th>NOTAM</th><th>Yer</th><th>Durum</th><th>Son update</th></tr></thead>
      <tbody id="notam-rows"></tbody>
    </table>
    <div class="two" style="margin-top:12px">
      <div><h3>Okunabilir</h3><pre id="notam-parsed">Bir NOTAM seç.</pre></div>
      <div><h3>RAW FAA</h3><pre id="notam-raw">Bir NOTAM seç.</pre></div>
    </div>
  </section>

  <section class="panel">
    <h2>Bağlantılar</h2>
    <pre id="network-state">Henüz aktif test yapılmadı.</pre>
  </section>

  <section class="panel">
    <h2>Logs <span class="muted">· 3 gün</span></h2>
    <div class="actions"><button id="logs-refresh">Logları yenile</button></div>
    <pre id="logs" style="margin-top:10px">—</pre>
  </section>

  <section class="panel">
    <h2>Settings / Maintenance</h2>
    <p class="muted">Secret ve şifre alanları ekrana geri basılmaz. Boş bırakırsan değişmez. FAA ve SQL ayarları kaydetmeden önce test edilir.</p>
    <div class="settings">
      <label><span>Yeni Health şifresi</span><input id="healthPassword" type="password" placeholder="değiştirme"></label>
      <label><span>FAA Environment</span><select id="nmsEnvironment"><option value="production">production</option><option value="staging">staging</option></select></label>
      <label><span>FAA Client ID</span><input id="nmsClientId" placeholder="değiştirme"></label>
      <label><span>FAA Client Secret</span><input id="nmsClientSecret" type="password" placeholder="değiştirme"></label>
      <label><span>DB Host</span><input id="dbHost"></label>
      <label><span>DB Port</span><input id="dbPort" type="number"></label>
      <label><span>DB Name</span><input id="dbName"></label>
      <label><span>DB User</span><input id="dbUser"></label>
      <label><span>DB Password</span><input id="dbPassword" type="password" placeholder="değiştirme"></label>
    </div>
    <div class="actions" style="margin-top:12px"><button id="save">Test et ve kaydet</button><span id="save-status" class="muted"></span></div>
  </section>
</main>
<script>
const API="/main/api/v1/health.php";
const $=id=>document.getElementById(id);
const esc=v=>String(v??"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
let latestNotams=[];

async function req(action,opt={}){
  const r=await fetch(API+"?action="+action,{cache:"no-store",...opt});
  const d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||("HTTP "+r.status));
  return d;
}
function card(name,state,sub=""){
  const cls=state===true?"ok":state===false?"bad":"warn";
  const label=state===true?"OK":state===false?"ERROR":"UNKNOWN";
  return '<div class="card"><small>'+esc(name)+'</small><strong class="'+cls+'">'+label+'</strong><small>'+esc(sub)+'</small></div>';
}
function paint(d){
  const n=d.network||{},db=d.database||{},local=d.nms||{},apis=d.apis||{};
  $("overview").innerHTML=[
    card("API",Object.values(apis).every(Boolean),"v1"),
    card("MariaDB",db.ok,db.latencyMs!=null?db.latencyMs+" ms":""),
    card("FAA NMS",n.faa?.ok??null,n.faa?.status||""),
    card("METAR",n.metar?.ok??null,n.metar?.status||""),
    card("TAF",n.taf?.ok??null,n.taf?.status||""),
    card("WAFS",n.wafs?.ok??null,n.wafs?.status||""),
    card("ADSB.lol",n.adsb?.ok??null,n.adsb?.aircraft!=null?n.adsb.aircraft+" aircraft":""),
    card("MapLibre",n.maplibre?.ok??null,n.maplibre?.status||""),
    card("Leaflet",n.leaflet?.ok??null,n.leaflet?.status||""),
    card("OSM Tiles",n.osm?.ok??null,n.osm?.status||"")
  ].join("");
  $("api-state").textContent=JSON.stringify(apis,null,2);
  $("db-state").textContent=JSON.stringify(db,null,2);
  $("nms-state").textContent=JSON.stringify({config:d.nmsConfig,local},null,2);
  $("network-state").textContent=n.checkedAt?JSON.stringify(n,null,2):"Henüz aktif test yapılmadı. Bağlantıları test et.";
  const s=d.settings||{};
  $("nmsEnvironment").value=s.nms?.environment||"production";
  $("nmsClientId").placeholder=s.nms?.clientId?("mevcut: "+s.nms.clientId):"değiştirme";
  $("dbHost").value=s.db?.host||"";
  $("dbPort").value=s.db?.port||3306;
  $("dbName").value=s.db?.database||"";
  $("dbUser").value=s.db?.user||"";
}
async function load(probe=false){
  $("refresh").disabled=$("probe").disabled=true;
  try{paint(await req("snapshot"+(probe?"&probe=1":"")))}
  catch(e){alert(e.message)}
  finally{$("refresh").disabled=$("probe").disabled=false}
}
async function loadNotams(){
  const d=await req("notams&limit=20");latestNotams=d.items||[];
  $("notam-rows").innerHTML=latestNotams.map((x,i)=>'<tr data-i="'+i+'" style="cursor:pointer"><td>'+esc(x.parsed.ident)+'</td><td>'+esc(x.parsed.location)+'</td><td>'+esc(x.parsed.status)+'</td><td>'+esc(x.parsed.lastUpdated)+'</td></tr>').join("");
  document.querySelectorAll("#notam-rows tr").forEach(row=>row.onclick=()=>{
    const x=latestNotams[Number(row.dataset.i)];
    $("notam-parsed").textContent=JSON.stringify(x.parsed,null,2);
    $("notam-raw").textContent=typeof x.raw==="string"?x.raw:JSON.stringify(x.raw,null,2);
  });
}
async function loadLogs(){const d=await req("logs&lines=300");$("logs").textContent=(d.lines||[]).join("\n")||"Log yok."}

$("refresh").onclick=()=>load(false);
$("probe").onclick=()=>load(true);
$("logs-refresh").onclick=loadLogs;
$("delta").onclick=async()=>{try{$("nms-state").textContent=JSON.stringify(await req("nms-delta",{method:"POST",body:"{}"}),null,2);await load(false)}catch(e){alert(e.message)}};
$("save").onclick=async()=>{
  const body={
    healthPassword:$("healthPassword").value,
    nmsEnvironment:$("nmsEnvironment").value,
    nmsClientId:$("nmsClientId").value,
    nmsClientSecret:$("nmsClientSecret").value,
    dbHost:$("dbHost").value,
    dbPort:$("dbPort").value,
    dbName:$("dbName").value,
    dbUser:$("dbUser").value,
    dbPassword:$("dbPassword").value
  };
  $("save-status").textContent="Test ediliyor…";
  try{
    const d=await req("settings-save",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)});
    $("save-status").textContent="Kaydedildi: "+(d.changed||[]).join(", ");
    $("healthPassword").value=$("nmsClientSecret").value=$("dbPassword").value="";
    await load(false);
  }catch(e){$("save-status").textContent="Hata: "+e.message}
};
load(false);loadNotams();loadLogs();
setInterval(()=>{if(!document.hidden)load(false)},30000);
</script>
<?php endif; ?>
</body>
</html>