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
        'airports' => '/main/api/v1/airports.php',
        'charts' => '/main/api/v1/chart.php',
        'navdata' => '/main/api/v1/navdata.php',
        'weather' => '/main/api/v1/weather.php',
        'wafs' => '/main/api/v1/wafs.php',
        'flights' => '/main/api/v1/flights.php',
        'briefing' => '/main/api/v1/briefing.php',
        'enroute' => '/main/api/v1/enroute.php',
        'modelwx' => '/main/api/v1/modelwx.php',
    ],
    'notes' => [
        'Public read APIs only.',
        'FAA NMS synchronization, credentials and admin operations are not exposed through API v1.',
    ],
]);
