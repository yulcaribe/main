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
:root{
  color-scheme:dark;
  --bg:#061019;--panel:#0a1822;--panel2:#08141d;--line:#173448;--line2:#24475d;
  --text:#e8f6fb;--muted:#86a0ae;--ok:#6fe0a5;--bad:#ff8290;--warn:#ffc46b;--accent:#77ddff
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.shell{width:min(1180px,calc(100% - 28px));margin:auto;padding:24px 0 60px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}
h1{font-size:30px;margin:0}h2{font-size:17px;margin:0}h3{font-size:13px;margin:16px 0 8px}.muted{color:var(--muted)}
.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
button,input,select{border:1px solid var(--line2);background:#081620;color:var(--text);border-radius:9px;padding:9px 11px;font:inherit}
button{cursor:pointer;font-weight:700}button:hover{border-color:#3d6d88}button:disabled{opacity:.5;cursor:default}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:9px;margin-bottom:14px}
.card{border:1px solid var(--line);background:var(--panel);border-radius:13px;padding:12px}
.card small{display:block;color:var(--muted);font-size:10px}.card strong{font-size:17px}.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}.unknown{color:var(--muted)}
details.section{border:1px solid var(--line);background:var(--panel);border-radius:14px;margin-top:10px;overflow:hidden}
details.section[open]{border-color:#24506a}
summary{list-style:none;cursor:pointer;padding:15px 16px;display:flex;align-items:center;justify-content:space-between;gap:14px;user-select:none}
summary::-webkit-details-marker{display:none}
.summary-left{display:flex;align-items:center;gap:10px;min-width:0}.summary-title{font-size:15px;font-weight:800}.summary-sub{display:block;color:var(--muted);font-size:11px;font-weight:500;margin-top:2px}
.summary-right{display:flex;align-items:center;gap:9px;flex:0 0 auto}.chev{color:var(--muted);transition:transform .18s ease}details[open] .chev{transform:rotate(90deg)}
.badge{font-size:11px;font-weight:800;border:1px solid currentColor;border-radius:999px;padding:4px 8px;min-width:66px;text-align:center}
.content{border-top:1px solid var(--line);padding:14px 16px 17px;background:var(--panel2)}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.three{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.kv{display:grid;grid-template-columns:minmax(130px,.7fr) 1.3fr;gap:7px 12px;background:#06111a;border:1px solid #153044;border-radius:10px;padding:11px}
.kv span{color:var(--muted)}.kv strong{word-break:break-word}
pre{margin:0;white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto;background:#050d13;border:1px solid #153044;border-radius:10px;padding:11px;font-size:11px}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:left;padding:8px;border-bottom:1px solid #163143}th{color:var(--muted)}tbody tr[data-i]{cursor:pointer}tbody tr[data-i]:hover{background:#102432}
.settings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.settings label span{display:block;color:var(--muted);font-size:10px;margin-bottom:4px}.field-row{display:flex;gap:6px}.field-row input{min-width:0;flex:1}.settings input,.settings select{width:100%}
.note{padding:10px;border:1px solid #1e3c4e;background:#081722;border-radius:9px;color:var(--muted);font-size:12px}
.login{width:min(360px,calc(100% - 28px));margin:16vh auto;border:1px solid var(--line);background:var(--panel);border-radius:16px;padding:20px}.login input,.login button{width:100%;margin-top:10px}
.status-line{display:flex;gap:8px;align-items:center}.dot{width:8px;height:8px;border-radius:50%;background:var(--muted)}.dot.ok{background:var(--ok)}.dot.bad{background:var(--bad)}.dot.warn{background:var(--warn)}
@media(max-width:760px){.top{align-items:flex-start;flex-direction:column}.two,.three,.settings{grid-template-columns:1fr}.kv{grid-template-columns:1fr}.summary-sub{display:none}.summary-right{gap:5px}}
</style>
</head>
<body>
<?php if (!$authed): ?>
<form class="login" method="post" autocomplete="off">
  <h1>System Health</h1>
  <p class="muted">YulCaribe sistem kontrol ve bakım ekranı.</p>
  <input name="admin_key" type="password" required autofocus autocomplete="current-password" placeholder="Health password">
  <button type="submit">Giriş yap</button>
  <?php if ($loginError): ?><p class="bad">Şifre geçersiz.</p><?php endif; ?>
</form>
<?php else: ?>
<main class="shell">
  <header class="top">
    <div>
      <h1>System Health</h1>
      <div class="muted">API · SQL · Navdata · Map · FAA NMS · Weather · WAFS · ADS-B · Cron · Logs</div>
    </div>
    <div class="actions">
      <button id="refresh">Yenile</button>
      <button id="probe">Tüm bağlantıları test et</button>
      <form method="post"><button name="logout" value="1">Çıkış</button></form>
    </div>
  </header>

  <section id="overview" class="grid"></section>

  <details class="section" id="sec-api">
    <summary>
      <span class="summary-left"><span><span class="summary-title">API v1</span><span class="summary-sub">Tüm public data endpointleri</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="api">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content">
      <table><thead><tr><th>Endpoint</th><th>Dosya</th><th>Aktif test</th><th>HTTP</th><th>Süre</th></tr></thead><tbody id="api-table"></tbody></table>
    </div>
  </details>

  <details class="section" id="sec-db">
    <summary>
      <span class="summary-left"><span><span class="summary-title">MariaDB / Navdata</span><span class="summary-sub">SQL bağlantısı ve navigasyon tabloları</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="db">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content">
      <div class="two">
        <div class="kv" id="db-kv"></div>
        <pre id="navdata-counts">Yükleniyor…</pre>
      </div>
    </div>
  </details>

  <details class="section" id="sec-map">
    <summary>
      <span class="summary-left"><span><span class="summary-title">Map System</span><span class="summary-sub">MapLibre, OSM ve tüm harita layerleri</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="map">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content">
      <div class="two">
        <div class="kv" id="map-kv"></div>
        <div class="kv" id="map-layer-kv"></div>
      </div>
    </div>
  </details>

  <details class="section" id="sec-weather">
    <summary>
      <span class="summary-left"><span><span class="summary-title">Weather</span><span class="summary-sub">METAR · TAF · WAFS · Leaflet</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="weather">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content"><div class="kv" id="weather-kv"></div></div>
  </details>

  <details class="section" id="sec-flights">
    <summary>
      <span class="summary-left"><span><span class="summary-title">Flights / ADS-B</span><span class="summary-sub">adsb.lol bağlantısı ve uçuş layeri</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="flights">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content"><div class="kv" id="flights-kv"></div></div>
  </details>

  <details class="section" id="sec-notam">
    <summary>
      <span class="summary-left"><span><span class="summary-title">FAA NMS / NOTAM</span><span class="summary-sub">FAA auth, sync, retention ve son NOTAM'lar</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="notam">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content">
      <div class="actions" style="margin-bottom:10px"><button id="delta">Delta Sync</button></div>
      <div class="two">
        <div class="kv" id="nms-kv"></div>
        <pre id="nms-detail">Yükleniyor…</pre>
      </div>
      <h3>Son çekilen NOTAM'lar</h3>
      <table><thead><tr><th>NOTAM</th><th>Yer</th><th>Durum</th><th>Son update</th></tr></thead><tbody id="notam-rows"></tbody></table>
      <div class="two" style="margin-top:12px">
        <div><h3>Okunabilir</h3><pre id="notam-parsed">Bir NOTAM seç.</pre></div>
        <div><h3>RAW FAA</h3><pre id="notam-raw">Bir NOTAM seç.</pre></div>
      </div>
    </div>
  </details>

  <details class="section" id="sec-jobs">
    <summary>
      <span class="summary-left"><span><span class="summary-title">Cron & Jobs</span><span class="summary-sub">NMS cron, NOTAM cleanup ve log retention</span></span></span>
      <span class="summary-right"><span class="badge unknown" data-section-status="jobs">UNKNOWN</span><span class="chev">›</span></span>
    </summary>
    <div class="content"><pre id="jobs-state">Yükleniyor…</pre></div>
  </details>

  <details class="section" id="sec-logs">
    <summary>
      <span class="summary-left"><span><span class="summary-title">Logs</span><span class="summary-sub">3 günlük NMS/system log görünümü</span></span></span>
      <span class="summary-right"><span class="badge ok" data-section-status="logs">✓ OK</span><span class="chev">›</span></span>
    </summary>
    <div class="content">
      <div class="actions" style="margin-bottom:10px"><button id="logs-refresh">Logları yenile</button><span class="muted">3 günden eski kayıtlar otomatik temizlenir.</span></div>
      <pre id="logs">Henüz yüklenmedi.</pre>
    </div>
  </details>

  <details class="section" id="sec-settings">
    <summary>
      <span class="summary-left"><span><span class="summary-title">Settings / Maintenance</span><span class="summary-sub">Health, FAA ve SQL bağlantı ayarları</span></span></span>
      <span class="summary-right"><span class="badge ok" data-section-status="settings">✓ OK</span><span class="chev">›</span></span>
    </summary>
    <div class="content">
      <div class="note">DB User, host ve database açık görünür. DB Password ve FAA Client Secret yalnızca <b>Göster</b> dediğinde Health şifren tekrar doğrulanarak 30 saniye açık gösterilir. Secret değerleri loglanmaz.</div>
      <div class="settings" style="margin-top:12px">
        <label><span>Yeni Health şifresi</span><input id="healthPassword" type="password" autocomplete="new-password" placeholder="değiştirme"></label>
        <label><span>FAA Environment</span><select id="nmsEnvironment"><option value="production">production</option><option value="staging">staging</option></select></label>

        <label><span>FAA Client ID</span><input id="nmsClientId" autocomplete="off"></label>
        <label>
          <span>FAA Client Secret</span>
          <div class="field-row"><input id="nmsClientSecret" type="password" autocomplete="off" placeholder="mevcut secret var"><button type="button" data-reveal="faa-client-secret" data-target="nmsClientSecret">Göster</button></div>
        </label>

        <label><span>DB Host</span><input id="dbHost" autocomplete="off"></label>
        <label><span>DB Port</span><input id="dbPort" type="number" autocomplete="off"></label>
        <label><span>DB Name</span><input id="dbName" autocomplete="off"></label>
        <label><span>DB User</span><input id="dbUser" autocomplete="off"></label>
        <label>
          <span>DB Password</span>
          <div class="field-row"><input id="dbPassword" type="password" autocomplete="off" placeholder="mevcut password var"><button type="button" data-reveal="db-password" data-target="dbPassword">Göster</button></div>
        </label>
      </div>
      <div class="actions" style="margin-top:12px"><button id="save">Test et ve kaydet</button><span id="save-status" class="muted"></span></div>
    </div>
  </details>
</main>

<script>
const API="/main/api/v1/health.php";
const $=id=>document.getElementById(id);
const esc=v=>String(v??"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
let snapshot=null;
let latestNotams=[];
let notamsLoaded=false;
let logsLoaded=false;
let settingsTouched=false;

async function req(action,opt={}){
  const r=await fetch(API+"?action="+action,{cache:"no-store",...opt});
  const d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||("HTTP "+r.status));
  return d;
}

function probeState(p){
  if(!p)return "unknown";
  if(p.ok)return "ok";
  if(Number(p.status)===429)return "warn";
  return "error";
}
function combine(states){
  if(states.includes("error"))return "error";
  if(states.includes("warn"))return "warn";
  if(states.includes("unknown"))return "unknown";
  return "ok";
}
function badgeText(state){
  return state==="ok"?"✓ OK":state==="warn"?"! WARNING":state==="error"?"✕ ERROR":"? UNKNOWN";
}
function setSection(name,state){
  const el=document.querySelector('[data-section-status="'+name+'"]');
  if(!el)return;
  el.className="badge "+(state==="error"?"bad":state);
  el.textContent=badgeText(state);
}
function card(name,state,sub=""){
  const cls=state==="ok"?"ok":state==="error"?"bad":state==="warn"?"warn":"unknown";
  return '<div class="card"><small>'+esc(name)+'</small><strong class="'+cls+'">'+badgeText(state)+'</strong><small>'+esc(sub)+'</small></div>';
}
function statusHtml(state,label){
  const cls=state==="ok"?"ok":state==="error"?"bad":state==="warn"?"warn":"unknown";
  return '<span class="'+cls+'">'+esc(label||badgeText(state))+'</span>';
}
function row(label,value){
  return '<span>'+esc(label)+'</span><strong>'+String(value??"—")+'</strong>';
}
function probeLabel(p){
  if(!p)return statusHtml("unknown","Aktif test yok");
  const s=probeState(p);
  const bits=[badgeText(s)];
  if(p.status)bits.push("HTTP "+p.status);
  if(p.ms!=null)bits.push(p.ms+" ms");
  if(p.error)bits.push(p.error);
  return statusHtml(s,bits.join(" · "));
}

function render(d){
  snapshot=d;
  const net=d.network||{};
  const apiProbes=net.api||{};
  const files=d.apiFiles||{};
  const db=d.database||{};
  const jobs=d.jobs||{};
  const local=d.nms||{};
  const nmsCfg=d.nmsConfig||{};

  const apiFilesOk=Object.values(files).every(Boolean);
  const apiProbeList=Object.values(apiProbes);
  const apiState=apiProbeList.length
    ? combine(apiProbeList.map(probeState).concat(apiFilesOk?["ok"]:["error"]))
    : (apiFilesOk?"ok":"error");

  const dbState=db.ok?"ok":"error";
  const mapState=combine([
    probeState(net.mapPage),
    probeState(net.mapScript),
    probeState(net.maplibre),
    probeState(net.osm),
    probeState(apiProbes.navdata),
    probeState(apiProbes.notam),
    probeState(apiProbes.wafs),
    probeState(apiProbes.flights)
  ]);
  const weatherState=combine([
    probeState(apiProbes.metar),
    probeState(apiProbes.taf),
    probeState(apiProbes.wafs),
    probeState(net.leaflet)
  ]);
  const flightsState=probeState(apiProbes.flights);

  let notamState="ok";
  if(nmsCfg.credentialsConfigured===false)notamState="error";
  else if(local.state?.last_error)notamState="error";
  else if(local.syncHealth==="stale")notamState="warn";
  else if(net.faa)notamState=combine([notamState,probeState(net.faa)]);

  const cronState=jobs.cron?.status||"unknown";

  setSection("api",apiState);
  setSection("db",dbState);
  setSection("map",mapState);
  setSection("weather",weatherState);
  setSection("flights",flightsState);
  setSection("notam",notamState);
  setSection("jobs",cronState);
  setSection("logs","ok");
  setSection("settings","ok");

  $("overview").innerHTML=[
    card("API",apiState,Object.keys(files).length+" endpoint"),
    card("MariaDB",dbState,db.latencyMs!=null?db.latencyMs+" ms":""),
    card("Map",mapState,"MapLibre"),
    card("Weather",weatherState,"METAR / TAF / WAFS"),
    card("ADS-B",flightsState,"adsb.lol"),
    card("FAA NMS",notamState,local.syncHealth||""),
    card("Cron",cronState,jobs.cron?.ageSeconds!=null?jobs.cron.ageSeconds+" sn":""),
    card("Logs","ok","3 gün")
  ].join("");

  const orderedApis=["index","navdata","notam","weather","metar","taf","wafs","flights","briefing","modelwx","health"];
  $("api-table").innerHTML=orderedApis.map(name=>{
    const file=files[name];
    const p=apiProbes[name];
    return '<tr><td>'+esc(name)+'</td><td>'+statusHtml(file?"ok":"error",file?"✓ Var":"✕ Yok")+'</td><td>'+probeLabel(p)+'</td><td>'+esc(p?.status??"—")+'</td><td>'+esc(p?.ms!=null?p.ms+" ms":"—")+'</td></tr>';
  }).join("");

  $("db-kv").innerHTML=[
    row("Bağlantı",statusHtml(dbState,db.ok?"✓ OK":"✕ ERROR")),
    row("Server",esc(db.serverVersion||"—")),
    row("Host",esc(db.host||"—")),
    row("Port",esc(db.port||"—")),
    row("Database",esc(db.database||"—")),
    row("DB User",esc(db.user||"—")),
    row("Latency",db.latencyMs!=null?esc(db.latencyMs+" ms"):"—")
  ].join("");
  $("navdata-counts").textContent=JSON.stringify(db.counts||{},null,2);

  $("map-kv").innerHTML=[
    row("Unified /main/map/",probeLabel(net.mapPage)),
    row("map.js",probeLabel(net.mapScript)),
    row("MapLibre",probeLabel(net.maplibre)),
    row("OSM Tiles",probeLabel(net.osm)),
    row("Leaflet (Briefing)",probeLabel(net.leaflet))
  ].join("");
  $("map-layer-kv").innerHTML=[
    row("Navdata layer",probeLabel(apiProbes.navdata)),
    row("NOTAM layer",probeLabel(apiProbes.notam)),
    row("WAFS layer",probeLabel(apiProbes.wafs)),
    row("ADS-B layer",probeLabel(apiProbes.flights))
  ].join("");

  $("weather-kv").innerHTML=[
    row("METAR API",probeLabel(apiProbes.metar)),
    row("TAF API",probeLabel(apiProbes.taf)),
    row("Combined Weather",probeLabel(apiProbes.weather)),
    row("WAFS API",probeLabel(apiProbes.wafs)),
    row("AWC WAFS upstream",probeLabel(net.wafsUpstream)),
    row("Leaflet CDN",probeLabel(net.leaflet))
  ].join("");

  $("flights-kv").innerHTML=[
    row("Flights API",probeLabel(apiProbes.flights)),
    row("Upstream",esc(apiProbes.flights?.meta?.source||"ADSB.lol")),
    row("HTTP",esc(apiProbes.flights?.status??"—")),
    row("Aircraft",esc(apiProbes.flights?.meta?.count??"—")),
    row("Response",apiProbes.flights?.ms!=null?esc(apiProbes.flights.ms+" ms"):"—")
  ].join("");

  $("nms-kv").innerHTML=[
    row("Environment",esc(nmsCfg.environment||"—")),
    row("FAA Credentials",statusHtml(nmsCfg.credentialsConfigured?"ok":"error",nmsCfg.credentialsConfigured?"✓ Configured":"✕ Missing")),
    row("FAA Auth Test",probeLabel(net.faa)),
    row("Sync Health",statusHtml(local.syncHealth==="ok"?"ok":local.syncHealth==="warning"?"warn":local.syncHealth==="stale"?"warn":"unknown",local.syncHealth||"—")),
    row("Sync Age",local.syncAgeSeconds!=null?esc(local.syncAgeSeconds+" sn"):"—"),
    row("Latest NOTAM",esc(local.latestNotamUpdate||"—")),
    row("Active",esc(local.counts?.active??"—")),
    row("Future",esc(local.counts?.future??"—")),
    row("Expired",esc(local.counts?.expired??"—")),
    row("Cancelled",esc(local.counts?.cancelled??"—")),
    row("PERM",esc(local.counts?.permanent??"—"))
  ].join("");
  $("nms-detail").textContent=JSON.stringify({
    state:local.state,
    cronState:local.cronState,
    retentionCleanup:local.retentionCleanup,
    fullLoadDiagnostics:local.fullLoadDiagnostics,
    environmentCounts:local.environmentCounts
  },null,2);

  $("jobs-state").textContent=JSON.stringify(jobs,null,2);

  const s=d.settings||{};
  if(!settingsTouched){
    $("nmsEnvironment").value=(s.nms?.environment||"production").toLowerCase().includes("prod")?"production":"staging";
    $("nmsEnvironment").dataset.original=$("nmsEnvironment").value;
    $("nmsClientId").value=s.nms?.clientId||"";
    $("nmsClientId").dataset.original=$("nmsClientId").value;
    $("nmsClientSecret").placeholder=s.nms?.clientSecretConfigured?"mevcut secret var":"secret yok";
    $("dbHost").value=s.db?.host||"";
    $("dbHost").dataset.original=$("dbHost").value;
    $("dbPort").value=s.db?.port||3306;
    $("dbPort").dataset.original=$("dbPort").value;
    $("dbName").value=s.db?.database||"";
    $("dbName").dataset.original=$("dbName").value;
    $("dbUser").value=s.db?.user||"";
    $("dbUser").dataset.original=$("dbUser").value;
    $("dbPassword").placeholder=s.db?.passwordConfigured?"mevcut password var":"password yok";
  }
}

async function load(probe=false){
  $("refresh").disabled=$("probe").disabled=true;
  const old=$("probe").textContent;
  if(probe)$("probe").textContent="Test ediliyor…";
  try{
    render(await req("snapshot"+(probe?"&probe=1":"")));
  }catch(e){
    alert("Health yüklenemedi: "+e.message);
  }finally{
    $("refresh").disabled=$("probe").disabled=false;
    $("probe").textContent=old;
  }
}

async function loadNotams(){
  if(notamsLoaded)return;
  const d=await req("notams&limit=20");
  latestNotams=d.items||[];
  notamsLoaded=true;
  $("notam-rows").innerHTML=latestNotams.map((x,i)=>
    '<tr data-i="'+i+'"><td>'+esc(x.parsed.ident)+'</td><td>'+esc(x.parsed.location)+'</td><td>'+esc(x.parsed.status)+'</td><td>'+esc(x.parsed.lastUpdated)+'</td></tr>'
  ).join("");
  document.querySelectorAll("#notam-rows tr").forEach(tr=>tr.onclick=()=>{
    const x=latestNotams[Number(tr.dataset.i)];
    $("notam-parsed").textContent=JSON.stringify(x.parsed,null,2);
    $("notam-raw").textContent=typeof x.raw==="string"?x.raw:JSON.stringify(x.raw,null,2);
  });
}

async function loadLogs(){
  const d=await req("logs&lines=300");
  logsLoaded=true;
  $("logs").textContent=(d.lines||[]).join("\n")||"Log yok.";
}

async function revealSecret(kind,targetId,button){
  const input=$(targetId);
  if(input.type==="text"){
    input.type="password";
    button.textContent="Göster";
    return;
  }

  const password=prompt("Health şifreni tekrar gir:");
  if(password===null)return;

  button.disabled=true;
  try{
    const d=await req("secret-reveal",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({kind,password})
    });
    input.value=d.secret||"";
    input.dataset.original=d.secret||"";
    input.type="text";
    button.textContent="Gizle";
    setTimeout(()=>{
      if(input.type==="text"){
        input.type="password";
        button.textContent="Göster";
      }
    },30000);
  }catch(e){
    alert(e.message);
  }finally{
    button.disabled=false;
  }
}

document.querySelectorAll("[data-reveal]").forEach(button=>{
  button.addEventListener("click",()=>revealSecret(button.dataset.reveal,button.dataset.target,button));
});

$("refresh").onclick=()=>load(false);
$("probe").onclick=()=>load(true);
$("logs-refresh").onclick=loadLogs;
$("delta").onclick=async()=>{
  $("delta").disabled=true;
  try{
    const d=await req("nms-delta",{method:"POST",body:"{}"});
    $("nms-detail").textContent=JSON.stringify(d,null,2);
    await load(false);
    notamsLoaded=false;
    await loadNotams();
  }catch(e){alert(e.message)}
  finally{$("delta").disabled=false}
};

$("save").onclick=async()=>{
  const faaSecret=$("nmsClientSecret");
  const dbPassword=$("dbPassword");

  const body={
    healthPassword:$("healthPassword").value,
    nmsEnvironment:$("nmsEnvironment").dataset.original===$("nmsEnvironment").value?"":$("nmsEnvironment").value,
    nmsClientId:$("nmsClientId").dataset.original===$("nmsClientId").value?"":$("nmsClientId").value,
    nmsClientSecret:faaSecret.dataset.original===faaSecret.value?"":faaSecret.value,
    dbHost:$("dbHost").dataset.original===$("dbHost").value?"":$("dbHost").value,
    dbPort:$("dbPort").dataset.original===$("dbPort").value?"":$("dbPort").value,
    dbName:$("dbName").dataset.original===$("dbName").value?"":$("dbName").value,
    dbUser:$("dbUser").dataset.original===$("dbUser").value?"":$("dbUser").value,
    dbPassword:dbPassword.dataset.original===dbPassword.value?"":dbPassword.value
  };

  $("save").disabled=true;
  $("save-status").textContent="Test ediliyor…";
  try{
    const d=await req("settings-save",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify(body)
    });
    $("save-status").textContent=d.changed?.length?"Kaydedildi: "+d.changed.join(", "):"Değişiklik yok.";
    $("healthPassword").value="";
    faaSecret.value="";faaSecret.type="password";delete faaSecret.dataset.original;
    dbPassword.value="";dbPassword.type="password";delete dbPassword.dataset.original;
    document.querySelector('[data-target="nmsClientSecret"]').textContent="Göster";
    document.querySelector('[data-target="dbPassword"]').textContent="Göster";
    settingsTouched=false;
    await load(false);
  }catch(e){
    $("save-status").textContent="Hata: "+e.message;
  }finally{
    $("save").disabled=false;
  }
};

document.querySelectorAll("#sec-settings input,#sec-settings select").forEach(el=>{
  el.addEventListener("input",()=>{settingsTouched=true});
  el.addEventListener("change",()=>{settingsTouched=true});
});

$("sec-notam").addEventListener("toggle",()=>{if($("sec-notam").open)loadNotams().catch(e=>alert(e.message))});
$("sec-logs").addEventListener("toggle",()=>{if($("sec-logs").open&&!logsLoaded)loadLogs().catch(e=>alert(e.message))});

load(true);
setInterval(()=>{if(!document.hidden)load(false)},30000);
</script>
<?php endif; ?>
</body>
</html>