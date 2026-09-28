<?php
declare(strict_types=1);

require_once __DIR__ . '/adsbtat.php';
require_once __DIR__ . '/adsblol.php';
require_once __DIR__ . '/adsbfi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$icao = strtolower(trim((string)($_GET['icao'] ?? '')));
if (!preg_match('/^[0-9a-f]{6}$/', $icao)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Geçerli 6 haneli ICAO HEX gerekli.']);
    exit;
}

if (!function_exists('curl_multi_init')) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'ADS-B motoru kullanılamıyor.']);
    exit;
}

$providers = [
    'tat' => ['url'=>ycAdsbTatEndpoint($icao), 'parse'=>'ycAdsbTatParse'],
    'adsblol' => ['url'=>ycAdsbLolEndpoint($icao), 'parse'=>'ycAdsbLolParse'],
    'adsbfi' => ['url'=>ycAdsbFiEndpoint($icao), 'parse'=>'ycAdsbFiParse'],
];

$multi = curl_multi_init();
$handles = [];
foreach ($providers as $name => $provider) {
    $ch = curl_init($provider['url']);
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
    curl_multi_add_handle($multi, $ch);
    $handles[$name] = $ch;
}

do {
    $status = curl_multi_exec($multi, $running);
    if ($running) curl_multi_select($multi, 1.0);
} while ($running && $status === CURLM_OK);

$observations = [];
$sourceStatus = [];
foreach ($handles as $name => $ch) {
    $body = curl_multi_getcontent($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    $sourceStatus[$name] = $http;

    if (is_string($body) && $http === 200 && $error === '') {
        $payload = json_decode($body, true);
        if (is_array($payload)) {
            try {
                $parse = $providers[$name]['parse'];
                $row = $parse($payload);
                if (($row['icao'] ?? null) === $icao) $observations[$name] = $row;
            } catch (Throwable $e) {
                error_log('[adsb '.$name.' parse] '.$e->getMessage());
            }
        }
    } elseif ($error !== '') {
        error_log('[adsb '.$name.'] '.$error);
    }

    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

if ($observations === []) {
    http_response_code(404);
    echo json_encode([
        'ok'=>false,
        'icao'=>$icao,
        'aircraft'=>null,
        'availableSources'=>[],
        'sourceStatus'=>$sourceStatus,
        'error'=>'Uçak bulunamadı.',
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$ranked = array_values($observations);
usort($ranked, static function(array $a, array $b): int {
    $aPos = is_numeric($a['seenPos'] ?? null) ? (float)$a['seenPos'] : INF;
    $bPos = is_numeric($b['seenPos'] ?? null) ? (float)$b['seenPos'] : INF;
    return $aPos <=> $bPos;
});

$winner = $ranked[0];

http_response_code(200);
echo json_encode([
    'ok'=>true,
    'icao'=>$icao,
    'positionSource'=>$winner['provider'],
    'availableSources'=>array_keys($observations),
    'sourceStatus'=>$sourceStatus,
    'aircraft'=>$winner,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
