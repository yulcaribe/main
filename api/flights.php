<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function fetchAdsb(string $url): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Yulcaribe-Aviation/1.0 (+https://yulcaribe.com)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Cache-Control: no-cache'
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $body = curl_exec($ch);

    $result = [
        'body' => $body,
        'curlErrno' => curl_errno($ch),
        'curlError' => curl_error($ch),
        'httpStatus' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'contentType' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'primaryIp' => (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'totalTime' => (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)
    ];

    curl_close($ch);
    return $result;
}

function loadRecentCache(string $cacheFile, int $maxAge): ?array {
    if (!is_file($cacheFile)) {
        return null;
    }

    $raw = @file_get_contents($cacheFile);
    if ($raw === false) {
        return null;
    }

    $cached = json_decode($raw, true);
    if (!is_array($cached) || !isset($cached['savedAt'], $cached['data']) || !is_array($cached['data'])) {
        return null;
    }

    $age = time() - (int)$cached['savedAt'];
    if ($age < 0 || $age > $maxAge) {
        return null;
    }

    $cached['age'] = $age;
    return $cached;
}

$latRaw = $_GET['lat'] ?? null;
$lonRaw = $_GET['lon'] ?? null;
$radiusRaw = $_GET['radius'] ?? '100';

if ($latRaw === null || !is_numeric($latRaw)) {
    respond(400, ['ok' => false, 'error' => 'Geçersiz lat değeri.']);
}
if ($lonRaw === null || !is_numeric($lonRaw)) {
    respond(400, ['ok' => false, 'error' => 'Geçersiz lon değeri.']);
}
if (!is_numeric($radiusRaw)) {
    respond(400, ['ok' => false, 'error' => 'Geçersiz radius değeri.']);
}

$lat = (float)$latRaw;
$lon = (float)$lonRaw;
$radius = (int)$radiusRaw;

if ($lat < -90 || $lat > 90) {
    respond(400, ['ok' => false, 'error' => 'Lat -90 ile 90 arasında olmalı.']);
}
if ($lon < -180 || $lon > 180) {
    respond(400, ['ok' => false, 'error' => 'Lon -180 ile 180 arasında olmalı.']);
}

// ADSB.lol point endpoint: radius is nautical miles, max 250 NM.
$radius = max(1, min(250, $radius));

$url = sprintf(
    'https://api.adsb.lol/v2/point/%s/%s/%d',
    rtrim(rtrim(sprintf('%.6F', $lat), '0'), '.'),
    rtrim(rtrim(sprintf('%.6F', $lon), '0'), '.'),
    $radius
);

if (!function_exists('curl_init')) {
    respond(500, [
        'ok' => false,
        'error' => 'PHP cURL bu sunucuda aktif değil.',
        'upstreamUrl' => $url
    ]);
}

// Cache is only used as a short stale fallback when ADSB.lol temporarily fails.
$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_adsb_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';
$cacheMaxAge = 60;

$result = null;
$attempts = 0;

for ($attempt = 1; $attempt <= 2; $attempt++) {
    $attempts = $attempt;
    $result = fetchAdsb($url);

    $networkError = $result['body'] === false || $result['curlErrno'] !== 0;
    $status = (int)$result['httpStatus'];
    $success = !$networkError && $status >= 200 && $status < 300;

    if ($success) {
        break;
    }

    // Retry only transient network/server failures. Never retry 4xx responses.
    $retryable = $networkError || $status >= 500;
    if (!$retryable || $attempt === 2) {
        break;
    }

    usleep(200000);
}

if (!is_array($result)) {
    respond(502, [
        'ok' => false,
        'error' => 'ADSB.lol isteği başlatılamadı.',
        'upstreamUrl' => $url
    ]);
}

$body = $result['body'];
$curlErrno = (int)$result['curlErrno'];
$curlError = (string)$result['curlError'];
$httpStatus = (int)$result['httpStatus'];
$contentType = (string)$result['contentType'];
$primaryIp = (string)$result['primaryIp'];
$totalTime = (float)$result['totalTime'];

$networkError = $body === false || $curlErrno !== 0;
$httpError = !$networkError && ($httpStatus < 200 || $httpStatus >= 300);

if ($networkError || $httpError) {
    $cached = loadRecentCache($cacheFile, $cacheMaxAge);

    if ($cached !== null) {
        $data = $cached['data'];
        $data['_proxy'] = [
            'source' => 'ADSB.lol',
            'requestedAt' => gmdate('c'),
            'radiusNm' => $radius,
            'stale' => true,
            'cacheAgeSeconds' => $cached['age'],
            'attempts' => $attempts,
            'upstreamStatus' => $httpStatus,
            'curlErrno' => $curlErrno
        ];
        respond(200, $data);
    }

    if ($networkError) {
        respond(502, [
            'ok' => false,
            'error' => 'ADSB.lol bağlantısı kurulamadı.',
            'attempts' => $attempts,
            'curlErrno' => $curlErrno,
            'curlError' => $curlError,
            'upstreamUrl' => $url,
            'primaryIp' => $primaryIp,
            'totalTime' => $totalTime
        ]);
    }

    respond(502, [
        'ok' => false,
        'error' => 'ADSB.lol başarılı olmayan HTTP yanıtı döndürdü.',
        'attempts' => $attempts,
        'upstreamStatus' => $httpStatus,
        'upstreamBody' => mb_substr((string)$body, 0, 1200),
        'upstreamContentType' => $contentType,
        'upstreamUrl' => $url,
        'primaryIp' => $primaryIp,
        'totalTime' => $totalTime
    ]);
}

$data = json_decode((string)$body, true);

if (!is_array($data)) {
    $cached = loadRecentCache($cacheFile, $cacheMaxAge);

    if ($cached !== null) {
        $data = $cached['data'];
        $data['_proxy'] = [
            'source' => 'ADSB.lol',
            'requestedAt' => gmdate('c'),
            'radiusNm' => $radius,
            'stale' => true,
            'cacheAgeSeconds' => $cached['age'],
            'attempts' => $attempts,
            'upstreamStatus' => $httpStatus,
            'reason' => 'invalid_json'
        ];
        respond(200, $data);
    }

    respond(502, [
        'ok' => false,
        'error' => 'ADSB.lol geçerli JSON döndürmedi.',
        'attempts' => $attempts,
        'upstreamStatus' => $httpStatus,
        'upstreamBody' => mb_substr((string)$body, 0, 1200),
        'upstreamContentType' => $contentType,
        'upstreamUrl' => $url
    ]);
}

// Save the last good response for this exact map area.
@file_put_contents(
    $cacheFile,
    json_encode(
        ['savedAt' => time(), 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
    LOCK_EX
);

$data['_proxy'] = [
    'source' => 'ADSB.lol',
    'requestedAt' => gmdate('c'),
    'radiusNm' => $radius,
    'stale' => false,
    'attempts' => $attempts,
    'upstreamStatus' => $httpStatus,
    'totalTime' => $totalTime
];

respond(200, $data);
