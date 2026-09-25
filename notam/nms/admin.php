<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/internal/auth.php';
require_once __DIR__ . '/internal/client.php';
require_once __DIR__ . '/internal/store.php';
require_once __DIR__ . '/internal/health.php';
require_once __DIR__ . '/internal/full_run.php';
require_once __DIR__ . '/internal/full_payload.php';
require_once __DIR__ . '/internal/full_store.php';
require_once __DIR__ . '/internal/full_parser.php';

function nmsRespond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nmsReadJsonBody(): array {
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    return is_array($body) ? $body : $_POST;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'status')));

if ($action === 'status') {
    nmsRespond(200, [
        'ok' => true,
        'service' => 'faa-nms',
        'config' => nmsPublicStatus(),
    ]);
}

if ($action === 'admin-full') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        nmsRespond(405, ['ok' => false, 'error' => 'POST required.']);
    }

    $body = nmsReadJsonBody();
    if (!nmsHealthAuthenticated()) {
        $expected = nmsAdminKey();
        $provided = trim((string)($body['adminKey'] ?? ''));

        if ($expected === '') {
            nmsRespond(503, ['ok' => false, 'error' => 'NMS admin key is not configured.']);
        }
        if ($provided === '' || !hash_equals($expected, $provided)) {
            usleep(250000);
            nmsRespond(403, ['ok' => false, 'error' => 'Admin key is invalid.']);
        }
    }

    $cfg = nmsPrivateConfig();

    if ($cfg['env'] === 'production') {
        $pdo = nmsDb();
        $state = nmsSyncState($pdo, $cfg['env']);
        $progress = nmsFullReadProgress($cfg['env']);

        nmsRespond(200, [
            'ok' => true,
            'managedByCron' => true,
            'complete' => !empty($state['last_full_load']),
            'environment' => $cfg['env'],
            'lastFullLoad' => $state['last_full_load'] ?? null,
            'processed' => (int)($progress['processed'] ?? 0),
            'skipped' => (int)($progress['skipped'] ?? 0),
            'expected' => $progress['expected'] ?? null,
            'message' => empty($state['last_full_load'])
                ? 'Production Initial Load is handled automatically by the CLI cron worker.'
                : 'Production Initial Load is already complete; cron is maintaining deltas.',
        ]);
    }

    @set_time_limit(20);

    try {
        $result = nmsRunFullLoadSlice(250, 7);
    } catch (Throwable $e) {
        nmsRespond(500, [
            'ok' => false,
            'complete' => false,
            'error' => 'Initial load slice failed before completion.',
            'detail' => $e->getMessage(),
        ]);
    }

    $status = ($result['ok'] ?? false) ? 200 : (($result['rateLimitedLocally'] ?? false) ? 409 : 502);
    nmsRespond($status, $result);
}

if ($action === 'admin-delta') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        nmsRespond(405, ['ok' => false, 'error' => 'POST required.']);
    }

    $body = nmsReadJsonBody();
    if (!nmsHealthAuthenticated()) {
        $expected = nmsAdminKey();
        $provided = trim((string)($body['adminKey'] ?? ''));

        if ($expected === '') {
            nmsRespond(503, ['ok' => false, 'error' => 'NMS admin key is not configured.']);
        }
        if ($provided === '' || !hash_equals($expected, $provided)) {
            usleep(250000);
            nmsRespond(403, ['ok' => false, 'error' => 'Admin key is invalid.']);
        }
    }

    try {
        $result = nmsRunDeltaSync();
    } catch (Throwable $e) {
        $cfg = nmsPrivateConfig();
        nmsRespond(500, [
            'ok' => false,
            'error' => 'Delta sync failed before completion.',
            'detail' => $cfg['env'] === 'staging' ? $e->getMessage() : null,
        ]);
    }

    $status = ($result['ok'] ?? false)
        ? 200
        : (($result['rateLimitedLocally'] ?? false) ? 429 : 502);
    nmsRespond($status, $result);
}

if ($action === 'health') {
    if (!nmsHealthAuthenticated()) {
        nmsRespond(401, ['ok' => false, 'error' => 'Health session authentication required.']);
    }

    $cfg = nmsPrivateConfig();
    $public = nmsPublicStatus();

    try {
        $local = nmsHealthLocal($cfg['env']);
    } catch (Throwable $e) {
        nmsRespond(500, [
            'ok' => false,
            'service' => 'faa-nms',
            'environment' => $cfg['env'],
            'error' => 'Local NMS database health could not be read.',
            'detail' => $cfg['env'] === 'staging' ? $e->getMessage() : null,
        ]);
    }

    $remote = [
        'checked' => false,
        'ok' => null,
        'upstreamStatus' => null,
        'message' => 'Not checked.',
    ];

    if (($_GET['probe'] ?? '') === '1') {
        $ping = nmsGet('/ping', [], null);
        $remote = [
            'checked' => true,
            'ok' => (bool)($ping['ok'] ?? false),
            'upstreamStatus' => $ping['status'] ?? null,
            'message' => ($ping['ok'] ?? false)
                ? 'FAA NMS authentication and ping succeeded.'
                : (string)($ping['error'] ?? 'FAA NMS ping failed.'),
        ];
    }

    nmsRespond(200, [
        'ok' => true,
        'service' => 'faa-nms',
        'environment' => $cfg['env'],
        'credentialsConfigured' => (bool)($public['credentialsConfigured'] ?? false),
        'apiBase' => $public['apiBase'] ?? null,
        'authUrl' => $public['authUrl'] ?? null,
        'configDiagnostics' => $public['diagnostics'] ?? [],
        'local' => $local,
        'remote' => $remote,
        'generatedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
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

if ($action === 'delta-test') {
    $cfg = nmsPrivateConfig();

    if ($cfg['env'] !== 'staging') {
        nmsRespond(403, [
            'ok' => false,
            'error' => 'Delta test is available only in staging.',
        ]);
    }

    $cooldownPath = nmsCacheDir() . DIRECTORY_SEPARATOR . 'delta_test_last_run.txt';
    $lastRun = is_file($cooldownPath) ? (int)@file_get_contents($cooldownPath) : 0;
    $now = time();

    if ($lastRun > 0 && ($now - $lastRun) < 180) {
        nmsRespond(429, [
            'ok' => false,
            'environment' => $cfg['env'],
            'error' => 'Delta test cooldown is active.',
            'retryAfterSeconds' => 180 - ($now - $lastRun),
        ]);
    }

    @file_put_contents($cooldownPath, (string)$now, LOCK_EX);

    try {
        $result = nmsRunDeltaSync();
    } catch (Throwable $e) {
        nmsRespond(500, [
            'ok' => false,
            'service' => 'faa-nms',
            'testOnly' => true,
            'environment' => $cfg['env'],
            'error' => 'Unhandled staging delta-test failure.',
            'exception' => get_class($e),
            'detail' => $e->getMessage(),
        ]);
    }

    nmsRespond(($result['ok'] ?? false) ? 200 : 502, [
        'service' => 'faa-nms',
        'testOnly' => true,
    ] + $result);
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
    'allowedActions' => ['status', 'health', 'admin-full', 'admin-delta', 'sync-status', 'delta-test', 'ping', 'probe-notams'],
]);
