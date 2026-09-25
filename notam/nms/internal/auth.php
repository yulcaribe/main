<?php
declare(strict_types=1);

function nmsHealthSessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name('yulcaribe_health');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/main/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function nmsAdminKey(): string {
    $envKey = trim((string)(getenv('HEALTH_ADMIN_KEY') ?: getenv('NMS_ADMIN_KEY') ?: ''));
    if ($envKey !== '') return $envKey;

    $homeRoot = dirname(__DIR__, 5);
    $configPath = $homeRoot . '/data.php';
    if (!is_file($configPath)) return '';

    $root = require $configPath;
    if (!is_array($root)) return '';

    $healthKey = isset($root['health']) && is_array($root['health'])
        ? trim((string)($root['health']['admin_key'] ?? ''))
        : '';
    if ($healthKey !== '') return $healthKey;

    return isset($root['nms']) && is_array($root['nms'])
        ? trim((string)($root['nms']['admin_key'] ?? ''))
        : '';
}

function nmsHealthAuthenticated(): bool {
    nmsHealthSessionStart();
    return ($_SESSION['nms_health_authenticated'] ?? false) === true;
}

function nmsHealthLogin(string $provided): bool {
    nmsHealthSessionStart();
    $expected = nmsAdminKey();

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        usleep(250000);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['nms_health_authenticated'] = true;
    $_SESSION['nms_health_login_at'] = time();
    return true;
}

function nmsHealthLogout(): void {
    nmsHealthSessionStart();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/main/',
            'domain' => $params['domain'] ?: '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => 'Strict',
        ]);
    }

    session_destroy();
}
