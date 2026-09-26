(() => {
  "use strict";

  const page = document.body?.dataset?.page || "";
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");

  function setActiveNav(name) {
    document.querySelectorAll("[data-nav]").forEach(link => {
      if (link.dataset.nav === name) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
  }

  function weatherDictionary(language) {
    const tr = {
      wind:"Rüzgar",visibility:"Görüş",cloud:"Bulut",weather:"Hava",temperature:"Sıcaklık",dew:"Çiy noktası",qnh:"QNH",station:"İstasyon",observation:"Gözlem",issued:"Yayın",validity:"Geçerlilik",decoded:"Çözümleme",current:"Mevcut",forecast:"Tahmin",interpretation:"Hava Durumu Yorumu",calm:"Sakin",variable:"Değişken",noSignificant:"Belirgin olumsuz hava işareti yok.",lowVisibility:"Düşük görüş",strongWind:"Kuvvetli rüzgar",gust:"Hamle",cavok:"CAVOK: görüş ve bulut şartları belirgin kısıt göstermiyor."
    };
    const en = {
      wind:"Wind",visibility:"Visibility",cloud:"Cloud",weather:"Weather",temperature:"Temperature",dew:"Dew point",qnh:"QNH",station:"Station",observation:"Observation",issued:"Issued",validity:"Validity",decoded:"Decoded",current:"Current",forecast:"Forecast",interpretation:"Weather interpretation",calm:"Calm",variable:"Variable",noSignificant:"No significant adverse weather signal detected.",lowVisibility:"Low visibility",strongWind:"Strong wind",gust:"Gust",cavok:"CAVOK: visibility and cloud conditions show no significant restriction."
    };
    return language === "en" ? en : tr;
  }

  const WX = {
    RA:{tr:"Yağmur",en:"Rain"}, DZ:{tr:"Çisenti",en:"Drizzle"}, SN:{tr:"Kar",en:"Snow"},
    FG:{tr:"Sis",en:"Fog"}, BR:{tr:"Pus",en:"Mist"}, TS:{tr:"Gök gürültülü fırtına",en:"Thunderstorm"},
    SH:{tr:"Sağanak",en:"Shower"}, FZ:{tr:"Donan",en:"Freezing"}, GR:{tr:"Dolu",en:"Hail"},
    SQ:{tr:"Bora",en:"Squall"}, FC:{tr:"Huni/tornado",en:"Funnel/tornado"}
  };

  function decodeWeatherToken(token, language) {
    let code = String(token || "").toUpperCase();
    if (!code) return null;
    const parts = [];
    const intensity = code.startsWith("+") ? (language === "en" ? "Heavy" : "Kuvvetli") : code.startsWith("-") ? (language === "en" ? "Light" : "Hafif") : "";
    code = code.replace(/^[+-]/, "").replace(/^VC/, "");
    for (let i = 0; i < code.length; i += 2) {
      const item = WX[code.slice(i, i + 2)]?.[language];
      if (item) parts.push(item);
    }
    if (!parts.length) return null;
    return [intensity, ...parts].filter(Boolean).join(" ");
  }

  function decodeConditions(raw, language) {
    const d = weatherDictionary(language);
    const tokens = String(raw || "").replace(/\s+/g, " ").trim().split(" ").filter(Boolean);
    const rows = [];
    const clouds = [];
    const phenomena = [];

    const station = tokens.find(t => /^[A-Z]{4}$/.test(t));
    if (station) rows.push([d.station, station]);
    const time = tokens.find(t => /^\d{6}Z$/.test(t));
    if (time) rows.push([d.observation, `${time.slice(0,2)} ${time.slice(2,4)}:${time.slice(4,6)} UTC`]);

    for (const token of tokens) {
      let m = token.match(/^(\d{3}|VRB)(\d{2,3})(G(\d{2,3}))?KT$/);
      if (m) {
        const dir = m[1] === "VRB" ? d.variable : `${Number(m[1])}°`;
        const speed = Number(m[2]);
        rows.push([d.wind, `${dir} · ${speed} kt${m[4] ? ` · ${d.gust} ${Number(m[4])} kt` : ""}`]);
        continue;
      }
      if (token === "CAVOK" || token === "9999") {
        rows.push([d.visibility, token === "CAVOK" ? "10 km+ · CAVOK" : "10 km+"]);
        continue;
      }
      if (/^\d{4}$/.test(token)) {
        rows.push([d.visibility, `${Number(token).toLocaleString(language === "tr" ? "tr-TR" : "en-US")} m`]);
        continue;
      }
      m = token.match(/^(FEW|SCT|BKN|OVC|VV)(\d{3})(CB|TCU)?$/);
      if (m) {
        clouds.push(`${m[1]} ${Number(m[2]) * 100} ft${m[3] ? ` ${m[3]}` : ""}`);
        continue;
      }
      m = token.match(/^(M?\d{2})\/(M?\d{2})$/);
      if (m) {
        const num = v => (v.startsWith("M") ? -1 : 1) * Number(v.replace("M", ""));
        rows.push([d.temperature, `${num(m[1])} °C`]);
        rows.push([d.dew, `${num(m[2])} °C`]);
        continue;
      }
      m = token.match(/^Q(\d{4})$/);
      if (m) { rows.push([d.qnh, `${Number(m[1])} hPa`]); continue; }
      const wx = decodeWeatherToken(token, language);
      if (wx) phenomena.push(wx);
    }
    if (phenomena.length) rows.push([d.weather, phenomena.join(" · ")]);
    if (clouds.length) rows.push([d.cloud, clouds.join(" / ")]);
    return rows;
  }

  function renderDecode(container, title, rows, language) {
    if (!container) return;
    container.innerHTML = "";
    if (!rows.length) { container.hidden = true; return; }
    const section = document.createElement("section");
    section.className = "decode-section";
    section.innerHTML = `<h3>${esc(title)} · ${esc(weatherDictionary(language).decoded)}</h3>`;
    rows.forEach(([label, value]) => {
      const row = document.createElement("div");
      row.className = "decode-row";
      row.innerHTML = `<span>${esc(label)}</span><strong>${esc(value)}</strong>`;
      section.appendChild(row);
    });
    container.appendChild(section);
    container.hidden = false;
  }

  function buildInterpretation(data, language) {
    const d = weatherDictionary(language);
    const items = [];
    const raw = String(data?.metar?.raw || "");
    const tokens = raw.split(/\s+/).filter(Boolean);
    if (tokens.includes("CAVOK")) items.push({text:d.cavok,attention:false});
    const wind = tokens.map(t => t.match(/^(\d{3}|VRB)(\d{2,3})(G(\d{2,3}))?KT$/)).find(Boolean);
    if (wind) {
      const speed = Number(wind[2]);
      if (speed >= 20) items.push({text:`${d.strongWind}: ${speed} kt${wind[4] ? `, ${d.gust} ${Number(wind[4])} kt` : ""}`,attention:true});
      else if (wind[4] && Number(wind[4]) >= 25) items.push({text:`${d.gust}: ${Number(wind[4])} kt`,attention:true});
    }
    const vis = tokens.find(t => /^\d{4}$/.test(t));
    if (vis && Number(vis) < 5000) items.push({text:`${d.lowVisibility}: ${Number(vis)} m`,attention:true});
    const hazardous = tokens.find(t => /TS|FZRA|SN|FG|SQ|FC/.test(t));
    if (hazardous) items.push({text:`${d.weather}: ${decodeWeatherToken(hazardous, language) || hazardous}`,attention:true});
    if (!items.length) items.push({text:d.noSignificant,attention:false});
    return items;
  }

  function initWeather() {
    const form = $("weather-search-form");
    const input = $("weather-icao");
    if (!form || !input) return;
    let language = localStorage.getItem("yulcaribe-weather-language") || "tr";
    let lastData = null;
    let controller = null;

    const feedback = (text, state = "") => {
      const el = $("weather-feedback");
      if (!el) return;
      el.className = "status-line" + (state ? ` is-${state}` : "");
      el.textContent = text;
    };

    const render = data => {
      lastData = data;
      const results = $("weather-results");
      const metar = $("weather-metar");
      const taf = $("weather-taf");
      if (!results || !metar || !taf) return;
      metar.textContent = data?.metar?.raw || "METAR bulunamadı.";
      taf.textContent = data?.taf?.raw || "TAF bulunamadı.";
      renderDecode($("weather-metar-decode"), "METAR", decodeConditions(data?.metar?.raw, language), language);
      renderDecode($("weather-taf-decode"), "TAF", decodeConditions(data?.taf?.raw, language), language);
      const panel = $("weather-interpretation");
      const title = $("weather-interpretation-title");
      const content = $("weather-interpretation-content");
      if (panel && title && content) {
        const d = weatherDictionary(language);
        title.textContent = d.interpretation;
        content.innerHTML = `<div class="weather-summary-section"><h3>${esc(d.current)}</h3>${buildInterpretation(data, language).map(i => `<div class="weather-summary-item${i.attention ? " is-attention" : ""}">${esc(i.text)}</div>`).join("")}</div>`;
        panel.hidden = false;
      }
      const source = $("weather-source-line");
      if (source) source.textContent = `Kaynak · ${data?.source || "AviationWeather.gov"}`;
      results.hidden = false;
      feedback(`${data?.icao || ""} · ${[data?.metar?.raw ? "METAR" : "", data?.taf?.raw ? "TAF" : ""].filter(Boolean).join(" + ") || "veri yok"}`, data?.metar?.raw || data?.taf?.raw ? "success" : "error");
    };

    document.querySelectorAll("[data-weather-language]").forEach(button => {
      const sync = () => button.setAttribute("aria-pressed", String(button.dataset.weatherLanguage === language));
      sync();
      button.addEventListener("click", () => {
        language = button.dataset.weatherLanguage === "en" ? "en" : "tr";
        localStorage.setItem("yulcaribe-weather-language", language);
        document.querySelectorAll("[data-weather-language]").forEach(b => b.setAttribute("aria-pressed", String(b.dataset.weatherLanguage === language)));
        if (lastData) render(lastData);
      });
    });

    async function request(codeRaw) {
      const code = String(codeRaw || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 4);
      input.value = code;
      if (!/^[A-Z0-9]{4}$/.test(code)) { feedback("4 karakterli ICAO kodu gir.", "error"); return; }
      controller?.abort();
      controller = new AbortController();
      feedback(`${code} · yükleniyor`);
      try {
        const response = await fetch(`/main/api/metartaf.php?icao=${encodeURIComponent(code)}`, {cache:"no-store",signal:controller.signal,headers:{Accept:"application/json"}});
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) throw new Error(data?.error || `HTTP ${response.status}`);
        render(data);
      } catch (error) {
        if (error?.name === "AbortError") return;
        feedback(error?.message || "Veri alınamadı.", "error");
      }
    }

    input.addEventListener("input", () => { input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 4); });
    form.addEventListener("submit", event => { event.preventDefault(); request(input.value); });
    document.querySelectorAll("[data-weather-icao]").forEach(button => button.addEventListener("click", () => request(button.dataset.weatherIcao)));
    document.querySelectorAll("[data-copy-weather]").forEach(button => button.addEventListener("click", async () => {
      const target = $(button.dataset.copyWeather === "taf" ? "weather-taf" : "weather-metar");
      const text = target?.textContent?.trim();
      if (!text || text.includes("bulunamadı")) return;
      try { await navigator.clipboard.writeText(text); const old = button.textContent; button.textContent = "Kopyalandı"; setTimeout(() => button.textContent = old, 900); } catch (_) {}
    }));
  }

  function initNotam() {
    const results = $("results");
    if (!results) return;
    const fields = {
      q:$("f-q"), icao:$("f-icao"), fir:$("f-fir"), state:$("f-state"), at:$("f-at"), type:$("f-type"),
      classification:$("f-classification"), scope:$("f-scope"), traffic:$("f-traffic"), purpose:$("f-purpose"), selection:$("f-selection"),
      sort:$("f-sort"), limit:$("f-limit"), apply:$("apply"), reset:$("reset"), count:$("result-count"), label:$("result-label"),
      status:$("status"), prev:$("prev"), next:$("next"), pageInfo:$("page-info"), source:$("source-state")
    };
    let currentPage = 1;
    let controller = null;
    const p2 = n => String(n).padStart(2, "0");
    const utcInputNow = () => { const d = new Date(); return `${d.getUTCFullYear()}-${p2(d.getUTCMonth()+1)}-${p2(d.getUTCDate())}T${p2(d.getUTCHours())}:${p2(d.getUTCMinutes())}`; };
    const selectedAtIso = () => fields.at?.value ? new Date(fields.at.value + ":00Z").toISOString() : new Date().toISOString();
    const fmt = value => { if (!value) return "—"; const d = new Date(String(value).includes("T") ? value : String(value).replace(" ", "T") + "Z"); return Number.isNaN(d.getTime()) ? String(value) : new Intl.DateTimeFormat("tr-TR", {timeZone:"UTC",day:"2-digit",month:"2-digit",year:"numeric",hour:"2-digit",minute:"2-digit",hour12:false}).format(d) + " UTC"; };
    const stateLabel = s => ({valid:"GEÇERLİ",future:"GELECEK",expired:"GEÇMİŞ",cancelled:"İPTAL",replaced:"DEĞİŞTİRİLDİ"})[s] || String(s || "").toUpperCase();

    function addOptions(select, values) {
      if (!select) return;
      const current = select.value;
      (values || []).forEach(value => { if (![...select.options].some(o => o.value === value)) { const o = document.createElement("option"); o.value = value; o.textContent = value; select.appendChild(o); } });
      select.value = current;
    }

    async function loadFilters() {
      try {
        const response = await fetch("/main/api/notam.php?action=filters", {cache:"default"});
        const data = await response.json();
        if (!response.ok || !data?.ok) throw new Error();
        addOptions(fields.type, data.options?.type); addOptions(fields.classification, data.options?.classification); addOptions(fields.scope, data.options?.scope); addOptions(fields.traffic, data.options?.traffic);
        if (fields.source) fields.source.textContent = data.source || "FAA NMS";
      } catch (_) { if (fields.source) fields.source.textContent = "FAA NMS"; }
    }

    function params(targetPage = currentPage) {
      const q = new URLSearchParams({action:"list",state:fields.state?.value || "valid",at:selectedAtIso(),page:String(targetPage),limit:fields.limit?.value || "50",sort:fields.sort?.value || "updated_desc",include_text:"1"});
      const optional = {q:fields.q?.value.trim(),icao:fields.icao?.value.trim().toUpperCase(),fir:fields.fir?.value.trim().toUpperCase(),type:fields.type?.value,classification:fields.classification?.value,scope:fields.scope?.value,traffic:fields.traffic?.value,purpose:fields.purpose?.value.trim().toUpperCase(),selection_code:fields.selection?.value.trim().toUpperCase()};
      Object.entries(optional).forEach(([key,value]) => { if (value) q.set(key,value); });
      return q;
    }

    function renderItems(payload) {
      results.innerHTML = "";
      if (!payload.items?.length) { results.innerHTML = '<div class="empty-box">Bu filtrelerle gösterilecek NOTAM bulunamadı.</div>'; return; }
      const fragment = document.createDocumentFragment();
      payload.items.forEach(item => {
        const card = document.createElement("article");
        card.className = "notam-card";
        const state = item.temporalState || item.status || "valid";
        const stateClass = state === "replaced" ? "cancelled" : state;
        const limitText = [item.lowerLimit,item.upperLimit].filter(Boolean).join(" → ") || (item.minimumFl != null || item.maximumFl != null ? `FL ${item.minimumFl ?? "?"} → ${item.maximumFl ?? "?"}` : "Dikey limit belirtilmemiş");
        const isPerm = String(item.effectiveEndRaw || "").trim().toUpperCase() === "PERM";
        const endText = isPerm ? "PERM" : `${fmt(item.effectiveEnd)}${item.estimatedEnd ? " EST" : ""}`;
        const scheduleBadge = item.schedule ? (item.activityState === "active" ? '<span class="badge valid">PROGRAM AKTİF</span>' : item.activityState === "inactive" ? '<span class="badge future">PROGRAM DIŞI</span>' : '<span class="badge future">PROGRAMI KONTROL ET</span>') : "";
        const scheduleMeta = item.schedule ? `<span>Schedule / Item D<b>${esc(item.schedule)}</b></span>` : "";
        const replacementMeta = item.replacedBy?.replacementIdent ? `<span>Yerine geçen<b>${esc(item.replacedBy.replacementIdent)}</b></span>` : "";
        const scheduleNotice = item.schedule && item.activityState === "unknown" ? '<div class="source-line">Schedule otomatik olarak güvenle yorumlanamadı. Item D ham biçimde gösteriliyor; NOTAM gizlenmedi.</div>' : "";
        card.innerHTML = `<div class="notam-card-head"><div><h2>${esc(item.ident || item.id || "NOTAM")}</h2><div class="notam-location">${esc([item.icaoLocation || item.location,item.fir].filter(Boolean).join(" · "))}</div></div></div><div class="badges"><span class="badge ${esc(stateClass)}">${esc(stateLabel(state))}</span>${scheduleBadge}${item.type ? `<span class="badge">${esc(item.type)}</span>` : ""}${item.classification ? `<span class="badge">${esc(item.classification)}</span>` : ""}${item.scope ? `<span class="badge">SCOPE ${esc(item.scope)}</span>` : ""}</div><div class="notam-meta"><span>Başlangıç<b>${esc(fmt(item.effectiveStart))}</b></span><span>Bitiş<b>${esc(endText)}</b></span>${scheduleMeta}${replacementMeta}<span>Q / Selection<b>${esc(item.selectionCode || "—")}</b></span><span>Traffic<b>${esc(item.traffic || "—")}</b></span><span>Purpose<b>${esc(item.purpose || "—")}</b></span><span>Limits<b>${esc(limitText)}</b></span></div>${scheduleNotice}<div class="notam-text">${esc(item.text || "NOTAM metni bulunamadı.")}</div><div class="notam-foot"><span>${esc(fmt(item.lastUpdated))}</span><button type="button">Kopyala</button></div>`;
        card.querySelector("button")?.addEventListener("click", async event => { try { await navigator.clipboard.writeText(item.text || item.ident || ""); const old = event.currentTarget.textContent; event.currentTarget.textContent = "Kopyalandı"; setTimeout(() => event.currentTarget.textContent = old, 900); } catch (_) {} });
        fragment.appendChild(card);
      });
      results.appendChild(fragment);
    }

    function renderPaging(payload) {
      const p = payload.paging || {};
      if (fields.count) fields.count.textContent = Number(p.total || 0).toLocaleString("tr-TR");
      if (fields.label) fields.label.textContent = `NOTAM · ${p.returned || 0} gösteriliyor`;
      if (fields.pageInfo) fields.pageInfo.textContent = p.pages ? `Sayfa ${p.page} / ${p.pages}` : "Sayfa 0 / 0";
      if (fields.prev) fields.prev.disabled = !p.hasPrevious;
      if (fields.next) fields.next.disabled = !p.hasNext;
    }

    async function load(targetPage = currentPage) {
      controller?.abort(); controller = new AbortController(); currentPage = Math.max(1,targetPage);
      const q = params(currentPage);
      if (fields.status) fields.status.textContent = "FAA NMS verisi yükleniyor…";
      if (fields.apply) fields.apply.disabled = true;
      try {
        const response = await fetch(`/main/api/notam.php?${q}`, {cache:"no-store",signal:controller.signal});
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) throw new Error(payload?.error || `HTTP ${response.status}`);
        renderItems(payload); renderPaging(payload);
        if (fields.status) fields.status.textContent = `${String(payload.state || "").toUpperCase()} · ${fmt(payload.atUtc)}`;
      } catch (error) {
        if (error?.name === "AbortError") return;
        results.innerHTML = `<div class="error-box">${esc(error?.message || "NOTAM listesi yüklenemedi.")}</div>`;
        if (fields.status) fields.status.textContent = "API hatası";
      } finally { if (fields.apply) fields.apply.disabled = false; }
    }

    function normalizeCode(el) { if (el) el.value = el.value.toUpperCase().replace(/[^A-Z0-9]/g, ""); }
    [fields.icao,fields.fir,fields.purpose,fields.selection].forEach(el => el?.addEventListener("input", () => normalizeCode(el)));
    fields.apply?.addEventListener("click", () => load(1));
    fields.reset?.addEventListener("click", () => { Object.values(fields).forEach(() => {}); if(fields.q)fields.q.value="";if(fields.icao)fields.icao.value="";if(fields.fir)fields.fir.value="";if(fields.state)fields.state.value="valid";if(fields.at)fields.at.value=utcInputNow();if(fields.type)fields.type.value="";if(fields.classification)fields.classification.value="";if(fields.scope)fields.scope.value="";if(fields.traffic)fields.traffic.value="";if(fields.purpose)fields.purpose.value="";if(fields.selection)fields.selection.value="";if(fields.sort)fields.sort.value="updated_desc";if(fields.limit)fields.limit.value="50";load(1); });
    fields.prev?.addEventListener("click", () => load(currentPage - 1));
    fields.next?.addEventListener("click", () => load(currentPage + 1));
    [fields.q,fields.icao].forEach(el => el?.addEventListener("keydown", e => { if (e.key === "Enter") load(1); }));
    if (fields.at) fields.at.value = utcInputNow();
    loadFilters().finally(() => load(1));
  }

  if (page === "home") { setActiveNav("home"); initWeather(); }
  if (page === "notam") { setActiveNav("notam"); initNotam(); }
  if (page === "map") setActiveNav("map");
})();
