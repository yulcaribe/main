(() => {
  "use strict";

  const MODULE_URLS = [
    "https://cdn.jsdelivr.net/npm/@azohra/meteo.grib@0.1.4/dist/index.js",
    "https://esm.sh/@azohra/meteo.grib@0.1.4?bundle"
  ];

  let mod = null;
  let initPromise = null;
  let nextHandle = 1;
  const contexts = new Map();

  async function init() {
    if (mod) return;
    if (initPromise) return initPromise;
    initPromise = (async () => {
      let last = null;
      for (const url of MODULE_URLS) {
        try {
          const m = await import(url);
          if (typeof m.splitMessages !== "function" || typeof m.parseFields !== "function" || typeof m.decodeFieldValues !== "function") {
            throw new Error("GRIB2 modülü beklenen API'yi sunmuyor.");
          }
          mod = m;
          return;
        } catch (e) {
          last = e;
        }
      }
      throw new Error("GRIB2 decoder yüklenemedi: " + (last?.message || String(last)));
    })();
    return initPromise;
  }

  function ctx(handle) {
    const c = contexts.get(handle);
    if (!c) throw new Error("Geçersiz GRIB2 handle.");
    return c;
  }

  function rec(handle, index) {
    const c = ctx(handle);
    const r = c.records[index - 1];
    if (!r) throw new Error(`GRIB2 record ${index} bulunamadı.`);
    return r;
  }

  function parse(bytes) {
    if (!mod) throw new Error("GRIB2 decoder başlatılmadı.");
    const records = [];
    for (const message of mod.splitMessages(bytes)) {
      for (const field of mod.parseFields(message)) {
        const product = mod.parseProduct(field.section4);
        const grid = mod.parseGrid(field.section3);
        records.push({ field, product, grid, decoded: null });
      }
    }
    if (!records.length) throw new Error("GRIB2 içinde çözülebilir kayıt bulunamadı.");
    const handle = nextHandle++;
    contexts.set(handle, { records });
    if (contexts.size > 12) {
      const oldest = contexts.keys().next().value;
      contexts.delete(oldest);
    }
    return handle;
  }

  function recordCount(handle) {
    return ctx(handle).records.length;
  }

  function section4(handle, index) {
    const p = rec(handle, index).product;
    return {
      template: p.productDefinitionTemplateNumber,
      parameterCategory: p.parameterCategory,
      parameterNumber: p.parameterNumber,
      forecastTime: p.forecastTime ?? 0,
      indicatorOfUnitOfTimeRange: p.indicatorOfUnitOfTimeRange,
      typeOfFirstFixedSurface: p.typeOfFirstFixedSurface,
      scaleFactorOfFirstFixedSurface: p.scaleFactorOfFirstFixedSurface,
      scaledValueOfFirstFixedSurface: p.scaledValueOfFirstFixedSurface,
      typeOfSecondFixedSurface: p.typeOfSecondFixedSurface,
      scaleFactorOfSecondFixedSurface: p.scaleFactorOfSecondFixedSurface,
      scaledValueOfSecondFixedSurface: p.scaledValueOfSecondFixedSurface
    };
  }

  function values(handle, index) {
    const r = rec(handle, index);
    if (!r.decoded) r.decoded = mod.decodeFieldValues(r.field);
    return r.decoded.values;
  }

  function nearest(handle, index, lat, lon) {
    const r = rec(handle, index);
    return mod.nearestGridpoint(r.grid, lat, lon);
  }

  function section3(handle, index) {
    const g = rec(handle, index).grid;
    return {
      template: g.gridDefinitionTemplateNumber,
      numberOfPoints: g.numberOfDataPoints,
      ni: g.ni,
      nj: g.nj,
      scanMode: g.scanningMode
    };
  }

  window.YCGrib2 = {
    init,
    parse,
    recordCount,
    section3,
    section4,
    values,
    nearest,
    decoder: "@azohra/meteo.grib 0.1.4"
  };
})();