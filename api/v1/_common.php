<?php
declare(strict_types=1);

function ycApiV1Headers(string $cacheControl = 'no-store, max-age=0'): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . $cacheControl);
    header('X-YC-API-Version: 1');
}

function ycApiV1Respond(int $status, array $payload): never {
    http_response_code($status);
    $payload = ['apiVersion' => 'v1'] + $payload;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ycApiV1Method(string ...$allowed): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $allowed);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        ycApiV1Respond(405, ['ok' => false, 'error' => 'HTTP method not allowed.']);
    }
}

function ycApiV1String(array $source, string $key, int $maxLength = 200): string {
    $value = trim((string)($source[$key] ?? ''));
    if (function_exists('mb_substr')) return mb_substr($value, 0, $maxLength);
    return substr($value, 0, $maxLength);
}


function ycApiDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_mysql')) {
        ycApiV1Respond(500, ['ok' => false, 'error' => 'PDO MySQL aktif değil.']);
    }

    $path = dirname(__DIR__, 4) . '/data.php';
    if (!is_file($path)) {
        ycApiV1Respond(500, ['ok' => false, 'error' => 'Veritabanı yapılandırması bulunamadı.']);
    }

    $cfg = require $path;
    if (!is_array($cfg)) {
        ycApiV1Respond(500, ['ok' => false, 'error' => 'Veritabanı yapılandırması geçersiz.']);
    }

    foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
        if (!array_key_exists($key, $cfg)) {
            ycApiV1Respond(500, ['ok' => false, 'error' => 'Veritabanı yapılandırması eksik.']);
        }
    }

    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                (int)$cfg['port'],
                $cfg['database']
            ),
            $cfg['user'],
            $cfg['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable) {
        ycApiV1Respond(500, ['ok' => false, 'error' => 'Veritabanına bağlanılamadı.']);
    }

    return $pdo;
}
