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
    header('Content-Type: application/json; charset=utf-8');
    if ($status >= 400) header('Cache-Control: no-store, max-age=0');
    echo $json;
    exit;
}

function cleanIcao(?string $raw): ?string {
    $icao = strtoupper(trim((string)$raw));
    return preg_match('/^[A-Z0-9]{4}$/', $icao) ? $icao : null;
}

function cacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_metartaf';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function cacheRead(string $product, string $icao, int $ttl): ?array {
    $file = cacheDir() . DIRECTORY_SEPARATOR . $product . '_' . $icao . '.json';
    if (!is_file($file)) return null;
    $age = time() - (int)@filemtime($file);
    if ($age < 0 || $age > $ttl) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return null;
    $data['cacheHit'] = true;
    return $data;
}

function cacheWrite(string $product, string $icao, array $payload): void {
    $file = cacheDir() . DIRECTORY_SEPARATOR . $product . '_' . $icao . '.json';
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function fetchAwc(string $product, string $icao, int $ttl): array {
    if ($cached = cacheRead($product, $icao, $ttl)) return $cached;
    if (!function_exists('curl_init')) {
        return ['ok'=>false,'available'=>false,'raw'=>null,'source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
    }

    $url = 'https://aviationweather.gov/api/data/' . rawurlencode($product)
        . '?ids=' . rawurlencode($icao) . '&format=raw';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
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
        error_log('[metartaf] '.$product.' '.$icao.' upstream failed: HTTP '.$status.' '.$error);
        return ['ok'=>false,'available'=>false,'raw'=>null,'source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
    }

    $raw = trim((string)$body);
    if ($raw !== '' && !preg_match('/\b'.preg_quote($icao, '/').'\b/i', $raw)) {
        error_log('[metartaf] '.$product.' '.$icao.' response did not contain requested station');
        return ['ok'=>false,'available'=>false,'raw'=>null,'source'=>'AviationWeather.gov','transport'=>'HTTPS','cacheHit'=>false];
    }

    $payload = [
        'ok'=>true,
        'available'=>$raw !== '',
        'raw'=>$raw !== '' ? $raw : null,
        'source'=>'AviationWeather.gov',
        'transport'=>'HTTPS',
        'cacheHit'=>false,
    ];
    cacheWrite($product, $icao, $payload);
    return $payload;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    header('Allow: GET');
    out(405, ['ok'=>false,'error'=>'HTTP method not allowed.']);
}

$icao = cleanIcao($_GET['icao'] ?? null);
if ($icao === null) out(400, ['ok'=>false,'error'=>'4 karakterli geçerli ICAO kodu gerekli.']);

$metar = fetchAwc('metar', $icao, 60);
$taf = fetchAwc('taf', $icao, 180);

if (!$metar['ok'] && !$taf['ok']) {
    out(502, ['ok'=>false,'icao'=>$icao,'error'=>'Hava verisi alınamadı.']);
}

out(200, [
    'ok'=>true,
    'icao'=>$icao,
    'source'=>'AviationWeather.gov',
    'fetchedAt'=>gmdate('c'),
    'metar'=>[
        'available'=>(bool)$metar['available'],
        'raw'=>$metar['raw'],
        'source'=>$metar['source'],
        'transport'=>$metar['transport'],
    ],
    'taf'=>[
        'available'=>(bool)$taf['available'],
        'raw'=>$taf['raw'],
        'source'=>$taf['source'],
        'transport'=>$taf['transport'],
    ],
    'cache'=>[
        'metarHit'=>(bool)$metar['cacheHit'],
        'tafHit'=>(bool)$taf['cacheHit'],
    ],
]);
