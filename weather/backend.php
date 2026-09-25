<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/core.php';

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

$icao = ycWeatherNormalizeIcao($_GET['icao'] ?? null);
if ($icao === null) {
    respond(400, [
        'ok' => false,
        'error' => '4 karakterli geçerli bir ICAO kodu girin. Örnek: LTAI.'
    ]);
}

try {
    $combined = ycWeatherCombined($icao);
} catch (Throwable $e) {
    respond(500, [
        'ok' => false,
        'icao' => $icao,
        'error' => 'Hava verisi hazırlanamadı.'
    ]);
}

if (!($combined['ok'] ?? false)) {
    respond(502, [
        'ok' => false,
        'icao' => $icao,
        'error' => 'AviationWeather.gov isteği başarısız.',
        'metarError' => $combined['errors']['metar'] ?? null,
        'tafError' => $combined['errors']['taf'] ?? null,
    ]);
}

respond(200, [
    'ok' => true,
    'icao' => $icao,
    'source' => 'AviationWeather.gov',
    'fetchedAt' => $combined['fetchedAt'],
    'metar' => $combined['metar'],
    'taf' => $combined['taf'],
    'cache' => [
        'hit' => (bool)(($combined['cache']['metarHit'] ?? false) && ($combined['cache']['tafHit'] ?? false)),
        'metarHit' => (bool)($combined['cache']['metarHit'] ?? false),
        'tafHit' => (bool)($combined['cache']['tafHit'] ?? false),
        'ageSeconds' => 0,
    ],
]);
