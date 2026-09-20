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

function curlFetch(string $url, string $accept = 'text/plain, */*;q=0.8', bool $follow = true): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'YulCaribe/1.0 Weather Client',
        CURLOPT_HTTPHEADER => [
            'Accept: ' . $accept,
            'Cache-Control: no-cache'
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $totalTime = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    return [
        'body' => $body,
        'errno' => $errno,
        'error' => $error,
        'status' => $status,
        'effectiveUrl' => $effectiveUrl,
        'totalTime' => $totalTime
    ];
}

function fetchAwc(string $scheme, string $product, string $icao): array {
    $url = sprintf(
        '%s://aviationweather.gov/api/data/%s?ids=%s&format=raw',
        $scheme,
        rawurlencode($product),
        rawurlencode($icao)
    );

    // HTTP fallback is intentionally not followed. If AWC redirects HTTP to
    // HTTPS, following it would simply repeat the same TLS failure.
    $result = curlFetch($url, 'text/plain, */*;q=0.8', $scheme === 'https');

    if ($result['errno'] !== 0 || $result['body'] === false) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'error' => $result['error'] !== '' ? $result['error'] : 'Bağlantı kurulamadı.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => strtoupper($scheme),
            'url' => $url,
            'effectiveUrl' => $result['effectiveUrl'],
            'totalTime' => $result['totalTime']
        ];
    }

    if ($result['status'] === 204) {
        return [
            'ok' => true,
            'status' => 204,
            'error' => null,
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => strtoupper($scheme),
            'url' => $url,
            'effectiveUrl' => $result['effectiveUrl'],
            'totalTime' => $result['totalTime']
        ];
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'error' => 'AviationWeather.gov HTTP ' . $result['status'] . ' yanıtı döndürdü.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => strtoupper($scheme),
            'url' => $url,
            'effectiveUrl' => $result['effectiveUrl'],
            'totalTime' => $result['totalTime']
        ];
    }

    $raw = trim((string)$result['body']);

    return [
        'ok' => true,
        'status' => $result['status'],
        'error' => null,
        'raw' => $raw !== '' ? $raw : null,
        'source' => 'AviationWeather.gov',
        'transport' => strtoupper($scheme),
        'url' => $url,
        'effectiveUrl' => $result['effectiveUrl'],
        'totalTime' => $result['totalTime']
    ];
}

function findRawText(mixed $value): ?string {
    if (!is_array($value)) {
        return null;
    }

    foreach (['rawText', 'raw_text', 'raw'] as $key) {
        if (isset($value[$key]) && is_string($value[$key])) {
            $raw = trim($value[$key]);
            if ($raw !== '') {
                return $raw;
            }
        }
    }

    foreach ($value as $child) {
        $raw = findRawText($child);
        if ($raw !== null) {
            return $raw;
        }
    }

    return null;
}

function fetchMetarsEu(string $product, string $icao): array {
    $path = $product === 'metar' ? 'metars' : 'tafs';
    $url = sprintf('https://metars.eu/api/%s/%s', $path, rawurlencode($icao));
    $result = curlFetch($url, 'application/json', true);

    if ($result['errno'] !== 0 || $result['body'] === false) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'error' => $result['error'] !== '' ? $result['error'] : 'Bağlantı kurulamadı.',
            'raw' => null,
            'source' => 'metars.eu',
            'transport' => 'HTTPS'
        ];
    }

    if ($result['status'] === 404) {
        return [
            'ok' => true,
            'status' => 404,
            'error' => null,
            'raw' => null,
            'source' => 'metars.eu',
            'transport' => 'HTTPS'
        ];
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'error' => 'metars.eu HTTP ' . $result['status'] . ' yanıtı döndürdü.',
            'raw' => null,
            'source' => 'metars.eu',
            'transport' => 'HTTPS'
        ];
    }

    $json = json_decode((string)$result['body'], true);

    return [
        'ok' => is_array($json),
        'status' => $result['status'],
        'error' => is_array($json) ? null : 'metars.eu geçersiz JSON döndürdü.',
        'raw' => is_array($json) ? findRawText($json) : null,
        'source' => 'metars.eu',
        'transport' => 'HTTPS'
    ];
}

function fetchWeatherProduct(string $product, string $icao): array {
    $attempts = [];

    $https = fetchAwc('https', $product, $icao);
    $attempts[] = [
        'source' => $https['source'],
        'transport' => $https['transport'],
        'status' => $https['status'],
        'error' => $https['error']
    ];

    if ($https['ok']) {
        $https['attempts'] = $attempts;
        return $https;
    }

    $http = fetchAwc('http', $product, $icao);
    $attempts[] = [
        'source' => $http['source'],
        'transport' => $http['transport'],
        'status' => $http['status'],
        'error' => $http['error']
    ];

    if ($http['ok']) {
        $http['attempts'] = $attempts;
        return $http;
    }

    $backup = fetchMetarsEu($product, $icao);
    $attempts[] = [
        'source' => $backup['source'],
        'transport' => $backup['transport'],
        'status' => $backup['status'],
        'error' => $backup['error']
    ];
    $backup['attempts'] = $attempts;

    return $backup;
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
    . 'yulcaribe_weather_cache_v3';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}

$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $icao . '.json';
$cacheMaxAge = 120;

$cached = loadCache($cacheFile, $cacheMaxAge);
if ($cached !== null) {
    respond(200, $cached);
}

$metar = fetchWeatherProduct('metar', $icao);
$taf = fetchWeatherProduct('taf', $icao);

$hasMetar = $metar['ok'] === true && is_string($metar['raw']) && $metar['raw'] !== '';
$hasTaf = $taf['ok'] === true && is_string($taf['raw']) && $taf['raw'] !== '';

if (!$hasMetar && !$hasTaf && (!$metar['ok'] || !$taf['ok'])) {
    respond(502, [
        'ok' => false,
        'icao' => $icao,
        'error' => 'Hava durumu kaynaklarına şu anda ulaşılamıyor.',
        'metarAttempts' => $metar['attempts'] ?? [],
        'tafAttempts' => $taf['attempts'] ?? []
    ]);
}

$sources = array_values(array_unique(array_filter([
    $hasMetar ? ($metar['source'] ?? null) : null,
    $hasTaf ? ($taf['source'] ?? null) : null
])));

$payload = [
    'ok' => true,
    'icao' => $icao,
    'source' => implode(' + ', $sources),
    'fetchedAt' => gmdate('c'),
    'metar' => [
        'available' => $hasMetar,
        'raw' => $hasMetar ? $metar['raw'] : null,
        'source' => $metar['source'] ?? null,
        'transport' => $metar['transport'] ?? null,
        'upstreamStatus' => $metar['status'] ?? null
    ],
    'taf' => [
        'available' => $hasTaf,
        'raw' => $hasTaf ? $taf['raw'] : null,
        'source' => $taf['source'] ?? null,
        'transport' => $taf['transport'] ?? null,
        'upstreamStatus' => $taf['status'] ?? null
    ],
    'debug' => [
        'metarAttempts' => $metar['attempts'] ?? [],
        'tafAttempts' => $taf['attempts'] ?? []
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
