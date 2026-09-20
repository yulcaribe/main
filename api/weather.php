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

function fetchAwcProduct(string $product, string $icao, string $scheme = 'https'): array {
    $scheme = $scheme === 'http' ? 'http' : 'https';

    $url = sprintf(
        '%s://aviationweather.gov/api/data/%s?ids=%s&format=raw',
        $scheme,
        rawurlencode($product),
        rawurlencode($icao)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $scheme === 'https',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'YulCaribe/1.0 Weather Client',
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain, */*;q=0.8',
            'Cache-Control: no-cache'
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error !== '' ? $error : 'Bağlantı kurulamadı.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => strtoupper($scheme),
            'url' => $url,
            'totalTime' => $totalTime
        ];
    }

    if ($status === 204) {
        return [
            'ok' => true,
            'status' => 204,
            'error' => null,
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => strtoupper($scheme),
            'url' => $url,
            'totalTime' => $totalTime
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'AviationWeather.gov HTTP ' . $status . ' yanıtı döndürdü.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => strtoupper($scheme),
            'url' => $url,
            'totalTime' => $totalTime
        ];
    }

    $raw = trim((string)$body);

    return [
        'ok' => true,
        'status' => $status,
        'error' => null,
        'raw' => $raw !== '' ? $raw : null,
        'source' => 'AviationWeather.gov',
        'transport' => 'HTTP',
        'url' => $url,
        'totalTime' => $totalTime
    ];
}

function loadCache(string $file, int $maxAge): ?array {
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }

    $cached = json_decode($raw, true);
    if (!is_array($cached) || !isset($cached['savedAt'], $cached['payload'])) {
        return null;
    }

    $age = time() - (int)$cached['savedAt'];
    if ($age < 0 || $age > $maxAge || !is_array($cached['payload'])) {
        return null;
    }

    $cached['payload']['cache'] = [
        'hit' => true,
        'ageSeconds' => $age
    ];

    return $cached['payload'];
}

$icao = strtoupper(trim((string)($_GET['icao'] ?? '')));

if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) {
    respond(400, [
        'ok' => false,
        'error' => '4 karakterli geçerli bir ICAO kodu girin. Örnek: LTAI.'
    ]);
}

if (!function_exists('curl_init')) {
    respond(500, [
        'ok' => false,
        'error' => 'PHP cURL bu sunucuda aktif değil.'
    ]);
}

$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'yulcaribe_weather_cache_http_awc';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}

$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $icao . '.json';
$cacheMaxAge = 120;

$cached = loadCache($cacheFile, $cacheMaxAge);
if ($cached !== null) {
    respond(200, $cached);
}

function fetchAwcWithFallback(string $product, string $icao): array {
    $https = fetchAwcProduct($product, $icao, 'https');

    if ($https['ok']) {
        return $https;
    }

    return fetchAwcProduct($product, $icao, 'http');
}

$metar = fetchAwcWithFallback('metar', $icao);
$taf = fetchAwcWithFallback('taf', $icao);

$hasMetar = $metar['ok'] === true && is_string($metar['raw']) && $metar['raw'] !== '';
$hasTaf = $taf['ok'] === true && is_string($taf['raw']) && $taf['raw'] !== '';

if (!$hasMetar && !$hasTaf && (!$metar['ok'] || !$taf['ok'])) {
    respond(502, [
        'ok' => false,
        'icao' => $icao,
        'error' => 'AviationWeather.gov kaynağına HTTPS veya HTTP üzerinden ulaşılamıyor.',
        'metarStatus' => $metar['status'],
        'tafStatus' => $taf['status'],
        'metarError' => $metar['error'],
        'tafError' => $taf['error']
    ]);
}

$payload = [
    'ok' => true,
    'icao' => $icao,
    'source' => 'AviationWeather.gov',
    'fetchedAt' => gmdate('c'),
    'metar' => [
        'available' => $hasMetar,
        'raw' => $hasMetar ? $metar['raw'] : null,
        'source' => 'AviationWeather.gov',
        'transport' => $metar['transport'] ?? null,
        'upstreamStatus' => $metar['status']
    ],
    'taf' => [
        'available' => $hasTaf,
        'raw' => $hasTaf ? $taf['raw'] : null,
        'source' => 'AviationWeather.gov',
        'transport' => $taf['transport'] ?? null,
        'upstreamStatus' => $taf['status']
    ],
    'cache' => [
        'hit' => false,
        'ageSeconds' => 0
    ]
];

@file_put_contents(
    $cacheFile,
    json_encode(
        ['savedAt' => time(), 'payload' => $payload],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
    LOCK_EX
);

respond(200, $payload);
