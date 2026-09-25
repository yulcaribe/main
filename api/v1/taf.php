<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/weather/core.php';

ycApiV1Headers('public, max-age=60, stale-while-revalidate=120');
ycApiV1Method('GET');

$icao = ycWeatherNormalizeIcao($_GET['icao'] ?? null);
if ($icao === null) {
    ycApiV1Respond(400, [
        'ok' => false,
        'error' => '4 karakterli geçerli bir ICAO kodu gerekli.'
    ]);
}

try {
    $data = ycWeatherProduct('taf', $icao);
    if (!($data['ok'] ?? false)) {
        ycApiV1Respond(502, [
            'ok' => false,
            'resource' => 'taf',
            'icao' => $icao,
            'source' => 'AviationWeather.gov',
            'error' => 'TAF upstream isteği başarısız.',
        ]);
    }

    ycApiV1Respond(200, [
        'ok' => true,
        'resource' => 'taf',
        'icao' => $icao,
        'source' => 'AviationWeather.gov',
        'fetchedAt' => $data['fetchedAt'] ?? gmdate('c'),
        'available' => (bool)($data['available'] ?? false),
        'raw' => $data['raw'] ?? null,
        'transport' => $data['transport'] ?? null,
        'upstreamStatus' => $data['upstreamStatus'] ?? null,
        'cache' => $data['cache'] ?? ['hit' => false, 'ageSeconds' => 0],
    ]);
} catch (Throwable $e) {
    ycApiV1Respond(500, [
        'ok' => false,
        'resource' => 'taf',
        'icao' => $icao,
        'error' => 'TAF API isteği işlenemedi.'
    ]);
}
