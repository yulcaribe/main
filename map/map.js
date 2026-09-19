(() => {
  "use strict";

  if (!window.L) {
    document.body.innerHTML = `
      <div style="min-height:100vh;display:grid;place-items:center;background:#07131f;color:#eef6fb;font-family:system-ui,sans-serif;padding:24px;text-align:center">
        <div>
          <h2 style="margin:0 0 10px">Harita motoru yüklenemedi</h2>
          <p style="margin:0;color:#94a9b9">Leaflet CDN bağlantısı engellenmiş olabilir.</p>
        </div>
      </div>`;
    return;
  }

  const REFRESH_MS = 10000;
  const ABSENT_GRACE_MS = 30000;
  const MAX_RADIUS_NM = 250;
  const MIN_RADIUS_NM = 10;

  const countEl = document.getElementById("aircraft-count");
  const sourceEl = document.getElementById("source-label");
  const updateEl = document.getElementById("update-label");
  const feedDot = document.getElementById("feed-dot");
  const searchInput = document.getElementById("flight-search");
  const optionsToggle = document.getElementById("options-toggle");
  const optionsPanel = document.getElementById("options-panel");
  const optionsClose = document.getElementById("options-close");
  const themeButtons = [...document.querySelectorAll("[data-theme-value]")];
  const sizeButtons = [...document.querySelectorAll("[data-size-value]")];
  const hint = document.getElementById("map-hint");

  const savedTheme = localStorage.getItem("aviation-map-theme");
  document.body.dataset.theme = (savedTheme === "day" || savedTheme === "night") ? savedTheme : "night";

  const savedAircraftSize = localStorage.getItem("aviation-aircraft-size");
  document.body.dataset.aircraftSize = ["small","medium","large"].includes(savedAircraftSize)
    ? savedAircraftSize
    : "medium";

  syncOptionsUi();

  const map = L.map("map", {
    zoomControl: false,
    minZoom: 3,
    worldCopyJump: true,
    preferCanvas: true
  }).setView([40.65, 29.05], 7);

  L.control.zoom({ position: "bottomleft" }).addTo(map);

  L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  const aircraft = new Map();
  const routeCache = new Map();
  const routeRequests = new Map();
  let refreshTimer = null;
  let moveRefreshTimer = null;
  let hintTimer = null;
  let requestInFlight = false;
  let pendingRefresh = false;
  let viewRevision = 0;
  const ADSB_POINT_API = "https://api.adsb.lol/v2/point";

  function esc(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function num(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }

  function cleanFlight(ac) {
    return String(ac.flight || "").trim() || "UNKNOWN";
  }

  function aircraftId(ac) {
    return String(ac.hex || ac.r || ac.flight || "").trim().toLowerCase();
  }

  function isEmergency(ac) {
    return ["7500", "7600", "7700"].includes(String(ac.squawk || ""));
  }

  function formatAltitude(value) {
    if (value === "ground") return "GROUND";
    const n = num(value);
    return n === null ? "—" : Math.round(n).toLocaleString("tr-TR") + " ft";
  }

  function formatSpeed(value) {
    const n = num(value);
    return n === null ? "—" : Math.round(n) + " kt";
  }

  function formatTrack(value) {
    const n = num(value);
    return n === null ? "—" : Math.round(n) + "°";
  }

  function destination(lat, lon, bearingDeg, distanceNm) {
    const R = 3440.065;
    const d = distanceNm / R;
    const brng = bearingDeg * Math.PI / 180;
    const p1 = lat * Math.PI / 180;
    const l1 = lon * Math.PI / 180;

    const p2 = Math.asin(
      Math.sin(p1) * Math.cos(d) +
      Math.cos(p1) * Math.sin(d) * Math.cos(brng)
    );

    const l2 = l1 + Math.atan2(
      Math.sin(brng) * Math.sin(d) * Math.cos(p1),
      Math.cos(d) - Math.sin(p1) * Math.sin(p2)
    );

    return [
      p2 * 180 / Math.PI,
      ((l2 * 180 / Math.PI + 540) % 360) - 180
    ];
  }

  function predictedPosition(ac) {
    const lat = num(ac.lat);
    const lon = num(ac.lon);
    const speed = num(ac.gs);
    const track = num(ac.track);

    if (lat === null || lon === null) return null;
    if (speed === null || track === null || speed < 1) return [lat, lon];

    const distanceNm = speed * (REFRESH_MS / 3600000);
    return destination(lat, lon, track, distanceNm);
  }

  function aircraftPixelSize() {
    return {
      small: 30,
      medium: 38,
      large: 48
    }[document.body.dataset.aircraftSize] || 38;
  }

  function isGroundAircraft(ac) {
    return String(ac.alt_baro || "").toLowerCase() === "ground";
  }

  function displayHeading(ac) {
    const track = num(ac.track);
    const trueHeading = num(ac.true_heading);
    const magneticHeading = num(ac.mag_heading);

    if (isGroundAircraft(ac)) {
      return trueHeading ?? magneticHeading ?? track ?? 0;
    }

    return track ?? trueHeading ?? magneticHeading ?? 0;
  }

  function iconFor(ac) {
    const flight = esc(cleanFlight(ac));
    const heading = displayHeading(ac);
    const emergencyClass = isEmergency(ac) ? " emergency" : "";

    return L.divIcon({
      className: "aircraft-div-icon",
      html: `<div class="aircraft-marker${emergencyClass}">
        <span class="plane" style="transform:rotate(${heading}deg)">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 1.25c-.75 0-1.3.6-1.38 1.38L10 8.1 2.6 12v2.15l7.1-1.75-.42 4.82-2.58 1.9v1.55L12 19.45l5.3 1.22v-1.55l-2.58-1.9-.42-4.82 7.1 1.75V12L14 8.1l-.62-5.47C13.3 1.85 12.75 1.25 12 1.25Z"/>
          </svg>
        </span>
        <span class="aircraft-label">${flight}</span>
      </div>`,
      iconSize: [aircraftPixelSize(), aircraftPixelSize()],
      iconAnchor: [aircraftPixelSize() / 2, aircraftPixelSize() / 2]
    });
  }

  function routeCallsign(ac) {
    const callsign = String(ac.flight || "").trim().toUpperCase();
    return callsign && callsign !== "UNKNOWN" ? callsign : "";
  }

  function routeHtml(ac) {
    const callsign = routeCallsign(ac);
    if (!callsign) {
      return '<div class="popup-route"><span>Rota</span><strong>—</strong></div>';
    }

    const cached = routeCache.get(callsign);
    if (!cached) {
      return '<div class="popup-route"><span>Rota</span><strong>Uçağa tıklayınca yüklenir</strong></div>';
    }

    if (cached.status === "loading") {
      return '<div class="popup-route"><span>Rota</span><strong>Yükleniyor…</strong></div>';
    }

    if (cached.status !== "ok") {
      return '<div class="popup-route"><span>Rota</span><strong>Rota bulunamadı</strong></div>';
    }

    const route = cached.data || {};
    const codes = String(route._airport_codes_iata || route.airport_codes || "")
      .replaceAll("-", " → ");

    const airports = Array.isArray(route._airports) ? route._airports : [];
    const first = airports[0] || null;
    const last = airports.length > 1 ? airports[airports.length - 1] : null;

    const firstName = first?.name || first?.icao || first?.iata || "";
    const lastName = last?.name || last?.icao || last?.iata || "";
    const names = firstName && lastName
      ? `<small>${esc(firstName)} → ${esc(lastName)}</small>`
      : "";

    return `<div class="popup-route">
      <span>Rota</span>
      <strong>${esc(codes || "—")}</strong>
      ${names}
    </div>`;
  }

  async function ensureRoute(state) {
    const ac = state?.data;
    const callsign = routeCallsign(ac);
    if (!ac || !callsign) return;

    if (routeCache.has(callsign) && routeCache.get(callsign)?.status !== "loading") {
      state.marker.setPopupContent(popupFor(ac));
      return;
    }

    if (routeRequests.has(callsign)) {
      await routeRequests.get(callsign);
      state.marker.setPopupContent(popupFor(state.data));
      return;
    }

    routeCache.set(callsign, { status: "loading" });
    state.marker.setPopupContent(popupFor(ac));

    const lat = num(ac.lat);
    const lon = num(ac.lon);
    if (lat === null || lon === null) {
      routeCache.set(callsign, { status: "missing" });
      state.marker.setPopupContent(popupFor(ac));
      return;
    }

    const request = fetch(
      `https://api.adsb.lol/api/0/route/${encodeURIComponent(callsign)}/${lat.toFixed(5)}/${lon.toFixed(5)}`,
      { cache: "no-store", headers: { "Accept": "application/json" } }
    )
      .then(async response => {
        if (!response.ok) throw new Error("Route HTTP " + response.status);
        return response.json();
      })
      .then(route => {
        if (!route || route.airport_codes === "unknown") {
          routeCache.set(callsign, { status: "missing" });
        } else {
          routeCache.set(callsign, { status: "ok", data: route });
        }
      })
      .catch(error => {
        console.warn("Rota alınamadı:", callsign, error);
        routeCache.set(callsign, { status: "error" });
      })
      .finally(() => routeRequests.delete(callsign));

    routeRequests.set(callsign, request);
    await request;

    if (aircraft.get(state.id) === state) {
      state.marker.setPopupContent(popupFor(state.data));
    }
  }

  function popupFor(ac) {
    const flight = esc(cleanFlight(ac));
    const registration = esc(ac.r || "—");
    const type = esc(ac.t || ac.desc || "—");
    const squawk = esc(ac.squawk || "—");

    return `<div class="popup-flight">${flight}</div>
      <div class="popup-sub">${type}</div>
      ${routeHtml(ac)}
      <div class="popup-grid">
        <div><span>Kuyruk</span><strong>${registration}</strong></div>
        <div><span>İrtifa</span><strong>${esc(formatAltitude(ac.alt_baro))}</strong></div>
        <div><span>Hız</span><strong>${esc(formatSpeed(ac.gs))}</strong></div>
        <div><span>Heading</span><strong>${esc(formatTrack(displayHeading(ac)))}</strong></div>
        <div><span>Squawk</span><strong>${squawk}</strong></div>
      </div>`;
  }

  function aircraftLastSeenAt(ac) {
    const seenPos = num(ac.seen_pos);
    const seen = num(ac.seen);
    const ageSeconds = seenPos ?? seen ?? 0;
    return Date.now() - Math.max(0, Math.min(60, ageSeconds)) * 1000;
  }

  function upsertAircraft(ac, now) {
    const id = aircraftId(ac);
    const lat = num(ac.lat);
    const lon = num(ac.lon);
    if (!id || lat === null || lon === null) return null;

    const future = predictedPosition(ac) || [lat, lon];
    let state = aircraft.get(id);

    if (!state) {
      const marker = L.marker([lat, lon], {
        icon: iconFor(ac),
        keyboard: false,
        riseOnHover: true
      }).addTo(map);

      marker.bindPopup(popupFor(ac), { closeButton: false, offset: [0, -8] });

      state = {
        id,
        marker,
        from: L.latLng(lat, lon),
        to: L.latLng(future[0], future[1]),
        animStart: now,
        animDuration: REFRESH_MS,
        data: ac,
        lastSeenAt: aircraftLastSeenAt(ac)
      };

      marker.on("popupopen", () => ensureRoute(state));
      aircraft.set(id, state);
    } else {
      const current = state.marker.getLatLng();
      state.from = L.latLng(current.lat, current.lng);
      state.to = L.latLng(future[0], future[1]);
      state.animStart = now;
      state.animDuration = REFRESH_MS;
      state.data = ac;
      state.lastSeenAt = aircraftLastSeenAt(ac);
      state.marker.setIcon(iconFor(ac));
      state.marker.setPopupContent(popupFor(ac));
    }

    return id;
  }

  function animate(now) {
    for (const state of aircraft.values()) {
      const elapsed = Math.max(0, now - state.animStart);
      const p = Math.min(1, elapsed / state.animDuration);
      const eased = p < 1 ? (1 - Math.pow(1 - p, 2)) : 1;

      const lat = state.from.lat + (state.to.lat - state.from.lat) * eased;
      const lng = state.from.lng + (state.to.lng - state.from.lng) * eased;
      state.marker.setLatLng([lat, lng]);
    }
    requestAnimationFrame(animate);
  }
  requestAnimationFrame(animate);

  function radiusForView() {
    const center = map.getCenter();
    const edge = map.getBounds().getNorthEast();
    const meters = map.distance(center, edge);
    const nm = Math.ceil(meters / 1852);
    return Math.max(MIN_RADIUS_NM, Math.min(MAX_RADIUS_NM, nm));
  }

  async function fetchAircraft() {
    clearTimeout(refreshTimer);

    if (requestInFlight) {
      pendingRefresh = true;
      return;
    }

    requestInFlight = true;
    const revision = viewRevision;
    const center = map.getCenter();
    const radius = radiusForView();
    const lat = center.lat.toFixed(4);
    const lon = center.lng.toFixed(4);

    feedDot.classList.remove("bad");
    sourceEl.textContent = "Bağlanıyor…";

    try {
      const response = await fetch(
        `${ADSB_POINT_API}/${encodeURIComponent(lat)}/${encodeURIComponent(lon)}/${radius}`,
        {
          method: "GET",
          cache: "no-store",
          headers: { "Accept": "application/json" }
        }
      );

      const payload = await response.json().catch(() => null);

      if (!response.ok) {
        throw new Error(payload?.error || ("HTTP " + response.status));
      }

      if (!payload || !Array.isArray(payload.ac)) {
        throw new Error("Beklenmeyen uçak verisi.");
      }

      // User moved/zoomed while this request was running.
      // Do not paint the old area; immediately fetch the pending view afterwards.
      if (revision !== viewRevision) {
        pendingRefresh = true;
        return;
      }

      const now = performance.now();
      const wallNow = Date.now();
      const freshIds = new Set();

      for (const ac of payload.ac) {
        const id = upsertAircraft(ac, now);
        if (id) freshIds.add(id);
      }

      const queryCenter = L.latLng(Number(lat), Number(lon));
      const queryRadiusMeters = radius * 1852;

      for (const [id, state] of aircraft) {
        if (freshIds.has(id)) continue;

        const markerPos = state.marker.getLatLng();
        const outsideCurrentArea = map.distance(queryCenter, markerPos) > queryRadiusMeters * 1.08;
        const staleTooLong = wallNow - (state.lastSeenAt || 0) > ABSENT_GRACE_MS;

        if (outsideCurrentArea || staleTooLong) {
          map.removeLayer(state.marker);
          aircraft.delete(id);
        }
      }

      countEl.textContent = aircraft.size + " uçak";

      sourceEl.textContent = "ADSB.lol · direct";

      updateEl.textContent = new Intl.DateTimeFormat("tr-TR", {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
      }).format(new Date());

      feedDot.classList.remove("bad");
      feedDot.classList.add("ok");
    } catch (error) {
      // A failed refresh must never erase the last good aircraft set.
      if (revision === viewRevision) {
        console.error("Uçak verisi alınamadı:", error);
        feedDot.classList.remove("ok");
        feedDot.classList.add("bad");
        sourceEl.textContent = "ADSB.lol · son veri korunuyor";
        updateEl.textContent = "Geçici bağlantı hatası";
      }
    } finally {
      requestInFlight = false;

      if (pendingRefresh) {
        pendingRefresh = false;
        setTimeout(fetchAircraft, 0);
      } else {
        scheduleRefresh();
      }
    }
  }

  function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(fetchAircraft, REFRESH_MS);
  }

  function refreshForViewChange() {
    clearTimeout(refreshTimer);
    clearTimeout(moveRefreshTimer);
    clearTimeout(hintTimer);

    hint.classList.remove("hidden");
    hint.textContent = "Yeni bölgedeki uçaklar yükleniyor…";

    const center = map.getCenter();
    console.info("Harita bölgesi yenileniyor:", {
      lat: center.lat.toFixed(4),
      lon: center.lng.toFixed(4),
      radius: radiusForView()
    });

    fetchAircraft();
    hintTimer = setTimeout(() => hint.classList.add("hidden"), 1200);
  }

  function searchAircraft() {
    const q = searchInput.value.trim().toLowerCase();
    if (!q) return;

    for (const state of aircraft.values()) {
      const ac = state.data || {};
      const haystack = [
        ac.flight, ac.r, ac.hex, ac.t, ac.desc
      ].map(v => String(v || "").toLowerCase()).join(" ");

      if (haystack.includes(q)) {
        map.setView(state.marker.getLatLng(), Math.max(map.getZoom(), 10), { animate: true });
        state.marker.openPopup();
        return;
      }
    }

    searchInput.classList.add("not-found");
    setTimeout(() => searchInput.classList.remove("not-found"), 800);
  }

  function updateDetailMode() {
    document.body.classList.toggle("map-detailed", map.getZoom() >= 8);
  }

  function syncOptionsUi() {
    themeButtons.forEach(button => {
      button.classList.toggle("active", button.dataset.themeValue === document.body.dataset.theme);
    });

    sizeButtons.forEach(button => {
      button.classList.toggle("active", button.dataset.sizeValue === document.body.dataset.aircraftSize);
    });
  }

  function setTheme(theme) {
    document.body.dataset.theme = theme;
    localStorage.setItem("aviation-map-theme", theme);
    syncOptionsUi();
  }

  function setAircraftSize(size) {
    document.body.dataset.aircraftSize = size;
    localStorage.setItem("aviation-aircraft-size", size);

    for (const state of aircraft.values()) {
      state.marker.setIcon(iconFor(state.data));
    }

    syncOptionsUi();
  }

  function setOptionsOpen(open) {
    optionsPanel.classList.toggle("open", open);
    optionsPanel.setAttribute("aria-hidden", String(!open));
    optionsToggle.setAttribute("aria-expanded", String(open));
  }

  optionsToggle.addEventListener("click", () => {
    setOptionsOpen(!optionsPanel.classList.contains("open"));
  });

  optionsClose.addEventListener("click", () => setOptionsOpen(false));

  themeButtons.forEach(button => {
    button.addEventListener("click", () => setTheme(button.dataset.themeValue));
  });

  sizeButtons.forEach(button => {
    button.addEventListener("click", () => setAircraftSize(button.dataset.sizeValue));
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape") setOptionsOpen(false);
  });

  searchInput.addEventListener("keydown", event => {
    if (event.key === "Enter") searchAircraft();
  });

  map.on("moveend", () => {
    viewRevision += 1;
    updateDetailMode();
    refreshForViewChange();
  });

  updateDetailMode();
  fetchAircraft();
})();