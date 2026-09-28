<?php
declare(strict_types=1);

// These browser-origin checks are defence in depth, not client authentication.
function ycRejectRequest(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ycSameOrigin(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'yulcaribe.com'
        && (!isset($parts['port']) || $parts['port'] === 443)
        && !isset($parts['user']) && !isset($parts['pass']);
}
header('Vary: Origin, Sec-Fetch-Site, Referer');
header('Cross-Origin-Resource-Policy: same-origin');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if (($fetchSite !== '' && $fetchSite !== 'same-origin')
    || ($origin !== '' ? !in_array($origin, ['https://yulcaribe.com','https://yulcaribe.com:443'], true)
        : !ycSameOrigin($referer))) {
    ycRejectRequest(403, 'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.');
}
foreach ($_GET as $value) {
    if (!is_string($value) || strlen($value) > 2048 || str_contains($value, "\0")) {
        ycRejectRequest(400, 'Geçersiz veya çok uzun istek parametresi.');
    }
}
if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET'], true)) {
    header('Allow: GET');
    ycRejectRequest(405, 'HTTP method not allowed.');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-YC-API-Resource: metartaf');

function out(int $status, array $payload): never {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('[metartaf] JSON response: '.json_last_error_msg());
        $status = 500;
        $json = '{"ok":false,"error":"Response could not be encoded."}';
    }
    http_response_code($status);
    if ($status >= 400) header('Cache-Control: no-store, max-age=0');
    echo $json;
    exit;
}

function boolParam(string $key, bool $default): bool {
    if (!array_key_exists($key, $_GET)) return $default;
    return in_array(strtolower(trim((string)$_GET[$key])), ['1','true','yes','on'], true);
}

function stationTokens(): array {
    $raw = trim((string)($_GET['stations'] ?? $_GET['icao'] ?? ''));
    if ($raw === '') out(400, ['ok'=>false,'error'=>'ICAO veya IATA kodu gerekli.']);
    $parts = preg_split('/[\s,;]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $parts = array_values(array_unique($parts));
    if (count($parts) > 10) out(400, ['ok'=>false,'error'=>'En fazla 10 istasyon sorgulanabilir.']);
    foreach ($parts as $code) {
        if (!preg_match('/^[A-Z0-9]{3,4}$/', $code)) out(400, ['ok'=>false,'error'=>'Geçersiz ICAO/IATA kodu: '.$code]);
    }
    return $parts;
}

function navDb(): ?PDO {
    static $loaded = false;
    static $pdo = null;
    if ($loaded) return $pdo;
    $loaded = true;
    if (!extension_loaded('pdo_mysql')) return null;
    $path = dirname(__DIR__, 3) . '/data.php';
    if (!is_file($path)) return null;
    try {
        $cfg = require $path;
        if (!is_array($cfg)) return null;
        foreach (['host','port','database','user','password'] as $key) if (!array_key_exists($key, $cfg)) return null;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['database']),
            $cfg['user'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
        );
    } catch (Throwable $e) {
        error_log('[metartaf] nav db: '.$e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

function resolveStation(string $input): array {
    $pdo = navDb();
    $row = null;
    if ($pdo instanceof PDO) {
        try {
            if (strlen($input) === 3) {
                $stmt = $pdo->prepare("SELECT ident,iata,name,city FROM nav_points WHERE kind='airport' AND UPPER(COALESCE(iata,''))=:code ORDER BY ident LIMIT 1");
            } else {
                $stmt = $pdo->prepare("SELECT ident,iata,name,city FROM nav_points WHERE kind='airport' AND UPPER(ident)=:code ORDER BY ident LIMIT 1");
            }
            $stmt->execute(['code'=>$input]);
            $row = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[metartaf] airport resolve '.$input.': '.$e->getMessage());
        }
    }

    if (strlen($input) === 3) {
        if (!$row) return ['input'=>$input,'icao'=>null,'iata'=>$input,'name'=>null,'city'=>null,'error'=>'Havalimanı bulunamadı.'];
        return ['input'=>$input,'icao'=>strtoupper((string)$row['ident']),'iata'=>strtoupper((string)$row['iata']),'name'=>$row['name'] ?: null,'city'=>$row['city'] ?: null,'error'=>null];
    }

    return [
        'input'=>$input,
        'icao'=>$input,
        'iata'=>$row && $row['iata'] ? strtoupper((string)$row['iata']) : null,
        'name'=>$row['name'] ?? null,
        'city'=>$row['city'] ?? null,
        'error'=>null,
    ];
}

function cacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_metartaf';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}
function batchCacheFile(string $product, array $icaos): string {
    return cacheDir() . DIRECTORY_SEPARATOR . $product . '_' . sha1(implode(',', $icaos)) . '.json';
}
function cacheReadBatch(string $product, array $icaos, int $ttl): ?array {
    $file = batchCacheFile($product, $icaos);
    if (!is_file($file)) return null;
    $age = time() - (int)@filemtime($file);
    if ($age < 0 || $age > $ttl) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return null;
    $data['cacheHit'] = true;
    return $data;
}
function cacheWriteBatch(string $product, array $icaos, array $payload): void {
    @file_put_contents(batchCacheFile($product, $icaos), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function fetchAwcBatch(string $product, array $icaos, int $ttl): array {
    if (!$icaos) return ['ok'=>true,'raw'=>'','source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
    if ($cached = cacheReadBatch($product, $icaos, $ttl)) return $cached;
    if (!function_exists('curl_init')) return ['ok'=>false,'raw'=>'','source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];

    $url = 'https://aviationweather.gov/api/data/' . rawurlencode($product)
        . '?ids=' . rawurlencode(implode(',', $icaos)) . '&format=raw';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'YulCaribe/1.0 (+https://yulcaribe.com)',
        CURLOPT_HTTPHEADER => ['Accept: text/plain, */*;q=0.8'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0 || $body === false || ($status !== 204 && ($status < 200 || $status >= 300))) {
        error_log('[metartaf] '.$product.' '.implode(',', $icaos).' upstream failed: HTTP '.$status.' '.$error);
        return ['ok'=>false,'raw'=>'','source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
    }
    $payload = ['ok'=>true,'raw'=>trim((string)$body),'source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
    cacheWriteBatch($product, $icaos, $payload);
    return $payload;
}

function splitReports(string $raw): array {
    $raw = trim(str_replace("\r", "", $raw));
    if ($raw === '') return [];
    $lines = preg_split('/\n+/', $raw) ?: [];
    $reports = [];
    $current = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $isStart = (bool)preg_match('/^(?:METAR\s+|SPECI\s+|TAF(?:\s+(?:AMD|COR))?\s+)?[A-Z][A-Z0-9]{3}\s+\d{6}Z\b/', $line);
        if ($isStart && $current !== '') {
            $reports[] = trim($current);
            $current = $line;
        } else {
            $current = $current === '' ? $line : $current.' '.$line;
        }
    }
    if ($current !== '') $reports[] = trim($current);
    return $reports;
}

function reportForStation(array $reports, string $icao): ?string {
    foreach ($reports as $report) {
        if (preg_match('/\b'.preg_quote($icao, '/').'\s+\d{6}Z\b/i', $report)) return trim($report);
    }
    return null;
}

$tokens = stationTokens();
$wantMetar = boolParam('metar', true);
$wantTaf = boolParam('taf', true);
if (!$wantMetar && !$wantTaf) out(400, ['ok'=>false,'error'=>'METAR veya TAF seçeneklerinden en az biri gerekli.']);

$stations = [];
$seenIcao = [];
foreach ($tokens as $token) {
    $station = resolveStation($token);
    $icao = $station['icao'];
    if ($icao !== null && isset($seenIcao[$icao])) continue;
    if ($icao !== null) $seenIcao[$icao] = true;
    $stations[] = $station;
}
$icaos = array_values(array_map(fn($s) => $s['icao'], array_filter($stations, fn($s) => !empty($s['icao']))));

$metarBatch = $wantMetar ? fetchAwcBatch('metar', $icaos, 60) : ['ok'=>true,'raw'=>'','source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
$tafBatch = $wantTaf ? fetchAwcBatch('taf', $icaos, 180) : ['ok'=>true,'raw'=>'','source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
if (($wantMetar && !$metarBatch['ok']) && ($wantTaf && !$tafBatch['ok'])) out(502, ['ok'=>false,'error'=>'Hava verisi alınamadı.']);
if ($wantMetar && !$wantTaf && !$metarBatch['ok']) out(502, ['ok'=>false,'error'=>'METAR verisi alınamadı.']);
if ($wantTaf && !$wantMetar && !$tafBatch['ok']) out(502, ['ok'=>false,'error'=>'TAF verisi alınamadı.']);

$metarReports = splitReports((string)$metarBatch['raw']);
$tafReports = splitReports((string)$tafBatch['raw']);
foreach ($stations as &$station) {
    $icao = $station['icao'];
    if ($icao === null) {
        $station['metar'] = $wantMetar ? ['available'=>false,'raw'=>null,'source'=>'AviationWeather.gov','transport'=>'HTTPS'] : null;
        $station['taf'] = $wantTaf ? ['available'=>false,'raw'=>null,'source'=>'AviationWeather.gov','transport'=>'HTTPS'] : null;
        continue;
    }
    if ($wantMetar) {
        $raw = $metarBatch['ok'] ? reportForStation($metarReports, $icao) : null;
        $station['metar'] = ['available'=>$raw !== null,'raw'=>$raw,'source'=>'AviationWeather.gov','transport'=>'HTTPS'];
    } else $station['metar'] = null;
    if ($wantTaf) {
        $raw = $tafBatch['ok'] ? reportForStation($tafReports, $icao) : null;
        $station['taf'] = ['available'=>$raw !== null,'raw'=>$raw,'source'=>'AviationWeather.gov','transport'=>'HTTPS'];
    } else $station['taf'] = null;
}
unset($station);

$payload = [
    'ok'=>true,
    'source'=>'AviationWeather.gov',
    'fetchedAt'=>gmdate('c'),
    'requested'=>['metar'=>$wantMetar,'taf'=>$wantTaf],
    'stations'=>$stations,
    'cache'=>['metarHit'=>(bool)$metarBatch['cacheHit'],'tafHit'=>(bool)$tafBatch['cacheHit']],
];
if (count($stations) === 1 && !empty($stations[0]['icao'])) {
    $payload['icao'] = $stations[0]['icao'];
    $payload['metar'] = $stations[0]['metar'];
    $payload['taf'] = $stations[0]['taf'];
}
out(200, $payload);