<?php
declare(strict_types=1);

const YC_ADSB_FI_URL = 'https://opendata.adsb.fi/api/v2/hex/';

function ycAdsbFiEndpoint(string $icao): string {
    return YC_ADSB_FI_URL . rawurlencode($icao);
}

function ycAdsbFiParse(array $payload): array {
    $aircraft = is_array($payload['ac'] ?? null) ? $payload['ac'] : [];
    $row = is_array($aircraft[0] ?? null) ? $aircraft[0] : null;
    return ycAdsbFiStandardize($row, 'adsbfi');
}

function ycAdsbFiFetch(string $icao): array {
    return ycAdsbFiParse(ycAdsbFiHttpJson(ycAdsbFiEndpoint($icao)));
}

function ycAdsbFiStandardize(?array $a, string $provider): array {
    $icao = strtolower(trim((string)($a['hex'] ?? '')));
    if (str_starts_with($icao, '~')) $icao = '';
    return [
        'icao' => $icao !== '' ? $icao : null,
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
        'navModes' => is_array($a['nav_modes'] ?? null) ? array_values($a['nav_modes']) : [],
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
        'provider' => $provider,
    ];
}

function ycAdsbFiHttpJson(string $url): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL aktif değil.');
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>2,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>7,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'YulCaribe/1.0 ADS-B',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if(!is_string($body)||$status!==200)throw new RuntimeException('adsb.fi erişilemedi'.($error?': '.$error:'.'));
    $json=json_decode($body,true);if(!is_array($json))throw new RuntimeException('adsb.fi geçersiz JSON döndürdü.');return $json;
}
function ycAdsbFiString(mixed $v): ?string { if(!is_string($v)&&!is_numeric($v))return null;$s=trim((string)$v);return $s===''?null:$s; }
function ycAdsbFiNumber(mixed $v): int|float|null { return is_numeric($v)?$v+0:null; }
function ycAdsbFiAltitude(mixed $v): int|float|string|null { if(is_string($v)&&strtolower(trim($v))==='ground')return 'ground';return ycAdsbFiNumber($v); }

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, max-age=0');
    $icao=strtolower(trim((string)($_GET['icao']??'')));
    if(!preg_match('/^[0-9a-f]{6}$/',$icao)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Geçerli 6 haneli ICAO HEX gerekli.']);exit;}
    try{$aircraft=ycAdsbFiFetch($icao);echo json_encode(['ok'=>true,'provider'=>'adsbfi','aircraft'=>$aircraft],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);}catch(Throwable $e){error_log('[adsbfi] '.$e->getMessage());http_response_code(502);echo json_encode(['ok'=>false,'provider'=>'adsbfi','aircraft'=>null,'error'=>'ADS-B kaynağına erişilemedi.']);}
}
