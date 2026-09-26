(() => {
  "use strict";

  const bootDetail = document.getElementById("boot-detail");
  if (!window.maplibregl) {
    if (bootDetail) bootDetail.textContent = "MapLibre yÃ¼klenemedi.";
    return;
  }

  const API = {
    navdata: "/main/api/navdata.php",
    notam: "/main/api/notam.php",
    wafs: "/main/api/wafs.php",
    adsb: "/main/api/adsb.php"
  };

  const COLORS = {
    airport: "#7ee7ff",
    navaid: "#ffc76b",
    waypoint: "#d6e1e7",
    airway: "#5fdbe8",
    sid: "#70e8a7",
    star: "#bc9cff",
    airspace: "#ff7f94"
  };

  const NOTAM_COLOR = [
    "match", ["get", "display_group"],
    "AERIAL_SPORT", "#00c5b9",
    "RESTRICTED_AIRSPACE", "#ff5f6d",
    "AERIAL_SURVEY", "#ff9b4a",
    "TRAINING_MILITARY", "#d9e1e5",
    "OTHER", "#4f8cff",
    "#ffd35f"
  ];

  const WAFS = {
    edr: [140, 180, 240, 270, 300, 340, 390, 450],
    icing: [60, 100, 140, 180, 240, 300],
    wind: [100, 140, 180, 240, 270, 300, 340, 390, 450],
    cbextent: null,
    cbtop: null
  };

  const chartNames = new Set(["airport", "navaid", "waypoint", "airway", "sid", "star", "airspace"]);
  const approximateNotamSources = ["qline-coordinate", "airport-location"];
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");

  const boot = $("boot");
  const statusDot = $("status-dot");
  const statusText = $("status-text");
  const featureCount = $("feature-count");
  const zoomHint = $("zoom-hint");
  const searchInput = $("nav-search");
  const searchResults = $("search-results");
  const chartsToggleAll = $("charts-toggle-all");
  const timelineDock = $("timeline-dock");
  const timelineToggle = $("timeline-toggle");
  const timeInput = $("map-time");
  const timeSlider = $("map-time-slider");
  const timeLabel = $("selected-time-label");
  const timelineOffset = $("timeline-offset");
  const timelineScale = $("timeline-scale");
  const nowButton = $("time-now");
  const timeStepButtons = [...document.querySelectorAll("[data-time-step]")];
  const modeButtons = [...document.querySelectorAll("[data-panel-target]")];
  const panels = [...document.querySelectorAll(".tool-panel")];
  const closeButtons = [...document.querySelectorAll("[data-panel-close]")];
  const layerInputs = [...document.querySelectorAll("[data-nav-layer]")];
  const flightsEnabledInput = $("flights-enabled");
  const flightsStatus = $("flights-status");
  const notamTimeStatus = $("notam-time-status");
  const wafsEnabled = $("wafs-enabled");
  const wafsStatus = $("wafs-status");
  const wafsProducts = [...document.querySelectorAll("[data-wafs2-product]")];
  const wafsLevels = [...document.querySelectorAll("[data-wafs2-level]")];
  const wafsOpacities = [...document.querySelectorAll("[data-wafs2-opacity]")];

  const initialMode = new URLSearchParams(location.search).get("mode") || "charts";
  let activePanel = initialMode === "flights" ? "flights-panel"
    : initialMode === "notam" ? "notam-panel"
    : initialMode === "wafs" ? "wafs-panel"
    : "chart-panel";

  let chartController = null;
  let notamController = null;
  let searchController = null;
  let chartTimer = null;
  let notamTimer = null;
  let searchTimer = null;
  let chartCounts = {};
  let notamCounts = {};
  let chartTruncated = false;
  let notamTruncated = false;
  let timelineAnchor = null;
  let timelineRange = 24;
  const chartCache = new Map();
  const notamCache = new Map();

  const map = new maplibregl.Map({
    container: "map",
    center: [30.8, 36.9],
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
          attribution: "Â© OpenStreetMap contributors"
        }
      },
      layers: [{
        id: "osm-base",
        type: "raster",
        source: "osm",
        paint: {
          "raster-saturation": -0.82,
          "raster-brightness-min": 0.05,
          "raster-brightness-max": 0.42,
          "raster-contrast": 0.22
        }
      }]
    }
  });

  window.__YC_MAP__ = map;
  map.addControl(new maplibregl.NavigationControl({ showCompass: true }), "bottom-left");
  map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: "nautical" }), "bottom-left");

  const empty = () => ({ type: "FeatureCollection", features: [] });
  const selectedLayers = () => layerInputs.filter(i => i.checked).map(i => i.dataset.navLayer);
  const selectedCharts = () => selectedLayers().filter(name => chartNames.has(name));
  const notamEnabled = () => activePanel === "notam-panel" && selectedLayers().includes("notam");
  const flightsEnabled = () => activePanel === "flights-panel" && Boolean(flightsEnabledInput?.checked);

  function visibility(name) {
    if (name === "notam") return notamEnabled() ? "visible" : "none";
    if (chartNames.has(name)) {
      return activePanel === "chart-panel" && selectedLayers().includes(name) ? "visible" : "none";
    }
    return "visible";
  }

  function setLayerVisibility(name) {
    ["fill", "line", "hit", "circle", "label", "approx-line"].forEach(suffix => {
      const id = `nav-${name}-${suffix}`;
      if (map.getLayer(id)) map.setLayoutProperty(id, "visibility", visibility(name));
    });
  }

  function addMapLayers() {
    map.addSource("charts", { type: "geojson", data: empty() });
    map.addSource("notams", { type: "geojson", data: empty() });

    map.addLayer({
      id: "nav-airspace-fill", type: "fill", source: "charts",
      filter: ["all", ["==", ["get", "layer"], "airspace"], ["==", ["geometry-type"], "Polygon"]],
      paint: { "fill-color": COLORS.airspace, "fill-opacity": 0.055 },
      layout: { visibility: visibility("airspace") }
    });
    map.addLayer({
      id: "nav-airspace-line", type: "line", source: "charts",
      filter: ["==", ["get", "layer"], "airspace"],
      paint: { "line-color": COLORS.airspace, "line-width": ["interpolate", ["linear"], ["zoom"], 5, 0.8, 10, 1.5], "line-opacity": 0.66 },
      layout: { visibility: visibility("airspace") }
    });

    for (const type of ["airway", "sid", "star"]) {
      const min = type === "airway" ? 5 : 8;
      map.addLayer({
        id: `nav-${type}-hit`, type: "line", source: "charts",
        filter: ["==", ["get", "layer"], type], minzoom: min,
        paint: { "line-color": COLORS[type], "line-width": ["interpolate", ["linear"], ["zoom"], min, 12, 12, 18], "line-opacity": 0.01 },
        layout: { visibility: visibility(type) }
      });
      map.addLayer({
        id: `nav-${type}-line`, type: "line", source: "charts",
        filter: ["==", ["get", "layer"], type], minzoom: min,
        paint: {
          "line-color": COLORS[type],
          "line-width": ["interpolate", ["linear"], ["zoom"], min, type === "airway" ? 1.1 : 2, 12, type === "airway" ? 2.3 : 4.1, 15, type === "airway" ? 3 : 5.4],
          "line-opacity": type === "airway" ? 0.76 : 0.9
        },
        layout: { visibility: visibility(type), "line-cap": "round", "line-join": "round" }
      });
      map.addLayer({
        id: `nav-${type}-label`, type: "symbol", source: "charts",
        filter: ["==", ["get", "layer"], type], minzoom: type === "airway" ? 7 : 9,
        layout: {
          visibility: visi¶»§q«^