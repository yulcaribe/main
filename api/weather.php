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

function fetchAwcHttps(string $product, string $icao): array {
    $url = sprintf(
        'https://aviationweather.gov/api/data/%s?ids=%s&format=raw',
        rawurlencode($product),
        rawurlencode($icao)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain, */*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache'
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error !== '' ? $error : 'HTTPS bağlantısı kurulamadı.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTPS',
            'url' => $effectiveUrl !== '' ? $effectiveUrl : $url
        ];
    }

    if ($status === 204) {
        return [
            'ok' => true,
            'status' => 204,
            'error' => null,
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTPS',
            'url' => $effectiveUrl !== '' ? $effectiveUrl : $url
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'HTTPS HTTP ' . $status,
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTPS',
            'url' => $effectiveUrl !== '' ? $effectiveUrl : $url
        ];
    }

    $raw = trim((string)$body);

    return [
        'ok' => true,
        'status' => $status,
        'error' => null,
        'raw' => $raw !== '' ? $raw : null,
        'source' => 'AviationWeather.gov',
        'transport' => 'HTTPS',
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url
    ];
}

function decodeChunkedBody(string $body): string {
    $decoded = '';

    while ($body !== '') {
        $pos = strpos($body, "\r\n");
        if ($pos === false) {
            return $body;
        }

        $sizeLine = trim(substr($body, 0, $pos));
        $semicolon = strpos($sizeLine, ';');
        if ($semicolon !== false) {
            $sizeLine = substr($sizeLine, 0, $semicolon);
        }

        if (!ctype_xdigit($sizeLine)) {
            return $body;
        }

        $size = hexdec($sizeLine);
        $body = substr($body, $pos + 2);

        if ($size === 0) {
            break;
        }

        if (strlen($body) < $size) {
            return $body;
        }

        $decoded .= substr($body, 0, $size);
        $body = substr($body, $size + 2);
    }

    return $decoded;
}

function fetchAwcHttpSocket(string $product, string $icao): array {
    $host = 'aviationweather.gov';
    $path = sprintf(
        '/api/data/%s?ids=%s&format=raw',
        rawurlencode($product),
        rawurlencode($icao)
    );
    $url = 'http://' . $host . $path;

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        'tcp://' . $host . ':80',
        $errno,
        $errstr,
        8,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'HTTP socket: ' . ($errstr !== '' ? $errstr : 'bağlantı kurulamadı') . ' (' . $errno . ')',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTP',
            'url' => $url
        ];
    }

    stream_set_timeout($socket, 10);

    $request =
        "GET {$path} HTTP/1.1\r\n" .
        "Host: {$host}\r\n" .
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36\r\n" .
        "Accept: text/plain, */*;q=0.8\r\n" .
        "Accept-Language: en-US,en;q=0.9\r\n" .
        "Accept-Encoding: identity\r\n" .
        "Cache-Control: no-cache\r\n" .
        "Connection: close\r\n\r\n";

    fwrite($socket, $request);

    $response = '';
    while (!feof($socket)) {
        $chunk = fread($socket, 8192);
        if ($chunk === false) {
            break;
        }
        $response .= $chunk;
    }

    $meta = stream_get_meta_data($socket);
    fclose($socket);

    if (($meta['timed_out'] ?? false) === true) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'HTTP socket zaman aşımı.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTP',
            'url' => $url
        ];
    }

    $headerEnd = strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'HTTP socket geçersiz yanıt döndürdü.',
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTP',
            'url' => $url
        ];
    }

    $headerText = substr($response, 0, $headerEnd);
    $body = substr($response, $headerEnd + 4);

    $status = 0;
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $headerText, $match)) {
        $status = (int)$match[1];
    }

    if (preg_match('/^Transfer-Encoding:\s*chunked\s*$/im', $headerText)) {
        $body = decodeChunkedBody($body);
    }

    if ($status === 204) {
        return [
            'ok' => true,
            'status' => 204,
            'error' => null,
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTP',
            'url' => $url
        ];
    }

    if ($status < 200 || $status >= 300) {
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/im', $headerText, $locationMatch)) {
            $location = trim($locationMatch[1]);
        }

        return [
            'ok' => false,
            'status' => $status,
            'error' => 'HTTP socket status ' . $status . ($location ? ' → ' . $location : ''),
            'raw' => null,
            'source' => 'AviationWeather.gov',
            'transport' => 'HTTP',
            'url' => $url
        ];
    }

    $raw = trim($body);

    return [
        'ok' => true,
        'status' => $status,
        'error' => null,
        'raw' => $raw !== '' ? $raw : null,
        'source' => 'AviationWeather.gov',
        'transport' => 'HTTP',
        'url' => $url
    ];
}

function fetchAwcWithFallback(string $product, string $icao): array {
    $https = fetchAwcHttps($product, $icao);

    if ($https['ok']) {
        $https['attempts'] = [
            [
                'transport' => 'HTTPS',
                'status' => $https['status'],
                'error' => null
            ]
        ];
        return $https;
    }

    $http = fetchAwcHttpSocket($product, $icao);
    $http['attempts'] = [
        [
            'transport' => 'HTTPS',
            'status' => $https['status'],
            'error' => $https['error']
        ],
        [
            'transport' => 'HTTP',
            'status' => $http['status'],
            'error' => $http['error']
        ]
    ];

    return $http;
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
    . 'yulcaribe_weather_cache_awc_v5';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}

$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $icao . '.json';
$cacheMaxAge = 120;

$cached = loadCache($cacheFile, $cacheMaxAge);
if ($cached !== null) {
    respond(200, $cached);
}

$metar = fetchAwcWithFallback('metar', $icao);
$taf = fetchAwcWithFallback('taf', $icao);

$hasMetar = $metar['ok'] === true && is_string($metar['raw']) && $metar['raw'] !== '';
$hasTaf = $taf['ok'] === true && is_string($taf['raw']) && $taf['raw'] !== '';

if (!$hasMetar && !$hasTaf && (!$metar['ok'] || !$taf['ok'])) {
    respond(502, [
        'ok' => false,
        'icao' => $icao,
        'error' => 'AviationWeather.gov isteği başarısız.',
        'metarAttempts' => $metar['attempts'] ?? [],
        'tafAttempts' => $taf['attempts'] ?? []
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
        'upstreamStatus' => $metar['status'] ?? null
    ],
    'taf' => [
        'available' => $hasTaf,
        'raw' => $hasTaf ? $taf['raw'] : null,
        'source' => 'AviationWeather.gov',
        'transport' => $taf['transport'] ?? null,
        'upstreamStatus' => $taf['status'] ?? null
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
