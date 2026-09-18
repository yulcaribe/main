<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function fail(int $status, string $message, array $extra = []): never {
    http_response_code($status);
    echo json_encode(
        array_merge(['ok' => false, 'error' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lon = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
$radius = filter_input(INPUT_GET, 'radius', FILTER_VALIDATE_INT);

if ($lat === false || $lat === null || $lat < -90 || $lat > 90) {
    fail(400, 'Geçersiz lat değeri.');
}

if ($lon === false || $lon === null || $lon < -180 || $lon > 180) {
    fail(400, 'Geçersiz lon değeri.');
}

if ($radius === false || $radius === null) {
    $radius = 100;
}

$radius = max(1, min(250, (int)$radius));

$url = sprintf(
    'https://api.airplanes.live/v2/point/%s/%s/%d',
    rawurlencode((string)$lat),
    rawurlencode((string)$lon),
    $radius
);

$body = false;
$status = 0;
$contentType = 'application/json';

if (function_exists('curl_init')) {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Yulcaribe-Aviation/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],
        CURLOPT_ENCODING => ''
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fail(502, 'Airplanes.live bağlantı hatası.', ['detail' => $error]);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $remoteType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (is_string($remoteType) && $remoteType !== '') {
        $contentType = $remoteType;
    }

    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Accept: application/json',
                'User-Agent: Yulcaribe-Aviation/1.0'
            ])
        ]
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        fail(502, 'Airplanes.live bağlantısı kurulamadı.');
    }

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $headerLine, $m)) {
                $status = (int)$m[1];
            }
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(substr($headerLine, 13));
            }
        }
    }
}

if ($status < 200 || $status >= 300) {
    fail(502, 'Airplanes.live beklenmeyen HTTP yanıtı.', [
        'upstreamStatus' => $status
    ]);
}

$data = json_decode((string)$body, true);

if (!is_array($data)) {
    fail(502, 'Airplanes.live geçersiz JSON döndürdü.', [
        'contentType' => $contentType
    ]);
}

$data['_proxy'] = [
    'source' => 'Airplanes.live',
    'requestedAt' => gmdate('c'),
    'radiusNm' => $radius
];

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
