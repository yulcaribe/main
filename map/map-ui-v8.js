(() => {
  "use strict";

  const MAP = window.__YC_MAP__;
  const WAFS_API = "/main/api/v1/wafs.php";
  const PRODUCTS = {
    edr: {
      label: "Turbulence / EDR",
      short: "Turb",
      levels: [140, 180, 240, 270, 300, 340, 390, 450]
    },
    icing: {
      label: "Icing severity",
      short: "Icing",
      levels: [60, 100, 140, 180, 240, 300]
    },
    wind: {
      label: "Wind speed",
      short: "Wind",
      levels: [100, 140, 180, 240, 270, 300, 340, 390, 450]
    },
    cbextent: {
      label: "CB horizontal extent",
      short: "CB extent",
      levels: null
    },
    cbtop: {
      label: "CB tops",
      short: "CB tops",
      levels: null
    }
  };

  const master = document.getElementById("wafs-enabled");
  const status = document.getElementById("wafs-status");
  const summary = document.getElementById("wafs-active-summary");
  const timeInput = document.getElementById("map-time");
  const timeSlider = document.getElementById("map-time-slider");
  const modeButtons = [...document.querySelectorAll("[data-panel-target]")];
  const productInputs = [...document.querySelectorAll("[data-wafs2-product]")];
  const levelInputs = [...document.querySelectorAll("[data-wafs2-level]")];
  const opacityInputs = [...document.querySelectorAll("[data-wafs2-opacity]")];
  const timeStepButtons = [...document.querySelectorAll("[data-time-step]")];
  const nowButton = document.getElementById("time-now");

  if (!MAP || !master || !productInputs.length) return;

  const overlays = new Map();
  const controllers = new Map();
  const STORAGE_KEY = "yc:wafs:v2";
  let timeReloadTimer = null;

  function activeWafsMode() {
    const activeButton = document.querySelector("[data-panel-target].active");
    if (activeButton) return activeButton.dataset.panelTarget === "wafs-panel";
    return new URLSearchParams(location.search).get("mode") === "wafs";
  }

  function selectedUtc() {
    const raw = String(timeInput?.value || "").trim();
    if (raw) return raw.slice(0, 16).replace("T", " ");
    const d = new Date();
    return d.toISOString().slice(0, 16).replace("T", " ");
  }

  function sourceId(product) {
    return `wafsx-${product}-source`;
  }

  function layerId(product) {
    return `wafsx-${product}-layer`;
  }

  function productInput(product) {
    return document.querySelector(`[data-wafs2-product="${product}"]`);
  }

  function levelInput(product) {
    return document.querySelector(`[data-wafs2-level="${product}"]`);
  }

  function opacityInput(product) {
    return document.querySelector(`[data-wafs2-opacity="${product}"]`);
  }

  function metaEl(product) {
    return document.querySelector(`[data-wafs2-meta="${product}"]`);
  }

  function productLevel(product) {
    const cfg = PRODUCTS[product];
    if (!Array.isArray(cfg?.levels)) return null;
    const select = levelInput(product);
    const value = Number(select?.value);
    return cfg.levels.includes(value) ? value : cfg.levels[0];
  }

  function opacityFor(product) {
    const n = Number(opacityInput(product)?.value || 40);
    return Math.max(0.1, Math.min(0.85, n / 100));
  }

  function removeProduct(product) {
    controllers.get(product)?.abort();
    controllers.delete(product);
    const layer = layerId(product);
    const source = sourceId(product);
    if (MAP.getLayer(layer)) MAP.removeLayer(layer);
    if (MAP.getSource(source)) MAP.removeSource(source);
    const state = overlays.get(product);
    if (state?.url) URL.revokeObjectURL(state.url);
    overlays.delete(product);
    renderSummary();
  }

  function clearAll() {
    for (const product of Object.keys(PRODUCTS)) removeProduct(product);
  }

  function waitForImage(url, signal) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const abort = () => {
        img.src = "";
        reject(new DOMException("Aborted", "AbortError"));
      };
      signal?.addEventListener("abort", abort, { once: true });
      img.onload = () => {
        signal?.removeEventListener("abort", abort);
        resolve(img);
      };
      img.onerror = () => {
        signal?.removeEventListener("abort", abort);
        reject(new Error("WAFS görseli açılamadı."));
      };
      img.src = url;
    });
  }

  async function fetchFrame(product, signal) {
    const level = productLevel(product);
    const params = new URLSearchParams({
      action: "image",
      product,
      fl: String(level ?? 300),
      valid: selectedUtc()
    });
    const response = await fetch(`${WAFS_API}?${params}`, {
      cache: "no-store",
      signal
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => null);
      throw new Error(payload?.error || `WAFS HTTP ${response.status}`);
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    try {
      const img = await waitForImage(url, signal);
      const yMax = Math.PI * (img.naturalHeight / img.naturalWidth);
      const maxLat = 180 / Math.PI * Math.atan(Math.sinh(yMax));
      return {
        url,
        maxLat,
        level: response.headers.get("x-yc-wafs-layer-fl"),
        pressure: response.headers.get("x-yc-wafs-pressure-mb"),
        validUtc: response.headers.get("x-yc-wafs-valid-utc"),
        forecastHour: response.headers.get("x-yc-wafs-forecast-hour")
      };
    } catch (error) {
      URL.revokeObjectURL(url);
      throw error;
    }
  }

  function formatValid(value) {
    if (!value) return "seçilen UTC";
    return value.slice(0, 16).replace("T", " ") + "Z";
  }

  function renderSummary() {
    if (!summary) return;
    if (!activeWafsMode() || !master.checked) {
      summary.hidden = true;
      summary.innerHTML = "";
      return;
    }

    const active = productInputs.filter(input => input.checked);
    if (!active.length) {
      summary.hidden = true;
      summary.innerHTML = "";
      return;
    }

    summary.innerHTML = active.map(input => {
      const product = input.dataset.wafs2Product;
      const cfg = PRODUCTS[product];
      const state = overlays.get(product);
      const selectedLevel = productLevel(product);
      const level = state?.level && state.level !== "NA"
        ? `FL${state.level}`
        : selectedLevel != null
          ? `FL${selectedLevel}`
          : "WHOLE";
      const pressure = state?.pressure && state.pressure !== "NA"
        ? ` / ${state.pressure}mb`
        : "";
      const loading = state ? "" : " · …";
      return `<span><b>${cfg.short}</b> ${level}${pressure}${loading}</span>`;
    }).join("");
    summary.hidden = false;
  }

  function saveState() {
    const payload = {};
    for (const product of Object.keys(PRODUCTS)) {
      payload[product] = {
        enabled: Boolean(productInput(product)?.checked),
        level: productLevel(product),
        opacity: Number(opacityInput(product)?.value || 40)
      };
    }
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch (_) {}
  }

  function restoreState() {
    let payload = null;
    try {
      payload = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
    } catch (_) {}
    if (!payload || typeof payload !== "object") return;

    for (const [product, state] of Object.entries(payload)) {
      if (!PRODUCTS[product] || !state || typeof state !== "object") continue;
      const toggle = productInput(product);
      const level = levelInput(product);
      const opacity = opacityInput(product);
      if (toggle && typeof state.enabled === "boolean") toggle.checked = state.enabled;
      if (level && PRODUCTS[product].levels?.includes(Number(state.level))) level.value = String(state.level);
      if (opacity && Number.isFinite(Number(state.opacity))) opacity.value = String(state.opacity);
    }
  }

  async function loadProduct(product) {
    const toggle = productInput(product);
    if (!activeWafsMode() || !master.checked || !toggle?.checked) {
      removeProduct(product);
      return;
    }
    if (!MAP.loaded()) return;

    removeProduct(product);
    const controller = new AbortController();
    controllers.set(product, controller);
    const meta = metaEl(product);
    if (meta) meta.textContent = "yükleniyor…";
    renderSummary();

    try {
      const frame = await fetchFrame(product, controller.signal);
      if (controller.signal.aborted) {
        URL.revokeObjectURL(frame.url);
        return;
      }

      const source = sourceId(product);
      const layer = layerId(product);
      MAP.addSource(source, {
        type: "image",
        url: frame.url,
        coordinates: [
          [-180, frame.maxLat],
          [180, frame.maxLat],
          [180, -frame.maxLat],
          [-180, -frame.maxLat]
        ]
      });

      const layerSpec = {
        id: layer,
        type: "raster",
        source,
        paint: {
          "raster-opacity": opacityFor(product),
          "raster-fade-duration": 0
        }
      };
      const before = MAP.getLayer("nav-airspace-fill") ? "nav-airspace-fill" : undefined;
      if (before) MAP.addLayer(layerSpec, before); else MAP.addLayer(layerSpec);

      overlays.set(product, frame);
      const level = frame.level && frame.level !== "NA" ? `FL${frame.level}` : "whole layer";
      const pressure = frame.pressure && frame.pressure !== "NA" ? ` · ${frame.pressure}mb` : "";
      if (meta) meta.textContent = `${formatValid(frame.validUtc)} · ${level}${pressure} · F${frame.forecastHour || "?"}`;
    } catch (error) {
      if (error?.name === "AbortError") return;
      console.error(`[WAFS independent ${product}]`, error);
      if (meta) meta.textContent = error?.message || "frame yüklenemedi";
    } finally {
      if (controllers.get(product) === controller) controllers.delete(product);
      renderSummary();
      updateStatus();
    }
  }

  async function loadAll() {
    if (!activeWafsMode() || !master.checked) {
      clearAll();
      updateStatus();
      return;
    }
    const active = productInputs.filter(input => input.checked).map(input => input.dataset.wafs2Product);
    for (const product of Object.keys(PRODUCTS)) {
      if (!active.includes(product)) removeProduct(product);
    }
    renderSummary();
    if (!active.length) {
      updateStatus();
      return;
    }
    if (status) status.textContent = `${active.length} WAFS katmanı yükleniyor…`;
    await Promise.all(active.map(product => loadProduct(product)));
    updateStatus();
  }

  function updateStatus() {
    if (!status) return;
    if (!master.checked) {
      status.textContent = "WAFS kapalı.";
      return;
    }
    const active = productInputs.filter(input => input.checked);
    if (!active.length) {
      status.textContent = "WAFS açık ama ürün seçili değil.";
      return;
    }
    const ready = active.filter(input => overlays.has(input.dataset.wafs2Product)).length;
    status.textContent = `${ready}/${active.length} WAFS katmanı · ürün seviyeleri bağımsız · ${selectedUtc()}Z`;
  }

  function scheduleTimeReload() {
    clearTimeout(timeReloadTimer);
    timeReloadTimer = setTimeout(() => {
      if (activeWafsMode()) loadAll().catch(console.error);
    }, 80);
  }

  function syncWafsTimelineCadence() {
    const wafs = activeWafsMode();
    if (timeSlider) timeSlider.step = wafs ? "3" : "1";
    const minusOne = timeStepButtons.find(button => button.dataset.timeStep === "-1" || button.dataset.wafsOriginalStep === "-1");
    const plusOne = timeStepButtons.find(button => button.dataset.timeStep === "1" || button.dataset.wafsOriginalStep === "1");
    if (minusOne) {
      minusOne.dataset.wafsOriginalStep = "-1";
      minusOne.dataset.timeStep = wafs ? "-3" : "-1";
      minusOne.textContent = wafs ? "-3h" : "-1h";
    }
    if (plusOne) {
      plusOne.dataset.wafsOriginalStep = "1";
      plusOne.dataset.timeStep = wafs ? "3" : "1";
      plusOne.textContent = wafs ? "+3h" : "+1h";
    }
  }

  function tagNotamPopups() {
    document.querySelectorAll(".maplibregl-popup").forEach(popup => {
      const isNotam = Boolean(popup.querySelector(".notam-card"));
      popup.classList.toggle("yc-notam-popup", isNotam);
    });
  }

  restoreState();
  renderSummary();
  tagNotamPopups();

  const popupObserver = new MutationObserver(tagNotamPopups);
  popupObserver.observe(document.getElementById("map") || document.body, {
    childList: true,
    subtree: true
  });

  productInputs.forEach(input => {
    input.addEventListener("change", () => {
      saveState();
      const product = input.dataset.wafs2Product;
      if (input.checked) loadProduct(product).catch(console.error);
      else removeProduct(product);
      updateStatus();
    });
  });

  levelInputs.forEach(select => {
    select.addEventListener("change", () => {
      saveState();
      loadProduct(select.dataset.wafs2Level).catch(console.error);
    });
  });

  opacityInputs.forEach(input => {
    input.addEventListener("input", () => {
      const product = input.dataset.wafs2Opacity;
      const layer = layerId(product);
      if (MAP.getLayer(layer)) MAP.setPaintProperty(layer, "raster-opacity", opacityFor(product));
      saveState();
    });
  });

  master.addEventListener("change", () => {
    if (master.checked) loadAll().catch(console.error);
    else clearAll();
    renderSummary();
    updateStatus();
  });

  modeButtons.forEach(button => {
    button.addEventListener("click", () => {
      setTimeout(() => {
        syncWafsTimelineCadence();
        if (activeWafsMode()) {
          if (master.checked) loadAll().catch(console.error);
        } else {
          clearAll();
        }
        renderSummary();
      }, 0);
    });
  });

  timeSlider?.addEventListener("change", scheduleTimeReload);
  timeInput?.addEventListener("change", scheduleTimeReload);
  timeStepButtons.forEach(button => button.addEventListener("click", scheduleTimeReload));
  nowButton?.addEventListener("click", scheduleTimeReload);

  syncWafsTimelineCadence();
  if (MAP.loaded()) {
    if (activeWafsMode() && master.checked) loadAll().catch(console.error);
  } else {
    MAP.once("load", () => {
      if (activeWafsMode() && master.checked) loadAll().catch(console.error);
    });
  }
})();
