(() => {
  "use strict";

  const MAP = window.__YC_MAP__;
  if (!MAP) return;

  const PAINT = {
    cbextent: {
      "raster-contrast": 0.45,
      "raster-saturation": -0.35,
      "raster-brightness-min": 0.08,
      "raster-brightness-max": 1
    },
    cbtop: {
      "raster-contrast": 0.34,
      "raster-saturation": 0.5,
      "raster-brightness-min": 0.05,
      "raster-brightness-max": 1
    }
  };

  const layerId = product => `wafsx-${product}-layer`;

  function setPaintIfNeeded(id, property, value) {
    if (!MAP.getLayer(id)) return;
    const current = MAP.getPaintProperty(id, property);
    if (current === value) return;
    MAP.setPaintProperty(id, property, value);
  }

  function applyCbPaint() {
    for (const [product, props] of Object.entries(PAINT)) {
      const id = layerId(product);
      if (!MAP.getLayer(id)) continue;
      for (const [property, value] of Object.entries(props)) {
        setPaintIfNeeded(id, property, value);
      }
    }
  }

  function migrateCbOpacity(product, oldValue, newValue) {
    const input = document.querySelector(`[data-wafs2-opacity="${product}"]`);
    if (!input || String(input.value) !== String(oldValue)) return;
    input.value = String(newValue);
    input.dispatchEvent(new Event("input", { bubbles: true }));
  }

  // The previous defaults were deliberately soft. Raise only those exact defaults;
  // custom user values remain untouched.
  migrateCbOpacity("cbextent", 56, 68);
  migrateCbOpacity("cbtop", 60, 70);

  const scheduleApply = () => requestAnimationFrame(applyCbPaint);
  MAP.on("styledata", scheduleApply);
  MAP.on("sourcedata", scheduleApply);

  if (MAP.loaded()) applyCbPaint();
  else MAP.once("load", applyCbPaint);
})();
