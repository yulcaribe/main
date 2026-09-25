(() => {
  "use strict";

  if (!window.maplibregl) {
    document.getElementById("boot-detail").textContent = "MapLibre yüklenemedi.";
    return;
  }

  const NAVDATA_API = "/main/api/v1/navdata.php";
  const NOTAM_API = "/main/api/v1/notam.php";
  const WAFS_API = "/main/api/v1/wafs.php";
  const FLIGHTS_API = "/main/api/v1/flights.php";
  const FLIGHT_SOURCE_ID = "live-flights";
  const CHART_SOURCE_ID = "navdata-charts";
  const NOTAM_SOURCE_ID = "navdata-notams";
  const CHART_LAYER_NAMES = new Set(["airport", "navaid", "waypoint", "airway", "sid", "star", "airspace"]);
  // Q-line radius is metadata for filtering/candidate selection, not display
  // geometry. Only explicit E-text/FAA geometry reaches polygon rendering.
  const NOTAM_APPROX_SOURCES = ["qline-coordinate", "airport-location"];
  const NOTAM_COLOR = [
    "match", ["get", "display_group"],
    "AERIAL_SPORT", "#00c5b9",
    "RESTRICTED_AIRSPACE", "#ff5f6d",
    "AERIAL_SURVEY", "#ff9b4a",
    "TRAINING_MILITARY", "#d9e1e5",
    "OTHER", "#4f8cff",
    "#ffd35f"
  ];
  const WAFS_PRODUCTS = {
    edr: { label: "Turbulence / EDR", levels: [140,180,240,270,300,340,390,450] },
    icing: { label: "Icing severity", levels: [60,100,140,180,240,300] },
    cbextent: { label: "CB horizontal extent", levels: null },
    cbtop: { label: "CB tops", levels: null },
    wind: { label: "Wind speed", levels: [100,140,180,240,270,300,340,390,450] }
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
  const timelineScale = document.getElementById("timeline-scale");
  const timeNowButton = document.getElementById("time-now");
  const timeStepButtons = [...document.querySelectorAll("[data-time-step]")];
  const notamTimeStatus = document.getElementById("notam-time-status");
  const wafsEnabled = document.getElementById("wafs-enabled");
  const wafsFL = document.getElementById("wafs-fl");
  const wafsStatus = document.getElementById("wafs-status");
  const wafsProductInputs = [...document.querySelectorAll("[data-wafs-product]")];
  const wafsOpacityInputs = [...document.querySelectorAll("[data-wafs-opacity]")];
  const flightsEnabledInput = document.getElementById("flights-enabled");
  const flightsStatus = document.getElementById("flights-status");

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

  let chartRequestController = null;
  let notamRequestController = null;
  let searchController = null;
  let chartLoadTimer = null;
  let notamLoadTimer = null;
  let searchTimer = null;
  let weatherController = null;
  let weatherGeneration = 0;
  let timelineAnchor = null;
  const PANEL_TIMELINE_RANGES = {
    "chart-panel": 24,
    "notam-panel": 72,
    "wafs-panel": 72,
    "flights-panel": 24
  };
  let currentTimelineRange = PANEL_TIMELINE_RANGES["chart-panel"];
  const chartViewportCache = new Map();
  const notamViewportCache = new Map();
  let chartCountsState = {};
  let notamCountsState = {};
  let chartTruncated = false;
  let notamTruncated = false;
  let flightLoadTimer = null;
  let flightController = null;
  let flightFeatures = [];
  let flightCountsState = { flight: 0 };
  const weatherOverlays = new Map();

  const emptyGeojson = () => ({ type: "FeatureCollection", features: [] });

  const initialMode = new URLSearchParams(location.search).get("mode") || "charts";
  if (initialMode === "flights") {
    layerInputs.forEach(input => { input.checked = false; });
    if (flightsEnabledInput) flightsEnabledInput.checked = true;
    if (wafsEnabled) wafsEnabled.checked = false;
  } else if (initialMode === "notam") {
    layerInputs.forEach(input => { input.checked = input.dataset.navLayer === "notam"; });
    if (flightsEnabledInput) flightsEnabledInput.checked = false;
    if (wafsEnabled) wafsEnabled.checked = false;
  } else if (initialMode === "wafs") {
    layerInputs.forEach(input => { input.checked = false; });
    if (flightsEnabledInput) flightsEnabledInput.checked = false;
    if (wafsEnabled) wafsEnabled.checked = true;
  } else if (flightsEnabledInput) {
    flightsEnabledInput.checked = false;
  }

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

  function selectedChartLayers() {
    return selectedLayers().filter(name => CHART_LAYER_NAMES.has(name));
  }

  function notamEnabled() {
    return selectedLayers().includes("notam");
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
      `nav-${name}-label`,
      `nav-${name}-approx-line`
    ];
    for (const id of ids) {
      if (map.getLayer(id)) map.setLayoutProperty(id, "visibility", visibilityFor(name));
    }
  }

  function addNavLayers() {
    if (map.getSource(CHART_SOURCE_ID) || map.getSource(NOTAM_SOURCE_ID)) return;

    map.addSource(CHART_SOURCE_ID, {
      type: "geojson",
      data: emptyGeojson()
    });

    map.addSource(NOTAM_SOURCE_ID, {
      type: "geojson",
      data: emptyGeojson()
    });

    map.addLayer({
      id: "nav-airspace-fill",
      type: "fill",
      source: CHART_SOURCE_ID,
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
      source: CHART_SOURCE_ID,
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
        source: CHART_SOURCE_ID,
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
        source: CHART_SOURCE_ID,
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
        source: CHART_SOURCE_ID,
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
        source: CHART_SOURCE_ID,
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
        source: CHART_SOURCE_ID,
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
        source: CHART_SOURCE_ID,
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
      source: NOTAM_SOURCE_ID,
      filter: ["all", ["==", ["get", "layer"], "notam"], ["==", ["geometry-type"], "Polygon"]],
      paint: {
        "fill-color": NOTAM_COLOR,
        "fill-opacity": [
          "interpolate", ["linear"], ["zoom"],
          5, ["case", ["in", ["get", "geometry_source"], ["literal", NOTAM_APPROX_SOURCES]], 0.01, 0.025],
          8, ["case", ["in", ["get", "geometry_source"], ["literal", NOTAM_APPROX_SOURCES]], 0.025, 0.065],
          11, ["case", ["in", ["get", "geometry_source"], ["literal", NOTAM_APPROX_SOURCES]], 0.045, 0.14]
        ]
      },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-line",
      type: "line",
      source: NOTAM_SOURCE_ID,
      filter: [
        "all",
        ["==", ["get", "layer"], "notam"],
        ["!", ["in", ["get", "geometry_source"], ["literal", NOTAM_APPROX_SOURCES]]]
      ],
      paint: {
        "line-color": NOTAM_COLOR,
        "line-width": ["interpolate", ["linear"], ["zoom"], 5, 1.0, 8, 1.5, 11, 2.4],
        "line-opacity": ["interpolate", ["linear"], ["zoom"], 5, 0.62, 9, 0.9]
      },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-approx-line",
      type: "line",
      source: NOTAM_SOURCE_ID,
      filter: [
        "all",
        ["==", ["get", "layer"], "notam"],
        ["in", ["get", "geometry_source"], ["literal", NOTAM_APPROX_SOURCES]]
      ],
      paint: {
        "line-color": NOTAM_COLOR,
        "line-width": ["interpolate", ["linear"], ["zoom"], 5, 0.9, 8, 1.3, 11, 2.0],
        "line-opacity": 0.7,
        "line-dasharray": [2.0, 1.8]
      },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-circle",
      type: "circle",
      source: NOTAM_SOURCE_ID,
      filter: ["all", ["==", ["get", "layer"], "notam"], ["==", ["geometry-type"], "Point"]],
      paint: {
        "circle-radius": ["interpolate", ["linear"], ["zoom"], 5, 3.5, 8, 5, 11, 7],
        "circle-color": NOTAM_COLOR,
        "circle-opacity": 0.9,
        "circle-stroke-color": "#06111a",
        "circle-stroke-width": 1.35
      },
      layout: { visibility: visibilityFor("notam") }
    });

    map.addLayer({
      id: "nav-notam-label",
      type: "symbol",
      source: NOTAM_SOURCE_ID,
      filter: ["==", ["get", "layer"], "notam"],
      minzoom: 8,
      layout: {
        visibility: visibilityFor("notam"),
        "text-field": [
          "step", ["zoom"],
          ["coalesce", ["get", "semantic_class"], ["get", "category"], "NOTAM"],
          10, ["coalesce", ["get", "ident"], "NOTAM"]
        ],
        "text-size": ["interpolate", ["linear"], ["zoom"], 8, 8.5, 10, 10, 13, 11.5],
        "text-font": ["Noto Sans Regular"],
        "text-offset": [0.75, 0.75],
        "text-optional": true
      },
      paint: {
        "text-color": NOTAM_COLOR,
        "text-halo-color": "#06111a",
        "text-halo-width": 1.6
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
      "nav-notam-approx-line",
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

  async function loadNotamDetail(nmsId, popup) {
    const target = popup?.getElement()?.querySelector("[data-notam-detail]");
    if (!target || !nmsId) return;

    try {
      const q = new URLSearchParams({ action: "detail", id: String(nmsId) });
      const response = await fetch(`${NOTAM_API}?${q}`, { cache: "no-store" });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok || !payload?.notam) {
        throw new Error(payload?.error || `HTTP ${response.status}`);
      }

      const current = popup?.getElement()?.querySelector("[data-notam-detail]");
      if (!current) return;
      current.textContent = payload.notam.text || "NOTAM metni bulunamadı.";
    } catch (error) {
      const current = popup?.getElement()?.querySelector("[data-notam-detail]");
      if (current) current.textContent = "NOTAM metni yüklenemedi.";
      console.error("[NavMap NOTAM detail]", error);
    }
  }

  function showPopup(feature, lngLat) {
    const p = feature.properties || {};
    const title = p.ident || p.name || p.layer || "Navdata";
    const subtitle = [p.layer, p.name && p.name !== title ? p.name : null].filter(Boolean).join(" · ");

    let rows = "";
    let detailText = "";
    let detailNotamId = "";
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
      rows += infoRow("Semantic", p.semantic_class || p.category);
      rows += infoRow("Display group", p.display_group);
      rows += infoRow("Map render", p.map_render_type);
      rows += infoRow("Explicit radius", p.explicit_radius_nm ? `${p.explicit_radius_nm} NM` : null);
      rows += infoRow("Q-line radius", p.qline_radius_nm ? `${p.qline_radius_nm} NM (metadata)` : null);
      const sourceKey = String(p.geometry_source || "");
      const mapSource =
        sourceKey === "airport-location"
          ? "Airport entity location"
          : sourceKey === "e-text-point"
            ? "NOTAM E-text point"
            : sourceKey === "qline-coordinate"
              ? "Q-line coordinate fallback"
              : sourceKey === "e-text-polygon"
                ? "NOTAM E-text boundary"
                : sourceKey === "e-text-circle"
                  ? "NOTAM E-text circle"
                  : sourceKey === "e-text-corridor"
                    ? "NOTAM E-text corridor"
                    : "FAA geometry";
      rows += infoRow("Map source", mapSource);
      rows += infoRow("Geometry", p.geometry_accuracy);
      detailNotamId = String(p.nms_id || "");
    }

    const html = `
      <div class="popup">
        <h3>${esc(title)}</h3>
        <div class="sub">${esc(subtitle)}</div>
        <div class="popup-grid">${rows || "<div><span>Layer</span><strong>" + esc(p.layer) + "</strong></div>"}</div>
        ${detailNotamId ? '<div class="notam-text" data-notam-detail>NOTAM metni yükleniyor…</div>' : (detailText ? '<div class="notam-text">' + esc(detailText) + '</div>' : '')}
      </div>`;

    const popup = new maplibregl.Popup({ closeButton: true, maxWidth: "360px" })
      .setLngLat(lngLat)
      .setHTML(html)
      .addTo(map);

    if (detailNotamId) loadNotamDetail(detailNotamId, popup);
  }

  function updateCounts() {
    const counts = { ...chartCountsState, ...notamCountsState };
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
      notamTimeStatus.textContent = notamEnabled()
        ? new Intl.NumberFormat("tr-TR").format(n) + " NOTAM · " + formatSelectedUtc()
        : "FAA geometry ve E-text içinde açıkça tanımlanan alan/circle/corridor geometrileri gösterilir.";
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

  function bboxParams(paddingFactor = 0) {
    const b = map.getBounds();
    let west = b.getWest();
    let south = b.getSouth();
    let east = b.getEast();
    let north = b.getNorth();

    if (paddingFactor > 0 && west <= east) {
      const lonPad = Math.max(0.02, (east - west) * paddingFactor);
      const latPad = Math.max(0.02, (north - south) * paddingFactor);
      west = Math.max(-180, west - lonPad);
      east = Math.min(180, east + lonPad);
      south = Math.max(-85, south - latPad);
      north = Math.min(85, north + latPad);
    }

    return { west, south, east, north };
  }

  function bboxContains(outer, inner) {
    if (!outer || !inner) return false;
    if (outer.west > outer.east || inner.west > inner.east) return false;
    return inner.west >= outer.west
      && inner.east <= outer.east
      && inner.south >= outer.south
      && inner.north <= outer.north;
  }

  function viewportCacheGet(cache, key, exactBounds, maxAgeMs = Infinity) {
    const entry = cache.get(key);
    if (!entry) return null;
    if (Date.now() - entry.fetchedAt > maxAgeMs || !bboxContains(entry.bounds, exactBounds)) {
      return null;
    }
    cache.delete(key);
    cache.set(key, entry);
    return entry;
  }

  function viewportCachePut(cache, key, entry, maxEntries = 8) {
    cache.delete(key);
    cache.set(key, { ...entry, fetchedAt: Date.now() });
    while (cache.size > maxEntries) {
      const oldest = cache.keys().next().value;
      cache.delete(oldest);
    }
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

  function formatSelectedUtc(date = selectedTimeDate()) {
    return date.toISOString().slice(0, 16).replace("T", " ") + "Z";
  }

  function clampTimelineHours(hours) {
    return Math.max(-currentTimelineRange, Math.min(currentTimelineRange, hours));
  }

  function renderTimelineScale(range = currentTimelineRange) {
    if (!timelineScale) return;
    const labels = range <= 24
      ? [-24, -12, -6, 0, 6, 12, 24]
      : range <= 72
        ? [-72, -48, -24, 0, 24, 48, 72]
        : [-168, -120, -72, 0, 72, 120, 168];

    timelineScale.innerHTML = labels
      .map(value => {
        if (value === 0) return "<span>NOW</span>";
        const abs = Math.abs(value);
        const amount = abs >= 48 && abs % 24 === 0 ? `${abs / 24}d` : `${abs}h`;
        return `<span>${value > 0 ? "+" : "-"}${amount}</span>`;
      })
      .join("");
  }

  function clampDateToTimelineRange(date) {
    if (!timelineAnchor) return date;
    const hours = Math.round((date.getTime() - timelineAnchor.getTime()) / 3600000);
    const clamped = clampTimelineHours(hours);
    if (clamped === hours) return date;
    return new Date(timelineAnchor.getTime() + clamped * 3600000);
  }

  function updateTimelineOffset(date) {
    if (!timelineAnchor || !timelineOffset) return;
    const hours = Math.round((date.getTime() - timelineAnchor.getTime()) / 3600000);
    timelineOffset.textContent = hours > 0 ? `+${hours}h` : `${hours}h`;
  }

  function setTimelineRange(rangeHours, reload = false) {
    currentTimelineRange = Number(rangeHours) >= 168 ? 168 : Number(rangeHours) >= 72 ? 72 : 24;
    renderTimelineScale(currentTimelineRange);

    if (timeSlider) {
      timeSlider.min = String(-currentTimelineRange);
      timeSlider.max = String(currentTimelineRange);
    }

    const current = clampDateToTimelineRange(selectedTimeDate());
    setSelectedTime(current, reload, true);
  }

  function shiftSelectedTime(hoursDelta) {
    if (!timelineAnchor) return;
    const currentHours = Math.round((selectedTimeDate().getTime() - timelineAnchor.getTime()) / 3600000);
    const nextHours = clampTimelineHours(currentHours + hoursDelta);
    const nextDate = new Date(timelineAnchor.getTime() + nextHours * 3600000);
    setSelectedTime(nextDate, true, true);
  }

  function setSelectedTime(date, reload = true, syncSlider = true) {
    if (!timeInput) return;
    const normalized = clampDateToTimelineRange(date);
    timeInput.value = inputValueFromDate(normalized);
    if (timeLabel) timeLabel.textContent = formatSelectedUtc(normalized);
    updateTimelineOffset(normalized);

    if (syncSlider && timeSlider && timelineAnchor) {
      const hours = Math.round((normalized.getTime() - timelineAnchor.getTime()) / 3600000);
      timeSlider.value = String(clampTimelineHours(hours));
    }

    if (reload && map.loaded()) {
      scheduleNotamLoad(0, false);
      loadWeatherOverlays().catch(console.error);
    }
  }

  function initializeTime() {
    const now = new Date();
    now.setUTCSeconds(0, 0);
    timelineAnchor = now;
    if (timeSlider) timeSlider.value = "0";
    setSelectedTime(now, false, false);
    setTimelineRange(PANEL_TIMELINE_RANGES["chart-panel"], false);
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
    const cfg = WAFS_PRODUCTS[product];
    if (Array.isArray(cfg?.levels) && !cfg.levels.includes(fl)) {
      throw new Error(
        `FL${fl} desteklenmiyor · ${cfg.levels.map(v => "FL" + v).join(", ")}`
      );
    }

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

    const rawFl = String(wafsFL?.value ?? "").trim();
    const fl = Number(rawFl);
    if (!/^\d{1,3}$/.test(rawFl) || !Number.isInteger(fl) || fl < 50 || fl > 600) {
      clearWeatherOverlays();
      if (wafsStatus) wafsStatus.textContent = "Hata: Flight level FL050 ile FL600 arasında tam sayı olmalı.";
      wafsProductInputs.forEach(input => {
        const meta = document.querySelector(`[data-wafs-meta="${input.dataset.wafsProduct}"]`);
        if (meta) meta.textContent = "geçersiz FL";
      });
      return;
    }

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

      try {
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
      } catch (error) {
        console.error(`[WAFS ${product}]`, error);
        URL.revokeObjectURL(frame.url);
        if (meta) meta.textContent = "haritaya eklenemedi";
      }
    }

    if (wafsStatus) {
      wafsStatus.textContent = success
        ? `${success}/${active.length} WAFS katmanı · ${formatSelectedUtc()}`
        : "Seçilen UTC için public AWC WAFS frame bulunamadı.";
    }
  }

  function viewportQuery(bounds, z, layers, includeTime = false) {
    const q = new URLSearchParams({
      action: "viewport",
      z: String(z),
      layers: layers.join(","),
      west: String(bounds.west),
      south: String(bounds.south),
      east: String(bounds.east),
      north: String(bounds.north)
    });
    if (includeTime) q.set("at", selectedTimeIso());
    return q;
  }

  function refreshViewportStatus() {
    updateCounts();
    updateZoomHint(chartTruncated || notamTruncated);
  }

  async function loadChartViewport(force = false) {
    const z = Math.floor(map.getZoom());
    const layers = selectedChartLayers();
    const exactBounds = bboxParams();

    if (z < 5 || !layers.length) {
      map.getSource(CHART_SOURCE_ID)?.setData(emptyGeojson());
      chartCountsState = {};
      chartTruncated = false;
      refreshViewportStatus();
      return;
    }

    const layerKey = layers.slice().sort().join(",");
    const cacheKey = `${z}|${layerKey}`;
    const cached = force ? null : viewportCacheGet(chartViewportCache, cacheKey, exactBounds);
    if (cached) {
      map.getSource(CHART_SOURCE_ID)?.setData(cached.data);
      chartCountsState = cached.counts;
      chartTruncated = cached.truncated;
      refreshViewportStatus();
      statusText.textContent = "CHARTS · local cache";
      statusDot.classList.add("ok");
      statusDot.classList.remove("bad");
      return;
    }

    chartRequestController?.abort();
    chartRequestController = new AbortController();

    const requestBounds = bboxParams(0.32);
    const q = viewportQuery(requestBounds, z, layers, false);

    try {
      const response = await fetch(`${NAVDATA_API}?${q}`, {
        cache: "default",
        signal: chartRequestController.signal
      });
      const payload = await response.json().catch(() => null);

      if (!response.ok || !payload?.ok || !payload?.data) {
        throw new Error(payload?.error || `HTTP ${response.status}`);
      }

      map.getSource(CHART_SOURCE_ID)?.setData(payload.data);
      chartCountsState = Object.fromEntries(
        [...CHART_LAYER_NAMES].map(name => [name, Number(payload.counts?.[name] || 0)])
      );
      chartTruncated = Boolean(payload.truncated);
      viewportCachePut(chartViewportCache, cacheKey, {
        bounds: requestBounds,
        data: payload.data,
        counts: chartCountsState,
        truncated: chartTruncated
      });
      refreshViewportStatus();

      statusText.textContent = chartTruncated
        ? "CHARTS · yoğun görünüm"
        : "MariaDB · görünüm hazır";
      statusDot.classList.add("ok");
      statusDot.classList.remove("bad");
    } catch (error) {
      if (error.name === "AbortError") return;
      console.error("[NavMap charts]", error);
      statusText.textContent = "CHARTS API hatası";
      statusDot.classList.add("bad");
    }
  }

  async function loadNotamViewport(force = false) {
    const z = Math.floor(map.getZoom());
    const exactBounds = bboxParams();

    if (z < 5 || !notamEnabled()) {
      map.getSource(NOTAM_SOURCE_ID)?.setData(emptyGeojson());
      notamCountsState = {};
      notamTruncated = false;
      refreshViewportStatus();
      return;
    }

    const timeKey = selectedTimeIso().slice(0, 16);
    const cacheKey = `${z}|${timeKey}`;
    const cached = force ? null : viewportCacheGet(notamViewportCache, cacheKey, exactBounds, 120000);
    if (cached) {
      map.getSource(NOTAM_SOURCE_ID)?.setData(cached.data);
      notamCountsState = cached.counts;
      notamTruncated = cached.truncated;
      refreshViewportStatus();
      statusText.textContent = "NOTAM · local cache";
      statusDot.classList.add("ok");
      statusDot.classList.remove("bad");
      return;
    }

    notamRequestController?.abort();
    notamRequestController = new AbortController();

    const requestBounds = bboxParams(0.38);
    const q = viewportQuery(requestBounds, z, ["notam"], true);
    q.set("action", "map");
    q.delete("layers");

    try {
      const response = await fetch(`${NOTAM_API}?${q}`, {
        cache: "default",
        signal: notamRequestController.signal
      });
      const payload = await response.json().catch(() => null);

      if (!response.ok || !payload?.ok || !payload?.data) {
        throw new Error(payload?.error || `HTTP ${response.status}`);
      }

      map.getSource(NOTAM_SOURCE_ID)?.setData(payload.data);
      notamCountsState = { notam: Number(payload.counts?.notam || 0) };
      notamTruncated = Boolean(payload.truncated);
      viewportCachePut(notamViewportCache, cacheKey, {
        bounds: requestBounds,
        data: payload.data,
        counts: notamCountsState,
        truncated: notamTruncated
      });
      refreshViewportStatus();

      const cacheState = response.headers.get("x-yc-navmap-cache");
      const timingState = response.headers.get("x-yc-navmap-timing");
      const timingShort = timingState
        ? timingState
            .split(",")
            .filter(part => /^(faa_sql|airport_sql|coord_sql|total)=/.test(part))
            .join(" · ")
        : "";
      const baseStatus = notamTruncated
        ? "NOTAM · yoğun görünüm"
        : cacheState === "HIT"
          ? "NOTAM · cache"
          : "NOTAM · canlı görünüm";
      statusText.textContent = timingShort ? `${baseStatus} · ${timingShort}` : baseStatus;
      statusDot.classList.add("ok");
      statusDot.classList.remove("bad");
    } catch (error) {
      if (error.name === "AbortError") return;
      console.error("[NavMap NOTAM]", error);
      statusText.textContent = "NOTAM API hatası";
      statusDot.classList.add("bad");
    }
  }

  function scheduleChartLoad(delay = 180, force = false) {
    clearTimeout(chartLoadTimer);
    chartLoadTimer = setTimeout(() => loadChartViewport(force), delay);
  }

  function scheduleNotamLoad(delay = 180, force = false) {
    clearTimeout(notamLoadTimer);
    notamLoadTimer = setTimeout(() => loadNotamViewport(force), delay);
  }

  function scheduleViewportLoad(delay = 180, force = false) {
    scheduleChartLoad(delay, force);
    scheduleNotamLoad(delay, force);
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
      const response = await fetch(`${NAVDATA_API}?action=search&q=${encodeURIComponent(q.trim())}`, {
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
    scheduleViewportLoad(0, true);
    loadWeatherOverlays().catch(console.error);
  });

  map.on("moveend", () => scheduleViewportLoad());
  map.on("zoomend", () => updateZoomHint(chartTruncated || notamTruncated));

  layerInputs.forEach(input => {
    input.addEventListener("change", () => {
      const layer = input.dataset.navLayer;
      setLayerVisibility(layer);

      if (layer === "notam") {
        scheduleNotamLoad(0, false);
      } else {
        scheduleChartLoad(0, false);
      }
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

  timeStepButtons.forEach(button => {
    button.addEventListener("click", () => {
      const hours = Number(button.dataset.timeStep || 0);
      if (!Number.isFinite(hours) || hours === 0) return;
      shiftSelectedTime(hours);
    });
  });

  timeNowButton?.addEventListener("click", () => {
    const now = new Date();
    now.setUTCSeconds(0, 0);
    timelineAnchor = now;
    if (timeSlider) timeSlider.value = "0";
    setSelectedTime(now, true, false);
  });

  function activatePanel(target, allowToggle = true) {
    const panel = document.getElementById(target);
    const button = modeButtons.find(b => b.dataset.panelTarget === target);
    const wasOpen = panel?.classList.contains("open");

    toolPanels.forEach(p => p.classList.remove("open"));
    modeButtons.forEach(b => b.classList.remove("active"));

    if (allowToggle && wasOpen) return;

    if (panel) panel.classList.add("open");
    if (button) button.classList.add("active");

    setTimelineRange(PANEL_TIMELINE_RANGES[target] || 24, false);
  }

  modeButtons.forEach(button => {
    button.addEventListener("click", () => {
      activatePanel(button.dataset.panelTarget, true);
    });
  });

  panelCloseButtons.forEach(button => {
    button.addEventListener("click", () => {
      button.closest(".tool-panel")?.classList.remove("open");
      modeButtons.forEach(b => b.classList.remove("active"));
    });
  });

  setInterval(() => {
    if (!map.loaded() || !notamEnabled()) return;
    scheduleNotamLoad(0, true);
  }, 300000);

  wafsEnabled?.addEventListener("change", () => loadWeatherOverlays().catch(console.error));
  wafsFL?.addEventListener("input", () => {
    const raw = String(wafsFL.value || "").trim();
    const fl = Number(raw);
    const active = activeWafsProducts();
    const invalid = active
      .map(product => ({ product, cfg: WAFS_PRODUCTS[product] }))
      .filter(({ cfg }) => Array.isArray(cfg?.levels) && Number.isInteger(fl) && !cfg.levels.includes(fl));

    if (!/^\d{1,3}$/.test(raw) || !Number.isInteger(fl) || fl < 50 || fl > 600) {
      wafsFL.setCustomValidity("Flight level FL050 ile FL600 arasında tam sayı olmalı.");
    } else if (invalid.length) {
      const first = invalid[0];
      wafsFL.setCustomValidity(
        `${first.cfg.label}: FL${fl} desteklenmiyor. ${first.cfg.levels.map(v => "FL" + v).join(", ")}`
      );
    } else {
      wafsFL.setCustomValidity("");
    }
  });
  wafsFL?.addEventListener("change", () => {
    wafsFL.reportValidity();
    loadWeatherOverlays().catch(console.error);
  });
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
