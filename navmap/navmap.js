(() => {
  "use strict";

  if (!window.maplibregl) {
    document.getElementById("boot-detail").textContent = "MapLibre yüklenemedi.";
    return;
  }

  const API = "/main/api/navmap.php";
  const WAFS_API = "/main/api/wafs.php";
  const sourceId = "navdata";
  const WAFS_PRODUCTS = {
    edr: { label: "Turbulence / EDR" },
    icing: { label: "Icing severity" },
    cbextent: { label: "CB horizontal extent" },
    cbtop: { label: "CB tops" },
    wind: { label: "Wind speed" }
  };
  const mapEl = document.getElementById("map");
  const boot = document.getElementById("boot");
  const bootDetail = document.getElementById("boot-detail");
  const statusDot = document.getElementById("status-dot");
  const statusText = document.getElementById("status-text");
  const featureCount = document.getElementById("feature-count");
  const zoomHint = document.getElementById("zoom-hint");
  const searchInput = document.getElementById("nav-search");
  const searchResults = document.getElementById("search-results");
  const modeButtons = [...document.querySelectorAll("[data-panel-target]")];
  const toolPanels = [...document.querySelectorAll(".tool-panel")];
  const panelCloseButtons = [...document.querySelectorAll("[data-panel-close]")];
  const timeInput = document.getElementById("map-time");
  const timeSlider = document.getElementById("map-time-slider");
  const timeLabel = document.getElementById("selected-time-label");
  const timelineOffset = document.getElementById("timeline-offset");
  const timeNowButton = document.getElementById("time-now");
  const notamTimeStatus = document.getElementById("notam-time-status");
  const wafsEnabled = document.getElementById("wafs-enabled");
  const wafsFL = document.getElementById("wafs-fl");
  const wafsStatus = document.getElementById("wafs-status");
  const wafsProductInputs = [...document.querySelectorAll("[data-wafs-product]")];
  const wafsOpacityInputs = [...document.querySelectorAll("[data-wafs-opacity]")];

  const layerInputs = [...document.querySelectorAll("[data-nav-layer]")];
  const countKeys = [...new Set(
    [...document.querySelectorAll("[data-layer-count]")].map(el => el.dataset.layerCount)
  )];

  const palette = {
    airport: "#7ee7ff",
    navaid: "#ffc76b",
    waypoint: "#d6e1e7",
    airway: "#5fdbe8",
    sid: "#70e8a7",
    star: "#bc9cff",
    airspace: "#ff7f94",
    notam: "#ffd35f"
  };

  const HIT_TOLERANCE = window.matchMedia("(pointer: coarse)").matches ? 18 : 11;

  let requestController = null;
  let searchController = null;
  let loadTimer = null;
  let searchTimer = null;
  let weatherController = null;
  let weatherGeneration = 0;
  let timelineAnchor = null;
  const weatherOverlays = new Map();

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
      `nav-${name}-hit`,
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
      const minZoom = type === "airway" ? 5 : 8;

      // Invisible wide line makes thin aviation routes easy to select without
      // visually bloating the chart.
      map.addLayer({
        id: `nav-${type}-hit`,
        type: "line",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: minZoom,
        paint: {
          "line-color": palette[type],
          "line-width": [
            "interpolate", ["linear"], ["zoom"],
            minZoom, type === "airway" ? 12 : 15,
            10, type === "airway" ? 15 : 18,
            15, type === "airway" ? 18 : 22
          ],
          "line-opacity": 0.01
        },
        layout: { visibility: visibilityFor(type) }
      });

      map.addLayer({
        id: `nav-${type}-line`,
        type: "line",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: minZoom,
        paint: {
          "line-color": palette[type],
          "line-width": [
            "interpolate", ["linear"], ["zoom"],
            minZoom, type === "airway" ? 1.15 : 2.1,
            10, type === "airway" ? 1.8 : 3.1,
            12, type === "airway" ? 2.35 : 4.2,
            15, type === "airway" ? 3.0 : 5.7
          ],
          "line-opacity": type === "airway" ? 0.76 : 0.9
        },
        layout: {
          visibility: visibilityFor(type),
          "line-cap": "round",
          "line-join": "round"
        }
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
          "symbol-spacing": type === "airway" ? 520 : 380,
          "text-field": ["coalesce", ["get", "ident"], ""],
          "text-size": [
            "interpolate", ["linear"], ["zoom"],
            type === "airway" ? 7 : 9, type === "airway" ? 10 : 11,
            12, type === "airway" ? 11.5 : 13,
            15, type === "airway" ? 13 : 15
          ],
          "text-font": ["Noto Sans Regular"],
          "text-keep-upright": true,
          "text-optional": true
        },
        paint: {
          "text-color": palette[type],
          "text-halo-color": "#06111a",
          "text-halo-width": 1.5
        }
      });
    }

    for (const type of ["airport", "navaid", "waypoint"]) {
      const minZoom = type === "airport" ? 5 : type === "navaid" ? 6 : 8;
      const sizes = type === "airport"
        ? [6.0, 8.0, 10.5, 13.0]
        : type === "navaid"
          ? [5.0, 6.7, 8.8, 11.0]
          : [4.0, 5.5, 7.5, 9.5];

      // Large, nearly transparent click target. The visible symbol remains
      // chart-like while the interaction area stays comfortable on mouse/touch.
      map.addLayer({
        id: `nav-${type}-hit`,
        type: "circle",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: minZoom,
        paint: {
          "circle-radius": [
            "interpolate", ["linear"], ["zoom"],
            minZoom, type === "waypoint" ? 11 : 13,
            10, type === "waypoint" ? 14 : 16,
            15, type === "waypoint" ? 18 : 21
          ],
          "circle-color": palette[type],
          "circle-opacity": 0.01,
          "circle-stroke-opacity": 0
        },
        layout: { visibility: visibilityFor(type) }
      });

      map.addLayer({
        id: `nav-${type}-circle`,
        type: "circle",
        source: sourceId,
        filter: ["==", ["get", "layer"], type],
        minzoom: minZoom,
        paint: {
          "circle-radius": [
            "interpolate", ["linear"], ["zoom"],
            minZoom, sizes[0],
            9, sizes[1],
            12, sizes[2],
            15, sizes[3]
          ],
          "circle-color": palette[type],
          "circle-opacity": type === "waypoint" ? 0.9 : 0.98,
          "circle-stroke-color": "#06111a",
          "circle-stroke-width": [
            "interpolate", ["linear"], ["zoom"],
            minZoom, 1.1,
            12, 1.6,
            15, 2.0
          ]
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
          "text-size": [
            "interpolate", ["linear"], ["zoom"],
            type === "airport" ? 6 : type === "navaid" ? 7 : 9,
            type === "airport" ? 11.5 : type === "navaid" ? 10.5 : 10,
            12,
            type === "airport" ? 13.5 : type === "navaid" ? 12.5 : 12,
            15,
            type === "airport" ? 15.5 : type === "navaid" ? 14.5 : 14
          ],
          "text-font": ["Noto Sans Regular"],
          "text-offset": [0.9, 0],
          "text-anchor": "left",
          "text-optional": true
        },
        paint: {
          "text-color": palette[type],
          "text-halo-color": "#06111a",
          "text-halo-width": 1.55
        }
      });
    }

    map.addLayer({
      id: "nav-notam-fill",
      type: "fill",
      source: sourceId,
      filter: ["all", ["==", ["get", "layer"], "notam"], ["==", ["geometry-type"], "Polygon"]],
      paint: { "fill-color": palette.notam, "fill-opacity": 0.13 },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-line",
      type: "line",
      source: sourceId,
      filter: ["==", ["get", "layer"], "notam"],
      paint: {
        "line-color": palette.notam,
        "line-width": ["interpolate", ["linear"], ["zoom"], 5, 1.2, 10, 2.1],
        "line-opacity": 0.9
      },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-circle",
      type: "circle",
      source: sourceId,
      filter: ["all", ["==", ["get", "layer"], "notam"], ["==", ["geometry-type"], "Point"]],
      paint: {
        "circle-radius": ["interpolate", ["linear"], ["zoom"], 5, 4, 10, 7],
        "circle-color": palette.notam,
        "circle-stroke-color": "#06111a",
        "circle-stroke-width": 1.4
      },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-label",
      type: "symbol",
      source: sourceId,
      filter: ["==", ["get", "layer"], "notam"],
      minzoom: 7,
      layout: {
        visibility: visibilityFor("notam"),
        "text-field": ["coalesce", ["get", "ident"], "NOTAM"],
        "text-size": 10,
        "text-font": ["Noto Sans Regular"],
        "text-offset": [0.8, 0.8],
        "text-optional": true
      },
      paint: {
        "text-color": palette.notam,
        "text-halo-color": "#06111a",
        "text-halo-width": 1.5
      }
    });

    const interactiveLayerIds = [
      "nav-airport-hit",
      "nav-navaid-hit",
      "nav-waypoint-hit",
      "nav-sid-hit",
      "nav-star-hit",
      "nav-airway-hit",
      "nav-airspace-fill",
      "nav-airspace-line",
      "nav-notam-fill",
      "nav-notam-line",
      "nav-notam-circle",
      "nav-notam-label"
    ];

    const priority = {
      airport: 0,
      navaid: 1,
      waypoint: 2,
      sid: 3,
      star: 4,
      airway: 5,
      notam: 6,
      airspace: 7
    };

    function pickInteractiveFeature(point) {
      const box = [
        [point.x - HIT_TOLERANCE, point.y - HIT_TOLERANCE],
        [point.x + HIT_TOLERANCE, point.y + HIT_TOLERANCE]
      ];

      const features = map.queryRenderedFeatures(box, {
        layers: interactiveLayerIds.filter(id => map.getLayer(id))
      });

      if (!features.length) return null;

      features.sort((a, b) => {
        const pa = priority[a.properties?.layer] ?? 99;
        const pb = priority[b.properties?.layer] ?? 99;
        return pa - pb;
      });

      return features[0];
    }

    map.on("mousemove", event => {
      map.getCanvas().style.cursor = pickInteractiveFeature(event.point) ? "pointer" : "";
    });

    map.on("click", event => {
      const feature = pickInteractiveFeature(event.point);
      if (!feature) return;
      showPopup(feature, event.lngLat);
    });

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
    let detailText = "";
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
    } else if (p.layer === "notam") {
      rows += infoRow("Location", p.icao_location || p.location);
      rows += infoRow("Class", p.classification);
      rows += infoRow("Valid from", p.effective_start);
      rows += infoRow("Valid to", p.effective_end_raw || p.effective_end);
      rows += infoRow("Lower", p.lower_limit);
      rows += infoRow("Upper", p.upper_limit);
      rows += infoRow("Map source", p.geometry_source === "airport-location" ? "Airport marker fallback" : "FAA geometry");
      detailText = p.text || "";
    }

    const html = `
      <div class="popup">
        <h3>${esc(title)}</h3>
        <div class="sub">${esc(subtitle)}</div>
        <div class="popup-grid">${rows || "<div><span>Layer</span><strong>" + esc(p.layer) + "</strong></div>"}</div>
        ${detailText ? '<div class="notam-text">' + esc(detailText) + '</div>' : ''}
      </div>`;

    new maplibregl.Popup({ closeButton: true, maxWidth: "360px" })
      .setLngLat(lngLat)
      .setHTML(html)
      .addTo(map);
  }

  function updateCounts(counts = {}) {
    let total = 0;
    for (const key of countKeys) {
      const count = Number(counts[key] || 0);
      total += count;
      document.querySelectorAll(`[data-layer-count="${key}"]`).forEach(el => {
        el.textContent = new Intl.NumberFormat("tr-TR").format(count);
      });
    }
    featureCount.textContent = new Intl.NumberFormat("tr-TR").format(total) + " obje";
    if (notamTimeStatus) {
      const n = Number(counts.notam || 0);
      notamTimeStatus.textContent = selectedLayers().includes("notam")
        ? new Intl.NumberFormat("tr-TR").format(n) + " NOTAM · " + formatSelectedUtc()
        : "FAA geometry veya meydan konumu bulunan NOTAM'lar gösterilir.";
    }
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

  function inputValueFromDate(date) {
    return date.toISOString().slice(0, 16);
  }

  function selectedTimeDate() {
    const raw = timeInput?.value || "";
    const utcRaw = raw ? (raw.length === 16 ? raw + ":00Z" : raw + "Z") : "";
    const parsed = utcRaw ? new Date(utcRaw) : new Date();
    return Number.isFinite(parsed.getTime()) ? parsed : new Date();
  }

  function selectedTimeIso() {
    return selectedTimeDate().toISOString();
  }

  function formatSelectedUtc() {
    return selectedTimeDate().toISOString().slice(0, 16).replace("T", " ") + "Z";
  }

  function updateTimelineOffset(date) {
    if (!timelineAnchor || !timelineOffset) return;
    const hours = Math.round((date.getTime() - timelineAnchor.getTime()) / 3600000);
    timelineOffset.textContent = hours === 0 ? "NOW" : (hours > 0 ? "+" + hours + "h" : hours + "h");
  }

  function setSelectedTime(date, reload = true, syncSlider = true) {
    if (!timeInput) return;
    timeInput.value = inputValueFromDate(date);
    if (timeLabel) timeLabel.textContent = formatSelectedUtc();
    updateTimelineOffset(date);
    if (syncSlider && timeSlider && timelineAnchor) {
      const hours = Math.round((date.getTime() - timelineAnchor.getTime()) / 3600000);
      timeSlider.value = String(Math.max(-72, Math.min(72, hours)));
    }
    if (reload && map.loaded()) {
      scheduleViewportLoad(0);
      loadWeatherOverlays().catch(console.error);
    }
  }

  function initializeTime() {
    const now = new Date();
    now.setUTCSeconds(0, 0);
    timelineAnchor = now;
    if (timeSlider) timeSlider.value = "0";
    setSelectedTime(now, false, false);
  }

  function wafsSourceId(product) {
    return `wafs-${product}-source`;
  }

  function wafsLayerId(product) {
    return `wafs-${product}-layer`;
  }

  function wafsOpacity(product) {
    const input = document.querySelector(`[data-wafs-opacity="${product}"]`);
    const n = Number(input?.value || 40);
    return Math.max(0.1, Math.min(0.85, n / 100));
  }

  function activeWafsProducts() {
    if (!wafsEnabled?.checked) return [];
    return wafsProductInputs.filter(input => input.checked).map(input => input.dataset.wafsProduct);
  }

  function clearWeatherProduct(product) {
    const layerId = wafsLayerId(product);
    const sourceIdForProduct = wafsSourceId(product);
    if (map.getLayer(layerId)) map.removeLayer(layerId);
    if (map.getSource(sourceIdForProduct)) map.removeSource(sourceIdForProduct);
    const state = weatherOverlays.get(product);
    if (state?.url) URL.revokeObjectURL(state.url);
    weatherOverlays.delete(product);
  }

  function clearWeatherOverlays(keep = new Set()) {
    for (const product of [...weatherOverlays.keys()]) {
      if (!keep.has(product)) clearWeatherProduct(product);
    }
  }

  function loadImage(url, signal) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const abort = () => { img.src = ""; reject(new DOMException("Aborted", "AbortError")); };
      signal?.addEventListener("abort", abort, { once: true });
      img.onload = () => { signal?.removeEventListener("abort", abort); resolve(img); };
      img.onerror = () => { signal?.removeEventListener("abort", abort); reject(new Error("WAFS görseli açılamadı.")); };
      img.src = url;
    });
  }

  async function fetchWafsFrame(product, fl, signal) {
    const valid = selectedTimeIso().slice(0, 16).replace("T", " ");
    const q = new URLSearchParams({ action: "image", product, fl: String(fl), valid });
    const response = await fetch(`${WAFS_API}?${q}`, { cache: "no-store", signal });
    if (!response.ok) {
      const errorPayload = await response.json().catch(() => null);
      throw new Error(errorPayload?.error || `WAFS HTTP ${response.status}`);
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    try {
      const img = await loadImage(url, signal);
      const yMax = Math.PI * (img.naturalHeight / img.naturalWidth);
      const maxLat = 180 / Math.PI * Math.atan(Math.sinh(yMax));
      return {
        product,
        url,
        maxLat,
        validUtc: response.headers.get("x-yc-wafs-valid-utc"),
        runUtc: response.headers.get("x-yc-wafs-run"),
        layerFL: response.headers.get("x-yc-wafs-layer-fl"),
        forecastHour: response.headers.get("x-yc-wafs-forecast-hour")
      };
    } catch (error) {
      URL.revokeObjectURL(url);
      throw error;
    }
  }

  async function loadWeatherOverlays() {
    if (!map.loaded()) return;

    const generation = ++weatherGeneration;
    weatherController?.abort();
    weatherController = new AbortController();

    const active = activeWafsProducts();
    const activeSet = new Set(active);
    clearWeatherOverlays(activeSet);

    if (!wafsEnabled?.checked) {
      clearWeatherOverlays();
      if (wafsStatus) wafsStatus.textContent = "WAFS kapalı.";
      return;
    }

    if (!active.length) {
      clearWeatherOverlays();
      if (wafsStatus) wafsStatus.textContent = "WAFS açık ama ürün seçili değil.";
      return;
    }

    const fl = Math.max(50, Math.min(600, Number(wafsFL?.value || 360)));
    if (wafsStatus) wafsStatus.textContent = active.length + " WAFS katmanı yükleniyor…";
    for (const product of active) {
      const meta = document.querySelector(`[data-wafs-meta="${product}"]`);
      if (meta) meta.textContent = "yükleniyor…";
    }

    const results = await Promise.all(active.map(async product => {
      try {
        return { product, frame: await fetchWafsFrame(product, fl, weatherController.signal), error: null };
      } catch (error) {
        return { product, frame: null, error };
      }
    }));

    if (generation !== weatherGeneration) {
      for (const result of results) if (result.frame?.url) URL.revokeObjectURL(result.frame.url);
      return;
    }

    let success = 0;
    for (const result of results) {
      const product = result.product;
      const meta = document.querySelector(`[data-wafs-meta="${product}"]`);
      clearWeatherProduct(product);

      if (result.error || !result.frame) {
        if (meta) meta.textContent = result.error?.message || "frame yok";
        continue;
      }

      const frame = result.frame;
      const sourceIdForProduct = wafsSourceId(product);
      const layerId = wafsLayerId(product);

      map.addSource(sourceIdForProduct, {
        type: "image",
        url: frame.url,
        coordinates: [
          [-180, frame.maxLat],
          [180, frame.maxLat],
          [180, -frame.maxLat],
          [-180, -frame.maxLat]
        ]
      });

      const layer = {
        id: layerId,
        type: "raster",
        source: sourceIdForProduct,
        paint: {
          "raster-opacity": wafsOpacity(product),
          "raster-fade-duration": 0
        }
      };
      const before = map.getLayer("nav-airspace-fill") ? "nav-airspace-fill" : undefined;
      if (before) map.addLayer(layer, before); else map.addLayer(layer);

      weatherOverlays.set(product, { url: frame.url });
      success++;

      const level = frame.layerFL && frame.layerFL !== "NA" ? `FL${frame.layerFL}` : "whole";
      const valid = frame.validUtc ? frame.validUtc.slice(0,16).replace("T"," ") + "Z" : formatSelectedUtc();
      if (meta) meta.textContent = `${valid} · ${level} · F${frame.forecastHour || "?"}`;
    }

    if (wafsStatus) {
      wafsStatus.textContent = success
        ? `${success}/${active.length} WAFS katmanı · ${formatSelectedUtc()}`
        : "Seçilen UTC için public AWC WAFS frame bulunamadı.";
    }
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
      north: String(bounds.north),
      at: selectedTimeIso()
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

  initializeTime();

  map.on("load", () => {
    addNavLayers();
    boot.classList.add("hidden");
    scheduleViewportLoad(0);
    loadWeatherOverlays().catch(console.error);
  });

  map.on("moveend", () => scheduleViewportLoad());
  map.on("zoomend", () => updateZoomHint(false));

  layerInputs.forEach(input => {
    input.addEventListener("change", () => {
      setLayerVisibility(input.dataset.navLayer);
      scheduleViewportLoad(0);
    });
  });

  timeSlider?.addEventListener("input", () => {
    if (!timelineAnchor) return;
    const hours = Number(timeSlider.value || 0);
    const date = new Date(timelineAnchor.getTime() + hours * 3600000);
    setSelectedTime(date, false, false);
  });

  timeSlider?.addEventListener("change", () => {
    if (!timelineAnchor) return;
    const hours = Number(timeSlider.value || 0);
    const date = new Date(timelineAnchor.getTime() + hours * 3600000);
    setSelectedTime(date, true, false);
  });

  timeInput?.addEventListener("change", () => {
    const date = selectedTimeDate();
    setSelectedTime(date, true, true);
  });

  timeNowButton?.addEventListener("click", () => {
    const now = new Date();
    now.setUTCSeconds(0, 0);
    timelineAnchor = now;
    if (timeSlider) timeSlider.value = "0";
    setSelectedTime(now, true, false);
  });

  modeButtons.forEach(button => {
    button.addEventListener("click", () => {
      const target = button.dataset.panelTarget;
      const panel = document.getElementById(target);
      const wasOpen = panel?.classList.contains("open");
      toolPanels.forEach(p => p.classList.remove("open"));
      modeButtons.forEach(b => b.classList.remove("active"));
      if (!wasOpen && panel) {
        panel.classList.add("open");
        button.classList.add("active");
      }
    });
  });

  panelCloseButtons.forEach(button => {
    button.addEventListener("click", () => {
      button.closest(".tool-panel")?.classList.remove("open");
      modeButtons.forEach(b => b.classList.remove("active"));
    });
  });

  wafsEnabled?.addEventListener("change", () => loadWeatherOverlays().catch(console.error));
  wafsFL?.addEventListener("change", () => loadWeatherOverlays().catch(console.error));
  wafsProductInputs.forEach(input => {
    input.addEventListener("change", () => loadWeatherOverlays().catch(console.error));
  });
  wafsOpacityInputs.forEach(input => {
    input.addEventListener("input", () => {
      const product = input.dataset.wafsOpacity;
      const layerId = wafsLayerId(product);
      if (map.getLayer(layerId)) map.setPaintProperty(layerId, "raster-opacity", wafsOpacity(product));
    });
  });

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
