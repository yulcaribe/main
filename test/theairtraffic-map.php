<?php
declare(strict_types=1);

/*
 * Isolated TheAirTraffic -> MapLibre binding test.
 * This file intentionally lives under /test and does not depend on /main/api.
 */

if (isset($_GET['feed'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $box = $_GET['box'] ?? '';
    if (!preg_match('/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/', $box)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid box']);
        exit;
    }

    $parts = array_map('floatval', explode(',', $box));
    if (
        count($parts) !== 4 ||
        $parts[0] < -90 || $parts[0] > 90 ||
        $parts[1] < -90 || $parts[1] > 90 ||
        $parts[2] < -180 || $parts[2] > 180 ||
        $parts[3] < -180 || $parts[3] > 180 ||
        $parts[0] >= $parts[1]
    ) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Box out of range']);
        exit;
    }

    if (!function_exists('curl_init')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'cURL unavailable']);
        exit;
    }

    $target = 'https://globe.theairtraffic.com/re-api/?binCraft&zstd&box=' . $box;
    $ch = curl_init($target);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
        CURLOPT_REFERER => 'https://globe.theairtraffic.com/',
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Accept-Language: tr,en-US;q=0.9,en;q=0.8,ru;q=0.7,zh-CN;q=0.6,zh;q=0.5',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Priority: u=1, i',
            'Sec-CH-UA: "Chromium";v="154", "Google Chrome";v="154", "Not A(Brand";v="99"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "macOS"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error ?: 'Upstream request failed']);
        exit;
    }

    http_response_code($status ?: 502);
    header('X-Upstream-Status: ' . ($status ?: 0));
    header('X-Upstream-Content-Type: ' . ($type ?: 'unknown'));

    if ($status !== 200) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    header('Content-Type: application/zstd');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>TheAirTraffic MapLibre Test</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/maplibre-gl@4.7.1/dist/maplibre-gl.css">
<style>
    html,body,#map{width:100%;height:100%;margin:0}
    body{background:#071019;color:#eef6ff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;overflow:hidden}
    .panel{
        position:absolute;z-index:3;top:14px;left:14px;min-width:245px;max-width:min(390px,calc(100vw - 28px));
        padding:13px 15px;border:1px solid rgba(255,255,255,.15);border-radius:13px;
        background:rgba(5,13,21,.88);backdrop-filter:blur(10px);box-shadow:0 10px 30px rgba(0,0,0,.28)
    }
    .panel strong{display:block;font-size:15px;margin-bottom:7px}
    .row{display:flex;justify-content:space-between;gap:18px;font-size:12px;line-height:1.7;color:#aebdca}
    .row b{font-weight:700;color:#fff}
    .live{color:#77ef9a!important}
    .bad{color:#ff8585!important}
    .hint{margin-top:7px;padding-top:7px;border-top:1px solid rgba(255,255,255,.1);font-size:11px;color:#8194a5}
    .maplibregl-popup-content{
        background:#08131d;color:#eaf4fb;border:1px solid #263a4b;border-radius:10px;padding:12px 14px;
        box-shadow:0 12px 35px rgba(0,0,0,.35)
    }
    .maplibregl-popup-tip{border-top-color:#08131d!important}
    .ac-title{font-weight:800;font-size:15px;margin-bottom:6px}
    .aircraft-marker{
        width:24px;height:24px;display:flex;align-items:center;justify-content:center;
        filter:drop-shadow(0 1px 2px rgba(0,0,0,.75));
        cursor:pointer;
    }
    .aircraft-marker svg{width:24px;height:24px;display:block}
    .ac-grid{display:grid;grid-template-columns:auto auto;gap:3px 14px;font-size:12px}
    .ac-grid span:nth-child(odd){color:#8297a8}
</style>
</head>
<body>
<div id="map"></div>

<div class="panel">
    <strong>TheAirTraffic · MapLibre bind testi</strong>
    <div class="row"><span>Durum</span><b id="status">Başlatılıyor…</b></div>
    <div class="row"><span>Uçak</span><b id="count">0</b></div>
    <div class="row"><span>Son veri</span><b id="updated">—</b></div>
    <div class="row"><span>Payload</span><b id="payload">—</b></div>
    <div class="hint" id="hint">Harita görünümüne göre TheAirTraffic binCraft+zstd isteniyor.</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
<script src="https://cdn.jsdelivr.net/gh/wiedehopf/tar1090@master/html/libs/zstddec-tar1090-0.0.5.js"></script>
<script>
(() => {
    "use strict";

    const statusEl = document.getElementById("status");
    const countEl = document.getElementById("count");
    const updatedEl = document.getElementById("updated");
    const payloadEl = document.getElementById("payload");
    const hintEl = document.getElementById("hint");

    const SOURCE = "tat-aircraft";
    const REFRESH_MS = 2000;
    const MIN_FETCH_ZOOM = 4.2;
    const RENDER_DELAY_MS = 4200;
    const SAMPLE_KEEP_MS = 20000;
    const ANIMATION_FRAME_MS = 32;
    const FETCH_BOX_PADDING = 0.35;

    let decoder = null;
    let decoderReady = false;
    let aborter = null;
    let refreshTimer = null;
    let requestSeq = 0;
    let sourceClockOffsetMs = 0;
    let haveSourceClock = false;
    let lastAnimationFrame = 0;
    let activeFetchBox = null;
    const aircraftMarkers = new Map();

    const map = new maplibregl.Map({
        container: "map",
        center: [30.80, 36.90],
        zoom: 7,
        minZoom: 2,
        maxZoom: 15,
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
            layers: [{
                id: "osm-base",
                type: "raster",
                source: "osm",
                paint: {
                    "raster-saturation": -0.35,
                    "raster-brightness-min": 0.08,
                    "raster-brightness-max": 0.72,
                    "raster-contrast": 0.12
                }
            }]
        }
    });

    map.addControl(new maplibregl.NavigationControl({ showCompass: true }), "bottom-left");
    map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: "nautical" }), "bottom-left");

    function setStatus(text, ok = null) {
        statusEl.textContent = text;
        statusEl.className = ok === true ? "live" : ok === false ? "bad" : "";
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
            const u32 = new Uint32Array(buffer, off, stride / 4);
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
            let alt = s16[10] * 25;
            let gs = s16[17] / 10;
            let track = s16[20] / 90;
            let magHeading = s16[22] / 90;
            let trueHeading = s16[23] / 90;
            let baroRate = s16[8] * 8;

            const validity1 = u8[73];
            const validity2 = u8[74];
            const validity3 = u8[75];

            const flight = (validity1 & 8) ? readAscii(u8, 78, 86) : "";
            const typeCode = readAscii(u8, 88, 92);
            const registration = readAscii(u8, 92, 104);

            if (!(validity1 & 16)) alt = null;
            if (!(validity1 & 64)) {
                lat = null;
                lon = null;
                seenPos = null;
            }
            if (!(validity1 & 128)) gs = null;
            if (!(validity2 & 8)) track = null;
            if (!(validity2 & 64)) magHeading = null;
            if (!(validity2 & 128)) trueHeading = null;
            if (!(validity3 & 1)) baroRate = null;

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
                hex, flight, registration, typeCode, type,
                lat, lon, alt, gs, track, heading, baroRate,
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

    function aircraftPopupHtml(ac) {
        const track = Number(ac.track);
        const vr = Number(ac.baroRate);
        const altLabel = ac.alt === "ground" ? "GND" : (Number.isFinite(ac.alt) ? Math.round(ac.alt) + " ft" : "—");
        const gsLabel = Number.isFinite(ac.gs) ? Math.round(ac.gs) + " kt" : "—";
        return `
            <div class="ac-title">${escapeHtml(ac.flight || ac.registration || ac.hex || "Aircraft")}</div>
            <div class="ac-grid">
                <span>Hex</span><b>${escapeHtml((ac.hex || "").toUpperCase())}</b>
                <span>Reg</span><b>${escapeHtml(ac.registration || "—")}</b>
                <span>Type</span><b>${escapeHtml(ac.typeCode || "—")}</b>
                <span>Altitude</span><b>${escapeHtml(altLabel)}</b>
                <span>Speed</span><b>${escapeHtml(gsLabel)}</b>
                <span>Track</span><b>${Number.isFinite(track) ? track.toFixed(0) + "°" : "—"}</b>
                <span>V/S</span><b>${Number.isFinite(vr) ? Math.round(vr) + " ft/min" : "—"}</b>
                <span>Source</span><b>${escapeHtml(ac.type || "—")}</b>
            </div>`;
    }

    function createAircraftElement() {
        const el = document.createElement("div");
        el.className = "aircraft-marker";
        el.innerHTML = `
            <svg viewBox="0 0 64 64" aria-hidden="true">
                <path d="M32 2 L37 25 L58 33 L58 39 L37 36 L36 50 L44 56 L44 60 L32 56 L20 60 L20 56 L28 50 L27 36 L6 39 L6 33 L27 25 Z"
                      fill="#f5fbff" stroke="#071019" stroke-width="3" stroke-linejoin="round"/>
            </svg>`;
        return el;
    }

    function shortestAngle(a, b) {
        let delta = ((b - a + 540) % 360) - 180;
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

        const popup = new maplibregl.Popup({ closeButton: true, offset: 16 });
        const marker = new maplibregl.Marker({
            element: el,
            rotationAlignment: "map",
            pitchAlignment: "map"
        })
            .setLngLat([ac.lon, ac.lat])
            .setRotation(Number.isFinite(ac.heading) ? ac.heading : 0)
            .addTo(map);

        item = {
            marker,
            popup,
            el,
            data: ac,
            rendered: ac,
            samples: [],
            lastSeenAt: Date.now()
        };

        el.addEventListener("click", (event) => {
            event.stopPropagation();
            const current = aircraftMarkers.get(ac.hex);
            if (!current) return;
            const shown = current.rendered || current.data;
            current.popup
                .setLngLat([shown.lon, shown.lat])
                .setHTML(aircraftPopupHtml(current.data))
                .addTo(map);
        });

        aircraftMarkers.set(ac.hex, item);
        return item;
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
            const samePosition = last &&
                Math.abs(last.lon - ac.lon) < 1e-9 &&
                Math.abs(last.lat - ac.lat) < 1e-9;

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

            const cutoff = sourceNowMs - SAMPLE_KEEP_MS;
            while (item.samples.length > 2 && item.samples[1].t < cutoff) {
                item.samples.shift();
            }
        }

        const staleClientCutoff = Date.now() - 15000;
        for (const [hex, item] of aircraftMarkers) {
            if (!seenNow.has(hex) && item.lastSeenAt < staleClientCutoff) {
                item.popup.remove();
                item.marker.remove();
                aircraftMarkers.delete(hex);
            }
        }
    }

    function renderBufferedAircraft(nowClientMs) {
        if (!haveSourceClock) return;

        const targetSourceTime = nowClientMs - sourceClockOffsetMs - RENDER_DELAY_MS;

        for (const item of aircraftMarkers.values()) {
            const samples = item.samples;
            if (!samples.length) {
                item.el.style.display = "none";
                continue;
            }

            let before = null;
            let after = null;

            for (let i = 0; i < samples.length; i++) {
                const s = samples[i];
                if (s.t <= targetSourceTime) before = s;
                if (s.t >= targetSourceTime) {
                    after = s;
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
                shown = {
                    ...before.data,
                    lon: before.lon,
                    lat: before.lat,
                    heading: before.heading
                };
            }

            item.rendered = shown;
            item.el.style.display = "";
            item.marker.setLngLat([shown.lon, shown.lat]);

            if (typeof item.marker.setRotation === "function") {
                item.marker.setRotation(Number.isFinite(shown.heading) ? shown.heading : 0);
            }

            if (item.popup.isOpen()) {
                item.popup.setLngLat([shown.lon, shown.lat]);
            }
        }
    }

    function animationLoop(ts) {
        if (ts - lastAnimationFrame >= ANIMATION_FRAME_MS) {
            lastAnimationFrame = ts;
            renderBufferedAircraft(Date.now());
        }
        requestAnimationFrame(animationLoop);
    }

    function currentViewBox() {
        const b = map.getBounds();
        return {
            south: b.getSouth(),
            north: b.getNorth(),
            west: b.getWest(),
            east: b.getEast()
        };
    }

    function paddedFetchBox() {
        const view = currentViewBox();
        const latSpan = Math.max(0.05, view.north - view.south);
        const lonSpan = Math.max(0.05, view.east - view.west);

        return {
            south: Math.max(-90, view.south - latSpan * FETCH_BOX_PADDING),
            north: Math.min(90, view.north + latSpan * FETCH_BOX_PADDING),
            west: Math.max(-180, view.west - lonSpan * FETCH_BOX_PADDING),
            east: Math.min(180, view.east + lonSpan * FETCH_BOX_PADDING)
        };
    }

    function viewFitsInsideFetchBox() {
        if (!activeFetchBox) return false;
        const view = currentViewBox();

        return (
            view.south >= activeFetchBox.south &&
            view.north <= activeFetchBox.north &&
            view.west >= activeFetchBox.west &&
            view.east <= activeFetchBox.east
        );
    }

    function ensureFetchBox(force = false) {
        if (force || !activeFetchBox || !viewFitsInsideFetchBox()) {
            activeFetchBox = paddedFetchBox();
            return true;
        }
        return false;
    }

    function boxString() {
        ensureFetchBox(false);
        return [
            activeFetchBox.south.toFixed(6),
            activeFetchBox.north.toFixed(6),
            activeFetchBox.west.toFixed(6),
            activeFetchBox.east.toFixed(6)
        ].join(",");
    }

    async function refresh() {
        clearTimeout(refreshTimer);

        if (!decoderReady || !map.loaded()) {
            refreshTimer = setTimeout(refresh, 600);
            return;
        }

        if (map.getZoom() < MIN_FETCH_ZOOM) {
            setStatus("Biraz yaklaş", null);
            hintEl.textContent = "Canlı veri için zoom " + MIN_FETCH_ZOOM.toFixed(1) + "+ gerekli.";
            refreshTimer = setTimeout(refresh, 1200);
            return;
        }

        const seq = ++requestSeq;
        if (aborter) aborter.abort();
        aborter = new AbortController();

        const box = boxString();
        setStatus("Veri alınıyor…", null);

        try {
            const res = await fetch("?feed=1&box=" + encodeURIComponent(box), {
                cache: "no-store",
                signal: aborter.signal
            });

            if (!res.ok) {
                const detail = await res.text();
                throw new Error("HTTP " + res.status + " " + detail.slice(0, 100));
            }

            const compressed = new Uint8Array(await res.arrayBuffer());
            if (seq !== requestSeq) return;

            const decoded = decoder.decode(compressed);
            const parsed = parseBinCraft(decoded);

            const sourceNowMs = parsed.now * 1000;
            const measuredOffset = Date.now() - sourceNowMs;
            if (!haveSourceClock) {
                sourceClockOffsetMs = measuredOffset;
                haveSourceClock = true;
            } else {
                sourceClockOffsetMs = sourceClockOffsetMs * 0.85 + measuredOffset * 0.15;
            }

            ingestAircraftSnapshot(parsed.aircraft, sourceNowMs);

            countEl.textContent = String(parsed.aircraft.length);
            payloadEl.textContent = compressed.byteLength + " B → " + decoded.byteLength + " B";
            updatedEl.textContent = new Date().toLocaleTimeString("tr-TR");
            hintEl.textContent = "binCraft v" + parsed.version + " · " + (RENDER_DELAY_MS / 1000).toFixed(1) + " sn buffer · sticky bbox · global " + parsed.globalCount;
            setStatus("CANLI", true);
        } catch (err) {
            if (err && err.name === "AbortError") return;
            console.error(err);
            setStatus("HATA", false);
            hintEl.textContent = err && err.message ? err.message : String(err);
        } finally {
            refreshTimer = setTimeout(refresh, REFRESH_MS);
        }
    }

    async function initDecoder() {
        if (!window.zstddec || !window.zstddec.ZSTDDecoder) {
            throw new Error("zstd decoder yüklenemedi");
        }
        decoder = new zstddec.ZSTDDecoder();
        await decoder.init();
        decoderReady = true;
    }

    map.on("load", async () => {
        try {
            setStatus("Decoder hazırlanıyor…");
            await initDecoder();
            setStatus("Hazır");
            ensureFetchBox(true);
            requestAnimationFrame(animationLoop);
            refresh();
        } catch (err) {
            console.error(err);
            setStatus("DECODER HATASI", false);
            hintEl.textContent = err.message || String(err);
        }
    });

    map.on("moveend", () => {
        if (!decoderReady) return;

        // Zooming or panning inside the already-fetched coverage must not
        // restart the aircraft stream. Keep the current bbox and RAM samples.
        if (!viewFitsInsideFetchBox()) {
            ensureFetchBox(true);
            refresh();
        }
    });

    function escapeHtml(value) {
        return String(value ?? "").replace(/[&<>"']/g, ch => ({
            "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
        })[ch]);
    }
})();
</script>
</body>
</html>
