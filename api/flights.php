<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

const FRESH_CACHE_SECONDS = 15;
const STALE_CACHE_SECONDS = 180;
const GLOBAL_MIN_INTERVAL_MS = 2200;
const DEFAULT_BACKOFF_SECONDS = 30;

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function clampFloat(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadiusNm = 3440.065;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLambda = deg2rad($lon2 - $lon1);

    $a = sin($dPhi / 2) ** 2
        + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

    return 2 * $earthRadiusNm * asin(min(1.0, sqrt($a)));
}

function filterAircraft(array $data, float $lat, float $lon, int $radius): array {
    if (!isset($data['ac']) || !is_array($data['ac'])) {
        return $data;
    }

    $filtered = [];

    foreach ($data['ac'] as $ac) {
        if (!is_array($ac)) {
            continue;
        }

        if (!isset($ac['lat'], $ac['lon']) || !is_numeric($ac['lat']) || !is_numeric($ac['lon'])) {
            continue;
        }

        $distance = haversineNm(
            $lat,
            $lon,
            (float)$ac['lat'],
            (float)$ac['lon']
        );

        if ($distance <= $radius) {
            $filtered[] = $ac;
        }
    }

    $data['ac'] = $filtered;
    $data['total'] = count($filtered);
    return $data;
}

function loadCache(string $cacheFile, int $maxAge): ?array {
    if (!is_file($cacheFile)) {
        return null;
    }

    $raw = @file_get_contents($cacheFile);
    if ($raw === false) {
        return null;
    }

    $cached = json_decode($raw, true);
    if (
        !is_array($cached)
        || !isset($cached['savedAt'], $cached['data'])
        || !is_array($cached['data'])
    ) {
        return null;
    }

    $age = time() - (int)$cached['savedAt'];
    if ($age < 0 || $age > $maxAge) {
        return null;
    }

    return [
        'age' => $age,
        'data' => $cached['data']
    ];
}

function saveCache(string $cacheFile, array $data): void {
    @file_put_contents(
        $cacheFile,
        json_encode(
            ['savedAt' => time(), 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}

function readBackoff(string $file): int {
    if (!is_file($file)) {
        return 0;
    }

    $raw = trim((string)@file_get_contents($file));
    return ctype_digit($raw) ? (int)$raw : 0;
}

function setBackoff(string $file, int $seconds): void {
    $seconds = max(DEFAULT_BACKOFF_SECONDS, min(300, $seconds));
    @file_put_contents($file, (string)(time() + $seconds), LOCK_EX);
}

function clearBackoff(string $file): void {
    if (is_file($file)) {
        @unlink($file);
    }
}

function waitForGlobalRateSlot(string $lockFile): void {
    $handle = @fopen($lockFile, 'c+');
    if ($handle === false) {
        usleep(GLOBAL_MIN_INTERVAL_MS * 1000);
        return;
    }

    flock($handle, LOCK_EX);

    rewind($handle);
    $raw = trim((string)stream_get_contents($handle));
    $lastRequestAt = is_numeric($raw) ? (float)$raw : 0.0;
    $now = microtime(true);
    $elapsedMs = ($now - $lastRequestAt) * 1000;

    if ($lastRequestAt > 0 && $elapsedMs < GLOBAL_MIN_INTERVAL_MS) {
        $sleepMs = (int)ceil(GLOBAL_MIN_INTERVAL_MS - $elapsedMs);
        usleep($sleepMs * 1000);
    }

    $now = microtime(true);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, sprintf('%.6f', $now));
    fflush($handle);

    flock($handle, LOCK_UN);
    fclose($handle);
}

function fetchAdsb(string $url): array {
    $headers = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'YulCaribe/1.0 (+https://yulcaribe.com)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Cache-Control: no-cache'
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        }
    ]);

    $body = curl_exec($ch);

    $result = [
        'body' => $body,
        'curlErrno' => curl_errno($ch),
        'curlError' => curl_error($ch),
        'httpStatus' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'totalTime' => (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        'retryAfter' => isset($headers['retry-after']) && is_numeric($headers['retry-after'])
            ? (int)$headers['retry-after']
            : null
    ];

    curl_close($ch);
    return $result;
}

function sendData(
    array $data,
    float $requestLat,
    float $requestLon,
    int $requestRadius,
    array $proxyMeta
): never {
    $filtered = filterAircraft($data, $requestLat, $requestLon, $requestRadius);
    $filtered['_proxy'] = $proxyMeta;
    respond(200, $filtered);
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
if (!function_exists('curl_init')) {
    respond(500, ['ok' => false, 'error' => 'PHP cURL bu sunucuda aktif değil.']);
}

$requestLat = clampFloat((float)$latRaw, -90.0, 90.0);
$requestLon = clampFloat((float)$lonRaw, -180.0, 180.0);
$requestRadius = max(1, min(235, (int)$radiusRaw));

// Quantize nearby users/views into the same upstream request.
// 0.1 degree is roughly 6 NM in latitude. The extra radius margin prevents
// rounding the centre from clipping the requested edge.
$bucketLat = round($requestLat * 10) / 10;
$bucketLon = round($requestLon * 10) / 10;
$upstreamRadius = (int)(ceil(($requestRadius + 8) / 25) * 25);
$upstreamRadius = max(25, min(250, $upstreamRadius));

$url = sprintf(
    'https://api.adsb.lol/v2/point/%.1f/%.1f/%d',
    $bucketLat,
    $bucketLon,
    $upstreamRadius
);

$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'yulcaribe_adsb_cache_v2';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}

$bucketKey = hash('sha256', sprintf('%.1f|%.1f|%d', $bucketLat, $bucketLon, $upstreamRadius));
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $bucketKey . '.json';
$bucketLockFile = $cacheDir . DIRECTORY_SEPARATOR . $bucketKey . '.lock';
$globalRateLockFile = $cacheDir . DIRECTORY_SEPARATOR . 'global_rate.lock';
$backoffFile = $cacheDir . DIRECTORY_SEPARATOR . 'global_backoff.txt';

$fresh = loadCache($cacheFile, FRESH_CACHE_SECONDS);
if ($fresh !== null) {
    sendData(
        $fresh['data'],
        $requestLat,
        $requestLon,
        $requestRadius,
        [
            'source' => 'ADSB.lol',
            'cacheHit' => true,
            'stale' => false,
            'cacheAgeSeconds' => $fresh['age'],
            'upstreamStatus' => 200,
            'bucket' => [
                'lat' => $bucketLat,
                'lon' => $bucketLon,
                'radiusNm' => $upstreamRadius
            ]
        ]
    );
}

$stale = loadCache($cacheFile, STALE_CACHE_SECONDS);
$backoffUntil = readBackoff($backoffFile);

if ($backoffUntil > time()) {
    if ($stale !== null) {
        sendData(
            $stale['data'],
            $requestLat,
            $requestLon,
            $requestRadius,
            [
                'source' => 'ADSB.lol',
                'cacheHit' => true,
                'stale' => true,
                'cacheAgeSeconds' => $stale['age'],
                'upstreamStatus' => 429,
                'retryAfterSeconds' => $backoffUntil - time(),
                'bucket' => [
                    'lat' => $bucketLat,
                    'lon' => $bucketLon,
                    'radiusNm' => $upstreamRadius
                ]
            ]
        );
    }

    respond(429, [
        'ok' => false,
        'error' => 'ADSB.lol geçici rate limit uyguluyor.',
        'upstreamStatus' => 429,
        'retryAfterSeconds' => $backoffUntil - time()
    ]);
}

$bucketLock = @fopen($bucketLockFile, 'c');
if ($bucketLock === false) {
    respond(500, ['ok' => false, 'error' => 'Cache kilidi açılamadı.']);
}

flock($bucketLock, LOCK_EX);

// Another request may have refreshed this bucket while we waited.
$freshAfterLock = loadCache($cacheFile, FRESH_CACHE_SECONDS);
if ($freshAfterLock !== null) {
    flock($bucketLock, LOCK_UN);
    fclose($bucketLock);

    sendData(
        $freshAfterLock['data'],
        $requestLat,
        $requestLon,
        $requestRadius,
        [
            'source' => 'ADSB.lol',
            'cacheHit' => true,
            'stale' => false,
            'cacheAgeSeconds' => $freshAfterLock['age'],
            'upstreamStatus' => 200,
            'bucket' => [
                'lat' => $bucketLat,
                'lon' => $bucketLon,
                'radiusNm' => $upstreamRadius
            ]
        ]
    );
}

waitForGlobalRateSlot($globalRateLockFile);
$result = fetchAdsb($url);

$body = $result['body'];
$curlErrno = (int)$result['curlErrno'];
$httpStatus = (int)$result['httpStatus'];
$networkError = $body === false || $curlErrno !== 0;

if (!$networkError && $httpStatus >= 200 && $httpStatus < 300) {
    $data = json_decode((string)$body, true);

    if (is_array($data) && isset($data['ac']) && is_array($data['ac'])) {
        saveCache($cacheFile, $data);
        clearBackoff($backoffFile);

        flock($bucketLock, LOCK_UN);
        fclose($bucketLock);

        sendData(
            $data,
            $requestLat,
            $requestLon,
            $requestRadius,
            [
                'source' => 'ADSB.lol',
                'cacheHit' => false,
                'stale' => false,
                'cacheAgeSeconds' => 0,
                'upstreamStatus' => $httpStatus,
                'upstreamTimeSeconds' => $result['totalTime'],
                'bucket' => [
                    'lat' => $bucketLat,
                    'lon' => $bucketLon,
                    'radiusNm' => $upstreamRadius
                ]
            ]
        );
    }
}

if ($httpStatus === 429) {
    setBackoff(
        $backoffFile,
        $result['retryAfter'] ?? DEFAULT_BACKOFF_SECONDS
    );
}

flock($bucketLock, LOCK_UN);
fclose($bucketLock);

$staleAfterFailure = loadCache($cacheFile, STALE_CACHE_SECONDS);
if ($staleAfterFailure !== null) {
    sendData(
        $staleAfterFailure['data'],
        $requestLat,
        $requestLon,
        $requestRadius,
        [
            'source' => 'ADSB.lol',
            'cacheHit' => true,
            'stale' => true,
            'cacheAgeSeconds' => $staleAfterFailure['age'],
            'upstreamStatus' => $httpStatus,
            'retryAfterSeconds' => $httpStatus === 429
                ? max(DEFAULT_BACKOFF_SECONDS, (int)($result['retryAfter'] ?? 0))
                : null,
            'bucket' => [
                'lat' => $bucketLat,
                'lon' => $bucketLon,
                'radiusNm' => $upstreamRadius
            ]
        ]
    );
}

if ($networkError) {
    respond(502, [
        'ok' => false,
        'error' => 'ADSB.lol bağlantısı kurulamadı.',
        'upstreamStatus' => $httpStatus,
        'curlErrno' => $curlErrno,
        'curlError' => $result['curlError']
    ]);
}

respond($httpStatus === 429 ? 429 : 502, [
    'ok' => false,
    'error' => $httpStatus === 429
        ? 'ADSB.lol geçici rate limit uyguluyor.'
        : 'ADSB.lol başarılı olmayan yanıt döndürdü.',
    'upstreamStatus' => $httpStatus,
    'retryAfterSeconds' => $httpStatus === 429
        ? max(DEFAULT_BACKOFF_SECONDS, (int)($result['retryAfter'] ?? 0))
        : null
]);
