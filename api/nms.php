<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/nms_client.php';
require_once __DIR__ . '/nms_store.php';

function nmsRespond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'status')));

if ($action === 'status') {
    nmsRespond(200, [
        'ok' => true,
        'service' => 'faa-nms',
        'config' => nmsPublicStatus(),
    ]);
}

if ($action === 'sync-status') {
    $cfg = nmsPrivateConfig();

    try {
        $local = nmsLocalStatus($cfg['env']);
    } catch (Throwable) {
        nmsRespond(500, [
            'ok' => false,
            'service' => 'faa-nms',
            'environment' => $cfg['env'],
            'error' => 'Local NMS database status could not be read.',
        ]);
    }

    nmsRespond(200, [
        'ok' => true,
        'service' => 'faa-nms',
        'environment' => $cfg['env'],
        'local' => $local,
    ]);
}

if ($action === 'ping') {
    $result = nmsGet('/ping', [], null);

    if (!($result['ok'] ?? false)) {
        nmsRespond((int)($result['status'] ?? 502), [
            'ok' => false,
            'service' => 'faa-nms',
            'environment' => nmsPrivateConfig()['env'],
            'error' => $result['error'] ?? 'NMS ping failed.',
            'upstreamStatus' => $result['status'] ?? null,
        ]);
    }

    nmsRespond(200, [
        'ok' => true,
        'service' => 'faa-nms',
        'environment' => nmsPrivateConfig()['env'],
        'upstreamStatus' => $result['status'] ?? 200,
        'response' => $result['data'] ?? $result['body'] ?? null,
    ]);
}

if ($action === 'probe-notams') {
    $cfg = nmsPrivateConfig();

    if ($cfg['env'] !== 'staging') {
        nmsRespond(403, [
            'ok' => false,
            'error' => 'This probe is available only in staging.',
        ]);
    }

    $location = strtoupper(trim((string)($_GET['location'] ?? 'LTAI')));
    if (!in_array($location, ['LTAI', 'EDDK'], true)) {
        nmsRespond(400, [
            'ok' => false,
            'error' => 'Allowed test locations: LTAI, EDDK.',
        ]);
    }

    $result = nmsGet('/notams', ['location' => $location], 'GEOJSON');

    if (!($result['ok'] ?? false)) {
        nmsRespond((int)($result['status'] ?? 502), [
            'ok' => false,
            'service' => 'faa-nms',
            'environment' => $cfg['env'],
            'location' => $location,
            'error' => $result['error'] ?? 'NMS NOTAM probe failed.',
            'upstreamStatus' => $result['status'] ?? null,
        ]);
    }

    $apiResponse = is_array($result['data'] ?? null) ? $result['data'] : [];
    $features = $apiResponse['data']['geojson'] ?? [];
    if (!is_array($features)) {
        $features = [];
    }

    $items = [];
    foreach (array_slice($features, 0, 20) as $feature) {
        if (!is_array($feature)) continue;
        $notam = $feature['properties']['coreNOTAMData']['notam'] ?? null;
        if (!is_array($notam)) continue;

        $items[] = [
            'id' => $notam['id'] ?? null,
            'series' => $notam['series'] ?? null,
            'number' => $notam['number'] ?? null,
            'year' => $notam['year'] ?? null,
            'type' => $notam['type'] ?? null,
            'affectedFir' => $notam['affectedFir'] ?? null,
            'location' => $notam['location'] ?? null,
            'icaoLocation' => $notam['icaoLocation'] ?? null,
            'effectiveStart' => $notam['effectiveStart'] ?? null,
            'effectiveEnd' => $notam['effectiveEnd'] ?? null,
            'classification' => $notam['classification'] ?? null,
            'lastUpdated' => $notam['lastUpdated'] ?? null,
            'text' => $notam['text'] ?? null,
        ];
    }

    nmsRespond(200, [
        'ok' => true,
        'service' => 'faa-nms',
        'environment' => $cfg['env'],
        'location' => $location,
        'upstreamStatus' => $result['status'] ?? 200,
        'apiStatus' => $apiResponse['status'] ?? null,
        'count' => count($features),
        'returned' => count($items),
        'items' => $items,
    ]);
}

nmsRespond(400, [
    'ok' => false,
    'error' => 'Unknown action.',
    'allowedActions' => ['status', 'sync-status', 'ping', 'probe-notams'],
]);
