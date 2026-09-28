<?php
declare(strict_types=1);

const YC_ADSB_TAT_URL = 'https://globe.theairtraffic.com/re-api/';

function ycAdsbTatEndpoint(string $icao): string {
    return YC_ADSB_TAT_URL . '?find_hex=' . rawurlencode($icao);
}

function ycAdsbTatParse(array $payload): array {
    $aircraft = is_array($payload['aircraft'] ?? null) ? $payload['aircraft'] : (is_array($payload['ac'] ?? null) ? $payload['ac'] : []);
    $row = is_array($aircraft[0] ?? null) ? $aircraft[0] : null;
    return ycAdsbTatStandardize($row, 'tat');
}

function ycAdsbTatFetch(string $icao): array {
    return ycAdsbTatParse(ycAdsbTatHttpJson(ycAdsbTatEndpoint($icao)));
}

function ycAdsbTatStandardize(?array $a, string $provider): array {
    $icao = strtolower(trim((string)($a['hex'] ?? '')));
    if (str_starts_with($icao, '~')) $icao = '';
    return [
        'icao' => $icao !== '' ? $icao : null,
        'callsign' => ycAdsbTatString($a['flight'] ?? null),
        'reg' => ycAdsbTatString($a['r'] ?? null),
        'aircraftType' => ycAdsbTatString($a['t'] ?? null),
        'sourceType' => ycAdsbTatString($a['type'] ?? null),
        'lat' => ycAdsbTatNumber($a['lat'] ?? null),
        'lon' => ycAdsbTatNumber($a['lon'] ?? null),
        'altBaro' => ycAdsbTatAltitude($a['alt_baro'] ?? null),
        'altGeom' => ycAdsbTatNumber($a['alt_geom'] ?? null),
        'groundSpeed' => ycAdsbTatNumber($a['gs'] ?? null),
        'ias' => ycAdsbTatNumber($a['ias'] ?? null),
        'tas' => ycAdsbTatNumber($a['tas'] ?? null),
        'mach' => ycAdsbTatNumber($a['mach'] ?? null),
        'track' => ycAdsbTatNumber($a['track'] ?? null),
        'trueHeading' => ycAdsbTatNumber($a['true_heading'] ?? null),
        'magHeading' => ycAdsbTatNumber($a['mag_heading'] ?? null),
        'roll' => ycAdsbTatNumber($a['roll'] ?? null),
        'baroRate' => ycAdsbTatNumber($a['baro_rate'] ?? null),
        'geomRate' => ycAdsbTatNumber($a['geom_rate'] ?? null),
        'squawk' => ycAdsbTatString($a['squawk'] ?? null),
        'emergency' => ycAdsbTatString($a['emergency'] ?? null),
        'category' => ycAdsbTatString($a['category'] ?? null),
        'navQnh' => ycAdsbTatNumber($a['nav_qnh'] ?? null),
        'navAltitudeMcp' => ycAdsbTatNumber($a['nav_altitude_mcp'] ?? null),
        'navAltitudeFms' => ycAdsbTatNumber($a['nav_altitude_fms'] ?? null),
        'navHeading' => ycAdsbTatNumber($a['nav_heading'] ?? null),
        'navModes' => is_array($a['nav_modes'] ?? null) ? array_values($a['nav_modes']) : [],
        'nic' => ycAdsbTatNumber($a['nic'] ?? null),
        'rc' => ycAdsbTatNumber($a['rc'] ?? null),
        'nicBaro' => ycAdsbTatNumber($a['nic_baro'] ?? null),
        'nacP' => ycAdsbTatNumber($a['nac_p'] ?? null),
        'nacV' => ycAdsbTatNumber($a['nac_v'] ?? null),
        'sil' => ycAdsbTatNumber($a['sil'] ?? null),
        'silType' => ycAdsbTatString($a['sil_type'] ?? null),
        'gva' => ycAdsbTatNumber($a['gva'] ?? null),
        'sda' => ycAdsbTatNumber($a['sda'] ?? null),
        'version' => ycAdsbTatNumber($a['version'] ?? null),
        'alert' => ycAdsbTatNumber($a['alert'] ?? null),
        'spi' => ycAdsbTatNumber($a['spi'] ?? null),
        'seen' => ycAdsbTatNumber($a['seen'] ?? null),
        'seenPos' => ycAdsbTatNumber($a['seen_pos'] ?? null),
        'messages' => ycAdsbTatNumber($a['messages'] ?? null),
        'rssi' => ycAdsbTatNumber($a['rssi'] ?? null),
        'provider' => $provider,
    ];
}

function ycAdsbTatHttpJson(string $url): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL aktif değil.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 7,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'YulCaribe/1.0 ADS-B',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) throw new RuntimeException('TheAirTraffic erişilemedi'.($error ? ': '.$error : '.'));
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('TheAirTraffic geçersiz JSON döndürdü.');
    return $json;
}

function ycAdsbTatString(mixed $v): ?string {
    if (!is_string($v) && !is_numeric($v)) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}
function ycAdsbTatNumber(mixed $v): int|float|null { return is_numeric($v) ? $v + 0 : null; }
function ycAdsbTatAltitude(mixed $v): int|float|string|null {
    if (is_string($v) && strtolower(trim($v)) === 'ground') return 'ground';
    return ycAdsbTatNumber($v);
}

function ycAdsbTatSameOrigin(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'yulcaribe.com'
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function ycAdsbTatRequireSameOrigin(): void {
    header('Vary: Origin, Sec-Fetch-Site, Referer');
    header('Cross-Origin-Resource-Policy: same-origin');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (($fetchSite !== '' && $fetchSite !== 'same-origin')
        || ($origin !== ''
            ? !in_array($origin, ['https://yulcaribe.com', 'https://yulcaribe.com:443'], true)
            : !ycAdsbTatSameOrigin($referer))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>false,'error'=>'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ycAdsbTatRequireSameOrigin();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $icao = strtolower(trim((string)($_GET['icao'] ?? '')));
    if (!preg_match('/^[0-9a-f]{6}$/', $icao)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Geçerli 6 haneli ICAO HEX gerekli.']);
        exit;
    }
    try {
        $aircraft = ycAdsbTatFetch($icao);
        echo json_encode(['ok'=>true,'provider'=>'tat','aircraft'=>$aircraft], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('[adsbtat] '.$e->getMessage());
        http_response_code(502);
        echo json_encode(['ok'=>false,'provider'=>'tat','aircraft'=>null,'error'=>'ADS-B kaynağına erişilemedi.']);
    }
}
