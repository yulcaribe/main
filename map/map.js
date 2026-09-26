(() => {
  "use strict";

  if (!window.maplibregl) {
    document.getElementById("boot-detail").textContent = "MapLibre yüklenemedi.";
    return;
  }

  const NAVDATA_API = "/main/api/v1/navdata.php";
  const NOTAM_API = "/main/api/v1/notam.php";
  const WAFS_API = "/main/api/v1/wafs.php";
  const ADSB_API = "/main/api/v1/adsb.php";
  const ADSB_REFRESH_MS = 2000;
  const ADSB_MIN_FETCH_ZOOM = 4.2;
  const ADSB_RENDER_DELAY_MS = 4200;
  const ADSB_SAMPLE_KEEP_MS = 20000;
  const ADSB_ANIMATION_FRAME_MS = 32;
  const ADSB_FETCH_BOX_PADDING = 0.35;
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
  const searchShell = document.getElementById("search-shell");
  const chartsToggleAll = document.getElementById("charts-toggle-all");
  const timelineDock = document.getElementById("timeline-dock");
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
  const aircraftInfoPanel = document.getElementById("aircraft-info");
  const aircraftInfoTitle = document.getElementById("aircraft-info-title");
  const aircraftInfoSubtitle = document.getElementById("aircraft-info-subtitle");
  const aircraftInfoBody = document.getElementById("aircraft-info-body");
  const aircraftInfoClose = document.getElementById("aircraft-info-close");

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
  let mapReady = false;
  let flightFeatures = [];
  let flightCountsState = { flight: 0 };
  let adsbDecoder = null;
  let adsbDecoderReady = false;
  let adsbRequestSeq = 0;
  let adsbSourceClockOffsetMs = 0;
  let adsbHaveSourceClock = false;
  let adsbLastAnimationFrame = 0;
  let adsbAnimationStarted = false;
  let adsbFetchBox = null;
  let adsbLastMoveZoom = null;
  let adsbLastMoveCenter = null;
  let selectedAircraftHex = null;
  let aircraftInfoLastRenderAt = 0;
  const aircraftMarkers = new Map();
  const weatherOverlays = new Map();

  const emptyGeojson = () => ({ type: "FeatureCollection", features: [] });

  const initialMode = new URLSearchParams(location.search).get("mode") || "charts";
  let activePanelTarget = initialMode === "flights"
    ? "flights-panel"
    : initialMode === "notam"
      ? "notam-panel"
      : initialMode === "wafs"
        ? "wafs-panel"
        : "chart-panel";

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
    return activePanelTarget === "notam-panel" && selectedLayers().includes("notam");
  }

  function visibilityFor(name) {
    if (name === "notam") {
      return activePanelTarget === "notam-panel" && selectedLayers().includes(name) ? "visible" : "none";
    }
    if (CHART_LAYER_NAMES.has(name)) {
      return activePanelTarget === "chart-panel" && selectedLayers().includes(name) ? "visible" : "none";
    }
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
      flight: -1,
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
    if (p.layer === "flight") {
      rows += infoRow("Registration", p.registration);
      rows += infoRow("Type", p.aircraft_type);
      rows += infoRow("Altitude", p.altitude === "ground" ? "GROUND" : (p.altitude ? p.altitude + " ft" : null));
      rows += infoRow("Groundspeed", p.groundspeed ? p.groundspeed + " kt" : null);
      rows += infoRow("Track", p.track != null ? p.track + "°" : null);
      rows += infoRow("Squawk", p.squawk);
      rows += infoRow("ICAO hex", p.hex);
    } else if (p.layer === "airport" || p.layer === "navaid" || p.layer === "waypoint") {
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
    const counts = { ...chartCountsState, ...notamCountsState, ...flightCountsState };

    for (const key of countKeys) {
      const count = Number(counts[key] || 0);
      document.querySelectorAll(`[data-layer-count="${key}"]`).forEach(el => {
        el.textContent = new Intl.NumberFormat("tr-TR").format(count);
      });
    }

    let visibleCount = 0;
    let suffix = " obje";

    if (activePanelTarget === "flights-panel") {
      visibleCount = Number(flightCountsState.flight || 0);
      suffix = " aircraft";
    } else if (activePanelTarget === "notam-panel") {
      visibleCount = Number(notamCountsState.notam || 0);
      suffix = " NOTAM";
    } else if (activePanelTarget === "wafs-panel") {
      visibleCount = activeWafsProducts().length;
      suffix = " layer";
    } else {
      for (const name of CHART_LAYER_NAMES) {
        if (selectedLayers().includes(name)) visibleCount += Number(chartCountsState[name] || 0);
      }
    }

    featureCount.textContent = new Intl.NumberFormat("tr-TR").format(visibleCount) + suffix;

    if (activePanelTarget === "flights-panel") {
      statusText.textContent = flightsEnabled()
        ? (adsbHaveSourceClock ? "LIVE" : "FLIGHTS · bağlanıyor")
        : "FLIGHTS · OFF";
    } else if (activePanelTarget === "wafs-panel") {
      statusText.textContent = "WAFS · FL" + String(wafsFL?.value || "—");
    }

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
    if (activePanelTarget !== "wafs-panel" || !wafsEnabled?.checked) return [];
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

  function flightsEnabled() {
    return activePanelTarget === "flights-panel" && Boolean(flightsEnabledInput?.checked);
  }

  function setFlightHealth(state) {
    if (!flightsStatus) return;
    const label = flightsStatus.querySelector(".flight-health-label");
    flightsStatus.classList.remove("is-loading", "is-ok", "is-error");

    if (state === "off") {
      flightsStatus.hidden = true;
      return;
    }

    flightsStatus.hidden = false;
    if (state === "ok") {
      flightsStatus.classList.add("is-ok");
      if (label) label.textContent = "Güncel";
      flightsStatus.setAttribute("aria-label", "ADS-B güncel");
      return;
    }

    if (state === "error") {
      flightsStatus.classList.add("is-error");
      if (label) label.textContent = "Hata";
      flightsStatus.setAttribute("aria-label", "ADS-B hata");
      return;
    }

    flightsStatus.classList.add("is-loading");
    if (label) label.textContent = "Yükleniyor";
    flightsStatus.setAttribute("aria-label", "ADS-B yükleniyor");
  }

  function sourceType(code) {
    switch (code) {
      case 0: return "adsb_icao";
      case 1: return "adsb_icao_nt";
      case 2: return "adsr_icao";
      case 3: return "tisb_icao";
      case 4: return "adsc";
      case 5: return "mlat";
      case 6: return "other";
      case 7: return "mode_s";
      case 8: return "adsb_other";
      case 9: return "adsr_other";
      case 10: return "tisb_trackfile";
      case 11: return "tisb_other";
      case 12: return "mode_ac";
      default: return "unknown";
    }
  }

  function readAscii(u8, start, end) {
    let out = "";
    for (let i = start; i < end && u8[i]; i++) out += String.fromCharCode(u8[i]);
    return out.trim();
  }

  function parseBinCraft(uint8) {
    const buffer = uint8.buffer.slice(uint8.byteOffset, uint8.byteOffset + uint8.byteLength);
    if (buffer.byteLength < 52) throw new Error("binCraft header too short");

    const header = new Uint32Array(buffer, 0, 13);
    const stride = header[2];
    const version = header[10];
    if (!stride || stride < 108 || stride > 256 || buffer.byteLength < stride) {
      throw new Error("Unexpected binCraft stride: " + stride);
    }

    const aircraft = [];
    for (let off = stride; off + stride <= buffer.byteLength; off += stride) {
      const s32 = new Int32Array(buffer, off, stride / 4);
      const u16 = new Uint16Array(buffer, off, stride / 2);
      const s16 = new Int16Array(buffer, off, stride / 2);
      const u8 = new Uint8Array(buffer, off, stride);

      const nonIcao = !!(s32[0] & (1 << 24));
      let hex = (s32[0] & ((1 << 24) - 1)).toString(16).padStart(6, "0");
      if (nonIcao) hex = "~" + hex;

      let seen;
      let seenPos;
      if (version >= 20240218) {
        seen = s32[1] / 10;
        seenPos = s32[27] / 10;
      } else {
        seenPos = u16[2] / 10;
        seen = u16[3] / 10;
      }

      let lon = s32[2] / 1e6;
      let lat = s32[3] / 1e6;
      let baroRate = s16[8] * 8;
      let geomRate = s16[9] * 8;
      let alt = s16[10] * 25;
      let altGeom = s16[11] * 25;
      let navAltitudeMcp = u16[12] * 4;
      let navAltitudeFms = u16[13] * 4;
      let navQnh = s16[14] / 10;
      let navHeading = s16[15] / 90;

      const squawkHex = u16[16].toString(16).padStart(4, "0");
      let squawk = squawkHex[0] > "9"
        ? String(parseInt(squawkHex[0], 16)) + squawkHex.slice(1)
        : squawkHex;

      let gs = s16[17] / 10;
      let mach = s16[18] / 1000;
      let roll = s16[19] / 100;
      let track = s16[20] / 90;
      let trackRate = s16[21] / 100;
      let magHeading = s16[22] / 90;
      let trueHeading = s16[23] / 90;
      let windDir = s16[24];
      let windSpeed = s16[25];
      let oat = s16[26];
      let tat = s16[27];
      let tas = u16[28];
      let ias = u16[29];

      const category = u8[64] ? u8[64].toString(16).toUpperCase() : "";
      const receiverCount = u8[104];
      let rssi;
      if (version >= 20250403) {
        rssi = (u8[105] * (50 / 255)) - 50;
      } else {
        const level = u8[105] * u8[105] / 65025 + 1.125e-5;
        rssi = 10 * Math.log(level) / Math.log(10);
      }

      const validity1 = u8[73];
      const validity2 = u8[74];
      const validity3 = u8[75];
      const validity4 = u8[76];
      const validity5 = u8[77];

      const flight = (validity1 & 8) ? readAscii(u8, 78, 86) : "";
      const typeCode = readAscii(u8, 88, 92);
      const registration = readAscii(u8, 92, 104);

      if (!(validity1 & 16)) alt = null;
      if (!(validity1 & 32)) altGeom = null;
      if (!(validity1 & 64)) {
        lat = null;
        lon = null;
        seenPos = null;
      }
      if (!(validity1 & 128)) gs = null;

      if (!(validity2 & 1)) ias = null;
      if (!(validity2 & 2)) tas = null;
      if (!(validity2 & 4)) mach = null;
      if (!(validity2 & 8)) track = null;
      if (!(validity2 & 16)) trackRate = null;
      if (!(validity2 & 32)) roll = null;
      if (!(validity2 & 64)) magHeading = null;
      if (!(validity2 & 128)) trueHeading = null;

      if (!(validity3 & 1)) baroRate = null;
      if (!(validity3 & 2)) geomRate = null;

      if (!(validity4 & 4)) squawk = null;
      if (!(validity4 & 32)) navQnh = null;
      if (!(validity4 & 64)) navAltitudeMcp = null;
      if (!(validity4 & 128)) navAltitudeFms = null;

      if (!(validity5 & 2)) navHeading = null;
      if (!(validity5 & 16)) {
        windSpeed = null;
        windDir = null;
      }
      if (!(validity5 & 32)) {
        oat = null;
        tat = null;
      }

      const airground = u8[68] & 15;
      if (airground === 1) alt = "ground";

      const heading = track ?? trueHeading ?? magHeading ?? 0;
      const type = sourceType((u8[67] & 240) >> 4);

      if (
        lat == null || lon == null ||
        !Number.isFinite(lat) || !Number.isFinite(lon) ||
        Math.abs(lat) > 90 || Math.abs(lon) > 180
      ) continue;

      aircraft.push({
        hex, flight, registration, typeCode, type, category,
        lat, lon, alt, altGeom,
        gs, ias, tas, mach, roll,
        track, trackRate, magHeading, trueHeading, heading,
        baroRate, geomRate,
        squawk, navQnh, navAltitudeMcp, navAltitudeFms, navHeading,
        windDir, windSpeed, oat, tat,
        receiverCount, rssi,
        seen, seenPos
      });
    }

    return {
      now: header[0] / 1000 + header[1] * 4294967.296,
      stride,
      version,
      globalCount: header[3],
      aircraft
    };
  }

  function aircraftFmt(value, digits = 0, suffix = "") {
    return Number.isFinite(value) ? Number(value).toFixed(digits) + suffix : "—";
  }

  function aircraftFmtAlt(value) {
    if (value === "ground") return "GND";
    return Number.isFinite(value) ? Math.round(value).toLocaleString("en-US") + " ft" : "—";
  }

  function aircraftInfoRow(label, value, extraClass = "") {
    return '<div class="info-row"><span>' + esc(label) + '</span><b class="' + extraClass + '">' + esc(value ?? "—") + '</b></div>';
  }

  function aircraftInfoSection(title, rows) {
    return '<section class="info-section"><h3>' + esc(title) + '</h3>' + rows.join("") + '</section>';
  }

  function renderAircraftInfo(ac, shown) {
    if (!ac || !shown || selectedAircraftHex !== ac.hex || !aircraftInfoPanel) return;

    aircraftInfoTitle.textContent = ac.flight || ac.registration || ac.hex.toUpperCase();
    aircraftInfoSubtitle.textContent = [ac.registration, ac.typeCode, ac.hex.toUpperCase()].filter(Boolean).join(" · ") || "—";

    aircraftInfoBody.innerHTML =
      aircraftInfoSection("Altitude", [
        aircraftInfoRow("Baro altitude", aircraftFmtAlt(ac.alt)),
        aircraftInfoRow("Geom altitude", aircraftFmtAlt(ac.altGeom)),
        aircraftInfoRow("Vertical rate", aircraftFmt(ac.baroRate, 0, " ft/min")),
        aircraftInfoRow("Geom V/S", aircraftFmt(ac.geomRate, 0, " ft/min"))
      ]) +
      aircraftInfoSection("Speed & heading", [
        aircraftInfoRow("Ground speed", aircraftFmt(ac.gs, 1, " kt")),
        aircraftInfoRow("IAS", aircraftFmt(ac.ias, 0, " kt")),
        aircraftInfoRow("TAS", aircraftFmt(ac.tas, 0, " kt")),
        aircraftInfoRow("Mach", aircraftFmt(ac.mach, 3)),
        aircraftInfoRow("Track", aircraftFmt(ac.track, 1, "°")),
        aircraftInfoRow("True heading", aircraftFmt(ac.trueHeading, 1, "°")),
        aircraftInfoRow("Mag heading", aircraftFmt(ac.magHeading, 1, "°")),
        aircraftInfoRow("Roll", aircraftFmt(ac.roll, 1, "°"))
      ]) +
      aircraftInfoSection("Navigation", [
        aircraftInfoRow("Squawk", ac.squawk || "—"),
        aircraftInfoRow("Selected ALT (MCP)", aircraftFmt(ac.navAltitudeMcp, 0, " ft")),
        aircraftInfoRow("Selected ALT (FMS)", aircraftFmt(ac.navAltitudeFms, 0, " ft")),
        aircraftInfoRow("Selected heading", aircraftFmt(ac.navHeading, 1, "°")),
        aircraftInfoRow("QNH", aircraftFmt(ac.navQnh, 1, " hPa"))
      ]) +
      aircraftInfoSection("Weather", [
        aircraftInfoRow("Wind", Number.isFinite(ac.windDir) && Number.isFinite(ac.windSpeed) ? Math.round(ac.windDir) + "° / " + Math.round(ac.windSpeed) + " kt" : "—"),
        aircraftInfoRow("OAT", aircraftFmt(ac.oat, 0, " °C")),
        aircraftInfoRow("TAT", aircraftFmt(ac.tat, 0, " °C"))
      ]) +
      aircraftInfoSection("Signal & source", [
        aircraftInfoRow("Source", ac.type || "—"),
        aircraftInfoRow("Category", ac.category || "—"),
        aircraftInfoRow("Receivers", Number.isFinite(ac.receiverCount) ? String(ac.receiverCount) : "—"),
        aircraftInfoRow("RSSI", aircraftFmt(ac.rssi, 1, " dBFS")),
        aircraftInfoRow("Seen", aircraftFmt(ac.seen, 1, " s")),
        aircraftInfoRow("Seen position", aircraftFmt(ac.seenPos, 1, " s")),
        aircraftInfoRow("Position", Number(shown.lat).toFixed(5) + ", " + Number(shown.lon).toFixed(5), "info-coords")
      ]);
  }

  function closeAircraftInfo() {
    if (selectedAircraftHex && aircraftMarkers.has(selectedAircraftHex)) {
      aircraftMarkers.get(selectedAircraftHex).el.classList.remove("selected");
    }
    selectedAircraftHex = null;
    aircraftInfoPanel?.classList.remove("open");
    aircraftInfoPanel?.setAttribute("aria-hidden", "true");
  }

  function selectAircraft(hex) {
    if (selectedAircraftHex && aircraftMarkers.has(selectedAircraftHex)) {
      aircraftMarkers.get(selectedAircraftHex).el.classList.remove("selected");
    }
    selectedAircraftHex = hex;
    const item = aircraftMarkers.get(hex);
    if (!item) return;
    item.el.classList.add("selected");
    aircraftInfoPanel?.classList.add("open");
    aircraftInfoPanel?.setAttribute("aria-hidden", "false");
    renderAircraftInfo(item.data, item.rendered || item.data);
  }

  function createAircraftElement() {
    const el = document.createElement("div");
    el.className = "aircraft-marker";
    el.innerHTML =
      '<svg viewBox="0 0 64 64" aria-hidden="true">' +
      '<path d="M32 3 C29.8 3 28.7 5.4 28.4 8.4 L26.8 25.2 L7 34.4 L7 39 L27.8 34.4 L28.2 49.5 L20.2 55.5 L20.2 59 L32 56 L43.8 59 L43.8 55.5 L35.8 49.5 L36.2 34.4 L57 39 L57 34.4 L37.2 25.2 L35.6 8.4 C35.3 5.4 34.2 3 32 3 Z" fill="#f4f8fb" stroke="#071019" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    return el;
  }

  function shortestAngle(a, b) {
    const delta = ((b - a + 540) % 360) - 180;
    return a + delta;
  }

  function lerp(a, b, t) {
    return a + (b - a) * t;
  }

  function interpolateAircraft(a, b, t) {
    const headingB = shortestAngle(a.heading ?? 0, b.heading ?? a.heading ?? 0);
    return {
      ...b,
      lon: lerp(a.lon, b.lon, t),
      lat: lerp(a.lat, b.lat, t),
      heading: ((lerp(a.heading ?? 0, headingB, t) % 360) + 360) % 360
    };
  }

  function ensureAircraftMarker(ac) {
    let item = aircraftMarkers.get(ac.hex);
    if (item) return item;

    const el = createAircraftElement();
    el.style.display = "none";
    const marker = new maplibregl.Marker({
      element: el,
      rotationAlignment: "map",
      pitchAlignment: "map"
    })
      .setLngLat([ac.lon, ac.lat])
      .setRotation(Number.isFinite(ac.heading) ? ac.heading : 0)
      .addTo(map);

    item = { marker, el, data: ac, rendered: ac, samples: [], lastSeenAt: Date.now() };
    el.addEventListener("click", event => {
      event.stopPropagation();
      selectAircraft(ac.hex);
    });
    aircraftMarkers.set(ac.hex, item);
    return item;
  }

  function clearAircraftMarkers() {
    for (const item of aircraftMarkers.values()) item.marker.remove();
    aircraftMarkers.clear();
    closeAircraftInfo();
    adsbHaveSourceClock = false;
  }

  function ingestAircraftSnapshot(aircraft, sourceNowMs) {
    const seenNow = new Set();
    for (const ac of aircraft) {
      seenNow.add(ac.hex);
      const item = ensureAircraftMarker(ac);
      item.data = ac;
      item.lastSeenAt = Date.now();

      const ageMs = Number.isFinite(ac.seenPos) ? Math.max(0, ac.seenPos * 1000) : 0;
      const sampleTime = sourceNowMs - ageMs;
      const last = item.samples[item.samples.length - 1];
      const sameTimestamp = last && Math.abs(last.t - sampleTime) < 50;
      const samePosition = last && Math.abs(last.lon - ac.lon) < 1e-9 && Math.abs(last.lat - ac.lat) < 1e-9;

      if (!sameTimestamp && !samePosition) {
        item.samples.push({
          t: sampleTime,
          lon: ac.lon,
          lat: ac.lat,
          heading: Number.isFinite(ac.heading) ? ac.heading : 0,
          data: ac
        });
      } else if (last) {
        last.data = ac;
        last.heading = Number.isFinite(ac.heading) ? ac.heading : last.heading;
      }

      const cutoff = sourceNowMs - ADSB_SAMPLE_KEEP_MS;
      while (item.samples.length > 2 && item.samples[1].t < cutoff) item.samples.shift();
    }

    const staleCutoff = Date.now() - 15000;
    for (const [hex, item] of aircraftMarkers) {
      if (!seenNow.has(hex) && item.lastSeenAt < staleCutoff) {
        if (selectedAircraftHex === hex) closeAircraftInfo();
        item.marker.remove();
        aircraftMarkers.delete(hex);
      }
    }
  }

  function renderBufferedAircraft(nowClientMs) {
    if (!flightsEnabled()) {
      for (const item of aircraftMarkers.values()) item.el.style.display = "none";
      return;
    }
    if (!adsbHaveSourceClock) return;

    const targetSourceTime = nowClientMs - adsbSourceClockOffsetMs - ADSB_RENDER_DELAY_MS;
    for (const item of aircraftMarkers.values()) {
      const samples = item.samples;
      if (!samples.length) {
        item.el.style.display = "none";
        continue;
      }

      let before = null;
      let after = null;
      for (const sample of samples) {
        if (sample.t <= targetSourceTime) before = sample;
        if (sample.t >= targetSourceTime) {
          after = sample;
          break;
        }
      }

      if (!before) {
        item.el.style.display = "none";
        continue;
      }

      let shown;
      if (after && after !== before && after.t > before.t) {
        const t = Math.max(0, Math.min(1, (targetSourceTime - before.t) / (after.t - before.t)));
        const a = { ...before.data, lon: before.lon, lat: before.lat, heading: before.heading };
        const b = { ...after.data, lon: after.lon, lat: after.lat, heading: after.heading };
        shown = interpolateAircraft(a, b, t);
      } else {
        shown = { ...before.data, lon: before.lon, lat: before.lat, heading: before.heading };
      }

      item.rendered = shown;
      item.el.style.display = "";
      item.marker.setLngLat([shown.lon, shown.lat]);
      if (typeof item.marker.setRotation === "function") {
        item.marker.setRotation(Number.isFinite(shown.heading) ? shown.heading : 0);
      }

      if (selectedAircraftHex === item.data.hex && nowClientMs - aircraftInfoLastRenderAt > 250) {
        aircraftInfoLastRenderAt = nowClientMs;
        renderAircraftInfo(item.data, shown);
      }
    }
  }

  function aircraftAnimationLoop(ts) {
    if (ts - adsbLastAnimationFrame >= ADSB_ANIMATION_FRAME_MS) {
      adsbLastAnimationFrame = ts;
      renderBufferedAircraft(Date.now());
    }
    requestAnimationFrame(aircraftAnimationLoop);
  }

  function startAircraftAnimation() {
    if (adsbAnimationStarted) return;
    adsbAnimationStarted = true;
    requestAnimationFrame(aircraftAnimationLoop);
  }

  function currentAdsbViewBox() {
    const b = map.getBounds();
    return { south:b.getSouth(), north:b.getNorth(), west:b.getWest(), east:b.getEast() };
  }

  function paddedAdsbFetchBox() {
    const view = currentAdsbViewBox();
    const latSpan = Math.max(0.05, view.north - view.south);
    const lonSpan = Math.max(0.05, view.east - view.west);
    return {
      south: Math.max(-90, view.south - latSpan * ADSB_FETCH_BOX_PADDING),
      north: Math.min(90, view.north + latSpan * ADSB_FETCH_BOX_PADDING),
      west: Math.max(-180, view.west - lonSpan * ADSB_FETCH_BOX_PADDING),
      east: Math.min(180, view.east + lonSpan * ADSB_FETCH_BOX_PADDING)
    };
  }

  function adsbViewFitsFetchBox() {
    if (!adsbFetchBox) return false;
    const view = currentAdsbViewBox();
    return view.south >= adsbFetchBox.south && view.north <= adsbFetchBox.north &&
      view.west >= adsbFetchBox.west && view.east <= adsbFetchBox.east;
  }

  function ensureAdsbFetchBox(force = false) {
    if (force || !adsbFetchBox || !adsbViewFitsFetchBox()) {
      adsbFetchBox = paddedAdsbFetchBox();
      return true;
    }
    return false;
  }

  function adsbBoxString() {
    ensureAdsbFetchBox(false);
    return [
      adsbFetchBox.south.toFixed(6),
      adsbFetchBox.north.toFixed(6),
      adsbFetchBox.west.toFixed(6),
      adsbFetchBox.east.toFixed(6)
    ].join(",");
  }

  function aircraftFeature(ac) {
    const lat = Number(ac?.lat);
    const lon = Number(ac?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    const flight = String(ac.flight || ac.registration || ac.hex || "").trim();
    return {
      type: "Feature",
      geometry: { type: "Point", coordinates: [lon, lat] },
      properties: {
        layer: "flight",
        ident: flight || "AIRCRAFT",
        flight,
        registration: String(ac.registration || ""),
        aircraft_type: String(ac.typeCode || ""),
        altitude: ac.alt === "ground" ? "ground" : String(ac.alt ?? ""),
        groundspeed: Number(ac.gs || 0),
        track: Number.isFinite(ac.heading) ? ac.heading : 0,
        squawk: String(ac.squawk || ""),
        hex: String(ac.hex || ""),
        emergency: ["7500", "7600", "7700"].includes(String(ac.squawk || ""))
      }
    };
  }

  async function initAdsbDecoder() {
    if (adsbDecoderReady) return;
    if (!window.zstddec?.ZSTDDecoder) throw new Error("zstd decoder yüklenemedi");
    adsbDecoder = new window.zstddec.ZSTDDecoder();
    await adsbDecoder.init();
    adsbDecoderReady = true;
  }

  async function loadFlights() {
    clearTimeout(flightLoadTimer);
    if (!flightsEnabled()) return;

    if (!mapReady) {
      setFlightHealth("loading");
      flightLoadTimer = setTimeout(loadFlights, 250);
      return;
    }

    if (map.getZoom() < ADSB_MIN_FETCH_ZOOM) {
      if (!adsbHaveSourceClock) setFlightHealth("loading");
      flightLoadTimer = setTimeout(loadFlights, 1200);
      return;
    }

    try {
      await initAdsbDecoder();
    } catch (error) {
      setFlightHealth("error");
      return;
    }

    flightController?.abort();
    flightController = new AbortController();
    const seq = ++adsbRequestSeq;
    const box = adsbBoxString();

    if (!adsbHaveSourceClock) setFlightHealth("loading");

    try {
      const response = await fetch(ADSB_API + "?action=feed&box=" + encodeURIComponent(box), {
        cache: "no-store",
        signal: flightController.signal
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.error || ("HTTP " + response.status));
      }

      const compressed = new Uint8Array(await response.arrayBuffer());
      if (seq !== adsbRequestSeq) return;
      const decoded = adsbDecoder.decode(compressed);
      const parsed = parseBinCraft(decoded);

      const sourceNowMs = parsed.now * 1000;
      const measuredOffset = Date.now() - sourceNowMs;
      if (!adsbHaveSourceClock) {
        adsbSourceClockOffsetMs = measuredOffset;
        adsbHaveSourceClock = true;
      } else {
        adsbSourceClockOffsetMs = adsbSourceClockOffsetMs * 0.85 + measuredOffset * 0.15;
      }

      ingestAircraftSnapshot(parsed.aircraft, sourceNowMs);
      flightFeatures = parsed.aircraft.map(aircraftFeature).filter(Boolean);
      flightCountsState = { flight: flightFeatures.length };
      statusText.textContent = "LIVE";
      statusDot.classList.add("ok");
      statusDot.classList.remove("bad");
      updateCounts();

      setFlightHealth("ok");
    } catch (error) {
      if (error.name === "AbortError") return;
      console.error("[ADS-B TheAirTraffic]", error);
      statusText.textContent = "ADS-B ERROR";
      statusDot.classList.add("bad");
      statusDot.classList.remove("ok");
      setFlightHealth("error");
    } finally {
      if (flightsEnabled()) flightLoadTimer = setTimeout(loadFlights, ADSB_REFRESH_MS);
    }
  }

  function scheduleFlightLoad(delay = 250) {
    clearTimeout(flightLoadTimer);
    if (flightsEnabled()) flightLoadTimer = setTimeout(loadFlights, delay);
  }

  function handleAdsbMoveEnd() {
    if (!flightsEnabled()) return;
    const zoomNow = map.getZoom();
    const centerNow = map.getCenter();

    if (adsbLastMoveZoom == null) adsbLastMoveZoom = zoomNow;
    if (adsbLastMoveCenter == null) adsbLastMoveCenter = centerNow;

    const zoomChanged = Math.abs(zoomNow - adsbLastMoveZoom) > 0.01;
    const centerChanged =
      Math.abs(centerNow.lng - adsbLastMoveCenter.lng) > 0.00001 ||
      Math.abs(centerNow.lat - adsbLastMoveCenter.lat) > 0.00001;
    const zoomedOut = zoomNow < adsbLastMoveZoom - 0.01;

    adsbLastMoveZoom = zoomNow;
    adsbLastMoveCenter = centerNow;

    if (zoomChanged) {
      if (zoomedOut && !adsbViewFitsFetchBox()) {
        ensureAdsbFetchBox(true);
        scheduleFlightLoad(0);
      }
      return;
    }

    if (centerChanged) {
      ensureAdsbFetchBox(true);
      scheduleFlightLoad(0);
    }
  }

  function setFlightVisibility() {
    for (const item of aircraftMarkers.values()) {
      item.el.style.display = flightsEnabled() ? "" : "none";
    }
  }

  aircraftInfoClose?.addEventListener("click", closeAircraftInfo);

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

  function normalizeAircraftSearch(value) {
    return String(value || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
  }

  function localAircraftSearch(q) {
    const needle = normalizeAircraftSearch(q);
    if (needle.length < 2) return [];

    return flightFeatures
      .map(feature => {
        const p = feature.properties || {};
        const fields = [p.registration, p.flight, p.hex, p.aircraft_type]
          .map(value => ({ raw: String(value || ""), normalized: normalizeAircraftSearch(value) }))
          .filter(item => item.normalized);

        let score = 99;
        for (const field of fields) {
          if (field.normalized === needle) score = Math.min(score, 0);
          else if (field.normalized.startsWith(needle)) score = Math.min(score, 1);
          else if (field.normalized.includes(needle)) score = Math.min(score, 2);
        }
        if (score === 99) return null;

        const [lon, lat] = feature.geometry?.coordinates || [];
        const registration = String(p.registration || "").trim();
        const flight = String(p.flight || "").trim();
        const type = String(p.aircraft_type || "").trim();
        const secondary = [flight && flight !== registration ? flight : "", type]
          .filter(Boolean)
          .join(" · ");

        return {
          kind: "aircraft",
          ident: registration || flight || String(p.hex || "AIRCRAFT"),
          name: secondary || String(p.hex || ""),
          registration,
          flight,
          hex: String(p.hex || ""),
          lon: Number(lon),
          lat: Number(lat),
          _score: score
        };
      })
      .filter(Boolean)
      .sort((a, b) =>
        a._score - b._score ||
        String(a.ident).localeCompare(String(b.ident), "tr")
      )
      .slice(0, 15);
  }

  async function search(q) {
    const query = q.trim();

    if (query.length < 2) {
      if (searchController) searchController.abort();
      searchResults.classList.remove("open");
      searchResults.innerHTML = "";
      return;
    }

    if (searchController) searchController.abort();
    searchController = new AbortController();
    const controller = searchController;
    const aircraftResults = localAircraftSearch(query);

    // Aircraft matches are shown immediately. Typing never selects, zooms or opens
    // the aircraft drawer; that only happens after the user chooses a row.
    renderSearch(aircraftResults);

    try {
      const response = await fetch(`${NAVDATA_API}?action=search&q=${encodeURIComponent(query)}`, {
        cache: "no-store",
        signal: controller.signal
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) throw new Error(payload?.error || "Arama hatası");
      if (controller !== searchController) return;

      const navResults = Array.isArray(payload.results) ? payload.results : [];
      renderSearch([...aircraftResults, ...navResults]);
    } catch (error) {
      if (error.name === "AbortError") return;
      renderSearch(aircraftResults);
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
        <span class="badge">${esc(item.kind === "aircraft" ? "AIRCRAFT" : item.kind)}</span>
      </button>
    `).join("");

    searchResults.classList.add("open");

    searchResults.querySelectorAll("[data-result-index]").forEach(button => {
      button.addEventListener("click", () => {
        const item = results[Number(button.dataset.resultIndex)];
        if (!item) return;
        searchResults.classList.remove("open");

        if (item.kind === "aircraft" && item.hex) {
          if (Number.isFinite(item.lon) && Number.isFinite(item.lat)) {
            map.flyTo({
              center: [item.lon, item.lat],
              zoom: Math.max(map.getZoom(), 9),
              essential: true
            });
          }
          selectAircraft(String(item.hex));
          return;
        }

        if (Number.isFinite(item.lon) && Number.isFinite(item.lat)) {
          map.flyTo({
            center: [item.lon, item.lat],
            zoom: Math.max(map.getZoom(), item.kind === "waypoint" ? 10 : 8),
            essential: true
          });
        } else {
          searchInput.value = item.ident || "";
          statusText.textContent = `${String(item.kind || "ITEM").toUpperCase()} bulundu · haritada görünmesi için ilgili bölgeye git`;
        }
      });
    });
  }

  initializeTime();

  map.on("load", async () => {
    mapReady = true;
    addNavLayers();
    setFlightVisibility();
    boot.classList.add("hidden");
    scheduleViewportLoad(0, true);
    startAircraftAnimation();
    try {
      await initAdsbDecoder();
      if (flightsEnabled()) {
        ensureAdsbFetchBox(true);
        adsbLastMoveZoom = map.getZoom();
        adsbLastMoveCenter = map.getCenter();
        scheduleFlightLoad(0);
      }
    } catch (error) {
      console.error("[ADS-B decoder]", error);
      setFlightHealth("error");
    }
    loadWeatherOverlays().catch(console.error);
  });

  map.on("moveend", () => { scheduleViewportLoad(); handleAdsbMoveEnd(); });
  map.on("zoomend", () => updateZoomHint(chartTruncated || notamTruncated));

  function chartInputs() {
    return layerInputs.filter(input => CHART_LAYER_NAMES.has(input.dataset.navLayer));
  }

  function syncChartsToggleAll() {
    if (!chartsToggleAll) return;
    const inputs = chartInputs();
    const enabled = inputs.filter(input => input.checked).length;
    chartsToggleAll.checked = inputs.length > 0 && enabled === inputs.length;
    chartsToggleAll.indeterminate = enabled > 0 && enabled < inputs.length;
    chartsToggleAll.setAttribute(
      "aria-checked",
      chartsToggleAll.indeterminate ? "mixed" : String(chartsToggleAll.checked)
    );
  }

  chartsToggleAll?.addEventListener("change", () => {
    const inputs = chartInputs();
    const turnOn = chartsToggleAll.checked;
    chartsToggleAll.indeterminate = false;
    inputs.forEach(input => {
      input.checked = turnOn;
      setLayerVisibility(input.dataset.navLayer);
    });
    syncChartsToggleAll();
    updateCounts();
    if (activePanelTarget === "chart-panel") scheduleChartLoad(0, false);
  });

  layerInputs.forEach(input => {
    input.addEventListener("change", () => {
      const layer = input.dataset.navLayer;
      setLayerVisibility(layer);
      syncChartsToggleAll();
      updateCounts();

      if (layer === "notam") {
        scheduleNotamLoad(0, false);
      } else {
        scheduleChartLoad(0, false);
      }
    });
  });
  syncChartsToggleAll();

  flightsEnabledInput?.addEventListener("change", async () => {
    setFlightVisibility();
    if (!flightsEnabled()) {
      flightController?.abort();
      clearTimeout(flightLoadTimer);
      clearAircraftMarkers();
      flightFeatures = [];
      flightCountsState = { flight: 0 };
      updateCounts();
      setFlightHealth("off");
      return;
    }

    setFlightHealth("loading");
    try {
      await initAdsbDecoder();
      ensureAdsbFetchBox(true);
      adsbLastMoveZoom = map.getZoom();
      adsbLastMoveCenter = map.getCenter();
      scheduleFlightLoad(0);
    } catch (error) {
      setFlightHealth("error");
    }
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

  function updateModeVisibility() {
    for (const name of CHART_LAYER_NAMES) setLayerVisibility(name);
    setLayerVisibility("notam");
    setFlightVisibility();

    const showTimeline = activePanelTarget === "notam-panel" || activePanelTarget === "wafs-panel";
    timelineDock?.classList.toggle("mode-hidden", !showTimeline);
    document.body.classList.toggle("timeline-visible", showTimeline);

    if (activePanelTarget !== "wafs-panel") clearWeatherOverlays();
    else loadWeatherOverlays().catch(console.error);

    if (activePanelTarget === "flights-panel" && flightsEnabled()) {
      if (!adsbHaveSourceClock) setFlightHealth("loading");
      ensureAdsbFetchBox(true);
      scheduleFlightLoad(0);
    } else {
      flightController?.abort();
      clearTimeout(flightLoadTimer);
    }

    if (activePanelTarget === "chart-panel") scheduleChartLoad(0, false);
    if (activePanelTarget === "notam-panel") scheduleNotamLoad(0, false);

    updateCounts();
  }

  function activatePanel(target, allowToggle = true) {
    const panel = document.getElementById(target);
    const button = modeButtons.find(b => b.dataset.panelTarget === target);
    const wasOpen = panel?.classList.contains("open");
    const modeChanged = activePanelTarget !== target;

    toolPanels.forEach(p => p.classList.remove("open"));
    modeButtons.forEach(b => b.classList.toggle("active", b === button));

    activePanelTarget = target;

    if (target === "flights-panel" && modeChanged && flightsEnabledInput) {
      flightsEnabledInput.checked = true;
    }
    if (target === "notam-panel") {
      const notamInput = layerInputs.find(input => input.dataset.navLayer === "notam");
      if (notamInput && modeChanged) notamInput.checked = true;
    }
    if (target === "wafs-panel" && modeChanged && wafsEnabled) {
      wafsEnabled.checked = true;
    }

    const modeName = target === "flights-panel"
      ? "flights"
      : target === "notam-panel"
        ? "notam"
        : target === "wafs-panel"
          ? "wafs"
          : "charts";
    const url = new URL(location.href);
    url.searchParams.set("mode", modeName);
    history.replaceState(null, "", url);

    setTimelineRange(PANEL_TIMELINE_RANGES[target] || 24, false);
    statusDot.classList.remove("bad");
    if (target === "flights-panel") {
      statusText.textContent = adsbHaveSourceClock ? "LIVE" : "FLIGHTS · bağlanıyor";
    } else if (target === "notam-panel") {
      statusText.textContent = "NOTAM";
    } else if (target === "wafs-panel") {
      statusText.textContent = "WAFS · FL" + String(wafsFL?.value || "—");
    } else {
      statusText.textContent = "CHARTS";
    }

    updateModeVisibility();

    if (allowToggle && wasOpen && !modeChanged) return;
    if (panel) panel.classList.add("open");
  }

  modeButtons.forEach(button => {
    button.addEventListener("click", () => {
      activatePanel(button.dataset.panelTarget, true);
    });
  });

  panelCloseButtons.forEach(button => {
    button.addEventListener("click", () => {
      button.closest(".tool-panel")?.classList.remove("open");
    });
  });

  setTimeout(() => {
    const panel = initialMode === "flights"
      ? "flights-panel"
      : initialMode === "notam"
        ? "notam-panel"
        : initialMode === "wafs"
          ? "wafs-panel"
          : "chart-panel";
    activatePanel(panel, false);
  }, 0);

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
    if (event.key === "Escape") {
      searchResults.classList.remove("open");
      searchInput.blur();
    }
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape") closeAircraftInfo();
  });

  document.addEventListener("click", event => {
    if (!event.target.closest(".search")) {
      searchResults.classList.remove("open");
    }
  });

  bootDetail.textContent = "Navdata katmanları hazırlanıyor…";
})();
