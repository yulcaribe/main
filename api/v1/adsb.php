<?php
declare(strict_types=1);

header('X-YC-API-Version: 1');
header('X-YC-API-Resource: adsb');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

const YC_ADSB_UPSTREAM = 'https://globe.theairtraffic.com/re-api/';
const YC_ADSB_SOURCE = 'TheAirTraffic';
const YC_ADSB_MAX_BYTES = 12000000;

function jsonOut(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parseBox(?string $raw): array {
    $raw = trim((string)$raw);
    if (!preg_match('/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/', $raw)) {
        jsonOut(400, ['ok'=>false,'error'=>'Geçersiz ADS-B box değeri.']);
    }

    $parts = array_map('floatval', explode(',', $raw));
    if (count($parts) !== 4) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B box dört koordinat içermeli.']);
    }

    [$south,$north,$west,$east] = $parts;
    if (
        $south < -90 || $south > 90 ||
        $north < -90 || $north > 90 ||
        $west < -180 || $west > 180 ||
        $east < -180 || $east > 180 ||
        $south >= $north ||
        $west >= $east
    ) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B box sınır dışında.']);
    }

    if (($north - $south) > 60 || ($east - $west) > 120) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B görünümü çok geniş. Haritada biraz yaklaşın.']);
    }

    return [$south,$north,$west,$east];
}

function boxString(array $box): string {
    return implode(',', array_map(
        static fn(float $v): string => number_format($v, 6, '.', ''),
        $box
    ));
}

function fetchTheAirTraffic(string $box): array {
    if (!function_exists('curl_init')) {
        return ['ok'=>false,'status'=>0,'body'=>'','contentType'=>'','error'=>'PHP cURL aktif değil.','ms'=>null];
    }

    $target = YC_ADSB_UPSTREAM . '?binCraft&zstd&box=' . $box;
    $body = '';

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
        CURLOPT_REFERER => 'https://globe.theairtraffic.com/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Accept-Language: tr,en-US;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Priority: u=1, i',
            'Sec-CH-UA: "Chromium";v="154", "Google Chrome";v="154", "Not A(Brand";v="99"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "macOS"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > YC_ADSB_MAX_BYTES) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $started = microtime(true);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $ms = round((microtime(true) - $started) * 1000, 1);
    curl_close($ch);

    return [
        'ok'=>$ok !== false && $errno === 0 && $status === 200 && $body !== '',
        'status'=>$status,
        'body'=>$body,
        'contentType'=>$contentType,
        'error'=>$error ?: null,
        'errno'=>$errno,
        'ms'=>$ms,
        'target'=>$target,
    ];
}

$action = strtolower(trim((string)($_GET['action'] ?? 'feed')));
$defaultBox = '36.500000,37.300000,30.000000,31.200000';
$box = boxString(parseBox((string)($_GET['box'] ?? $defaultBox)));
$result = fetchTheAirTraffic($box);

if ($action === 'status' || $action === 'health') {
    $looksLikeZstd = strlen($result['body']) >= 4
        && substr($result['body'], 0, 4) === "\x28\xB5\x2F\xFD";

    jsonOut($result['ok'] ? 200 : 502, [
        'ok'=>$result['ok'],
        'resource'=>'adsb',
        'source'=>YC_ADSB_SOURCE,
        'mode'=>'binCraft+zstd',
        'upstreamStatus'=>$result['status'],
        'contentType'=>$result['contentType'] ?: null,
        'bytes'=>strlen($result['body']),
        'zstdFrame'=>$looksLikeZstd,
        'ms'=>$result['ms'],
        'box'=>$box,
        'error'=>$result['ok'] ? null : ($result['error'] ?: 'TheAirTraffic yanıtı alınamadı.'),
    ]);
}

if ($action !== 'feed') {
    jsonOut(400, ['ok'=>false,'error'=>'Bilinmeyen ADS-B action.']);
}

if (!$result['ok']) {
    jsonOut(502, [
        'ok'=>false,
        'resource'=>'adsb',
        'source'=>YC_ADSB_SOURCE,
        'error'=>$result['error'] ?: 'TheAirTraffic ADS-B feed yanıt vermedi.',
        'upstreamStatus'=>$result['status'],
        'ms'=>$result['ms'],
    ]);
}

http_response_code(200);
header('Content-Type: application/zstd');
header('Content-Length: '.strlen($result['body']));
header('X-YC-ADSB-Source: '.YC_ADSB_SOURCE);
header('X-YC-ADSB-Mode: binCraft+zstd');
header('X-YC-ADSB-Upstream-Status: '.(string)$result['status']);
header('X-YC-ADSB-Upstream-Time-Ms: '.(string)$result['ms']);
echo $result['body'];
