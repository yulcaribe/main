<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

function healthOut(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$started = microtime(true);
$status = [
    'php' => ['ok' => true, 'version' => PHP_VERSION],
    'database' => ['ok' => false],
    'navdata' => ['ok' => false],
    'notam' => ['ok' => false],
];

try {
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql unavailable');
    $path = dirname(__DIR__, 3) . '/data.php';
    if (!is_file($path)) throw new RuntimeException('config unavailable');
    $cfg = require $path;
    if (!is_array($cfg)) throw new RuntimeException('config invalid');

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['database']),
        $cfg['user'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->query('SELECT 1')->fetchColumn();
    $status['database'] = ['ok' => true];

    try {
        $navCount = (int)$pdo->query('SELECT COUNT(*) FROM nav_points')->fetchColumn();
        $status['navdata'] = ['ok' => true, 'points' => $navCount];
    } catch (Throwable $e) {
        error_log('[health-navdata] '.$e->getMessage());
    }

    try {
        $notamCount = (int)$pdo->query("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment='production'")->fetchColumn();
        $status['notam'] = ['ok' => true, 'records' => $notamCount];
    } catch (Throwable $e) {
        error_log('[health-notam] '.$e->getMessage());
    }
} catch (Throwable $e) {
    error_log('[health-db] '.$e->getMessage());
}

$ok = $status['database']['ok'] && $status['navdata']['ok'] && $status['notam']['ok'];
healthOut($ok ? 200 : 503, [
    'ok' => $ok,
    'timeUtc' => gmdate('c'),
    'durationMs' => round((microtime(true) - $started) * 1000, 1),
    'services' => $status,
]);
