<?php
declare(strict_types=1);

header('X-YC-API-Version: 1');
header('X-YC-API-Resource: wafs');

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=900');

const YC_WAFS_BASE = 'https://aviationweather.gov/data/products/wafs';
const YC_WAFS_UA = 'YulCaribe-WAFS/1.0 (+https://yulcaribe.com)';
const YC_WAFS_MAX_BYTES = 1500000;
const YC_WAFS_BUDGET = 11.0;

$ycWafsStarted = microtime(true);

function jsonOut(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pngOut(string $body, array $meta): never {
    http_response_code(200);
    header('Content-Type: image/png');
    header('Content-Length: '.strlen($body));
    header('X-YC-WAFS-Source: AWC public WAFS visualization');
    header('X-YC-WAFS-Product: '.$meta['product']);
    header('X-YC-WAFS-Run: '.$meta['runUtc']);
    header('X-YC-WAFS-Forecast-Hour: '.(string)$meta['forecastHour']);
    header('X-YC-WAFS-Valid-UTC: '.$meta['validUtc']);
    header('X-YC-WAFS-Requested-UTC: '.$meta['requestedUtc']);
    header('X-YC-WAFS-Layer-FL: '.($meta['layerFL'] === null ? 'NA' : (string)$meta['layerFL']));
    header('X-YC-WAFS-Pressure-MB: '.($meta['pressureMb'] === null ? 'NA' : (string)$meta['pressureMb']));
    header('X-YC-WAFS-Approximate: 1');
    echo $body;
    exit;
}

function cacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_wafs_png_v1';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function cacheRead(string $key, int $ttl): ?string {
    $file = cacheDir().DIRECTORY_SEPARATOR.sha1($key).'.png';
    if (!is_file($file)) return null;
    $age = time() - (int)@filemtime($file);
    if ($age < 0 || $age > $ttl) return null;
    $body = @file_get_contents($file);
    return is_string($body) && str_starts_with($body, "\x89PNG\r\n\x1a\n") ? $body : null;
}

function cacheWrite(string $key, string $body): void {
    @file_put_contents(cacheDir().DIRECTORY_SEPARATOR.sha1($key).'.png', $body, LOCK_EX);
}

function fetchPng(string $url): ?string {
    global $ycWafsStarted;
    if (!function_exists('curl_init')) return null;
    $remaining = YC_WAFS_BUDGET - (microtime(true) - $ycWafsStarted);
    if ($remaining <= 0.4) return null;

    if (($cached = cacheRead($url, 21600)) !== null) return $cached;

    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT_MS => min(3500, (int)($remaining * 1000)),
        CURLOPT_TIMEOUT_MS => (int)(min(5.0, $remaining) * 1000),
        CURLOPT_USERAGENT => YC_WAFS_UA,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: image/png,image/*;q=0.8,*/*;q=0.1'],
        CURLOPT_WRITEFUNCTION => function($ch, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > YC_WAFS_MAX_BYTES) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);

    if ($ok === false || $status < 200 || $status >= 300) return null;
    if (!str_starts_with($body, "\x89PNG\r\n\x1a\n")) return null;
    if ($ctype !== '' && !str_contains($ctype, 'image/png') && !str_contains($ctype, 'octet-stream')) return null;
    cacheWrite($url, $body);
    return $body;
}

function parseUtc(string $value): int {
    $value = trim($value);
    if ($value === '') jsonOut(400, ['ok'=>false,'error'=>'valid UTC zorunlu.']);
    $ts = strtotime($value.' UTC');
    if ($ts === false) jsonOut(400, ['ok'=>false,'error'=>'Geçersiz valid UTC.']);
    if ($ts < time() - 14*86400 || $ts > time() + 72*3600) {
        jsonOut(400, ['ok'=>false,'error'=>'WAFS görsel zamanı son 14 gün ile yaklaşık 72 saat sonrası arasında olmalı.']);
    }
    return $ts;
}

function pressureFromFL(int $fl): int {
    $heightM = max(0.0, $fl * 100.0 * 0.3048);
    if ($heightM <= 11000.0) {
        $p = 1013.25 * pow(1.0 - 2.25577e-5 * $heightM, 5.25588);
    } else {
        $p = 226.321 * exp(-($heightM - 11000.0) / 6341.62);
    }
    return (int)round($p);
}

function nearestLevel(int $requestedFL, array $levels): int {
    $best = $levels[0];
    $delta = PHP_INT_MAX;
    foreach ($levels as $level) {
        $d = abs($requestedFL - $level);
        if ($d < $delta) {
            $delta = $d;
            $best = $level;
        }
    }
    return $best;
}

function productConfig(string $product): ?array {
    $configs = [
        'edr' => [
            'label'=>'Turbulence / EDR',
            'levels'=>[140,180,240,270,300,340,390,450],
            'suffix'=>fn(int $pressure, int $fl): string => $pressure.'_edr',
            'visualThreshold'=>'EDR ×100 values above 15 are rendered by AWC',
        ],
        'icing' => [
            'label'=>'Icing severity',
            'levels'=>[60,100,140,180,240,300],
            'suffix'=>fn(int $pressure, int $fl): string => $pressure.'_icsev',
            'visualThreshold'=>'AWC rendered icing-severity categories',
        ],
        'wind' => [
            'label'=>'Wind speed',
            'levels'=>[100,140,180,240,270,300,340,390,450],
            'suffix'=>fn(int $pressure, int $fl): string => $pressure.'_wind',
            'visualThreshold'=>'wind speeds above 60 kt are rendered by AWC',
        ],
        'cbextent' => [
            'label'=>'CB horizontal extent',
            'levels'=>null,
            'suffix'=>fn(int $pressure, int $fl): string => 'whole_cbhe',
            'visualThreshold'=>'CB horizontal extent above 0.3 is rendered by AWC',
        ],
        'cbtop' => [
            'label'=>'CB tops',
            'levels'=>null,
            'suffix'=>fn(int $pressure, int $fl): string => 'cbtop_hght',
            'visualThreshold'=>'CB tops above 30,000 ft are rendered by AWC',
        ],
    ];
    return $configs[$product] ?? null;
}

function imageUrl(int $cycle, int $fh, string $suffix): string {
    $date = gmdate('Ymd', $cycle);
    $hour = gmdate('H', $cycle);
    $f = str_pad((string)$fh, 2, '0', STR_PAD_LEFT);
    return YC_WAFS_BASE.'/'.$date.'/'.$hour.'/'.$date.'_'.$hour.'_F'.$f.'_wafs_'.$suffix.'_m.png';
}

function snapshotCandidates(int $requested): array {
    // WAFS public images are issued at forecast hours 6..36 in 3-hour steps.
    // Prefer the newest cycle that can validly cover the requested time, but sort
    // primarily by distance from the requested route time.
    $latestPossible = min(time(), $requested - 6*3600);
    $base = intdiv(max(0, $latestPossible), 21600) * 21600;
    $out = [];

    for ($cycleOffset=0; $cycleOffset<6; $cycleOffset++) {
        $cycle = $base - $cycleOffset*21600;
        if ($cycle <= 0) continue;
        $hours = ($requested - $cycle) / 3600.0;
        $raw = [
            (int)(round($hours/3.0)*3),
            (int)(floor($hours/3.0)*3),
            (int)(ceil($hours/3.0)*3),
        ];
        foreach (array_values(array_unique($raw)) as $fh) {
            if ($fh < 6 || $fh > 36 || $fh % 3 !== 0) continue;
            $valid = $cycle + $fh*3600;
            $out[] = [
                'cycle'=>$cycle,
                'fh'=>$fh,
                'valid'=>$valid,
                'delta'=>abs($valid-$requested),
            ];
        }
    }

    usort($out, function(array $a, array $b): int {
        if ($a['delta'] !== $b['delta']) return $a['delta'] <=> $b['delta'];
        return $b['cycle'] <=> $a['cycle'];
    });

    $seen = [];
    $unique = [];
    foreach ($out as $row) {
        $key = $row['cycle'].'|'.$row['fh'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $row;
    }
    return array_slice($unique, 0, 8);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'status')));
$flRaw = trim((string)($_GET['fl'] ?? '360'));
if ($flRaw === '' || !preg_match('/^\d{1,3}$/', $flRaw)) {
    jsonOut(400, ['ok'=>false,'error'=>'Flight level tam sayı olmalı (örn. 100, 360).']);
}
$fl = (int)$flRaw;
if ($fl < 50 || $fl > 600) {
    jsonOut(400, ['ok'=>false,'error'=>'Flight level FL050 ile FL600 arasında olmalı.','requestedFL'=>$fl]);
}

if ($action === 'status') {
    $products = [];
    foreach (['edr','icing','wind','cbextent','cbtop'] as $id) {
        $cfg = productConfig($id);
        $layerFL = is_array($cfg['levels']) ? nearestLevel($fl, $cfg['levels']) : null;
        $products[] = [
            'id'=>$id,
            'label'=>$cfg['label'],
            'requestedFL'=>$fl,
            'layerFL'=>$layerFL,
            'pressureMb'=>$layerFL === null ? null : pressureFromFL($layerFL),
            'visualThreshold'=>$cfg['visualThreshold'],
        ];
    }
    jsonOut(200, [
        'ok'=>true,
        'source'=>'NOAA/NWS Aviation Weather Center public WAFS visualization',
        'viewer'=>'https://aviationweather.gov/wafs/',
        'forecastHours'=>'6..36 every 3 hours',
        'products'=>$products,
        'note'=>'Public PNG visualizations are forecast imagery, not raw WIFS/GRIB numerical grids. 301 mb = 301 hPa and corresponds approximately to FL300 in the standard atmosphere.',
    ]);
}

if ($action !== 'image') jsonOut(400, ['ok'=>false,'error'=>'Bilinmeyen action.']);

$product = strtolower(trim((string)($_GET['product'] ?? '')));
$cfg = productConfig($product);
if ($cfg === null) jsonOut(400, ['ok'=>false,'error'=>'Geçersiz WAFS product.']);

$requested = parseUtc((string)($_GET['valid'] ?? ''));

if (is_array($cfg['levels']) && !in_array($fl, $cfg['levels'], true)) {
    $minLevel = min($cfg['levels']);
    $maxLevel = max($cfg['levels']);
    jsonOut(400, [
        'ok'=>false,
        'error'=>$cfg['label'].' için FL'.$fl.' desteklenmiyor. Desteklenen seviyeler: '.implode(', ', array_map(static fn(int $v): string => 'FL'.$v, $cfg['levels'])).'.',
        'product'=>$product,
        'requestedFL'=>$fl,
        'minimumFL'=>$minLevel,
        'maximumFL'=>$maxLevel,
        'supportedLevels'=>$cfg['levels'],
    ]);
}

$layerFL = is_array($cfg['levels']) ? $fl : null;
$pressure = $layerFL === null ? 0 : pressureFromFL($layerFL);
$suffix = $cfg['suffix']($pressure, $layerFL ?? 0);

$attempted = [];
foreach (snapshotCandidates($requested) as $candidate) {
    $url = imageUrl($candidate['cycle'], $candidate['fh'], $suffix);
    $attempted[] = gmdate('Y-m-d H\Z', $candidate['cycle']).'/F'.$candidate['fh'];
    $body = fetchPng($url);
    if ($body === null) continue;

    pngOut($body, [
        'product'=>$product,
        'runUtc'=>gmdate('c', $candidate['cycle']),
        'forecastHour'=>$candidate['fh'],
        'validUtc'=>gmdate('c', $candidate['valid']),
        'requestedUtc'=>gmdate('c', $requested),
        'layerFL'=>$layerFL,
        'pressureMb'=>$layerFL === null ? null : $pressure,
    ]);
}

jsonOut(404, [
    'ok'=>false,
    'error'=>'İstenen zamana yakın public AWC WAFS PNG bulunamadı.',
    'product'=>$product,
    'requestedUtc'=>gmdate('c', $requested),
    'requestedFL'=>$fl,
    'layerFL'=>$layerFL,
    'pressureMb'=>$layerFL === null ? null : $pressure,
    'attempted'=>$attempted,
    'note'=>'Bu sonuç tehlike olmadığı anlamına gelmez; yalnızca public görsel dosya çözümlenemedi.',
]);
