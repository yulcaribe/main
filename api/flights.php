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

// Airplanes.live point endpoint: radius is nautical miles, max 250 NM.
$radius = max(1, min(250, $radius));

$url = sprintf(
    'https://api.airplanes.live/v2/point/%s/%s/%d',
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

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
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

$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
$totalTime = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);

curl_close($ch);

if ($body === false || $curlErrno !== 0) {
    respond(502, [
        'ok' => false,
        'error' => 'Airplanes.live bağlantısı kurulamadı.',
        'curlErrno' => $curlErrno,
        'curlError' => $curlError,
        'upstreamUrl' => $url,
        'primaryIp' => $primaryIp,
        'totalTime' => $totalTime
    ]);
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    respond(502, [
        'ok' => false,
        'error' => 'Airplanes.live başarılı olmayan HTTP yanıtı döndürdü.',
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
    respond(502, [
        'ok' => false,
        'error' => 'Airplanes.live geçerli JSON döndürmedi.',
        'upstreamStatus' => $httpStatus,
        'upstreamBody' => mb_substr((string)$body, 0, 1200),
        'upstreamContentType' => $contentType,
        'upstreamUrl' => $url
    ]);
}

$data['_proxy'] = [
    'source' => 'Airplanes.live',
    'requestedAt' => gmdate('c'),
    'radiusNm' => $radius,
    'upstreamStatus' => $httpStatus,
    'totalTime' => $totalTime
];

respond(200, $data);
