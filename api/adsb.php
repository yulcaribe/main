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
if (!in_array(strtolower(trim($_GET['action'] ?? 'feed')), ['feed','status','health'], true)) ycRejectRequest(400, 'Geçersiz action.');


header('X-YC-API-Resource: adsb');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

const YC_ADSB_UPSTREAM = 'https://globe.theairtraffic.com/re-api/';
const YC_ADSB_SOURCE = 'TheAirTraffic';
const YC_ADSB_MAX_BYTES = 12000000;

function jsonOut(int $status, array $payload): never {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('[adsb] JSON response: '.json_last_error_msg());
        $status = 500;
        $json = '{"ok":false,"error":"Response could not be encoded."}';
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status >= 400) header('Cache-Control: no-store, max-age=0');
    echo $json;
    exit;
}

function parseBox(?string $raw): array {
    $raw = trim((string)$raw);
    if (!preg_match('/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/', $raw)) {
        jsonOut(400, ['ok'=>false,'error'=>'Geçersiz ADS-B box değeri.']);
    }
    $parts = array_map('floatval', explode(',', $raw));
    if (count($parts) !== 4) jsonOut(400, ['ok'=>false,'error'=>'ADS-B box dört koordinat içermeli.']);
    [$south,$north,$west,$east] = $parts;
    if ($south < -90 || $south > 90 || $north < -90 || $north > 90 || $west < -180 || $west > 180 || $east < -180 || $east > 180 || $south >= $north || $west >= $east) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B box sınır dışında.']);
    }
    if (($north - $south) > 60 || ($east - $west) > 120) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B görünümü çok geniş. Haritada biraz yaklaşın.']);
    }
    return [$south,$north,$west,$east];
}

function boxString(array $box): string {
    return implode(',', array_map(static fn(float $v): string => number_format($v, 6, '.', ''), $box));
}

function fetchTheAirTraffic(string $box): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'body'=>'','contentType'=>'','error'=>'PHP cURL aktif değil.','ms'=>null];
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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/154 Safari/537.36',
        CURLOPT_REFERER => 'https://globe.theairtraffic.com/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: */*','Cache-Control: no-cache','Pragma: no-cache','X-Requested-With: XMLHttpRequest'],
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
    return ['ok'=>$ok !== false && $errno === 0 && $status === 200 && $body !== '','status'=>$status,'body'=>$body,'contentType'=>$contentType,'error'=>$error ?: null,'ms'=>$ms];
}

$action = strtolower(trim((string)($_GET['action'] ?? 'feed')));
$box = boxString(parseBox((string)($_GET['box'] ?? '36.500000,37.300000,30.000000,31.200000')));
$result = fetchTheAirTraffic($box);

if ($action === 'status' || $action === 'health') {
    $looksLikeZstd = strlen($result['body']) >= 4 && substr($result['body'], 0, 4) === "\x28\xB5\x2F\xFD";
    jsonOut($result['ok'] ? 200 : 502, [
        'ok'=>$result['ok'],'resource'=>'adsb','source'=>YC_ADSB_SOURCE,'mode'=>'binCraft+zstd',
        'upstreamStatus'=>$result['status'],'contentType'=>$result['contentType'] ?: null,'bytes'=>strlen($result['body']),
        'zstdFrame'=>$looksLikeZstd,'ms'=>$result['ms'],'box'=>$box,
        'error'=>$result['ok'] ? null : 'ADS-B kaynağına erişilemedi.'
    ]);
}
if ($action !== 'feed') jsonOut(400, ['ok'=>false,'error'=>'Bilinmeyen ADS-B action.']);
if (!$result['ok']) {
    error_log('[adsb] upstream failed HTTP '.(string)$result['status'].' '.(string)($result['error'] ?? ''));
    jsonOut(502, ['ok'=>false,'resource'=>'adsb','source'=>YC_ADSB_SOURCE,'error'=>'ADS-B verisi alınamadı.']);
}

http_response_code(200);
header('Content-Type: application/zstd');
header('Content-Length: '.strlen($result['body']));
header('X-YC-ADSB-Source: '.YC_ADSB_SOURCE);
header('X-YC-ADSB-Mode: binCraft+zstd');
echo $result['body'];
