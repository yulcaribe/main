<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
ycApiV1Headers('public, max-age=300, stale-while-revalidate=600');
ycApiV1Method('GET');

ycApiV1Respond(200, [
    'ok' => true,
    'name' => 'YulCaribe API',
    'version' => 'v1',
    'generatedAt' => gmdate('c'),
    'resources' => [
        'notams' => '/main/api/v1/notam.php',
        'navdata' => '/main/api/v1/navdata.php',
        'weather' => '/main/api/v1/weather.php',
        'metar' => '/main/api/v1/metar.php',
        'taf' => '/main/api/v1/taf.php',
        'wafs' => '/main/api/v1/wafs.php',
        'flights' => '/main/api/v1/flights.php',
        'briefing' => '/main/api/v1/briefing.php',
        'modelwx' => '/main/api/v1/modelwx.php',
    ],
    'notes' => [
        'Public read APIs only.',
        'Frontend data access is centralized under API v1.',
        'System Health administration is session-protected.'
    ],
]);
