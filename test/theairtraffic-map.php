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

    let decoder = null;
    let decoderReady = false;
    let aborter = null;
    let refreshTimer = null;
    let requestSeq = 0;

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

    function makeAircraftIcon() {
        const canvas = document.createElement("canvas");
        canvas.width = 64;
        canvas.height = 64;
        const c = canvas.getContext("2d");

        c.translate(32, 32);
        c.beginPath();
        c.moveTo(0, -29);
        c.lineTo(5, -7);
        c.lineTo(25, 1);
        c.lineTo(25, 7);
        c.lineTo(5, 4);
        c.lineTo(4, 19);
        c.lineTo(12, 24);
        c.lineTo(12, 28);
        c.lineTo(0, 25);
        c.lineTo(-12, 28);
        c.lineTo(-12, 24);
        c.lineTo(-4, 19);
        c.lineTo(-5, 4);
        c.lineTo(-25, 7);
        c.lineTo(-25, 1);
        c.lineTo(-5, -7);
        c.closePath();

        c.fillStyle = "#f5fbff";
        c.strokeStyle = "#071019";
        c.lineWidth = 3;
        c.lineJoin = "round";
        c.fill();
        c.stroke();

        return c.getImageData(0, 0, canvas.width, canvas.height);
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

    function geojsonFor(data) {
        return {
            type: "FeatureCollection",
            features: data.aircraft.map(ac => ({
                type: "Feature",
                id: ac.hex,
                geometry: { type: "Point", coordinates: [ac.lon, ac.lat] },
                properties: {
                    ...ac,
                    altLabel: ac.alt === "ground" ? "GND" : (Number.isFinite(ac.alt) ? Math.round(ac.alt) + " ft" : "—"),
                    gsLabel: Number.isFinite(ac.gs) ? Math.round(ac.gs) + " kt" : "—",
                    label: ac.flight || ac.registration || ac.hex.toUpperCase()
                }
            }))
        };
    }

    function boxString() {
        const b = map.getBounds();
        return [
            b.getSouth().toFixed(6),
            b.getNorth().toFixed(6),
            b.getWest().toFixed(6),
            b.getEast().toFixed(6)
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
            const fc = geojsonFor(parsed);

            const source = map.getSource(SOURCE);
            if (source) source.setData(fc);

            countEl.textContent = String(fc.features.length);
            payloadEl.textContent = compressed.byteLength + " B → " + decoded.byteLength + " B";
            updatedEl.textContent = new Date().toLocaleTimeString("tr-TR");
            hintEl.textContent = "binCraft v" + parsed.version + " · stride " + parsed.stride + " · global " + parsed.globalCount;
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
        map.addImage("aircraft", makeAircraftIcon(), { pixelRatio: 2 });

        map.addSource(SOURCE, {
            type: "geojson",
            data: { type: "FeatureCollection", features: [] }
        });

        map.addLayer({
            id: "tat-aircraft-icons",
            type: "symbol",
            source: SOURCE,
            layout: {
                "icon-image": "aircraft",
                "icon-size": [
                    "interpolate", ["linear"], ["zoom"],
                    4, 0.42,
                    8, 0.55,
                    12, 0.72
                ],
                "icon-allow-overlap": true,
                "icon-ignore-placement": true,
                "icon-rotate": ["coalesce", ["to-number", ["get", "heading"]], 0],
                "icon-rotation-alignment": "map",
                "text-field": ["get", "label"],
                "text-font": ["Open Sans Bold"],
                "text-size": 11,
                "text-offset": [0, 1.8],
                "text-anchor": "top",
                "text-optional": true,
                "text-allow-overlap": false
            },
            paint: {
                "text-color": "#f1f8fc",
                "text-halo-color": "#071019",
                "text-halo-width": 1.5
            }
        });

        map.on("click", "tat-aircraft-icons", e => {
            const f = e.features && e.features[0];
            if (!f) return;
            const p = f.properties || {};
            const track = Number(p.track);
            const vr = Number(p.baroRate);
            const html = `
                <div class="ac-title">${escapeHtml(p.flight || p.registration || p.hex || "Aircraft")}</div>
                <div class="ac-grid">
                    <span>Hex</span><b>${escapeHtml((p.hex || "").toUpperCase())}</b>
                    <span>Reg</span><b>${escapeHtml(p.registration || "—")}</b>
                    <span>Type</span><b>${escapeHtml(p.typeCode || "—")}</b>
                    <span>Altitude</span><b>${escapeHtml(p.altLabel || "—")}</b>
                    <span>Speed</span><b>${escapeHtml(p.gsLabel || "—")}</b>
                    <span>Track</span><b>${Number.isFinite(track) ? track.toFixed(0) + "°" : "—"}</b>
                    <span>V/S</span><b>${Number.isFinite(vr) ? Math.round(vr) + " ft/min" : "—"}</b>
                    <span>Source</span><b>${escapeHtml(p.type || "—")}</b>
                </div>`;
            new maplibregl.Popup({ closeButton: true })
                .setLngLat(f.geometry.coordinates)
                .setHTML(html)
                .addTo(map);
        });

        map.on("mouseenter", "tat-aircraft-icons", () => map.getCanvas().style.cursor = "pointer");
        map.on("mouseleave", "tat-aircraft-icons", () => map.getCanvas().style.cursor = "");

        try {
            setStatus("Decoder hazırlanıyor…");
            await initDecoder();
            setStatus("Hazır");
            refresh();
        } catch (err) {
            console.error(err);
            setStatus("DECODER HATASI", false);
            hintEl.textContent = err.message || String(err);
        }
    });

    map.on("moveend", () => {
        if (decoderReady) refresh();
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
