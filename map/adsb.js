(() => {
  "use strict";

  window.YCAdsb = {
    create(ctx) {
      const { map, api, esc, popup, setStatus, bbox } = ctx;
      const REFRESH_MS = 2000;
      const MIN_ZOOM = 4.2;
      const RENDER_DELAY_MS = 4200;
      const SAMPLE_KEEP_MS = 20000;
      const STALE_MS = 15000;
      const FRAME_MS = 32;

      let active = false;
      let controller = null;
      let timer = null;
      let requestVersion = 0;
      let failures = 0;
      let sourceClockOffsetMs = 0;
      let haveSourceClock = false;
      let animationStarted = false;
      let lastFrame = 0;

      const aircraftByIcao = new Map();
      const markers = new Map();
      const enabledInput = document.getElementById("flights-enabled");
      const health = document.getElementById("flights-status");

      function enabled() {
        return active && Boolean(enabledInput?.checked);
      }

      function finite(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : null;
      }

      function normalizeAircraft(row) {
        if (!row || typeof row !== "object") return null;
        const icao = String(row.icao || "").toLowerCase();
        if (!/^[0-9a-f]{6}$/.test(icao)) return null;
        const lat = finite(row.lat);
        const lon = finite(row.lon);
        if (lat === null || lon === null || Math.abs(lat) > 90 || Math.abs(lon) > 180) return null;
        const track = finite(row.track);
        const trueHeading = finite(row.trueHeading);
        const magHeading = finite(row.magHeading);
        const heading = track ?? trueHeading ?? magHeading ?? 0;
        return {
          ...row,
          icao,
          lat,
          lon,
          track,
          trueHeading,
          magHeading,
          heading,
          groundSpeed: finite(row.groundSpeed),
          seen: finite(row.seen),
          seenPos: finite(row.seenPos),
          baroRate: finite(row.baroRate)
        };
      }

      function createAircraftElement() {
        const el = document.createElement("div");
        el.className = "aircraft-marker";
        el.innerHTML = '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 3 C29.8 3 28.7 5.4 28.4 8.4 L26.8 25.2 L7 34.4 L7 39 L27.8 34.4 L28.2 49.5 L20.2 55.5 L20.2 59 L32 56 L43.8 59 L43.8 55.5 L35.8 49.5 L36.2 34.4 L57 39 L57 34.4 L37.2 25.2 L35.6 8.4 C35.3 5.4 34.2 3 32 3 Z" fill="#f4f8fb" stroke="#071019" stroke-width="1.6" stroke-linejoin="round"/></svg>';
        return el;
      }

      const shortestAngle = (a, b) => a + (((b - a + 540) % 360) - 180);
      const lerp = (a, b, t) => a + (b - a) * t;

      function interpolate(a, b, t) {
        const hb = shortestAngle(a.heading ?? 0, b.heading ?? a.heading ?? 0);
        return {
          ...b,
          lon: lerp(a.lon, b.lon, t),
          lat: lerp(a.lat, b.lat, t),
          heading: ((lerp(a.heading ?? 0, hb, t) % 360) + 360) % 360
        };
      }

      function extrapolate(sample, seconds) {
        const data = sample.data || {};
        const gs = finite(data.groundSpeed);
        const track = finite(data.track) ?? finite(sample.heading);
        const s = Math.max(0, Math.min(7, Number(seconds) || 0));
        if (gs === null || gs < 15 || track === null || s <= 0) {
          return { ...data, lon: sample.lon, lat: sample.lat, heading: sample.heading };
        }
        const distanceNm = gs * s / 3600;
        const rad = track * Math.PI / 180;
        const north = Math.cos(rad) * distanceNm;
        const east = Math.sin(rad) * distanceNm;
        const lat = sample.lat + north / 60;
        const cosLat = Math.max(.15, Math.cos(lat * Math.PI / 180));
        return {
          ...data,
          lat,
          lon: sample.lon + east / (60 * cosLat),
          heading: sample.heading
        };
      }

      function showAircraftCard(item) {
        const ac = item.data || {};
        const shown = item.rendered || ac;
        const rows = [];
        const push = (k, v) => {
          if (v !== null && v !== undefined && v !== "") rows.push(`<div><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`);
        };
        push("Callsign", ac.callsign || "—");
        push("Registration", ac.reg || "—");
        push("Type", ac.aircraftType || "—");
        push("Altitude", ac.altBaro === "ground" ? "GND" : Number.isFinite(Number(ac.altBaro)) ? `${Math.round(Number(ac.altBaro)).toLocaleString("en-US")} ft` : "—");
        push("Groundspeed", Number.isFinite(ac.groundSpeed) ? `${Math.round(ac.groundSpeed)} kt` : "—");
        push("Track", Number.isFinite(shown.heading) ? `${Math.round(shown.heading)}°` : "—");
        popup([shown.lon, shown.lat], ac.callsign || ac.reg || ac.icao.toUpperCase(), [ac.reg, ac.aircraftType].filter(Boolean).join(" · ") || "ADS-B", rows.join(""), { maxWidth: "300px" });
      }

      function ensureMarker(ac) {
        let item = markers.get(ac.icao);
        if (item) return item;
        const el = createAircraftElement();
        el.style.display = "none";
        const marker = new maplibregl.Marker({ element: el, rotationAlignment: "map", pitchAlignment: "map" })
          .setLngLat([ac.lon, ac.lat])
          .setRotation(Number.isFinite(ac.heading) ? ac.heading : 0)
          .addTo(map);
        item = { marker, el, data: ac, rendered: ac, samples: [], lastSeenAt: Date.now() };
        el.addEventListener("click", e => { e.stopPropagation(); showAircraftCard(item); });
        markers.set(ac.icao, item);
        return item;
      }

      function updateSourceClock(sourceNowMs) {
        if (!Number.isFinite(sourceNowMs) || sourceNowMs <= 0) return;
        const measured = Date.now() - sourceNowMs;
        sourceClockOffsetMs = haveSourceClock ? sourceClockOffsetMs * .85 + measured * .15 : measured;
        haveSourceClock = true;
      }

      function ingestBatch(rows, sourceNowMs, cycleSeen) {
        updateSourceClock(sourceNowMs);
        for (const raw of rows) {
          const ac = normalizeAircraft(raw);
          if (!ac) continue;
          cycleSeen.add(ac.icao);
          aircraftByIcao.set(ac.icao, ac);
          const item = ensureMarker(ac);
          item.data = ac;
          item.lastSeenAt = Date.now();
          const ageMs = Number.isFinite(ac.seenPos) ? Math.max(0, ac.seenPos * 1000) : 0;
          const sampleTime = sourceNowMs - ageMs;
          const last = item.samples[item.samples.length - 1];
          if (last && sampleTime <= last.t + 50) {
            if (Math.abs(last.t - sampleTime) < 50) {
              last.data = ac;
              last.heading = Number.isFinite(ac.heading) ? ac.heading : last.heading;
            }
            continue;
          }
          const samePos = last && Math.abs(last.lon - ac.lon) < 1e-9 && Math.abs(last.lat - ac.lat) < 1e-9;
          if (!samePos) {
            item.samples.push({ t: sampleTime, lon: ac.lon, lat: ac.lat, heading: Number.isFinite(ac.heading) ? ac.heading : 0, data: ac });
          } else if (last) {
            last.t = sampleTime;
            last.data = ac;
            last.heading = Number.isFinite(ac.heading) ? ac.heading : last.heading;
          }
          const cutoff = sourceNowMs - SAMPLE_KEEP_MS;
          while (item.samples.length > 2 && item.samples[1].t < cutoff) item.samples.shift();
        }
      }

      function finishCycle(cycleSeen) {
        const staleBefore = Date.now() - STALE_MS;
        for (const [icao, item] of markers) {
          if (!cycleSeen.has(icao) && item.lastSeenAt < staleBefore) {
            item.marker.remove();
            markers.delete(icao);
            aircraftByIcao.delete(icao);
          }
        }
      }

      function renderBuffered(nowClientMs) {
        if (!enabled()) {
          for (const item of markers.values()) item.el.style.display = "none";
          return;
        }
        if (!haveSourceClock) return;
        const target = nowClientMs - sourceClockOffsetMs - RENDER_DELAY_MS;
        for (const item of markers.values()) {
          const samples = item.samples;
          if (!samples.length) { item.el.style.display = "none"; continue; }
          let before = null;
          let after = null;
          for (const s of samples) {
            if (s.t <= target) before = s;
            if (s.t >= target) { after = s; break; }
          }
          if (!before) { item.el.style.display = "none"; continue; }
          let shown;
          if (after && after !== before && after.t > before.t) {
            const t = Math.max(0, Math.min(1, (target - before.t) / (after.t - before.t)));
            shown = interpolate({ ...before.data, lon: before.lon, lat: before.lat, heading: before.heading }, { ...after.data, lon: after.lon, lat: after.lat, heading: after.heading }, t);
          } else {
            shown = extrapolate(before, Math.max(0, (target - before.t) / 1000));
          }
          item.rendered = shown;
          item.el.style.display = "";
          item.marker.setLngLat([shown.lon, shown.lat]);
          item.marker.setRotation(Number.isFinite(shown.heading) ? shown.heading : 0);
        }
      }

      function animation(ts) {
        if (!document.hidden && ts - lastFrame >= FRAME_MS) {
          lastFrame = ts;
          renderBuffered(Date.now());
        }
        requestAnimationFrame(animation);
      }

      function startAnimation() {
        if (animationStarted) return;
        animationStarted = true;
        requestAnimationFrame(animation);
      }

      function setHealth(state) {
        if (!health) return;
        const label = health.querySelector(".flight-health-label");
        health.classList.remove("is-loading", "is-ok", "is-error");
        if (state === "off") { health.hidden = true; return; }
        health.hidden = false;
        if (state === "ok") {
          health.classList.add("is-ok");
          if (label) label.textContent = "Güncel";
        } else if (state === "error") {
          health.classList.add("is-error");
          if (label) label.textContent = "Hata";
        } else {
          health.classList.add("is-loading");
          if (label) label.textContent = "Yükleniyor";
        }
      }

      function stopRequest() {
        requestVersion++;
        clearTimeout(timer);
        timer = null;
        controller?.abort();
        controller = null;
      }

      async function load() {
        stopRequest();
        if (!enabled() || document.hidden) return;
        if (map.getZoom() < MIN_ZOOM) { timer = setTimeout(load, 1200); return; }

        const version = requestVersion;
        const requestController = new AbortController();
        controller = requestController;
        const current = () => version === requestVersion && enabled() && !document.hidden && !requestController.signal.aborted;
        setHealth(haveSourceClock ? "ok" : "loading");

        const cycleSeen = new Set();
        let sourceStatus = {};

        try {
          const b = bbox(.35);
          const box = [b.south, b.north, b.west, b.east].map(v => Number(v).toFixed(6)).join(",");
          const r = await fetch(`${api.adsb}?box=${encodeURIComponent(box)}&stream=1`, {
            cache: "no-store",
            signal: requestController.signal,
            headers: { "Accept": "application/x-ndjson" }
          });
          if (!r.ok) throw new Error(`HTTP ${r.status}`);
          if (!r.body) throw new Error("Streaming response unavailable");

          const reader = r.body.getReader();
          const textDecoder = new TextDecoder();
          let pending = "";

          const handleLine = line => {
            if (!line || !current()) return;
            const event = JSON.parse(line);
            if (event.type === "batch") {
              sourceStatus[event.source] = Number(event.status) || 0;
              const rows = Array.isArray(event.aircraft) ? event.aircraft : [];
              if (rows.length) {
                ingestBatch(rows, Number(event.at) || Date.now(), cycleSeen);
                setStatus("LIVE");
                setHealth("ok");
                startAnimation();
              }
            } else if (event.type === "end") {
              sourceStatus = event.sourceStatus || sourceStatus;
            }
          };

          while (current()) {
            const { value, done } = await reader.read();
            if (done) break;
            pending += textDecoder.decode(value, { stream: true });
            let nl;
            while ((nl = pending.indexOf("\n")) !== -1) {
              const line = pending.slice(0, nl).trim();
              pending = pending.slice(nl + 1);
              if (line) handleLine(line);
            }
          }

          pending += textDecoder.decode();
          if (pending.trim() && current()) handleLine(pending.trim());
          if (!current()) return;

          finishCycle(cycleSeen);
          const anySourceOk = Object.values(sourceStatus).some(v => Number(v) === 200);
          if (!anySourceOk) throw new Error("No ADS-B source available");
          failures = 0;
          setStatus("LIVE");
          setHealth("ok");
        } catch (e) {
          if (current() && e?.name !== "AbortError") {
            failures = Math.min(failures + 1, 4);
            setStatus("ADS-B ERROR", true);
            setHealth("error");
          }
        } finally {
          if (current()) {
            controller = null;
            timer = setTimeout(load, Math.min(30000, REFRESH_MS * 2 ** failures));
          }
        }
      }

      function setVisibility() {
        for (const item of markers.values()) item.el.style.display = enabled() ? "" : "none";
      }

      function normalize(value) {
        return String(value || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
      }

      function searchLocal(q) {
        const needle = normalize(q);
        if (needle.length < 2) return [];
        return Array.from(aircraftByIcao.values()).map(ac => {
          const fields = [ac.reg, ac.callsign, ac.icao, ac.aircraftType].map(normalize).filter(Boolean);
          let score = 99;
          for (const field of fields) {
            if (field === needle) score = Math.min(score, 0);
            else if (field.startsWith(needle)) score = Math.min(score, 1);
            else if (field.includes(needle)) score = Math.min(score, 2);
          }
          if (score === 99) return null;
          return { kind: "aircraft", ident: ac.reg || ac.callsign || ac.icao, name: [ac.callsign, ac.aircraftType].filter(Boolean).join(" · "), hex: ac.icao, lon: ac.lon, lat: ac.lat, _score: score };
        }).filter(Boolean).sort((a, b) => a._score - b._score).slice(0, 12);
      }

      function select(hex) {
        const item = markers.get(String(hex || "").toLowerCase());
        if (!item) return;
        const shown = item.rendered || item.data;
        map.flyTo({ center: [shown.lon, shown.lat], zoom: Math.max(map.getZoom(), 9) });
        showAircraftCard(item);
      }

      enabledInput?.addEventListener("change", () => {
        setVisibility();
        if (enabled()) load();
        else { stopRequest(); setHealth("off"); }
      });

      document.addEventListener("visibilitychange", () => {
        if (document.hidden) stopRequest();
        else if (enabled()) load();
      });

      return {
        init() { startAnimation(); },
        setActive(value) {
          active = Boolean(value);
          if (active && enabledInput) enabledInput.checked = true;
          setVisibility();
          if (active) load();
          else { stopRequest(); setHealth("off"); }
        },
        refresh() { if (enabled()) load(); },
        searchLocal,
        select
      };
    }
  };
})();
