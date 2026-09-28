<?php
declare(strict_types=1);

const YC_ADSB_FI_URL = 'https://opendata.adsb.fi/api';

function ycAdsbFiIcaoEndpoint(string $icao): string {
    return YC_ADSB_FI_URL . '/v2/hex/' . rawurlencode($icao);
}

function ycAdsbFiAreaEndpoint(array $box): string {
    [$south,$north,$west,$east] = $box;
    $lat = ($south + $north) / 2.0;
    $lon = ($west + $east) / 2.0;
    $earthNm = 3440.065;
    $maxNm = 1.0;
    foreach ([[$south,$west],[$south,$east],[$north,$west],[$north,$east]] as [$clat,$clon]) {
        $p1 = deg2rad($lat); $p2 = deg2rad($clat);
        $dLat = $p2 - $p1; $dLon = deg2rad($clon - $lon);
        $h = sin($dLat/2)**2 + cos($p1)*cos($p2)*sin($dLon/2)**2;
        $nm = 2*$earthNm*asin(min(1.0, sqrt($h)));
        $maxNm = max($maxNm, $nm);
    }
    $radius = min(250.0, ceil($maxNm * 1.05));
    return YC_ADSB_FI_URL . '/v3/lat/' . rawurlencode(number_format($lat, 6, '.', '')) . '/lon/' . rawurlencode(number_format($lon, 6, '.', '')) . '/dist/' . rawurlencode((string)$radius);
}

function ycAdsbFiParseList(array $payload): array {
    $raw = $payload['ac'] ?? null;
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $row = ycAdsbFiStandardize($item);
        if ($row['icao'] !== null) $out[] = $row;
    }
    return $out;
}

function ycAdsbFiParseOne(array $payload): ?array {
    $rows = ycAdsbFiParseList($payload);
    return $rows[0] ?? null;
}

function ycAdsbFiStandardize(array $a): array {
    $icao = strtolower(trim((string)($a['hex'] ?? '')));
    if ($icao === '' || str_starts_with($icao, '~') || !preg_match('/^[0-9a-f]{6}$/', $icao)) $icao = null;
    return [
        'icao' => $icao,
        'callsign' => ycAdsbFiString($a['flight'] ?? null),
        'reg' => ycAdsbFiString($a['r'] ?? null),
        'aircraftType' => ycAdsbFiString($a['t'] ?? null),
        'sourceType' => ycAdsbFiString($a['type'] ?? null),
        'lat' => ycAdsbFiNumber($a['lat'] ?? null),
        'lon' => ycAdsbFiNumber($a['lon'] ?? null),
        'altBaro' => ycAdsbFiAltitude($a['alt_baro'] ?? null),
        'altGeom' => ycAdsbFiNumber($a['alt_geom'] ?? null),
        'groundSpeed' => ycAdsbFiNumber($a['gs'] ?? null),
        'ias' => ycAdsbFiNumber($a['ias'] ?? null),
        'tas' => ycAdsbFiNumber($a['tas'] ?? null),
        'mach' => ycAdsbFiNumber($a['mach'] ?? null),
        'track' => ycAdsbFiNumber($a['track'] ?? null),
        'trueHeading' => ycAdsbFiNumber($a['true_heading'] ?? null),
        'magHeading' => ycAdsbFiNumber($a['mag_heading'] ?? null),
        'roll' => ycAdsbFiNumber($a['roll'] ?? null),
        'baroRate' => ycAdsbFiNumber($a['baro_rate'] ?? null),
        'geomRate' => ycAdsbFiNumber($a['geom_rate'] ?? null),
        'squawk' => ycAdsbFiString($a['squawk'] ?? null),
        'emergency' => ycAdsbFiString($a['emergency'] ?? null),
        'category' => ycAdsbFiString($a['category'] ?? null),
        'navQnh' => ycAdsbFiNumber($a['nav_qnh'] ?? null),
        'navAltitudeMcp' => ycAdsbFiNumber($a['nav_altitude_mcp'] ?? null),
        'navAltitudeFms' => ycAdsbFiNumber($a['nav_altitude_fms'] ?? null),
        'navHeading' => ycAdsbFiNumber($a['nav_heading'] ?? null),
        'nic' => ycAdsbFiNumber($a['nic'] ?? null),
        'rc' => ycAdsbFiNumber($a['rc'] ?? null),
        'nicBaro' => ycAdsbFiNumber($a['nic_baro'] ?? null),
        'nacP' => ycAdsbFiNumber($a['nac_p'] ?? null),
        'nacV' => ycAdsbFiNumber($a['nac_v'] ?? null),
        'sil' => ycAdsbFiNumber($a['sil'] ?? null),
        'silType' => ycAdsbFiString($a['sil_type'] ?? null),
        'gva' => ycAdsbFiNumber($a['gva'] ?? null),
        'sda' => ycAdsbFiNumber($a['sda'] ?? null),
        'version' => ycAdsbFiNumber($a['version'] ?? null),
        'alert' => ycAdsbFiNumber($a['alert'] ?? null),
        'spi' => ycAdsbFiNumber($a['spi'] ?? null),
        'seen' => ycAdsbFiNumber($a['seen'] ?? null),
        'seenPos' => ycAdsbFiNumber($a['seen_pos'] ?? null),
        'messages' => ycAdsbFiNumber($a['messages'] ?? null),
        'rssi' => ycAdsbFiNumber($a['rssi'] ?? null),
        'navModes' => is_array($a['nav_modes'] ?? null) ? array_values($a['nav_modes']) : [],
        'provider' => 'adsbfi',
    ];
}

function ycAdsbFiHttpJson(string $url): array {
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
    if (!is_string($body) || $status !== 200) throw new RuntimeException('adsbfi erişilemedi'.($error ? ': '.$error : '.'));
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('adsbfi geçersiz JSON döndürdü.');
    return $json;
}

function ycAdsbFiString(mixed $v): ?string {
    if (!is_string($v) && !is_numeric($v)) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function ycAdsbFiNumber(mixed $v): int|float|null {
    return is_numeric($v) ? $v + 0 : null;
}

function ycAdsbFiAltitude(mixed $v): int|float|string|null {
    if (is_string($v) && strtolower(trim($v)) === 'ground') return 'ground';
    return ycAdsbFiNumber($v);
}

function ycAdsbFiSameOrigin(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'yulcaribe.com'
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && !isset($parts['user']) && !isset($parts['pass']);
}

function ycAdsbFiRequireSameOrigin(): void {
    header('Vary: Origin, Sec-Fetch-Site, Referer');
    header('Cross-Origin-Resource-Policy: same-origin');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (($fetchSite !== '' && $fetchSite !== 'same-origin')
        || ($origin !== ''
            ? !in_array($origin, ['https://yulcaribe.com','https://yulcaribe.com:443'], true)
            : !ycAdsbFiSameOrigin($referer))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>false,'error'=>'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

function ycAdsbFiParseBox(string $raw): array {
    if (!preg_match('/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/', trim($raw))) {
        throw new InvalidArgumentException('Geçersiz ADS-B box.');
    }
    [$south,$north,$west,$east] = array_map('floatval', explode(',', $raw));
    if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east) {
        throw new InvalidArgumentException('ADS-B box sınır dışında.');
    }
    return [$south,$north,$west,$east];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ycAdsbFiRequireSameOrigin();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    try {
        $icao = strtolower(trim((string)($_GET['icao'] ?? '')));
        $boxRaw = trim((string)($_GET['box'] ?? ''));
        if ($icao !== '') {
            if (!preg_match('/^[0-9a-f]{6}$/', $icao)) throw new InvalidArgumentException('Geçerli 6 haneli ICAO HEX gerekli.');
            $rows = ycAdsbFiParseList(ycAdsbFiHttpJson(ycAdsbFiIcaoEndpoint($icao)));
        } elseif ($boxRaw !== '') {
            $box = ycAdsbFiParseBox($boxRaw);
            $rows = ycAdsbFiParseList(ycAdsbFiHttpJson(ycAdsbFiAreaEndpoint($box)));
        } else {
            throw new InvalidArgumentException('icao veya box gerekli.');
        }
        echo json_encode(['ok'=>true,'provider'=>'adsbfi','aircraft'=>$rows], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'provider'=>'adsbfi','aircraft'=>[],'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[adsb adsbfi] '.$e->getMessage());
        http_response_code(502);
        echo json_encode(['ok'=>false,'provider'=>'adsbfi','aircraft'=>[],'error'=>'ADS-B kaynağına erişilemedi.']);
    }
}
