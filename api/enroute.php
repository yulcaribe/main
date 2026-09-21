<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

const AWC_BASE = 'https://aviationweather.gov/api/data/';
const USER_AGENT = 'YulCaribe-Aviation/1.0 (+https://yulcaribe.com)';

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_enroute_v1';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function cacheGet(string $key, int $maxAge): ?array {
    $file = cacheDir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['savedAt'], $data['payload'])) return null;
    $age = time() - (int)$data['savedAt'];
    if ($age < 0 || $age > $maxAge || !is_array($data['payload'])) return null;
    $data['payload']['cache'] = ['hit' => true, 'ageSeconds' => $age];
    return $data['payload'];
}

function cachePut(string $key, array $payload): void {
    $file = cacheDir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    @file_put_contents($file, json_encode(['savedAt' => time(), 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function awcGet(string $path, array $query = [], int $timeout = 12): array {
    $url = AWC_BASE . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $attempt = function(bool $verifyPeer) use ($url, $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/geo+json;q=0.9, */*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return compact('body', 'errno', 'error', 'status');
    };

    $r = $attempt(true);
    if ($r['errno'] !== 0 && stripos((string)$r['error'], 'certificate has expired') !== false) {
        $r = $attempt(false);
    }

    if ($r['status'] === 204) return ['ok' => true, 'status' => 204, 'data' => []];
    if ($r['errno'] !== 0 || $r['body'] === false || $r['status'] < 200 || $r['status'] >= 300) {
        return ['ok' => false, 'status' => $r['status'], 'error' => $r['error'] ?: ('HTTP ' . $r['status']), 'url' => $url];
    }

    $data = json_decode((string)$r['body'], true);
    if ($data === null && trim((string)$r['body']) !== 'null') {
        return ['ok' => false, 'status' => $r['status'], 'error' => 'AWC JSON yanıtı çözülemedi.', 'url' => $url];
    }

    return ['ok' => true, 'status' => $r['status'], 'data' => $data];
}

function rad(float $d): float { return $d * M_PI / 180.0; }
function deg(float $r): float { return $r * 180.0 / M_PI; }

function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 3440.065;
    $p1 = rad($lat1); $p2 = rad($lat2);
    $dp = rad($lat2 - $lat1); $dl = rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $r * (2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
}

function greatCircle(float $lat1, float $lon1, float $lat2, float $lon2, int $count): array {
    $p1 = rad($lat1); $l1 = rad($lon1); $p2 = rad($lat2); $l2 = rad($lon2);
    $delta = 2 * asin(sqrt(sin(($p2-$p1)/2)**2 + cos($p1)*cos($p2)*sin(($l2-$l1)/2)**2));
    if ($delta < 1e-9) return [[$lat1, $lon1], [$lat2, $lon2]];

    $pts = [];
    for ($i = 0; $i < $count; $i++) {
        $f = $i / ($count - 1);
        $a = sin((1-$f)*$delta) / sin($delta);
        $b = sin($f*$delta) / sin($delta);
        $x = $a*cos($p1)*cos($l1) + $b*cos($p2)*cos($l2);
        $y = $a*cos($p1)*sin($l1) + $b*cos($p2)*sin($l2);
        $z = $a*sin($p1) + $b*sin($p2);
        $lat = atan2($z, sqrt($x*$x + $y*$y));
        $lon = atan2($y, $x);
        $pts[] = [round(deg($lat), 5), round(deg($lon), 5)];
    }
    return $pts;
}

function nearestRoute(array $route, float $lat, float $lon): array {
    $best = INF; $idx = 0;
    foreach ($route as $i => $p) {
        $d = haversineNm($lat, $lon, (float)$p[0], (float)$p[1]);
        if ($d < $best) { $best = $d; $idx = $i; }
    }
    $progress = count($route) > 1 ? $idx / (count($route) - 1) : 0.0;
    return [$best, $progress];
}

function normalizeStation(array $s, array $route): ?array {
    $icao = strtoupper((string)($s['icaoId'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) return null;
    if (!isset($s['lat'], $s['lon']) || !is_numeric($s['lat']) || !is_numeric($s['lon'])) return null;
    [$routeDistance, $progress] = nearestRoute($route, (float)$s['lat'], (float)$s['lon']);
    $types = array_map('strtoupper', is_array($s['siteType'] ?? null) ? $s['siteType'] : []);
    return [
        'icao' => $icao,
        'name' => (string)($s['site'] ?? $s['name'] ?? $icao),
        'lat' => (float)$s['lat'],
        'lon' => (float)$s['lon'],
        'country' => (string)($s['country'] ?? ''),
        'state' => (string)($s['state'] ?? ''),
        'priority' => is_numeric($s['priority'] ?? null) ? (int)$s['priority'] : 99,
        'hasMetar' => in_array('METAR', $types, true),
        'hasTaf' => in_array('TAF', $types, true),
        'routeDistanceNm' => round($routeDistance, 1),
        'progress' => round($progress, 4),
    ];
}

function selectStations(array $candidates, float $distanceNm, int $maxIntermediate = 6): array {
    $slots = max(2, min($maxIntermediate, (int)ceil($distanceNm / 450)));
    $selected = [];

    for ($b = 0; $b < $slots; $b++) {
        $center = ($b + 1) / ($slots + 1);
        $best = null; $bestScore = INF;
        foreach ($candidates as $c) {
            if ($c['progress'] < 0.05 || $c['progress'] > 0.95) continue;
            if (!$c['hasMetar'] && !$c['hasTaf']) continue;
            if (isset($selected[$c['icao']])) continue;
            $score = abs($c['progress'] - $center) * 420
                + $c['routeDistanceNm']
                + ($c['hasTaf'] ? 0 : 55)
                + min(60, max(0, $c['priority'])) * 0.8;
            if ($score < $bestScore) { $bestScore = $score; $best = $c; }
        }
        if ($best) $selected[$best['icao']] = $best;
    }

    $out = array_values($selected);
    usort($out, fn($a,$b) => $a['progress'] <=> $b['progress']);
    return $out;
}

function mapByIcao(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $icao = strtoupper((string)($row['icaoId'] ?? ''));
        if ($icao !== '') $out[$icao] = $row;
    }
    return $out;
}

$from = strtoupper(trim((string)($_GET['from'] ?? '')));
$to = strtoupper(trim((string)($_GET['to'] ?? '')));
$corridor = (int)($_GET['corridor'] ?? 90);
$corridor = max(40, min(180, $corridor));

if (!preg_match('/^[A-Z0-9]{4}$/', $from) || !preg_match('/^[A-Z0-9]{4}$/', $to) || $from === $to) {
    respond(400, ['ok' => false, 'error' => 'Geçerli ve farklı iki ICAO kodu girin. Örnek: LTAI → EDDB.']);
}
if (!function_exists('curl_init')) respond(500, ['ok' => false, 'error' => 'PHP cURL aktif değil.']);

$cacheKey = "{$from}|{$to}|{$corridor}";
if ($cached = cacheGet($cacheKey, 300)) respond(200, $cached);

$airportRes = awcGet('airport', ['ids' => $from . ',' . $to, 'format' => 'json']);
if (!$airportRes['ok'] || !is_array($airportRes['data'])) {
    respond(502, ['ok' => false, 'error' => 'Havalimanı bilgileri AviationWeather.gov üzerinden alınamadı.', 'detail' => $airportRes['error'] ?? null]);
}

$airports = mapByIcao($airportRes['data']);
if (!isset($airports[$from], $airports[$to])) {
    respond(404, ['ok' => false, 'error' => 'ICAO kodlarından biri AviationWeather.gov havalimanı verisinde bulunamadı.']);
}

$a = $airports[$from]; $b = $airports[$to];
$lat1 = (float)$a['lat']; $lon1 = (float)$a['lon'];
$lat2 = (float)$b['lat']; $lon2 = (float)$b['lon'];
$distanceNm = haversineNm($lat1, $lon1, $lat2, $lon2);
$routeCount = max(24, min(96, (int)ceil($distanceNm / 35) + 1));
$route = greatCircle($lat1, $lon1, $lat2, $lon2, $routeCount);

$probeCount = max(4, min(12, (int)ceil($distanceNm / 300) + 1));
$probeRoute = greatCircle($lat1, $lon1, $lat2, $lon2, $probeCount);
$stationPool = [];
$searchNm = min(200, max(100, $corridor + 65));

foreach ($probeRoute as $p) {
    $lat = (float)$p[0]; $lon = (float)$p[1];
    $latDelta = $searchNm / 60.0;
    $cos = max(0.18, abs(cos(rad($lat))));
    $lonDelta = $searchNm / (60.0 * $cos);
    $bbox = sprintf('%.3f,%.3f,%.3f,%.3f', max(-90,$lat-$latDelta), max(-180,$lon-$lonDelta), min(90,$lat+$latDelta), min(180,$lon+$lonDelta));
    $res = awcGet('stationinfo', ['bbox' => $bbox, 'format' => 'json']);
    if (!$res['ok'] || !is_array($res['data'])) continue;
    foreach ($res['data'] as $row) {
        if (!is_array($row)) continue;
        $n = normalizeStation($row, $route);
        if (!$n) continue;
        if ($n['routeDistanceNm'] > $corridor + 35) continue;
        $stationPool[$n['icao']] = $n;
    }
}

$intermediate = selectStations(array_values($stationPool), $distanceNm, 6);

$endpointStationsRes = awcGet('stationinfo', ['ids' => $from . ',' . $to, 'format' => 'json']);
$endpointStationMap = $endpointStationsRes['ok'] && is_array($endpointStationsRes['data']) ? mapByIcao($endpointStationsRes['data']) : [];

$fromStation = isset($endpointStationMap[$from]) ? normalizeStation($endpointStationMap[$from], $route) : null;
$toStation = isset($endpointStationMap[$to]) ? normalizeStation($endpointStationMap[$to], $route) : null;

$fromBase = [
    'icao' => $from, 'name' => (string)($a['name'] ?? $from), 'lat' => $lat1, 'lon' => $lon1,
    'country' => (string)($a['country'] ?? ''), 'state' => (string)($a['state'] ?? ''),
    'hasMetar' => $fromStation['hasMetar'] ?? true, 'hasTaf' => $fromStation['hasTaf'] ?? true,
    'routeDistanceNm' => 0.0, 'progress' => 0.0, 'role' => 'departure'
];
$toBase = [
    'icao' => $to, 'name' => (string)($b['name'] ?? $to), 'lat' => $lat2, 'lon' => $lon2,
    'country' => (string)($b['country'] ?? ''), 'state' => (string)($b['state'] ?? ''),
    'hasMetar' => $toStation['hasMetar'] ?? true, 'hasTaf' => $toStation['hasTaf'] ?? true,
    'routeDistanceNm' => 0.0, 'progress' => 1.0, 'role' => 'arrival'
];
foreach ($intermediate as &$s) $s['role'] = 'enroute'; unset($s);
$stations = array_merge([$fromBase], $intermediate, [$toBase]);

$ids = implode(',', array_values(array_unique(array_column($stations, 'icao'))));
$metarRes = awcGet('metar', ['ids' => $ids, 'format' => 'json']);
$tafRes = awcGet('taf', ['ids' => $ids, 'format' => 'json']);
$metarMap = $metarRes['ok'] && is_array($metarRes['data']) ? mapByIcao($metarRes['data']) : [];
$tafMap = $tafRes['ok'] && is_array($tafRes['data']) ? mapByIcao($tafRes['data']) : [];

foreach ($stations as &$s) {
    $icao = $s['icao'];
    $m = $metarMap[$icao] ?? null;
    $t = $tafMap[$icao] ?? null;
    $s['metar'] = is_array($m) ? [
        'raw' => $m['rawOb'] ?? null,
        'obsTime' => $m['obsTime'] ?? null,
        'flightCategory' => $m['fltCat'] ?? null,
        'windDirection' => $m['wdir'] ?? null,
        'windSpeedKt' => $m['wspd'] ?? null,
        'windGustKt' => $m['wgst'] ?? null,
        'visibilitySm' => $m['visib'] ?? null,
        'weather' => $m['wxString'] ?? null,
    ] : null;
    $s['taf'] = is_array($t) ? [
        'raw' => $t['rawTAF'] ?? null,
        'issueTime' => $t['issueTime'] ?? null,
        'validFrom' => $t['validTimeFrom'] ?? null,
        'validTo' => $t['validTimeTo'] ?? null,
    ] : null;
}
unset($s);

$sigmetRes = awcGet('isigmet', ['format' => 'geojson'], 15);
$sigmets = $sigmetRes['ok'] && is_array($sigmetRes['data']) ? $sigmetRes['data'] : ['type' => 'FeatureCollection', 'features' => []];
if (($sigmets['type'] ?? '') !== 'FeatureCollection') $sigmets = ['type' => 'FeatureCollection', 'features' => []];

$payload = [
    'ok' => true,
    'source' => 'NOAA/NWS Aviation Weather Center',
    'fetchedAt' => gmdate('c'),
    'routeMode' => 'great_circle',
    'operationalRoute' => false,
    'corridorNm' => $corridor,
    'distanceNm' => round($distanceNm, 0),
    'from' => $fromBase,
    'to' => $toBase,
    'route' => $route,
    'stations' => $stations,
    'sigmets' => $sigmets,
    'cache' => ['hit' => false, 'ageSeconds' => 0],
    'notes' => [
        'Kalkış ve varış meydanları her zaman dahil edilir.',
        'Yolboyunda her havalimanı değil, rota koridoruna yakın temsilci METAR/TAF istasyonları seçilir.',
        'Haritadaki rota büyük daire hattıdır; operasyonel flight plan rotası değildir.'
    ]
];

cachePut($cacheKey, $payload);
respond(200, $payload);