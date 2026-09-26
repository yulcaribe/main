(() => {
  "use strict";

  const esc = value => String(value ?? "").replace(/[&<>'"]/g, c => ({
    "&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"
  }[c]));

  function ensureSection() {
    let section = document.getElementById("notam-section");
    if (section) return section;
    const station = document.getElementById("station-section");
    if (!station?.parentNode) return null;
    section = document.createElement("section");
    section.id = "notam-section";
    section.className = "section";
    section.hidden = true;
    section.innerHTML = `
      <div class="section-head">
        <div><span class="eyebrow">ROUTE / TIME / LEVEL RELEVANCE</span><h2>NOTAM</h2></div>
        <p>FAA NMS local verisi. Meydan, rota, airway/procedure, uçuş penceresi, ortak schedule kalıpları ve cruise seviyesi birlikte değerlendirilir.</p>
      </div>
      <div id="notam-list" class="hazard-list"></div>`;
    station.parentNode.insertBefore(section, station.nextSibling);
    return section;
  }

  const basisText = value => ({
    endpoint:"DEP / ARR",
    route_reference:"ROUTE REF",
    route_intersection:"ROUTE HIT",
    near_route:"NEAR ROUTE"
  })[value] || String(value || "ROUTE").toUpperCase();

  function verticalText(item) {
    if (item.verticalRelation === "at_cruise_level") return "CRUISE LEVEL OVERLAP";
    if (item.verticalRelation === "below_notam") return "BELOW NOTAM BAND";
    if (item.verticalRelation === "above_notam") return "ABOVE NOTAM BAND";
    return "LEVEL UNKNOWN";
  }

  function scheduleText(item) {
    const rel = item.scheduleRelation;
    if (rel === "active") return "SCHEDULE ACTIVE";
    if (rel === "inactive") return "SCHEDULE OUTSIDE WINDOW";
    if (rel === "unknown") return "SCHEDULE MANUAL CHECK";
    return "NO DAILY SCHEDULE";
  }

  function priorityClass(priority) {
    return priority === "high" ? "bad" : priority === "medium" ? "warn" : "info";
  }

  function patchNotamSummary(data) {
    const host = document.getElementById("simple-brief");
    if (!host) return;
    document.getElementById("notam-brief-line")?.remove();
    const impact = data?.notamImpact;
    const row = document.createElement("div");
    row.id = "notam-brief-line";
    row.className = "brief-line";

    let status = "VERİ YOK", cls = "warn", text = "NOTAM relevance check alınamadı; resmi kaynak ayrıca kontrol edilmeli.";
    if (impact?.available === true) {
      const matched = Number(impact.matchedCount ?? impact.relevantCount ?? 0);
      const high = Number(impact.highPriorityCount || 0);
      const medium = Number(impact.mediumPriorityCount || 0);
      const excluded = Number(impact.scheduleExcludedCount || 0);
      const unknown = Number(impact.scheduleUnknownCount || 0);
      if (!matched) {
        status = "NO MATCH"; cls = "info";
        text = `Otomatik kontrolde uçuş penceresi için aktif rota/meydan NOTAM eşleşmesi bulunmadı. ${excluded} schedule-dışı kayıt elendi.${unknown ? ` ${unknown} schedule ifadesi manuel kontrol gerektiriyor.` : ""} Bu, resmi briefing yerine geçmez.`;
      } else {
        status = high ? `${high} HIGH · ${matched} MATCH` : `${matched} MATCH`;
        cls = high ? "bad" : medium ? "warn" : "info";
        text = `${matched} aktif/potansiyel eşleşme: ${high} yüksek, ${medium} orta öncelik. ${Number(impact.endpointCount || 0)} DEP/ARR, ${Number(impact.routeIntersectionCount || 0)} route hit, ${Number(impact.referenceMatchCount || 0)} route ref. ${excluded} schedule-dışı kayıt elendi.${unknown ? ` ${unknown} schedule ifadesi kesin çözülemedi.` : ""}`;
      }
    }
    row.innerHTML = `<div class="top"><strong>NOTAM</strong><span class="${cls}">${esc(status)}</span></div><p>${esc(text)}</p>`;
    const rows = [...host.querySelectorAll(".brief-line")];
    const arrival = rows.find(r => r.querySelector("strong")?.textContent?.trim() === "VARIŞ");
    if (arrival) arrival.after(row); else host.appendChild(row);
  }

  function renderNotam(data) {
    const section = ensureSection();
    const list = document.getElementById("notam-list");
    if (!section || !list) return;
    section.hidden = false;
    const impact = data?.notamImpact;

    if (!impact || impact.available !== true) {
      list.innerHTML = '<div class="empty">NOTAM relevance check alınamadı. Durum bilinmiyor; resmi kaynak ayrıca kontrol edilmeli.</div>';
      return;
    }

    const count = Number(impact.matchedCount ?? impact.relevantCount ?? 0);
    const candidate = Number(impact.candidateCount || 0);
    const corridor = Number(impact.routeCorridorNm || 50);
    const high = Number(impact.highPriorityCount || 0);
    const medium = Number(impact.mediumPriorityCount || 0);
    const info = Number(impact.infoCount || 0);
    const excluded = Number(impact.scheduleExcludedCount || 0);
    const unknown = Number(impact.scheduleUnknownCount || 0);
    const items = Array.isArray(impact.items) ? impact.items : [];

    if (!count) {
      list.innerHTML = `
        <article class="hazard-card">
          <div class="head"><strong>ROUTE NOTAM CHECK</strong><span class="tag info">NO ACTIVE MATCH</span></div>
          <div class="hazard-meta">
            <span>${candidate} aday kayıt tarandı</span>
            <span>${excluded} schedule-dışı elendi</span>
            <span>${corridor} NM rota koridoru</span>
            <span>FL${esc(impact.cruiseFL ?? "—")}</span>
            <span>${esc(impact.source || "FAA NMS")}</span>
          </div>
          <pre>Otomatik kontrolde rota / meydan / uçuş penceresi / seviye açısından aktif eşleşme tespit edilmedi.${unknown ? ` ${unknown} schedule ifadesi manuel kontrol gerektiriyor.` : ""} Bu sonuç resmi briefing veya dispatch doğrulaması değildir.</pre>
        </article>`;
      return;
    }

    const summaryClass = high ? "bad" : medium ? "warn" : "info";
    const summary = `
      <article class="hazard-card">
        <div class="head"><strong>ROUTE NOTAM CHECK</strong><span class="tag ${summaryClass}">${count} MATCHED</span></div>
        <div class="hazard-meta">
          <span>${high} HIGH</span><span>${medium} MEDIUM</span><span>${info} INFO</span>
          <span>${Number(impact.endpointCount || 0)} DEP/ARR</span>
          <span>${Number(impact.routeIntersectionCount || 0)} ROUTE HIT</span>
          <span>${Number(impact.nearRouteCount || 0)} NEAR ROUTE</span>
          <span>${Number(impact.referenceMatchCount || 0)} ROUTE REF</span>
          <span>${excluded} SCHEDULE-OUT</span>
          <span>${unknown} SCHEDULE MANUAL</span>
        </div>
        <pre>“MATCHED” otomatik briefing filtresine eşleşen kaydı ifade eder; resmi operational acceptance değildir. Ortak DAILY / weekday saat kalıpları uygulanır, çözülemeyen schedule metinleri ayrıca işaretlenir.</pre>
      </article>`;

    const cards = items.slice(0, 30).map(item => {
      const cls = priorityClass(item.priority);
      const distance = item.distanceNm == null ? "" : `${Number(item.distanceNm).toFixed(1)} NM`;
      const refs = Array.isArray(item.matchedRouteRefs) && item.matchedRouteRefs.length ? item.matchedRouteRefs.join(", ") : "";
      const window = [item.effectiveStart, item.effectiveEndRaw || item.effectiveEnd].filter(Boolean).join(" → ");
      return `
        <article class="hazard-card">
          <div class="head"><strong>${esc(item.ident || "NOTAM")}</strong><span class="tag ${cls}">${esc(String(item.priority || "info").toUpperCase())} · ${esc(basisText(item.basis))}</span></div>
          <div class="hazard-meta">
            <span>${esc(item.location || item.fir || "ROUTE")}</span>
            <span>${esc(item.semantic || "OTHER")}</span>
            <span>${esc(verticalText(item))}</span>
            <span>${esc(scheduleText(item))}</span>
            ${window ? `<span>${esc(window)}</span>` : ""}
            ${distance ? `<span>${esc(distance)}</span>` : ""}
            ${refs ? `<span>REF ${esc(refs)}</span>` : ""}
          </div>
          ${item.schedule ? `<small style="display:block;margin:8px 0;color:#8297a6">SCHEDULE: ${esc(item.schedule)}</small>` : ""}
          <pre>${esc(item.text || "NOTAM text unavailable")}</pre>
        </article>`;
    }).join("");
    const tail = items.length > 30 ? `<div class="empty">İlk 30 eşleşme gösteriliyor; toplam ${items.length} kayıt.</div>` : "";
    list.innerHTML = summary + cards + tail;
  }

  function patchAutoRoute(autoMode, segments, airways, bridges, bridgeNm) {
    if (!autoMode) return;
    const type = document.getElementById("route-type");
    const parsed = document.getElementById("resolved-route");
    const rows = [...document.querySelectorAll("#simple-brief .brief-line")];
    const routeRow = rows.find(row => row.querySelector("strong")?.textContent?.trim() === "ROTA");

    if (autoMode === "navdata" || autoMode === "navdata-hybrid") {
      const hybrid = autoMode === "navdata-hybrid";
      if (type) type.textContent = hybrid ? "AUTO NAVDATA + DCT" : "AUTO NAVDATA";
      if (parsed && !parsed.textContent.startsWith("AUTO ESTIMATED NAVDATA")) parsed.textContent = `AUTO ESTIMATED NAVDATA${hybrid ? " + SHORT DCT BRIDGES" : ""}\n${parsed.textContent}`;
      if (routeRow) {
        const status = routeRow.querySelector(".top span");
        const text = routeRow.querySelector("p");
        if (status) { status.textContent = hybrid ? "EST. NAVDATA + DCT" : "ESTIMATED NAVDATA"; status.className = "warn"; }
        if (text) text.textContent = hybrid
          ? `OFP girilmedi. Great-circle yalnızca arama rehberi oldu; MariaDB airway graph üzerinde ${airways || "—"} airway / ${segments || "—"} segment kullanıldı ve kopuk/FRA kısımları ${bridges || "—"} kısa DCT köprü (${bridgeNm || "—"} NM) ile bağlandı. IFPS/Eurocontrol onayı değildir.`
          : `OFP girilmedi. Great-circle yalnızca arama rehberi oldu; MariaDB airway graph üzerinden ${airways || "—"} airway / ${segments || "—"} segmentlik tahmini rota üretildi. IFPS/Eurocontrol onayı değildir.`;
      }
    } else if (autoMode === "great-circle") {
      if (type) type.textContent = "GREAT CIRCLE FALLBACK";
      if (routeRow) {
        const status = routeRow.querySelector(".top span");
        const text = routeRow.querySelector("p");
        if (status) { status.textContent = "ESTIMATED"; status.className = "warn"; }
        if (text) text.textContent = "OFP girilmedi ve yeterli airway/DCT bağlantılı navdata rotası üretilemedi; great-circle fallback kullanılıyor.";
      }
    }
  }

  function updateFooter(data) {
    const el = document.getElementById("data-status");
    if (!el || !data?.notamImpact) return;
    const impact = data.notamImpact;
    const suffix = impact.available === true
      ? ` · NOTAM ${Number(impact.matchedCount ?? impact.relevantCount ?? 0)} match / ${Number(impact.highPriorityCount || 0)} high`
      : " · NOTAM VERİ YOK";
    el.textContent = el.textContent.replace(/ · NOTAM .*$/, "") + suffix;
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const response = await nativeFetch(...args);
    try {
      const source = args[0] instanceof Request ? args[0].url : String(args[0] || "");
      const url = new URL(source, location.href);
      if (url.pathname.endsWith("/main/api/v1/briefing.php")) {
        const clone = response.clone();
        const autoMode = response.headers.get("X-YC-Auto-Route");
        const segments = response.headers.get("X-YC-Auto-Route-Segments");
        const airways = response.headers.get("X-YC-Auto-Route-Airways");
        const bridges = response.headers.get("X-YC-Auto-Route-Bridges");
        const bridgeNm = response.headers.get("X-YC-Auto-Route-Bridge-NM");
        clone.json().then(data => {
          const apply = () => {
            patchNotamSummary(data);
            renderNotam(data);
            patchAutoRoute(autoMode, segments, airways, bridges, bridgeNm);
            updateFooter(data);
          };
          setTimeout(apply, 40);
          setTimeout(apply, 220);
        }).catch(() => {});
      }
    } catch (_) {}
    return response;
  };

  ensureSection();
})();
