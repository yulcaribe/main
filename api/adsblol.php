<?php
declare(strict_types=1);

const YC_ADSB_LOL_URL = 'https://api.adsb.lol';

function ycAdsbLolIcaoEndpoint(string $icao): string {
    return YC_ADSB_LOL_URL . '/v2/hex/' . rawurlencode($icao);
}

function ycAdsbLolAreaEndpoint(array $box): string {
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
    return YC_ADSB_LOL_URL . '/v2/point/' . rawurlencode(number_format($lat, 6, '.', '')) . '/' . rawurlencode(number_format($lon, 6, '.', '')) . '/' . rawurlencode((string)$radius);
}

function ycAdsbLolParseList(array $payload): array {
    $raw = $payload['ac'] ?? null;
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $row = ycAdsbLolStandardize($item);
        if ($row['icao'] !== null) $out[] = $row;
    }
    return $out;
}

function ycAdsbLolParseOne(array $payload): ?array {
    $rows = ycAdsbLolParseList($payload);
    return $rows[0] ?? null;
}

function ycAdsbLolStandardize(array $a): array {
    $icao = strtolower(trim((string)($a['hex'] ?? '')));
    if ($icao === '' || str_starts_with($icao, '~') || !preg_match('/^[0-9a-f]{6}$/', $icao)) $icao = null;
    return [
        'icao' => $icao,
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
        'navModes' => is_array($a['nav_modes'] ?? null) ? array_values($a['nav_modes']) : [],
        'provider' => 'adsblol',
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
    if (!is_string($body) || $status !== 200) throw new RuntimeException('adsblol erişilemedi'.($error ? ': '.$error : '.'));
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('adsblol geçersiz JSON döndürdü.');
    return $json;
}

function ycAdsbLolString(mixed $v): ?string {
    if (!is_string($v) && !is_numeric($v)) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function ycAdsbLolNumber(mixed $v): int|float|null {
    return is_numeric($v) ? $v + 0 : null;
}

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
        && !isset($parts['user']) && !isset($parts['pass']);
}

function ycAdsbLolRequireSameOrigin(): void {
    header('Vary: Origin, Sec-Fetch-Site, Referer');
    header('Cross-Origin-Resource-Policy: same-origin');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (($fetchSite !== '' && $fetchSite !== 'same-origin')
        || ($origin !== ''
            ? !in_array($origin, ['https://yulcaribe.com','https://yulcaribe.com:443'], true)
            : !ycAdsbLolSameOrigin($referer))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>false,'error'=>'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

function ycAdsbLolParseBox(string $raw): array {
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
    ycAdsbLolRequireSameOrigin();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    try {
        $icao = strtolower(trim((string)($_GET['icao'] ?? '')));
        $boxRaw = trim((string)($_GET['box'] ?? ''));
        if ($icao !== '') {
            if (!preg_match('/^[0-9a-f]{6}$/', $icao)) throw new InvalidArgumentException('Geçerli 6 haneli ICAO HEX gerekli.');
            $rows = ycAdsbLolParseList(ycAdsbLolHttpJson(ycAdsbLolIcaoEndpoint($icao)));
        } elseif ($boxRaw !== '') {
            $box = ycAdsbLolParseBox($boxRaw);
            $rows = ycAdsbLolParseList(ycAdsbLolHttpJson(ycAdsbLolAreaEndpoint($box)));
        } else {
            throw new InvalidArgumentException('icao veya box gerekli.');
        }
        echo json_encode(['ok'=>true,'provider'=>'adsblol','aircraft'=>$rows], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'provider'=>'adsblol','aircraft'=>[],'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[adsb adsblol] '.$e->getMessage());
        http_response_code(502);
        echo json_encode(['ok'=>false,'provider'=>'adsblol','aircraft'=>[],'error'=>'ADS-B kaynağına erişilemedi.']);
    }
}
