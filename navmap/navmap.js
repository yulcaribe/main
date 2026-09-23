(() => {
  "use strict";

  if (!window.maplibregl) {
    document.getElementById("boot-detail").textContent = "MapLibre yüklenemedi.";
    return;
  }

  const API = "/main/api/navmap.php";
  const sourceId = "navdata";
  const mapEl = document.getElementById("map");
  const boot = document.getElementById("boot");
  const bootDetail = document.getElementById("boot-detail");
  const statusDot = document.getElementById("status-dot");
  const statusText = document.getElementById("status-text");
  const featureCount = document.getElementById("feature-count");
  const zoomHint = document.getElementById("zoom-hint");
  const layerToggle = document.getElementById("layers-toggle");
  const layersPanel = document.getElementById("layers-panel");
  const layersClose = document.getElementById("layers-close");
  const searchInput = document.getElementById("nav-search");
  const searchResults = document.getElementById("search-results");

  const layerInputs = [...document.querySelectorAll("[data-nav-layer]")];
  const countEls = Object.fromEntries(
    [...document.querySelectorAll("[data-layer-count]")].map(el => [el.dataset.layerCount, el])
  );

  const palette = {
    airport: "#7ee7ff",
    navaid: "#ffc76b",
    waypoint: "#d6e1e7",
    airway: "#5fdbe8",
    sid: "#70e8a7",
    star: "#bc9cff",
    airspace: "#ff7f94"
  };

  let requestController = null;
  let searchController = null;
  let loadTimer = null;
  let searchTimer = null;

  const emptyGeojson = () => ({ type: "FeatureCollection", features: [] });

  const map = new maplibregl.Map({
    container: mapEl,
    center: [30.80, 36.90],
    zoom: 6,
    minZoom: 2,
    maxZoom: 15,
    hash: true,
    style: {
      version: 8,
      glyphs: "https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf",
      sources: {
        osm: {
          type: "raster",
          tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
          tileSize: 256,
          attribution: "© OpenStreetMap contributors"
        }
      },
      layers: [
        {
          id: "osm-base",
          type: "raster",
          source: "osm",
          paint: {
            "raster-saturation": -0.82,
            "raster-brightness-min": 0.05,
            "raster-brightness-max": 0.42,
            "raster-contrast": 0.22
          }
        }
      ]
    }
  });

  map.addControl(new maplibregl.NavigationControl({ showCompass: true }), "bottom-left");
  map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: "nautical" }), "bottom-left");

  function selectedLayers() {
    return layerInputs
      .filter(input => input.checked)
      .map(input => input.dataset.navLayer);
  }

  function visibilityFor(name) {
    return selectedLayers().includes(name) ? "visible" : "none";
  }

  function setLayerVisibility(name) {
    const ids = [
      `nav-${name}-fill`,
      `nav-${name}-line`,
      `nav-${name}-circle`,
      `nav-${name}-label`
    ];
    for (const id of ids) {
      if (map.getLayer(id)) map.setLayoutProperty(id, "visibility", visibilityFor(name));
    }
  }

  function addNavLayers() {
    if (map.getSource(sourceId)) return;

    map.addSource(sourceId, {
      type: "geojson",
      data: emptyGeojson()
    });

    map.addLayer({
      id: "nav-airspace-fill",
      type: "fill",
      source: sourceId,
      filter: ["all", ["==", ["get", "layer"], "airspace"], ["==", ["geometry-type"], "Polygon"]],
      paint: {
        "fill-color": palette.airspace,
        "fill-opacity": 0.055
      },
      layout: { visibility: visibilityFor("airspace") }
    });

    map.addLayer({
      id: "nav-airspace-line",
      type: "line",
      source: sourceId,
      filter: ["==", ["get", "layer"], "airspace"],
      paint: {
        "line-color": palette.airspace,
        "line-width": ["interpolate", ["linear"], ["zoom"], 5, 0.8, 10, 1.5],
        "line-opacity": 0.66
      },
      layout: { visibility: visibilityFor("airspace") }
    });

    for (const type of ["airway", "sid", "star"]) {
      map.addLayer({
        id: `nav-${type}-line`,
        type: "line",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: type === "airway" ? 5 : 8,
        paint: {
          "line-color": palette[type],
          "line-width": [
            "interpolate", ["linear"], ["zoom"],
            type === "airway" ? 5 : 8, type === "airway" ? 0.75 : 1.0,
            12, type === "airway" ? 1.7 : 2.2
          ],
          "line-opacity": type === "airway" ? 0.72 : 0.82
        },
        layout: { visibility: visibilityFor(type) }
      });

      map.addLayer({
        id: `nav-${type}-label`,
        type: "symbol",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: type === "airway" ? 7 : 9,
        layout: {
          visibility: visibilityFor(type),
          "symbol-placement": "line",
          "symbol-spacing": 420,
          "text-field": ["coalesce", ["get", "ident"], ""],
          "text-size": 10,
          "text-font": ["Noto Sans Regular"],
          "text-keep-upright": true
        },
        paint: {
          "text-color": palette[type],
          "text-halo-color": "#06111a",
          "text-halo-width": 1.25
        }
      });
    }

    for (const type of ["airport", "navaid", "waypoint"]) {
      const minZoom = type === "airport" ? 5 : type === "navaid" ? 6 : 8;
      const radius = type === "airport" ? 4.8 : type === "navaid" ? 3.8 : 2.4;

      map.addLayer({
        id: `nav-${type}-circle`,
        type: "circle",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: minZoom,
        paint: {
          "circle-radius": [
            "interpolate", ["linear"], ["zoom"],
            minZoom, radius * 0.78,
            12, radius * 1.25
          ],
          "circle-color": palette[type],
          "circle-opacity": type === "waypoint" ? 0.78 : 0.95,
          "circle-stroke-color": "#06111a",
          "circle-stroke-width": 1
        },
        layout: { visibility: visibilityFor(type) }
      });

      map.addLayer({
        id: `nav-${type}-label`,
        type: "symbol",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: type === "airport" ? 6 : type === "navaid" ? 7 : 9,
        layout: {
          visibility: visibilityFor(type),
          "text-field": ["coalesce", ["get", "ident"], ""],
          "text-size": type === "airport" ? 11 : 9,
          "text-font": ["Noto Sans Regular"],
          "text-offset": [0.75, 0],
          "text-anchor": "left",
          "text-optional": true
        },
        paint: {
          "text-color": palette[type],
          "text-halo-color": "#06111a",
          "text-halo-width": 1.35
        }
      });
    }

    const clickable = [
      "nav-airspace-fill", "nav-airspace-line",
      "nav-airway-line", "nav-sid-line", "nav-star-line",
      "nav-airport-circle", "nav-navaid-circle", "nav-waypoint-circle"
    ];

    for (const id of clickable) {
      map.on("mouseenter", id, () => { map.getCanvas().style.cursor = "pointer"; });
      map.on("mouseleave", id, () => { map.getCanvas().style.cursor = ""; });
      map.on("click", id, event => {
        const feature = event.features && event.features[0];
        if (!feature) return;
        showPopup(feature, event.lngLat);
      });
    }
  }

  function esc(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function infoRow(label, value) {
    if (value === null || value === undefined || value === "") return "";
    return `<div><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
  }

  function showPopup(feature, lngLat) {
    const p = feature.properties || {};
    const title = p.ident || p.name || p.layer || "Navdata";
    const subtitle = [p.layer, p.name && p.name !== title ? p.name : null].filter(Boolean).join(" · ");

    let rows = "";
    if (p.layer === "airport" || p.layer === "navaid" || p.layer === "waypoint") {
      rows += infoRow("IATA", p.iata);
      rows += infoRow("Şehir", p.city);
      rows += infoRow("Elev", p.elevation_ft != null ? `${p.elevation_ft} ft` : null);
      rows += infoRow("Frekans", p.frequency);
      rows += infoRow("Channel", p.channel);
      rows += infoRow("Status", p.status);
    } else if (p.layer === "airway" || p.layer === "sid" || p.layer === "star") {
      rows += infoRow("From", p.from_ident);
      rows += infoRow("To", p.to_ident);
      rows += infoRow("Lower", p.lower_text);
      rows += infoRow("Upper", p.upper_unlimited ? "UNL" : p.upper_text);
      rows += infoRow("Forward", p.forward);
      rows += infoRow("Backward", p.backward);
    } else if (p.layer === "airspace") {
      rows += infoRow("Lower", p.lower_text);
      rows += infoRow("Upper", p.upper_unlimited ? "UNL" : p.upper_text);
      rows += infoRow("Type", p.type_code);
      rows += infoRow("Usage", p.usage_code);
      rows += infoRow("Control", p.control_type);
    }

    const html = `
      <div class="popup">
        <h3>${esc(title)}</h3>
        <div class="sub">${esc(subtitle)}</div>
        <div class="popup-grid">${rows || "<div><span>Layer</span><strong>" + esc(p.layer) + "</strong></div>"}</div>
      </div>`;

    new maplibregl.Popup({ closeButton: true, maxWidth: "360px" })
      .setLngLat(lngLat)
      .setHTML(html)
      .addTo(map);
  }

  function updateCounts(counts = {}) {
    let total = 0;
    for (const key of Object.keys(countEls)) {
      const count = Number(counts[key] || 0);
      total += count;
      countEls[key].textContent = new Intl.NumberFormat("tr-TR").format(count);
    }
    featureCount.textContent = new Intl.NumberFormat("tr-TR").format(total) + " obje";
  }

  function updateZoomHint(truncated = false) {
    const z = map.getZoom();
    if (z < 5) {
      zoomHint.textContent = "Navdata için biraz yaklaş · z5+";
      zoomHint.style.display = "block";
      return;
    }
    if (truncated) {
      zoomHint.textContent = "Yoğun bölge · biraz daha yaklaş";
      zoomHint.style.display = "block";
      return;
    }
    if (z < 8) {
      zoomHint.textContent = "Waypoint + SID/STAR z8 üzerinde görünür";
      zoomHint.style.display = "block";
      return;
    }
    zoomHint.style.display = "none";
  }

  function bboxParams() {
    const b = map.getBounds();
    return {
      west: b.getWest(),
      south: b.getSouth(),
      east: b.getEast(),
      north: b.getNorth()
    };
  }

  async function loadViewport() {
    clearTimeout(loadTimer);

    if (requestController) requestController.abort();
    requestController = new AbortController();

    const z = Math.floor(map.getZoom());
    const selected = selectedLayers();
    const bounds = bboxParams();

    statusText.textContent = "Navdata yükleniyor…";
    statusDot.classList.remove("ok", "bad");

    if (z < 5 || !selected.length) {
      map.getSource(sourceId)?.setData(emptyGeojson());
      updateCounts({});
      statusText.textContent = z < 5 ? "Zoom z5 bekleniyor" : "Katman kapalı";
      updateZoomHint(false);
      return;
    }

    const q = new URLSearchParams({
      action: "viewport",
      z: String(z),
      layers: selected.join(","),
      west: String(bounds.west),
      south: String(bounds.south),
      east: String(bounds.east),
      north: String(bounds.north)
    });

    try {
      const response = await fetch(`${API}?${q}`, {
        cache: "no-store",
        signal: requestController.signal
      });
      const payload = await response.json().catch(() => null);

      if (!response.ok || !payload?.ok || !payload?.data) {
        throw new Error(payload?.error || `HTTP ${response.status}`);
      }

      map.getSource(sourceId).setData(payload.data);
      updateCounts(payload.counts || {});
      updateZoomHint(Boolean(payload.truncated));

      statusText.textContent = payload.truncated
        ? "Yoğun görünüm · veri sınırlandı"
        : "MariaDB · canlı görünüm";
      statusDot.classList.add("ok");
    } catch (error) {
      if (error.name === "AbortError") return;
      console.error("[NavMap]", error);
      statusText.textContent = "Navdata API hatası";
      statusDot.classList.add("bad");
      updateZoomHint(false);
    }
  }

  function scheduleViewportLoad(delay = 180) {
    clearTimeout(loadTimer);
    loadTimer = setTimeout(loadViewport, delay);
  }

  async function search(q) {
    if (searchController) searchController.abort();
    searchController = new AbortController();

    if (q.trim().length < 2) {
      searchResults.classList.remove("open");
      searchResults.innerHTML = "";
      return;
    }

    try {
      const response = await fetch(`${API}?action=search&q=${encodeURIComponent(q.trim())}`, {
        cache: "no-store",
        signal: searchController.signal
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) throw new Error(payload?.error || "Arama hatası");

      renderSearch(payload.results || []);
    } catch (error) {
      if (error.name === "AbortError") return;
      renderSearch([]);
    }
  }

  function renderSearch(results) {
    if (!results.length) {
      searchResults.innerHTML = '<div style="padding:12px;color:#879ca8;font-size:10px">Sonuç bulunamadı.</div>';
      searchResults.classList.add("open");
      return;
    }

    searchResults.innerHTML = results.map((item, i) => `
      <button class="search-result" type="button" data-result-index="${i}">
        <span>
          <strong>${esc(item.ident || item.name || "—")}</strong>
          <small>${esc(item.name || "")}</small>
        </span>
        <span class="badge">${esc(item.kind)}</span>
      </button>
    `).join("");

    searchResults.classList.add("open");

    searchResults.querySelectorAll("[data-result-index]").forEach(button => {
      button.addEventListener("click", () => {
        const item = results[Number(button.dataset.resultIndex)];
        searchResults.classList.remove("open");

        if (Number.isFinite(item.lon) && Number.isFinite(item.lat)) {
          map.flyTo({
            center: [item.lon, item.lat],
            zoom: Math.max(map.getZoom(), item.kind === "waypoint" ? 10 : 8),
            essential: true
          });
        } else {
          searchInput.value = item.ident || "";
          statusText.textContent = `${item.kind.toUpperCase()} bulundu · haritada görünmesi için ilgili bölgeye git`;
        }
      });
    });
  }

  map.on("load", () => {
    addNavLayers();
    boot.classList.add("hidden");
    scheduleViewportLoad(0);
  });

  map.on("moveend", () => scheduleViewportLoad());
  map.on("zoomend", () => updateZoomHint(false));

  layerInputs.forEach(input => {
    input.addEventListener("change", () => {
      setLayerVisibility(input.dataset.navLayer);
      scheduleViewportLoad(0);
    });
  });

  layerToggle.addEventListener("click", () => layersPanel.classList.toggle("open"));
  layersClose.addEventListener("click", () => layersPanel.classList.remove("open"));

  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => search(searchInput.value), 240);
  });

  searchInput.addEventListener("keydown", event => {
    if (event.key === "Escape") searchResults.classList.remove("open");
  });

  document.addEventListener("click", event => {
    if (!event.target.closest(".search")) searchResults.classList.remove("open");
  });

  bootDetail.textContent = "Navdata katmanları hazırlanıyor…";
})();
