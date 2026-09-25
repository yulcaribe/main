(() => {
  "use strict";

  const API = "/main/api/v1/notam.php";
  const $ = id => document.getElementById(id);
  const els = {
    q: $("f-q"),
    icao: $("f-icao"),
    fir: $("f-fir"),
    state: $("f-state"),
    at: $("f-at"),
    type: $("f-type"),
    classification: $("f-classification"),
    scope: $("f-scope"),
    traffic: $("f-traffic"),
    purpose: $("f-purpose"),
    selection: $("f-selection"),
    sort: $("f-sort"),
    limit: $("f-limit"),
    apply: $("apply"),
    reset: $("reset"),
    results: $("results"),
    count: $("result-count"),
    label: $("result-label"),
    status: $("status"),
    prev: $("prev"),
    next: $("next"),
    pageInfo: $("page-info"),
    source: $("source-state"),
    template: $("notam-template")
  };

  let page = 1;
  let controller = null;
  let lastPayload = null;

  function utcInputNow() {
    const d = new Date();
    const p = n => String(n).padStart(2, "0");
    return `${d.getUTCFullYear()}-${p(d.getUTCMonth()+1)}-${p(d.getUTCDate())}T${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
  }

  function normalizeCode(el) {
    el.value = el.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
  }

  function escapeText(value) {
    return String(value ?? "");
  }

  function fmtDate(value) {
    if (!value) return "—";
    const d = new Date(String(value).includes("T") ? value : String(value).replace(" ", "T") + "Z");
    if (Number.isNaN(d.getTime())) return value;
    return new Intl.DateTimeFormat("tr-TR", {
      timeZone: "UTC",
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false
    }).format(d) + " UTC";
  }

  function selectedAtIso() {
    if (!els.at.value) return new Date().toISOString();
    return new Date(els.at.value + ":00Z").toISOString();
  }

  function classifyState(item, atIso) {
    if (item.status === "cancelled") return "cancelled";
    const at = new Date(atIso).getTime();
    const start = item.effectiveStart ? new Date(String(item.effectiveStart).replace(" ", "T") + "Z").getTime() : null;
    const end = item.effectiveEnd ? new Date(String(item.effectiveEnd).replace(" ", "T") + "Z").getTime() : null;
    if (start !== null && start > at) return "future";
    if (end !== null && end < at && String(item.effectiveEndRaw || "").toUpperCase() !== "PERM") return "expired";
    return "valid";
  }

  function stateLabel(state) {
    return ({
      valid: "GEÇERLİ",
      future: "GELECEK",
      expired: "GEÇMİŞ",
      cancelled: "CANCELLED"
    })[state] || state.toUpperCase();
  }

  function addSelectOptions(select, values) {
    const current = select.value;
    for (const value of values || []) {
      if ([...select.options].some(o => o.value === value)) continue;
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      select.appendChild(option);
    }
    select.value = current;
  }

  async function loadFilterOptions() {
    try {
      const res = await fetch(API + "?action=filters", { cache: "default" });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || "Filtreler alınamadı");
      addSelectOptions(els.type, data.options?.type);
      addSelectOptions(els.classification, data.options?.classification);
      addSelectOptions(els.scope, data.options?.scope);
      addSelectOptions(els.traffic, data.options?.traffic);
      els.source.textContent = `${data.source || "FAA NMS"} · production`;
    } catch (error) {
      els.source.textContent = "FAA NMS";
    }
  }

  function paramsFromUi(targetPage = page) {
    const q = new URLSearchParams({
      action: "list",
      state: els.state.value,
      at: selectedAtIso(),
      page: String(targetPage),
      limit: els.limit.value,
      sort: els.sort.value,
      include_text: "1"
    });

    const optional = {
      q: els.q.value.trim(),
      icao: els.icao.value.trim().toUpperCase(),
      fir: els.fir.value.trim().toUpperCase(),
      type: els.type.value,
      classification: els.classification.value,
      scope: els.scope.value,
      traffic: els.traffic.value,
      purpose: els.purpose.value.trim().toUpperCase(),
      selection_code: els.selection.value.trim().toUpperCase()
    };

    for (const [key, value] of Object.entries(optional)) {
      if (value) q.set(key, value);
    }
    return q;
  }

  function persistUrl(q) {
    const clean = new URLSearchParams(q);
    clean.delete("action");
    clean.delete("include_text");
    history.replaceState(null, "", "?" + clean.toString());
  }

  function loadFromUrl() {
    const q = new URLSearchParams(location.search);
    if (q.has("q")) els.q.value = q.get("q");
    if (q.has("icao")) els.icao.value = q.get("icao");
    if (q.has("fir")) els.fir.value = q.get("fir");
    if (q.has("state")) els.state.value = q.get("state");
    if (q.has("type")) els.type.value = q.get("type");
    if (q.has("classification")) els.classification.value = q.get("classification");
    if (q.has("scope")) els.scope.value = q.get("scope");
    if (q.has("traffic")) els.traffic.value = q.get("traffic");
    if (q.has("purpose")) els.purpose.value = q.get("purpose");
    if (q.has("selection_code")) els.selection.value = q.get("selection_code");
    if (q.has("sort")) els.sort.value = q.get("sort");
    if (q.has("limit")) els.limit.value = q.get("limit");
    if (q.has("page")) page = Math.max(1, Number(q.get("page")) || 1);

    const at = q.get("at");
    if (at) {
      const d = new Date(at);
      if (!Number.isNaN(d.getTime())) {
        const p = n => String(n).padStart(2, "0");
        els.at.value = `${d.getUTCFullYear()}-${p(d.getUTCMonth()+1)}-${p(d.getUTCDate())}T${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
      }
    }
  }

  function metaCell(label, value) {
    const span = document.createElement("span");
    const text = document.createTextNode(label);
    const b = document.createElement("b");
    b.textContent = value || "—";
    span.append(text, b);
    return span;
  }

  function badge(text, className = "") {
    const el = document.createElement("span");
    el.className = "badge" + (className ? " " + className : "");
    el.textContent = text;
    return el;
  }

  function renderItems(payload) {
    els.results.innerHTML = "";
    const atIso = payload.atUtc || selectedAtIso();

    if (!payload.items?.length) {
      const empty = document.createElement("div");
      empty.className = "empty";
      empty.textContent = "Bu filtrelerle gösterilecek NOTAM bulunamadı.";
      els.results.appendChild(empty);
      return;
    }

    const fragment = document.createDocumentFragment();
    for (const item of payload.items) {
      const node = els.template.content.firstElementChild.cloneNode(true);
      node.querySelector("[data-ident]").textContent = item.ident || item.id || "NOTAM";
      node.querySelector("[data-location]").textContent =
        [item.icaoLocation || item.location, item.fir].filter(Boolean).join(" · ");

      const state = item.temporalState || classifyState(item, atIso);
      const badges = node.querySelector("[data-badges]");
      badges.appendChild(badge(stateLabel(state), state));
      if (item.type) badges.appendChild(badge(item.type));
      if (item.classification) badges.appendChild(badge(item.classification));
      if (item.scope) badges.appendChild(badge("SCOPE " + item.scope));

      const meta = node.querySelector("[data-meta]");
      meta.append(
        metaCell("Başlangıç", fmtDate(item.effectiveStart)),
        metaCell("Bitiş", String(item.effectiveEndRaw || "").toUpperCase() === "PERM" ? "PERM" : fmtDate(item.effectiveEnd)),
        metaCell("Q / Selection", item.selectionCode),
        metaCell("Traffic", item.traffic),
        metaCell("Purpose", item.purpose),
        metaCell("Son güncelleme", fmtDate(item.lastUpdated))
      );

      const text = escapeText(item.text || "");
      node.querySelector("[data-text]").textContent = text || "NOTAM metni bulunamadı.";
      node.querySelector("[data-limits]").textContent =
        [item.lowerLimit, item.upperLimit].filter(Boolean).join(" → ") ||
        (item.minimumFl != null || item.maximumFl != null
          ? `FL ${item.minimumFl ?? "?"} → ${item.maximumFl ?? "?"}`
          : "Dikey limit belirtilmemiş");

      node.querySelector("[data-copy]").addEventListener("click", async e => {
        try {
          await navigator.clipboard.writeText(text || item.ident || "");
          e.currentTarget.textContent = "Kopyalandı";
          setTimeout(() => { e.currentTarget.textContent = "Kopyala"; }, 1200);
        } catch {
          e.currentTarget.textContent = "Kopyalanamadı";
        }
      });

      fragment.appendChild(node);
    }
    els.results.appendChild(fragment);
  }

  function paintPaging(payload) {
    const p = payload.paging || {};
    els.count.textContent = Number(p.total || 0).toLocaleString("tr-TR");
    els.label.textContent = `NOTAM · ${p.returned || 0} gösteriliyor`;
    els.pageInfo.textContent = p.pages ? `Sayfa ${p.page} / ${p.pages}` : "Sayfa 0 / 0";
    els.prev.disabled = !p.hasPrevious;
    els.next.disabled = !p.hasNext;
  }

  async function load(targetPage = page) {
    controller?.abort();
    controller = new AbortController();
    page = Math.max(1, targetPage);
    const q = paramsFromUi(page);
    persistUrl(q);

    els.status.textContent = "FAA NMS verisi yükleniyor…";
    els.apply.disabled = true;

    try {
      const started = performance.now();
      const res = await fetch(API + "?" + q.toString(), {
        cache: "no-store",
        signal: controller.signal
      });
      const payload = await res.json().catch(() => null);
      if (!res.ok || !payload?.ok) throw new Error(payload?.error || `HTTP ${res.status}`);

      lastPayload = payload;
      renderItems(payload);
      paintPaging(payload);
      const ms = Math.round(performance.now() - started);
      els.status.textContent = `${payload.state.toUpperCase()} · ${fmtDate(payload.atUtc)} · ${ms} ms`;
    } catch (error) {
      if (error.name === "AbortError") return;
      els.results.innerHTML = "";
      const box = document.createElement("div");
      box.className = "error";
      box.textContent = error.message || "NOTAM listesi yüklenemedi.";
      els.results.appendChild(box);
      els.status.textContent = "API hatası";
    } finally {
      els.apply.disabled = false;
    }
  }

  function reset() {
    els.q.value = "";
    els.icao.value = "";
    els.fir.value = "";
    els.state.value = "valid";
    els.at.value = utcInputNow();
    els.type.value = "";
    els.classification.value = "";
    els.scope.value = "";
    els.traffic.value = "";
    els.purpose.value = "";
    els.selection.value = "";
    els.sort.value = "updated_desc";
    els.limit.value = "50";
    page = 1;
    load(1);
  }

  els.icao.addEventListener("input", () => normalizeCode(els.icao));
  els.fir.addEventListener("input", () => normalizeCode(els.fir));
  els.purpose.addEventListener("input", () => normalizeCode(els.purpose));
  els.selection.addEventListener("input", () => normalizeCode(els.selection));
  els.apply.addEventListener("click", () => load(1));
  els.reset.addEventListener("click", reset);
  els.prev.addEventListener("click", () => load(page - 1));
  els.next.addEventListener("click", () => load(page + 1));
  els.q.addEventListener("keydown", e => { if (e.key === "Enter") load(1); });
  els.icao.addEventListener("keydown", e => { if (e.key === "Enter") load(1); });

  els.at.value = utcInputNow();
  loadFilterOptions().finally(() => {
    loadFromUrl();
    load(page);
  });
})();