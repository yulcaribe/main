<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/nms_client.php';

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

nmsRespond(400, [
    'ok' => false,
    'error' => 'Unknown action.',
    'allowedActions' => ['status', 'ping'],
]);
