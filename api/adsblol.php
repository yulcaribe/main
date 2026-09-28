<?php
declare(strict_types=1);

const YC_ADSB_LOL_URL = 'https://api.adsb.lol/v2/hex/';

function ycAdsbLolEndpoint(string $icao): string {
    return YC_ADSB_LOL_URL . rawurlencode($icao);
}

function ycAdsbLolParse(array $payload): array {
    $aircraft = is_array($payload['ac'] ?? null) ? $payload['ac'] : [];
    $row = is_array($aircraft[0] ?? null) ? $aircraft[0] : null;
    return ycAdsbLolStandardize($row, 'adsblol');
}

function ycAdsbLolFetch(string $icao): array {
    return ycAdsbLolParse(ycAdsbLolHttpJson(ycAdsbLolEndpoint($icao)));
}

function ycAdsbLolStandardize(?array $a, string $provider): array {
    $icao = strtolower(trim((string)($a['hex'] ?? '')));
    if (str_starts_with($icao, '~')) $icao = '';
    return [
        'icao' => $icao !== '' ? $icao : null,
        'callsign' => ycAdsbLolString($a['flight'] ?? null),
        'reg' => ycAdsbLolString($a['r'] ?? null),
        'aircraftType' => ycAdsbLolString($a['t'] ?? null),
        'sourceType' => ycAdsbLolString($a['type'] ?? null),
        'lat' => ycAdsbLolNumber($a['lat'] ?? null),
        'lon' => ycAdsbLolNumber($a['lon'] ?? null),
        'altBaro' => ycAdsbLolAltitude($a['alt_baro'] ?? null),
        'altGeom' => ycAdsbLolNumber($a['alt_geom'] ?? null),
        'groundSpeed' => ycAdsbLolNumber($a['gs'] ?? null),
        'ias' => ycAdsbLolNumber($a['ias'] ?? null),
        'tas' => ycAdsbLolNumber($a['tas'] ?? null),
        'mach' => ycAdsbLolNumber($a['mach'] ?? null),
        'track' => ycAdsbLolNumber($a['track'] ?? null),
        'trueHeading' => ycAdsbLolNumber($a['true_heading'] ?? null),
        'magHeading' => ycAdsbLolNumber($a['mag_heading'] ?? null),
        'roll' => ycAdsbLolNumber($a['roll'] ?? null),
        'baroRate' => ycAdsbLolNumber($a['baro_rate'] ?? null),
        'geomRate' => ycAdsbLolNumber($a['geom_rate'] ?? null),
        'squawk' => ycAdsbLolString($a['squawk'] ?? null),
        'emergency' => ycAdsbLolString($a['emergency'] ?? null),
        'category' => ycAdsbLolString($a['category'] ?? null),
        'navQnh' => ycAdsbLolNumber($a['nav_qnh'] ?? null),
        'navAltitudeMcp' => ycAdsbLolNumber($a['nav_altitude_mcp'] ?? null),
        'navAltitudeFms' => ycAdsbLolNumber($a['nav_altitude_fms'] ?? null),
        'navHeading' => ycAdsbLolNumber($a['nav_heading'] ?? null),
        'navModes' => is_array($a['nav_modes'] ?? null) ? array_values($a['nav_modes']) : [],
        'nic' => ycAdsbLolNumber($a['nic'] ?? null),
        'rc' => ycAdsbLolNumber($a['rc'] ?? null),
        'nicBaro' => ycAdsbLolNumber($a['nic_baro'] ?? null),
        'nacP' => ycAdsbLolNumber($a['nac_p'] ?? null),
        'nacV' => ycAdsbLolNumber($a['nac_v'] ?? null),
        'sil' => ycAdsbLolNumber($a['sil'] ?? null),
        'silType' => ycAdsbLolString($a['sil_type'] ?? null),
        'gva' => ycAdsbLolNumber($a['gva'] ?? null),
        'sda' => ycAdsbLolNumber($a['sda'] ?? null),
        'version' => ycAdsbLolNumber($a['version'] ?? null),
        'alert' => ycAdsbLolNumber($a['alert'] ?? null),
        'spi' => ycAdsbLolNumber($a['spi'] ?? null),
        'seen' => ycAdsbLolNumber($a['seen'] ?? null),
        'seenPos' => ycAdsbLolNumber($a['seen_pos'] ?? null),
        'messages' => ycAdsbLolNumber($a['messages'] ?? null),
        'rssi' => ycAdsbLolNumber($a['rssi'] ?? null),
        'provider' => $provider,
    ];
}

function ycAdsbLolHttpJson(string $url): array {
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
    if (!is_string($body) || $status !== 200) throw new RuntimeException('ADSB.lol erişilemedi'.($error ? ': '.$error : '.'));
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('ADSB.lol geçersiz JSON döndürdü.');
    return $json;
}

function ycAdsbLolString(mixed $v): ?string {
    if (!is_string($v) && !is_numeric($v)) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}
function ycAdsbLolNumber(mixed $v): int|float|null { return is_numeric($v) ? $v + 0 : null; }
function ycAdsbLolAltitude(mixed $v): int|float|string|null {
    if (is_string($v) && strtolower(trim($v)) === 'ground') return 'ground';
    return ycAdsbLolNumber($v);
}

function ycAdsbLolSameOrigin(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'yulcaribe.com'
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function ycAdsbLolRequireSameOrigin(): void {
    header('Vary: Origin, Sec-Fetch-Site, Referer');
    header('Cross-Origin-Resource-Policy: same-origin');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (($fetchSite !== '' && $fetchSite !== 'same-origin')
        || ($origin !== ''
            ? !in_array($origin, ['https://yulcaribe.com', 'https://yulcaribe.com:443'], true)
            : !ycAdsbLolSameOrigin($referer))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>false,'error'=>'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ycAdsbLolRequireSameOrigin();
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
        $aircraft = ycAdsbLolFetch($icao);
        echo json_encode(['ok'=>true,'provider'=>'adsblol','aircraft'=>$aircraft], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('[adsblol] '.$e->getMessage());
        http_response_code(502);
        echo json_encode(['ok'=>false,'provider'=>'adsblol','aircraft'=>null,'error'=>'ADS-B kaynağına erişilemedi.']);
    }
}
