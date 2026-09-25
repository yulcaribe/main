<?php
declare(strict_types=1);

/**
 * CLI-only FAA NMS synchronizer.
 *
 * cPanel cron example (later, once production credentials are active):
 *   php /home/yulcari1/public_html/main/notam/nms/sync.php delta
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/internal/client.php';
require_once __DIR__ . '/internal/store.php';

$action = strtolower(trim((string)($argv[1] ?? 'delta')));

try {
    if ($action === 'status') {
        $cfg = nmsPrivateConfig();
        $result = [
            'ok' => true,
            'environment' => $cfg['env'],
            'local' => nmsLocalStatus($cfg['env']),
        ];
    } elseif ($action === 'delta') {
        $result = nmsRunDeltaSync();
    } else {
        $result = [
            'ok' => false,
            'error' => 'Unknown action.',
            'allowedActions' => ['delta', 'status'],
        ];
    }
} catch (Throwable $e) {
    $result = [
        'ok' => false,
        'error' => 'NMS sync failed before completion.',
        'detail' => $e->getMessage(),
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);
