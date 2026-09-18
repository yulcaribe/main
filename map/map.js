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
  const MAX_RADIUS_NM = 250;
  const MIN_RADIUS_NM = 10;

  const countEl = document.getElementById("aircraft-count");
  const sourceEl = document.getElementById("source-label");
  const updateEl = document.getElementById("update-label");
  const feedDot = document.getElementById("feed-dot");
  const searchInput = document.getElementById("flight-search");
  const themeButton = document.getElementById("theme-toggle");
  const hint = document.getElementById("map-hint");

  const savedTheme = localStorage.getItem("aviation-map-theme");
  if (savedTheme === "day" || savedTheme === "night") {
    document.body.dataset.theme = savedTheme;
  }
  updateThemeButton();

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
  let refreshTimer = null;
  let requestSerial = 0;
  const LOCAL_FLIGHT_API = "/main/api/flights.php";

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

  function iconFor(ac) {
    const flight = esc(cleanFlight(ac));
    const track = num(ac.track) ?? 0;
    const emergencyClass = isEmergency(ac) ? " emergency" : "";

    return L.divIcon({
      className: "aircraft-div-icon",
      html: `<div class="aircraft-marker${emergencyClass}">
        <span class="plane" style="transform:rotate(${track - 45}deg)">✈</span>
        <span class="aircraft-label">${flight}</span>
      </div>`,
      iconSize: [28, 28],
      iconAnchor: [14, 14]
    });
  }

  function popupFor(ac) {
    const flight = esc(cleanFlight(ac));
    const registration = esc(ac.r || "Tescil bilinmiyor");
    const type = esc(ac.t || ac.desc || "Tip bilinmiyor");
    const squawk = esc(ac.squawk || "—");

    return `<div class="popup-flight">${flight}</div>
      <div class="popup-sub">${registration} · ${type}</div>
      <div class="popup-grid">
        <div><span>İrtifa</span><strong>${esc(formatAltitude(ac.alt_baro))}</strong></div>
        <div><span>Hız</span><strong>${esc(formatSpeed(ac.gs))}</strong></div>
        <div><span>Heading</span><strong>${esc(formatTrack(ac.track))}</strong></div>
        <div><span>Squawk</span><strong>${squawk}</strong></div>
      </div>`;
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
        data: ac
      };

      aircraft.set(id, state);
    } else {
      const current = state.marker.getLatLng();
      state.from = L.latLng(current.lat, current.lng);
      state.to = L.latLng(future[0], future[1]);
      state.animStart = now;
      state.animDuration = REFRESH_MS;
      state.data = ac;
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
    const serial = ++requestSerial;
    const center = map.getCenter();
    const radius = radiusForView();
    const lat = center.lat.toFixed(4);
    const lon = center.lng.toFixed(4);

    feedDot.classList.remove("bad");
    sourceEl.textContent = "Bağlanıyor…";

    try {
      const response = await fetch(
        `${LOCAL_FLIGHT_API}?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}&radius=${radius}`,
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

      if (serial !== requestSerial) return;

      const now = performance.now();
      const freshIds = new Set();

      for (const ac of payload.ac) {
        const id = upsertAircraft(ac, now);
        if (id) freshIds.add(id);
      }

      for (const [id, state] of aircraft) {
        if (!freshIds.has(id)) {
          map.removeLayer(state.marker);
          aircraft.delete(id);
        }
      }

      countEl.textContent = aircraft.size + " uçak";
      sourceEl.textContent = payload?._proxy?.source || "Airplanes.live";
      updateEl.textContent = new Intl.DateTimeFormat("tr-TR", {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
      }).format(new Date());

      feedDot.classList.remove("bad");
      feedDot.classList.add("ok");
    } catch (error) {
      if (serial !== requestSerial) return;

      console.error("Uçak verisi alınamadı:", error);
      feedDot.classList.remove("ok");
      feedDot.classList.add("bad");
      sourceEl.textContent = "Veri yok";
      updateEl.textContent = "Bağlantı hatası";
    }

    scheduleRefresh();
  }

  function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(fetchAircraft, REFRESH_MS);
  }

  function refreshForMapMove() {
    clearTimeout(refreshTimer);
    clearTimeout(hintTimer);
    hint.classList.remove("hidden");
    hint.textContent = "Yeni bölgedeki uçaklar yükleniyor…";
    hintTimer = setTimeout(() => hint.classList.add("hidden"), 2200);
    fetchAircraft();
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

  function updateThemeButton() {
    const night = document.body.dataset.theme === "night";
    themeButton.textContent = night ? "☀" : "☾";
    themeButton.title = night ? "Gündüz moduna geç" : "Gece moduna geç";
  }

  themeButton.addEventListener("click", () => {
    const next = document.body.dataset.theme === "night" ? "day" : "night";
    document.body.dataset.theme = next;
    localStorage.setItem("aviation-map-theme", next);
    updateThemeButton();
  });

  searchInput.addEventListener("keydown", event => {
    if (event.key === "Enter") searchAircraft();
  });

  map.on("zoomend", updateDetailMode);
  map.on("moveend", refreshForMapMove);

  updateDetailMode();
  fetchAircraft();
})();