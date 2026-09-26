(() => {
  "use strict";

  const esc = value => String(value ?? "").replace(/[&<>'"]/g, c => ({
    "&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"
  }[c]));

  function ensureSection() {
    let section = document.getElementById("notam-section");
    if (section) return section;
    const station = document.getElementById("station-section");
    if (!station || !station.parentNode) return null;
    section = document.createElement("section");
    section.id = "notam-section";
    section.className = "section";
    section.hidden = true;
    section.innerHTML = `
      <div class="section-head">
        <div><span class="eyebrow">ROUTE / TIME / LEVEL RELEVANCE</span><h2>NOTAM</h2></div>
        <p>FAA NMS local verisi; uçuş penceresi, rota koridoru, meydanlar, airway/procedure referansları ve cruise seviyesiyle otomatik süzülür.</p>
      </div>
      <div id="notam-list" class="hazard-list"></div>`;
    station.parentNode.insertBefore(section, station.nextSibling);
    return section;
  }

  function basisText(value) {
    return ({
      endpoint:"DEP / ARR",
      route_reference:"ROUTE REF",
      route_intersection:"ROUTE HIT",
      near_route:"NEAR ROUTE"
    })[value] || String(value || "ROUTE").toUpperCase();
  }

  function verticalText(item) {
    if (item.verticalRelation === "at_cruise_level") return "CRUISE LEVEL OVERLAP";
    if (item.verticalRelation === "below_notam") return "BELOW NOTAM BAND";
    if (item.verticalRelation === "above_notam") return "ABOVE NOTAM BAND";
    return "LEVEL UNKNOWN";
  }

  function renderNotam(data) {
    const section = ensureSection();
    const list = document.getElementById("notam-list");
    if (!section || !list) return;
    section.hidden = false;
    const impact = data?.notamImpact;

    if (!impact || impact.available !== true) {
      list.innerHTML = '<div class="empty">NOTAM relevance check alınamadı. NOTAM durumu bilinmiyor; resmi kaynak ayrıca kontrol edilmeli.</div>';
      return;
    }

    const count = Number(impact.relevantCount || 0);
    const candidate = Number(impact.candidateCount || 0);
    const corridor = Number(impact.routeCorridorNm || 50);
    const items = Array.isArray(impact.items) ? impact.items : [];
    const caveat = impact.scheduleEvaluated === false
      ? "Serbest metin schedule ifadeleri otomatik olarak kesin uygulanmış sayılmaz."
      : "Schedule zamanı otomatik değerlendirildi.";

    if (!count) {
      list.innerHTML = `
        <article class="hazard-card">
          <div class="head"><strong>ROUTE NOTAM CHECK</strong><span class="tag info">NO RELEVANT RECORD</span></div>
          <div class="hazard-meta">
            <span>${candidate} aday kayıt tarandı</span>
            <span>${corridor} NM rota koridoru</span>
            <span>FL${esc(impact.cruiseFL ?? "—")}</span>
            <span>${esc(impact.source || "FAA NMS")}</span>
          </div>
          <pre>Otomatik kontrolde rota / meydan / uçuş penceresi / seviye açısından ilgili NOTAM tespit edilmedi. Bu sonuç resmi briefing veya dispatch doğrulaması değildir. ${esc(caveat)}</pre>
        </article>`;
      return;
    }

    const summary = `
      <article class="hazard-card">
        <div class="head"><strong>ROUTE NOTAM CHECK</strong><span class="tag warn">${count} RELEVANT</span></div>
        <div class="hazard-meta">
          <span>${Number(impact.endpointCount || 0)} DEP/ARR</span>
          <span>${Number(impact.routeIntersectionCount || 0)} ROUTE HIT</span>
          <span>${Number(impact.nearRouteCount || 0)} NEAR ROUTE</span>
          <span>${Number(impact.referenceMatchCount || 0)} ROUTE REF</span>
          <span>${Number(impact.atCruiseLevelCount || 0)} FL OVERLAP</span>
          <span>${corridor} NM CORRIDOR</span>
        </div>
        <pre>${esc(caveat)}</pre>
      </article>`;

    const cards = items.slice(0, 12).map(item => {
      const cls = item.basis === "route_intersection" || item.basis === "endpoint" ? "bad" : "warn";
      const distance = item.distanceNm == null ? "" : ` · ${Number(item.distanceNm).toFixed(1)} NM`;
      const refs = Array.isArray(item.matchedRouteRefs) && item.matchedRouteRefs.length
        ? ` · ${item.matchedRouteRefs.join(", ")}` : "";
      const window = [item.effectiveStart, item.effectiveEndRaw || item.effectiveEnd].filter(Boolean).join(" → ");
      return `
        <article class="hazard-card">
          <div class="head"><strong>${esc(item.ident || "NOTAM")}</strong><span class="tag ${cls}">${esc(basisText(item.basis))}</span></div>
          <div class="hazard-meta">
            <span>${esc(item.location || item.fir || "ROUTE")}</span>
            <span>${esc(item.semantic || "OTHER")}</span>
            <span>${esc(verticalText(item))}</span>
            ${window ? `<span>${esc(window)}</span>` : ""}
            ${distance ? `<span>${esc(distance.slice(3))}</span>` : ""}
            ${refs ? `<span>REF ${esc(refs.slice(3))}</span>` : ""}
          </div>
          <pre>${esc(item.text || "NOTAM text unavailable")}</pre>
        </article>`;
    }).join("");

    list.innerHTML = summary + cards;
  }

  function patchAutoRoute(autoMode, segments, airways) {
    if (!autoMode) return;
    const type = document.getElementById("route-type");
    const parsed = document.getElementById("resolved-route");
    const rows = [...document.querySelectorAll("#simple-brief .brief-line")];
    const routeRow = rows.find(row => row.querySelector("strong")?.textContent?.trim() === "ROTA");

    if (autoMode === "navdata") {
      if (type) type.textContent = "AUTO NAVDATA";
      if (parsed && !parsed.textContent.startsWith("AUTO ESTIMATED NAVDATA")) {
        parsed.textContent = `AUTO ESTIMATED NAVDATA\n${parsed.textContent}`;
      }
      if (routeRow) {
        const status = routeRow.querySelector(".top span");
        const text = routeRow.querySelector("p");
        if (status) { status.textContent = "ESTIMATED NAVDATA"; status.className = "warn"; }
        if (text) text.textContent = `OFP girilmedi. Great-circle yalnızca arama rehberi olarak kullanıldı; MariaDB airway graph üzerinden ${airways || "—"} airway / ${segments || "—"} segmentlik tahmini rota üretildi. IFPS/Eurocontrol onayı değildir.`;
      }
    } else if (autoMode === "great-circle") {
      if (type) type.textContent = "GREAT CIRCLE FALLBACK";
      if (routeRow) {
        const status = routeRow.querySelector(".top span");
        const text = routeRow.querySelector("p");
        if (status) { status.textContent = "ESTIMATED"; status.className = "warn"; }
        if (text) text.textContent = "OFP girilmedi ve uygun bağlı airway graph rotası üretilemedi; great-circle fallback kullanılıyor.";
      }
    }
  }

  function updateFooter(data) {
    const el = document.getElementById("data-status");
    if (!el || !data?.notamImpact) return;
    const impact = data.notamImpact;
    const suffix = impact.available === true
      ? ` · NOTAM ${Number(impact.relevantCount || 0)} ilgili`
      : " · NOTAM VERİ YOK";
    if (!el.textContent.includes("· NOTAM")) el.textContent += suffix;
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
        clone.json().then(data => {
          const apply = () => {
            renderNotam(data);
            patchAutoRoute(autoMode, segments, airways);
            updateFooter(data);
          };
          setTimeout(apply, 30);
          setTimeout(apply, 180);
        }).catch(() => {});
      }
    } catch (_) {}
    return response;
  };

  ensureSection();
})();
